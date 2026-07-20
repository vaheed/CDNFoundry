<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\AnalyticsStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LogController extends Controller
{
    public function index(Request $request, string $stream, AnalyticsStore $store): JsonResponse
    {
        $range = $store->range($request, true);
        $result = $store->logs(null, $range, $stream, $request->query('cursor'));

        return response()->json(['data' => $result['items'], 'meta' => [...$store->metadata($range), 'next_cursor' => $result['next_cursor']]]);
    }
}
