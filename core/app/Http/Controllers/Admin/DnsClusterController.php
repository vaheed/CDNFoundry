<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\DnsClusterResource;
use App\Jobs\ReconcileAllDnsZones;
use App\Jobs\ReconcilePlatformDnsIdentity;
use App\Jobs\TestDnsCluster;
use App\Models\AuditLog;
use App\Models\DnsCluster;
use App\Models\Operation;
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
        $cluster = DnsCluster::query()->create([...$this->validated($request), 'enabled' => false]);
        AuditLog::record($request->user(), 'dns.cluster_created', $cluster, ['name' => $cluster->name], $request->ip());

        $operation = Operation::query()->create([
            'actor_id' => $request->user()->getKey(), 'type' => 'dns.cluster_test', 'status' => 'pending',
            'input' => ['cluster_id' => $cluster->id],
        ]);
        AuditLog::record($request->user(), 'dns.cluster_test_requested', $cluster, [], $request->ip());
        TestDnsCluster::dispatch($operation->getKey())->afterCommit();

        return response()->json(['data' => DnsClusterResource::make($cluster), 'operation_id' => $operation->getKey()], 202);
    }

    public function show(DnsCluster $cluster): DnsClusterResource
    {
        return DnsClusterResource::make($cluster);
    }

    public function test(Request $request, DnsCluster $cluster): JsonResponse
    {
        $operation = Operation::query()->where('type', 'dns.cluster_test')->whereIn('status', ['pending', 'running'])
            ->where('input->cluster_id', $cluster->id)->first();
        if ($operation === null) {
            $operation = Operation::query()->create([
                'actor_id' => $request->user()->getKey(), 'type' => 'dns.cluster_test', 'status' => 'pending',
                'input' => ['cluster_id' => $cluster->id],
            ]);
            AuditLog::record($request->user(), 'dns.cluster_test_requested', $cluster, [], $request->ip());
            TestDnsCluster::dispatch($operation->getKey())->afterCommit();
        }

        return response()->json(['data' => $operation], 202);
    }

    public function update(Request $request, DnsCluster $cluster): DnsClusterResource
    {
        $data = $this->validated($request, $cluster);
        $cluster->update($data);
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

    public function enable(Request $request, DnsCluster $cluster): JsonResponse
    {
        abort_unless($cluster->last_health_status === 'healthy', 409, 'Test the cluster connection successfully before enabling it.');
        abort_if(DnsCluster::query()->where('enabled', true)->whereKeyNot($cluster->id)->exists(), 409, 'Another DNS primary is enabled. Zones are written to exactly one primary; disable it first.');
        if (! $cluster->enabled) {
            $cluster->update(['enabled' => true]);
            AuditLog::record($request->user(), 'dns.cluster_enabled', $cluster, [], $request->ip());
            ReconcilePlatformDnsIdentity::dispatch()->afterCommit();
        }
        $operation = Operation::query()->where('type', 'dns.global_reconcile')->whereIn('status', ['pending', 'running'])->first()
            ?? Operation::query()->create(['actor_id' => $request->user()->id, 'type' => 'dns.global_reconcile', 'status' => 'pending', 'input' => ['cluster_id' => $cluster->id]]);
        ReconcileAllDnsZones::dispatch($operation->id)->afterCommit();

        return response()->json(['data' => DnsClusterResource::make($cluster->refresh()), 'operation_id' => $operation->id], 202);
    }

    private function validated(Request $request, ?DnsCluster $cluster = null): array
    {
        return $request->validate([
            'name' => [$cluster ? 'sometimes' : 'required', 'string', 'max:100', Rule::unique('dns_clusters')->ignore($cluster)],
            'location' => [$cluster ? 'sometimes' : 'required', 'string', 'max:100'],
            'api_url' => [$cluster ? 'sometimes' : 'required', 'url:http,https', 'max:500'],
            'api_key' => [$cluster ? 'sometimes' : 'required', 'string', 'min:8', 'max:500'],
            // PowerDNS accepts a primary only as an address and port, never a hostname.
            'transfer_endpoint' => [$cluster ? 'sometimes' : 'required', 'string', 'max:100', function (string $attribute, mixed $value, \Closure $fail): void {
                $valid = preg_match('/^(?:(\d{1,3}(?:\.\d{1,3}){3})|\[([0-9a-fA-F:]+)\]):(\d{1,5})$/', (string) $value, $m) === 1
                    && (int) $m[3] >= 1 && (int) $m[3] <= 65535
                    && filter_var($m[1] !== '' ? $m[1] : $m[2], FILTER_VALIDATE_IP) !== false;
                if (! $valid) {
                    $fail('The transfer endpoint must be an IP address and port, such as 192.0.2.10:5300 or [2001:db8::10]:5300.');
                }
            }],
            'server_id' => ['sometimes', 'string', 'max:100'],
            'nameservers' => [$cluster ? 'sometimes' : 'required', 'array', 'min:2', 'max:8'],
            'nameservers.*.hostname' => ['required', 'string', 'max:253'],
            'capacity_zones' => ['sometimes', 'integer', 'between:1,10000000'],
            'operational_notes' => ['nullable', 'string', 'max:4000'],
        ]);
    }
}
