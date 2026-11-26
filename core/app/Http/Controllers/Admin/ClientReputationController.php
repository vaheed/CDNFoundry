<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ClientReputation;
use App\Models\Domain;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The shadow reputation bands, for platform administrators only. The list carries client keys and counts, never a domain's name,
 * and nothing here reaches a cell.
 */
class ClientReputationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate(['band' => ['sometimes', 'in:watch,elevated,high']]);
        $query = ClientReputation::query()->orderByDesc('score')->orderByDesc('id');
        if (isset($filters['band'])) {
            $query->where('band', $filters['band']);
        }
        $page = $query->cursorPaginate(50)->through(fn (ClientReputation $row): array => [
            'client' => $row->client_key, 'band' => $row->band, 'score' => $row->score, 'events' => $row->events, 'requests' => $row->requests,
            'domains' => $row->domain_count, 'cells' => $row->cell_count, 'edges' => $row->edge_count,
            'first_seen_at' => $row->first_seen_at->toIso8601String(), 'last_seen_at' => $row->last_seen_at->toIso8601String(),
        ]);

        return response()->json([
            'data' => $page, 'stage' => 'shadow', 'enrolled_domains' => Domain::query()->where('reputation_sharing', true)->count(),
        ]);
    }
}
