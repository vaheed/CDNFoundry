---
title: Testing and qualification
description: Run CDNFoundry unit, feature, contract, real-runtime, scale, and manual browser qualification safely.
---

# Testing and qualification

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
edge proxy, TLS, cache, security, analytics, operations, UI rendering contracts,
and OpenAPI drift.

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

## Browser qualification

Browser E2E is manual and user owned. Coding agents must not launch Chromium,
Playwright, Selenium, Cypress, or another browser automation tool. Use
[Manual browser qualification](/manual-browser-qualification), record exact
expected and actual results, and never report an unexecuted checkpoint as passed.

## Reporting

Record the exact commands, revision, environment, result counts or terminal
markers, migration activity, manual-browser status, and limitations. Historical
results in `docs/legacy/` are evidence for their recorded commits, not proof for
the current tree.
