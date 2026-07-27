---
title: Post-baseline manual qualification
description: Owner-run browser and real-traffic checklist for the completed baseline and the current post-baseline roadmap phase.
---

# Post-baseline manual qualification

This is the manual, owner-run release job. Coding agents must not launch or
automate a browser. Automated tests and API probes do not replace any browser
checkpoint in this document.

A missing menu, field, action, status, metric, or runtime result is **Failed**,
not not-applicable. Record every result as **Passed**, **Failed**, **Blocked**,
or **Not run**. A current phase cannot pass with a blocked or unexecuted
applicable checkpoint.

## Current scope

The original roadmap is the completed regression baseline. The
[current roadmap](roadmap.md) contains only post-baseline work. This document
covers:

1. a focused browser and real-traffic regression of that baseline; and
2. post-baseline **Phase 1 — Edge gateway ingress**, the current roadmap phase.

Post-baseline Phases 2–12 are intentionally absent. Add their exact browser and
operator checkpoints only when their implementation becomes current. Do not
invent future menus, forms, fields, or actions.

## Qualification record

Create one record for every run.

| Evidence | Recorded value |
| --- | --- |
| Result | Passed / Failed / Blocked / Not run |
| Date and operator | |
| Commit SHA and working-tree state | |
| Environment and topology | |
| Browser and version | |
| Desktop viewport | |
| Narrow/mobile viewport | |
| Gateway, agent, and cell image versions | |
| IPv4 and optional IPv6 service addresses | |
| Disposable domains and origins | |
| Operation, revision, and task IDs | |
| Metrics/log evidence location | |
| Screenshots or screen recording location | |
| Automated/runtime qualification report | |
| Failures, owners, and retest evidence | |

Evidence must be sanitized. Never record passwords, API tokens, bootstrap
tokens, private keys, customer data, certificate private material, or signing
keys.

## Preparation

Use disposable accounts, domains, origins, and publicly routed test addresses.
Documentation ranges such as `192.0.2.0/24`, `198.51.100.0/24`,
`203.0.113.0/24`, `2001:db8::/32`, and names below `example.test` are examples
only and do not qualify real traffic.

1. From the repository root, start the persistent development topology and run
   migrations explicitly:

   ```sh
   make dev-up
   make dev-migrate
   make dev-pdns-migrate
   docker compose -f compose.dev.yml ps
   ```

2. Confirm the existing named volumes remain in place. Never run `down -v`,
   delete volumes, use `migrate:fresh`, or run destructive Laravel tests against
   development PostgreSQL.
3. Verify `http://localhost:8080/api/health` and
   `http://localhost:8080/api/ready`, DNSdist on UDP and TCP
   `127.0.0.1:1053`, Horizon workers, the scheduler, PowerDNS, ClickHouse,
   Vector, both enrolled edge agents, and every required cell.
4. If an administrator is needed, create it with the documented
   `cdnf:admin:create` command. Do not put its prompted password in the
   qualification record.
5. Prepare:

   - one administrator and one enabled domain user;
   - one unassigned comparison user;
   - one real delegated disposable domain;
   - two proxied hostnames with distinguishable origin responses;
   - one DNS-only hostname;
   - at least two gateway service-address sets, including one IPv4-only edge;
   - a working IPv4 path and, for this support qualification, one configured IPv6 path;
   - one healthy comparison domain that must remain available during failures.

6. Record the exact public address, pool/cell, hostname, origin marker, and
   expected certificate fingerprint for every traffic route.
7. Use the surfaces below. Production-like runs must substitute approved HTTPS
   management and monitoring addresses.

| Surface | Development address |
| --- | --- |
| Landing page | `http://localhost:8080/` |
| Administrator panel | `http://localhost:8080/admin` |
| Domain-user panel | `http://localhost:8080/app` |
| Horizon | `http://localhost:8080/admin/horizon` |
| Development mail | `http://localhost:8025` |
| PowerAdmin, diagnostic only | `http://localhost:9191` |
| Prometheus | `http://localhost:9090` |
| Alertmanager | `http://localhost:9093` |
| Edge A/B health | `http://localhost:8081/healthz`, `http://localhost:8082/healthz` |
| Edge-control local health | `https://localhost:9443/healthz` |

For every browser section, repeat the important read and mutation paths at a
desktop width and a narrow/mobile width. Confirm visible keyboard focus,
associated labels, useful validation at the affected field, readable tables,
safe confirmation for destructive actions, no horizontal page overflow, no
missing assets, and no unexpected browser-console errors.

## Completed-baseline regression

The baseline remains part of every post-baseline release. Record one result for
each section below; a sampling run does not authorize removal of any baseline
feature.

### Access, authorization, and secrets

1. Open the landing page and follow its links to `/admin`, `/app`, and
   `/api/health`. Expect the CDNFoundry page, compiled assets, and no Laravel
   starter screen.
2. Sign in to `/admin`. Expect the administrator dashboard and the
   **Control plane**, **Customers**, **Edge network**, **Operations**,
   **Observe**, and **Account** navigation groups.
3. Open **Customers → Users → New user**. Create a disposable **Domain user**
   with a unique name, email, password, and matching confirmation. Expect one
   user row and no plaintext password after save.
4. Disable that user. In a separate browser profile, expect `/app` login to be
   denied. Re-enable the user and expect login to succeed.
5. In each panel, open **Account → Profile** and **Account → API tokens**.
   Change the display name, create a named token, record that plaintext appears
   once, refresh, and confirm only metadata/final characters remain. Revoke the
   token and confirm it no longer authenticates.
6. As the domain user, directly request administrator pages for users, DNS
   clusters, edges, service pools, platform settings, audit logs, operations,
   and Horizon. Expect denial and no administrator navigation or data.
7. Open **Audit logs** as administrator. Expect the preceding mutations with
   actor, action, subject, IP, and time, without secrets or editable controls.

### Desired state, DNS, and domain isolation

1. Open **Control plane → System DNS identity**. Confirm the configured platform
   domain, proxy hostname, at least two nameservers and glue addresses, SOA
   values, TTLs, and cluster targets. Preview without mutation, then cancel.
2. Open **Control plane → DNS clusters**. Expect enabled targets to show health
   without exposing their saved API keys.
3. Open **Domains**, select the disposable delegated domain, and confirm its
   lifecycle, desired revision, assigned users, authoritative deployment, and
   edge-delivery state.
4. Sign in as the assigned domain user. Expect only assigned domains. Directly
   request an unassigned domain URL and expect not found or forbidden without
   leaking its name or state.
5. In **DNS records**, create and delete one disposable DNS-only TXT record.
   Expect one durable domain revision per effective mutation, asynchronous DNS
   reconciliation, the correct answer after creation, and no answer after
   deletion through DNSdist over UDP and TCP.
6. Attempt an out-of-zone owner, invalid TTL, conflicting CNAME, and a
   domain-user delegation change. Expect field-level rejection, no partial
   mutation, and no revision increase.
7. Preview one existing Geo-DNS record with country, continent, default, IPv4,
   and IPv6 inputs. Expect country before continent before default and no
   desired-state mutation.

### Proxy, TLS, cache, security, and isolation

1. Open a proxied hostname in **DNS records**. Confirm the origin destination,
   scheme, locked standard port, **Origin Host header**, **TLS SNI**, TLS
   verification, timeouts, retry count, WebSocket setting, health-check
   setting, and platform-managed DNS route.
2. Choose **Test origin**. Expect an asynchronous operation and a bounded
   status/latency result or stable failure reason; the browser request must not
   wait for the external probe.
3. Send real HTTP and HTTPS traffic over IPv4 and IPv6. Expect the intended
   origin marker, forwarding behavior, certificate, cache status, and no
   Laravel request-path dependency.
4. Open **TLS**. Confirm mode, covered names, expiry, fingerprint, and deployment
   status without private-key material.
5. Open **Cache**. Request one cacheable URL twice and expect MISS then HIT.
   Purge that exact URL and expect a durable operation followed by MISS. Do not
   scan or delete cache directories.
6. Open **Security profile and limits** and the security-rules relation. Confirm
   the active bounded profile and test one disposable IPv4 rule and one IPv6
   rule. Expect stable allow/block behavior and removal without unrelated
   traffic impact.
7. Keep the healthy comparison domain active throughout. Expect no change in
   its DNS, TLS, cache, origin, or security behavior.

### Telemetry, operations, and recovery visibility

1. Generate controlled DNS, HTTP, HTTPS, HIT, MISS, origin-failure, and security
   events. As the domain user, open **Observe → Analytics** and expect only
   assigned-domain aggregates and logs with bounded ranges and masked client
   addresses.
2. As administrator, open **Observe → Telemetry**. Expect component freshness,
   bounded/redacted data, and no secrets.
3. Open **Operations**. Inspect pending, succeeded, and failed examples. Expect
   operation ID, type, requester, status, attempts, timestamps/duration, and a
   bounded error. Retry only a supported disposable failure and expect no
   duplicate active work.
4. Open **Platform settings**. Save one reversible non-runtime value and restore
   it. Expect typed validation and audit history. Do not change a production
   secret.
5. Stop telemetry only in the controlled environment. Expect a visible
   degraded state while DNS and edge traffic continue. Restore telemetry and
   record bounded buffer recovery.
6. Refresh, sign out, sign in, and restart the affected non-database service.
   Expect PostgreSQL desired state and the previous valid runtime state to
   remain intact.

### Baseline regression result

| Area | Result | Evidence or failure |
| --- | --- | --- |
| Access, authorization, and secrets | | |
| Desired state, DNS, and domain isolation | | |
| Proxy, TLS, cache, security, and isolation | | |
| Telemetry, operations, and recovery visibility | | |

The baseline regression passes only when every row is **Passed**.

## Phase 1 — Edge gateway ingress

### Purpose and topology

Qualify one minimal gateway per edge that binds public IPv4 and optional IPv6
service addresses and routes to bounded OpenResty cells. The gateway routes HTTP by
destination address and validated Host, and routes HTTPS by destination address
and TLS SNI without terminating customer TLS.

Use one dual-stack service-address set, one IPv4-only service-address set, two
distinguishable hostnames, two target cells, and one unrelated comparison route. Record the
exact mapping before the run:

| Route | Service IPv4 | Service IPv6 | Host/SNI | Target cell | Origin marker |
| --- | --- | --- | --- | --- | --- |
| A | | | | | |
| B | | | | | |
| Comparison | | | | | |

### Browser state and authorization

1. Sign in as administrator and open **Edge network → Edges**.
2. Open each participating edge. Expect **Enrolled at**, **Last heartbeat**,
   **Agent version**, **Traffic listener**, **Active configuration sequence**,
   **Identity expires**, and **Latest deployment rejection**. Record the values
   before traffic testing.
3. Open the **Cells** relation. Expect each target to show **Cell**,
   **Service pool**, status, **Service addresses**, runtime/version, workload,
   resources, storage, and drain state. Confirm Route A and Route B have the
   intended unique configured addresses and ready cells. The IPv4-only cell
   must save and become ready with its IPv6 field empty.
4. Open **Edge network → Service pools**. Expect the relevant enabled pool,
   withdrawal state, **DNS routing target**, revision, and edge-cell count.
5. Refresh both pages. Expect the same durable desired state and current
   acknowledged runtime state; no one-time enrollment secret may reappear.
6. Sign in as a domain user and directly request the edge and service-pool
   administrator URLs. Expect denial with no fleet addresses, revisions,
   capacity, or failure details disclosed.
7. On each edge detail page, expect **Gateway** = **Ready**, **Gateway map
   revision** equal to **Active configuration sequence**, and visible **Gateway
   listeners**, **Gateway routes**, **Gateway active connections**, **Gateway
   errors**, and **Gateway rejected candidates**. Record every value. If any
   field is absent or the revision differs, mark this checkpoint **Failed**.

### HTTP routing

For every probe, record timestamp, destination address, Host, response status,
target cell, origin marker, gateway revision, and relevant metric/log evidence.

1. Send HTTP for Route A to its service IPv4 with Route A's exact Host. Expect
   Route A's cell and origin marker.
2. Repeat over Route A's service IPv6. Expect the same logical route and
   response.
3. Repeat over Route B's IPv4-only address. Expect Route B's cell and origin
   marker, not Route A's, with no IPv6 value required or synthesized.
4. Send Route A's Host to Route B's address and Route B's Host to Route A's
   address. Expect only mappings explicitly present in the active routing map;
   an absent address/Host pair must be rejected before origin traffic.
5. Send an unknown Host, empty Host where the protocol permits the probe,
   malformed Host, overlong Host, and duplicated/conflicting Host header.
   Expect a bounded rejection and zero origin requests.
6. Send traffic to an unconfigured destination address on the test host.
   Expect no gateway route and no cell or origin request.
7. Attempt to spoof the gateway-to-cell client-identity fields documented by
   the implementation. Expect untrusted inbound values to be removed or
   replaced and the cell to receive only the gateway's trusted identity.
8. Repeat a valid request after every rejection. Expect normal latency and no
   poisoned keepalive, route, or connection state.

### HTTPS SNI routing

Use valid certificates and strict verification for release qualification.
Development-only `-k` probes do not qualify certificate behavior.

1. Connect to Route A's IPv4 with Route A's exact SNI and send its matching HTTP
   Host through the TLS connection. Expect the cell-selected certificate,
   Route A's origin marker, and no customer TLS termination at the gateway.
2. Repeat Route A over IPv6, then repeat Route B over IPv4 only. Expect all
   configured paths to serve without requiring an IPv6 value for Route B.
3. Record the served certificate fingerprint for each route and compare it with
   the expected cell certificate.
4. Send Route A's SNI with Route B's Host and the reverse. Expect the
   implementation's documented safe rejection; no unintended origin may be
   contacted.
5. Try unknown SNI, missing SNI, malformed SNI, and an SNI name not present for
   that destination address. Expect rejection before customer request
   processing and zero origin requests.
6. Repeat a valid TLS request immediately afterward. Expect the active route
   and certificate to remain unchanged.

### Atomic activation, failure, and recovery

Perform candidate changes only through the supported desired-state workflow.
Never edit generated gateway or cell files directly.

1. Record the active gateway map revision and checksums, then make one valid
   disposable routing change. Expect desired state to commit, asynchronous work
   to become visible, a validated candidate to activate atomically, and the
   acknowledged revision to advance.
2. During activation, continuously request Route A, Route B, and the comparison
   route over every configured family. Expect no partial map, cross-route response, or
   unnecessary interruption.
3. Submit a deliberately invalid candidate using the supported qualification
   fixture. Expect validation failure, a stable reason, no acknowledgement of
   the invalid revision, and the previous valid map to keep serving.
4. Confirm **Latest deployment rejection** or the gateway-specific failure
   surface shows a bounded reason without candidate contents or secrets.
5. Retry/coalesce the same desired revision. Expect idempotent work and no
   duplicate activation. Supersede it with a newer revision and expect obsolete
   work to stop without replacing the newer valid state.
6. Restart only the gateway. Expect it to restore or rebuild the last valid map
   and become ready without rebuilding customer state through Laravel request
   paths.
7. Make the control plane temporarily unavailable. Expect established and new
   valid HTTP/HTTPS traffic to continue from local state.
8. Make one target cell unavailable. Expect a bounded failure isolated from the
   other route, comparison cell, edge agent, and gateway process.
9. Restore the cell and control plane. Expect reconciliation to converge on the
   latest desired revision without manual file repair.
10. Return every disposable mutation to its starting state and record the final
    active revision and health.

### Metrics, bounds, and scale evidence

1. In the implemented monitoring surface, confirm gateway listener, active
   revision, route count, connections, errors, and readiness are visible per
   edge without customer secrets or unbounded labels.
2. Generate accepted and rejected HTTP and HTTPS traffic over every configured
   family, including the IPv4-only edge. Expect the
   corresponding counters/state to change and unrelated cell telemetry to
   remain attributable.
3. Link the agent-owned scale report for at least 50,000 Host/SNI mappings and
   multiple dual-stack service pairs on one edge. The report must state
   hardware/topology, dataset, concurrency, duration, throughput, latency, CPU,
   memory, saturation point, and accepted limit.
4. Link failure evidence for invalid candidates, gateway restart, control-plane
   outage, target-cell outage, retry, obsolete work, and last-valid-state
   preservation.
5. Confirm alerts and runbooks identify the edge, active revision, degraded
   component, stable reason, and operator action.

### Phase 1 completion gate

Fill every row. Link evidence instead of writing only “passed.”

Agent-owned status on 2026-07-27: implementation, Go unit/scale tests, 161
isolated Laravel tests, Compose/Prometheus validation, documentation checks,
the non-browser dual-stack and IPv4-only HTTP/HTTPS runtime test, and the
completed-baseline non-browser regression stages passed. See
[Edge gateway ingress](operations/gateway-ingress.md). Agent-owned strict TLS
verification passed with the active development ACME trust root. Owner browser
evidence, including browser-native strict certificate verification, remains
**Pending owner run**, so the release decision remains **Blocked** and the rows
below must not be marked Passed by a coding agent.

| Gate | Result | Required evidence |
| --- | --- | --- |
| Implementation | | Gateway state, authorization, asynchronous revision workflow, atomic activation, and rollback |
| Unit and feature tests | | Happy path, permissions, validation, bounds, idempotency, and stable errors |
| Real-runtime E2E | | Real HTTP Host and HTTPS SNI routing and rejection behavior |
| IPv4 and IPv6 | | Both families for configured, unknown, failure, and recovery paths |
| Scale | | 50,000 mappings, multiple dual-stack pairs, hardware, load, resources, and saturation |
| Failure and recovery | | Invalid candidate, retry, obsolete work, restart, outage, rollback, and last-valid map |
| Isolation | | One route/cell failure leaves unrelated traffic, agent, and gateway healthy |
| Observability | | Listener, revision, routes, connections, errors, readiness, alerts, and bounded logs |
| Documentation | | User, administrator, API/OpenAPI, architecture, deployment, operations, troubleshooting, and runbooks |
| Manual qualification | | Every baseline and Phase 1 checkpoint recorded by the owner |
| Regression | | Completed DNS, proxy, TLS, cache, security, telemetry, analytics, backup, and operations baseline remains healthy |
| Release decision | | Passed, Failed, Blocked, or Removed from scope with approved contract change |

Phase 1 is complete only when every applicable gate is **Passed**. A missing UI,
failed configured IPv6 path, failed IPv4-only path, unexecuted scale run, or
unrecorded browser result keeps the phase incomplete.

## Failure record

For every failed or blocked checkpoint, record:

| Field | Value |
| --- | --- |
| Checkpoint | |
| Expected result | |
| Actual result | |
| Sanitized evidence | |
| Operation/task/revision IDs | |
| Severity and traffic impact | |
| Owner | |
| Fix or approved scope decision | |
| Retest date, commit, and result | |

Do not mark the release qualified until every current failure passes retest or
an explicit product-contract change removes the requirement.
