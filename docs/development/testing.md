---
title: Testing and qualification
description: Run the CDNFoundry Laravel, Go, browser, real-runtime, and CI checks safely, and record the results.
---

# Testing and qualification

This page is for contributors. It lists each check, the command that runs it, what it proves, and what it never touches. Run the checks that match your change, then record the results as described in [Reporting](#reporting).

You need Docker with Compose. Most commands run from the repository root. Copy the documented example environment file before the first startup of a development stack.

::: danger Database guard
Laravel tests must run with `APP_ENV=testing`, `DB_CONNECTION=sqlite`, and `DB_DATABASE=:memory:`. Never point a migration or truncation test at the persistent development PostgreSQL volume, and never run `RefreshDatabase` against PostgreSQL.
:::

## Choose the checks

| You changed | Run |
| --- | --- |
| PHP code, policies, API, models | `make dev-test`, then `make openapi-check` if you touched API routes |
| A Filament screen or the theme | `tests/browser/run.sh` (see [Browser suite](#browser-suite)) |
| `edge-agent` or `edge-gateway` | The Go commands in [Go modules](#go-modules) |
| OpenResty, DNS, TLS, or other runtime behavior | `make dev-e2e` and the matching standalone fixture in [Real-runtime tests](#non-browser-real-runtime-tests) |
| Compose files or configuration | `make config-check` |
| Documentation | `make docs-check` (see [Documentation contributions](../contributing/documentation.md)) |
| Fleet tool | `make fleet-test` |

## Laravel tests

Run Laravel tests only with this command:

```sh
make dev-test
```

Expected result: PHPUnit finishes with a pass count and no failures.

The target builds the assets, prepares the development vendor and test-CA volumes, and then runs `php artisan test` with `compose.test.yml`. That override:

- starts no PostgreSQL, Redis, or other runtime service;
- fixes `APP_ENV=testing`, `DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:`, an empty `DB_URL`, array cache and sessions, and synchronous queues;
- gives PHP disposable, bounded tmpfs mounts for temporary files, application storage, and the bootstrap cache, and mounts no development storage volume;
- limits the container to 1 GiB of memory and 128 processes.

`Tests\TestCase` fails closed when those effective values are absent. If a test fails with a database-guard error, you ran PHP outside `make dev-test`.

The suite covers policies, validation, idempotency, lifecycle, DNS, Geo-DNS, the edge proxy, stable multi-cell placement (including a 20,000-domain, 10,000-change in-memory scale dataset), TLS, cache, security, analytics, operations, UI rendering contracts, and OpenAPI drift.

`make dev-assets` creates `core/public/build` with mode `0755` before it exports the assets, so the non-root PHP worker can read the Vite manifest. `make dev-test` and the development startup targets run the same step.

## Go modules

CI checks formatting, vet, tests, and build in both Go modules, `edge-agent` and `edge-gateway`. It also runs `govulncheck`. Run the same checks in each module:

```sh
cd edge-agent
gofmt -l .
go vet ./...
go test ./...
go build ./...
```

Expected result: `gofmt -l .` prints nothing and the other commands exit with status 0. Repeat in `edge-gateway`.

Both Dockerfiles also run `go test ./...` during the image build.

The Go CI job propagates a failure from either module through its logging pipeline. `tests/qualification/test_go_ci.py` runs that job's shell with controlled tool failures in the first and last module. It covers formatting, vet, tests, and build, so a later success cannot hide an earlier failure. These shell regressions add to the real Go tests and origin-runtime checks.

## Browser suite

Run `tests/browser/run.sh` for any UI change. It starts a disposable SQLite stack from `compose.browser.yml` with generated credentials and a deliberately awkward seed. Then it runs Playwright in light and dark mode at desktop and phone width, with visual snapshots and an axe accessibility check on every page. It never uses PostgreSQL, a named volume, or a non-local URL.

See [Frontend development](frontend.md#browser-suite) for the details and for what to do after an intended design change. Rendered UI is tested only here, not in `tests/e2e`.

## Non-browser real-runtime tests

These Python fixtures in `tests/e2e` start real containers. They may create disposable containers and temporary files. They must not remove repository named volumes or inspect rendered UI.

Start and migrate the persistent development stack, then run:

```sh
make dev-e2e
```

Expected result: every script exits with status 0, so the target exits with status 0. The target builds the managed-WAF image first, then runs these scripts in order:

- foundation, API, and system identity (`e2e.py`);
- authoritative DNS (`dns_authoritative.py`);
- Geo-DNS (`geo_dns.py`);
- edge control with mutual TLS (`edge_control_mtls.py`);
- managed TLS (`managed_tls.py`);
- security and isolation (`edge_security.py`);
- analytics and telemetry outage (`telemetry_analytics.py`);
- backup and restore (`backup_restore.py`);
- managed WAF (`managed_waf.py`);
- origin failover (`origin_failover.py`).

Two tests are not in this CI target because they failed on shared runners and were taken out of the gate:

- edge distribution and pool moves (`edge_distribution.py`): the DNS answer did not move to the quarantine pool within 120
  seconds. Run it by hand with `python3 tests/e2e/edge_distribution.py` on a running development stack.
- OpenResty runtime traffic (`openresty_runtime.py`): run it with `make dev-cache-e2e` or `make dev-compression-e2e`.

Expensive jobs run separately:

| Command | Runs |
| --- | --- |
| `make dev-scale-e2e` | `control_plane_scale.py` |
| `make dev-cache-e2e` | `openresty_runtime.py` |
| `make dev-recovery-e2e` | `offhost_recovery.py` |
| `make dev-upgrade-e2e` | `mixed_version_upgrade.py` |
| `make dev-throughput-e2e` | `cell_throughput.py` |
| `make dev-mmdb-e2e` | `mmdb_provider_outage.py` |
| `make dev-waf-e2e` | `managed_waf.py` after building the pinned image |
| `make dev-gateway-e2e` | `gateway_ingress.py` after building the gateway image |

### Managed WAF

`make dev-waf-e2e` builds the pinned image and runs the real managed-WAF corpus. It verifies off, monitor, balanced, and strict behavior, malformed and oversized bodies, literal expiry-aware exclusions, privacy-safe telemetry, concurrent blocking load, and isolation of non-WAF hosts.

### Gateway ingress

`python3 tests/e2e/gateway_ingress.py` qualifies the gateway runtime without a browser. It needs the development edge profile and the locally built `cdnfoundry/edge-gateway:qualification` image. `make dev-gateway-e2e` builds that image and runs the script.

- A disposable three-backend shared-pool topology must route three Host names to three distinct cells and reject an unrelated hostname.
- Isolated gateway cases force the normal privileged-port threshold. They verify the non-root file capability, listener-only first-endpoint activation, last-valid recovery, and log suppression for an unchanged candidate error.

### Cell inventory

`python3 tests/e2e/cell_inventory.py` qualifies the eight-slot cell inventory: isolation, restart, storage bounds, and idle and active overhead.

### Origin probes

`python3 tests/e2e/origin_probes.py` requires real origin-probe DNS, HTTP/TLS, and IPv6 checks.

- It creates a disposable Docker network with a unique private IPv6 subnet, runs the pinned Go image with 768 MiB and two CPUs, and removes only its own container and network. It uses no persistent database.
- It checks stale and mixed DNS, timeouts, header limits, certificate verification, unverified-TLS reporting, and literal and AAAA-resolved IPv6 connections.
- The `origin-probes` production-runner gate and the Go CI job run it. Ordinary Go tests skip the required-IPv6 case when no private IPv6 interface exists. This command fails instead.

### Origin destinations

`python3 tests/e2e/origin_destinations.py` qualifies the OpenResty address boundary. It builds the current edge runtime, or accepts `--image` for an already built compatible image. It records the immutable local image ID and mounts the current runtime and configuration sources.

Setup:

- Two unique disposable networks provide RFC 1918, ULA, and carrier-grade origin addresses. IPv6 is required.
- The cell has 512 MiB, one CPU, and 128 processes. Only a random loopback HTTP test port is published.
- A pinned Python DNS fixture (64 MiB, half a CPU, 64 processes) answers over UDP and TCP through Docker's embedded resolver. The runtime never queries public DNS.

DNS cases:

- A-only, AAAA-only, and dual-stack names, mixed unsafe sets, and family errors;
- 64 and 65 record boundaries, recursive CNAME answers, and truncation with TCP retry;
- concurrent deadlines and DNS changes between requests;
- a DNS-failed primary must activate the configured backup, and backup DNS failure and recovery must keep correct role attribution;
- origin connection slots must return to zero after timed-out lookups.

Connection-limit cases:

- Held HTTP requests exercise a one-connection origin limit. Repeated excess requests must return 503 without releasing the occupied slot, recording passive origin failures, or activating a configured backup.
- When the held request completes, capacity returns to zero and the next request is admitted.

Retry cases:

- Controlled origin responses qualify zero, one, and two retries, stricter security limits, exhaustion, recovery to 200 and 404, verified IPv6 HTTPS, and POST replay prevention.
- The canary log must contain the exact expected attempt count. Successful retries must leave the primary active with no passive failure receipt.
- Receipt assertions run before the large corpus fills the status endpoint's bounded key scan.
- A fixture-only twelfth-attempt success ends a defective retry loop safely and fails the expected-count check.

Lifecycle cases:

- The fixture removes a hostname while a real HTTP origin response is held open. New requests must return 421, then the fixture restores the hostname.
- Successful and failed in-flight responses must both release their reservation. Restored traffic must succeed without a cell restart.

The real HTTP/TLS canary also covers permitted destinations, explicit exclusions, expanded, mapped, and malformed IPv6, verified TLS and wrong-name rejection, CIDR security rules, invalid runtime-file retention, and container restart. The fixture implies no Laravel, database, or signed-agent activation. CI runs it against the image it just built. The production runner calls it as `origin-destinations`.

`openresty_runtime.py` resolves its syslog hostname to loopback explicitly. It verifies serving with telemetry unavailable and needs no Vector container on its test network.

### Uploaded TLS chains

`python3 tests/e2e/uploaded_tls.py` qualifies custom-certificate chains. It builds the production PHP and OpenResty images, or accepts `--core-image` and `--edge-image` for compatible existing images. It records their immutable local IDs and mounts the current validator and runtime sources explicitly.

- A PHP process with no network or database generates synthetic private-CA chains and runs the chain validator.
- A disposable OpenResty cell then serves those certificates to a real verifying Python TLS client.
- The valid control must return HTTP 200.
- Twelve invalid cases (CA, usage, time, path, purpose, name, critical extension, and strength) must be rejected by admission. Forced into a runtime snapshot, each must fail TLS. The failure is a client verification error, or a fresh OpenResty weak-chain installation error observed at the candidate revision.
- Restoring the valid snapshot must restore its fingerprint and HTTP 200. Each publication waits for the runtime's per-worker refresh.
- The strength cases include 1024-bit RSA issuer and root keys, and SHA-1 signatures on the leaf and intermediate.

The validator runs the real OpenSSL command with authentication level 2, explicit uploaded-root trust, and a five-second deadline. The run records the PHP compile-time OpenSSL constant and the command and library versions. A preliminary peer-identity observation disables verification and lowers only that diagnostic client's security level. Qualification requests use security level 2 with trust and name verification. Server installation errors must match the current revision and fresh diagnostics.

The fixture uses no browser and no existing database, container, or named volume, and never writes synthetic private material to its result log. PHP has 256 MiB, one CPU, 64 processes, and bounded tmpfs. The cell has 512 MiB, one CPU, 128 processes, and a random loopback TLS port.

`TlsApiTest` separately proves API transaction rollback, chain normalization, and API and Filament repeat-upload behavior. The fixture does not qualify signed agent delivery, ACME renewal, or public-browser trust. CI and the production runner require it as the `uploaded-tls` gate.

### Image and vulnerability fixtures

- `python3 tests/e2e/trivy_classifications.py` qualifies vulnerability-policy exceptions with the pinned Trivy container. It uses offline synthetic reports to verify exact package, version, and path scope, unapproved findings, expiry, and end-of-life failure. CI runs it before the production image builds. Full image scans stay separate, and these fixtures do not show that an image is free of vulnerabilities.
- CI runs `sudo -E python3 tests/e2e/dns_images.py` after loading the compiled PostgreSQL, PowerDNS, and DNSdist images tagged `:ci`. The fixture uses a disposable Docker network and database. It waits for PostgreSQL's final TCP server after schema initialization. Then it verifies DNS answers, APIs, serving during a telemetry outage, and recovery after a PowerDNS restart. It removes only its own containers and network.
- Missing pinned DNS, database, and Caddy fixture images are pulled before runtime setup. The pull makes four attempts with a 120-second deadline each and bounded backoff, and existing local images skip the registry. These retries cover image downloads only. Runtime assertions are never retried or suppressed.

## Domain claim database qualification

`python3 tests/e2e/postgres_domain_claims.py` runs the real application migrations and concurrent domain creation against its own named disposable PostgreSQL container, loopback port, and tmpfs database. It uses no PHPUnit migration traits and touches no persistent database or named volume.

It needs host PHP with PDO PostgreSQL, OpenSSL 3 on `PATH`, and the installed `core/vendor` dependencies. The result records the image digest, the instance name, and the observed name-lock wait.

### Custom TLS and DNS interleaving

The gate pauses the real OpenSSL verifier after a custom TLS upload has read hostname coverage. A second PHP process adds a proxied hostname through the DNS controller and commits its new revision before validation resumes.

- The stale upload must return 409, keep the original covering certificate, and preserve the DNS writer's revision.
- Retrying the now-incomplete certificate against current names must return 422.
- Synthetic private material stays in a mode-0600 temporary file and is never printed.

This is a real PostgreSQL and process interleaving. The API and Filament feature tests separately inject a revision change at the verifier boundary to check each entry point.

### Managed certificate contention

The gate holds the domain row while the real managed-certificate job contends with either a custom-mode selection or a proxied DNS-record addition. It confirms lock contention through PostgreSQL activity before it releases the writer. The worker must keep the custom selection, or activate its reusable managed certificate at the DNS writer's revision plus one.

The application suite separately injects an operation-persistence failure, asserts unchanged activation and revision with no dispatch, and then verifies a retry.

Custom-mode selection is also raced against removal through the real TLS controller. After removal commits a managed fallback, the waiting selection must return 409 and keep that fallback and revision. API and Filament tests also advance the clock past certificate expiry when the action rereads the domain in its transaction. They require a conflict or field error with no new revision, operation, audit entry, or edge dispatch.

`ManagedTlsTest` submits renew and reissue API requests for a domain with valid managed coverage, runs the ordinary planner first, and reads both operation resources. Renewal must not complete the forced-reissue receipt. The later forced job must record its new order ID. A retry creates no duplicate order and keeps that result and the active certificate.

### Maintenance sweeps

Maintenance regressions use more eligible domains than fit in one batch. They cover eligibility filtering, arrivals during a sweep, wraparound, dispatch failure, lost cursor state, competing invocations, and lease loss.

The PostgreSQL gate also runs the real command in separate PHP processes with the database cache driver in its disposable database. It requires an empty dispatch while another process holds the scan lease, then distinct bounded batches and wraparound. This shows shared-cache progress and locking for that driver. It does not qualify production Redis outages or CA throughput.

### Challenge cleanup and finalization

The gate overlaps expired-challenge maintenance with the real certificate-finalization job. The job uses synthetic valid certificate material and fixture HTTP responses for the CA. All row locks, writes, and commits use the real application and database.

The test pauses finalization under the domain lock and confirms that maintenance contends before it releases the lock. Both must finish without deadlock, clean the challenge, and leave one revision increment with the issued certificate selected.

Application tests cover the cleanup operation record, live-challenge preservation, repeated cleanup, and rollback and retry when operation persistence fails. This is transaction qualification, not a live-CA issuance or public-DNS serving test.

### Late failures and obsolete orders

Three more PostgreSQL interleavings delay a failure callback, a CA request error, or preflight execution until another worker completes issuance. Separate PHP processes run the real job and callback with fixture CA responses. Each case must keep the succeeded order and operation, the selected certificate, and the single finalization revision, with no retry metadata or errors. The application suite also repeats an exhausted-issuance callback and requires unchanged completion time, error, and revision, with no duplicate DNS dispatch.

The gate also holds a successful CA order response while another process finalizes, fails, or obsoletes the order. After the response is released, the delayed job must exit without a duplicate-certificate error. It must preserve the completed order and operation, completion time, certificate count, selected certificate, and revision. The obsolescence case uses the real job after it removes the proxy records of the disposable domain, so no persistent domain changes.

Obsolescence coverage also pauses a real PostgreSQL worker after its first order read while another process fails that order. The resumed worker must preserve the completed state. An isolated SQLite trigger rejects the issuance receipt update. That proves obsolescence and cleanup rollback, the later retry, and repeat safety without sending any CA request.

### Idempotency locking

The PostgreSQL script also exercises idempotency locking with process-local caches, concurrent identical requests, and a process killed between mutation and receipt.

## Parent delegation

`python3 tests/e2e/parent_delegation.py` checks parent delegation. Install host `bind9-dnsutils` (it provides `dig` and `delv`) and PHP with intl and the existing Composer dependencies first.

The script builds a pinned Alpine and BIND fixture, generates an ephemeral signed COM zone, and queries real UDP and TCP listeners over IPv4 and IPv6 loopback. IPv6 loopback is required. It covers fresh and stale delegation, bogus signatures, and existing DS and child-apex answers.

A test-only process seam supplies the fixture trust anchor and redirects packets. Production validation and bounds run unchanged. The check changes no application database and no existing container or volume. It does not qualify public DNS, registrar propagation, or routed IPv6.

## Final production qualification

`make dev-production-qualification` runs the bounded final non-browser suite. It writes a machine-readable report and per-check logs under `storage/qualification/`. It returns nonzero when a check fails, or when required evidence is absent: owner-operated public traffic, Anycast, external load, fleet installer, or browser evidence. See [Production qualification](../operations/production-qualification.md) for the topology, evidence variables, failure exercises, and release decision.

## Static and contract checks

```sh
make config-check
make openapi-check
make docs-check
make fleet-test
make qualification-tools-check
git diff --check
```

Expected result: every command exits with status 0 and `git diff --check` prints nothing.

- `make config-check` validates the development, test, and production-example Compose files and the production environment generator. Then it runs `QueueTimingContractTest`.
- `make openapi-check` prepares the vendor volume and compares the routes with the committed OpenAPI contract in the same disposable test configuration. It needs neither PostgreSQL nor Redis.
- `make fleet-test` runs `pytest` on `tests/fleet`.
- `make qualification-tools-check` runs the supply-chain and qualification unit tests, including the image-transfer rejection cases.

`make contract-check` runs `config-check`, `openapi-check`, and the edge-rejection, cache-key, and observability contract tests.

Application CI additionally runs Composer validation and advisories, npm production advisories, Pint, the frontend build, Python compilation, production image builds, and a read-only core-image smoke test.

## CI image reuse and build caches

CI builds the production images in three independent `images` matrix jobs: `application` (core, web, edge-control), `edge` (edge-runtime), and `agents` (edge-agent, edge-gateway, mmdb-updater). `docker-bake.hcl` owns the build targets. The application group shares its core build with web and edge-control through Bake target contexts.

- Each target has a separate GitHub Actions layer-cache scope, including intermediate compiler layers. A cache miss still builds from the pinned inputs. An unavailable cache export cannot bypass a build or qualification failure.
- Go checks cache their compiler and module data using the module declarations.
- Builds carry the workflow commit, ref name, and commit timestamp in their release labels.
- Each group uploads a compressed Docker archive with its checksum and image IDs. `scripts/ci/images.py load` rejects missing, duplicate, corrupt, or wrong-commit artifacts and verifies the loaded image IDs.
- Production qualification and publication load those same artifacts. Publication tags the qualified images with the commit instead of compiling them again. Complete scans, SBOMs, signatures, attestations, and the signed manifest remain required.
- The publication scanner shares one local vulnerability database cache between images and still evaluates each image.
- Archives expire after one day. If they expire, rerun the whole workflow, not only the publication job.

Each image group and the publication job have a 30-minute deadline. Production-image qualification has 20 minutes. These are failure bounds, not a promised workflow duration. Runner queues, environment approval, cold caches, uploads, runtime qualification, and registry availability all change the elapsed time. Measure cold and warm successful runs before you claim a new completion time.

Validate the transfer rejection cases with:

```sh
python3 -m unittest discover -s tests/qualification
```

The PostgreSQL and ClickHouse upgrade fixtures use temporary bind-mounted data. They restore its ownership through a bounded container and then remove the temporary files. This lets the same real upgrade and restart checks finish on a non-root GitHub runner without changing any persistent database or named volume. Run `python3 tests/e2e/postgres_upgrade.py` and `python3 tests/e2e/clickhouse_upgrade.py` as a normal Docker-enabled user to cover that cleanup boundary. Browser qualification does not apply to these CI-only changes.

## Reporting

Record these items for every change:

- the exact commands, revision, and environment;
- result counts or terminal markers;
- migration activity;
- browser suite results;
- limitations.

Results recorded for an earlier commit are not proof for the current tree. Never report a check you did not run as passed.
