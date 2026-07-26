# Phase 5 qualification

Phase 5 implementation and agent-owned qualification completed on 2026-07-19. Owner-run browser/public-HTTPS qualification remains not executed and is the only open release gate.

## Automated control-plane qualification

- `make dev-test`: **118 tests, 925 assertions passed** using the required `APP_ENV=testing`, SQLite `:memory:`, array cache, and synchronous queue isolation. Coverage includes authorization, typed bounds, idempotency, cache rollback, purge retries/backlog, custom TLS validation and secret-safe responses, DNS-only/unverified managed-TLS exclusion, valid-certificate reuse, deep-host supplemental orders, portable latest-order selection, DNS acknowledgement before ACME validation, encrypted keys, failed-order cleanup, last-valid-certificate preservation, and deduplicated administrator alerts.
- Focused `ManagedTlsTest`: **5 tests, 29 assertions passed** after the final retry-lifetime and failure-preservation changes.
- `make openapi-check`: the committed route contract is current.
- Laravel Pint check: **233 files passed**.
- Containerized edge-agent build ran its complete Go test suite and passed. It verifies multi-certificate cell compilation, supplemental per-host selection, atomic activation/rollback, and mode-`0600` runtime snapshots containing TLS keys.
- Go formatting check passed after applying the reported formatting change.

## Real managed-TLS qualification

`python3 tests/e2e/phase5_tls.py` passed against the persistent development stack with local Pebble, DNSdist, and PowerDNS. The final cumulative run created a disposable active, nameserver-verified domain and issued its apex/wildcard certificate through real DNS-01, then removed its runtime and control-plane test state.

The test proves the order waits for the derived challenge revision, public DNSdist exposes the TXT record, Pebble validates it, the managed certificate activates, the raw PostgreSQL key is ciphertext, the API omits keys/tokens/CSR, no fake user DNS row exists, and challenge cleanup removes the runtime TXT through a later DNS revision.

## Real edge/cache/purge/TLS qualification

`python3 tests/e2e/phase4_control_plane.py` now carries the Phase 5 control-plane boundary through two real mTLS identities. It changes persisted cache policy, receives and acknowledges the resulting signed artifact on both edges, rolls back to the prior validated revision, replays one full purge with the same idempotency key, fails one edge delivery, proves the same durable task retries with a bounded delay, completes its second attempt, and verifies exact URL cache keys in both edge task payloads.

`python3 tests/e2e/phase4_runtime.py` passed using freshly built hardened edge-agent and OpenResty images. It covers:

- dynamic certificate selection by SNI, HTTP/2, verified HTTPS origin behavior, unknown/disabled SNI rejection, and last-valid state across worker restart;
- fixed shared unprivileged runtime UID plus agent-generated mode-`0600` TLS snapshots;
- `MISS`, `HIT`, `BYPASS`, `EXPIRED`, and `STALE` response/log states;
- GET/HEAD admission; Authorization, cookie, Range, POST, development-mode, Set-Cookie, private/no-store, Vary-star/unallowed-Vary, redirect, negative-response bypass;
- bounded normalized Accept-Encoding variants, exact query separation, browser TTL rewriting, origin-header respect and configured override;
- 1 MiB object-tier rejection without unsafe Lua buffering;
- stale serving inside a 3-second grace, refusal after expiry, and refusal for zero grace;
- authenticated replay-safe full and exact-URL purge with post-purge misses;
- passive origin-failure accounting, IPv4/IPv6 listeners, placement isolation, drain/undrain/restart, and invalid/blocked origin behavior.

## Platform and migration qualification

- Development and production Compose configurations passed `make config-check`.
- The cumulative `make dev-e2e` target includes both real Phase 5 control-plane/cache qualification and `phase5_tls.py`; CI cannot publish images after testing only Phases 1–4.
- `make docs-check` validates repository-local Markdown links, and CI checks production dependency advisories, Python syntax, and Go formatting/vetting in addition to the existing build/runtime gates.
- Persistent development PostgreSQL was preserved. The TLS state/workflow/notification migrations and the additive `010300` notification-data `jsonb` conversion are applied, along with the cache purge migration; no volume removal, refresh, or destructive migration ran.
- The Pebble development service is pinned by immutable image digest. Production uses explicit ACME directory/email/budget configuration and does not include Pebble.
- `git diff --check` passed after the final documentation edit.

## Documentation

- Managed lifecycle: `managed-tls-lifecycle.md`
- ACME failures: `acme-failure-guide.md`
- Custom certificates: `custom-certificates.md`
- Cache semantics: `cache-semantics.md`
- Development mode: `cache-development-mode.md`
- Purge failures: `cache-purge-troubleshooting.md`
- Exact cumulative owner checklist: Phase 5 in `manual-browser-qualification.md`

## Manual browser status

Not executed. Browser automation was not launched. The owner must run every Phase 5 checkpoint, including public delegated HTTPS on reachable edges, and record date/operator, commit, browser/viewports, operation IDs, revisions, fingerprints, runtime responses, logs, screenshots, and failures. Until that occurs, Phase 5 is implementation-complete and agent-qualified but **not owner release-qualified**.
