<?php

namespace App\Http\Controllers;

use App\Enums\DomainLifecycleState;
use App\Http\Requests\StoreDomainRequest;
use App\Http\Resources\DomainResource;
use App\Jobs\ReconcileEdgeDomain;
use App\Models\AuditLog;
use App\Models\DnsCluster;
use App\Models\DnsDeployment;
use App\Models\Domain;
use App\Models\EdgeArtifact;
use App\Models\Operation;
use App\Support\PlatformSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class DomainController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Domain::query()->orderBy('id');
        if (! $request->user()->isAdmin()) {
            $query->whereHas('users', fn ($users) => $users->whereKey($request->user()->getKey()));
        }

        return DomainResource::collection($query->cursorPaginate(50));
    }

    public function store(StoreDomainRequest $request): JsonResponse
    {
        $domain = DB::transaction(function () use ($request): Domain {
            $domain = Domain::query()->create([
                'name' => $request->validated('name'),
                'display_name' => trim((string) $request->input('name')),
                'lifecycle_state' => DomainLifecycleState::PendingVerification,
                'revision' => 1,
            ]);
            if (! $request->user()->isAdmin()) {
                $domain->users()->attach($request->user()->getKey());
            }
            AuditLog::record($request->user(), 'domain.created', $domain, ['name' => $domain->name], $request->ip());

            return $domain;
        });

        return DomainResource::make($domain)->response()->setStatusCode(201);
    }

    public function show(Request $request, Domain $domain): DomainResource
    {
        Gate::authorize('view', $domain);

        return DomainResource::make($domain);
    }

    public function update(Request $request, Domain $domain): DomainResource
    {
        Gate::authorize('update', $domain);
        $validated = $request->validate([
            'display_name' => ['required', 'string', 'max:253'],
        ]);
        $displayName = trim($validated['display_name']);
        abort_if($displayName === '', 422, 'The display name cannot be empty.');
        if ($domain->display_name !== $displayName) {
            $domain->update(['display_name' => $displayName]);
            AuditLog::record($request->user(), 'domain.updated', $domain, ['fields' => ['display_name']], $request->ip());
        }

        return DomainResource::make($domain->refresh());
    }

    public function disable(Request $request, Domain $domain): DomainResource
    {
        Gate::authorize('update', $domain);
        if ($domain->lifecycle_state !== DomainLifecycleState::Deprovisioning && $domain->lifecycle_state !== DomainLifecycleState::Disabled) {
            $domain->forceFill(['lifecycle_state' => DomainLifecycleState::Disabled, 'disabled_at' => now(), 'revision' => $domain->revision + 1])->save();
            AuditLog::record($request->user(), 'domain.disabled', $domain, ['revision' => $domain->revision], $request->ip());
            $this->queueEdgeState($domain, $request);
        }

        return DomainResource::make($domain->refresh());
    }

    public function destroy(Request $request, Domain $domain): JsonResponse
    {
        Gate::authorize('delete', $domain);
        $operation = DB::transaction(function () use ($request, $domain): Operation {
            $locked = Domain::query()->lockForUpdate()->findOrFail($domain->id);
            if ($locked->lifecycle_state !== DomainLifecycleState::Deprovisioning) {
                $locked->forceFill([
                    'lifecycle_state' => DomainLifecycleState::Deprovisioning,
                    'deprovision_after' => now()->addDays(app(PlatformSettings::class)->integer('dns_lifecycle', 'deprovision_delay_days')),
                    'revision' => $locked->revision + 1,
                ])->save();
                foreach (DnsCluster::query()->orderBy('id')->get() as $cluster) {
                    DnsDeployment::query()->updateOrCreate(
                        ['domain_id' => $locked->id, 'dns_cluster_id' => $cluster->id],
                        ['desired_revision' => $locked->revision, 'status' => 'pending', 'tombstone' => true],
                    );
                }
                AuditLog::record($request->user(), 'domain.deprovisioning_started', $locked, ['revision' => $locked->revision], $request->ip());
            }

            return Operation::query()->where('type', 'domain.deprovision')->whereIn('status', ['pending', 'running'])
                ->where('input->domain_id', $locked->id)->first() ?? Operation::query()->create([
                    'actor_id' => $request->user()->id, 'type' => 'domain.deprovision', 'status' => 'pending',
                    'input' => ['domain_id' => $locked->id, 'revision' => $locked->revision],
                ]);
        });
        $this->queueEdgeState($domain->refresh(), $request);

        return response()->json(['data' => ['operation_id' => $operation->id, 'status' => $operation->status, 'domain' => DomainResource::make($domain->refresh())]], 202);
    }

    private function queueEdgeState(Domain $domain, Request $request): void
    {
        if (! $domain->dnsRecords()->where('mode', 'proxied')->exists() && ! EdgeArtifact::query()->where('domain_id', $domain->id)->exists()) {
            return;
        }
        Operation::coalesceDomain('edge.domain_reconcile', $domain->id, $request->user()->id);
        ReconcileEdgeDomain::dispatch($domain->id)->afterCommit();
    }
}
