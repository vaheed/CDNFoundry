<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Throwable;

class HealthController extends Controller
{
    public function health(): JsonResponse
    {
        return response()->json(['status' => 'ok']);
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
        $queues = collect(['interactive', 'runtime', 'certificate_purge', 'bulk_maintenance'])->mapWithKeys(
            fn (string $queue): array => [$queue => ['depth' => Redis::connection()->llen("queues:$queue")]],
        );

        return response()->json(['data' => [
            'status' => collect($checks)->contains('status', 'failed') ? 'degraded' : 'ok',
            'checks' => $checks,
            'queues' => $queues,
            'duration_ms' => round((hrtime(true) - $started) / 1_000_000, 2),
        ]]);
    }
}
