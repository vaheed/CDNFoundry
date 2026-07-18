<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\PlatformDnsSettingsRequest;
use App\Jobs\ApplyPlatformDnsSettings;
use App\Models\AuditLog;
use App\Models\Operation;
use App\Models\PlatformDnsSetting;
use App\Support\PlatformDnsConfirmation;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class PlatformDnsSettingsController extends Controller
{
    public function show(): JsonResponse
    {
        return response()->json(['data' => PlatformDnsSetting::query()->find(1)]);
    }

    public function validateSettings(PlatformDnsSettingsRequest $request): JsonResponse
    {
        return response()->json(['data' => [
            'valid' => true, 'settings' => $request->validated(),
            'confirmation_token' => PlatformDnsConfirmation::issue($request->validated()),
            'confirmation_expires_in_seconds' => 900,
        ]]);
    }

    public function update(PlatformDnsSettingsRequest $request): JsonResponse
    {
        abort_unless(PlatformDnsConfirmation::valid($request->input('confirmation_token'), $request->validated()), 409, 'Validate and explicitly confirm this exact DNS identity payload before applying it.');
        $operation = DB::transaction(function () use ($request): Operation {
            $current = PlatformDnsSetting::query()->lockForUpdate()->find(1);
            $settings = PlatformDnsSetting::query()->updateOrCreate(['id' => 1], [
                ...$request->validated(),
                'revision' => ($current?->revision ?? 0) + 1,
            ]);
            $operation = Operation::query()->create([
                'actor_id' => $request->user()->getKey(), 'type' => 'platform_dns_identity.update',
                'status' => 'pending', 'input' => ['settings_id' => 1, 'revision' => $settings->revision],
            ]);
            AuditLog::record($request->user(), 'platform_dns_settings.update_requested', $settings, ['operation_id' => $operation->id, 'revision' => $settings->revision], $request->ip());

            return $operation;
        });
        ApplyPlatformDnsSettings::dispatch($operation->getKey())->afterCommit();

        return response()->json(['data' => $operation->refresh()], 202);
    }
}
