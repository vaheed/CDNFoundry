<?php

namespace App\Http\Controllers\Admin;

use App\Enums\DomainLifecycleState;
use App\Http\Controllers\Controller;
use App\Http\Resources\DnsClusterResource;
use App\Jobs\ReconcileDnsZone;
use App\Models\AuditLog;
use App\Models\DnsCluster;
use App\Models\Domain;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class DnsClusterController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return DnsClusterResource::collection(DnsCluster::query()->orderBy('id')->cursorPaginate(50));
    }

    public function store(Request $request): JsonResponse
    {
        $cluster = DnsCluster::query()->create($this->validated($request));
        AuditLog::record($request->user(), 'dns.cluster_created', $cluster, ['name' => $cluster->name], $request->ip());
        Domain::query()->where('lifecycle_state', DomainLifecycleState::Active->value)->orderBy('id')->chunkById(100, fn ($domains) => $domains->each(fn (Domain $domain) => ReconcileDnsZone::dispatch($domain->id)->afterCommit()));

        return DnsClusterResource::make($cluster)->response()->setStatusCode(201);
    }

    public function show(DnsCluster $cluster): DnsClusterResource
    {
        return DnsClusterResource::make($cluster);
    }

    public function update(Request $request, DnsCluster $cluster): DnsClusterResource
    {
        $cluster->update($this->validated($request, $cluster));
        AuditLog::record($request->user(), 'dns.cluster_updated', $cluster, ['fields' => array_keys($request->all())], $request->ip());

        return DnsClusterResource::make($cluster->refresh());
    }

    public function disable(Request $request, DnsCluster $cluster): DnsClusterResource
    {
        if ($cluster->enabled) {
            $cluster->update(['enabled' => false]);
            AuditLog::record($request->user(), 'dns.cluster_disabled', $cluster, [], $request->ip());
        }

        return DnsClusterResource::make($cluster->refresh());
    }

    public function enable(Request $request, DnsCluster $cluster): DnsClusterResource
    {
        if (! $cluster->enabled) {
            $cluster->update(['enabled' => true]);
            AuditLog::record($request->user(), 'dns.cluster_enabled', $cluster, [], $request->ip());
            Domain::query()->where('lifecycle_state', DomainLifecycleState::Active->value)->orderBy('id')->chunkById(100, fn ($domains) => $domains->each(fn (Domain $domain) => ReconcileDnsZone::dispatch($domain->id)->afterCommit()));
        }

        return DnsClusterResource::make($cluster->refresh());
    }

    private function validated(Request $request, ?DnsCluster $cluster = null): array
    {
        return $request->validate([
            'name' => [$cluster ? 'sometimes' : 'required', 'string', 'max:100', Rule::unique('dns_clusters')->ignore($cluster)],
            'location' => [$cluster ? 'sometimes' : 'required', 'string', 'max:100'],
            'enabled' => ['sometimes', 'boolean'],
            'api_url' => [$cluster ? 'sometimes' : 'required', 'url:http,https', 'max:500'],
            'api_key' => [$cluster ? 'sometimes' : 'required', 'string', 'min:8', 'max:500'],
            'server_id' => ['sometimes', 'string', 'max:100'],
            'nameservers' => [$cluster ? 'sometimes' : 'required', 'array', 'min:2', 'max:8'],
            'nameservers.*.hostname' => ['required', 'string', 'max:253'],
            'capacity_zones' => ['sometimes', 'integer', 'between:1,10000000'],
            'operational_notes' => ['nullable', 'string', 'max:4000'],
        ]);
    }
}
