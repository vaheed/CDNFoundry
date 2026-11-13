<?php

namespace App\Http\Controllers;

use App\Models\Domain;
use App\Support\AnalyticsStore;
use App\Support\MitigationRules;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class SecurityMitigationController extends Controller
{
    /** The newest mitigated requests of a domain from stored request events, with the rule behind each. */
    public function index(Request $request, Domain $domain, AnalyticsStore $store): JsonResponse
    {
        Gate::authorize('view', $domain);
        $filters = $request->validate([
            'action' => ['sometimes', 'in:blocked,logged'], 'rule' => ['sometimes', 'string', 'max:24'], 'reason' => ['sometimes', 'string', 'max:64'],
            'score_max' => ['sometimes', 'integer', 'between:1,99'], 'path' => ['sometimes', 'string', 'max:512'], 'country' => ['sometimes', 'string', 'size:2'],
            'asn' => ['sometimes', 'integer', 'between:0,4294967295'], 'user_agent' => ['sometimes', 'string', 'max:256'], 'request_id' => ['sometimes', 'string', 'max:64'],
        ]);
        $request->merge(['from' => $request->query('from') ?? now('UTC')->subDay()->addMinute()->toIso8601String()]);
        $range = $store->range($request, true);
        $rows = $store->mitigations($domain, $range, $filters);
        $rules = MitigationRules::describe($domain, array_column($rows, 'security_rule'));
        $items = array_map(fn (array $row): array => [...$row, 'rule' => $row['security_rule'] === '' ? null : ['ref' => $row['security_rule'], ...$rules[$row['security_rule']]]], $rows);

        return response()->json(['data' => $items, 'meta' => [...$store->metadata($range), 'retention_days' => 7, 'maximum_rows' => AnalyticsStore::MITIGATIONS_LIMIT, 'truncated' => count($rows) >= AnalyticsStore::MITIGATIONS_LIMIT]]);
    }
}
