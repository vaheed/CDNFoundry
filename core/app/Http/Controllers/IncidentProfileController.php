<?php

namespace App\Http\Controllers;

use App\Actions\EndIncidentProfile;
use App\Actions\StartIncidentProfile;
use App\Models\Domain;
use App\Support\IncidentProfile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/** The incident profile of one domain: read its state, turn it on for a chosen time, or end it early. Delivery to the cells is asynchronous. */
class IncidentProfileController extends Controller
{
    public function show(Domain $domain): JsonResponse
    {
        Gate::authorize('view', $domain);

        return response()->json(['data' => $this->present($domain)]);
    }

    public function start(Request $request, Domain $domain): JsonResponse
    {
        Gate::authorize('update', $domain);
        $validated = $request->validate(['duration_minutes' => ['required', 'integer', 'between:'.IncidentProfile::MINIMUM_MINUTES.','.IncidentProfile::MAXIMUM_MINUTES]]);
        $started = StartIncidentProfile::execute($domain, $request->user(), (int) $validated['duration_minutes'], $request->ip());

        return response()->json(['data' => $this->present($started)], 201);
    }

    public function end(Request $request, Domain $domain): JsonResponse
    {
        Gate::authorize('update', $domain);
        abort_unless(EndIncidentProfile::execute($domain, $request->user(), 'manual', $request->ip()), 409, 'The incident profile is not on for this domain.');

        return response()->json(['data' => $this->present($domain->refresh())]);
    }

    /** @return array<string, mixed> */
    private function present(Domain $domain): array
    {
        $domain->refresh();

        return [
            'active' => IncidentProfile::active($domain),
            'started_at' => $domain->incident_started_at?->toIso8601String(),
            'expires_at' => $domain->incident_expires_at?->toIso8601String(),
            'started_by' => $domain->incident_started_by,
            'overlay_sequence' => (int) $domain->security_overlay_sequence,
            'protections' => IncidentProfile::protections(),
            'minimum_minutes' => IncidentProfile::MINIMUM_MINUTES, 'maximum_minutes' => IncidentProfile::MAXIMUM_MINUTES,
        ];
    }
}
