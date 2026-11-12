<?php

namespace Tests\Feature;

use App\Actions\RecordSecurityReport;
use App\Jobs\ReconcileEdgeDomain;
use App\Models\AuditLog;
use App\Models\Domain;
use App\Models\DomainEdgeCell;
use App\Models\Edge;
use App\Models\EdgeRevision;
use App\Models\EdgeTask;
use App\Support\SecurityConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class AutomaticSecurityStateTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_hour_of_noisy_heartbeats_creates_no_revision_and_only_overlay_tasks(): void
    {
        Queue::fake();
        [$edge, $domain] = $this->servedDomain('automatic');
        $revisions = EdgeRevision::query()->count();

        // A cumulative cell counter that keeps growing for an hour, reported every five seconds.
        foreach (range(0, 719) as $step) {
            $this->travelTo(now()->startOfHour()->addSeconds($step * 5));
            $this->report($edge, $domain, 'client_rate_exceeded', ($step + 1) * 4);
        }

        $domain->refresh();
        $this->assertSame(3, $domain->revision, 'automatic state never bumps the configuration revision');
        $this->assertSame($revisions, EdgeRevision::query()->count());
        $this->assertSame('restricted', $domain->automatic_security_state);
        $this->assertSame('normal', $domain->security_state, 'the operator state is untouched');
        Queue::assertNotPushed(ReconcileEdgeDomain::class);

        $overlays = EdgeTask::query()->where('type', 'security_overlay')->orderBy('created_at')->get();
        $this->assertNotEmpty($overlays);
        $this->assertLessThanOrEqual(2, $overlays->count(), 'one task per state change, not one per heartbeat');
        $last = $overlays->last()->payload;
        $this->assertSame('restricted', $last['state']);
        $this->assertSame($domain->security_overlay_sequence, $last['overlay_sequence']);
        $this->assertSame(SecurityConfig::overlay($domain)['limits'], $last['limits']);
        $this->assertSame(2, AuditLog::query()->where('action', 'security.automatic_state_changed')->count());
    }

    public function test_a_slow_trickle_never_escalates_but_a_burst_does(): void
    {
        Queue::fake();
        [$edge, $domain] = $this->servedDomain('automatic');
        $total = 0;
        foreach (range(0, 59) as $minute) {
            $this->travelTo(now()->startOfHour()->addMinutes($minute));
            $total += 5;
            $this->report($edge, $domain, 'client_rate_exceeded', $total);
        }
        $this->assertSame('normal', $domain->refresh()->automatic_security_state, '300 events spread over an hour are below 50 per five minutes');

        $this->report($edge, $domain, 'client_rate_exceeded', $total + 60);
        $this->assertSame('suspected', $domain->refresh()->automatic_security_state);
        $this->assertSame(3, $domain->revision);
    }

    public function test_a_counter_reset_on_the_cell_does_not_look_like_a_burst(): void
    {
        Queue::fake();
        [$edge, $domain] = $this->servedDomain('automatic');
        $this->report($edge, $domain, 'client_rate_exceeded', 40);
        $this->report($edge, $domain, 'client_rate_exceeded', 45);
        $this->assertSame(45, RecordSecurityReport::recentRate($domain));
        $this->report($edge, $domain, 'client_rate_exceeded', 3);
        $this->assertSame(48, RecordSecurityReport::recentRate($domain), 'a lower counter is a restart: only its own value is new');
        $this->assertSame('normal', $domain->refresh()->automatic_security_state);
    }

    public function test_quiet_domains_step_back_with_hysteresis_and_without_revisions(): void
    {
        Queue::fake();
        [$edge, $domain] = $this->servedDomain('automatic');
        $this->report($edge, $domain, 'client_rate_exceeded', 150);
        $this->assertSame('restricted', $domain->refresh()->automatic_security_state);

        $this->travel(5)->minutes();
        $this->artisan('cdnf:security:reconcile-readiness')->assertSuccessful();
        $this->assertSame('restricted', $domain->refresh()->automatic_security_state, 'not quiet long enough');

        $this->travel(6)->minutes();
        $this->artisan('cdnf:security:reconcile-readiness')->assertSuccessful();
        $this->assertSame('recovering', $domain->refresh()->automatic_security_state);
        $this->artisan('cdnf:security:reconcile-readiness')->assertSuccessful();
        $this->assertSame('normal', $domain->refresh()->automatic_security_state);
        $this->assertSame(3, $domain->revision);
        $this->assertSame('normal', EdgeTask::query()->where('type', 'security_overlay')->latest('created_at')->first()->payload['state']);
    }

    public function test_a_customer_rule_hit_flood_never_changes_state(): void
    {
        Queue::fake();
        [$edge, $domain] = $this->servedDomain('automatic');
        foreach (range(1, 20) as $step) {
            $this->report($edge, $domain, 'domain_restricted', $step * 100);
        }
        $this->assertSame('normal', $domain->refresh()->automatic_security_state);
        $this->assertSame(0, EdgeTask::query()->where('type', 'security_overlay')->count());
    }

    public function test_a_domain_the_operator_restricted_is_left_to_the_operator(): void
    {
        Queue::fake();
        [$edge, $domain] = $this->servedDomain('automatic');
        $domain->update(['security_state' => 'restricted']);
        $this->report($edge, $domain, 'client_rate_exceeded', 500);
        $this->assertSame('normal', $domain->refresh()->automatic_security_state);
    }

    public function test_an_edge_that_receives_the_domain_later_gets_the_current_overlay(): void
    {
        Queue::fake();
        [$edge, $domain] = $this->servedDomain('automatic');
        $this->report($edge, $domain, 'client_rate_exceeded', 80);
        $other = Edge::query()->create(['name' => 'late-pop', 'country_code' => 'DE', 'continent_code' => 'EU']);
        $cell = $other->cells()->create(['slot' => 1, 'status' => 'ready']);
        DomainEdgeCell::query()->create(['domain_id' => $domain->id, 'edge_id' => $other->id, 'replica' => 1, 'active_cell_id' => $cell->id, 'desired_revision' => 3, 'state' => 'active']);

        \App\Actions\ChangeAutomaticSecurityState::dispatchOverlay($domain->refresh());

        $task = EdgeTask::query()->where('edge_id', $other->id)->where('type', 'security_overlay')->sole();
        $this->assertSame('suspected', $task->payload['state']);
        $this->assertSame($domain->security_overlay_sequence, $task->payload['overlay_sequence']);
    }

    /** @return array{0: Edge, 1: Domain} */
    private function servedDomain(string $policy): array
    {
        $edge = Edge::query()->create(['name' => 'overlay-pop', 'country_code' => 'IR', 'continent_code' => 'AS']);
        $cell = $edge->cells()->create(['slot' => 1, 'status' => 'ready']);
        $domain = Domain::query()->create([
            'name' => 'overlay.test', 'display_name' => 'Overlay', 'lifecycle_state' => 'active', 'revision' => 3,
            'security_settings' => ['quarantine_policy' => $policy] + SecurityConfig::defaults(),
        ]);
        $domain->dnsRecords()->create(['name' => 'overlay.test', 'type' => 'A', 'content' => '192.0.2.1', 'content_hash' => hash('sha256', '192.0.2.1'), 'ttl' => 60, 'mode' => 'proxied']);
        DomainEdgeCell::query()->create(['domain_id' => $domain->id, 'edge_id' => $edge->id, 'replica' => 1, 'active_cell_id' => $cell->id, 'desired_revision' => 3, 'state' => 'active']);

        return [$edge, $domain];
    }

    private function report(Edge $edge, Domain $domain, string $reason, int $cumulative): void
    {
        RecordSecurityReport::execute($edge, [
            'domain_id' => $domain->id, 'hostname' => $domain->name, 'reason_code' => $reason, 'count' => $cumulative, 'occurred_at' => now()->timestamp,
        ]);
    }
}
