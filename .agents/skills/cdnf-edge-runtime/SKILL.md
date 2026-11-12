---
name: cdnf-edge-runtime
description: Work on the CDNFoundry data plane: edge snapshots and artifacts, the Go edge agent and gateway, OpenResty cells, cache keys, purge, origins, and security limits. Use for any change that affects how edges receive or serve traffic.
---

# cdnf-edge-runtime

## Purpose
Deliver signed, bounded, per-domain runtime state to cells and serve traffic safely when control is unavailable.

## When to use
Changes to `ReconcileEdgeDomain`, the `/edge/v1` API, `edge-agent`, `edge-gateway`, `docker/openresty/runtime.lua`, the nginx edge configs, cells, pools, or placement.

## Required inputs
The snapshot fields, the affected cells and pools, bounds (bytes, domains, connections), failure behavior, and IPv4/IPv6 coverage.

## Files normally touched
`core/app/Jobs/ReconcileEdgeDomain.php`, `core/app/Actions/PlanDomainEdgeCells.php`, `PromoteReadyEdgePlacements.php`, `core/app/Http/Controllers/EdgeAgentController.php`, `core/app/Http/Middleware/AuthenticateEdge.php`, `edge-agent/main.go`, `edge-agent/generation.go`, `edge-gateway/main.go`, `docker/openresty/runtime.lua`, `docker/nginx/edge-runtime.conf`, `docker/nginx/proxy-cache.conf`, `core/app/Support/CacheKey.php`, `OriginData.php`.

## Procedure
1. Snapshots are deterministic and minimal. Never put secrets in snapshots or artifacts. Artifacts carry a certificate id; the agent fetches the key over mTLS from the key endpoint, encrypted to that edge's identity key.
2. The agent must isolate bad input per domain. A rejected artifact is acknowledged as rejected and must not stop the cursor. Keep the agent's rejection reasons identical to `EdgeAgentController::rejected()` validation.
3. Activation is atomic (generation swap in `generation.go`). Invalid bundles never replace active ones.
4. The cache key and the purge key must come from one definition. PHP `CacheKey::fromUrl` and Lua `request_cache_key` share the vectors in `CacheKeyVectorsTest` and `tests/contract/test_cache_key_vectors.py`; change both and the vectors together. Purge state must survive cell restarts.
5. Origins: resolve, then check every address against the hard and private blocklists in `runtime.lua` before connecting. Bound retries and timeouts.
6. Placement defaults to `shared` pools only. Quarantine and dedicated pools receive a domain only by explicit assignment.
7. Lua timers reload a cell file only when its stamp changes. The agent requires `EDGE_RUNTIME_DIR` and has one activation path: a compiled generation under that directory, activated by an atomic pointer swap. A runtime file always carries a `generation_id`.

## Validation commands
- Backend: `make dev-test`, or one class with `docker compose -f compose.dev.yml -f compose.test.yml run --rm --no-deps core php artisan test --compact --filter <Class>` (the test override forces in-memory SQLite; never run tests against PostgreSQL).
- Style: `docker compose -f compose.dev.yml -f compose.test.yml run --rm --no-deps core vendor/bin/pint --test`.
- Go: `cd edge-agent && gofmt -l . && go vet ./... && go test ./...`, and the same in `edge-gateway` (or run them in `golang:1.26.11` with Docker).
- Feature: `EdgeProxyTest`, `MultiCellPlacementTest`, `CacheApiTest`, `OriginDestinationSafetyTest`.
- Runtime: `python3 tests/e2e/openresty_runtime.py`, `edge_purge_protocol.py`, `origin_destinations.py`, `gateway_ingress.py`.

## Definition of done
Cells serve their last valid state with control down. One bad domain affects only itself. Every limit is enforced in the cell or gateway, never in Laravel.

## Stop conditions
Stop if the change needs per-domain nginx server blocks, reloads, containers, or cache directories, or puts Laravel in the request path.

## Forbidden shortcuts
No unbounded maps or files, no in-memory-only durable state, no test that mocks the cell instead of running it.

## Expected completion summary
Snapshot changes, agent, gateway, and Lua changes, bounds, failure-injection evidence, and IPv4/IPv6 results.
