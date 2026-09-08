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
cells while an unrelated hostname is rejected. Its isolated gateway cases force
the normal privileged-port threshold and verify the non-root file capability,
listener-only first-endpoint activation, last-valid recovery, and unchanged
candidate-error log suppression.

Run `python3 tests/e2e/cell_inventory.py` for the eight-slot non-browser cell
inventory, isolation, restart, storage-bound, and idle/active overhead
qualification.

Run `make dev-phase9-e2e` to build the pinned image and execute the real
managed-WAF corpus. It verifies off/monitor/balanced/strict behavior, malformed
and oversized bodies, literal expiry-aware exclusions, privacy-safe telemetry,
concurrent blocking load, and non-WAF-host isolation.

::: danger Database guard
Laravel tests must use `APP_ENV=testing`, `DB_CONNECTION=sqlite`, and
`DB_DATABASE=:memory:`. Never point migration or truncation tests at the
persistent development PostgreSQL volume.
:::

A fresh `make dev-assets` creates `core/public/build` with mode `0755` before
exporting assets, so the non-root PHP worker can read the Vite manifest. The
same preparation runs through `make dev-test` and the development startup
targets. Application environment setup remains required; copy the documented
example configuration before first startup.

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

The Go CI job runs both modules with failure propagation inside its logging
pipeline. `tests/qualification/test_go_ci.py` executes that job's shell with
controlled tool failures in the first and last module, covering formatting,
vet, tests, and build. A later success cannot hide an earlier failure. These
shell regressions supplement the real Go test/build and origin-runtime checks.

Run `python3 tests/e2e/origin_probes.py` to require real origin-probe DNS,
HTTP/TLS, and IPv6 checks. It creates a disposable Docker network with a unique
private IPv6 subnet, runs the pinned Go image with 768 MiB / two CPUs, and
removes only its own container/network. No persistent database is used. It
checks stale/mixed DNS, timeout, header limits, certificate verification,
unverified TLS reporting, and literal/AAAA-resolved IPv6 connections. The
`origin-probes` production-runner gate and Go CI job run this check. Ordinary
Go tests skip the required-IPv6 case when no private IPv6 interface is present;
this qualification command fails if its IPv6 interface is unavailable.

Run `python3 tests/e2e/origin_destinations.py` for the OpenResty address boundary.
It builds the current edge runtime (or accepts `--image` for an already built
compatible image), records its immutable local image ID, and mounts the current
runtime/configuration sources. Two unique disposable networks provide RFC1918,
ULA and carrier-grade origin addresses; IPv6 is required. The cell has 512 MiB,
one CPU and 128 processes, with only a random loopback HTTP test port published.
A pinned, 64-MiB / half-CPU / 64-process Python DNS fixture supplies UDP/TCP
responses through Docker's embedded resolver. It covers A-only, AAAA-only and
dual-stack names, mixed unsafe sets, family errors, 64/65-record boundaries,
recursive CNAME answers, truncation/TCP, concurrent deadlines and DNS changes
between requests. DNS-failed primaries must activate the configured backup;
backup DNS failure and recovery must retain correct role attribution. The
fixture records elapsed times and checks that origin
connection slots return to zero after timed-out lookups. Held HTTP requests also
exercise a one-connection origin limit: repeated excess requests must return
503 without releasing the occupied slot, recording passive origin failures, or
activating a configured backup. Completion must restore capacity to zero and
admit the next request. No public DNS service
is queried by the runtime corpus.
Controlled origin responses qualify zero, one and two retries, stricter security
limits, exhaustion, recovery to 200/404, verified IPv6 HTTPS and POST replay
prevention. The canary log must contain the exact expected attempt count;
successful retries must leave primary active with no passive failure receipt.
Receipt assertions run before the large corpus fills the status endpoint's
bounded key scan. A fixture-only twelfth-attempt success safely terminates a
defective retry loop and fails the expected-count check.
The real HTTP/TLS canary also covers permitted destinations, explicit exclusions,
expanded/mapped/malformed IPv6, verified TLS and wrong-name rejection, CIDR
security rules, invalid runtime-file retention and container restart. No
Laravel/database or signed-agent activation is implied by this fixture. CI runs
it against the image it just built; the production runner calls it through
`origin-destinations`.

The cumulative `phase4_runtime.py` fixture resolves its syslog hostname to
loopback explicitly. It verifies serving with telemetry unavailable and does
not require a Vector container to exist on its test network.

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

## Final production qualification

Run `make dev-production-qualification` to execute the bounded final
non-browser suite and write a machine-readable report plus per-check logs under
`storage/qualification/`. The command returns nonzero when a check fails or
when required owner-operated public traffic, Anycast, external load, fleet
installer, or browser evidence is absent. See
[Production qualification](../operations/production-qualification.md) for the
required topology, evidence variables, failure exercises, and release decision.

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

## Domain claim database qualification

`python3 tests/e2e/postgres_domain_claims.py` runs actual application migrations
and concurrent domain creation against its own named disposable PostgreSQL
container, loopback port and tmpfs database. It uses no PHPUnit migration traits
and touches no persistent database or named volume. It requires host PHP with
PDO PostgreSQL and the installed `core/vendor` dependencies. The result records
the image digest, instance name and observed name-lock wait.

The PostgreSQL script also exercises idempotency locking with process-local
caches, concurrent identical requests, and a killed process between mutation and
receipt. For parent delegation, install host `bind9-dnsutils` (providing `dig`
and `delv`) alongside PHP with intl and existing Composer dependencies, then run:

```sh
python3 tests/e2e/parent_delegation.py
```

This builds a pinned Alpine/BIND fixture, generates an ephemeral signed COM
zone, and queries real UDP/TCP listeners over IPv4 and IPv6 loopback. It covers
fresh/stale delegation, bogus signatures, existing DS and child-apex answers.
A test-only process seam supplies the fixture trust anchor and redirects packets;
production validation and bounds run unchanged. This does not qualify public
DNS, registrar propagation or routed IPv6. No application database or existing
container/volume changes. IPv6 loopback is required for the check.
