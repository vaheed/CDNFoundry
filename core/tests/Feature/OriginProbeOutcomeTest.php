<?php

namespace Tests\Feature;

use App\Jobs\DispatchOriginTest;
use App\Models\DnsRecord;
use App\Models\Domain;
use App\Models\DomainEdgeCell;
use App\Models\Edge;
use App\Models\EdgeTask;
use App\Models\Operation;
use App\Support\OriginHealth;
use App\Support\OriginMonitoring;
use App\Support\OriginProbeOutcome;
use App\Support\OriginProbePlanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class OriginProbeOutcomeTest extends TestCase
{
    use RefreshDatabase;

    private DnsRecord $record;

    private Operation $operation;

    /** @var array<string, list<Edge>> */
    private array $edges = [];

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->enableOriginMonitoring();
    }

    /** @param array<string, int> $perContinent edges in each continent, which is the probe group here */
    private function scene(array $perContinent): void
    {
        $domain = Domain::query()->create(['name' => 'outcome.example', 'display_name' => 'O', 'lifecycle_state' => 'active', 'revision' => 1, 'nameservers_verified_at' => now()]);
        $this->record = $domain->dnsRecords()->create(['name' => 'outcome.example', 'type' => 'A', 'content' => '8.8.8.8', 'content_hash' => hash('sha256', '8.8.8.8'), 'mode' => 'proxied', 'ttl' => 60,
            'origin' => ['host' => '8.8.8.8', 'port' => 80, 'scheme' => 'http', 'host_header' => 'outcome.example', 'sni' => null, 'verify_tls' => false, 'connect_timeout_ms' => 1000, 'response_timeout_ms' => 5000, 'retry_count' => 0, 'websocket' => false, 'health_check' => ['enabled' => true, 'path' => '/', 'interval_seconds' => 60]]]);
        foreach ($perContinent as $continent => $count) {
            foreach (range(1, $count) as $index) {
                $edge = Edge::query()->create(['name' => "$continent-$index", 'country_code' => 'IR', 'continent_code' => $continent, 'enabled' => true, 'registered_at' => now(), 'last_heartbeat_at' => now()]);
                DomainEdgeCell::query()->create(['domain_id' => $domain->id, 'edge_id' => $edge->id, 'desired_revision' => 1, 'state' => 'active']);
                $this->edges[$continent][] = $edge;
            }
        }
        $this->operation = Operation::query()->create(['type' => 'edge.origin_test', 'status' => 'pending', 'input' => ['domain_id' => $domain->id, 'record_id' => $this->record->id,
            'origin_role' => 'primary', 'edge_ids' => [], 'addresses' => ['8.8.8.8'], 'scheduled' => true, 'origin_checksum' => OriginHealth::checksum($this->record->origin)]]);
        (new DispatchOriginTest($this->operation->id))->handle();
    }

    /** The agent's answer for a task: healthy, or unhealthy with a reason. */
    private function answer(EdgeTask $task, bool $healthy): void
    {
        $task->update(['status' => $healthy ? 'succeeded' : 'failed', 'result' => ['status' => $healthy ? 'healthy' : 'unhealthy', 'latency_ms' => 20, 'failure_reason' => $healthy ? null : 'connect_timeout', 'edge_id' => $task->edge_id]]);
        OriginProbeOutcome::taskAnswered($task->refresh(), $this->operation->refresh(), $this->record->refresh());
    }

    /** @return Collection<int, EdgeTask> */
    private function tasks()
    {
        return EdgeTask::query()->where('payload->operation_id', $this->operation->id)->orderBy('created_at')->get();
    }

    private function state(): array
    {
        return OriginHealth::summarize($this->record->refresh())['roles']['primary']['signals']['active'];
    }

    public function test_all_groups_healthy_is_reachable_and_names_no_failure(): void
    {
        $this->scene(['EU' => 2, 'AS' => 2, 'NA' => 2]);
        foreach ($this->tasks() as $task) {
            $this->answer($task, true);
        }
        $this->assertSame('healthy', $this->state()['state']);
        $this->assertSame('reachable', $this->record->refresh()->origin_signals['primary']['active']['scope']);
        $this->assertCount(3, $this->tasks(), 'no confirmation was needed');
    }

    public function test_a_failing_worker_is_confirmed_by_another_worker_of_its_group_and_a_worker_fault_is_not_an_origin_fault(): void
    {
        $this->scene(['EU' => 2, 'AS' => 2, 'NA' => 2]);
        $tasks = $this->tasks();
        $this->answer($tasks[0], false);
        $this->answer($tasks[1], true);
        $this->answer($tasks[2], true);
        $all = $this->tasks();
        $this->assertCount(4, $all, 'one confirmation was added');
        $confirmation = $all->first(fn (EdgeTask $task): bool => isset($task->payload['confirmation_of']));
        $this->assertSame($tasks[0]->id, $confirmation->payload['confirmation_of']);
        $this->assertNotSame($tasks[0]->edge_id, $confirmation->edge_id);
        $this->assertSame(OriginProbePlanner::group(Edge::find($tasks[0]->edge_id)), OriginProbePlanner::group(Edge::find($confirmation->edge_id)), 'the same group is asked first');
        $this->assertSame('pending', $confirmation->status);
        $this->assertArrayNotHasKey('active', $this->record->refresh()->origin_signals['primary'] ?? [], 'nothing is recorded until the failure is confirmed or cleared');

        $this->answer($confirmation, true);
        $signal = $this->record->refresh()->origin_signals['primary']['active'];
        $this->assertSame('healthy', $signal['status']);
        $this->assertSame(1, $signal['worker_faults']);
        $this->assertSame('healthy', $this->state()['state']);
    }

    public function test_a_failure_every_group_confirms_is_an_origin_failure(): void
    {
        $this->scene(['EU' => 2, 'AS' => 2, 'NA' => 2]);
        foreach ($this->tasks() as $task) {
            $this->answer($task, false);
        }
        $confirmation = $this->tasks()->first(fn (EdgeTask $task): bool => isset($task->payload['confirmation_of']));
        $this->assertNotNull($confirmation);
        $this->answer($confirmation, false);
        $this->assertSame('unhealthy', $this->state()['state']);
        $this->assertSame('origin', $this->record->refresh()->origin_signals['primary']['active']['scope']);
        $this->assertCount(4, $this->tasks(), 'one confirmation only, inside the budget of the origin');
    }

    public function test_a_failure_from_one_group_only_is_regional_and_shows_as_degraded(): void
    {
        $this->scene(['EU' => 2, 'AS' => 2, 'NA' => 2]);
        $tasks = $this->tasks();
        $this->answer($tasks[0], false);
        $this->answer($tasks[1], true);
        $this->answer($tasks[2], true);
        $this->answer($this->tasks()->first(fn (EdgeTask $task): bool => isset($task->payload['confirmation_of'])), false);
        $signal = $this->record->refresh()->origin_signals['primary']['active'];
        $this->assertSame('regional', $signal['scope']);
        $this->assertNotSame('', $signal['failed_groups']);
        $this->assertSame('degraded', $this->state()['state'], 'a regional connectivity failure is not a confirmed origin failure');
    }

    public function test_a_failure_nobody_can_confirm_is_unconfirmed_and_never_unhealthy(): void
    {
        $this->scene(['EU' => 1]);
        $this->answer($this->tasks()[0], false);
        $this->assertCount(1, $this->tasks(), 'there is no other worker to ask');
        $signal = $this->record->refresh()->origin_signals['primary']['active'];
        $this->assertSame('unconfirmed', $signal['scope']);
        $this->assertSame('degraded', $this->state()['state']);
    }

    public function test_a_group_nobody_can_probe_is_listed_as_not_monitored_and_not_counted_healthy(): void
    {
        $this->scene(['EU' => 1, 'AS' => 1]);
        Edge::query()->where('continent_code', 'AS')->update(['last_heartbeat_at' => now()->subHour()]);
        $this->operation->update(['status' => 'pending']);
        EdgeTask::query()->where('payload->operation_id', $this->operation->id)->delete();
        (new DispatchOriginTest($this->operation->id))->handle();
        foreach ($this->tasks() as $task) {
            $this->answer($task, true);
        }
        $signal = $this->record->refresh()->origin_signals['primary']['active'];
        $this->assertSame('continent:AS', $signal['not_monitored']);
        $this->assertSame('healthy', $signal['status']);
    }

    public function test_a_failing_origin_is_checked_less_often_but_never_by_more_than_five_times_its_interval(): void
    {
        $this->assertSame(60, OriginMonitoring::stretched(60, 0));
        $this->assertSame(120, OriginMonitoring::stretched(60, 1));
        $this->assertSame(300, OriginMonitoring::stretched(60, 4));
        $this->assertSame(300, OriginMonitoring::stretched(60, 40), 'the stretch is capped');

        $this->scene(['EU' => 1]);
        foreach (range(1, 3) as $round) {
            EdgeTask::query()->where('payload->operation_id', $this->operation->id)->delete();
            $this->operation->update(['status' => 'pending']);
            (new DispatchOriginTest($this->operation->id))->handle();
            $this->answer($this->tasks()[0], false);
            $this->assertSame($round, $this->record->refresh()->origin_signals['primary']['active']['failure_streak']);
        }
        EdgeTask::query()->where('payload->operation_id', $this->operation->id)->delete();
        $this->operation->update(['status' => 'pending']);
        (new DispatchOriginTest($this->operation->id))->handle();
        $this->answer($this->tasks()[0], true);
        $this->assertSame(0, $this->record->refresh()->origin_signals['primary']['active']['failure_streak'], 'a reachable origin returns to its own interval');
    }
}
