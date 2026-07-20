<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\BuildUsageRollups;
use App\Jobs\ReconcileAllDnsZones;
use App\Jobs\ReconcileAllEdgeDomains;
use App\Jobs\ReconcileAllPurges;
use App\Jobs\ReconcileAllTls;
use App\Models\AuditLog;
use App\Models\Operation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReconciliationController extends Controller
{
    public function run(Request $request, string $scope): JsonResponse
    {
        abort_unless(in_array($scope, ['dns', 'edges', 'tls', 'purges', 'usage'], true), 404);
        $type = "{$scope}.global_reconcile";
        $operation = Operation::query()->where('type', $type)->whereIn('status', ['pending', 'running'])->first();
        if ($operation === null) {
            $input = $scope === 'usage' ? ['from' => now()->utc()->subHour()->startOfHour()->toIso8601String(), 'to' => now()->utc()->startOfHour()->toIso8601String()] : [];
            $operation = Operation::query()->create(['actor_id' => $request->user()->id, 'type' => $type, 'status' => 'pending', 'input' => $input]);
            AuditLog::record($request->user(), "{$scope}.global_reconcile_requested", $operation, [], $request->ip());
            match ($scope) {
                'dns' => ReconcileAllDnsZones::dispatch($operation->id)->afterCommit(),
                'edges' => ReconcileAllEdgeDomains::dispatch($operation->id)->afterCommit(),
                'tls' => ReconcileAllTls::dispatch($operation->id)->afterCommit(),
                'purges' => ReconcileAllPurges::dispatch($operation->id)->afterCommit(),
                'usage' => BuildUsageRollups::dispatch($input['from'], $input['to'], null, $operation->id)->afterCommit(),
            };
        }

        return response()->json(['data' => ['operation_id' => $operation->id, 'status' => $operation->status]], 202);
    }
}
