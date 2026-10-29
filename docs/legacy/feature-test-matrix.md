---
title: "Feature test matrix"
description: Per-feature test evidence for the current release, with the command, the test file and the status of each row.
---

# Feature test matrix

Date: 2026-10-29. Source release: `41177d7` (CI run 40848204167 passed every job).
Status values: **passed**, **failed**, **not run**. A row is **not run** when the
environment could not exercise it; the reason is stated.

## Local suites (developer host, `make` targets)

| Area | Command | Test files | Result |
| --- | --- | --- | --- |
| Core Laravel suite (367 tests, 12,785 assertions, after this pass) | `make dev-test` | `core/tests/Feature/*`, `core/tests/Unit/*` | passed |
| End-to-end phases 1-9, origin failover, phase4 runtime | `make dev-e2e` | `tests/e2e/e2e.py`, `phase2_dns.py`, `phase3_geo_dns.py`, `phase4_control_plane.py`, `phase4_mtls.py`, `phase5_tls.py`, `phase6_security.py`, `phase7_analytics.py`, `phase8_operations.py`, `phase9_waf.py`, `origin_failover.py`, `phase4_runtime.py` | passed (exit 0; see note) |
| Fleet tests (86 after this pass) | `pytest -q tests/fleet` | `tests/fleet/test_fleet.py`, `tests/fleet/test_issue_edge_listener_material.py` | passed (86) |
| Observability contracts | `python3 -m unittest discover -s tests/observability` | `tests/observability/*` | passed (20) |
| Supply chain | `python3 -m unittest discover -s tests/supply_chain` | `tests/supply_chain/*` | passed (8) |
| Qualification tools | `python3 -m unittest discover -s tests/qualification` | `tests/qualification/*` | passed (16) |
| Supply-chain policy | `make supply-chain-check` | `scripts/supply-chain-policy.py` | passed |
| Compose and generated env | `make config-check` | compose files, `tests/config/generate-production-env.sh` | passed |
| OpenAPI contract | `make openapi-check` | `core` generated OpenAPI and endpoint catalog | passed |
| Documentation links and commands | `python3 tests/docs/check_links.py` | `docs/**` | passed (103 documents) |

Note: `make dev-e2e` prints an explicit `*_passed` line for phases 1, 2, 3, 4 (control
plane and simple anycast), 8 and origin failover. Phases 4 mTLS, 5 TLS, 6 security, 7
analytics, 9 WAF and phase4 runtime exit 0 without printing a pass marker; the make
target stops at the first failing command, so exit 0 means they passed.

`tests/docs` contains `check_links.py` and `check_built_site.py` and no unit tests;
they were not run in this pass.

## Fixes verified in this pass (2026-10-29)

| Problem | Verification |
| --- | --- |
| P1 dry run wording and output | `test_setup_dry_run_uses_in_memory_state`, `test_dry_run_does_not_create_state_or_secrets` passed |
| P4 edge listener material | `tests/fleet/test_issue_edge_listener_material.py` passed (2): chain, SAN, modes, idempotent rerun |
| P5 healthcheck-disabled services under `--wait` | reproduced with busybox (exit 1 before); `test_control_start_waits_only_on_services_with_active_healthchecks` passed |
| P8 one error envelope | `AccessApiTest` updated; core suite 367 passed; idempotency re-verified on stage: 422/201/409 with flat `code` |
| P11 https pagination links | `ProxiedRequestSchemeTest` passed; stage control still runs the old image until redeployed |
| P6 Docker Hub 403 | `daemon.json` mirror on workstation, control, pop-1, pop-2; `docker pull` of digest through mirror verified on pop-1 |

### Verification status after this pass (not green)

- `make dev-e2e` with the changes: phases 1 to 9 and origin failover passed in the
  run that started this pass. **Phase4 runtime fails on this workstation.** It fails
  the same way on the untouched `41177d7` tree, so it is not caused by the changes.
- Cause: the edge cell containers (512 MiB limit) are OOM-killed during the stale and
  stress segment. The kernel logged 63 `openresty` OOM kills in edge cell cgroups.
  Host memory was nearly exhausted (16 GB total, about 0.5 GB free) with IDE language
  servers and ClickHouse resident.
- Fixes in the test itself: `wait_for` budget widened to 30 s; origin outage wrapped in
  `try/finally` so a failed assertion cannot leave `origin-http` stopped (this left the
  origin down after one earlier failure).
- Decision needed before a dev push: edge cell memory sizing for the stress path, or
  test-side load reduction. Until phase4 passes, the changes are committed locally only.

P9 was attempted (disable unconfigured seeded pools in a new migration) and reverted:
38 core tests failed because placement needs an enabled default pool. It is an open
design question; see the gap report.

## Stage (rebuilt from the starter guide, 2026-10-29)

Hosts: control `203.0.113.10`, pop-1 `203.0.113.11`, pop-2 `203.0.113.12`.

| Feature | Evidence | Result |
| --- | --- | --- |
| Control health, readiness, admin login (HTTPS, Let's Encrypt) | `curl --resolve control.cdnf.example.net:443:203.0.113.10` on `/api/health`, `/api/ready`, `/admin/login` | passed (200, verified TLS) |
| Grafana health (HTTPS) | `curl` on `grafana.cdnf.example.net/api/health` | passed (200) |
| Admin creation (CLI) | `php artisan cdnf:admin:create` over stdin | passed |
| Admin API login, bearer token | `POST /api/auth/login` | passed |
| Domain user cannot reach admin resources | login as `user`; `GET /admin/edges`, `/admin/users`, `/admin/dns/clusters`, `/admin/edge-pools` | passed (403 on all four) |
| Domain user sees only assigned domains | `GET /domains` as user | passed (empty list, no domains assigned) |
| Admin user creation and duplicate email | `POST /admin/users`, then repeat | passed (201, then 422) |
| Idempotency: non-UUID key rejected | `POST /admin/edge-pools` with `stage-idem-…` | passed (422 `invalid_idempotency_key`, flat envelope as of this pass) |
| Idempotency: replay with same body | same UUID key, same body | passed (201 replayed) |
| Idempotency: same key, different body | same UUID key, changed body | passed (409 `idempotency_conflict`) |
| DNS cluster create, test, enable (pop-1, pop-2) | `POST /admin/dns/clusters`, `…/test`, `…/enable` | passed (operations succeeded; `healthy: true`) |
| Platform DNS identity: validate, save | `POST …/dns/validate`, `PATCH /admin/system/settings/dns` | passed (revision 1) |
| Authoritative SOA on both PoPs, UDP and TCP | `dig @PoP example.net SOA +norecurse` from control host | passed (`aa`, serial 2026102901) |
| Recursion refused for foreign names | `dig @PoP google.com` from control host | passed (REFUSED) |
| Edge enrollment and heartbeat (pop-1, pop-2) | `POST /admin/edges`, one-time token into `.env.prod`, `start.sh` | passed (enrolled, heartbeating, agent 1.2.0, 8/8 cells ready each) |
| Shared service pool: cells, endpoints, enable | `PUT …/cells/{id}`, `POST …/edges/{id}/endpoint`, `POST …/enable` | passed (`routing_status: ready`, both endpoints `ready`) |
| Endpoint acknowledgement timestamp | `gateway_acknowledged_at` on endpoints | passed (set on both endpoints; an earlier check read the wrong field name) |
| Seeded pools disabled when unused | `POST /admin/edge-pools/{1,2}/disable` | passed |
| Customer domain create, verify nameservers, activation | `POST /domains`, `POST /domains/{id}/verify-nameservers` | **not run**: no owned disposable zone (ROADMAP P-03, customer.example.com claim expired) |
| DNS records, DNS-only and proxied | `POST /domains/{d}/dns/records` | **not run**: depends on customer domain |
| Origin validation and origin probe | `POST …/origin/test` | **not run**: depends on customer domain |
| Managed TLS (DNS-01 issuance, uploads) | `…/tls`, `…/tls/status` | **not run**: depends on customer domain |
| Cache, purge (exact URL, full epoch) | `…/cache`, `…/cache/purges` | **not run**: depends on customer domain and live traffic |
| Security rules (block, restore) | `…/security/rules` | **not run**: depends on customer domain and live traffic |
| WAF | managed WAF endpoints | **not run**: depends on customer domain |
| Analytics and usage | `/analytics`, `/usage` | **not run**: no customer traffic yet |
| Backups and restore | `/backups` | **not run**: backups disabled in the fleet topology |
| Operations listing and pagination | `GET /admin/operations`, `GET /admin/edges` cursors | partly run (operations list works); cursor pagination not exercised |

## Customer test zone (2026-10-29, later)

| Feature | Evidence | Result |
| --- | --- | --- |
| Claim creation and assigned nameservers | `POST /domains` for `customer.example.com` | passed (`pending_verification`, assigned NS) |
| Initial SOA/NS deployment to both PoPs | `GET /domains/1/dns/deployment` | passed (revision 1 succeeded on both clusters) |
| Nameserver verification and activation | `POST /domains/1/verify-nameservers` after registrar delegation | passed (`active`) |
| DNS-only A record and deployment | `POST /domains/1/dns/records` (`192.0.2.50`) | passed (revision 3 succeeded on both; UDP and TCP answers; `aa`) |
| Proxied origin validation: platform address rejected | `PATCH …/dns/records/1` with the control address | **failed before the fix** (200; edges reached the control). Fixed in code (P12). Passes at the API after the stage setting (422). |
| Proxied origin reachable from edges | origin test | not run: no public customer origin available; stage hosts are all platform addresses |
| Managed TLS, cache, purge, security, WAF, analytics | — | not run: needs a reachable customer origin and live traffic |

## Gaps found in this pass

See [clean rebuild gap report](clean-rebuild-gap-report.md). Product gaps P1 to P5 and
the documentation gaps are recorded there.

## Coverage not yet tested

- 194 OpenAPI operations: 78 are scoped to `/domains/{domain}`, so they wait on an owned
  customer zone. The remaining operations outside the rows above have no stage run yet.
- Cursor pagination and bulk input limits: not exercised on stage.
