<?php

namespace App\Http\Controllers;

use App\Http\Resources\DnsRecordResource;
use App\Jobs\ReconcileDnsZone;
use App\Models\AuditLog;
use App\Models\DnsCluster;
use App\Models\DnsRecord;
use App\Models\Domain;
use App\Support\GeoDnsConfig;
use App\Support\GeoIpClassifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class GeoDnsController extends Controller
{
    public function show(Domain $domain, DnsRecord $record): JsonResponse
    {
        $this->authorizeRecord($domain, $record, 'view');
        abort_unless($record->mode === 'geo_dns', 404);

        return response()->json(['data' => $record->geo_config]);
    }

    public function update(Request $request, Domain $domain, DnsRecord $record): DnsRecordResource
    {
        $this->authorizeRecord($domain, $record, 'update');
        abort_unless($record->mode === 'geo_dns', 404);
        $config = GeoDnsConfig::validate($request->all(), $record->type, $domain->name);
        DB::transaction(function () use ($request, $domain, $record, $config): void {
            $locked = Domain::query()->lockForUpdate()->findOrFail($domain->id);
            $current = DnsRecord::query()->where('domain_id', $locked->id)->lockForUpdate()->findOrFail($record->id);
            if ($current->geo_config !== $config) {
                $current->update(['geo_config' => $config, 'content' => $config['default'][0], 'content_hash' => hash('sha256', json_encode($config, JSON_THROW_ON_ERROR))]);
                $locked->increment('revision');
                AuditLog::record($request->user(), 'dns.geo_updated', $current, ['domain_id' => $locked->id, 'revision' => $locked->revision], $request->ip());
            }
        });
        $this->reconcile($domain);

        return DnsRecordResource::make($record->refresh());
    }

    public function preview(Request $request, Domain $domain, DnsRecord $record, GeoIpClassifier $classifier): JsonResponse
    {
        $this->authorizeRecord($domain, $record, 'view');
        abort_unless($record->mode === 'geo_dns', 404);
        $validated = $request->validate(['ip' => ['required', 'ip']]);
        $geo = $classifier->classify($validated['ip']);

        return response()->json(['data' => [
            'ip' => $validated['ip'], 'country' => $geo['country'], 'continent' => $geo['continent'],
            'source' => $geo['source'], 'targets' => GeoDnsConfig::select($record->geo_config, $geo['country'], $geo['continent']),
            'location_basis' => 'Preview uses the supplied IP. Runtime uses trusted ECS when present, otherwise the recursive resolver address.',
        ]]);
    }

    private function authorizeRecord(Domain $domain, DnsRecord $record, string $ability): void
    {
        Gate::authorize($ability, $domain);
        abort_unless($record->domain_id === $domain->id, 404);
    }

    private function reconcile(Domain $domain): void
    {
        if ($domain->refresh()->lifecycle_state->value === 'active' && DnsCluster::query()->where('enabled', true)->exists()) {
            ReconcileDnsZone::dispatch($domain->id)->afterCommit();
        }
    }
}
