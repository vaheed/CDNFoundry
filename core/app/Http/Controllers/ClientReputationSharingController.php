<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\ClientReputationReport;
use App\Models\Domain;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * A domain's opt-in to platform reputation. In the shadow stage it only lets the control plane add up what cells report about
 * clients of this domain; it never changes how a request to the domain is served, and the domain sees no other domain's data.
 */
class ClientReputationSharingController extends Controller
{
    public function show(Domain $domain): JsonResponse
    {
        Gate::authorize('view', $domain);

        return response()->json(['data' => $this->payload($domain)]);
    }

    public function update(Request $request, Domain $domain): JsonResponse
    {
        Gate::authorize('update', $domain);
        $data = $request->validate(['enabled' => ['required', 'boolean']]);
        DB::transaction(function () use ($domain, $data, $request): void {
            $domain->forceFill(['reputation_sharing' => (bool) $data['enabled']])->save();
            if (! $data['enabled']) {
                ClientReputationReport::query()->where('domain_id', $domain->id)->delete();
            }
            AuditLog::record($request->user(), 'security.reputation_sharing', $domain, ['enabled' => (bool) $data['enabled']], $request->ip());
        });

        return response()->json(['data' => $this->payload($domain->refresh())]);
    }

    /** @return array<string, mixed> */
    private function payload(Domain $domain): array
    {
        return ['enabled' => $domain->reputation_sharing, 'stage' => 'shadow', 'effect' => 'none'];
    }
}
