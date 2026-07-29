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

Where a field, entry, table heading, or section heading has optional
explanatory help, hover the title and focus it with the keyboard. Expect the
same short tooltip with no separate help icon and no persistent help paragraph.
Validation errors, warnings, confirmation text, degraded-state reasons, and
live operational evidence must remain visible without hovering.

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
7. Open **Security** on the disposable domain. Start maintenance with a unique
   response message and expect HTTP 503 only for that domain; end maintenance
   and expect normal service. Use **Protect domain**, then **Return to normal**,
   and confirm the security state and operation are visible. If a quarantine
   pool is ready, use **Quarantine domain** and confirm target-first placement
   moves only that domain before returning it to normal.
8. On a disposable service pool, choose **Maintenance**, enter an automatic
   expiry, and expect HTTP 503 only from cells assigned to that pool. Confirm
   edge and cell screens have concrete **Drain**, **Undrain**, and **Restart**
   controls and no generic emergency-action picker. End pool maintenance.
9. Keep the healthy comparison domain active throughout. Expect no change in
   its DNS, TLS, cache, origin, or security behavior.

### Telemetry, operations, and recovery visibility

1. Generate controlled DNS, HTTP, HTTPS, HIT, MISS, origin-failure, and security
   events. As the domain user, open **Observe → Analytics** and expect only
   assigned-domain aggregates and logs with bounded ranges and masked client
   addresses.
2. As administrator, open **Observe → Telemetry**. Expect component freshness,
   bounded/redacted data, and no secrets. Expect the blue **Live window
   included** label to explain that only the configured newest window is
   provisional, not that delivery is degraded. Expect **Finalized usage** to
   show no more than five rows and every shown row to be **Finalized**.
3. In **Compression savings**, expect encoding, profile/fallback, request,
   delivered, and saved values to remain readable without page overflow. In
   **Recent logs**, expect five rows per stream, **Edge requests** to contain
   generated edge traffic, and **Show more**/**Show fewer** to expand and
   collapse a stream without reloading the page.
4. In **Vector buffer and delivery**, expect explicit human-readable current
   **Buffered data** and **Buffered events** gauges plus **Discarded events
   since start** and **Component errors since start**. Generate a controlled
   buffer/recovery event and expect the current gauges to recover. Lifetime
   counters need not reset before Vector restarts, may describe the same failed
   events, and must identify affected components when nonzero.
5. Open the administrator dashboard and inspect **Component health**. For each
   degraded/unavailable component, expect its bounded counts/timestamps and a
   component-specific **How to fix** direction. Confirm **Queue lanes** shows
   ready, reserved, delayed, and total work and **Recent audit activity** is a
   table directly below it. Leave the page open for 15 seconds and expect the
   evidence to refresh without a browser reload. Resolve one controlled
   failure and expect only its component to recover.
6. Open **Operations**. Inspect pending, succeeded, and failed examples. Expect
   operation ID, type, requester, status, attempts, timestamps/duration, and a
   bounded error. Use the copy control and paste into a plain-text field; expect
   the complete UUID rather than the shortened table label. Retry only a
   supported disposable failure and expect no duplicate active work.
7. Open **Platform settings**. Save one reversible non-runtime value and restore
   it. Expect typed validation and audit history. Do not change a production
   secret.
8. Stop telemetry only in the controlled environment. Expect a visible
   degraded state while DNS and edge traffic continue. Restore telemetry and
   record bounded buffer recovery.
9. Refresh, sign out, sign in, and restart the affected non-database service.
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
7. On each edge detail page, expect **Gateway process** = **Ready**, **Gateway map
   revision** equal to **Active configuration sequence**, and visible **Gateway
   listeners**, **Gateway routes**, **Gateway active connections**, **Gateway
   errors**, and **Gateway rejected candidates**. Record every value. If any
   field is absent or the revision differs, mark this checkpoint **Failed**.
8. Leave one edge detail page open for at least 15 seconds. Expect **Last
   heartbeat** and the Cells/Endpoints readiness data to refresh without a
   browser reload. Restart only that edge agent container. Expect the heartbeat
   to become stale after the configured threshold and then recover within two
   successful five-second polls. Confirm the page remains a compact value grid
   without explanatory paragraphs below the fields.
9. Hover over every live edge field label and focus each label with the
   keyboard. Expect a short tooltip without an extra help icon or any layout
   change. Confirm the **Traffic listener** and **Gateway process**
   tooltips distinguish listener convergence from gateway process readiness.
   Confirm **Active configuration sequence** and **Gateway map revision**
   tooltips say these monotonic identities must not be reset. Confirm **Gateway
   routes** explains that it is the current destination-address plus Host/SNI
   protocol-map count, not a lifetime counter.

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

Agent-owned status on 2026-07-27: implementation, Go unit/scale tests, 162
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
| Implementation | Passed | [Gateway design and operation](operations/gateway-ingress.md) and administrator gateway state |
| Unit and feature tests | Passed | [CI run 30290594675](https://github.com/vaheed/CDNFoundry/actions/runs/30290594675) |
| Real-runtime E2E | Passed | [Gateway qualification evidence](operations/gateway-ingress.md#qualification-evidence) |
| IPv4 and IPv6 | Passed | Dual-stack and IPv4-only runtime evidence in the gateway qualification report |
| Scale | Passed | 50,000-map hardware, load, resource, latency, and saturation report |
| Failure and recovery | Passed | Invalid candidate, restart, outage, rollback, and last-valid runtime qualification |
| Isolation | Passed | Unknown route and target failure remain isolated in the runtime suite |
| Observability | Passed | [Metrics, alerts, and diagnostics](operations/gateway-ingress.md#monitoring-and-failures) |
| Documentation | Passed | User, administrator, architecture, deployment, operations, troubleshooting, and runbook checks |
| Manual qualification | Pending owner run | One gateway-detail screenshot accepted; all remaining baseline and Phase 1 checkpoints require owner evidence |
| Regression | Passed | CI backend/runtime E2E and completed-baseline non-browser regression stages |
| Release decision | Blocked | Awaiting the remaining owner-run manual browser qualification |

Phase 1 is complete only when every applicable gate is **Passed**. A missing UI,
failed configured IPv6 path, failed IPv4-only path, unexecuted scale run, or
unrecorded browser result keeps the phase incomplete.

## Phase 2 — Bounded cell inventory

Do not start this gate until Phase 1 is Passed. Record the edge UUID, configured
slot count, release SHA, host, browser, and timestamps. The shipped topology
uses eight slots.

### Fresh inventory and authorization

1. Sign in as an administrator and open **Edge network → Edges → New edge**.
   Enter a unique name, country, continent, public IPv4, optional public IPv6,
   and **Cell slots = 8**. Save and copy the one-time bootstrap token.
2. Open the new edge and its **Cells** relation. Expect exactly `cell-01`
   through `cell-08`, consecutive slot numbers, unique HTTP/HTTPS/status ports,
   unique runtime paths, and no extra row. Expect `cell-01` assigned to shared,
   `cell-02` assigned to quarantine, and `cell-03`–`cell-08` unassigned.
3. Attempt another edge with slot counts 0 and 33. Expect field validation and
   no edge, slot, token, task, or audit side effect. Create a disposable edge
   with one slot and expect exactly `cell-01`; drain, disable, and delete it.
4. Enroll the eight-slot edge. Refresh its detail page and Cells relation.
   Expect current enrollment, heartbeat, agent version, gateway readiness, and
   every running slot's ready/drained state and capacity. No bootstrap secret
   may reappear.
5. Sign in as a domain user and request the edge list/detail and cell API URLs.
   Expect denial without slot identity, assignment, address, capacity, path,
   resource, revision, or failure disclosure.

### Runtime controls and isolation

1. Record every cell's status, active revision, assigned domain count, active
   connections, CPU, memory, cache, temporary storage, and last restart.
   Expect CPU as a percentage, and memory/cache/temporary values in human-readable
   binary units rather than raw counters.
2. Drain `cell-02`. Expect one pending operation/task, then **Drained** only for
   that slot. Repeat the action with the same idempotency key through the API;
   expect the same operation and no duplicate task.
3. Undrain `cell-02`. Expect pending then ready. Restart `cell-02`; expect its
   restart timestamp/generation to advance after a bounded drain while the
   edge agent, gateway, `cell-01`, and `cell-03`–`cell-08` remain available.
4. Stop `cell-04` through the operator runtime fixture. Expect it to become
   stopped or degraded with a stable reason. Valid traffic targeting another
   cell must continue, gateway and agent readiness must remain, and unrelated
   revisions/capacity must not reset.
5. Saturate the disposable `cell-04` CPU and memory only up to its cgroup
   ceilings. Expect the container limit to hold and another cell's traffic and
   status to remain available. Record host and per-cell metrics.
6. Restore `cell-04`. Expect reconciliation to return it to the latest active
   revision without editing generated files or replaying unrelated cells.

### Recovery, bounds, and evidence

1. Restart the agent. Expect enrollment identity, mutual TLS, acknowledgements,
   active sequence, all eight slot files, drained controls, and last-valid
   snapshot recovery. No cell-engine socket may be mounted in the agent.
2. Make the control plane unavailable. Restart one cell and keep valid traffic
   on another. Expect local serving and previous valid state to continue; after
   restoration, expect convergence without duplicate activation.
3. Present an invalid slot mapping and invalid runtime candidate through the
   supported fixture. Expect rejection, bounded reason, and previous active
   state for every unrelated slot.
4. Confirm each cell has 512 MiB memory, 0.5 CPU, 128 PID, 256 MiB cache, 64 MiB
   request-temporary, and 16 MiB log ceilings. Fill each disposable storage area
   to its ceiling and expect bounded failure without host filesystem growth or
   another cell losing service.
5. Link the eight-slot agent-owned report with host/topology, idle and active
   overhead per slot, concurrency, workload, saturation result, accepted limit,
   crash isolation, restart, snapshot recovery, IPv4/IPv6, and baseline
   regression evidence.

### Phase 2 completion gate

Agent-owned implementation, PostgreSQL expand migration, 162 isolated Laravel
tests, Go format/vet/test/build, Compose validation, the cumulative non-browser
baseline/runtime regression, and the eight-slot overhead/isolation test passed
on 2026-07-27. The owner browser run above and the Phase 1 release gate must both
be Passed before changing this phase's release decision from **Blocked**.

| Gate | Result | Required evidence |
| --- | --- | --- |
| Implementation | Passed | [Bounded inventory design and operations](operations/cell-inventory.md) |
| Unit and feature tests | Passed | 162 Laravel tests / 1,280 assertions and Go format/vet/test/build |
| Real-runtime E2E | Passed | Eight-slot test plus enrollment, mTLS, snapshot, restart, and cumulative baseline runtime suite |
| IPv4 and IPv6 | Passed | Authoritative DNS and edge baseline dual-stack/IPv4-only evidence |
| Scale | Passed | Eight-slot idle/active overhead and isolation report |
| Failure and recovery | Passed | Control outage, restart, retry, rollback, and last-valid cumulative evidence |
| Isolation | Passed | `cell-04` stop left `cell-05` and support process ready |
| Observability | Pending owner run | Runtime metrics passed; cell state/capacity and alert screenshots remain owner evidence |
| Documentation | Passed | User, administrator, reference, deployment, operations, troubleshooting, and runbook checks |
| Manual qualification | Pending owner run | Every exact browser checkpoint above |
| Regression | Passed | Completed baseline and Phase 1 cumulative non-browser checks |
| Release decision | Blocked | Phase 1 and owner-run Phase 2 browser evidence are not Passed |

## Phase 3 — Multi-cell pools and stable placement

Use one enrolled edge with at least four running slots: three assigned to one
shared pool and one assigned to quarantine. Use three disposable proxied
domains with distinguishable origin markers. Do not change production traffic
for this checklist.

1. Sign in as administrator and open **Edge network → Service pools**. Create or
   edit the disposable shared pool. Expect kind, minimum ready cells, replicas
   per edge, maximum domains per cell, revision, and total participating cells.
2. Set minimum ready cells to `3`, replicas per edge to `1`, and a test-safe
   capacity. Open **Edge network → Edges**, edit the disposable edge, and use
   **Assign service pool** on three free cells. Select only the shared pool;
   there are no address fields on a cell. Expect one operation per assignment
   and stable cell names, ports, and runtime paths.
3. Attempt replicas `2` on shared and quarantine pools. Expect validation
   failure. On a reserved disposable pool, expect `2` or `3` to save; values
   above `3` must fail. Confirm dedicated placement cannot serve a second
   domain.
4. Open each test domain and choose **Move service pool**. Record domain,
   revision, active pool/cell, target pool/cell, operation ID, and timestamps.
   Expect normal domains to distribute across the three cells while each
   remains on one stable cell per edge.
5. Add an unrelated domain and an additional unused shared cell. Refresh all
   prior domains. Expect every valid prior assignment to remain unchanged.
6. Inspect the three cell runtime diagnostics and gateway routes. Each domain
   must appear only on its selected cell. The quarantine and unassigned cells
   must contain no test-domain artifact, certificate, Host route, or SNI route.
7. Start a move, keep the target cell unready, and send HTTP/HTTPS traffic.
   Expect the source marker throughout and visible deploying/degraded reason.
   Restore readiness and acknowledge the target. Expect the gateway to switch,
   then the source to remain during the bounded drain and disappear afterward.
   If a deploying reconciliation is interrupted for more than five minutes,
   expect the scheduler to requeue the same coalesced operation and converge.
8. Stop one shared target cell. Domains on the other two cells and quarantine
   traffic must continue. Restore it and expect last-valid state convergence
   without unrelated placement changes.
9. Repeat representative HTTP and strict HTTPS probes over IPv4 and configured
   IPv6. On an IPv4-only topology, leave IPv6 empty and expect readiness without
   a synthesized address.
10. Sign in as a domain user. Expect domain placement visibility only for
    assigned domains and denial from service-pool, cell-assignment, fleet
    capacity, and other-domain endpoints.

### Phase 3 completion gate

The coding-agent run must record implementation, automated/runtime, scale,
failure, isolation, documentation, and regression evidence below. The owner
records browser and real-traffic evidence after completing the exact steps
above. Until that row is Passed, the release decision remains **Blocked**.

| Gate | Result | Evidence |
| --- | --- | --- |
| Implementation | Passed | Stable per-edge cell state, pool policy, targeted artifacts, and cell-aware gateway maps; PostgreSQL expand migration applied in 209.95 ms |
| Automated/runtime qualification | Passed | 177 Laravel tests / 11,369 assertions; edge-agent Go test/build; strict dual-stack and IPv4-only gateway runtime; three-cell Host routing/isolation |
| Scale | Passed | 20,000 deterministic placements and 10,000 placement-affecting changes completed in 0.16 s with zero unnecessary reshuffles |
| Documentation | Passed | Concepts, API/OpenAPI, configuration, testing, operations, troubleshooting guidance, manual checklist, and roadmap |
| Manual browser and real traffic | Pending owner run | Steps 1–10 with screenshots, operation/revision IDs, and HTTP/HTTPS evidence |
| Release decision | Blocked | Owner-run evidence is mandatory |

## Phase 4 — Pool service endpoints and Geo-Unicast

Use two enrolled disposable edges. On the first edge assign three ready cells
to a shared pool and one ready cell to a reserved pool. Use service addresses
routed to the test gateways and distinct from every management address.

1. Sign in as administrator and open **Edge network → Edges → View → Pool
   endpoints**. Create a dual-stack endpoint for the shared pool. Enter its
   service IPv4 and IPv6. Expect `pending`, desired revision `1`, and no DNS
   publication before the gateway acknowledges it.
2. On the same edge create a different endpoint for the reserved pool. Attempt
   the shared IPv4, a management address, a private address, and an empty pair.
   Expect field validation and no desired revision or gateway change. Save a
   distinct valid pair.
3. Configure the second edge with an IPv4-only shared endpoint. On a disposable
   pool configure an IPv6-only endpoint. Expect all three family modes to save
   without synthesizing a missing address.
4. Set the shared pool minimum ready cells to `3`. Start all three cells and
   inspect the endpoint table. Expect gateway `ready`, active revision equal to
   or greater than desired revision, and readiness `ready`. Stop one cell;
   expect `insufficient_ready_cells` and only that endpoint to leave DNS.
5. Query the pool hostname from a country matching edge one, a continent-only
   location, and an unknown location. For A and AAAA separately, expect country,
   then continent, then global fallback and only ready endpoint addresses.
6. Send HTTP Host and strict HTTPS SNI traffic to both endpoint pairs. Expect
   the shared endpoint to distribute only across its three cells and the
   reserved endpoint to reach only its assigned cell. Unknown Host/SNI and an
   address/hostname conflict must be rejected before activation.
7. Withdraw the shared endpoint on edge one. Expect only that edge/pool pair to
   disappear; the reserved pair and edge-two shared pair continue. Restore it
   and expect publication only after a new gateway acknowledgement.
8. Edit a disposable dual-stack endpoint and clear IPv6. Expect IPv4 to remain
   and IPv6 to disappear after acknowledgement. Clearing the last address must
   fail. Turn on **Temporarily remove from traffic**, then delete the endpoint;
   expect the saved endpoint to disappear while its cells remain assigned.
9. Restart the gateway and agent while sending traffic. Expect the prior valid
   map to serve until the desired candidate validates, then gateway, DNS, pool,
   and cell state converge to the same revision without a broad domain rewrite.
10. Sign in as a domain user. Expect denial from endpoint CRUD, gateway candidate,
   fleet readiness, and management-address data.
11. Record topology, hardware, domain count, endpoint count, concurrent health
    changes, DNS reconciliation duration, CPU/memory, saturation point, accepted
    limit, revision IDs, and sanitized HTTP/HTTPS/DNS evidence for at least two
    edges and several pools.

### Phase 4 completion gate

| Gate | Result | Evidence |
| --- | --- | --- |
| Implementation | Passed | Revisioned edge/pool endpoints, unique address constraints, mTLS gateway candidate, readiness reasons, Geo-Unicast publication, API, and administrator UI |
| Unit and feature tests | Passed | 182 isolated Laravel tests / 11,388 assertions; edge-agent Go test/build |
| Real-runtime E2E | Passed | Two-edge mTLS control-plane and real PowerDNS test; placement migration reached revision 13 with zero obsolete artifacts |
| IPv4 and IPv6 | Passed | Feature coverage for IPv4-only, IPv6-only, and dual-stack endpoints plus real dual-stack DNS publication |
| Scale | Passed | Existing 20,000-domain / 10,000-change dataset plus bounded two-edge, two-pool endpoint reconciliation without a domain-wide rewrite |
| Failure, recovery, and isolation | Passed | Conflict rejection, readiness-gated publication, isolated edge/pool withdrawal, restoration, and last-valid gateway/DNS regression |
| Observability | Pending owner run | Endpoint state/reason and gateway revision screenshots plus runtime metrics |
| Documentation | Passed | Endpoint operations, topology, configuration, troubleshooting, and exact owner checklist |
| Manual browser and real traffic | Pending owner run | Steps 1–11 with screenshots, revisions, and traffic evidence |
| Regression | Passed | Full Laravel suite, Compose/OpenAPI/docs checks, edge-agent build, cache-control regression, and prior placement scale dataset |
| Release decision | Blocked | Owner-run evidence is mandatory |

## Phase 5 — Simple Anycast pools

Use two provider-approved POPs with the same routed IPv4 and, where available,
IPv6 service pair. Keep one Geo-Unicast pool and hostname active as an
unrelated comparison. Record provider ticket/change ID, prefix ownership,
route collectors, edge IDs, cell IDs, pool/endpoint revisions, gateway active
revisions, domain revisions, operation IDs, and UTC timestamps.

1. Sign in as administrator and open **Edge network → Service pools → New
   service pool**. Choose **Simple Anycast**. Hover or focus the relevant field
   labels and confirm the tooltips state
   that CDNFoundry binds and publishes addresses but does not announce or
   withdraw BGP routes. Confirm it also states that pool creation assigns no
   edge or cell, one distinct pair belongs to one pool, and **Shared** is the
   normal kind. Enter the approved IPv4 and optional IPv6 pair. After creation,
   confirm every existing edge has exactly the same assignments as before and
   no `edge.pool_provision` operation exists.
2. Attempt an empty pair, private/special address, management address, existing
   Geo-Unicast address, and address owned by another Anycast pool. Expect
   field-level rejection, no pool/revision, and no gateway or DNS change.
3. Use **Cells → Assign service pool** to assign the required slots on POP A
   and POP B. Expect the first assignment to create one participation record
   automatically on that edge with the inherited pair. Additional cells reuse
   it. Confirm the endpoint creation form lists only Geo-Unicast pools, and an
   unrelated edge consumes no slot and gains no participation record.
4. Enable the pool. Expect both gateways to receive the identical dual-stack
   pair, only their local assigned cells as targets, `pending` before local
   acknowledgement, and then pool route state `ready`. Unknown Host/SNI and
   conflicting address candidates must preserve the previous valid map.
5. Query the pool target and a disposable assigned domain through DNSdist over
   UDP/TCP from each supported family. Expect exactly the shared pair without
   country/continent data records. Confirm the comparison Geo-Unicast hostname
   still uses country, continent, then global fallback.
6. From at least three independent external vantage points (including one IPv6
   vantage when configured), record route origin/path, selected POP, HTTP Host,
   strict HTTPS SNI/certificate, origin marker, status, and latency. Expect the
   provider route—not CDNFoundry—to select a healthy POP.
7. Stop or drain POP A's participating cell/gateway without changing POP B.
   Expect pool `degraded`, POP B's candidate/revision and traffic unchanged,
   and DNS to retain the pair while POP B remains ready. Record route behavior
   from all vantage points and confirm unrelated Geo-Unicast traffic continues.
8. Through the network operator/provider workflow, withdraw the route at POP A
   and record ticket/change ID, exact request/effective timestamps, route
   collector evidence, traffic convergence, and accepted loss window. Restore
   it and record the same evidence. CDNFoundry must issue no router command and
   store no router credential.
9. Use the CDNFoundry pool **Withdraw** action. Expect gateway candidates and
   authoritative DNS publication to withdraw while the external route remains
   operator-owned. Coordinate provider withdrawal if required. Restore the
   pool, acknowledge both gateways, and expect publication and traffic only
   after local readiness returns.
10. Restart one agent and gateway. Inject one invalid candidate. Expect the
    previous valid map to serve, the other POP to remain unchanged, and the
    restarted POP to converge from desired state with clear reason/revision.
11. Record topology, provider, hardware, two-POP concurrent traffic, throughput,
    latency, CPU, memory, connection count, uplink utilization, saturation
    point, and accepted limit. Confirm the UI/runbook explicitly says Anycast
    is not upstream volumetric scrubbing and cannot protect a saturated uplink.
12. Sign in as a domain user. Expect only assigned-domain routing visibility
    and denial from pool pair, endpoint participation, edge candidate,
    readiness, and fleet capacity administration.
13. Create a disposable disabled pool and confirm **Delete** is available.
    Assign a cell and expect deletion to be blocked. For Anycast, unassign the
    final cell and expect its automatic participation record to disappear;
    then delete the empty pool. Expect an audit row and no effect on other
    pools, cells, endpoints, DNS, or gateway candidates.

### Phase 5 completion gate

| Gate | Result | Evidence |
| --- | --- | --- |
| Implementation | Passed | Pool-owned pair, explicit edge participation, shared gateway candidates, direct DNS publication, stable status reasons, authorization, audit, and last-valid reconciliation |
| Unit and feature tests | Passed | 191 isolated Laravel tests / 11,437 assertions, including 8 focused Anycast tests / 44 assertions |
| Real-runtime E2E | Passed | Two-edge mTLS gateway candidate and real PowerDNS withdrawal/restoration qualification; cache/placement regression reached revision 13 |
| IPv4 and IPv6 | Passed | Dual-stack Anycast and Geo-Unicast runtime plus IPv4-only automated gateway/endpoint regression |
| Scale and external network evidence | Pending owner run | Two approved POPs, at least three external vantage points, provider route evidence, load/saturation measurements |
| Failure, recovery, and isolation | Partially passed; owner network run pending | Controlled POP loss, gateway acknowledgement race recovery, unrelated POP state, invalid-candidate/last-valid regression passed; provider route withdrawal/restoration is owner-run |
| Observability | Pending owner run | Ready/degraded/withdrawn UI, gateway revisions/reasons, route collectors, metrics, logs, alerts |
| Documentation | Passed | Administrator UI guidance, operations runbook, architecture/troubleshooting links, exact owner checklist |
| Manual browser and real traffic | Pending owner run | Steps 1–13 with screenshots, revisions, provider evidence, and traffic captures |
| Regression | Passed | Full Laravel suite, Compose/OpenAPI/docs checks, edge-agent and edge-gateway Go test/build images, real cache and placement regression |
| Release decision | Blocked | Owner-operated BGP and external vantage evidence is mandatory and cannot be executed by the coding agent |

## Phase 6 — Persistent bounded cache

Use a disposable proxied domain in a pool with at least two ready cells. Record
the pool, placement, edge, cell, domain revision, cache epoch, storage quota,
origin request counter, and all operation/task IDs.

1. As administrator, open **Edge network → Service pools**, edit the disposable
   pool, and verify **Cache profile** offers Small, Standard, Large, and
   Streaming. Save Small. Expect one pool revision increment and one coalesced
   global edge reconciliation. Confirm no new cell, process, directory, timer,
   or container is created.
2. Open the domain and choose **Cache → Cache settings**. Verify enabled, edge
   and browser TTL, maximum object, origin-header policy, four query policies,
   selected query names, bypass cookies, approved status TTL map, admission
   count, stale-if-error, stale-while-revalidate, serving mode, and variant
   ceiling. Save include-selected with `page` and `lang`, 200=`60`, 404=`15`,
   admission=`2`, both stale windows=`10`, normal mode, and variants=`8`.
   Expect `202`, a domain revision increment, audit row, and target-first
   delivery to participating cells only.
3. Attempt 33 query names, status `418`, admission `0` and `11`, variant `0`
   and `129`, stale values above `86400`, and an object larger than 1 GiB.
   Expect typed validation with no revision, artifact, task, or audit side
   effect. Repeat one valid mutation with the same idempotency key and expect
   the original response; reuse it with different input and expect conflict.
4. Request `/asset?page=2&ignored=a&lang=fa` twice, then reorder the query and
   change only `ignored`. Expect one MISS followed by HITs. Change `page` and
   expect MISS. Switch to ignore-all and expect all query variants to share one
   key. Confirm an exact URL purge uses the same normalized key.
5. Request a cacheable 200 twice and expect MISS then HIT. Request a configured
   404 twice and expect MISS then HIT for 15 seconds. Confirm an unapproved
   status, authorization, configured cookie, Set-Cookie, private/no-store,
   unsupported Vary, oversized object, and range request bypass or do not
   store. Confirm the second request requirement prevents one-hit pollution.
6. Generate more than eight query variants in one minute. Expect later variants
   to bypass admission while an already-resident object remains a HIT. Drive
   cache admissions above the Small profile ceiling and fill its quota/minimum
   free reserve. Expect bounded bypass/eviction, no unbounded temporary growth,
   and unrelated-domain traffic on the other cell to continue.
7. Seed an object, restart its cell container normally, and request it again.
   Expect HIT from the persistent per-cell volume. Replace only the disposable
   cache volume and expect a safe MISS/rebuild without desired-state loss.
   Confirm no other cell volume or cache object changed.
8. Stop the origin after a one-second TTL. Within the configured windows expect
   stale-while-revalidate or stale-if-error service and bounded origin attempts.
   After expiry expect failure, not indefinite stale. Set Cache only, then
   Stale only: resident content remains available and an origin-bound miss
   returns 503 with `cache_mode_origin_disabled`. Restore Normal and the origin.
9. Purge one URL and confirm durable per-edge tasks, acknowledgements, and a
   MISS only for that key. Full purge and confirm one epoch increment, no disk
   scan, and MISS across participating cells. Interrupt one delivery, retry the
   same task, restart the agent, and confirm convergence without duplicate
   epochs or loss of the previous valid artifact.
10. Record mixed HIT/MISS load throughput, p50/p95/p99 latency, hit ratio, CPU,
    memory, IOPS, disk used/free, temporary bytes, origin requests, purge
    fan-out time, high-cardinality bypasses, saturation point, and accepted
    limit. Run IPv4 and configured IPv6 traffic plus the documented IPv4-only
    topology. Confirm telemetry failure never stops cache service.

### Phase 6 completion gate

| Gate | Result | Evidence |
| --- | --- | --- |
| Implementation | Passed | Persistent per-cell volumes, four profiles, typed domain policy, deterministic keys, TTL/admission/object/range/variant bounds, stale modes, and durable purge |
| Unit and feature tests | Passed | 194 isolated Laravel tests / 11,472 assertions plus Pint |
| Real-runtime E2E | Passed | OpenResty runtime covers persistence, query normalization, status TTL, stale, bounds, purge, restart, invalid-candidate last-valid state, and isolation |
| IPv4 and IPv6 | Partially passed; owner run pending | Cumulative DNS dual-stack passed; owner external cache traffic and IPv4-only evidence required |
| Scale | Pending owner load run | Exact metrics and accepted saturation limit from step 10 |
| Failure, recovery, and isolation | Partially passed; owner run pending | Automated restart, origin failure, last-valid, purge retry, and cell isolation passed; owner disk-pressure evidence remains |
| Observability | Pending owner run | UI state, cell capacity, logs, metrics, alerts, and stable reason captures |
| Documentation | Passed | Cache guide, API/reference, operations, troubleshooting, architecture, and this exact checklist |
| Manual qualification | Pending owner run | Steps 1–10; coding agents do not run browser automation |
| Regression | Passed | Full cumulative non-browser E2E passes foundation, dual-stack DNS, Geo-DNS, two-edge control plane through revision 14 with zero obsolete artifacts, mTLS, TLS, security, analytics outage recovery, operations recovery, and OpenResty cache runtime |
| Release decision | Blocked | Owner browser, external load, and disk-pressure evidence remain mandatory |

## Phase 7 — Gzip and Brotli compression

Use a disposable proxied domain in a ready shared pool and a second domain in
a ready reserved or dedicated pool. Serve a text/JSON object larger than 1 KiB,
an image, a 12 MiB text object, a range-capable object, an ETag response, and a
one-second cacheable response that can be served stale. Record pool/domain
revisions, cell identity, cache status, encoding, byte counts, CPU, latency,
and operation IDs.

1. As administrator, open **Edge network → Service pools**, edit the shared
   pool, and confirm **Compression profile** offers Off, Standard, and Maximum
   savings with explanatory help. Select Maximum savings and expect field-level
   rejection with no revision, artifact, audit, or task. Save Standard and
   expect one pool revision plus one coalesced asynchronous reconciliation.
2. Edit the reserved/dedicated pool, select Maximum savings, and save. Expect
   `202`, an operation ID, revisioned artifacts only for participating cells,
   acknowledgement before success, and no new process, container, timer,
   server block, or cache directory.
3. Request the same eligible object with `Accept-Encoding: identity`, `gzip`,
   and `br`. Decode each and compare hashes. Expect identical content, Gzip for
   Standard, Brotli preference for Maximum savings, `Vary: Accept-Encoding`,
   and one MISS followed by HITs without extra cache objects.
4. Repeat with quality values, unsupported encodings, HEAD, conditional
   `If-None-Match`, and origin 304 revalidation. Expect correct identity
   fallback, no body for HEAD/304, stable validators, and no representation
   corruption.
5. Request the image, archive, sub-1-KiB response, 12 MiB response, and byte
   range. Expect identity; the range must remain 206 with correct
   `Content-Range`. Confirm ordinary cache, stale-if-error, exact purge, epoch
   purge, and origin-header behavior remains unchanged.
6. Drive more than 16 concurrent eligible responses on the maximum-savings
   cell and more than 32 on Standard. Expect excess work to receive identity
   with `cpu_pressure_identity`, while every response succeeds and unrelated
   domains/cells retain normal latency and encoding.
7. Set `EDGE_COMPRESSION_DISABLED=1` on one canary cell and replace only that
   cell. Expect identity plus `emergency_disabled` without a serving outage.
   Remove it and expect configured encoding to resume. Then save pool profile
   Off and verify the durable asynchronous fleet-wide path.
8. Open **Observe → Analytics and logs** as administrator and domain user.
   Query no more than 24 hours. Compare request captures with encoding,
   delivered bytes, identity estimate, bytes saved, ratio, profile, and
   fallback. Expect accurate totals and domain-user isolation. Stop ClickHouse
   briefly and confirm traffic continues while analytics reports unavailable.
9. Run mixed identity/Gzip/Brotli HIT/MISS load over IPv4 and configured IPv6,
   plus the documented IPv4-only topology. Record dataset, hardware,
   concurrency, throughput, p50/p95/p99 latency, bytes saved, CPU, memory,
   saturation point, fallback count, and accepted limit.
10. Restart the cell and inject an invalid runtime artifact. Expect the prior
    valid compression/cache policy to keep serving. Restore desired state,
    confirm convergence and telemetry, and run the cumulative non-browser
    regression.

### Phase 7 completion gate

| Gate | Result | Evidence |
| --- | --- | --- |
| Implementation | Passed | Pool policy, PostgreSQL constraints, revisioned artifacts, canonical identity cache, pinned Brotli image, bounded filters, pressure/emergency fallback, telemetry, authorization, audit, and last-valid delivery |
| Unit and feature tests | Passed | 201 isolated Laravel tests / 11,513 assertions, including policy/API/artifact/analytics and ACME JWK coordinate coverage, plus Pint |
| Real-runtime E2E | Passed | Identity/Gzip/Brotli content, canonical HIT, range, pressure/emergency fallback, restart, stale, purge, invalid candidate, and real Vector/ClickHouse analytics |
| IPv4 and IPv6 | Partially passed; owner run pending | Local IPv4/IPv6 listener and cumulative dual-stack DNS passed; owner external compression traffic and IPv4-only evidence required |
| Scale | Pending owner load run | Exact measurements from step 9 |
| Failure, recovery, and isolation | Partially passed; owner run pending | Automated pressure, emergency, restart, invalid-candidate, telemetry outage, and unrelated-cell checks passed; owner saturation remains |
| Observability | Partially passed; owner run pending | Real encoding/bytes/ratio/profile/fallback events and analytics passed; owner UI/alert capture remains |
| Documentation | Passed | Compression guide, cache/analytics/telemetry/upgrade references, and this exact checklist |
| Manual qualification | Pending owner run | Steps 1–10; coding agents do not run browser automation |
| Regression | Passed | Foundation, DNS, Geo-DNS, two-edge control plane through revision 14 with zero obsolete artifacts, mTLS, TLS, security, analytics outage recovery, operations recovery, and compression/cache runtime |
| Release decision | Blocked | Owner browser, external load, CPU saturation, and external IPv4/IPv6 evidence remain mandatory |

## Phase 8 — Primary and backup origin failover

Use one disposable proxied hostname with distinguishable primary and backup
responses, one cacheable one-second object, and one unrelated proxied hostname.
Record the domain/revision, pool/cell, origin request counters, transition
headers, operation IDs, and timestamps. The two origins must be independently
stoppable and must pass the normal public-destination safety policy.

1. As the domain owner, open the proxied record in **DNS records**. Enable
   **Backup origin** and enter backup hostname/IP, scheme, Host header, TLS SNI
   and verification, connect/response timeouts, failure threshold, recovery
   threshold, hold-down, and failback delay. Save. Expect one desired revision,
   one coalesced asynchronous deployment, and no new process, container,
   server block, cache directory, worker, or timer.
2. Enter loopback, link-local, metadata, platform-listener, proxy-loop, invalid
   TLS, identical-primary, and out-of-range policy values. Expect field-level
   rejection with no revision, artifact, audit success, or runtime change.
   Confirm an unassigned domain user cannot view or mutate either origin.
3. Choose **Test origin**, then **Test backup**. Expect separate asynchronous
   operation IDs and bounded results from selected ready edges. Confirm the
   result contains status/latency but does not disclose credentials or private
   keys.
4. Request uncached paths while both origins are healthy. Expect the primary
   marker and `X-CDNFoundry-Origin: primary`. Confirm analytics stores
   `origin_role=primary` and a bounded transition reason.
5. Stop the primary and send concurrent MISS traffic. Before the configured
   failure threshold expect bounded failures; afterward expect the backup
   marker, `X-CDNFoundry-Origin: backup`, and
   `primary_failure_threshold`. Record transition time, origin pressure,
   errors, CPU, memory, and p50/p95/p99 latency.
6. Keep the primary unavailable past the hold-down and verify traffic remains
   stable on backup. Disconnect Laravel/Redis from the cell network and repeat
   requests. Expect local failover to continue without a control-plane call.
7. Restore primary. Before failback delay expect backup. After the delay expect
   primary probes and only return to stable primary after the recovery
   threshold. Interrupt recovery once and confirm the success count resets
   rather than flapping.
8. Seed the one-second cache object, then stop both origins after it expires.
   Within stale-if-error expect `STALE`; after the stale window expect a bounded
   upstream or configured maintenance failure. Confirm attempts do not form a
   retry storm.
9. While both origins fail, load the unrelated hostname and a second cell.
   Expect normal origin connection budgets, latency, and service. Inject an
   invalid backup artifact and confirm the prior valid primary/backup state
   remains active.
10. Run controlled HIT/MISS failover and recovery load over external IPv4 and
    configured IPv6, plus the documented IPv4-only topology. Record hardware,
    concurrency, throughput, transition time, p50/p95/p99, primary/backup
    pressure, errors, stale responses, CPU, memory, saturation point, and the
    accepted operating limit.

### Phase 8 completion gate

| Gate | Result | Evidence |
| --- | --- | --- |
| Implementation | Passed | One validated backup, bounded policy, revisioned artifacts, local cell state, role-specific tests, stale precedence, diagnostics, and telemetry |
| Unit and feature tests | Passed | 205 isolated Laravel tests / 11,548 assertions cover authorization, validation, idempotency conflict, atomic preservation, IPv4/IPv6 artifact, telemetry presentation, live queue accounting, and safety envelopes; Pint passes 311 files |
| Real-runtime E2E | Passed | Primary, threshold failover, 24 concurrent backup requests, hold-down, delayed threshold recovery, dual failure stale, bounded error, and unrelated-host isolation |
| IPv4 and IPv6 | Partially passed; owner run pending | IPv4 and IPv6 backup state compiles; owner external traffic and IPv4-only topology evidence required |
| Scale | Pending owner load run | Exact measurements and accepted saturation limit from steps 5 and 10 |
| Failure, recovery, and isolation | Partially passed; owner run pending | Automated local transitions, stale, bounded failure, and unrelated-host isolation passed; owner control-plane partition and external saturation remain |
| Observability | Partially passed; owner run pending | Runtime headers, authenticated active-role status, and a real backup/primary-failure Vector-to-ClickHouse event passed; owner UI and alert captures remain |
| Documentation | Passed | Origin guide, telemetry, upgrade instructions, roadmap evidence, and this exact checklist |
| Manual qualification | Pending owner run | Steps 1–10; coding agents do not run browser automation |
| Regression | Passed | Full isolated suite, cumulative foundation/DNS/Geo-DNS/control-plane/mTLS/TLS/security/analytics/operations E2E, established and Phase 8 OpenResty runtime, Compose, OpenAPI, Vector, and docs checks |
| Release decision | Blocked | Owner browser, external load/saturation, control-plane partition, and external IPv4/IPv6 evidence remain mandatory |

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
