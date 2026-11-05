<?php

namespace App\Actions;

use App\Models\AuditLog;
use App\Models\Edge;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Creates an edge with its cell slots and a one-time bootstrap token. The token is returned once and stored only as a hash. */
class CreateEdge
{
    /**
     * @param  array{name: string, country_code: string, continent_code: string, cell_slot_count?: int}  $data
     * @return array{0: Edge, 1: string}
     */
    public function handle(array $data, ?User $actor, ?string $ip): array
    {
        $token = Str::random(64);
        $edge = DB::transaction(function () use ($data, $token, $actor, $ip): Edge {
            $edge = Edge::query()->create(array_merge($data, ['country_code' => strtoupper($data['country_code']), 'continent_code' => strtoupper($data['continent_code']), 'cell_slot_count' => Edge::CELL_SLOT_COUNT, 'bootstrap_token_hash' => hash('sha256', $token), 'bootstrap_expires_at' => now()->addHours(Edge::BOOTSTRAP_TOKEN_HOURS)]));
            for ($slot = 1; $slot <= $edge->cell_slot_count; $slot++) {
                $edge->cells()->create([
                    'slot' => $slot, 'edge_pool_id' => null, 'status' => 'unassigned',
                ]);
            }
            AuditLog::record($actor, 'edge.created', $edge, [], $ip);

            return $edge;
        });

        return [$edge, $token];
    }
}
