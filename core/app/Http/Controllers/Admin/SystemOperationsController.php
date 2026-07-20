<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\SystemHealth;
use Illuminate\Http\JsonResponse;

class SystemOperationsController extends Controller
{
    public function health(SystemHealth $health): JsonResponse
    {
        $components = $health->components();

        return response()->json(['data' => ['status' => $health->overall($components), 'checked_at' => now()->toIso8601String()]]);
    }

    public function components(SystemHealth $health): JsonResponse
    {
        $components = $health->components();

        return response()->json(['data' => ['status' => $health->overall($components), 'components' => $components, 'queues' => $health->queues()]]);
    }
}
