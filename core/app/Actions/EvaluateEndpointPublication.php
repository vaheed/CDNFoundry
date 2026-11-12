<?php

namespace App\Actions;

use App\Models\AuditLog;
use App\Models\EdgePool;
use App\Models\EdgePoolEndpoint;

/**
 * Decides which pool endpoints DNS publishes. A PoP leaves DNS only on a
 * positive signal. A pool never loses its whole answer set to an automatic
 * signal: when every endpoint looks unhealthy the last published set stays and
 * the pool is flagged so monitoring can alert.
 */
class EvaluateEndpointPublication
{
    /** Reasons that mean an operator removed the PoP on purpose. */
    private const OPERATOR_REASONS = ['edge_unavailable', 'withdrawn', 'pool_disabled'];

    /** A new revision has not been acknowledged yet. That is a change in progress, not a PoP that cannot serve. */
    private const CONVERGING_REASONS = ['gateway_not_ready', 'gateway_not_acknowledged'];

    public static function execute(): void
    {
        EdgePool::query()->where('enabled', true)->where('withdrawn', false)->orderBy('id')->get()->each(function (EdgePool $pool): void {
            $endpoints = EdgePoolEndpoint::query()->with(['edge', 'pool'])->where('edge_pool_id', $pool->id)->orderBy('id')->get();
            $evaluated = $endpoints->map(fn (EdgePoolEndpoint $endpoint): array => [$endpoint, $endpoint->withdrawn ? 'withdrawn' : $endpoint->publicationReason()]);
            $anyHealthy = $evaluated->contains(fn (array $pair): bool => $pair[1] === 'ready');
            $failStatic = false;
            foreach ($evaluated as [$endpoint, $reason]) {
                // The gateway still serves its previous valid map while it takes a new revision. An endpoint that is
                // published stays published through that window; one that never was waits for its acknowledgement.
                $converging = in_array($reason, self::CONVERGING_REASONS, true)
                    && ($endpoint->edge->capacity['gateway']['ready'] ?? false) === true;
                $published = match (true) {
                    $reason === 'ready' => true,
                    in_array($reason, self::OPERATOR_REASONS, true) => false,
                    $converging => $endpoint->dns_published,
                    $anyHealthy => false,
                    // Every automatic signal says unhealthy: keep what was published.
                    default => $endpoint->dns_published,
                };
                if (! $anyHealthy && ! in_array($reason, self::OPERATOR_REASONS, true) && $endpoint->dns_published) {
                    $failStatic = true;
                }
                if ($endpoint->dns_published !== $published || $endpoint->dns_reason !== $reason) {
                    if ($endpoint->dns_published !== $published) {
                        AuditLog::record(null, $published ? 'edge.endpoint_published' : 'edge.endpoint_withdrawn', $endpoint, ['reason' => $reason]);
                    }
                    $endpoint->forceFill([
                        'dns_published' => $published, 'dns_reason' => $reason,
                        'dns_state_changed_at' => $endpoint->dns_published !== $published ? now() : $endpoint->dns_state_changed_at,
                    ])->save();
                }
            }
            if ($pool->dns_fail_static !== $failStatic) {
                $pool->forceFill(['dns_fail_static' => $failStatic])->save();
            }
        });
    }
}
