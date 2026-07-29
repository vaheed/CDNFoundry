<?php

namespace App\Filament\Admin\Resources\EdgePools\Pages;

use App\Filament\Admin\Resources\EdgePools\EdgePoolResource;
use App\Jobs\ReconcileAllEdgeDomains;
use App\Jobs\ReconcilePlatformDnsIdentity;
use App\Models\AuditLog;
use App\Models\Operation;
use App\Support\EdgePoolRoutingData;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class EditEdgePool extends EditRecord
{
    protected static string $resource = EdgePoolResource::class;

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data['waf_runtime_version'] = ($data['waf_capable'] ?? false) ? config('security.waf.ruleset') : null;
        $data['waf_canary_state'] = ($data['waf_capable'] ?? false) ? ($data['waf_canary_state'] ?? 'monitoring') : 'not_required';
        $routing = EdgePoolRoutingData::validate($data, $this->record, true);
        if (($routing['routing_mode'] ?? $this->record->routing_mode) !== $this->record->routing_mode && ($this->record->enabled || $this->record->endpoints()->exists())) {
            throw ValidationException::withMessages(['routing_mode' => 'Disable the pool and remove its endpoints before changing routing mode.']);
        }
        $data = [...$data, ...$routing];
        if (($data['replicas_per_edge'] ?? $this->record->replicas_per_edge) > 1 && ! in_array($this->record->kind, ['reserved', 'dedicated'], true)) {
            throw ValidationException::withMessages(['replicas_per_edge' => 'Replicated placement is limited to reserved and dedicated pools.']);
        }
        if (($data['name'] ?? $this->record->name) !== $this->record->name && $this->record->cells()->exists()) {
            throw ValidationException::withMessages(['name' => 'Pool runtime names are immutable after cells have been provisioned.']);
        }
        $data['revision'] = $this->record->revision + 1;

        return $data;
    }

    protected function afterSave(): void
    {
        if ($this->record->wasChanged(['routing_mode', 'anycast_ipv4', 'anycast_ipv6'])) {
            $this->record->endpoints()->update(['revision' => DB::raw('revision + 1'), 'gateway_state' => 'pending', 'readiness_reason' => 'gateway_not_acknowledged']);
        }
        AuditLog::record(auth()->user(), 'edge.pool_updated', $this->record, [], request()->ip());
        ReconcilePlatformDnsIdentity::dispatchForRoutingChange();
        if ($this->record->wasChanged(['minimum_ready_cells', 'replicas_per_edge', 'maximum_domains_per_cell', 'cache_profile', 'compression_profile', 'waf_capable', 'waf_runtime_version', 'waf_canary_state'])) {
            $operation = Operation::query()->create([
                'actor_id' => auth()->id(), 'type' => 'edge.global_reconcile', 'status' => 'pending',
                'input' => ['pool_id' => $this->record->id, 'reason' => 'pool_policy_changed'],
            ]);
            ReconcileAllEdgeDomains::dispatch($operation->id)->afterCommit();
        }
    }
}
