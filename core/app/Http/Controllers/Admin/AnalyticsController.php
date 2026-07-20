<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\AnalyticsStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AnalyticsController extends Controller
{
    public function summary(Request $request, AnalyticsStore $store): JsonResponse
    {
        $range = $store->range($request);

        return response()->json(['data' => $store->summary(null, $range), 'meta' => $store->metadata($range)]);
    }

    public function view(Request $request, string $view, AnalyticsStore $store): JsonResponse
    {
        $range = $store->range($request);

        return response()->json(['data' => $store->aggregate(null, $range, $view), 'meta' => $store->metadata($range)]);
    }
}
