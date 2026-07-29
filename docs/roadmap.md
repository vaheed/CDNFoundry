---
title: CDNFoundry next development roadmap
description: Post-baseline roadmap for the new edge, cache, origin resilience, WAF, and fleet capabilities.
---

# CDNFoundry next development roadmap

## Starting point

The original production roadmap is complete. The existing control plane,
authoritative DNS, Geo-DNS, proxy, TLS, baseline cache and purge, security,
telemetry, analytics, backup, and operations are the regression baseline.

This roadmap contains only the new work discussed after that baseline.

CDNFoundry remains a simple but solid private CDN. It must stay understandable,
bounded, production-safe, and easy to operate. It is not intended to become a
general cloud platform or a Cloudflare replacement.

## Project boundaries

- Laravel and Filament remain the single control plane.
- PostgreSQL remains the desired-state source of truth.
- DNS and HTTP traffic never pass through Laravel.
- The edge gateway only binds service addresses and routes by destination IP,
  Host, and TLS SNI.
- OpenResty cells own TLS, cache, compression, security, WAF, origin proxying,
  and request telemetry.
- Cell slots are bounded and created during edge installation.
- The edge agent never receives unrestricted Docker access.
- No default per-domain process, container, worker, timer, cache directory,
  server block, or reload is allowed.
- External effects remain asynchronous, revisioned, idempotent, coalesced,
  acknowledged, and last-valid-state preserving.
- No microservices, Kafka, Kubernetes requirement, CQRS, event sourcing,
  GraphQL, custom RBAC, plugin runtime, reseller hierarchy, billing engine,
  custom WAF language, or second dashboard.
- Simple Anycast uses operator/provider routing. CDNFoundry does not become a
  BGP controller.
- DDoS readiness never claims upstream volumetric scrubbing after physical
  capacity is saturated.
- Roadmap phase numbers and lifecycle suffixes must not appear in production
  code or filenames.

## Required completion gate

Every phase must independently record:

| Gate | Required evidence |
| --- | --- |
| Implementation | State, migrations, authorization, API, UI where needed, jobs, reconciliation, metrics, audit, and rollback |
| Unit and feature tests | Happy path, permissions, validation, bounds, idempotency, and stable errors |
| Real-runtime E2E | Real DNS, HTTP, HTTPS, TLS, cache, compression, WAF, restart, and failure behavior where applicable |
| IPv4 and IPv6 | IPv4 always passes; IPv6 passes when configured, and an IPv4-only topology is qualified |
| Scale | Dataset, topology, hardware, concurrency, result, saturation point, and accepted limit |
| Failure and recovery | Retry, obsolete work, invalid candidate, dependency outage, restart, rollback, and last-valid state |
| Isolation | Failure or load in one domain, pool, cell, edge, or component does not unnecessarily affect unrelated traffic |
| Observability | Health, metrics, logs, alerts, capacity, degraded states, and stable reason codes |
| Documentation | User, administrator, API/OpenAPI, architecture, deployment, operations, troubleshooting, and runbooks |
| Manual qualification | Owner-run evidence from the [manual qualification checklist](manual-browser-qualification.md) |
| Regression | The completed baseline continues to work |
| Release decision | Passed, failed, blocked, or removed from scope |

A phase is not complete when an applicable test was not executed. Every phase
must finish as a usable production increment and must not depend on unfinished
work from a later phase.

## Phase 1 — Edge gateway ingress

**Goal:** introduce one minimal gateway per edge so one server can bind several
public IPv4/IPv6 service pairs and route traffic to bounded OpenResty cells.

**Implementation:**

- Bind operator-configured IPv4 service addresses and optional IPv6 service addresses.
- Route HTTP by destination address and validated Host.
- Route HTTPS by destination address and TLS SNI without terminating customer TLS.
- Forward trusted client identity to cells through a fixed internal contract.
- Reject unknown addresses, Host values, and SNI names before expensive work.
- Activate generated routing maps atomically and retain the previous valid map.
- Expose listener, revision, route, connection, error, and readiness metrics.
- Keep cache, TLS certificates, WAF, origin logic, and control-plane calls out of
  the gateway.

**Scale target:** at least 50,000 Host/SNI mappings and multiple dual-stack
service pairs on one edge, with throughput, latency, CPU, memory, and saturation
recorded.

**Completion checklist:**

- [x] Real HTTP Host and HTTPS SNI traffic reaches the intended cell.
- [x] Dual-stack and IPv4-only topologies pass.
- [x] Unknown and invalid traffic is rejected.
- [x] Invalid maps never replace active maps.
- [x] Restart restores or rebuilds the last valid map.
- [x] Existing baseline edge traffic remains functional during migration.
- [ ] Tests, scale, documentation, and manual qualification pass.

## Phase 2 — Bounded cell inventory

**Goal:** replace the fixed shared/quarantine assumption with a bounded set of
generic OpenResty cell slots created during edge installation.

**Implementation:**

- Configure a bounded slot count such as `cell-01` through `cell-N`.
- Give every slot stable identity, ports, runtime path, cache path, temporary
  path, status endpoint, and resource limits.
- Register assigned, unassigned, ready, degraded, drained, and stopped states.
- Remove readiness logic tied to one hardcoded shared cell.
- Let the agent configure, reload, drain, restart, and report existing slots.
- Keep agent resources separate from cell resources.
- Do not mount the container-engine socket into the agent.

**Scale target:** qualify at least eight slots on one edge and record idle and
active overhead per slot.

**Completion checklist:**

- [x] Fresh installation creates exactly the configured slots.
- [x] Every slot has unique identity, paths, ports, health, and limits.
- [x] One crashed or saturated cell does not stop the gateway, agent, or other cells.
- [x] Cell restart and rollback preserve unrelated traffic.
- [x] Cache, temporary, and log storage remain bounded.
- [x] Existing enrollment, mTLS, and snapshot recovery still pass.
- [ ] Tests, scale, documentation, and manual qualification pass.

## Phase 3 — Multi-cell pools and stable placement

**Goal:** allow one pool to use several cells on the same edge while preserving
cache locality and predictable isolation.

**Implementation:**

- Support shared, reserved, dedicated, and quarantine pool kinds.
- Separate stable cell identity from pool identity.
- Add explicit edge participation and cell assignment per pool.
- Allow several cells from one edge in one pool.
- Add minimum-ready-cell and capacity policies.
- Place a normal domain on one stable cell per edge by default.
- Keep replicated placement exceptional and bounded.
- Move domains target-first, switch gateway routing, then drain the source.
- Deliver artifacts only to participating and migration-target cells.

**Scale target:** at least 20,000 domains across several cells and a controlled
burst of 10,000 placement-affecting changes without unnecessary reshuffling.

**Completion checklist:**

- [x] One shared pool uses at least three cells on one edge.
- [x] Placement remains stable across unrelated changes.
- [x] Reserved, dedicated, and quarantine rules are enforced.
- [x] Failed target readiness keeps the source active.
- [x] Successful movement removes old state only after the target is serving.
- [x] Non-participating cells receive no artifacts.
- [x] Pool readiness evaluates all required cells.
- [ ] Tests, scale, documentation, and manual qualification pass.

**Agent completion evidence (2026-07-28):** implementation, the PostgreSQL
expand migration, 177 isolated Laravel tests / 11,369 assertions, edge-agent Go
tests and image build, OpenAPI generation, the 20,000-domain / 10,000-change
stability dataset, and the real gateway three-cell routing/isolation test pass.
Strict IPv4/IPv6 HTTP and HTTPS, IPv4-only, invalid-candidate, restart, and
last-valid gateway regression also pass on x86_64, 32 vCPU, 15.6 GiB RAM, Docker
29.1.3. Documentation and the exact owner checklist are current. The final
combined checkbox remains open only because browser/manual real-traffic
qualification is owner-run and has not been executed by the coding agent.

## Phase 4 — Pool service endpoints and Geo-Unicast

**Goal:** support several public service IP pairs on one edge, with each pair
owned by one pool endpoint backed by one or more cells.

**Implementation:**

- Add per-edge pool endpoints with IPv4, optional IPv6, gateway state,
  participating cells, and readiness.
- Allow separate service pairs for shared, reserved, dedicated, and quarantine pools.
- Keep management addresses separate from service endpoints.
- Publish only ready, non-withdrawn endpoints through system-managed DNS.
- Preserve country, continent, and global Geo-DNS fallback behavior.
- Prevent duplicate or conflicting address ownership.
- Reconcile gateway bindings, pool readiness, and DNS publication through one
  revisioned workflow.

**Scale target:** several pools and service pairs on each of at least two edges,
with endpoint-health changes measured without rewriting every domain.

**Completion checklist:**

- [x] Three shared cells serve one dual-stack pair.
- [x] A reserved pool serves a different pair on the same edge.
- [x] DNS publishes the correct ready endpoints.
- [x] Withdrawal affects only the intended pool.
- [x] IPv4-only, IPv6-only, and dual-stack endpoints pass.
- [x] Conflicts fail before activation.
- [x] Restart and reconciliation converge gateway, DNS, pool, and cell state.
- [ ] Tests, scale, documentation, and manual qualification pass.

**Agent completion evidence (2026-07-28):** the PostgreSQL endpoint migration
and strict management-address cleanup applied, most recently in 54.32 ms; 182
isolated Laravel tests / 11,388 assertions, edge-agent
Go tests and image build, Compose/OpenAPI/docs validation, and the two-edge real
PowerDNS control-plane test pass. The runtime test covered a three-cell
dual-stack pair, an isolated second-pool pair, withdrawal/restoration,
Geo-Unicast publication, revision acknowledgement, placement migration, and
zero obsolete artifacts. The existing 20,000-domain / 10,000-change dataset
remains green. The combined checkbox remains open only because owner-run browser
and external real-traffic qualification has not been executed by the coding
agent.

## Phase 5 — Simple Anycast pools

**Goal:** allow selected pools to use one shared IPv4/IPv6 pair across several
POPs while route advertisement remains owned by the network operator/provider.

**Implementation:**

- Add `simple_anycast` routing mode beside Geo-Unicast.
- Store one pool-level IPv4 and optional IPv6 pair.
- Attach explicit POPs/edges and readiness requirements.
- Bind the same pair on participating gateways.
- Publish the shared pair for assigned domains.
- Expose clear ready, degraded, and withdrawn signals.
- Do not add FRR, BIRD, router credentials, arbitrary commands, or BGP control.

**Scale target:** at least two approved POPs using the same dual-stack service
pair, tested from multiple external vantage points with controlled POP loss.

**Completion checklist:**

- [x] One Anycast pool serves from multiple POPs.
- [x] Geo-Unicast and Anycast coexist on the same fleet.
- [x] POP failure does not corrupt another POP's local state.
- [ ] External route withdrawal and restoration are recorded.
- [x] UI and docs clearly state that CDNFoundry does not announce BGP routes.
- [x] Uplink and upstream-scrubbing limitations remain explicit.
- [ ] Tests, network evidence, documentation, and manual qualification pass.

**Agent completion evidence (2026-07-28):** the pool-owned address migration
applied to persistent PostgreSQL in 83.82 ms. All 191 isolated Laravel tests /
11,437 assertions, including 8 focused Anycast tests / 44 assertions,
edge-agent and edge-gateway Go test/build images, Compose/OpenAPI/docs checks,
and the real two-edge mTLS/PowerDNS control-plane test pass. The runtime test
covered one shared dual-stack pair on two POPs, Geo-Unicast coexistence,
explicit zero-side-effect pool/edge creation, controlled POP loss and degraded
state, local-state isolation, DNS and gateway
withdrawal/restoration, forced-reconciliation recovery, and the existing cache
and placement regression through revision 13 with zero obsolete artifacts.
The exact evidence and owner handoff are in [Simple Anycast qualification](operations/simple-anycast-qualification.md).
External provider route withdrawal/restoration, multi-vantage traffic, load,
and browser evidence remain owner-run, so the final combined checkbox and
release decision remain blocked.

## Phase 6 — Cache

**Goal:** turn the baseline cache into a persistent, bounded, production-strength
cell cache without a distributed cache or per-domain directories.

**Implementation:**

- Use persistent per-cell cache volumes with explicit size, inactive time,
  temporary quota, and minimum free space.
- Add small, standard, large, and streaming pool profiles.
- Keep stable domain-to-cell placement.
- Add query policies: include all, ignore all, include selected, ignore selected.
- Keep deterministic scheme, Host, path, query, and epoch behavior.
- Add bounded TTL policies for approved status codes.
- Add admission, object-size, low-disk, range, and variant protections.
- Add stale-if-error, stale-while-revalidate, cache-only, and stale-only modes.
- Preserve exact URL purge and epoch full purge.

**Scale target:** mixed HIT/MISS traffic, quota pressure, restart persistence,
purge fan-out, and high-cardinality abuse with throughput, latency, IOPS, CPU,
memory, disk, and hit ratio recorded.

**Completion checklist:**

- [x] Cache survives routine restart and remains rebuildable after loss.
- [x] Profiles enforce disk, temporary, object, and admission ceilings.
- [x] Query policies do not create unbounded variants.
- [x] TTL and stale behavior match policy.
- [x] Low disk and abuse bypass safely.
- [x] Purge remains durable across participating cells.
- [x] One domain cannot exhaust unrelated cache resources beyond accepted limits.
- [ ] Tests, load evidence, documentation, and manual qualification pass.

**Agent completion evidence (2026-07-28):** the cache-profile PostgreSQL
migration applied in 51.93 ms. All 194 isolated Laravel tests / 11,472
assertions, focused API/UI tests, Pint, Compose/OpenAPI/docs validation,
the OpenResty image build, and the dedicated real cache runtime pass. Runtime
evidence covers a persistent per-cell volume across container restart,
deterministic selected-query keys, status TTLs, object/range/admission/variant
bounds, stale expiry, exact/full purge, invalid-candidate last-valid state, and
cell isolation. Four separately bounded cache zones enforce profile disk,
inactive, minimum-free, object, and admission ceilings while request temporary
storage remains a stricter per-cell filesystem quota. The cumulative
non-browser E2E passes foundation, dual-stack DNS, Geo-DNS, the two-edge
control plane through revision 14 with zero obsolete artifacts, mTLS, TLS,
security, analytics outage recovery, operations recovery, and the real
OpenResty cache runtime. The preservation-aware control-plane scenario now
selects its own disposable pool explicitly instead of depending on hash
placement across owner-created persistent pools. Owner browser, disk-pressure,
mixed-load saturation, and external IPv6/IPv4-only evidence remain mandatory;
the combined checkbox and release decision therefore remain blocked.

## Phase 7 — Gzip and Brotli compression

**Goal:** reduce delivered bandwidth through safe compression integrated with
Cache and bounded by pool and cell resources.

**Implementation:**

- Store one canonical uncompressed cache object by default.
- Request identity encoding from origins where required.
- Enable Gzip as the normal default.
- Add optional Brotli through one immutable, pinned, tested edge image/module.
- Expose only off, standard, and maximum-savings profiles.
- Use a tested MIME allowlist and minimum response size.
- Avoid recompressing images, video, archives, and other compressed formats.
- Handle Accept-Encoding, Vary, ETag, HEAD, 304, stale, purge, and fallback.
- Bound range traffic, large responses, concurrency, and CPU usage.
- Add emergency disable and CPU-pressure fallback.
- Record encoding, bytes, ratio, profile, and fallback telemetry.

**Scale target:** mixed identity, Gzip, and Brotli clients against HIT and MISS
traffic, with bandwidth saved, throughput, latency, and CPU cost recorded.

**Completion checklist:**

- [x] Identity, Gzip, and Brotli decode to identical content.
- [x] One canonical object serves different encodings correctly.
- [x] Compressed and range content follows safe policy.
- [x] Vary, ETag, revalidation, stale, and purge remain correct.
- [x] Shared pools cannot select unsafe levels.
- [x] CPU pressure falls back without stopping traffic.
- [x] Compression analytics are accurate.
- [ ] Tests, load evidence, documentation, and manual qualification pass.

**Agent completion evidence (2026-07-29):** the additive PostgreSQL migration
applied to persistent development state in 50.77 ms and the additive
ClickHouse telemetry migration applied without resetting either volume. All
201 isolated Laravel tests / 11,513 assertions, Pint, the pinned OpenResty plus
`ngx_brotli` image build, Vector validation, Compose/OpenAPI/docs checks, and
the real OpenResty runtime pass. Runtime evidence covers identical
identity/Gzip/Brotli content, one canonical HIT object, MIME/size/range bounds,
CPU-pressure and emergency identity fallback, restart, stale, purge,
invalid-candidate last-valid behavior, IPv6, and cell isolation. Real Vector
and ClickHouse qualification covers scoped encoding, delivered/identity bytes,
ratio, profile, fallback, privacy, outage recovery, and a 20,000-row analytics
dataset. Owner browser, external mixed-client load/saturation, and external
IPv4/IPv6 evidence remain mandatory, so the combined checkbox and release
decision remain blocked.

## Phase 8 — Primary and backup origin failover

**Goal:** add one simple active-passive backup origin per proxied hostname.

**Implementation:**

- Support one primary and one optional backup origin.
- Reuse strict origin, TLS, timeout, header, and loop validation.
- Add bounded health checks and request-path failure evidence.
- Add failure threshold, recovery threshold, hold-down, and failback delay.
- Keep active-origin state local to cells during control-plane loss.
- Fail over without calling Laravel in the request path.
- Prefer stale or cache-only behavior before retry storms.
- Expose active origin and transition reason without secrets.
- Do not add weighted, percentage, geographic, or arbitrary origin pools.

**Scale target:** controlled failover and recovery under concurrent HIT/MISS
traffic, with transition time, origin pressure, errors, and isolation recorded.

**Completion checklist:**

- [x] Healthy primary receives normal traffic.
- [x] Qualified failure moves traffic to backup.
- [x] Recovery does not flap.
- [x] Both-origin failure follows stale or maintenance policy.
- [x] Invalid backup state never replaces valid primary state.
- [x] Control-plane outage does not remove local failover.
- [x] One failing origin cannot exhaust unrelated origin budgets.
- [ ] Tests, load evidence, documentation, and manual qualification pass.

**Agent completion evidence (2026-07-29):** one optional backup and bounded
failure, recovery, hold-down, and failback policy are stored in the existing
revisioned origin JSON, validated through the same IPv4/IPv6 destination and
TLS policy, exposed in Filament and the policy-aware API, and compiled
deterministically into signed cell artifacts. Real OpenResty qualification
covers healthy primary traffic, transition within five seconds, 24 concurrent
backup requests, delayed thresholded recovery, both-origin stale service,
bounded failure, local operation without a control-plane request, and
unrelated-host isolation. Isolated Laravel coverage verifies permission,
validation, idempotency conflict, atomic preservation, and equal safety
envelopes. All 205 isolated Laravel tests / 11,537 assertions, Pint, Compose,
OpenAPI, docs, Vector validation, cumulative non-browser E2E, and a real
backup-transition Vector-to-ClickHouse event pass. Owner browser, external
load/saturation, and external IPv4/IPv6
evidence remain mandatory, so the combined checkbox and release decision
remain blocked.

## Phase 9 — Managed OWASP CRS WAF

**Goal:** add optional managed application-signature protection without exposing
a custom WAF language or raw ModSecurity configuration.

**Implementation:**

- Pin ModSecurity v3 and OWASP Core Rule Set releases.
- Build an immutable WAF-capable OpenResty cell image/profile.
- Support off, monitor, balanced, and strict profiles.
- Map profiles to tested thresholds, paranoia levels, body limits, and blocking.
- Prefer reserved, dedicated, or quarantine WAF-capable cells where isolation is needed.
- Allow only bounded exclusions by approved dimensions, reason, owner, and expiry.
- Reject arbitrary SecRule directives, customer rule uploads, runtime downloads,
  and custom expression languages.
- Add rule, score, action, processing-time, body-limit, and exclusion telemetry.
- Roll new CRS/image versions through the normal bounded edge release process.
- Preserve the previous image and ruleset after release failure.

**Scale target:** safe attack and false-positive corpora plus HIT/MISS load, with
latency, CPU, memory, throughput, detection, false positives, and accepted limits recorded.

**Completion checklist:**

- [x] Off, monitor, balanced, and strict behave as documented.
- [x] Monitor detects without blocking.
- [x] Blocking uses stable, privacy-safe reasons.
- [x] Exclusions are bounded, audited, and expiring.
- [x] Oversized or malformed bodies remain bounded.
- [x] Failed edge releases keep the previous valid WAF runtime.
- [x] Non-WAF pools remain healthy during WAF load or failure.
- [ ] Tests, security evidence, documentation, and manual qualification pass.

**Agent completion evidence (2026-07-29):** the control plane stores one fixed
managed profile and bounded, owned, expiring literal exclusions in PostgreSQL,
increments the domain revision transactionally, audits mutations, coalesces
reconciliation, and admits enabled profiles only to WAF-capable pools running
the pinned immutable runtime. Signed artifacts carry deterministic profile,
threshold, body-limit, ruleset, and exclusion data; failed or ineligible
candidates preserve the previous active placement and artifact. The edge image
pins ModSecurity 3.0.14, connector 1.0.4, and CRS 4.26.0, disables raw
matched-value logging, and emits bounded privacy-safe WAF telemetry through
Vector to ClickHouse. Real OpenResty qualification covers all profiles,
monitor-only detection, XSS/SQLi/malformed JSON, 256 KiB bounds, stable 403/413
reasons, exclusions, 48 concurrent blocked requests beside 48 healthy non-WAF
requests, configuration validation, and payload-free logs. All 210 isolated
Laravel tests / 11,595 assertions, Pint, immutable image build, Compose,
OpenAPI, docs, and the clean cumulative non-browser E2E pass. Owner browser,
external corpus/load/saturation, invalid-image drill, and external IPv4/IPv6
evidence remain mandatory, so the combined checkbox and release decision
remain blocked.

## Phase 10 — Observability and capacity control

**Goal:** make gateway, endpoint, pool, cell, cache, compression, failover,
Anycast, and WAF behavior operationally visible and capacity-manageable.

**Implementation:**

- Add bounded gateway, endpoint, pool, placement, cell, cache, compression,
  origin, and WAF telemetry.
- Extend ClickHouse schemas and aggregates with bounded retention and queries.
- Add useful administrator and domain analytics without leaking unrelated data.
- Add alerts for stale maps, endpoint mismatch, cell exhaustion, cache pressure,
  compression pressure, origin failover, WAF errors, and Anycast disagreement.
- Keep telemetry best-effort and outside serving decisions.

**Scale target:** at least 20,000 active proxied domains across several pools,
cells, endpoints, and edges, including ClickHouse/Vector outage and recovery.

**Completion checklist:**

- [x] Every new component has healthy, degraded, and unavailable states.
- [x] Metrics identify pool, cell, edge, and revision.
- [x] Domain users cannot see unrelated data.
- [x] Raw logs remain bounded and redacted.
- [x] Telemetry outage never blocks serving.
- [x] Queries remain bounded at the qualification dataset.
- [x] Alerts link to actionable runbooks.
- [ ] Tests, scale evidence, documentation, and manual qualification pass.

**Agent completion evidence (2026-07-29):** bounded, redacted ClickHouse/Vector
telemetry and domain-policy-scoped analytics now cover the post-baseline
gateway, endpoint, placement, cell, cache, compression, origin, and managed WAF
runtime. Prometheus identifies the bounded edge/pool/cell/endpoint/revision
dimensions, cell resource ratios, endpoint mismatch, version drift, and paused
rollouts. Alerts link to exact recovery runbooks and telemetry remains outside
serving decisions. All 215 isolated Laravel tests / 11,625 assertions, the Go
agent suite, OpenAPI/Compose/docs checks, and real Vector-to-ClickHouse
analytics/privacy/20,000-domain/outage/recovery qualification pass. The
combined checkbox remains open for owner alert, browser, external load, and
external traffic evidence.

## Phase 11 — Bounded fleet rollout automation

**Goal:** automate proven edge upgrades without introducing general remote
execution or dynamic containers.

**Implementation:**

- Manage immutable gateway, agent, normal-cell, and WAF-cell versions.
- Define compatibility ranges and a bounded mixed-version window.
- Support canary edges/POPs and rollout waves.
- Pause on health, error, readiness, revision, or capacity thresholds.
- Roll back to the last compatible image and configuration.
- Preserve the fixed slot topology.
- Expose desired/current version, wave, progress, pause, failure, and rollback.
- Audit every rollout decision.

**Scale target:** multi-edge, multi-POP rollout with mixed normal and WAF cells,
including failed canary, automatic pause, and rollback.

**Completion checklist:**

- [x] Canary completes before later waves.
- [x] Failed canary pauses automatically.
- [x] Rollback restores the previous compatible runtime.
- [x] Traffic continues during the mixed-version window.
- [x] No arbitrary command execution or dynamic unbounded containers exist.
- [x] Version drift and incompatibility are visible.
- [ ] Tests, recovery evidence, documentation, and manual qualification pass.

**Agent completion evidence (2026-07-29):** PostgreSQL owns immutable
four-component releases, compatibility bounds, rollout/wave state, previous
versions, and per-edge progress. Explicit canaries precede deterministic
bounded waves; stale readiness and failed tasks pause automatically; rollback
uses the same gates. The unprivileged agent validates digest-only references
and writes one atomic typed intent for a fixed-purpose installer, with no
command field, dynamic slot creation, or container-engine socket. Current and
desired versions, drift, wave, failures, metrics, alerts, and every automated
decision are visible and audited. Focused Laravel and Go tests pass. The
combined checkbox remains open for the owner-operated multi-POP installer,
mixed IPv4/IPv6 traffic, failure/rollback, scale, and browser evidence.

## Phase 12 — Final production qualification

**Goal:** prove the complete post-baseline architecture as one deployable,
recoverable, simple, and solid product.

**Required topology:**

- At least two POPs/edges.
- At least eight bounded cell slots per edge.
- One shared pool using at least three cells per edge.
- One reserved pool using a separate IPv4/IPv6 pair.
- One quarantine pool.
- Geo-Unicast endpoints.
- One Simple Anycast pool where an approved routing environment exists.
- Persistent Cache, Gzip, Brotli, backup origin, and managed WAF.
- Real IPv4 and IPv6 traffic where available.

**Final checklist:**

- [ ] Clean edge installation and registration pass.
- [ ] All pool kinds and endpoint modes pass.
- [ ] Multiple service pairs work on one edge.
- [ ] Placement, movement, drain, quarantine, and rollback pass.
- [ ] Geo-Unicast and Anycast external checks pass.
- [ ] Cache persistence, purge, stale, pressure, Gzip, and Brotli pass.
- [ ] Origin failover and failback pass.
- [ ] WAF monitor, block, exclusion, and rollback pass.
- [ ] Existing traffic continues through controlled control-plane and telemetry outages.
- [ ] Invalid gateway, cell, and WAF candidates preserve previous valid state.
- [ ] One saturated cell does not stop unrelated pools.
- [ ] Fleet canary upgrade and rollback pass.
- [ ] Clean-host control-plane restore and derived-state reconciliation pass.
- [ ] All affected API, UI, architecture, deployment, operations, security,
      troubleshooting, and runbook documentation is current.
- [ ] Every completed test is linked and every unexecuted test is marked clearly.
- [ ] Owner-run browser and real-traffic qualification is recorded.
- [ ] Release notes state measured capabilities and limitations honestly.

**Agent implementation evidence (2026-07-29):** the final qualification now has
one bounded non-browser runner that records per-check logs, commit and host
metadata, failures, deliberately unexecuted checks, and a stable passed/failed/
blocked release decision. It joins current contracts, isolated application and
Go suites, gateway/cell/runtime regression, the bounded scale dataset,
clean-replacement recovery and derived-state reconstruction, upgrade
compatibility, throughput, and GeoIP provider failure. Public dual-stack,
approved Anycast routing, external saturation, the fixed-purpose fleet
installer, and browser evidence remain explicitly owner-operated and cannot be
silently skipped. The production qualification runbook, honest capability
limits, and exact Phase 12 owner checklist are current. Final checklist boxes
remain open until the current report and owner evidence pass on one commit.

## Future candidates

The following remain outside the committed roadmap until real demand justifies a
separate bounded implementation and qualification contract:

- secondary ACME authority;
- DNSSEC;
- HTTP/3 and QUIC;
- private origin connector;
- immutable backup storage and warm control-plane standby;
- long-retention analytics archive;
- replicated placement for exceptional high-volume domains;
- additional bounded placement policies;
- origin shield or hierarchical cache.

## Explicitly out of scope

- weighted or percentage origin balancing;
- general BGP/router management;
- volumetric DDoS scrubbing guarantees;
- CAPTCHA, browser challenge, or bot-scoring platforms;
- customer-written WAF rules or edge scripts;
- serverless workers or plugin marketplaces;
- object-storage product features;
- per-domain containers or processes by default;
- Kubernetes as a deployment requirement;
- billing, reseller, organization, team, or custom-role systems.

## Final rule

Keep the completed platform intact. Add one bounded production capability per
phase. Keep the gateway simple, keep cells isolated, preserve last-valid state,
test real traffic and failure behavior, record scale honestly, update every
affected document, and never move DNS or HTTP traffic through Laravel.
