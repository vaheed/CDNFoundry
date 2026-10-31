<?php

use App\Http\Controllers\Admin\UsageController as AdminUsageController;
use App\Http\Controllers\EdgeAgentController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\MetricsController;
use App\Http\Controllers\UsageController;
use Illuminate\Support\Facades\Route;

Route::prefix('edge/v1')->middleware('api')->group(function (): void {
    Route::post('/register', [EdgeAgentController::class, 'register'])->middleware('throttle:edge-register');
    Route::middleware(['edge.auth', 'throttle:edge-agent'])->group(function (): void {
        Route::post('/identity/renew', [EdgeAgentController::class, 'renewIdentity']);
        Route::post('/heartbeat', [EdgeAgentController::class, 'heartbeat']);
        Route::get('/probes/targets', [EdgeAgentController::class, 'probeTargets']);
        Route::post('/probes', [EdgeAgentController::class, 'probeResults']);
        Route::get('/gateway/config', [EdgeAgentController::class, 'gatewayConfig']);
        Route::get('/origin-policy', [EdgeAgentController::class, 'originPolicy']);
        Route::get('/config/manifest', [EdgeAgentController::class, 'manifest']);
        Route::get('/config/artifacts/{checksum}', [EdgeAgentController::class, 'artifact']);
        Route::get('/certificates/{certificate}/key', [EdgeAgentController::class, 'certificateKey']);
        Route::get('/config/full', [EdgeAgentController::class, 'full']);
        Route::post('/config/applied', [EdgeAgentController::class, 'applied']);
        Route::post('/config/rejected', [EdgeAgentController::class, 'rejected']);
        Route::get('/tasks', [EdgeAgentController::class, 'tasks']);
        Route::post('/tasks/{task}/result', [EdgeAgentController::class, 'taskResult']);
    });
});

Route::get('/', function () {
    return view('welcome');
});
Route::get('/health', [HealthController::class, 'page'])->name('service-health');
Route::get('/metrics', MetricsController::class);

Route::middleware(['auth', 'account.active'])->group(function (): void {
    Route::get('/app/analytics/domains/{domain}/usage.csv', [UsageController::class, 'csv'])
        ->name('app.analytics.usage.csv');
    Route::get('/admin/telemetry/usage.csv', [AdminUsageController::class, 'csv'])
        ->middleware('admin')
        ->name('admin.telemetry.usage.csv');
});
