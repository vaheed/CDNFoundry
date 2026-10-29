---
name: cdnf-reconciliation
description: Implement asynchronous, revisioned, idempotent desired-state reconciliation for DNS, edge, TLS, cache, or security changes. Use whenever a change must reach PowerDNS, edges, ACME, or another external target.
---

# cdnf-reconciliation

## Purpose
Move desired state from PostgreSQL to a runtime target safely, coalesced and retryable, keeping the last valid state.

## When to use
Any job under `core/app/Jobs/Reconcile*`, `Issue*`, `Dispatch*`, or any change that adds an external effect.

## Required inputs
The desired-state fields, the revision column, the target set, the obsolete-work rule, the acknowledgement signal, and the retry and backoff bounds.

## Files normally touched
`core/app/Jobs/ReconcileEdgeDomain.php`, `ReconcileDnsZone.php`, `ReconcilePlatformDnsIdentity.php`, `core/app/Actions/PromoteReadyEdgePlacements.php`, `core/app/Models/Operation.php`, `core/routes/console.php`, `core/config/horizon.php`.

## Procedure
1. In one transaction: lock the row, write the desired state, bump `revision`, call `Operation::coalesceDomain(type, domainId, actorId)`, and dispatch the unique job `->afterCommit()`.
2. In the job: reload, return early when `revision` moved (obsolete), render **deterministically** (sort every list, never rely on `pluck()` order — roadmap M2), checksum, validate, then activate.
3. Record per-target status (`DnsDeployment`, `EdgeArtifact` and acks). Mark the operation from those.
4. Retries need backoff (`$backoff`), and a scheduled repair job must exist for the target. DNS currently has none (roadmap H7).
5. Never make the whole target set depend on its slowest member. Promotion that waits for `every` edge blocks a pool on one dead PoP (roadmap H3).
6. Never turn "no signal" into "remove". Missing heartbeats must not withdraw published addresses (roadmap C3).

## Validation commands
- Backend: `make dev-test`, or one class with `docker compose -f compose.dev.yml -f compose.test.yml run --rm --no-deps core php artisan test --compact --filter <Class>` (the test override forces in-memory SQLite; never run tests against PostgreSQL).
- Style: `docker compose -f compose.dev.yml -f compose.test.yml run --rm --no-deps core vendor/bin/pint --test`.
- Add tests for: an obsolete revision is skipped, a concurrent edit coalesces, a target failure keeps the previous state, and a retry succeeds after recovery.
- Runtime: `tests/e2e/phase4_runtime.py` (edge), `tests/e2e/phase2_dns.py` (DNS).

## Definition of done
Each change produces exactly one effective deployment per target. Failures are visible on the operation, and the previous valid state keeps serving.

## Stop conditions
Stop if the design needs a synchronous call from an HTTP request, a per-domain timer, or a global unchunked loop.

## Forbidden shortcuts
No delete-before-replace, no `tries` without backoff, and no lane shared with work that can block for seconds (see `config/horizon.php`, roadmap M5).

## Expected completion summary
State and revision flow, targets, the obsolete rule, retry and repair behavior, and failure evidence.
