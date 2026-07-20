<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\CreateControlBackup;
use App\Jobs\DeleteControlBackup;
use App\Jobs\PreflightBackupRestore;
use App\Models\AuditLog;
use App\Models\Backup;
use App\Models\Operation;
use App\Support\ResticBackupRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class BackupController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return JsonResource::collection(Backup::query()->orderBy('id')->cursorPaginate(50));
    }

    public function show(Backup $backup): JsonResource
    {
        return JsonResource::make($backup);
    }

    public function store(Request $request, ResticBackupRepository $repository): JsonResponse
    {
        abort_unless($repository->configured(), 503, 'Encrypted off-host backup repository is not configured.');
        [$backup, $operation] = DB::transaction(function () use ($request): array {
            $backup = Backup::query()->create(['requested_by' => $request->user()->id, 'status' => 'pending']);
            $operation = Operation::query()->create(['actor_id' => $request->user()->id, 'type' => 'backup.create', 'status' => 'pending', 'input' => ['backup_id' => $backup->id]]);
            AuditLog::record($request->user(), 'backup.create_requested', $backup, ['operation_id' => $operation->id], $request->ip());

            return [$backup, $operation];
        });
        CreateControlBackup::dispatch($backup->id, $operation->id)->afterCommit();

        return response()->json(['data' => ['backup_id' => $backup->id, 'operation_id' => $operation->id, 'status' => 'pending']], 202);
    }

    public function restore(Request $request, Backup $backup): JsonResponse
    {
        $data = $request->validate(['confirmation' => ['required', 'string', 'max:100'], 'current_password' => ['required', 'string', 'max:200']]);
        abort_unless(hash_equals("RESTORE {$backup->id}", $data['confirmation']), 422, 'The restore confirmation value is incorrect.');
        abort_unless(Hash::check($data['current_password'], $request->user()->password), 422, 'Administrator re-authentication failed.');
        abort_unless($backup->status === 'succeeded' && $backup->snapshot_id !== null, 409, 'Only a completed backup can be restored.');
        $operation = Operation::query()->create(['actor_id' => $request->user()->id, 'type' => 'backup.restore', 'status' => 'pending', 'input' => ['backup_id' => $backup->id]]);
        AuditLog::record($request->user(), 'backup.restore_preflight_requested', $backup, ['operation_id' => $operation->id], $request->ip());
        PreflightBackupRestore::dispatch($backup->id, $operation->id)->afterCommit();

        return response()->json(['data' => ['operation_id' => $operation->id, 'status' => 'pending']], 202);
    }

    public function destroy(Request $request, Backup $backup): JsonResponse
    {
        abort_if($backup->status === 'running', 409, 'A running backup cannot be deleted.');
        $operation = Operation::query()->create(['actor_id' => $request->user()->id, 'type' => 'backup.delete', 'status' => 'pending', 'input' => ['backup_id' => $backup->id]]);
        $backup->update(['status' => 'deleting']);
        AuditLog::record($request->user(), 'backup.delete_requested', $backup, ['operation_id' => $operation->id], $request->ip());
        DeleteControlBackup::dispatch($backup->id, $operation->id)->afterCommit();

        return response()->json(['data' => ['operation_id' => $operation->id, 'status' => 'pending']], 202);
    }
}
