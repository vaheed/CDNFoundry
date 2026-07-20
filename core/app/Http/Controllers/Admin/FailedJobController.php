<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

class FailedJobController extends Controller
{
    public function index(): JsonResponse
    {
        $jobs = DB::table('failed_jobs')->orderBy('id')->cursorPaginate(50);
        $jobs->through(fn ($job): array => [
            'id' => $job->id, 'uuid' => $job->uuid, 'connection' => $job->connection, 'queue' => $job->queue,
            'job' => data_get(json_decode($job->payload, true), 'displayName', 'unknown'),
            'exception' => mb_substr(strtok($job->exception, "\n") ?: 'Job failed', 0, 500), 'failed_at' => $job->failed_at,
        ]);

        return response()->json($jobs);
    }

    public function retry(Request $request, string $job): JsonResponse
    {
        $row = DB::table('failed_jobs')->where('uuid', $job)->orWhere('id', ctype_digit($job) ? (int) $job : -1)->first();
        abort_if($row === null, 404, 'Failed job not found.');
        Artisan::call('queue:retry', ['id' => [$row->uuid]]);
        AuditLog::record($request->user(), 'failed_job.retry_requested', null, ['uuid' => $row->uuid, 'queue' => $row->queue], $request->ip());

        return response()->json(['data' => ['uuid' => $row->uuid, 'status' => 'queued']], 202);
    }

    public function destroy(Request $request, string $job): JsonResponse
    {
        $row = DB::table('failed_jobs')->where('uuid', $job)->orWhere('id', ctype_digit($job) ? (int) $job : -1)->first();
        abort_if($row === null, 404, 'Failed job not found.');
        DB::table('failed_jobs')->where('uuid', $row->uuid)->delete();
        AuditLog::record($request->user(), 'failed_job.deleted', null, ['uuid' => $row->uuid, 'queue' => $row->queue], $request->ip());

        return response()->json(null, 204);
    }
}
