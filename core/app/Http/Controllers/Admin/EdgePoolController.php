<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Edge;
use App\Models\EdgePool;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EdgePoolController extends Controller
{
    public function index()
    {
        return response()->json(['data' => EdgePool::query()->orderBy('id')->cursorPaginate(100)]);
    }

    public function show(EdgePool $pool): JsonResponse
    {
        return response()->json(['data' => $pool]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:100', 'unique:edge_pools'], 'kind' => ['required', 'in:shared,quarantine,dedicated']]);
        $pool = EdgePool::query()->create($data);
        foreach (Edge::query()->get() as $edge) {
            $edge->cells()->create(['edge_pool_id' => $pool->id, 'name' => $pool->name]);
        }

        return response()->json(['data' => $pool], 201);
    }

    public function update(Request $request, EdgePool $pool): JsonResponse
    {
        $pool->update($request->validate(['name' => ['sometimes', 'string', 'max:100'], 'enabled' => ['sometimes', 'boolean']]));
        $pool->cells()->update(['name' => $pool->name]);

        return response()->json(['data' => $pool->refresh()]);
    }

    public function state(EdgePool $pool, string $state): JsonResponse
    {
        $pool->update(['enabled' => $state === 'enable', 'revision' => $pool->revision + 1]);

        return response()->json(['data' => $pool]);
    }

    public function enable(EdgePool $pool): JsonResponse
    {
        return $this->state($pool, 'enable');
    }

    public function disable(EdgePool $pool): JsonResponse
    {
        return $this->state($pool, 'disable');
    }
}
