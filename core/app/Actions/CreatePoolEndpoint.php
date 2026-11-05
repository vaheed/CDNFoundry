<?php

namespace App\Actions;

use App\Jobs\ReconcilePlatformDnsIdentity;
use App\Models\AuditLog;
use App\Models\Edge;
use App\Models\EdgePool;
use App\Models\EdgePoolEndpoint;
use App\Models\User;
use App\Support\EdgePoolEndpointData;
use Illuminate\Support\Facades\DB;

/** Gives a Geo-Unicast pool its public service address on one edge. Shared by the admin API and PoP enrollment. */
class CreatePoolEndpoint
{
    /** @param array<string, mixed> $input */
    public function handle(EdgePool $pool, Edge $edge, array $input, ?User $actor, ?string $ip): EdgePoolEndpoint
    {
        abort_if($pool->isSimpleAnycast(), 409, 'Simple Anycast participation is created automatically when its first cell is assigned to this edge.');
        abort_if($pool->endpoints()->where('edge_id', $edge->id)->exists(), 409, 'This pool already owns an endpoint on the edge.');
        $data = EdgePoolEndpointData::validate($input, null, $pool);
        $endpoint = DB::transaction(function () use ($actor, $ip, $pool, $edge, $data): EdgePoolEndpoint {
            $endpoint = $pool->endpoints()->create([...$data, 'edge_id' => $edge->id, 'revision' => 1, 'gateway_state' => 'pending', 'readiness_reason' => 'gateway_not_acknowledged']);
            $pool->update(['revision' => $pool->revision + 1]);
            AuditLog::record($actor, 'edge.pool_endpoint_created', $endpoint, ['edge_id' => $edge->id, 'pool_id' => $pool->id], $ip);

            return $endpoint;
        });
        ReconcilePlatformDnsIdentity::dispatchForRoutingChange($actor?->id);

        return $endpoint;
    }
}
