# Cache settings and purge semantics

Phase 5 cache control-plane state is stored per domain and delivered in the normal signed, revisioned edge artifact. This document describes the implemented control-plane slice; OpenResty response caching and edge-agent purge execution are not yet production-qualified.

## Settings

`GET /api/domains/{domain}/cache` returns the typed settings, current cache epoch, and any active development-mode expiry. `PATCH /api/domains/{domain}/cache` replaces the complete settings object and queues normal edge reconciliation.

The bounded settings are cache enabled, edge TTL, browser TTL, maximum object bytes, respect-origin-headers, query-string participation, up to 32 bypass cookie names, and stale-if-error seconds. Development mode requires an explicit duration from 1 minute through 24 hours. Its absolute expiry is placed in the edge artifact; an expired value is reported as inactive even if cleanup has not yet run.

## Purges

`POST /api/domains/{domain}/cache/purge` accepts either `{"type":"all"}` or at most 100 absolute URLs in a payload no larger than 128 KiB.

A full purge increments the domain's monotonic cache epoch under a row lock. It never scans a cache directory. A URL purge preserves the URL query bytes and ordering when query strings participate in the cache key. URLs must use HTTP or HTTPS, the selected domain, a default port, no credentials, and no fragment. The canonical control-plane key is:

```text
scheme|lowercase-host|path[?original-query]
```

Every enabled, non-revoked edge receives one unique durable task for a purge. `GET /api/domains/{domain}/cache/purges/{purge}` exposes its per-edge task state. Repeating a request with the same `Idempotency-Key` replays the original response; reusing that key with different input is a conflict.

## Current qualification boundary

The desired-state API, bounds, authorization, epoch update, URL normalization, signed-artifact inclusion, durable per-edge task creation, and task acknowledgement roll-up are implemented. The edge agent and OpenResty runtime do not yet execute these purge tasks or provide HIT/MISS/BYPASS/EXPIRED/STALE behavior. Do not treat a queued purge as an effective runtime purge until that later Phase 5 slice is implemented and real-traffic qualified.
