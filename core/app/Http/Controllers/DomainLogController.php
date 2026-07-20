<?php

namespace App\Http\Controllers;

use App\Models\Domain;
use App\Support\AnalyticsStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class DomainLogController extends Controller
{
    public function index(Request $request, Domain $domain, string $stream, AnalyticsStore $store): JsonResponse
    {
        Gate::authorize('view', $domain);
        $range = $store->range($request, true);
        $result = $store->logs($domain, $range, $stream, $request->query('cursor'));

        return response()->json(['data' => $result['items'], 'meta' => [...$store->metadata($range), 'next_cursor' => $result['next_cursor']]]);
    }
}
