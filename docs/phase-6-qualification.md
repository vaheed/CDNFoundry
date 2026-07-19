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

The edge agent reports bounded top-20 aggregates and persists emergency state
across restart. The readiness scheduler expires emergency controls and advances
quiet recovery. No CAPTCHA, challenge, editable WAF language, ModSecurity/CRS,
or volumetric-scrubbing claim was added.

## Automated and runtime evidence

- Isolated `SecurityApiTest`: **6 tests, 60 assertions passed** for policy,
  profile ceilings, revisions, IPv4/IPv6/CIDR/GeoIP validation, ordering,
  bounded one-revision import and rollback, target-first state operations,
  idempotent/expiring emergency tasks, and pool withdrawal.
- Containerized edge-agent build: Go tests passed, including authenticated
  bounded targeting and persisted emergency restoration.
- `tests/e2e/phase6_security.py`: passed with real OpenResty, local MMDB, IPv4
  and IPv6 rules, trusted client IP/GeoIP, rate and connection limits, body and
  method rejection, origin capacity/circuit behavior, cell isolation,
  emergency replay/expiry, top-N events, bounded memory, and invalid-candidate
  last-valid preservation.

## Cumulative release-candidate evidence

- `make dev-test`: **128 tests, 1,017 assertions passed** under the required
  `APP_ENV=testing`, SQLite `:memory:`, array-cache, synchronous-queue boundary.
- `make dev-e2e`: passed every non-browser test from Phases 1–6. This includes
  real PostgreSQL/Valkey outage recovery, PowerDNS/DNSdist IPv4+IPv6, Geo-DNS,
  two-edge signed artifact acknowledgement and target-first movement, mTLS,
  Pebble DNS-01, cache/purge retry and rollback, Phase 6 security, and the full
  prior edge/cache/TLS runtime regression.
- `tests/e2e/phase4_runtime.py` additionally caught and qualified HTTP/2-safe
  header accounting and bounded stale-if-error behavior for ordinary origin
  loss; security-controlled failures remain explicit.
- Laravel Pint formatted **249 files** and then passed its final check. The
  containerized edge-agent build ran all Go tests. Development and production
  Compose configs, OpenAPI freshness, Markdown links, and `git diff --check`
  passed.

The additive `2026_07_20_000100_create_security_readiness_state` migration is
applied to the preserved development PostgreSQL volume. It uses JSONB shape
constraints and adds no destructive rewrite or refresh. No named volume was
removed.

## Manual browser status

Not executed. Browser automation was not launched. The owner must perform every
Phase 6 step in `manual-browser-qualification.md` and record operator/date,
commit, browser/viewports, domain/edge addresses, operation IDs, revisions,
events, runtime responses, screenshots, and any failure.
