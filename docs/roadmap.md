---
title: CDNFoundry next development roadmap
description: Ordered post-baseline roadmap for the new edge, cache, origin-resilience, WAF, and fleet capabilities.
---

# CDNFoundry next development roadmap

## Current boundary

The original production platform roadmap is complete and is not repeated here.
The existing Laravel control plane, authoritative DNS, Geo-DNS, proxy, TLS,
baseline cache and purge, security controls, telemetry, analytics, backup, and
operations behavior are the starting baseline for this roadmap.

This document contains only the **new development work** agreed after completion
of that baseline.

Every new phase must:

- extend the existing product without rewriting it;
- remain deployable and testable when the phase is finished;
- preserve all previously working behavior;
- stay simple to develop and operate;
- use bounded resources and explicit failure behavior;
- include tests, scale evidence, documentation, and owner-run browser qualification.

## Product direction

CDNFoundry remains a small, solid private CDN for operators and ISPs. It is not
trying to become Cloudflare, Fastly, Akamai, or a general cloud platform.

The next development cycle focuses on five practical outcomes:

1. multiple isolated OpenResty cells on one edge;
2. multiple public IPv4/IPv6 service pairs on the same edge;
3. Geo-Unicast and Simple Anycast pool routing;
4. stronger cache, compression, origin resilience, and managed WAF behavior;
5. safe operation and upgrades of the expanded edge fleet.

## Non-negotiable boundaries

1. Laravel and Filament remain the single management/control plane.
2. PostgreSQL remains the durable desired-state source of truth.
3. DNS, HTTP, HTTPS, TLS selection, cache decisions, WAF decisions, and raw
   telemetry never pass through Laravel.
4. The edge gateway is a small data-plane router. It does not cache, terminate
   customer TLS, run WAF logic, call Laravel, or query a database.
5. OpenResty cells perform TLS, cache, security, WAF when enabled, origin proxy,
   and request telemetry.
6. Cell containers are created as bounded generic slots during edge installation.
   The edge agent assigns and configures them; it does not receive unrestricted
   Docker or containerd access.
7. A domain does not receive a default container, process, worker, timer, cache
   directory, server block, or reload.
8. Pools may use many cells on one edge. A public IPv4/IPv6 pair belongs to a
   pool endpoint, not necessarily to one cell.
9. Simple Anycast uses operator/provider routing. CDNFoundry does not become a
   BGP controller and does not require FRR, BIRD, or direct router access.
10. External effects remain asynchronous, revisioned, idempotent, coalesced,
    acknowledged, and last-valid-state preserving.
11. No custom WAF language, arbitrary ModSecurity directives, user scripts,
    plugin runtime, or downloaded runtime rules are introduced.
12. No microservices, Kafka, Kubernetes requirement, CQRS, event sourcing,
    GraphQL, custom RBAC, reseller hierarchy, billing engine, or second dashboard.
13. No unlimited claims. Every scale result records topology, hardware,
    concurrency, dataset, saturation point, and accepted limits.
14. Development PostgreSQL and named Compose volumes remain persistent. Tests
    use the repository's isolated test environment and never destructively reset
    persistent development data.
15. Roadmap phase names and numbers never appear in production class names,
    filenames, routes, migrations, tables, or configuration keys.

## Completion gate for every phase

A phase is complete only when every applicable row is recorded independently.

| Gate | Required evidence |
| --- | --- |
| Implementation | Durable state, migrations, policies, API, UI where required, jobs, reconciliation, audit, metrics, and rollback behavior |
| Unit and feature tests | Happy path, authorization, validation, bounds, idempotency, stable errors, and backward compatibility |
| Real-runtime E2E | Real HTTP/HTTPS/DNS/cache/TLS/WAF/runtime behavior; mocks alone are insufficient |
| IPv4 and IPv6 | Both families pass whenever the phase handles addressing or traffic |
| Scale checkpoint | Dataset, topology, hardware, concurrency, measured result, saturation point, and accepted limit |
| Failure and recovery | Restart, dependency outage, invalid candidate, retry, obsolete work, rollback, and last-valid state |
| Isolation | Failure or load in one domain, pool, cell, edge, or component does not unnecessarily affect unrelated traffic |
| Observability | Metrics, logs, reason codes, alerts, health, capacity, and partial/degraded state |
| Documentation | User/admin guide, API/OpenAPI, architecture, deployment, operations, troubleshooting, and runbooks |
| Manual qualification | Owner-run checkpoints in `docs/manual-browser-qualification.md` |
| Regression | Existing completed platform behavior remains functional |
| Release decision | Passed, failed, blocked, or deliberately removed from scope |

A checkbox cannot be marked complete when the related test was not executed.
Every phase must finish as a usable production increment; no phase may leave the
active serving path dependent on unfinished work from a later phase.

# Committed development roadmap

## Phase 1 — Edge gateway ingress

### Goal

Introduce one minimal gateway on every edge so one server can bind multiple
public IPv4/IPv6 service pairs and route traffic to bounded OpenResty cells.

### Implementation

- Add one gateway process/container per edge.
- Bind one or more operator-configured public IPv4 and IPv6 service addresses.
- Route HTTP by destination address and validated `Host`.
- Route HTTPS by destination address and TLS SNI without terminating customer TLS.
- Forward the trusted client address to cells through a qualified PROXY protocol
  or equivalent fixed internal contract.
- Reject unknown destination addresses, hosts, and SNI names early.
- Load a generated local routing map atomically.
- Keep and restore the previous valid map after invalid configuration or restart.
- Expose gateway readiness, active revision, listener state, connection totals,
  routing failures, and per-endpoint health.
- Keep the gateway free of cache, certificates, origin logic, WAF rules, Lua
  business logic, and control-plane network calls.

### Scale checkpoint

- At least 50,000 hostname/SNI mappings in one generated map.
- Multiple IPv4 and IPv6 service pairs on one edge.
- Measured HTTP and TLS pass-through throughput with gateway CPU, memory,
  connections, latency, and saturation recorded.

### Completion checklist

- [ ] HTTP Host and HTTPS SNI route to the intended backend cell.
- [ ] IPv4 and IPv6 listeners pass real traffic.
- [ ] Unknown Host/SNI and unassigned destination addresses are rejected.
- [ ] Real client address reaches the cell through the trusted internal contract.
- [ ] Invalid or partial maps never replace the active map.
- [ ] Gateway restart preserves or reconstructs the last valid routing state.
- [ ] Gateway failure is visible and does not corrupt cell runtime state.
- [ ] Existing direct edge behavior remains available during controlled migration.
- [ ] Tests, scale evidence, metrics, alerts, docs, and manual qualification pass.

## Phase 2 — Bounded cell inventory and edge installation

### Goal

Replace the fixed shared/quarantine runtime assumption with a bounded inventory
of generic OpenResty cell slots created during edge installation.

### Implementation

- Add an installation setting such as a bounded cell-slot count.
- Generate/start named generic slots such as `cell-01` through `cell-N`.
- Give every slot stable identity, internal ports, runtime path, cache path,
  temporary path, status endpoint, and resource limits.
- Allow unused slots to remain unassigned and idle or stopped according to a
  documented installation policy.
- Register the full static inventory with the edge agent.
- Remove readiness hardcoding tied to `shared-default`.
- Let the agent configure, reload, drain, restart, and report existing slots.
- Do not mount the Docker socket into the edge agent.
- Keep agent resources separate from cell resource groups.
- Support safe cell image compatibility and last-valid runtime state.

### Scale checkpoint

- Qualify at least 8 cell slots on one edge.
- Record idle overhead and active overhead per cell.
- Prove one saturated or crashed cell does not terminate the agent, gateway, or
  unrelated cells.

### Completion checklist

- [ ] Fresh edge installation creates exactly the configured bounded slots.
- [ ] Every slot has unique identity, paths, ports, health, and resource limits.
- [ ] Agent reports assigned, unassigned, ready, degraded, drained, and stopped states.
- [ ] Agent operates without unrestricted container-engine access.
- [ ] Cell restart and image rollback preserve unrelated traffic.
- [ ] Cache/temp/log storage cannot exceed configured quotas.
- [ ] Existing edge enrollment, mTLS, rotation, and snapshot recovery still pass.
- [ ] Tests, scale evidence, docs, and manual qualification pass.

## Phase 3 — Multi-cell pools and stable domain placement

### Goal

Allow one pool to use multiple cells on the same edge while preserving stable
cache locality and predictable failure isolation.

### Implementation

- Support pool kinds:
  - `shared` for unrelated normal domains;
  - `reserved` for one customer's or workload group's domains;
  - `dedicated` for one exceptional domain;
  - `quarantine` for attacked or unstable domains.
- Separate stable cell-slot identity from pool identity.
- Add explicit edge participation and cell assignment per pool.
- Allow multiple cells from one edge to belong to one pool.
- Add minimum-ready-cell and capacity policy per pool/edge participation.
- Place each normal domain on one stable active cell inside its pool by default.
- Allow exceptional replicated placement only as an explicit bounded mode.
- Move domains target-first: configure target, verify readiness, switch gateway,
  then drain and remove source state.
- Deliver artifacts only to active and target participating cells/edges.
- Prevent one dedicated pool from accepting multiple domains.
- Preserve previous placement and gateway state after failed migration.

### Scale checkpoint

- At least 20,000 domains distributed across multiple cells without unnecessary
  reshuffling after adding a domain or a new cell.
- Controlled burst of at least 10,000 placement-affecting changes with coalescing.
- Record compiler time, artifact size, gateway-map activation time, and database
  query behavior.

### Completion checklist

- [ ] One shared pool uses at least three cells on one edge.
- [ ] Domain placement remains stable across unrelated changes.
- [ ] Reserved, dedicated, and quarantine constraints are enforced.
- [ ] Failed target readiness leaves source placement active.
- [ ] Successful movement drains and removes the old assignment safely.
- [ ] Artifacts are not sent to non-participating edges or cells.
- [ ] Pool readiness counts all required cells rather than one arbitrary cell.
- [ ] Unrelated domain/cache traffic remains healthy during movement.
- [ ] Tests, scale evidence, docs, and manual qualification pass.

## Phase 4 — Pool service endpoints and Geo-Unicast

### Goal

Support multiple public service IP pairs on one edge, with each pair serving one
pool endpoint backed by one or more cells.

### Implementation

- Add a pool endpoint per participating edge with:
  - public IPv4;
  - optional public IPv6;
  - listener/gateway state;
  - participating cells;
  - routing mode `geo_unicast`;
  - enabled, drained, withdrawn, and readiness state.
- Allow one edge to expose, for example:
  - one IPv4/IPv6 pair for three shared cells;
  - another pair for one reserved customer pool;
  - another pair for quarantine.
- Publish only ready, non-withdrawn pool endpoints into system-managed DNS.
- Preserve country, continent, and global fallback behavior.
- Keep management addresses separate from service endpoints.
- Prevent duplicate/conflicting address ownership.
- Reconcile gateway bindings, pool readiness, and DNS publication as one
  revisioned workflow with last-valid state.

### Scale checkpoint

- Multiple pools and service pairs on each of at least two edges.
- Measured DNS publication and gateway reconciliation after endpoint health
  changes without rewriting every domain.

### Completion checklist

- [ ] Three shared cells serve one IPv4/IPv6 pair on one edge.
- [ ] A reserved customer pool serves a different pair on the same edge.
- [ ] DNS returns the correct ready endpoint set for each pool.
- [ ] Withdrawal of one endpoint does not change unrelated pools.
- [ ] IPv4-only, IPv6-only, and dual-stack endpoints behave correctly.
- [ ] Address conflicts and invalid bindings fail before activation.
- [ ] Gateway, DNS, placement, and cell state converge after restart/reconcile.
- [ ] Tests, scale evidence, docs, and manual qualification pass.

## Phase 5 — Simple Anycast pools

### Goal

Allow selected pools to use one shared IPv4/IPv6 pair across multiple POPs while
keeping route announcement outside CDNFoundry.

### Implementation

- Add routing mode `simple_anycast` to eligible pools.
- Store one pool-level IPv4 and optional IPv6 service pair.
- Attach explicit participating POPs/edges and readiness requirements.
- Bind the same service pair on every participating edge gateway.
- Publish the same Anycast pair for domains assigned to the pool.
- Expose a clear readiness/withdrawal signal for the operator or upstream routing
  system.
- Document that the network/provider owns BGP advertisement and withdrawal.
- Do not add FRR, BIRD, router credentials, arbitrary routing commands, or a BGP
  control plane to CDNFoundry.
- Preserve Geo-Unicast pools on the same fleet.

### Scale and network checkpoint

- At least two real or approved lab POPs using the same IPv4/IPv6 pair.
- Traffic observations from multiple external vantage points.
- Controlled POP loss and restoration with route behavior recorded by the
  operator/provider.
- Gateway and cell capacity limits recorded independently per POP.

### Completion checklist

- [ ] One Anycast pool uses the same service pair on multiple POPs.
- [ ] Geo-Unicast and Anycast pools coexist on the same edges.
- [ ] CDNFoundry exposes honest ready/degraded/withdrawn state.
- [ ] POP failure does not corrupt another POP's local serving state.
- [ ] External route withdrawal/restoration is qualified and documented.
- [ ] UI/docs never claim CDNFoundry itself announces BGP routes.
- [ ] Physical-uplink and upstream-scrubbing limitations remain explicit.
- [ ] Tests, network evidence, docs, and manual qualification pass.

## Phase 6 — Cache v2 storage and cache-key policy

### Goal

Turn the existing basic cache into a persistent, bounded, production-strength
cell cache without adding per-domain cache directories or a distributed cache.

### Implementation

- Use persistent per-cell cache volumes with explicit maximum size, inactive
  duration, temporary-storage quota, and minimum-free-space policy.
- Add a small set of pool resource profiles such as `small`, `standard`, `large`,
  and `streaming`.
- Keep domain placement stable so a domain normally reaches one cell/cache on an
  edge.
- Add bounded cache-key query policies:
  - include all parameters;
  - ignore all parameters;
  - include selected names;
  - ignore selected names.
- Preserve deterministic Host, path, scheme, query, and cache-epoch behavior.
- Add bounded TTL policy for approved status codes such as 200/206, redirects,
  and optional short negative caching.
- Add cache admission protection:
  - per-domain admission rate;
  - cache-key and query-variant limits;
  - maximum cacheable object size;
  - optional minimum cacheable object size;
  - low-disk bypass;
  - range and high-cardinality protections.
- Add explicit stale modes:
  - off;
  - stale-if-error;
  - stale-while-revalidate;
  - cache-only emergency;
  - stale-only emergency.
- Preserve epoch full purge and exact URL purge.

### Scale checkpoint

- Cache-hit and cache-miss load at declared object-size distributions.
- Cache quota pressure, low-disk behavior, restart persistence, purge fan-out,
  and high-cardinality abuse.
- Record throughput, latency, disk IOPS, disk usage, memory, CPU, and hit ratio.

### Completion checklist

- [ ] Cache survives routine cell restart and remains rebuildable after loss.
- [ ] Pool profiles enforce disk/temp/object/admission ceilings.
- [ ] Query policies produce deterministic keys without unbounded variants.
- [ ] TTL and status-code behavior match configured policy.
- [ ] Stale modes behave correctly during origin failure.
- [ ] Low disk and cache abuse bypass safely without filling the host.
- [ ] URL/full purge remains durable and bounded across participating cells.
- [ ] One domain cannot evict or fill unrelated cell caches beyond accepted limits.
- [ ] Tests, load evidence, docs, and manual qualification pass.

## Phase 7 — Gzip, Brotli, and compressed delivery

### Goal

Reduce customer bandwidth with safe response compression integrated with Cache
v2 and bounded by cell/pool resources.

### Implementation

- Store one canonical uncompressed cache object by default.
- Request identity encoding from origins where required for deterministic cache
  behavior.
- Enable Gzip as the default broadly compatible compression method.
- Add optional Brotli through one immutable, pinned, tested edge image/module.
- Expose simple profiles only:
  - `off`;
  - `standard`;
  - `maximum_savings` for reserved/dedicated pools.
- Use a tested MIME-type allowlist and minimum response size.
- Avoid recompressing JPEG, PNG, WebP, AVIF, video, archives, and other already
  compressed formats.
- Handle `Accept-Encoding`, `Vary`, ETag/revalidation, HEAD, 304, stale, purge,
  and identity fallback correctly.
- Disable or bound on-the-fly compression for range traffic and large responses.
- Add per-cell compression concurrency, CPU-pressure fallback, and emergency
  disable behavior.
- Emit encoding, origin bytes, uncompressed bytes, served bytes, compression
  ratio, profile, and fallback telemetry.

### Scale checkpoint

- Mixed identity/Gzip/Brotli clients against cache HIT and MISS traffic.
- Compressible and non-compressible object distributions.
- CPU saturation, concurrency limit, fallback, and large-response behavior.
- Record bandwidth saved, latency, throughput, and CPU cost per profile.

### Completion checklist

- [ ] Identity, Gzip, and Brotli responses decode to identical content.
- [ ] One canonical cached object serves different client encodings correctly.
- [ ] Already-compressed and range responses follow safe policy.
- [ ] `Vary`, ETag, revalidation, stale, and purge behavior remain correct.
- [ ] Shared pools cannot select unsafe compression levels.
- [ ] CPU pressure falls back or disables compression without stopping traffic.
- [ ] Compression telemetry and bandwidth-savings analytics are accurate.
- [ ] Tests, load evidence, docs, and manual qualification pass.

## Phase 8 — Primary and backup origin failover

### Goal

Remove the single-origin availability weakness with one simple active-passive
backup origin per proxied hostname.

### Implementation

- Support one primary and one optional backup origin.
- Reuse the same strict origin-address, TLS, header, timeout, and loop validation.
- Use bounded health checks and request-path failure evidence.
- Add failure threshold, recovery threshold, hold-down, and failback delay.
- Keep active origin state local to cells and last-valid during control-plane loss.
- Fail over without a Laravel request-path call.
- Use stale/cache-only behavior before unnecessary origin retry storms.
- Expose active origin, transition reason, timestamps, and health without secrets.
- Do not add weighted balancing, traffic percentages, geographic origin steering,
  service discovery, or arbitrary origin pools.

### Scale checkpoint

- Controlled primary failure and recovery under concurrent cache HIT/MISS traffic.
- Record failover time, failback time, origin connection pressure, errors, and
  unrelated-domain impact.

### Completion checklist

- [ ] Healthy primary receives normal origin traffic.
- [ ] Qualified primary failure moves traffic to backup within policy.
- [ ] Recovery uses hysteresis and does not flap.
- [ ] Both-origin failure follows stale/cache-only/maintenance policy.
- [ ] Invalid backup configuration never replaces valid primary state.
- [ ] Control-plane outage does not remove local failover behavior.
- [ ] One failing origin cannot exhaust unrelated origin budgets.
- [ ] Tests, load evidence, docs, and manual qualification pass.

## Phase 9 — Managed OWASP CRS WAF

### Goal

Add optional managed application-signature protection without building a custom
WAF language or exposing raw ModSecurity configuration.

### Implementation

- Use a pinned ModSecurity v3 and OWASP Core Rule Set release.
- Build one immutable WAF-capable OpenResty cell image/profile.
- Support simple domain/pool profiles:
  - `off`;
  - `monitor`;
  - `balanced`;
  - `strict`.
- Map profiles to tested anomaly thresholds, paranoia levels, body-inspection
  limits, and blocking behavior.
- Prefer WAF-capable reserved, dedicated, or quarantine cells where isolation is
  required; do not force WAF overhead onto every normal pool.
- Support only bounded exclusions by approved dimensions such as rule ID,
  hostname, path prefix, argument/header name, reason, owner, and expiry.
- Never expose arbitrary `SecRule`, arbitrary directives, customer rule uploads,
  runtime downloads, or custom expression languages.
- Add detection/block reason, rule ID, anomaly score, processing time, body-limit,
  exclusion, and profile telemetry with redaction.
- Roll new CRS/image versions through monitor-only canaries before blocking.
- Preserve the previous WAF image/ruleset and active traffic state on failure.

### Scale and security checkpoint

- Safe laboratory test corpus for common SQL injection, XSS, traversal, protocol,
  and evasion patterns.
- False-positive corpus for representative applications.
- Cache HIT/MISS traffic with WAF enabled and disabled.
- Record latency, CPU, memory, request-body cost, throughput, detection accuracy,
  false positives, and accepted limits.

### Completion checklist

- [ ] Off, monitor, balanced, and strict profiles behave as documented.
- [ ] Monitor records detections without blocking.
- [ ] Blocking profiles return stable reasons and preserve privacy.
- [ ] Bounded exclusions work, expire, audit, and cannot become arbitrary rules.
- [ ] Oversized or malformed bodies fail according to policy without exhausting cells.
- [ ] WAF failure/canary regression does not replace the previous valid runtime.
- [ ] Unrelated non-WAF pools remain healthy during WAF load/failure.
- [ ] Tests, security evidence, docs, and manual qualification pass.

## Phase 10 — Expanded telemetry, analytics, and capacity control

### Goal

Make the new gateway, endpoint, pool, cell, cache, compression, failover, Anycast,
and WAF behavior observable and capacity-manageable.

### Implementation

- Add bounded telemetry for:
  - gateway listeners, maps, routes, connections, errors, and revisions;
  - service endpoints and Anycast readiness;
  - pool participation, minimum readiness, and placement transitions;
  - cell CPU, memory, connections, cache disk/temp, admission, and saturation;
  - compression encoding, ratios, bytes saved, concurrency, and fallback;
  - origin health, active origin, failover/failback, and circuit state;
  - WAF profile, anomaly score, rule category, processing time, and action.
- Extend ClickHouse schemas and aggregates with bounded retention and query limits.
- Update administrator and domain analytics only where the data is useful.
- Add Prometheus alerts for stale maps, endpoint mismatch, cell exhaustion, cache
  disk pressure, compression CPU pressure, origin failover, WAF errors, and
  Anycast readiness disagreement.
- Keep telemetry best-effort and outside serving decisions.

### Scale checkpoint

- At least 20,000 active proxied domains across multiple pools, cells, endpoints,
  and edges.
- High-cardinality fields remain bounded or aggregated.
- ClickHouse/Vector outage and backlog recovery under live traffic.

### Completion checklist

- [ ] Every new serving component has healthy/degraded/unavailable state.
- [ ] Metrics and logs identify the responsible pool, cell, edge, and revision.
- [ ] Domain users cannot see unrelated pool/customer data.
- [ ] Raw logs remain redacted, bounded, and directly delivered to ClickHouse.
- [ ] Telemetry outage never blocks gateway/cell traffic.
- [ ] Queries remain bounded and responsive at the qualification dataset.
- [ ] Alerts and runbooks identify actionable recovery steps.
- [ ] Tests, scale evidence, docs, and manual qualification pass.

## Phase 11 — Bounded fleet rollout automation

### Goal

Automate proven manual upgrades when fleet size makes per-edge rollout
inefficient, without adding general remote execution or dynamic containers.

### Implementation

- Manage immutable versions for gateway, edge agent, normal cell, and WAF cell.
- Define compatibility ranges and a bounded mixed-version window.
- Support explicit canary edges/POPs and rollout waves.
- Pause automatically on health, error, readiness, revision, or capacity thresholds.
- Roll back to the last compatible image/configuration.
- Preserve fixed cell-slot topology; rollout automation does not create arbitrary
  containers or run arbitrary commands.
- Expose desired/current version, wave, progress, failure, pause, and rollback.
- Audit every rollout decision and retain operator confirmation for destructive
  or fleet-wide actions.

### Scale checkpoint

- Multi-edge, multi-POP rollout with normal and WAF cells.
- Mixed-version serving, controlled canary failure, automatic pause, and rollback.
- Record rollout time, unavailable capacity, errors, and operator recovery steps.

### Completion checklist

- [ ] Canary completes before later waves start.
- [ ] Failed canary pauses rollout automatically.
- [ ] Rollback restores the prior compatible runtime without database restore.
- [ ] Existing traffic continues through a bounded mixed-version window.
- [ ] No arbitrary command execution or unbounded container creation exists.
- [ ] Version drift and incompatible agents/cells are visible.
- [ ] Tests, recovery evidence, docs, and manual qualification pass.

## Phase 12 — Final production qualification for the new architecture

### Goal

Prove the complete post-baseline architecture works as one simple, solid,
recoverable product.

### Required topology

- At least two POPs/edges.
- At least eight bounded cell slots per edge.
- One shared pool using at least three cells per edge.
- One reserved customer pool using a separate IPv4/IPv6 pair.
- One quarantine pool.
- Geo-Unicast service endpoints.
- One Simple Anycast pool across both POPs where the operator can provide the
  required routing environment.
- Persistent Cache v2, Gzip, Brotli, primary/backup origins, and one managed WAF
  pool/profile.
- Real IPv4 and IPv6 clients/origins where available.

### Final qualification

- Complete new-edge installation and registration from clean hosts.
- Create and activate all pool types and endpoint modes.
- Serve real HTTP/HTTPS through multiple service pairs on the same edge.
- Verify stable domain placement, movement, drain, quarantine, and rollback.
- Verify Geo-Unicast and Simple Anycast behavior from external vantage points.
- Exercise Cache v2, persistent restart, purge, stale, quota pressure, Gzip, and Brotli.
- Fail the primary origin and prove controlled backup failover/failback.
- Exercise WAF monitor/block/exclusion/canary behavior with safe test traffic.
- Stop Laravel, queues, Redis/Valkey, and ClickHouse while existing traffic continues.
- Restart gateway, agent, cells, Vector, ClickHouse, DNSdist, and PowerDNS according
  to their runbooks.
- Apply invalid gateway/cell/WAF artifacts and verify previous valid state remains.
- Saturate one cell within the approved lab and verify unrelated cells/pools continue.
- Perform fleet canary upgrade and rollback.
- Restore control-plane data on a clean replacement host and reconcile derived state.
- Record measured limits, hardware, topology, RPO, RTO, throughput, latency,
  saturation, known limitations, and owner browser evidence.

### Completion checklist

- [ ] Every phase completion gate is passed and linked to evidence.
- [ ] Existing completed baseline features pass the regression smoke suite.
- [ ] All current API/OpenAPI, UI, deployment, architecture, security, operations,
      troubleshooting, and runbook documentation is updated.
- [ ] All tests clearly report passed, failed, blocked, and not executed results.
- [ ] No unresolved critical/high failure remains.
- [ ] The owner records the final manual/browser/real-traffic qualification.
- [ ] The release notes state measured capabilities and limitations without
      unsupported scale, Anycast, WAF, or DDoS claims.

# Future candidates — not part of the committed phases

These capabilities remain outside the committed roadmap until repeated customer
or operator demand justifies a separate scope and qualification contract:

- secondary ACME certificate authority;
- DNSSEC signing and rollover lifecycle;
- HTTP/3 and QUIC;
- private outbound origin connector for non-public origins;
- immutable/deletion-protected backup storage and warm control-plane standby;
- long-retention analytics archive/export;
- replicated placement for exceptional high-volume domains;
- additional placement policies that preserve the bounded pool/cell model;
- origin shield or hierarchical cache.

A candidate is admitted only when:

- a real requirement exists;
- the existing product cannot solve it safely;
- operational and failure costs are understood;
- it does not move traffic through Laravel;
- it does not require rewriting the control plane or generic edge runtime;
- it has bounded state, rollback, observability, tests, docs, and a release gate;
- disabling it leaves the committed product functional.

# Explicitly out of scope

- Weighted origin balancing and percentage traffic splitting
- General BGP/router management
- Volumetric DDoS scrubbing guarantees
- CAPTCHA, browser challenges, or bot-scoring platforms
- Customer-written WAF rules or edge scripts
- Serverless workers or plugin marketplaces
- Object-storage product features
- Per-domain containers/processes by default
- Kubernetes as a deployment requirement
- Billing, payment, reseller, organization, team, or custom-role systems

## Final rule

> Keep the completed platform intact. Add one bounded production capability per
> phase. Keep the gateway simple, keep cells isolated, preserve last-valid state,
> test real traffic and failure behavior, record scale honestly, update every
> affected document, and never move DNS or HTTP traffic through Laravel.
