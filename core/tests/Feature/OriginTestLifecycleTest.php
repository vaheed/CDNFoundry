<?php

namespace Tests\Feature;

use App\Filament\Domain\Resources\Domains\Pages\ViewDomain;
use App\Filament\Domain\Resources\Domains\RelationManagers\DnsRecordsRelationManager;
use App\Http\Controllers\EdgeAgentController;
use App\Jobs\DispatchOriginTest;
use App\Models\DnsRecord;
use App\Models\Domain;
use App\Models\Edge;
use App\Models\EdgeTask;
use App\Models\Operation;
use App\Models\User;
use App\Support\ArtifactSigner;
use App\Support\OriginData;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class OriginTestLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_queued_probe_does_not_combine_new_origin_with_old_addresses(): void
    {
        [$owner, $domain, $record] = $this->fixture();
        $response = $this->actingAs($owner)->postJson("/api/domains/{$domain->id}/dns/records/{$record->id}/origin/test", [])->assertAccepted();
        $operation = Operation::query()->findOrFail($response->json('data.operation_id'));
        $record->update(['origin' => $this->origin('1.1.1.1')]);
        (new DispatchOriginTest($operation->id))->handle();
        $this->assertSame('failed', $operation->refresh()->status);
        $this->assertSame(0, EdgeTask::query()->count());
    }

    public function test_queued_probe_rechecks_actor_assignment_and_disable_state(): void
    {
        [$owner, $domain, $record] = $this->fixture();
        $operation = $this->operation($owner, $domain, $record);
        $domain->users()->detach($owner);
        (new DispatchOriginTest($operation->id))->handle();
        $this->assertSame('failed', $operation->refresh()->status);
        $this->assertSame(0, EdgeTask::query()->count());
        $domain->users()->attach($owner);
        $owner->update(['disabled_at' => now()]);
        $operation = $this->operation($owner, $domain, $record);
        (new DispatchOriginTest($operation->id))->handle();
        $this->assertSame('failed', $operation->refresh()->status);
        $this->assertSame(0, EdgeTask::query()->count());
    }

    public function test_inactive_domains_cannot_dispatch_manual_or_scheduled_probes(): void
    {
        [$owner, $domain, $record] = $this->fixture();
        $origin = $record->origin;
        $origin['health_check'] = ['enabled' => true, 'path' => '/', 'interval_seconds' => 60];
        $record->update(['origin' => $origin, 'created_at' => now()->subHour()]);
        foreach (['pending_verification', 'disabled', 'deprovisioning'] as $state) {
            $domain->update(['lifecycle_state' => $state]);
            $this->actingAs($owner)->postJson("/api/domains/{$domain->id}/dns/records/{$record->id}/origin/test", [])->assertConflict();
            $operation = $this->operation($owner, $domain, $record);
            (new DispatchOriginTest($operation->id))->handle();
            $this->assertSame('failed', $operation->refresh()->status);
        }
        $this->artisan('cdnf:edge:dispatch-origin-checks')->assertSuccessful();
        $this->assertSame(0, Operation::query()->where('input->scheduled', true)->count());
        $this->assertSame(0, EdgeTask::query()->count());
    }

    public function test_late_probe_result_does_not_overwrite_a_changed_origins_health(): void
    {
        [$owner, $domain, $record, $edge] = $this->fixture();
        $operation = $this->operation($owner, $domain, $record);
        (new DispatchOriginTest($operation->id))->handle();
        $task = EdgeTask::query()->where('payload->operation_id', $operation->id)->sole();
        $record->update(['origin' => $this->origin('1.1.1.1')]);
        $this->report($edge, $task);
        $this->assertNull($record->refresh()->origin_health);
        $this->assertSame('succeeded', $task->refresh()->status);
    }

    public function test_current_origin_probe_still_updates_health_and_repeated_dispatch_is_stable(): void
    {
        [$owner, $domain, $record, $edge] = $this->fixture();
        $operation = $this->operation($owner, $domain, $record);
        (new DispatchOriginTest($operation->id))->handle();
        (new DispatchOriginTest($operation->id))->handle();
        $task = EdgeTask::query()->where('payload->operation_id', $operation->id)->sole();
        $this->report($edge, $task);
        $this->assertSame('healthy', $record->refresh()->origin_health['status']);
        $this->assertSame('succeeded', $operation->refresh()->status);
        $before = $operation->result;
        (new DispatchOriginTest($operation->id))->handle();
        $this->assertSame($before, $operation->refresh()->result);
    }

    public function test_current_task_is_delivered_and_retry_retains_recipients_and_progress(): void
    {
        [$owner, $domain, $record, $edge] = $this->fixture();
        $secondEdge = Edge::query()->create(['name' => 'second-probe-edge', 'country_code' => 'IR', 'continent_code' => 'AS', 'enabled' => true,
            'registered_at' => now(), 'last_heartbeat_at' => now()]);
        $operation = $this->operation($owner, $domain, $record);
        (new DispatchOriginTest($operation->id))->handle();
        $request = Request::create('/edge/v1/tasks', 'GET');
        $request->attributes->set('edge', $edge);
        $response = app(EdgeAgentController::class)->tasks($request)->getData(true);
        $task = EdgeTask::query()->where('edge_id', $edge->id)->sole();
        $this->assertSame([$task->id], array_column($response['data'], 'id'));
        $this->report($edge, $task);
        $this->assertSame(1, $operation->refresh()->result['completed']);
        $edge->update(['enabled' => false]);
        Edge::query()->create(['name' => 'newly-available-edge', 'country_code' => 'IR', 'continent_code' => 'AS', 'enabled' => true,
            'registered_at' => now(), 'last_heartbeat_at' => now()]);
        (new DispatchOriginTest($operation->id))->handle();
        $this->assertSame(2, EdgeTask::query()->count());
        $this->assertSame(1, $operation->refresh()->result['completed']);
        $this->report($secondEdge, EdgeTask::query()->where('edge_id', $secondEdge->id)->sole());
        $this->assertSame('succeeded', $operation->refresh()->status);
    }

    public function test_origin_edits_clear_health_and_cancellation_preserves_failed_operation(): void
    {
        [$owner, $domain, $record, $edge] = $this->fixture();
        $operation = $this->operation($owner, $domain, $record);
        (new DispatchOriginTest($operation->id))->handle();
        $record->update(['origin_health' => ['status' => 'healthy']]);
        $this->actingAs($owner)->putJson("/api/domains/{$domain->id}/dns/records/{$record->id}/origin", $this->origin('1.1.1.1'))->assertAccepted();
        $this->assertNull($record->refresh()->origin_health);
        $operation->update(['status' => 'failed', 'error' => 'Cancelled by qualification', 'finished_at' => now()]);
        $request = Request::create('/edge/v1/tasks', 'GET');
        $request->attributes->set('edge', $edge);
        $this->assertSame([], app(EdgeAgentController::class)->tasks($request)->getData(true)['data']);
        $this->assertSame('failed', $operation->refresh()->status);
        $this->assertSame('Cancelled by qualification', $operation->error);
        $this->assertSame('failed', EdgeTask::query()->sole()->status);
    }

    public function test_legacy_unbound_operation_requires_a_fresh_origin_test(): void
    {
        [$owner, $domain, $record] = $this->fixture();
        $operation = $this->operation($owner, $domain, $record);
        $input = $operation->input;
        unset($input['origin_checksum']);
        $operation->update(['input' => $input]);
        (new DispatchOriginTest($operation->id))->handle();
        $this->assertSame('failed', $operation->refresh()->status);
        $this->assertSame(0, EdgeTask::query()->count());
    }

    public function test_task_delivery_cancels_a_probe_after_access_is_revoked(): void
    {
        [$owner, $domain, $record, $edge] = $this->fixture();
        $operation = $this->operation($owner, $domain, $record);
        (new DispatchOriginTest($operation->id))->handle();
        $task = EdgeTask::query()->where('payload->operation_id', $operation->id)->sole();
        $domain->users()->detach($owner);
        $request = Request::create('/edge/v1/tasks', 'GET');
        $request->attributes->set('edge', $edge);
        $response = app(EdgeAgentController::class)->tasks($request)->getData(true);
        $this->assertSame([], $response['data']);
        $this->assertSame('failed', $task->refresh()->status);
        $this->assertSame('task_cancelled', $task->last_error);
        $this->assertNull($record->refresh()->origin_health);
    }

    public function test_filament_actions_use_the_same_binding_and_active_domain_gate(): void
    {
        [$owner, $domain, $record] = $this->fixture();
        $origin = $record->origin;
        $origin['backup'] = $this->origin('1.1.1.1');
        $record->update(['origin' => $origin]);
        Filament::setCurrentPanel(Filament::getPanel('domain'));
        $this->actingAs($owner);
        $component = fn () => Livewire::test(DnsRecordsRelationManager::class, ['ownerRecord' => $domain->refresh(), 'pageClass' => ViewDomain::class]);
        $component()->callTableAction('testOrigin', $record)->assertHasNoActionErrors();
        $component()->callTableAction('testBackupOrigin', $record)->assertHasNoActionErrors();
        $operations = Operation::query()->where('type', 'edge.origin_test')->get();
        $this->assertCount(2, $operations);
        foreach ($operations as $operation) {
            $target = $operation->input['origin_role'] === 'backup' ? $origin['backup'] : $origin;
            $this->assertSame(hash('sha256', ArtifactSigner::encode($target)), $operation->input['origin_checksum']);
        }
        $domain->update(['lifecycle_state' => 'pending_verification']);
        $component()->assertTableActionDisabled('testOrigin', $record)->assertTableActionDisabled('testBackupOrigin', $record);
    }

    public function test_previously_mounted_filament_action_denies_a_revoked_user(): void
    {
        [$owner, $domain, $record] = $this->fixture();
        Filament::setCurrentPanel(Filament::getPanel('domain'));
        $this->actingAs($owner);
        $component = Livewire::test(DnsRecordsRelationManager::class, ['ownerRecord' => $domain, 'pageClass' => ViewDomain::class]);
        $domain->users()->detach($owner);
        $component->mountTableAction('testOrigin', $record)->assertForbidden();
        $this->assertSame(0, Operation::query()->where('type', 'edge.origin_test')->count());
        Queue::assertNotPushed(DispatchOriginTest::class);
    }

    private function fixture(): array
    {
        Queue::fake();
        $owner = User::factory()->create();
        $domain = Domain::query()->create(['name' => 'probe-'.Str::lower(Str::random(12)).'.example', 'display_name' => 'Probe',
            'lifecycle_state' => 'active', 'nameservers_verified_at' => now(), 'revision' => 1]);
        $domain->users()->attach($owner);
        $record = $domain->dnsRecords()->create(['type' => 'A', 'mode' => 'proxied', 'name' => $domain->name, 'content' => '8.8.8.8',
            'content_hash' => hash('sha256', '8.8.8.8'), 'ttl' => 60, 'origin' => $this->origin('8.8.8.8')]);
        $edge = Edge::query()->create(['name' => 'probe-edge', 'country_code' => 'IR', 'continent_code' => 'AS', 'enabled' => true,
            'registered_at' => now(), 'last_heartbeat_at' => now()]);

        return [$owner, $domain, $record, $edge];
    }

    private function operation(User $owner, Domain $domain, DnsRecord $record): Operation
    {
        return Operation::query()->create(['type' => 'edge.origin_test', 'status' => 'pending', 'actor_id' => $owner->id, 'input' => [
            'domain_id' => $domain->id, 'record_id' => $record->id, 'origin_role' => 'primary', 'edge_ids' => [], 'addresses' => ['8.8.8.8'],
            'origin_checksum' => hash('sha256', ArtifactSigner::encode($record->origin)),
        ]]);
    }

    private function origin(string $host): array
    {
        return OriginData::validate(['host' => $host, 'scheme' => 'http', 'port' => 80, 'host_header' => 'origin.example',
            'verify_tls' => false, 'connect_timeout_ms' => 1000, 'response_timeout_ms' => 1000, 'retry_count' => 0]);
    }

    private function report(Edge $edge, EdgeTask $task): void
    {
        $request = Request::create('/edge/v1/tasks/'.$task->id.'/result', 'POST', ['status' => 'succeeded', 'result' => ['status' => 'healthy']]);
        $request->attributes->set('edge', $edge);
        $this->assertSame(200, app(EdgeAgentController::class)->taskResult($request, $task->id)->getStatusCode());
    }
}
