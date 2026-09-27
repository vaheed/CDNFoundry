<?php

namespace App\Http\Controllers;

use App\Support\SystemHealth;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Throwable;

class HealthController extends Controller
{
    private const QUEUES = ['interactive', 'runtime', 'certificate_purge', 'bulk_maintenance'];

    public function health(Request $request): JsonResponse|RedirectResponse
    {
        if ($request->query('format') !== 'json' && str_contains((string) $request->header('Accept'), 'text/html')) {
            return redirect('/health');
        }

        return response()->json(['status' => 'ok']);
    }

    public function page(): Response
    {
        try {
            $snapshot = Cache::get(SystemHealth::PUBLIC_CACHE_KEY);
        } catch (Throwable) {
            $snapshot = null;
        }

        $checkedAt = is_array($snapshot) ? ($snapshot['checked_at'] ?? null) : null;
        $timestamp = is_string($checkedAt) ? strtotime($checkedAt) : false;
        $fresh = $timestamp !== false && abs(now()->timestamp - $timestamp) <= SystemHealth::PUBLIC_FRESH_SECONDS;
        if (! is_array($snapshot) || ! is_array($snapshot['groups'] ?? null)) {
            $snapshot = ['status' => 'unknown', 'checked_at' => null, 'groups' => []];
        }
        if ($timestamp === false) {
            $snapshot['checked_at'] = null;
        }

        return response()->view('service-health', [
            'snapshot' => $snapshot,
            'status' => $fresh ? $snapshot['status'] : 'unknown',
            'fresh' => $fresh,
        ])->header('Cache-Control', 'no-store');
    }

    public function ready(): JsonResponse
    {
        $checks = [];
        try {
            DB::select('select 1');
            $checks['database'] = 'ok';
        } catch (Throwable) {
            $checks['database'] = 'failed';
        }
        try {
            Redis::connection()->command('ping');
            $checks['redis'] = 'ok';
        } catch (Throwable) {
            $checks['redis'] = 'failed';
        }
        $ready = ! in_array('failed', $checks, true);

        return response()->json(['status' => $ready ? 'ready' : 'not_ready', 'checks' => $checks], $ready ? 200 : 503);
    }

    public function status(): JsonResponse
    {
        $started = hrtime(true);
        $checks = [];
        try {
            DB::select('select 1');
            $checks['database'] = ['status' => 'ok'];
        } catch (Throwable $exception) {
            $checks['database'] = ['status' => 'failed', 'message' => $exception->getMessage()];
        }
        try {
            Redis::connection()->command('ping');
            $checks['redis'] = ['status' => 'ok'];
        } catch (Throwable $exception) {
            $checks['redis'] = ['status' => 'failed', 'message' => $exception->getMessage()];
        }
        $queues = collect(self::QUEUES)->mapWithKeys(function (string $queue): array {
            $connection = Redis::connection();
            $depth = (int) $connection->llen("queues:$queue");
            $oldest = $depth > 0 ? json_decode((string) $connection->lindex("queues:$queue", 0), true) : null;
            $pushedAt = is_array($oldest) ? ($oldest['pushedAt'] ?? $oldest['pushed_at'] ?? null) : null;

            return [$queue => [
                'depth' => $depth,
                'oldest_job_age_seconds' => is_numeric($pushedAt) ? max(0, (int) floor(microtime(true) - (float) $pushedAt)) : null,
            ]];
        });

        return response()->json(['data' => [
            'status' => collect($checks)->contains('status', 'failed') ? 'degraded' : 'ok',
            'checks' => $checks,
            'queues' => $queues,
            'duration_ms' => round((hrtime(true) - $started) / 1_000_000, 2),
        ]]);
    }
}
