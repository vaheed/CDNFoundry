<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Domain;
use App\Support\SecurityAlertData;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class SecurityAlertController extends Controller
{
    public function show(Domain $domain): JsonResponse
    {
        Gate::authorize('view', $domain);

        return response()->json(['data' => $this->payload($domain)]);
    }

    public function update(Request $request, Domain $domain): JsonResponse
    {
        Gate::authorize('update', $domain);
        $alert = SecurityAlertData::validate($request->all());
        $domain->update(['security_alert' => $alert]);
        AuditLog::record($request->user(), 'security.alert_updated', $domain, $alert, $request->ip());

        return response()->json(['data' => $this->payload($domain->refresh())]);
    }

    public function destroy(Request $request, Domain $domain): JsonResponse
    {
        Gate::authorize('update', $domain);
        $domain->update(['security_alert' => null]);
        AuditLog::record($request->user(), 'security.alert_removed', $domain, [], $request->ip());

        return response()->json(['data' => $this->payload($domain->refresh())]);
    }

    private function payload(Domain $domain): array
    {
        return ['alert' => $domain->security_alert, 'periods' => array_keys(SecurityAlertData::PERIODS), 'cooldown_minutes' => SecurityAlertData::COOLDOWN_MINUTES];
    }
}
