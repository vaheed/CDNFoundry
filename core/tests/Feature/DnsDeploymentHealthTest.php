<?php

namespace Tests\Feature;

use App\Jobs\ReconcileDnsZone;
use App\Models\DnsCluster;
use App\Models\DnsDeployment;
use App\Models\Domain;
use App\Models\PlatformDnsSetting;
use App\Models\User;
use App\Support\DomainNameserverVerification;
use App\Support\PowerDnsClient;
use App\Support\SystemHealth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Customer-side delegation problems never degrade the platform; the platform's own provisioning failures do. Each case
 * runs the real reconcile job against a fake PowerDNS and reads the result through SystemHealth, the way the status page does.
 */
class DnsDeploymentHealthTest extends TestCase
{
    use RefreshDatabase;

    private int $patchStatus = 204;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        PlatformDnsSetting::query()->create(['id' => 1, 'platform_domain' => 'cdnf.test', 'proxy_hostname' => 'proxy.cdnf.test',
            'nameservers' => [['hostname' => 'ns1.cdnf.test', 'ipv4' => '192.0.2.1'], ['hostname' => 'ns2.cdnf.test', 'ipv4' => '192.0.2.2']],
            'soa_primary' => 'ns1.cdnf.test', 'soa_mailbox' => 'hostmaster.cdnf.test', 'soa_refresh' => 3600, 'soa_retry' => 600, 'soa_expire' => 604800, 'soa_minimum_ttl' => 300, 'default_ttl' => 300]);
        DnsCluster::query()->create(['name' => 'dns-1', 'location' => 'x', 'enabled' => true, 'last_health_status' => 'healthy', 'last_health_at' => now(),
            'api_url' => 'http://pdns.test', 'api_key' => 'k', 'server_id' => 'localhost', 'nameservers' => [['hostname' => 'ns1.cdnf.test'], ['hostname' => 'ns2.cdnf.test']], 'capacity_zones' => 1000]);
        $published = [];
        Http::fake(function (Request $request) use (&$published) {
            if (! str_contains($request->url(), 'pdns.test')) {
                return Http::response('ok', 200);
            }
            $zone = preg_match('#/zones/([^/?]+)#', $request->url(), $matches) ? $matches[1] : null;
            if ($request->method() === 'GET') {
                return $zone === null || ! isset($published[$zone]) ? Http::response([], 404) : Http::response(['rrsets' => $published[$zone]]);
            }
            if ($request->method() === 'PATCH') {
                if ($this->patchStatus >= 400) {
                    return Http::response(['error' => 'refused'], $this->patchStatus);
                }
                $published[$zone] = collect($request->data()['rrsets'])->where('changetype', 'REPLACE')->map(fn (array $rrset): array => collect($rrset)->except('changetype')->all())->values()->all();
            }

            return Http::response([], $request->method() === 'POST' ? 201 : 204);
        });
    }

    private function domain(string $name, string $state = 'pending_verification', array $extra = []): Domain
    {
        return Domain::query()->create(['name' => $name, 'display_name' => $name, 'lifecycle_state' => $state, 'revision' => 1,
            'delegation_nameservers' => ['ns1.cdnf.test', 'ns2.cdnf.test'], ...($state === 'active' ? ['nameservers_verified_at' => now()] : []), ...$extra]);
    }

    private function reconcile(Domain $domain, int $patchStatus = 204): void
    {
        $this->patchStatus = $patchStatus;
        try {
            (new ReconcileDnsZone($domain->id))->handle(app(PowerDnsClient::class));
        } catch (\Throwable) {
            // The job retries; its failed deployment row is what these tests read.
        }
    }

    private function dns(): array
    {
        $components = app(SystemHealth::class)->components();

        return ['dns_deployments' => $components['dns_deployments'], 'authoritative_dns' => $components['authoritative_dns']];
    }

    private function assertHealthy(): void
    {
        $this->assertSame('healthy', $this->dns()['dns_deployments']['status']);
        $this->assertSame('healthy', $this->dns()['authoritative_dns']['status']);
    }

    public function test_pending_unverified_child_competing_and_lost_delegation_domains_never_degrade_the_platform(): void
    {
        foreach (range(1, 22) as $number) {
            $this->reconcile($this->domain("pending{$number}.example"));
        }
        $this->assertSame(22, DnsDeployment::query()->where('status', 'succeeded')->count());

        $holder = $this->domain('held.example', 'active');
        $this->reconcile($holder);
        $claim = $this->domain('held.example', extra: ['delegation_nameservers' => ['ns3.cdnf.test', 'ns4.cdnf.test']]);
        $child = $this->domain('sub.held.example', extra: ['delegation_nameservers' => ['ns5.cdnf.test', 'ns6.cdnf.test']]);
        $this->reconcile($claim);
        $this->reconcile($child);
        $this->assertSame(0, DnsDeployment::query()->whereIn('domain_id', [$claim->id, $child->id])->count(), 'a competing claim and an unverified child publish nothing');

        $live = $this->domain('live.example', 'active');
        $this->reconcile($live);
        $live->forceFill(['delegation_lost_at' => now(), 'revision' => 2])->save();
        $this->reconcile($live->refresh());

        $this->assertSame(0, DnsDeployment::query()->drifted()->count());
        $this->assertHealthy();
        $this->travel(3)->hours();
        $this->assertSame(0, DnsDeployment::query()->drifted()->count());
    }

    public function test_a_platform_failure_degrades_health_after_its_grace_period_and_clears_on_recovery(): void
    {
        $domain = $this->domain('platform.example');
        $this->reconcile($domain, 500);

        $deployment = DnsDeployment::query()->sole();
        $this->assertSame(['failed', 'platform'], [$deployment->status, $deployment->failure_class]);
        $this->assertNotNull($deployment->first_failed_at);
        $this->assertHealthy();

        $this->travel(DnsDeployment::PLATFORM_FAILURE_GRACE_MINUTES + 1)->minutes();
        $this->assertSame('degraded', $this->dns()['dns_deployments']['status']);
        $this->assertSame(1, $this->dns()['dns_deployments']['details']['drifted']);

        $first = $deployment->refresh()->first_failed_at;
        $this->reconcile($domain, 500);
        $this->assertTrue($deployment->refresh()->first_failed_at->equalTo($first), 'a retry keeps the time of the first failure');

        $this->reconcile($domain);
        $deployment->refresh();
        $this->assertSame(['succeeded', null, null], [$deployment->status, $deployment->failure_class, $deployment->first_failed_at]);
        $this->assertHealthy();
    }

    public function test_an_unverified_domain_whose_delegation_zone_fails_because_powerdns_is_down_still_counts(): void
    {
        $this->reconcile($this->domain('down.example'), 503);
        $this->travel(DnsDeployment::PLATFORM_FAILURE_GRACE_MINUTES + 1)->minutes();

        $this->assertSame(1, DnsDeployment::query()->drifted()->count());
        $this->assertSame('degraded', $this->dns()['dns_deployments']['status']);
    }

    public function test_a_zone_content_rejection_is_customer_health_and_not_a_platform_fault(): void
    {
        $domain = $this->domain('content.example', 'active');
        $this->reconcile($domain, 422);

        $deployment = DnsDeployment::query()->sole();
        $this->assertSame(['failed', 'customer_content'], [$deployment->status, $deployment->failure_class]);
        $this->travel(3)->hours();
        $this->assertSame(0, DnsDeployment::query()->drifted()->count());
        $this->assertSame(1, DnsDeployment::query()->customerRejected()->count());
        $this->assertHealthy();
        $this->assertSame(1, $this->dns()['dns_deployments']['details']['customer_rejected']);

        $this->reconcile($domain);
        $this->assertSame(0, DnsDeployment::query()->customerRejected()->count());
    }

    public function test_the_owner_and_an_administrator_see_the_failure_class_but_only_the_administrator_sees_the_error(): void
    {
        $domain = $this->domain('visible.example', 'active');
        $owner = User::factory()->create();
        $domain->users()->attach($owner);
        $this->reconcile($domain, 422);

        $row = $this->actingAs(User::factory()->admin()->create())->getJson('/api/admin/dns/deployments')->assertOk()->json('data.0');
        $this->assertSame('customer_content', $row['failure_class']);
        $this->assertStringContainsString('rejected', $row['last_error']);

        $mine = $this->actingAs($owner)->getJson("/api/domains/{$domain->id}/dns/deployment")->assertOk()->json('data.deployments.0');
        $this->assertSame(['failed', 'customer_content'], [$mine['status'], $mine['failure_class']]);
        $this->assertArrayNotHasKey('last_error', $mine);
    }

    public function test_a_surge_of_content_rejections_is_a_platform_fault(): void
    {
        foreach (range(1, DnsDeployment::CUSTOMER_REJECTION_SURGE) as $number) {
            $this->reconcile($this->domain("surge{$number}.example", 'active'), 422);
        }

        $this->assertSame(DnsDeployment::CUSTOMER_REJECTION_SURGE, DnsDeployment::query()->customerRejected()->count());
        $this->travel(3)->minutes();
        $this->assertSame(DnsDeployment::CUSTOMER_REJECTION_SURGE, DnsDeployment::query()->drifted()->count());
        $this->assertSame('degraded', $this->dns()['dns_deployments']['status']);

        // Rejections older than the window no longer indicate a platform fault.
        $this->travel(DnsDeployment::CUSTOMER_REJECTION_WINDOW_MINUTES + 1)->minutes();
        $this->assertSame(0, DnsDeployment::query()->drifted()->count());
    }

    public function test_a_takeover_winners_pending_row_counts_only_when_it_stops_being_planned_work(): void
    {
        $holder = $this->domain('taken.example', 'active');
        $this->reconcile($holder);
        $winner = $this->domain('taken.example', extra: ['delegation_nameservers' => ['ns3.cdnf.test', 'ns4.cdnf.test']]);
        $retire = new \ReflectionMethod(DomainNameserverVerification::class, 'retireCompetingClaims');
        $retire->invoke(app(DomainNameserverVerification::class), $winner, null, null);

        $pending = DnsDeployment::query()->where('domain_id', $winner->id)->sole();
        $this->assertSame(['pending', false], [$pending->status, $pending->tombstone]);
        $this->assertSame(0, DnsDeployment::query()->drifted()->count());
        $this->assertHealthy();

        $this->travel(DnsDeployment::PLANNED_PENDING_GRACE_MINUTES + 1)->minutes();
        $this->assertSame(1, DnsDeployment::query()->drifted()->count());
    }

    public function test_a_failed_retirement_is_a_platform_fault(): void
    {
        $domain = $this->domain('gone.example', 'deprovisioning');
        DnsDeployment::query()->create(['domain_id' => $domain->id, 'dns_cluster_id' => DnsCluster::query()->value('id'), 'desired_revision' => 1, 'status' => 'failed', 'tombstone' => true]);

        $this->assertSame(1, DnsDeployment::query()->drifted()->count());
        $this->assertSame('degraded', $this->dns()['dns_deployments']['status']);
    }

    public function test_a_failed_cluster_health_check_still_degrades_the_nameservers(): void
    {
        DnsCluster::query()->update(['last_health_status' => 'unreachable']);

        $this->assertSame('degraded', $this->dns()['authoritative_dns']['status']);
    }

    public function test_the_metrics_split_platform_drift_from_customer_rejections(): void
    {
        $this->reconcile($this->domain('metric.example', 'active'), 422);
        config(['services.metrics.token' => 'metrics-test-token']);
        $this->app['auth']->forgetGuards();
        $body = $this->withToken('metrics-test-token')->get('/metrics')->assertOk()->getContent();

        $this->assertStringContainsString("cdnfoundry_dns_deployments_drifted 0\n", $body);
        $this->assertStringContainsString("cdnfoundry_dns_deployments_customer_rejected 1\n", $body);
    }

    public function test_repair_looks_at_platform_failures_before_customer_content_rejections(): void
    {
        $customer = $this->domain('first.example', 'active');
        $platform = $this->domain('second.example', 'active');
        $this->reconcile($customer, 422);
        $this->reconcile($platform, 500);
        Queue::fake();

        $this->artisan('cdnf:dns:repair-drift', ['--limit' => 1])->assertSuccessful();

        Queue::assertPushed(ReconcileDnsZone::class, fn (ReconcileDnsZone $job): bool => $job->domainId === $platform->id);
        Queue::assertNotPushed(ReconcileDnsZone::class, fn (ReconcileDnsZone $job): bool => $job->domainId === $customer->id);
    }
}
