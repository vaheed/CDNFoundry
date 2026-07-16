# Phase 1 qualification

Phase 1 implementation and non-browser qualification completed on 2026-07-17. Browser E2E remains a manual, user-owned release check under the repository contract.

## Evidence

- `php artisan test`: 33 tests passed with 224 assertions.
- `make dev-test`: the same 33 tests and 224 assertions passed inside the development Compose service.
- `make dev-e2e`: passed with `phase1_backend_e2e=passed`; Python exercised the real API, authorization, idempotency, token revocation, queues, auditing, and the asynchronous system-identity operation.
- `vendor/bin/pint --test`: passed.
- `npm run build`: production assets built successfully.
- `make openapi-check`: the committed OpenAPI document matches the generated route contract.
- Development and production Compose configuration validation: passed.
- `make dev-migrate`: completed explicitly with no pending migrations.
- Development stack inspection: core, web, PostgreSQL, Valkey, ClickHouse, MMDB updater, and other health-checked dependencies were healthy; Horizon and Scheduler were running independently.
- Independent web, core, Horizon, and Scheduler restarts preserved a committed qualification user. The API recovered after each restart.
- The real HTTPS test origin completed a TLS request and returned HTTP 200.
- IPv4 and IPv6 glue validation is covered together by the system identity feature test.
- Root implementation-contract and all ten project-skill structures and dry-run records are enforced by `RepositoryContractTest`.

## Manual release job

The real-browser item is deliberately not executed by coding agents. Follow [manual-browser-qualification.md](manual-browser-qualification.md) and record the commit, date, browser version, and output before accepting a release.

No production migration or external operation was performed during this qualification. Local qualification accounts and operations may exist in the development database.
