<?php

namespace Tests\Feature;

use App\Http\Controllers\EdgeAgentController;
use App\Models\Domain;
use App\Models\DomainEdgeCell;
use App\Models\Edge;
use App\Models\Operation;
use App\Models\SecurityEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class SecurityEventRetentionTest extends TestCase
{
    use RefreshDatabase;

    public function test_repeated_reports_in_one_hour_keep_one_row_per_domain_edge_and_reason(): void
    {
        Queue::fake();
        [$edge, $domain] = $this->assignedDomain();
        $hour = now()->utc()->toImmutable()->startOfHour();

        foreach (range(1, 60) as $minute) {
            $this->report($edge, $domain, 'client_rate_exceeded', $minute, $hour->addSeconds($minute * 30)->timestamp);
        }
        $this->report($edge, $domain, 'body_too_large', 3, $hour->addMinute()->timestamp);
        $this->report($edge, $domain, 'client_rate_exceeded', 7, $hour->addHours(1)->addMinute()->timestamp);

        $rows = SecurityEvent::query()->where('domain_id', $domain->id)->orderBy('occurred_at')->get();
        $this->assertCount(3, $rows);
        $this->assertSame(60, $rows->firstWhere(fn (SecurityEvent $row): bool => $row->reason_code === 'client_rate_exceeded' && $row->bucket_at->equalTo($hour))->details['count']);
    }

    public function test_a_year_of_hourly_activity_stays_inside_the_retention_bound(): void
    {
        $domain = Domain::query()->create(['name' => 'year.test', 'display_name' => 'Year', 'lifecycle_state' => 'active', 'revision' => 1]);
        $rows = [];
        foreach (range(0, 365 * 24 - 1) as $hoursAgo) {
            $at = now()->startOfHour()->subHours($hoursAgo);
            foreach (['client_rate_exceeded', 'body_too_large'] as $reason) {
                $rows[] = ['domain_id' => $domain->id, 'reason_code' => $reason, 'state' => 'normal', 'details' => '{"count":5}', 'occurred_at' => $at, 'bucket_at' => $at, 'created_at' => $at, 'updated_at' => $at];
            }
        }
        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('security_events')->insert($chunk);
        }
        $this->assertSame(365 * 24 * 2, SecurityEvent::query()->count());

        do {
            $this->artisan('cdnf:security-events:prune', ['--batch' => 5000])->assertSuccessful();
        } while (SecurityEvent::query()->where('occurred_at', '<', now()->subDays(30))->exists());

        $this->assertLessThanOrEqual(30 * 24 * 2 + 2, SecurityEvent::query()->count());
        $this->assertGreaterThan(30 * 24 * 2 - 4, SecurityEvent::query()->count(), 'rows inside the window are kept');
    }

    public function test_finished_operations_expire_but_unfinished_and_unacknowledged_failures_stay(): void
    {
        $old = now()->subDays(120);
        $make = fn (string $status, ?\DateTimeInterface $finished, ?\DateTimeInterface $acknowledged = null): string => tap((string) Str::uuid(), function (string $id) use ($status, $finished, $acknowledged): void {
            DB::table('operations')->insert(['id' => $id, 'type' => 'dns.zone_reconcile', 'status' => $status, 'input' => '{}', 'finished_at' => $finished, 'acknowledged_at' => $acknowledged, 'created_at' => $finished ?? now(), 'updated_at' => now()]);
        });
        $succeeded = $make('succeeded', $old);
        $acknowledged = $make('failed', $old, $old);
        $unacknowledged = $make('failed', $old);
        $running = $make('running', null);
        $recent = $make('succeeded', now()->subDay());

        $this->artisan('cdnf:operations:prune')->assertSuccessful();

        $this->assertNull(Operation::query()->find($succeeded));
        $this->assertNull(Operation::query()->find($acknowledged));
        $this->assertNotNull(Operation::query()->find($unacknowledged));
        $this->assertNotNull(Operation::query()->find($running));
        $this->assertNotNull(Operation::query()->find($recent));
    }

    /** @return array{0: Edge, 1: Domain} */
    private function assignedDomain(): array
    {
        $edge = Edge::query()->create(['name' => 'retention-pop', 'country_code' => 'IR', 'continent_code' => 'AS']);
        $cell = $edge->cells()->create(['slot' => 1, 'status' => 'ready']);
        $domain = Domain::query()->create(['name' => 'retention.test', 'display_name' => 'Retention', 'lifecycle_state' => 'active', 'revision' => 1]);
        $domain->dnsRecords()->create(['name' => 'retention.test', 'type' => 'A', 'content' => '192.0.2.1', 'content_hash' => hash('sha256', '192.0.2.1'), 'ttl' => 60, 'mode' => 'proxied']);
        DomainEdgeCell::query()->create(['domain_id' => $domain->id, 'edge_id' => $edge->id, 'replica' => 1, 'active_cell_id' => $cell->id, 'desired_revision' => 1, 'state' => 'active']);

        return [$edge, $domain];
    }

    private function report(Edge $edge, Domain $domain, string $reason, int $count, int $occurredAt): void
    {
        $request = Request::create('/edge/v1/heartbeat', 'POST', [
            'agent_version' => '1.2.0', 'listener_ready' => true, 'active_sequence' => 0,
            'cells' => [['name' => 'cell-01', 'status' => 'ready', 'capacity' => ['active_connections' => 0]]],
            'noisy_domains' => [['domain_id' => $domain->id, 'hostname' => $domain->name, 'reason_code' => $reason, 'count' => $count, 'occurred_at' => $occurredAt]],
        ]);
        $request->attributes->set('edge', $edge);
        app(EdgeAgentController::class)->heartbeat($request);
    }
}
