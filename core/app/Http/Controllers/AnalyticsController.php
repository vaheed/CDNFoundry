<?php

namespace App\Http\Controllers;

use App\Models\Domain;
use App\Support\AnalyticsStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class AnalyticsController extends Controller
{
    public function summary(Request $request, Domain $domain, AnalyticsStore $store): JsonResponse
    {
        Gate::authorize('view', $domain);
        $range = $store->range($request);

        return response()->json(['data' => $store->summary($domain, $range), 'meta' => $store->metadata($range)]);
    }

    public function view(Request $request, Domain $domain, string $view, AnalyticsStore $store): JsonResponse
    {
        Gate::authorize('view', $domain);
        $rawAggregate = in_array($view, ['top-urls', 'compression'], true);
        $range = $store->range($request, $rawAggregate);
        $data = $rawAggregate ? $store->topUrls($domain, $range) : $store->aggregate($domain, $range, $view);

        return response()->json(['data' => $data, 'meta' => $store->metadata($range)]);
    }

    public function compression(Request $request, Domain $domain, AnalyticsStore $store): JsonResponse
    {
        Gate::authorize('view', $domain);
        $range = $store->range($request, true);

        return response()->json(['data' => $store->aggregate($domain, $range, 'compression'), 'meta' => $store->metadata($range)]);
    }
}
