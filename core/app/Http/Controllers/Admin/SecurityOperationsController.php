<?php

namespace App\Http\Controllers\Admin;

use App\Actions\DispatchEmergencyMode;
use App\Http\Controllers\Controller;
use App\Jobs\ReconcileEdgeDomain;
use App\Jobs\ReconcilePlatformDnsIdentity;
use App\Models\AuditLog;
use App\Models\Domain;
use App\Models\DomainEdgePlacement;
use App\Models\Edge;
use App\Models\EdgeCell;
use App\Models\EdgePool;
use App\Models\Operation;
use App\Models\SecurityEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class SecurityOperationsController extends Controller
{
    public function restrict(Request $request, Domain $domain): JsonResponse
    {
        return $this->state($request, $domain, 'restricted');
    }

    public function quarantine(Request $request, Domain $domain): JsonResponse
    {
        abort_unless($domain->dnsRecords()->where('mode', 'proxied')->exists(), 409, 'Only a proxied domain can be quarantined.');
        $pool = EdgePool::query()->where('enabled', true)->where('withdrawn', false)->where('kind', 'quarantine')->orderBy('id')->firstOrFail();

        return $this->state($request, $domain, 'quarantined', $pool);
    }

    public function release(Request $request, Domain $domain): JsonResponse
    {
        $pool = EdgePool::query()->where('enabled', true)->where('withdrawn', false)->where('kind', 'shared')->orderBy('id')->firstOrFail();

        return $this->state($request, $domain, 'recovering', $pool);
    }

    public function edgeEmergency(Request $request, Edge $edge): JsonResponse
    {
        return $this->emergency($request, 'edge', $edge->id, $edge);
    }

    public function clearEdgeEmergency(Request $request, Edge $edge): JsonResponse
    {
        return $this->clearEmergency($request, 'edge', $edge->id, $edge);
    }

    public function cellEmergency(Request $request, EdgeCell $cell): JsonResponse
    {
        return $this->emergency($request, 'cell', (string) $cell->id, $cell);
    }

    public function clearCellEmergency(Request $request, EdgeCell $cell): JsonResponse
    {
        return $this->clearEmergency($request, 'cell', (string) $cell->id, $cell);
    }

    public function withdraw(Request $request, EdgePool $pool): JsonResponse
    {
        abort_if($pool->withdrawn, 409, 'The service pool is already withdrawn.');
        DB::transaction(function () use ($pool, $request): void {
            $pool->update(['withdrawn' => true, 'revision' => $pool->revision + 1]);
            AuditLog::record($request->user(), 'edge.pool_withdrawn', $pool, ['revision' => $pool->revision], $request->ip());
        });
        ReconcilePlatformDnsIdentity::dispatchForRoutingChange();

        return response()->json(['data' => ['pool_id' => $pool->id, 'withdrawn' => true]], 202);
    }

    public function restore(Request $request, EdgePool $pool): JsonResponse
    {
        abort_unless($pool->withdrawn, 409, 'The service pool is not withdrawn.');
        DB::transaction(function () use ($pool, $request): void {
            $pool->update(['withdrawn' => false, 'revision' => $pool->revision + 1]);
            AuditLog::record($request->user(), 'edge.pool_restored', $pool, ['revision' => $pool->revision], $request->ip());
        });
        ReconcilePlatformDnsIdentity::dispatchForRoutingChange();

        return response()->json(['data' => ['pool_id' => $pool->id, 'withdrawn' => false]], 202);
    }

    private function state(Request $request, Domain $domain, string $state, ?EdgePool $pool = null): JsonResponse
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:250']]);
        $operation = DB::transaction(function () use ($data, $domain, $pool, $request, $state): Operation {
            $locked = Domain::query()->lockForUpdate()->findOrFail($domain->id);
            $locked->update(['security_state' => $state, 'security_state_changed_at' => now(), 'revision' => $locked->revision + 1]);
            if ($pool !== null) {
                DomainEdgePlacement::query()->updateOrCreate(['domain_id' => $locked->id], [
                    'target_pool_id' => $pool->id, 'desired_revision' => $locked->revision,
                    'state' => 'deploying', 'drain_after' => null, 'last_error' => null,
                ]);
            }
            SecurityEvent::query()->create([
                'domain_id' => $locked->id, 'state' => $state,
                'reason_code' => $state === 'quarantined' ? 'domain_quarantined' : 'domain_restricted',
                'details' => ['reason' => $data['reason'] ?? null, 'actor_id' => $request->user()->id, 'target_pool_id' => $pool?->id],
                'occurred_at' => now(),
            ]);
            AuditLog::record($request->user(), "security.domain_$state", $locked, ['revision' => $locked->revision, 'target_pool_id' => $pool?->id], $request->ip());

            return Operation::coalesceDomain('edge.domain_reconcile', $locked->id, $request->user()->id);
        });
        ReconcileEdgeDomain::dispatch($domain->id)->afterCommit();

        return response()->json(['data' => ['operation_id' => $operation->id, 'state' => $state, 'target_pool_id' => $pool?->id]], 202);
    }

    private function emergency(Request $request, string $type, string $id, object $subject): JsonResponse
    {
        $data = $request->validate([
            'actions' => ['required', 'array', 'min:1', 'max:11'],
            'actions.*' => ['required', 'string', 'distinct', Rule::in(config('security.emergency_actions'))],
            'duration_minutes' => ['nullable', 'integer', 'between:1,'.config('security.emergency_duration_minutes_maximum')],
        ]);
        [$mode, $operation] = DispatchEmergencyMode::activate($type, $id, $data['actions'], $data['duration_minutes'] ?? null, $request->user());
        AuditLog::record($request->user(), 'security.emergency_activated', $subject, ['mode_id' => $mode->id, 'actions' => $mode->actions, 'expires_at' => $mode->expires_at?->toIso8601String()], $request->ip());

        return response()->json(['data' => ['operation_id' => $operation->id, 'emergency_mode_id' => $mode->id, 'expires_at' => $mode->expires_at]], 202);
    }

    private function clearEmergency(Request $request, string $type, string $id, object $subject): JsonResponse
    {
        $operation = DispatchEmergencyMode::deactivateTarget($type, $id, $request->user());
        AuditLog::record($request->user(), 'security.emergency_deactivated', $subject, ['operation_id' => $operation->id], $request->ip());

        return response()->json(['data' => ['operation_id' => $operation->id]], 202);
    }
}
