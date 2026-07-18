<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\ReconcilePlatformDnsIdentity;
use App\Models\AuditLog;
use App\Models\Edge;
use App\Models\EdgePool;
use App\Support\GeoVocabulary;
use App\Support\NetworkAddress;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class EdgeController extends Controller
{
    public function index()
    {
        return response()->json(['data' => Edge::query()->orderBy('id')->cursorPaginate(100)]);
    }

    public function show(Edge $edge): JsonResponse
    {
        return response()->json(['data' => $edge->load('cells')]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:100', 'unique:edges'], 'country_code' => ['required', Rule::in(GeoVocabulary::countries())], 'continent_code' => ['required', Rule::in(GeoVocabulary::CONTINENTS)], 'ipv4' => ['required', 'ipv4', 'unique:edges'], 'ipv6' => ['nullable', 'ipv6', 'unique:edges']]);
        abort_if(NetworkAddress::isUnsafe($data['ipv4']) || (isset($data['ipv6']) && NetworkAddress::isUnsafe($data['ipv6'])), 422, 'Edge addresses must be public unicast service addresses.');
        $token = Str::random(64);
        $edge = DB::transaction(function () use ($data, $token, $request): Edge {
            $edge = Edge::query()->create(array_merge($data, ['country_code' => strtoupper($data['country_code']), 'continent_code' => strtoupper($data['continent_code']), 'bootstrap_token_hash' => hash('sha256', $token)]));
            $defaultSharedId = EdgePool::query()->where('enabled', true)->where('kind', 'shared')->orderBy('id')->value('id');
            foreach (EdgePool::query()->orderBy('id')->get() as $pool) {
                $edge->cells()->create([
                    'edge_pool_id' => $pool->id, 'name' => $pool->name,
                    'service_ipv4' => $pool->id === $defaultSharedId ? $edge->ipv4 : null,
                    'service_ipv6' => $pool->id === $defaultSharedId ? $edge->ipv6 : null,
                ]);
            }
            AuditLog::record($request->user(), 'edge.created', $edge, [], $request->ip());

            return $edge;
        });

        return response()->json(['data' => array_merge($edge->toArray(), ['bootstrap_token' => $token])], 201);
    }

    public function update(Request $request, Edge $edge): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:100', Rule::unique('edges')->ignore($edge)],
            'country_code' => ['sometimes', Rule::in(GeoVocabulary::countries())],
            'continent_code' => ['sometimes', Rule::in(GeoVocabulary::CONTINENTS)],
            'ipv4' => ['sometimes', 'ipv4', Rule::unique('edges')->ignore($edge)],
            'ipv6' => ['sometimes', 'nullable', 'ipv6', Rule::unique('edges')->ignore($edge)],
        ]);
        foreach (array_filter([$data['ipv4'] ?? null, $data['ipv6'] ?? null]) as $address) {
            abort_if(NetworkAddress::isUnsafe($address), 422, 'Edge addresses must be public unicast service addresses.');
        }
        DB::transaction(function () use ($request, $edge, $data): void {
            $oldIpv4 = $edge->ipv4;
            $oldIpv6 = $edge->ipv6;
            $edge->update([
                ...$data,
                ...(isset($data['country_code']) ? ['country_code' => strtoupper($data['country_code'])] : []),
                ...(isset($data['continent_code']) ? ['continent_code' => strtoupper($data['continent_code'])] : []),
            ]);
            $defaultSharedId = EdgePool::query()->where('enabled', true)->where('kind', 'shared')->orderBy('id')->value('id');
            if ($defaultSharedId !== null) {
                $edge->cells()->where('edge_pool_id', $defaultSharedId)->where('service_ipv4', $oldIpv4)->update(['service_ipv4' => $edge->ipv4]);
                $edge->cells()->where('edge_pool_id', $defaultSharedId)->where(fn ($query) => $oldIpv6 === null ? $query->whereNull('service_ipv6') : $query->where('service_ipv6', $oldIpv6))->update(['service_ipv6' => $edge->ipv6]);
            }
            AuditLog::record($request->user(), 'edge.updated', $edge, ['fields' => array_keys($data)], $request->ip());
        });
        ReconcilePlatformDnsIdentity::dispatchForRoutingChange();

        return response()->json(['data' => $edge->refresh()]);
    }

    public function destroy(Request $request, Edge $edge): JsonResponse
    {
        abort_if($edge->enabled || ! $edge->drained, 409, 'Drain and disable the edge before deletion.');
        $edge->delete();
        AuditLog::record($request->user(), 'edge.deleted', $edge, [], $request->ip());
        ReconcilePlatformDnsIdentity::dispatchForRoutingChange();

        return response()->json(null, 204);
    }

    public function state(Request $request, Edge $edge, string $state): JsonResponse
    {
        $changes = match ($state) {
            'enable' => ['enabled' => true], 'disable' => ['enabled' => false], 'drain' => ['drained' => true], 'undrain' => ['drained' => false], default => abort(404)
        };
        DB::transaction(function () use ($edge, $changes, $request, $state): void {
            $edge->update($changes);
            AuditLog::record($request->user(), 'edge.'.$state, $edge, [], $request->ip());
        });
        ReconcilePlatformDnsIdentity::dispatchForRoutingChange();

        return response()->json(['data' => $edge->refresh()]);
    }

    public function enable(Request $request, Edge $edge): JsonResponse
    {
        return $this->state($request, $edge, 'enable');
    }

    public function disable(Request $request, Edge $edge): JsonResponse
    {
        return $this->state($request, $edge, 'disable');
    }

    public function drain(Request $request, Edge $edge): JsonResponse
    {
        return $this->state($request, $edge, 'drain');
    }

    public function undrain(Request $request, Edge $edge): JsonResponse
    {
        return $this->state($request, $edge, 'undrain');
    }

    public function rotate(Request $request, Edge $edge): JsonResponse
    {
        $token = Str::random(64);
        $edge->update([
            'identity_hash' => null, 'identity_csr_hash' => null, 'identity_certificate' => null,
            'identity_certificate_serial' => null, 'identity_certificate_expires_at' => null,
            'identity_revoked_at' => now(), 'bootstrap_token_hash' => hash('sha256', $token),
            'bootstrap_consumed_at' => null, 'registered_at' => null,
        ]);
        AuditLog::record($request->user(), 'edge.identity_rotated', $edge, [], $request->ip());
        ReconcilePlatformDnsIdentity::dispatch()->afterCommit();

        return response()->json(['data' => ['bootstrap_token' => $token]]);
    }
}
