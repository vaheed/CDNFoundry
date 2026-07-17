<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Edge;
use App\Models\EdgePool;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

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
        $data = $request->validate(['name' => ['required', 'string', 'max:100', 'unique:edges'], 'country_code' => ['required', 'alpha:ascii', 'size:2'], 'continent_code' => ['required', 'alpha:ascii', 'size:2'], 'ipv4' => ['required', 'ipv4', 'unique:edges'], 'ipv6' => ['nullable', 'ipv6', 'unique:edges']]);
        $token = Str::random(64);
        $edge = Edge::query()->create(array_merge($data, ['country_code' => strtoupper($data['country_code']), 'continent_code' => strtoupper($data['continent_code']), 'bootstrap_token_hash' => hash('sha256', $token)]));
        foreach (EdgePool::query()->where('enabled', true)->get() as $pool) {
            $edge->cells()->create(['edge_pool_id' => $pool->id, 'name' => 'pool-'.$pool->id]);
        }
        AuditLog::record($request->user(), 'edge.created', $edge, [], $request->ip());

        return response()->json(['data' => array_merge($edge->toArray(), ['bootstrap_token' => $token])], 201);
    }

    public function update(Request $request, Edge $edge): JsonResponse
    {
        $edge->update($request->validate(['name' => ['sometimes', 'string', 'max:100'], 'country_code' => ['sometimes', 'alpha:ascii', 'size:2'], 'continent_code' => ['sometimes', 'alpha:ascii', 'size:2'], 'ipv4' => ['sometimes', 'ipv4'], 'ipv6' => ['sometimes', 'nullable', 'ipv6']]));

        return response()->json(['data' => $edge->refresh()]);
    }

    public function destroy(Request $request, Edge $edge): JsonResponse
    {
        abort_if($edge->enabled || ! $edge->drained, 409, 'Drain and disable the edge before deletion.');
        $edge->delete();
        AuditLog::record($request->user(), 'edge.deleted', $edge, [], $request->ip());

        return response()->json(null, 204);
    }

    public function state(Request $request, Edge $edge, string $state): JsonResponse
    {
        $changes = match ($state) {
            'enable' => ['enabled' => true], 'disable' => ['enabled' => false], 'drain' => ['drained' => true], 'undrain' => ['drained' => false], default => abort(404)
        };
        $edge->update($changes);
        AuditLog::record($request->user(), 'edge.'.$state, $edge, [], $request->ip());

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
        $edge->update(['identity_hash' => null, 'identity_certificate_serial' => null, 'identity_certificate_expires_at' => null, 'identity_revoked_at' => now(), 'bootstrap_token_hash' => hash('sha256', $token), 'registered_at' => null]);
        AuditLog::record($request->user(), 'edge.identity_rotated', $edge, [], $request->ip());

        return response()->json(['data' => ['bootstrap_token' => $token]]);
    }
}
