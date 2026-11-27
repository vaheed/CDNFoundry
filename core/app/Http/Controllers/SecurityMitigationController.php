<?php

namespace App\Http\Controllers;

use App\Models\Domain;
use App\Support\AnalyticsStore;
use App\Support\DecisionTrace;
use App\Support\MitigationRules;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Gate;

class SecurityMitigationController extends Controller
{
    /** Mitigated requests of a domain from stored request events, newest first and paged by cursor, with the rule and decision trace behind each. */
    public function index(Request $request, Domain $domain, AnalyticsStore $store): JsonResponse
    {
        Gate::authorize('view', $domain);
        $filters = $request->validate([
            'action' => ['sometimes', 'in:blocked,logged'], 'rule' => ['sometimes', 'string', 'max:24'], 'reason' => ['sometimes', 'string', 'max:64'],
            'score_max' => ['sometimes', 'integer', 'between:1,99'], 'path' => ['sometimes', 'string', 'max:512'], 'country' => ['sometimes', 'string', 'size:2'],
            'asn' => ['sometimes', 'integer', 'between:0,4294967295'], 'user_agent' => ['sometimes', 'string', 'max:256'], 'request_id' => ['sometimes', 'string', 'max:64'], 'client_key' => ['sometimes', 'string', 'regex:/^[0-9a-f]{12}$/'],
            'cursor' => ['sometimes', 'string', 'max:200'], 'limit' => ['sometimes', 'integer', 'between:1,'.AnalyticsStore::MITIGATIONS_PAGE],
        ]);
        $request->merge(['from' => $request->query('from') ?? now('UTC')->subDay()->addMinute()->toIso8601String()]);
        $range = $store->range($request, true);
        $page = $store->mitigations($domain, $range, Arr::except($filters, ['cursor', 'limit']), $filters['cursor'] ?? null, (int) ($filters['limit'] ?? AnalyticsStore::MITIGATIONS_PAGE));
        $rows = $page['items'];
        $rules = MitigationRules::describe($domain, array_column($rows, 'security_rule'));
        $items = array_map(fn (array $row): array => [...$row, 'rule' => $row['security_rule'] === '' ? null : ['ref' => $row['security_rule'], ...$rules[$row['security_rule']]]], $rows);

        return response()->json(['data' => $items, 'meta' => [...$store->metadata($range), 'retention_days' => 7, 'page_size' => AnalyticsStore::MITIGATIONS_PAGE, 'next_cursor' => $page['next_cursor'], 'unavailable_signals' => DecisionTrace::UNAVAILABLE]]);
    }
}
