# Phase 6 qualification

Phase 6 implementation and agent-owned qualification were completed on
2026-07-20. Owner-run browser qualification remains not executed, so Phase 6 is
not owner release-qualified.

## Implemented boundary

The control plane stores typed security settings, deterministic rules,
operational state/events, emergency modes, and service-pool withdrawal in
PostgreSQL. Revisions deploy through existing signed cell artifacts and retain
last-valid runtime/placement. OpenResty enforces local GeoIP/IP/CIDR decisions,
request/connection/TLS/origin/cache bounds, circuit state, quarantine, and
emergency controls without a request-time control-plane dependency.

The configured profile surface provides three immutable recommended presets
(`standard`, `protected`, and `quarantine`) plus one editable `manual` profile.
Preset selection reactively updates the displayed values and successful saves
refresh the page state. Manual values are checked against field-wise platform
safety ceilings, while restricted/quarantined operational state can compile a
stricter effective profile without rewriting configured state.

The edge agent reports bounded top-20 aggregates and persists emergency state
across restart. The readiness scheduler expires emergency controls and advances
quiet recovery. No CAPTCHA, challenge, editable WAF language, ModSecurity/CRS,
or volumetric-scrubbing claim was added.

## Automated and runtime evidence

- Focused `SecurityApiTest` and `FilamentWorkflowTest`: **14 tests, 169
  assertions passed**. Coverage includes profile-selector reactivity, immediate
  post-save display/persistence, three immutable presets, the single bounded
  manual profile, operational-state overrides, policy, revisions,
  IPv4/IPv6/CIDR/GeoIP validation, ordering, bounded one-revision import and
  rollback, target-first state operations, idempotent/expiring emergency tasks,
  and pool withdrawal.
- Containerized edge-agent build: Go tests passed, including authenticated
  bounded targeting and persisted emergency restoration.
- `tests/e2e/phase6_security.py`: passed against the real Laravel API,
  persistent PostgreSQL, and real OpenResty. Control-plane coverage includes
  authorization, all profile choices, preset immutability, every manual field's
  upper/lower bounds, missing/extra payload fields, persistence, operation IDs,
  and idempotent replay/conflict. Runtime coverage includes local MMDB,
  IPv4/IPv6 rules, trusted client IP/GeoIP, rate/connection/body/method limits,
  origin capacity/circuit behavior, cell isolation, emergency replay/expiry,
  top-N events, bounded memory, and invalid-candidate last-valid preservation.

## Cumulative release-candidate evidence

- `make dev-test`: **131 tests, 1,052 assertions passed** under the required
  `APP_ENV=testing`, SQLite `:memory:`, array-cache, synchronous-queue boundary.
- The preceding Phase 6 baseline recorded a passing `make dev-e2e` run across
  PostgreSQL/Valkey outage recovery, PowerDNS/DNSdist IPv4+IPv6, Geo-DNS,
  two-edge signed artifact acknowledgement and target-first movement, mTLS,
  Pebble DNS-01, cache/purge retry/rollback, and edge/cache/TLS runtime
  regressions. This profile refinement reran the changed
  `tests/e2e/phase6_security.py`; the entire cumulative `make dev-e2e` sequence
  was not rerun.
- `tests/e2e/phase4_runtime.py` additionally caught and qualified HTTP/2-safe
  header accounting and bounded stale-if-error behavior for ordinary origin
  loss; security-controlled failures remain explicit.
- Laravel Pint passed its full check. Development and production Compose
  configs, OpenAPI freshness, all 44 documentation files' Markdown links, and
  `git diff --check` passed. The Phase 6 runtime E2E validated the generated
  OpenResty configuration with `openresty -t` before traffic tests.

The additive `2026_07_20_000100_create_security_readiness_state` migration is
applied to the preserved development PostgreSQL volume. It uses JSONB shape
constraints and adds no destructive rewrite or refresh. No named volume was
removed. This profile refinement adds no migration or operator data operation;
existing `standard`, `protected`, and `quarantine` settings remain valid.

## Manual browser status

Not executed. Browser automation was not launched. The owner checklist now
contains exact preset/reactivity/read-only/manual/save/error/cancel checks and
expected revision behavior. The owner must perform every Phase 6 step in
`manual-browser-qualification.md` and record operator/date, commit,
browser/viewports, domain/edge addresses, operation IDs, revisions, events,
runtime responses, screenshots, and any failure.
