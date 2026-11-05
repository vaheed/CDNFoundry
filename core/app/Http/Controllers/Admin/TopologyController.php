<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\SystemHealth;
use App\Support\TopologyGraph;
use Illuminate\Http\JsonResponse;

class TopologyController extends Controller
{
    public function show(SystemHealth $health, TopologyGraph $graph): JsonResponse
    {
        return response()->json(['data' => $graph->build($health->components())]);
    }
}
