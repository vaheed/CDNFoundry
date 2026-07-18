# Phase 1 qualification

Phase 1 implementation and non-browser qualification was re-audited on 2026-07-18. Browser E2E remains a manual, user-owned release check under the repository contract.

## Evidence

- `make dev-test`: the cumulative Phase 1–4 suite passed 93 tests with 712 assertions inside the development Compose service. The command fails closed unless the effective database is isolated in-memory SQLite.
- `make dev-e2e`: the cumulative real-stack target passed, including `phase1_backend_e2e=passed`; the Phase 1 job exercised the real API, authorization, idempotency, token revocation, queues, auditing, PostgreSQL-backed platform settings, and asynchronous system-identity application.
- `vendor/bin/pint --test`: passed.
- `npm ci --no-audit --no-fund && npm run build`: production assets built successfully without a build-time network font dependency.
- `make openapi-check`: the committed OpenAPI document matches the generated route contract.
- Development and production Compose configuration validation: passed.
- Self-contained production `core`, `web`, `edge-control`, `edge-runtime`, `edge-agent`, and `mmdb-updater` image builds and packaged-artifact checks: passed. Each application image is linked to the GitHub repository and production Compose requires one commit-SHA GHCR release tag.
- `make dev-migrate`: completed explicitly with no pending migrations.
- Development stack inspection: core, web, PostgreSQL, Valkey, ClickHouse, MMDB updater, and other health-checked dependencies were healthy; Horizon and Scheduler were running independently.
- Independent web, core, Horizon, and Scheduler restarts preserved a committed qualification user. The API recovered after each restart.
- The real HTTPS test origin completed a TLS request and returned HTTP 200.
- IPv4 and IPv6 glue validation is covered together by the system identity feature test.
- Root implementation-contract and all ten project-skill structures and dry-run records are enforced by `RepositoryContractTest`.

## Manual release job

The real-browser item is deliberately not executed by coding agents. Follow [manual-browser-qualification.md](manual-browser-qualification.md) and record the commit, date, browser version, and output before accepting a release.

No production migration or external operation was performed during this qualification. The persistent development PostgreSQL migrations were applied without a refresh and then reported `Nothing to migrate`. Local qualification accounts and operations may exist in the development database.
