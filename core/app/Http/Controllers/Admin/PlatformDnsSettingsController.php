<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\PlatformDnsSettingsRequest;
use App\Jobs\ApplyPlatformDnsSettings;
use App\Models\AuditLog;
use App\Models\Operation;
use App\Models\PlatformDnsSetting;
use Illuminate\Http\JsonResponse;

class PlatformDnsSettingsController extends Controller
{
    public function show(): JsonResponse
    {
        return response()->json(['data' => PlatformDnsSetting::query()->find(1)]);
    }

    public function validateSettings(PlatformDnsSettingsRequest $request): JsonResponse
    {
        return response()->json(['data' => ['valid' => true, 'settings' => $request->validated()]]);
    }

    public function update(PlatformDnsSettingsRequest $request): JsonResponse
    {
        $operation = Operation::query()->create([
            'actor_id' => $request->user()->getKey(), 'type' => 'platform_dns_identity.update',
            'status' => 'pending', 'input' => $request->validated(),
        ]);
        AuditLog::record($request->user(), 'platform_dns_settings.update_requested', $operation, [], $request->ip());
        ApplyPlatformDnsSettings::dispatch($operation->getKey());

        return response()->json(['data' => $operation->refresh()], 202);
    }
}
