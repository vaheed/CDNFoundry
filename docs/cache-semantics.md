# Cache settings and purge semantics

Phase 5 cache control-plane state is stored per domain and delivered in the normal signed, revisioned edge artifact. The edge agent fans durable purge tasks out to every configured cell through its authenticated bounded control endpoint. OpenResty response caching itself is not yet implemented or production-qualified.

## Settings

`GET /api/domains/{domain}/cache` returns the typed settings, current cache epoch, and any active development-mode expiry. `PATCH /api/domains/{domain}/cache` replaces the complete settings object and queues normal edge reconciliation.

The bounded settings are cache enabled, edge TTL, browser TTL, maximum object bytes, respect-origin-headers, query-string participation, up to 32 bypass cookie names, and stale-if-error seconds. Development mode requires an explicit duration from 1 minute through 24 hours. Its absolute expiry is placed in the edge artifact; an expired value is reported as inactive even if cleanup has not yet run.

## Purges

`POST /api/domains/{domain}/cache/purge` accepts either `{"type":"all"}` or at most 100 absolute URLs in a payload no larger than 128 KiB.

A full purge increments the domain's monotonic cache epoch under a row lock. It never scans a cache directory. A URL purge preserves the URL query bytes and ordering when query strings participate in the cache key. URLs must use HTTP or HTTPS, the selected domain, a default port, no credentials, and no fragment. The canonical control-plane key is:

```text
scheme|lowercase-host|path[?original-query]
```

Every enabled, non-revoked edge receives one unique durable task for a purge. Its agent applies the command to every configured cell. Full purges advance a monotonic per-domain epoch override; URL purges increment a generation addressed by the MD5 digest of the canonical key, avoiding unsafe or oversized shared-dictionary keys. Replaying the same task ID is a no-op. Failed delivery retries the same task up to five total attempts with bounded exponential delay; it never creates a second user-visible purge. `GET /api/domains/{domain}/cache/purges/{purge}` exposes its per-edge task state. Repeating an API request with the same `Idempotency-Key` replays the original response; reusing that key with different input is a conflict.

## Current qualification boundary

The desired-state API, bounds, authorization, epoch update, URL normalization, signed-artifact inclusion, durable per-edge task creation, authenticated all-cell execution, replay safety, and task acknowledgement roll-up are implemented. OpenResty does not yet store customer responses or provide HIT/MISS/BYPASS/EXPIRED/STALE behavior. Purge generations are ready for that cache-key implementation, but no customer object can be considered cached until the later runtime slice is implemented and real-traffic qualified.
