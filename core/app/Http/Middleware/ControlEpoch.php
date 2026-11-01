<?php

namespace App\Http\Middleware;

use App\Support\ControlPlane;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Every edge-control response says which control epoch produced it, so an edge can ignore a node
 * that was replaced. A node that is not the writable active node answers 503 `standby`, which makes
 * the agent try its next control address.
 */
class ControlEpoch
{
    public function handle(Request $request, Closure $next): Response
    {
        $reason = ControlPlane::fencedReason();
        if ($reason !== null) {
            return response()->json(['message' => 'This control node is not the active node.', 'code' => 'control_standby', 'details' => (object) []], 503)
                ->withHeaders([ControlPlane::STATE_HEADER => 'standby', 'Retry-After' => '5']);
        }
        $response = $next($request);
        $response->headers->set(ControlPlane::STATE_HEADER, 'active');
        $response->headers->set(ControlPlane::EPOCH_HEADER, (string) ControlPlane::epoch());

        return $response;
    }
}
