<?php

use App\Http\Controllers\EdgeAgentController;
use Illuminate\Support\Facades\Route;

Route::prefix('edge/v1')->middleware('api')->group(function (): void {
    Route::post('/register', [EdgeAgentController::class, 'register']);
    Route::middleware('edge.auth')->group(function (): void {
        Route::post('/heartbeat', [EdgeAgentController::class, 'heartbeat']);
        Route::get('/config/manifest', [EdgeAgentController::class, 'manifest']);
        Route::get('/config/artifacts/{checksum}', [EdgeAgentController::class, 'artifact']);
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
