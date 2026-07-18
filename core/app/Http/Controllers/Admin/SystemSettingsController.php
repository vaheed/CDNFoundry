<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\PlatformSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SystemSettingsController extends Controller
{
    public function index(PlatformSettings $settings): JsonResponse
    {
        return response()->json(['data' => $settings->present()]);
    }

    public function show(string $group, PlatformSettings $settings): JsonResponse
    {
        return response()->json(['data' => $settings->present($group)[0]]);
    }

    public function update(Request $request, string $group, PlatformSettings $settings): JsonResponse
    {
        $payload = $request->validate(['values' => ['required', 'array']]);
        $result = $settings->update($group, $payload['values'], $request->user(), $request->ip());
        $data = ['setting' => $settings->present($group)[0], 'operation' => $result['operation']?->refresh()];

        return response()->json(['data' => $data], $result['operation'] === null ? 200 : 202);
    }

    public function updateSelected(Request $request, PlatformSettings $settings): JsonResponse
    {
        $payload = $request->validate(['group' => ['required', 'string', 'max:80'], 'values' => ['required', 'array']]);

        return $this->update($request, $payload['group'], $settings);
    }
}
