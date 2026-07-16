# cdnf-edge-runtime

## Purpose
Implement generic, bounded edge-agent and OpenResty behavior.
## When to use
For agents, cells, placement, proxy, cache, security, or DDoS readiness.
## Required inputs
Artifact versions, placement, resource ceilings, origin/client contract, activation/rollback, and traffic tests.
## Files normally touched
Agent/OpenResty/Lua, compiler, cell/Compose config, tests and runbooks.
## Procedure
Keep one generic data runtime; assign stable cells; validate origins/requests; bound state; produce verified incremental/full bundles; activate atomically; retain prior state/offline service; test quarantine, crashes, HTTP/S, IPv4/IPv6, and noisy neighbors.
## Validation commands
Tests, config validation, real HTTP/HTTPS, restart/outage/load/isolation tests.
## Definition of done
Normal changes need no reload, failures stay isolated, and serving survives control-plane outage.
## Stop conditions
Stop if limits, compatibility, readiness, or last-valid behavior is undefined.
## Forbidden shortcuts
No per-domain server blocks/processes/reloads/directories, unbounded Lua keys, or runtime Laravel calls.
## Expected completion summary
Artifact/placement/runtime changes, limits, and real failure evidence.
