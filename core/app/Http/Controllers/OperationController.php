<?php

namespace App\Http\Controllers;

use App\Jobs\ApplyPlatformDnsSettings;
use App\Models\AuditLog;
use App\Models\Operation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;

class OperationController extends Controller
{
    public function show(Request $request, Operation $operation): JsonResource
    {
        abort_unless($request->user()->isAdmin() || $operation->actor_id === $request->user()->getKey(), 403);

        return JsonResource::make($operation);
    }

    public function index(): AnonymousResourceCollection
    {
        return JsonResource::collection(Operation::query()->latest()->cursorPaginate(50));
    }

    public function retry(Request $request, Operation $operation): JsonResponse
    {
        abort_unless($operation->status === 'failed', 409, 'Only failed operations can be retried.');
        abort_unless($operation->type === 'platform_dns_identity.update', 422, 'Unsupported operation type.');
        $operation->update(['status' => 'pending', 'error' => null, 'finished_at' => null]);
        AuditLog::record($request->user(), 'operation.retry_requested', $operation, [], $request->ip());
        ApplyPlatformDnsSettings::dispatch($operation->getKey());

        return response()->json(['data' => $operation->refresh()], 202);
    }
}
