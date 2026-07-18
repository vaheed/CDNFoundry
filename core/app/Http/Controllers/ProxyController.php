<?php

namespace App\Http\Controllers;

use App\Enums\DomainLifecycleState;
use App\Jobs\DispatchOriginTest;
use App\Jobs\ReconcileDnsZone;
use App\Jobs\ReconcileEdgeDomain;
use App\Models\AuditLog;
use App\Models\DnsCluster;
use App\Models\DnsRecord;
use App\Models\Domain;
use App\Models\EdgeRevision;
use App\Models\Operation;
use App\Support\OriginData;
use App\Support\ProxyRevisionRollback;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class ProxyController extends Controller
{
    public function show(Domain $domain): JsonResponse
    {
        Gate::authorize('view', $domain);

        return response()->json(['data' => is_array($domain->proxy_settings) ? $domain->proxy_settings : ReconcileEdgeDomain::defaults()]);
    }

    public function update(Request $request, Domain $domain): JsonResponse
    {
        Gate::authorize('update', $domain);
        $data = $request->validate([
            'enabled' => ['required', 'boolean'], 'redirect_https' => ['required', 'boolean'],
            'http_versions' => ['required', 'array', 'min:1', 'max:2'], 'http_versions.*' => ['in:1.1,2'],
            'retry_count' => ['required', 'integer', 'between:0,2'], 'maintenance' => ['nullable', 'array'],
            'maintenance.status' => ['required_with:maintenance', 'integer', 'in:503'],
            'maintenance.body' => ['required_with:maintenance', 'string', 'max:4096'],
        ]);
        DB::transaction(function () use ($domain, $data, $request): void {
            $locked = Domain::query()->lockForUpdate()->findOrFail($domain->id);
            $locked->update(['proxy_settings' => $data, 'revision' => $locked->revision + 1]);
            AuditLog::record($request->user(), 'proxy.defaults_updated', $locked, ['revision' => $locked->revision], $request->ip());
        });

        return $this->queue($request, $domain, 'edge.domain_reconcile');
    }

    public function origin(Domain $domain, DnsRecord $record): JsonResponse
    {
        $this->record($domain, $record);
        Gate::authorize('view', $domain);
        abort_unless($record->mode === 'proxied', 409, 'The DNS record is not proxied.');

        return response()->json(['data' => $record->origin]);
    }

    public function updateOrigin(Request $request, Domain $domain, DnsRecord $record): JsonResponse
    {
        $this->record($domain, $record);
        Gate::authorize('update', $domain);
        abort_unless($record->mode === 'proxied', 409, 'The DNS record is not proxied.');
        $origin = OriginData::validate($request->all());
        OriginData::resolveAndValidate($origin['host']);
        DB::transaction(function () use ($domain, $record, $origin, $request): void {
            $locked = Domain::query()->lockForUpdate()->findOrFail($domain->id);
            DnsRecord::query()->where('domain_id', $locked->id)->findOrFail($record->id)->update(['origin' => $origin, 'content' => $origin['host'], 'content_hash' => hash('sha256', json_encode($origin, JSON_THROW_ON_ERROR))]);
            $locked->update(['revision' => $locked->revision + 1]);
            AuditLog::record($request->user(), 'proxy.origin_updated', $record, ['revision' => $locked->revision], $request->ip());
        });

        return $this->queue($request, $domain, 'edge.domain_reconcile');
    }

    public function testOrigin(Request $request, Domain $domain, DnsRecord $record): JsonResponse
    {
        $this->record($domain, $record);
        Gate::authorize('update', $domain);
        abort_unless($record->mode === 'proxied', 409, 'The DNS record is not proxied.');
        $addresses = OriginData::resolveAndValidate($record->origin['host']);
        $selected = $request->validate(['edge_ids' => ['sometimes', 'array', 'max:20'], 'edge_ids.*' => ['uuid', 'distinct', 'exists:edges,id']]);
        $operation = Operation::query()->create(['id' => (string) Str::uuid(), 'type' => 'edge.origin_test', 'status' => 'pending', 'actor_id' => $request->user()->id, 'input' => [
            'domain_id' => $domain->id, 'record_id' => $record->id, 'addresses' => $addresses, 'edge_ids' => $selected['edge_ids'] ?? [],
        ]]);
        DispatchOriginTest::dispatch($operation->id)->afterCommit();

        return response()->json(['data' => ['operation_id' => $operation->id, 'status' => 'pending']], 202);
    }

    public function health(Domain $domain, DnsRecord $record): JsonResponse
    {
        $this->record($domain, $record);
        Gate::authorize('view', $domain);

        return response()->json(['data' => $record->origin_health]);
    }

    public function deployment(Domain $domain): JsonResponse
    {
        Gate::authorize('view', $domain);

        return response()->json(['data' => ['desired_revision' => $domain->revision, 'active_revision' => $domain->active_edge_revision, 'placement' => DB::table('domain_edge_placements')->where('domain_id', $domain->id)->first()]]);
    }

    public function deploy(Request $request, Domain $domain): JsonResponse
    {
        Gate::authorize('update', $domain);

        return $this->queue($request, $domain, 'edge.domain_reconcile');
    }

    public function revisions(Domain $domain)
    {
        Gate::authorize('view', $domain);

        return response()->json(['data' => EdgeRevision::query()->where('domain_id', $domain->id)->orderByDesc('revision')->cursorPaginate(50)]);
    }

    public function rollback(Request $request, Domain $domain): JsonResponse
    {
        Gate::authorize('update', $domain);
        $data = $request->validate(['revision' => ['required', 'integer', 'min:1']]);
        $prior = EdgeRevision::query()->where('domain_id', $domain->id)->where('revision', $data['revision'])->where('status', 'validated')->firstOrFail();
        ProxyRevisionRollback::apply($domain, $prior, $request->user(), $request->ip());
        if ($domain->refresh()->lifecycle_state === DomainLifecycleState::Active && DnsCluster::query()->where('enabled', true)->exists()) {
            Operation::coalesceDomain('dns.zone_reconcile', $domain->id, $request->user()->id);
            ReconcileDnsZone::dispatch($domain->id)->afterCommit();
        }

        return $this->queue($request, $domain, 'edge.domain_reconcile');
    }

    private function queue(Request $request, Domain $domain, string $type): JsonResponse
    {
        $operation = Operation::coalesceDomain($type, $domain->id, $request->user()->id);
        ReconcileEdgeDomain::dispatch($domain->id)->afterCommit();

        return response()->json(['data' => ['operation_id' => $operation->id, 'status' => $operation->status]], 202);
    }

    private function record(Domain $domain, DnsRecord $record): void
    {
        abort_unless($record->domain_id === $domain->id, 404);
    }
}
