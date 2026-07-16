<?php

use App\Http\Controllers\Admin\AuditLogController;
use App\Http\Controllers\Admin\PlatformDnsSettingsController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\OperationController;
use App\Http\Controllers\TokenController;
use Illuminate\Support\Facades\Route;

Route::get('/health', [HealthController::class, 'health']);
Route::get('/ready', [HealthController::class, 'ready']);
Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:login');

Route::middleware(['auth:sanctum', 'account.active'])->group(function (): void {
    Route::post('/auth/logout', [AuthController::class, 'logout'])->middleware('idempotent');
    Route::get('/me', [AuthController::class, 'me']);
    Route::patch('/me', [AuthController::class, 'update'])->middleware('idempotent');
    Route::put('/me/password', [AuthController::class, 'password'])->middleware('idempotent');
    Route::get('/me/tokens', [TokenController::class, 'index']);
    Route::post('/me/tokens', [TokenController::class, 'store'])->middleware('idempotent');
    Route::delete('/me/tokens/{token}', [TokenController::class, 'destroy'])->middleware('idempotent');
    Route::get('/operations/{operation}', [OperationController::class, 'show']);

    Route::prefix('admin')->middleware('admin')->group(function (): void {
        Route::get('/users', [UserController::class, 'index']);
        Route::post('/users', [UserController::class, 'store'])->middleware('idempotent');
        Route::get('/users/{user}', [UserController::class, 'show']);
        Route::get('/users/{user}/domains', [UserController::class, 'domains']);
        Route::patch('/users/{user}', [UserController::class, 'update'])->middleware('idempotent');
        Route::post('/users/{user}/disable', [UserController::class, 'disable'])->middleware('idempotent');
        Route::post('/users/{user}/enable', [UserController::class, 'enable'])->middleware('idempotent');
        Route::delete('/users/{user}', [UserController::class, 'destroy'])->middleware('idempotent');
        Route::get('/audit-logs', AuditLogController::class);
        Route::get('/system/status', [HealthController::class, 'status']);
        Route::get('/operations', [OperationController::class, 'index']);
        Route::get('/operations/{operation}', [OperationController::class, 'show']);
        Route::post('/operations/{operation}/retry', [OperationController::class, 'retry'])->middleware('idempotent');
        Route::get('/system/settings/dns', [PlatformDnsSettingsController::class, 'show']);
        Route::patch('/system/settings/dns', [PlatformDnsSettingsController::class, 'update'])->middleware('idempotent');
        Route::post('/system/settings/dns/validate', [PlatformDnsSettingsController::class, 'validateSettings'])->middleware('idempotent');
    });
});
