<?php

namespace App\Http\Controllers;

use App\Enums\DomainLifecycleState;
use App\Http\Resources\DnsRecordResource;
use App\Jobs\ReconcileDnsZone;
use App\Models\AuditLog;
use App\Models\DnsCluster;
use App\Models\DnsRecord;
use App\Models\Domain;
use App\Support\DnsRecordData;
use App\Support\DnsZoneValidator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class DnsRecordController extends Controller
{
    private const BULK_LIMIT = 5000;

    private const RECORDS_PER_DOMAIN_LIMIT = 10000;

    public function index(Domain $domain): AnonymousResourceCollection
    {
        Gate::authorize('view', $domain);

        return DnsRecordResource::collection($domain->dnsRecords()->orderBy('id')->cursorPaginate(100));
    }

    public function store(Request $request, Domain $domain): JsonResponse
    {
        Gate::authorize('update', $domain);
        $record = DB::transaction(function () use ($request, $domain): DnsRecord {
            $locked = Domain::query()->lockForUpdate()->findOrFail($domain->id);
            $data = DnsRecordData::validate($request->all(), $locked->name);
            $this->assertCanManageDelegation($request, $data['type']);
            if ($locked->dnsRecords()->count() >= self::RECORDS_PER_DOMAIN_LIMIT) {
                throw ValidationException::withMessages(['record' => 'The domain has reached the 10,000 record limit.']);
            }
            $this->assertValidFinalZone($locked, collect([$data]));
            $record = $locked->dnsRecords()->create($data);
            $this->incrementRevision($locked);
            AuditLog::record($request->user(), 'dns.record_created', $record, ['domain_id' => $locked->id, 'revision' => $locked->revision], $request->ip());

            return $record;
        });
        $this->queueReconciliation($domain);

        return DnsRecordResource::make($record)->response()->setStatusCode(201);
    }

    public function show(Domain $domain, DnsRecord $record): DnsRecordResource
    {
        Gate::authorize('view', $domain);
        $this->assertRecordInDomain($domain, $record);

        return DnsRecordResource::make($record);
    }

    public function update(Request $request, Domain $domain, DnsRecord $record): DnsRecordResource
    {
        Gate::authorize('update', $domain);
        $this->assertRecordInDomain($domain, $record);
        $updated = DB::transaction(function () use ($request, $domain, $record): DnsRecord {
            $locked = Domain::query()->lockForUpdate()->findOrFail($domain->id);
            $current = DnsRecord::query()->where('domain_id', $locked->id)->lockForUpdate()->findOrFail($record->id);
            $existing = $current->only(['type', 'name', 'content', 'ttl', 'priority', 'weight', 'port', 'mode']);
            $existing['geo'] = $current->geo_config;
            $data = DnsRecordData::validate(array_merge($existing, $request->all()), $locked->name);
            $this->assertCanManageDelegation($request, $current->type);
            $this->assertCanManageDelegation($request, $data['type']);
            $this->assertValidFinalZone($locked, collect([$data]), collect([$current->id]));
            if ($current->only(array_keys($data)) !== $data) {
                $current->update($data);
                $this->incrementRevision($locked);
                AuditLog::record($request->user(), 'dns.record_updated', $current, ['domain_id' => $locked->id, 'revision' => $locked->revision], $request->ip());
            }

            return $current->refresh();
        });
        $this->queueReconciliation($domain);

        return DnsRecordResource::make($updated);
    }

    public function destroy(Request $request, Domain $domain, DnsRecord $record): JsonResponse
    {
        Gate::authorize('update', $domain);
        $this->assertRecordInDomain($domain, $record);
        $this->assertCanManageDelegation($request, $record->type);
        DB::transaction(function () use ($request, $domain, $record): void {
            $locked = Domain::query()->lockForUpdate()->findOrFail($domain->id);
            $current = DnsRecord::query()->where('domain_id', $locked->id)->lockForUpdate()->find($record->id);
            if ($current !== null) {
                AuditLog::record($request->user(), 'dns.record_deleted', $current, ['domain_id' => $locked->id, 'revision' => $locked->revision + 1], $request->ip());
                $current->delete();
                $this->incrementRevision($locked);
            }
        });
        $this->queueReconciliation($domain);

        return response()->json(null, 204);
    }

    public function bulk(Request $request, Domain $domain): JsonResponse
    {
        Gate::authorize('update', $domain);
        $validated = $request->validate([
            'actions' => ['required', 'array', 'min:1', 'max:'.self::BULK_LIMIT],
            'actions.*.action' => ['required', 'in:create,update,delete'],
            'actions.*.id' => ['required_unless:actions.*.action,create', 'integer'],
            'actions.*.record' => ['required_unless:actions.*.action,delete', 'array'],
        ]);

        $result = DB::transaction(function () use ($request, $domain, $validated): array {
            $locked = Domain::query()->lockForUpdate()->findOrFail($domain->id);
            $existing = $locked->dnsRecords()->lockForUpdate()->get()->keyBy('id');
            $final = $existing->map(function (DnsRecord $record): array {
                $row = $record->only(['type', 'name', 'content', 'content_hash', 'ttl', 'priority', 'weight', 'port', 'mode', 'geo_config']);
                $row['geo'] = $record->geo_config;

                return $row;
            });
            $creates = [];
            $updates = [];
            $deletes = [];

            foreach ($validated['actions'] as $offset => $action) {
                if ($action['action'] === 'create') {
                    $data = DnsRecordData::validate($action['record'], $locked->name);
                    $this->assertCanManageDelegation($request, $data['type']);
                    $key = 'new-'.$offset;
                    $final->put($key, $data);
                    $creates[$key] = $data;

                    continue;
                }
                $record = $existing->get((int) $action['id']);
                if ($record === null) {
                    throw ValidationException::withMessages(["actions.$offset.id" => 'The record does not belong to this domain.']);
                }
                $this->assertCanManageDelegation($request, $record->type);
                if ($action['action'] === 'delete') {
                    $final->forget($record->id);
                    $deletes[] = $record;

                    continue;
                }
                $data = DnsRecordData::validate(array_merge($final->get($record->id), $action['record']), $locked->name);
                $this->assertCanManageDelegation($request, $data['type']);
                $final->put($record->id, $data);
                $updates[] = [$record, $data];
            }

            if ($final->count() > self::RECORDS_PER_DOMAIN_LIMIT) {
                throw ValidationException::withMessages(['actions' => 'The resulting zone exceeds the 10,000 record limit.']);
            }

            DnsZoneValidator::assertValid($final->values());
            foreach ($deletes as $record) {
                $record->delete();
            }
            foreach ($updates as [$record, $data]) {
                $record->update($data);
            }
            foreach ($creates as $data) {
                $locked->dnsRecords()->create($data);
            }
            $this->incrementRevision($locked);
            AuditLog::record($request->user(), 'dns.records_bulk_changed', $locked, [
                'revision' => $locked->revision, 'actions' => count($validated['actions']),
            ], $request->ip());

            return ['revision' => $locked->revision, 'changed' => count($validated['actions'])];
        });
        $this->queueReconciliation($domain);

        return response()->json(['data' => $result]);
    }

    private function assertValidFinalZone(Domain $domain, Collection $additions, ?Collection $excludedIds = null): void
    {
        $rows = $domain->dnsRecords()->when($excludedIds?->isNotEmpty(), fn ($query) => $query->whereNotIn('id', $excludedIds))->get()
            ->map(fn (DnsRecord $record): array => $record->only(['type', 'name', 'content', 'content_hash', 'ttl', 'priority', 'weight', 'port', 'mode', 'geo_config']))
            ->concat($additions);
        DnsZoneValidator::assertValid($rows);
    }

    private function incrementRevision(Domain $domain): void
    {
        $domain->forceFill(['revision' => $domain->revision + 1])->save();
    }

    private function assertRecordInDomain(Domain $domain, DnsRecord $record): void
    {
        abort_unless($record->domain_id === $domain->id, 404);
    }

    private function queueReconciliation(Domain $domain): void
    {
        if ($domain->refresh()->lifecycle_state === DomainLifecycleState::Active && DnsCluster::query()->where('enabled', true)->exists()) {
            ReconcileDnsZone::dispatch($domain->id)->afterCommit();
        }
    }

    private function assertCanManageDelegation(Request $request, string $type): void
    {
        abort_if($type === 'NS' && ! $request->user()->isAdmin(), 403, 'Only administrators can manage delegated NS records.');
    }
}
