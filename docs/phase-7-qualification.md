# Phase 7 qualification record

Automated qualification was run on 2026-07-20 against the persistent local
Compose development stack without deleting volumes.

## Passed evidence

- Isolated SQLite feature suite: Phase 7 analytics API/UI tests cover policies,
  bounds, masking, explicit outage behavior, usage idempotency/exports, and both
  Filament scopes. Session-authenticated browser CSV routes additionally cover
  guests, cross-domain access, non-administrators, owners, and administrators;
  rendered-page checks reject the former token-protected API links.
- Full isolated suite after the telemetry presentation fix: **140 tests, 1,124
  assertions passed**. Production assets, Compose configuration, OpenAPI, and
  documentation-link checks also passed.
- Real runtime: `tests/e2e/phase7_analytics.py` passed direct Vector edge/DNS
  ingestion, DNSTap-backed DNS collection, all domain/admin query surfaces,
  secret/query removal, IPv4 `/24` and IPv6 `/48` masking, stable JSON/CSV usage,
  idempotent rebuild replay, and a 20,000-event aggregation check.
- Failure rehearsal: ClickHouse was stopped and restored. DNS and edge endpoints
  continued responding, the analytics API returned `analytics_unavailable`,
  Vector buffered the unique outage event, and the backlog drained afterward.
- Persistent PostgreSQL received only the Phase 7 production migration. No
  destructive refresh or named-volume removal was performed.
- The refreshed local control plane returned HTTP 200 from health, redirected
  the unauthenticated browser CSV route into session authentication, and kept
  the separate API route at HTTP 401 without a token. Existing edge-agent state
  volumes were repaired in place after an older root owner prevented the
  current non-root agents from writing; no files or volumes were removed, and
  both agents remained running afterward.

## Manual status

Browser automation was not run, as required. The exact owner-run Phase 7 job is
in `docs/manual-browser-qualification.md`. Phase 7 implementation and agent-owned
qualification are complete; release qualification remains pending until those
rendered desktop/mobile checkpoints are recorded as passed.
