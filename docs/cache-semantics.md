# Cache settings and purge semantics

Phase 5 cache state is stored per domain and delivered through the normal signed, revisioned edge artifact. Each bounded OpenResty cell owns a local cache; no domain receives its own directory, process, timer, or server block.

## Settings and admission

`GET /api/domains/{domain}/cache` returns the typed policy, monotonic full-purge epoch, and active development-mode expiry. `PATCH /api/domains/{domain}/cache` replaces the complete policy and asynchronously reconciles the next domain revision.

The settings are cache enabled, edge TTL, browser TTL, a 1 MiB/10 MiB/100 MiB maximum-object tier, respect-origin-headers, query-string participation, up to 32 bypass cookie names, and a stale-if-error duration of at most 24 hours. Development mode requires an explicit duration from 1 minute through 24 hours. Its absolute expiry is evaluated at request time, so an expired value stops bypassing even before database cleanup.

Only `GET` and `HEAD` are eligible. Authorization, Range, a configured cookie, active development mode, disabled/zero-TTL policy, and cache keys over 8 KiB bypass. Query bytes and ordering are preserved when enabled. Accept-Encoding is normalized to either `gzip` or absent, which bounds the admitted variant set. Only `Vary: Accept-Encoding` is accepted; `Vary: *`, any other Vary name, or an oversized Vary value bypasses.

Only successful `200` responses without `Set-Cookie`, `private`, `no-store`, or `no-cache` are admitted. Redirects, errors, and negative responses bypass. When origin headers are respected, bounded `s-maxage`, `max-age`, or `Expires` controls edge freshness; otherwise the configured edge TTL does. The configured browser TTL is emitted separately. Object admission uses NGINX buffering and one of three bounded temporary-file tiers rather than buffering the response in Lua memory. The cell cache is capped at 192 MiB and entries inactive for one hour are removed.

The deterministic key includes the domain, cache policy hash, monotonic epoch, exact-URL purge generation, canonical host, scheme policy, normalized path, and the original query bytes when enabled. Time is not part of the key: NGINX freshness produces real `EXPIRED` behavior.

`X-CDNFoundry-Cache` and the structured access log expose stable `MISS`, `HIT`, `BYPASS`, `EXPIRED`, and `STALE` states. Stale content is eligible only for the configured response-specific grace period. A zero grace or an elapsed grace returns the controlled origin error; no global NGINX stale directive can override the per-domain bound.

## Purges

`POST /api/domains/{domain}/cache/purge` accepts either `{"type":"all"}` or at most 100 absolute URLs in a payload no larger than 128 KiB.

A full purge increments the domain's monotonic cache epoch under a row lock; it never scans cache files. A URL purge preserves query bytes and ordering when query strings participate. URLs must use HTTP or HTTPS, the selected domain, a default port, no credentials, and no fragment. The canonical control-plane key is:

```text
scheme|lowercase-host|path[?original-query]
```

Every enabled, non-revoked edge receives one durable task. Its agent applies the authenticated command to each configured cell. Full purges advance a monotonic per-domain epoch override; URL purges increment a generation addressed by the MD5 digest of the canonical key, avoiding unsafe or oversized shared-dictionary keys. Replaying a task ID is a no-op. Failed delivery retries the same task up to five total attempts with bounded exponential delay and never creates a second user-visible purge. `GET /api/domains/{domain}/cache/purges/{purge}` exposes bounded per-edge state. Repeating an API request with the same `Idempotency-Key` replays the original response; reusing it with different input returns conflict.

## Failure behavior

Invalid candidates never replace the active edge revision. Cache-setting rollback follows the normal domain revision rollback path. An unavailable origin may use an already stored response only inside its configured stale grace; otherwise the edge returns a controlled error. Cache exhaustion is cell-local and cannot terminate the agent or sibling cells. Purge delivery failures remain visible and retry without blocking traffic or scanning storage.
