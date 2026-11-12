<?php

use App\Http\Controllers\Admin\UsageController as AdminUsageController;
use App\Http\Controllers\AlertmanagerReceiverController;
use App\Http\Controllers\EdgeAgentController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\MetricsController;
use App\Http\Controllers\UsageController;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

Route::prefix('edge/v1')->middleware(['api', 'control.epoch'])->group(function (): void {
    Route::post('/register', [EdgeAgentController::class, 'register'])->middleware('throttle:edge-register');
    Route::middleware(['edge.auth', 'throttle:edge-agent'])->group(function (): void {
        Route::post('/identity/renew', [EdgeAgentController::class, 'renewIdentity']);
        Route::post('/heartbeat', [EdgeAgentController::class, 'heartbeat']);
        Route::get('/probes/targets', [EdgeAgentController::class, 'probeTargets']);
        Route::post('/probes', [EdgeAgentController::class, 'probeResults']);
        Route::get('/gateway/config', [EdgeAgentController::class, 'gatewayConfig']);
        Route::get('/dns/transfer', [EdgeAgentController::class, 'dnsTransfer']);
        Route::get('/dns/changes', [EdgeAgentController::class, 'dnsChanges']);
        Route::get('/origin-policy', [EdgeAgentController::class, 'originPolicy']);
        Route::get('/control-addresses', [EdgeAgentController::class, 'controlAddresses']);
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
// Alertmanager's private receiver; it answers 404 without the monitoring token.
Route::post('/internal/alertmanager', AlertmanagerReceiverController::class)->withoutMiddleware([
    EncryptCookies::class, AddQueuedCookiesToResponse::class, StartSession::class, ShareErrorsFromSession::class, PreventRequestForgery::class,
]);
// Public and secret-free: lets a node and an operator learn the epoch before starting services.
Route::get('/control-state', [HealthController::class, 'controlState'])->withoutMiddleware([
    EncryptCookies::class, AddQueuedCookiesToResponse::class, StartSession::class, ShareErrorsFromSession::class, PreventRequestForgery::class,
]);

Route::middleware(['auth', 'account.active'])->group(function (): void {
    Route::get('/app/analytics/domains/{domain}/usage.csv', [UsageController::class, 'csv'])
        ->name('app.analytics.usage.csv');
    Route::get('/admin/telemetry/usage.csv', [AdminUsageController::class, 'csv'])
        ->middleware('admin')
        ->name('admin.telemetry.usage.csv');
});
