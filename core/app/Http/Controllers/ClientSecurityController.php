<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\ClientScriptSighting;
use App\Models\Domain;
use App\Support\ClientSecurityData;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/** Client-side security: the opt-in (one signed revision and a queued edge update each) and the scripts and connections the browsers reported. */
class ClientSecurityController extends DetectionSettingsController
{
    protected function column(): string
    {
        return 'client_security';
    }

    protected function auditName(): string
    {
        return 'security.client_security';
    }

    protected function validated(array $input): array
    {
        return ClientSecurityData::validate($input);
    }

    protected function payload(Domain $domain): array
    {
        return [
            'client_security' => $domain->client_security,
            'report_path' => ClientSecurityData::REPORT_PATH, 'policy' => ClientSecurityData::POLICY,
            'maximum_sightings' => ClientSecurityData::MAXIMUM_SIGHTINGS,
            'unapproved' => $domain->clientScriptSightings()->whereNull('approved_at')->count(),
        ];
    }

    /** What browsers reported, newest first: scripts and connections per page with the day each was first seen. */
    public function sightings(Request $request, Domain $domain): JsonResponse
    {
        Gate::authorize('view', $domain);
        $filters = $request->validate(['kind' => ['sometimes', 'in:script,connection'], 'status' => ['sometimes', 'in:new,approved'], 'page' => ['sometimes', 'string', 'max:200']]);
        $query = $domain->clientScriptSightings()->orderByDesc('first_seen_at')->orderByDesc('id');
        foreach (['kind', 'page'] as $filter) {
            if (isset($filters[$filter])) {
                $query->where($filter === 'page' ? 'page_path' : 'kind', $filters[$filter]);
            }
        }
        if (isset($filters['status'])) {
            $filters['status'] === 'new' ? $query->whereNull('approved_at') : $query->whereNotNull('approved_at');
        }

        return response()->json(['data' => $query->cursorPaginate(100)->through(fn (ClientScriptSighting $sighting): array => $sighting->toListing())]);
    }

    /** Approves one sighting, or every unapproved one when no sighting is given. Nothing reaches a cell. */
    public function approve(Request $request, Domain $domain, ?int $sighting = null): JsonResponse
    {
        Gate::authorize('update', $domain);
        $query = $domain->clientScriptSightings()->whereNull('approved_at');
        if ($sighting !== null) {
            $query->whereKey($sighting);
        }
        $count = DB::transaction(function () use ($domain, $query, $request, $sighting): int {
            $count = $query->update(['approved_at' => now(), 'source' => 'approved']);
            AuditLog::record($request->user(), 'security.client_scripts_approved', $domain, ['sighting_id' => $sighting, 'count' => $count], $request->ip());

            return $count;
        });
        abort_if($sighting !== null && $count === 0 && ! $domain->clientScriptSightings()->whereKey($sighting)->exists(), 404);

        return response()->json(['data' => ['approved' => $count]]);
    }
}
