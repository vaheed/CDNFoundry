<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\AdvanceFleetRollout;
use App\Models\AuditLog;
use App\Models\Edge;
use App\Models\FleetRelease;
use App\Models\FleetRollout;
use App\Support\RuntimeVersions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class FleetRolloutController extends Controller
{
    public function releases(): JsonResponse
    {
        return response()->json(['data' => FleetRelease::query()->orderBy('id')->cursorPaginate(100)]);
    }

    public function storeRelease(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100', 'unique:fleet_releases'],
            'gateway_image' => ['required', 'string', 'max:255'], 'agent_image' => ['required', 'string', 'max:255'],
            'normal_cell_image' => ['required', 'string', 'max:255'], 'waf_cell_image' => ['required', 'string', 'max:255'],
            'minimum_compatible_version' => ['required', 'string', 'max:40'],
            'maximum_compatible_version' => ['required', 'string', 'max:40'],
        ]);
        RuntimeVersions::validate([
            'gateway' => $data['gateway_image'], 'agent' => $data['agent_image'],
            'normal_cell' => $data['normal_cell_image'], 'waf_cell' => $data['waf_cell_image'],
        ]);
        abort_if(version_compare($data['minimum_compatible_version'], $data['maximum_compatible_version'], '>'), 422, 'The compatibility range is invalid.');
        $release = FleetRelease::query()->create($data);
        AuditLog::record($request->user(), 'fleet_release.created', $release, [], $request->ip());

        return response()->json(['data' => $release], 201);
    }

    public function index(): JsonResponse
    {
        return response()->json(['data' => FleetRollout::query()->withCount('edges')->orderByDesc('created_at')->cursorPaginate(100)]);
    }

    public function show(FleetRollout $rollout): JsonResponse
    {
        return response()->json(['data' => $rollout->load(['release', 'previousRelease', 'edges.edge'])]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'fleet_release_id' => ['required', 'integer', Rule::exists('fleet_releases', 'id')->where('enabled', true)],
            'previous_release_id' => ['nullable', 'integer', 'different:fleet_release_id', 'exists:fleet_releases,id'],
            'canary_edge_ids' => ['required', 'array', 'between:1,20'],
            'canary_edge_ids.*' => ['required', 'uuid', 'distinct', 'exists:edges,id'],
            'edge_ids' => ['required', 'array', 'between:1,200'],
            'edge_ids.*' => ['required', 'uuid', 'distinct', 'exists:edges,id'],
            'wave_size' => ['required', 'integer', 'between:1,20'],
            'maximum_parallel' => ['required', 'integer', 'between:1,20'],
            'minimum_ready_percent' => ['required', 'integer', 'between:50,100'],
            'maximum_error_percent' => ['required', 'integer', 'between:0,25'],
            'mixed_version_window_minutes' => ['required', 'integer', 'between:5,1440'],
        ]);
        abort_if(array_diff($data['canary_edge_ids'], $data['edge_ids']) !== [], 422, 'Canary edges must be included in edge_ids.');
        $release = FleetRelease::query()->findOrFail($data['fleet_release_id']);
        $rollout = DB::transaction(function () use ($data, $release, $request): FleetRollout {
            $rollout = FleetRollout::query()->create([
                ...collect($data)->except(['canary_edge_ids', 'edge_ids', 'wave_size'])->all(),
                'created_by' => $request->user()->id,
            ]);
            $canaries = array_flip($data['canary_edge_ids']);
            $later = 0;
            foreach ($data['edge_ids'] as $edgeId) {
                $edge = Edge::query()->findOrFail($edgeId);
                $wave = isset($canaries[$edgeId]) ? 1 : 2 + intdiv($later++, $data['wave_size']);
                $rollout->edges()->create([
                    'edge_id' => $edgeId, 'wave' => $wave,
                    'previous_versions' => $edge->runtime_versions, 'desired_versions' => $release->versions(),
                ]);
            }
            AuditLog::record($request->user(), 'fleet_rollout.created', $rollout, ['edges' => count($data['edge_ids'])], $request->ip());

            return $rollout;
        });
        AdvanceFleetRollout::dispatch($rollout->id)->afterCommit();

        return response()->json(['data' => ['id' => $rollout->id, 'status' => $rollout->status]], 202);
    }

    public function resume(Request $request, FleetRollout $rollout): JsonResponse
    {
        abort_unless($rollout->status === 'paused', 409, 'Only a paused rollout can resume.');
        abort_if($rollout->edges()->where('status', 'failed')->exists(), 409, 'Resolve or roll back failed edges before resuming.');
        $rollout->update(['status' => 'running', 'pause_reason' => null]);
        AuditLog::record($request->user(), 'fleet_rollout.resumed', $rollout, [], $request->ip());
        AdvanceFleetRollout::dispatch($rollout->id)->afterCommit();

        return response()->json(['data' => ['id' => $rollout->id, 'status' => 'running']], 202);
    }

    public function rollback(Request $request, FleetRollout $rollout): JsonResponse
    {
        abort_unless(in_array($rollout->status, ['running', 'paused', 'failed'], true), 409, 'This rollout cannot be rolled back.');
        abort_if($rollout->previous_release_id === null, 409, 'No compatible previous release was recorded.');
        $previous = $rollout->previousRelease()->firstOrFail();
        DB::transaction(function () use ($request, $rollout, $previous): void {
            $rollout->update(['status' => 'rolling_back', 'pause_reason' => null]);
            $rollout->edges()->whereIn('status', ['succeeded', 'failed', 'dispatched'])->get()->each(function ($row) use ($previous): void {
                $row->update(['status' => 'pending', 'desired_versions' => $previous->versions(), 'failure_reason' => null, 'started_at' => null, 'finished_at' => null]);
            });
            AuditLog::record($request->user(), 'fleet_rollout.rollback_started', $rollout, ['release_id' => $previous->id], $request->ip());
        });
        AdvanceFleetRollout::dispatch($rollout->id)->afterCommit();

        return response()->json(['data' => ['id' => $rollout->id, 'status' => 'rolling_back']], 202);
    }
}
