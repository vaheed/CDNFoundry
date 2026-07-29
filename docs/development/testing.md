---
title: Testing and qualification
description: Run CDNFoundry unit, feature, contract, real-runtime, and scale qualification safely.
---

# Testing and qualification

Run `python3 tests/e2e/gateway_ingress.py` for the non-browser gateway runtime
qualification. It requires the development edge profile and the locally built
`cdnfoundry/edge-gateway:qualification` image. Coding agents must not run the separate
manual browser checklist. The same job creates a disposable three-backend
shared-pool topology and verifies that three Host routes reach three distinct
cells while an unrelated hostname is rejected.

Run `python3 tests/e2e/cell_inventory.py` for the eight-slot non-browser cell
inventory, isolation, restart, storage-bound, and idle/active overhead
qualification.

Run `python3 tests/e2e/phase9_waf.py` for the real pinned-image managed-WAF
corpus. It verifies off/monitor/balanced/strict behavior, malformed and
oversized bodies, literal expiry-aware exclusions, privacy-safe telemetry,
concurrent blocking load, and non-WAF-host isolation.

::: danger Database guard
Laravel tests must use `APP_ENV=testing`, `DB_CONNECTION=sqlite`, and
`DB_DATABASE=:memory:`. Never point migration or truncation tests at the
persistent development PostgreSQL volume.
:::

## Laravel tests

Use only:

```sh
make dev-test
```

The target injects `APP_ENV=testing`, `DB_CONNECTION=sqlite`,
`DB_DATABASE=:memory:`, array cache, and synchronous queues. `Tests\TestCase`
fails closed when those effective values are absent. Never run
`RefreshDatabase` or a migration/truncation suite against development
PostgreSQL.

The suite covers policies, validation, idempotency, lifecycle, DNS, Geo-DNS,
edge proxy, stable multi-cell placement (including a 20,000-domain / 10,000-change
in-memory scale dataset), TLS, cache, security, analytics, operations, UI
rendering contracts, and OpenAPI drift.

## Go agent

CI runs formatting, vet, tests, and build in every Go module:

```sh
cd edge-agent
gofmt -l .
go vet ./...
go test ./...
go build ./...
```

The agent Dockerfile also runs its tests during image build.

## Non-browser real-runtime tests

Start and migrate the persistent development stack, then:

```sh
make dev-e2e
```

The cumulative target executes:

- foundation/API and system identity;
- authoritative DNS;
- Geo-DNS;
- edge control and mutual TLS;
- managed TLS;
- security and isolation;
- analytics and telemetry outage;
- operations;
- OpenResty runtime traffic.

Additional expensive jobs are separate:

```sh
make dev-scale-e2e
make dev-cache-e2e
make dev-phase8-recovery-e2e
make dev-phase8-upgrade-e2e
make dev-phase8-throughput-e2e
make dev-phase8-mmdb-e2e
```

These may create disposable containers and temporary files, but must not remove
repository named volumes or inspect rendered UI.

## Static and contract checks

```sh
make config-check
make openapi-check
make docs-check
git diff --check
```

Application CI additionally runs Composer validation/advisories, npm production
advisories, Pint, frontend build, Python compilation, production image builds,
and a read-only core-image smoke test.

## Reporting

Record the exact commands, revision, environment, result counts or terminal
markers, migration activity, and limitations. Historical
results in `docs/legacy/` are evidence for their recorded commits, not proof for
the current tree.
