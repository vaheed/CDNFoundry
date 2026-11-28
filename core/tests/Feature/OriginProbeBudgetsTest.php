<?php

namespace Tests\Feature;

use App\Jobs\DispatchOriginTest;
use App\Models\DnsRecord;
use App\Models\Domain;
use App\Models\DomainEdgeCell;
use App\Models\Edge;
use App\Models\EdgeTask;
use App\Models\Operation;
use App\Models\SystemSetting;
use App\Models\User;
use App\Support\OriginHealth;
use App\Support\OriginMonitoring;
use App\Support\PlatformSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class OriginProbeBudgetsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->enableOriginMonitoring();
    }

    /** @param array<string, mixed> $originOverrides */
    private function domainWithRecord(string $name, array $originOverrides = [], array $edges = []): array
    {
        $domain = Domain::query()->create(['name' => $name, 'display_name' => $name, 'lifecycle_state' => 'active', 'revision' => 1, 'nameservers_verified_at' => now()]);
        $origin = [...['host' => '8.8.8.8', 'port' => 80, 'scheme' => 'http', 'host_header' => $name, 'sni' => null, 'verify_tls' => false, 'connect_timeout_ms' => 1000, 'response_timeout_ms' => 5000,
            'retry_count' => 0, 'websocket' => false, 'health_check' => ['enabled' => true, 'path' => '/', 'interval_seconds' => 60]], ...$originOverrides];
        $record = $domain->dnsRecords()->create(['name' => $name, 'type' => 'A', 'content' => '8.8.8.8', 'content_hash' => hash('sha256', $name), 'mode' => 'proxied', 'ttl' => 60, 'origin' => $origin]);
        foreach ($edges as $edge) {
            DomainEdgeCell::query()->create(['domain_id' => $domain->id, 'edge_id' => $edge->id, 'desired_revision' => 1, 'state' => 'active']);
        }

        return [$domain, $record];
    }

    private function edge(string $name, string $continent = 'EU'): Edge
    {
        return Edge::query()->create(['name' => $name, 'country_code' => 'IR', 'continent_code' => $continent, 'enabled' => true, 'registered_at' => now(), 'last_heartbeat_at' => now()]);
    }

    private function scheduled(DnsRecord $record): Operation
    {
        $operation = Operation::query()->create(['type' => 'edge.origin_test', 'status' => 'pending', 'input' => ['domain_id' => $record->domain_id, 'record_id' => $record->id,
            'origin_role' => 'primary', 'edge_ids' => [], 'addresses' => ['8.8.8.8'], 'scheduled' => true, 'origin_checksum' => OriginHealth::checksum($record->origin)]]);
        (new DispatchOriginTest($operation->id))->handle();

        return $operation->refresh();
    }

    private function task(Edge $edge, string $status, array $result = [], ?int $domainId = null, string $destination = '8.8.8.8:80'): void
    {
        EdgeTask::query()->create(['id' => (string) Str::uuid(), 'edge_id' => $edge->id, 'type' => 'origin_test', 'status' => $status, 'result' => $result,
            'payload' => ['operation_id' => (string) Str::uuid(), 'domain_id' => $domainId ?? 0, 'destination' => $destination]]);
    }

    public function test_an_account_cannot_raise_its_probe_capacity_by_adding_domains(): void
    {
        $row = SystemSetting::query()->findOrFail('origin_monitoring');
        $row->update(['values' => [...$row->values, 'account_probes_per_minute' => 3, 'domain_probes_per_minute' => 100]]);
        app()->forgetInstance(PlatformSettings::class);
        $edge = $this->edge('eu-1');
        [$first] = $this->domainWithRecord('one.example', [], [$edge]);
        [$second, $record] = $this->domainWithRecord('two.example', ['host' => '1.1.1.1'], [$edge]);
        $user = User::factory()->create();
        $first->users()->attach($user);
        $second->users()->attach($user);
        foreach (range(1, 3) as $unused) {
            $this->task($edge, 'succeeded', ['status' => 'healthy', 'latency_ms' => 10], $first->id, '9.9.9.9:80');
        }

        $this->assertSame(0, OriginMonitoring::allowance('1.1.1.1:80', $second->id, 1), 'the three probes of the first domain used the account budget');
        $other = User::factory()->create();
        [$third] = $this->domainWithRecord('three.example', [], [$edge]);
        $third->users()->attach($other);
        $this->assertSame(1, OriginMonitoring::allowance('1.1.1.1:80', $third->id, 1), 'another account is not affected');
        $operation = $this->scheduled($record);
        $this->assertSame('failed', $operation->status);
        $this->assertSame(0, EdgeTask::query()->where('payload->operation_id', $operation->id)->count());
    }

    public function test_a_worker_over_its_probe_time_budget_is_skipped_and_another_worker_of_its_group_takes_the_probe(): void
    {
        $busy = $this->edge('eu-busy');
        $free = $this->edge('eu-free');
        [, $record] = $this->domainWithRecord('budget.example', [], [$busy, $free]);
        // 48 failed probes are charged the 5 second timeout each: 240 slot-seconds, the whole minute's budget.
        foreach (range(1, 48) as $unused) {
            $this->task($busy, 'failed', ['status' => 'unhealthy'], 0, '7.7.7.7:80');
            $this->task($free, 'succeeded', ['status' => 'healthy', 'latency_ms' => 100], 0, '7.7.7.7:80');
        }
        $this->assertFalse(OriginMonitoring::workerHasCapacity($busy->id));
        $this->assertTrue(OriginMonitoring::workerHasCapacity($free->id));
        $this->assertEqualsWithDelta(48 * 0.5, OriginMonitoring::workerLoad($free->id), 0.001, 'a healthy probe is charged its measured time, at least half a second');

        $operation = $this->scheduled($record);
        $tasks = EdgeTask::query()->where('payload->operation_id', $operation->id)->get();
        $this->assertCount(1, $tasks);
        $this->assertSame($free->id, $tasks->first()->edge_id);
    }

    public function test_probes_that_test_the_same_thing_are_one_probe_and_the_result_is_copied_without_the_observer(): void
    {
        $edge = $this->edge('eu-1');
        [, $first] = $this->domainWithRecord('alpha.example', ['host_header' => 'shared.example'], [$edge]);
        [, $second] = $this->domainWithRecord('beta.example', ['host_header' => 'shared.example'], [$edge]);
        $groups = [['group' => 'continent:EU', 'edge_id' => $edge->id]];
        OriginHealth::record($first, 'primary', 'active', ['status' => 'healthy', 'observer' => $edge->id, 'at' => now()->toIso8601String(), 'latency_ms' => 42, 'scope' => 'reachable',
            'fingerprint' => OriginMonitoring::fingerprint($first->origin, $groups)]);

        $operation = $this->scheduled($second);

        $this->assertSame('succeeded', $operation->status);
        $this->assertSame(0, EdgeTask::query()->where('payload->operation_id', $operation->id)->count(), 'no probe left the control plane');
        $signal = $second->refresh()->origin_signals['primary']['active'];
        $this->assertTrue($signal['shared']);
        $this->assertSame('healthy', $signal['status']);
        $this->assertSame(42, $signal['latency_ms']);
        $this->assertArrayNotHasKey('observer', $signal, 'which edge probed is not copied between domains');
        $this->assertSame('healthy', OriginHealth::summarize($second)['roles']['primary']['state']);
    }

    public function test_a_different_sni_a_different_path_or_a_stale_result_is_probed_for_itself(): void
    {
        $edge = $this->edge('eu-1');
        [, $first] = $this->domainWithRecord('alpha.example', [], [$edge]);
        $groups = [['group' => 'continent:EU', 'edge_id' => $edge->id]];
        OriginHealth::record($first, 'primary', 'active', ['status' => 'healthy', 'at' => now()->toIso8601String(), 'scope' => 'reachable', 'fingerprint' => OriginMonitoring::fingerprint($first->origin, $groups)]);

        [, $otherPath] = $this->domainWithRecord('gamma.example', ['health_check' => ['enabled' => true, 'path' => '/health', 'interval_seconds' => 60]], [$edge]);
        [, $otherSni] = $this->domainWithRecord('delta.example', ['sni' => 'delta.example'], [$edge]);
        foreach ([$otherPath, $otherSni] as $record) {
            $operation = $this->scheduled($record);
            $this->assertSame(1, EdgeTask::query()->where('payload->operation_id', $operation->id)->count(), $record->name);
            $this->assertArrayNotHasKey('shared', $record->refresh()->origin_signals['primary']['active'] ?? []);
        }

        OriginHealth::record($first, 'primary', 'active', ['status' => 'healthy', 'at' => now()->subMinutes(10)->toIso8601String(), 'scope' => 'reachable', 'fingerprint' => OriginMonitoring::fingerprint($first->origin, $groups)]);
        [, $same] = $this->domainWithRecord('epsilon.example', [], [$edge]);
        $operation = $this->scheduled($same);
        $this->assertSame(1, EdgeTask::query()->where('payload->operation_id', $operation->id)->count(), 'a result older than the minimum interval is not reused');
    }

    public function test_a_manual_test_is_never_merged_into_a_shared_result(): void
    {
        $edge = $this->edge('eu-1');
        [, $first] = $this->domainWithRecord('alpha.example', [], [$edge]);
        $groups = [['group' => 'continent:EU', 'edge_id' => $edge->id]];
        OriginHealth::record($first, 'primary', 'active', ['status' => 'healthy', 'at' => now()->toIso8601String(), 'scope' => 'reachable', 'fingerprint' => OriginMonitoring::fingerprint($first->origin, $groups)]);
        [, $second] = $this->domainWithRecord('beta.example', [], [$edge]);
        $user = User::factory()->create();
        $second->domain->users()->attach($user);
        $operation = Operation::query()->create(['actor_id' => $user->id, 'type' => 'edge.origin_test', 'status' => 'pending', 'input' => ['domain_id' => $second->domain_id, 'record_id' => $second->id,
            'origin_role' => 'primary', 'edge_ids' => [], 'addresses' => ['8.8.8.8'], 'origin_checksum' => OriginHealth::checksum($second->origin)]]);
        (new DispatchOriginTest($operation->id))->handle();
        $this->assertSame(1, EdgeTask::query()->where('payload->operation_id', $operation->id)->count());
    }

    /** @return array<string, int> */
    private function fleetProbeCounts(int $perContinent): array
    {
        $edges = [];
        foreach (['EU', 'AS', 'NA', 'SA', 'AF', 'OC'] as $continent) {
            foreach (range(1, $perContinent) as $index) {
                $edges[] = $this->edge("$continent-$index-$perContinent", $continent);
            }
        }
        $counts = [];
        foreach (range(1, 20) as $index) {
            [, $record] = $this->domainWithRecord("fleet$perContinent-$index.example", ['host' => '5.5.5.'.$index, 'host_header' => "fleet$perContinent-$index.example"], $edges);
            $counts[] = EdgeTask::query()->where('payload->operation_id', $this->scheduled($record)->id)->count();
        }

        return ['per_origin_min' => min($counts), 'per_origin_max' => max($counts), 'total' => array_sum($counts)];
    }

    public function test_the_number_of_probes_follows_the_budget_and_not_the_number_of_edges(): void
    {
        $small = $this->fleetProbeCounts(1);   // 6 edges, one per continent
        $large = $this->fleetProbeCounts(34);  // 204 edges
        $this->assertSame(['per_origin_min' => 3, 'per_origin_max' => 3, 'total' => 60], $small);
        $this->assertSame($small, $large, 'at 6 and at 204 edges the same 20 origins receive the same 60 probes');
    }
}
