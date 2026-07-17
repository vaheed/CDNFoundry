<?php

namespace App\Http\Controllers;

use App\Models\PlatformDnsSetting;
use Illuminate\Http\JsonResponse;

class NameserverController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $settings = PlatformDnsSetting::query()->find(1);
        abort_if($settings === null, 503, 'Platform nameservers are not configured.');

        return response()->json(['data' => collect($settings->nameservers)->map(fn (array $nameserver): array => [
            'hostname' => mb_strtolower(rtrim($nameserver['hostname'], '.')),
            'ipv4' => $nameserver['ipv4'], 'ipv6' => $nameserver['ipv6'],
        ])->values()]);
    }
}
