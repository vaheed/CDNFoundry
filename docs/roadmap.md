---
title: CDNFoundry product roadmap
description: Ordered implementation and qualification contract for a simple, solid, production-grade private CDN.
---

# CDNFoundry product roadmap

## 1. Product goal

CDNFoundry is a small, production-grade private CDN and authoritative DNS platform for operators that own or manage their infrastructure.

The product favors:

- low feature count;
- clear operations;
- bounded resource usage;
- deterministic desired state;
- failure isolation;
- last-valid-state recovery;
- real production qualification.

The product does not attempt to reproduce Cloudflare, Fastly, Akamai, or a general cloud platform.

A phase is complete only when its implementation, documentation, automated tests, real-runtime qualification, scale checkpoint, and owner-run browser qualification are all recorded.

## 2. Non-negotiable boundaries

1. One Laravel modular monolith and two Filament panels form the control plane.
2. PostgreSQL is the durable source of desired state.
3. DNS, HTTP, HTTPS, TLS selection, cache decisions, security decisions, and raw telemetry never pass through Laravel.
4. PowerDNS runtime state, edge artifacts, generated gateway maps, and analytics aggregates are derived and rebuildable.
5. External effects are asynchronous, revisioned, idempotent, coalesced, acknowledged, and last-valid-state preserving.
6. DNSdist is the only public authoritative DNS endpoint.
7. Edge traffic uses a small gateway and bounded identical OpenResty cells.
8. Cell containers are created during edge installation. The edge agent assigns and configures cells; it does not receive unrestricted Docker access.
9. No domain receives a default process, container, worker, timer, cache directory, Nginx server block, or reload.
10. Per-domain, per-pool, per-cell, queue, log, cache, import, purge, rule, artifact, and query limits are explicit.
11. DDoS readiness reduces application and noisy-neighbour blast radius but does not claim upstream volumetric scrubbing after physical capacity is saturated.
12. No microservices, Kafka, Kubernetes requirement, CQRS, event sourcing, GraphQL, custom RBAC, plugin runtime, reseller hierarchy, billing engine, custom expression language, or additional dashboard is introduced without an explicit product-contract change.
13. Production code and filenames never use roadmap phase numbers or lifecycle suffixes such as `V2`, `New`, or `Final`.
14. Development PostgreSQL and named Compose volumes remain persistent across all work. Destructive refreshes are forbidden.

## 3. Required completion gate for every phase

Every phase must record the following independently:

| Gate | Required evidence |
| --- | --- |
| Implementation | Durable state, API, policies, UI where required, jobs/reconciliation, metrics, audit, and rollback behavior |
| Unit and feature tests | Happy path, permissions, validation, idempotency, bounds, and stable errors |
| Runtime and E2E tests | Real DNS, HTTP, HTTPS, TLS, cache, security, telemetry, restart, and failure behavior where applicable |
| Scale checkpoint | Dataset, concurrency, hardware/topology, measured result, saturation point, and accepted limit |
| Failure and recovery | Retry, obsolete work, last-valid state, dependency outage, restart, rollback, and reconciliation |
| Documentation | User, administrator, API, architecture, operations, metrics, troubleshooting, and runbook updates |
| Browser qualification | Owner-run steps and evidence in `docs/manual-browser-qualification.md` |
| Release decision | Passed, failed, blocked, or explicitly removed from scope |

A phase cannot be marked complete when a required test was not executed.

## 4. Qualification rules

- Tests use the smallest realistic topology that proves the behavior, then a separate scale program proves the declared limits.
- Runtime behavior is not accepted from mocks alone.
- IPv4 and IPv6 are tested whenever the feature handles addresses or traffic.
- Invalid candidates never replace active DNS, gateway, cell, TLS, cache, WAF, or routing state.
- Rapid updates prove coalescing and obsolete-revision exit.
- A failure in one domain, pool, cell, edge, DNS cluster, telemetry component, or queue lane must not unnecessarily affect unrelated traffic.
- Every phase updates OpenAPI, route coverage, current documentation, examples, environment references, metrics, alerts, and runbooks when affected.
- Browser qualification is manual and owner-run. Coding agents maintain the checklist but report it as not run unless the owner records evidence.
- Non-browser E2E and real-runtime qualification is agent-owned under `tests/e2e`.

# Part One — Simple but solid production platform

Part One is the ordered production roadmap. Phases 1 through 8 preserve and requalify the current platform foundation. Phases 9 through 14 implement the agreed edge, Anycast, cache, origin, and managed WAF improvements.

## Phase 1 — Foundation, access, and system identity

### Outcome

A recoverable Laravel/Filament control plane with exactly two user types, typed system identity, audit, idempotency, queues, scheduler, health, and safe Compose foundations.

### Scope

- Administrator and assigned-domain-user access.
- Sanctum browser sessions and API tokens.
- Policy-aware route binding.
- User lifecycle, profile, password, token, and audit behavior.
- Typed platform DNS identity preview, exact confirmation, asynchronous apply, and rollback.
- Horizon queue lanes, scheduler freshness, health, readiness, and protected metrics.
- Development and production Compose definitions with explicit networks, volumes, limits, health checks, and migrations.

### Completion checklist

- [ ] Administrator and domain-user boundaries pass API and browser tests.
- [ ] Idempotency conflict and replay behavior pass.
- [ ] Secrets appear only at their allowed one-time boundary.
- [ ] System identity preview and exact-confirmation flow pass.
- [ ] Queue and scheduler outage states are visible and bounded.
- [ ] SQLite-isolated Laravel tests fail closed when the safe test database is not active.
- [ ] Compose, OpenAPI, formatting, static, documentation, and link checks pass.
- [ ] Clean startup, graceful shutdown, restart, and last-valid settings tests pass.
- [ ] Manual browser Phase 1 is recorded.

## Phase 2 — Domains and authoritative DNS

### Outcome

A complete authoritative DNS workflow with durable desired state, deterministic deployment, drift detection, and real DNS qualification.

### Scope

- Domain lifecycle, assignment, delegation verification, activation, delayed deprovisioning, tombstones, and reclaim safety.
- A, AAAA, CNAME, MX, TXT, NS, CAA, SRV, and reverse-zone PTR.
- Transactional bulk changes and bounded BIND import/export.
- Monotonic serials and deterministic zone rendering.
- Multiple DNS clusters with target acknowledgements.
- DNSdist-only public ingress and private PowerDNS/runtime databases.
- Zone rebuild from control-plane PostgreSQL.

### Scale checkpoint

- At least 500,000 domains.
- At least 1,000,000 DNS records.
- At least 50,000 DNS changes per day.
- A controlled burst of at least 10,000 mutations.
- Multiple DNS clusters and mixed IPv4/IPv6 data.

### Completion checklist

- [ ] Record validation, CNAME coexistence, zone boundaries, Punycode, duplicates, and TTL limits pass.
- [ ] Bulk operations are bounded, atomic, idempotent, and revisioned.
- [ ] One failed DNS target does not replace another target's valid state.
- [ ] PowerDNS runtime deletion and complete rebuild pass.
- [ ] Real UDP/TCP `dig` tests pass over IPv4 and IPv6.
- [ ] Scale and mutation-coalescing programs pass with measured evidence.
- [ ] DNS user/admin/API/operations documentation and runbooks are current.
- [ ] Manual browser Phase 2 is recorded.

## Phase 3 — Geo-DNS

### Outcome

Bounded country, continent, and default DNS answers without runtime dependency on Laravel or external GeoIP services.

### Scope

- Country, continent, then default selection.
- A and AAAA Geo-DNS records.
- Valid ECS use when present and trusted.
- Resolver-address fallback with honest accuracy documentation.
- Shared local MMDB with atomic update and last-valid preservation.
- Deterministic PowerDNS Lua compilation.

### Completion checklist

- [ ] Country, continent, default, and unknown behavior pass.
- [ ] IPv4 and IPv6 previews and real queries pass.
- [ ] Duplicate, missing-default, invalid-type, and excessive-target inputs fail safely.
- [ ] MMDB corruption/provider outage retains the previous valid database.
- [ ] Geo changes do not rewrite unrelated zones.
- [ ] External-vantage evidence records resolver/ECS limitations.
- [ ] Documentation and browser qualification are current.

## Phase 4 — Proxy, baseline edge pools, and edge agent

### Outcome

Safe reverse proxying through signed, data-driven OpenResty runtime state with stable baseline pool placement.

### Scope

- One explicit origin per proxied hostname.
- Origin safety, forwarding-header normalization, timeouts, retries, WebSocket behavior, and health tests.
- Shared, quarantine, and exceptional dedicated pools.
- Edge registration, one-time bootstrap, mTLS identity, revocation, and rotation.
- Signed deltas and bounded full snapshots.
- Atomic activation, acknowledgement, previous-valid retention, and offline serving.
- Target-first placement and source drain.
- Generic OpenResty configuration without per-domain reload.

### Completion checklist

- [ ] Unsafe origins and proxy loops are rejected at save and connect time.
- [ ] Agent enrollment, rotation, revocation, and replay limits pass.
- [ ] Corrupt, unsigned, oversized, incompatible, and obsolete artifacts are rejected.
- [ ] A fresh edge restores from a bounded full snapshot.
- [ ] Control-plane, queue, and network interruption do not stop existing traffic.
- [ ] One cell/domain failure does not stop an unrelated cell/domain.
- [ ] Real HTTP/HTTPS, IPv4/IPv6, restart, drain, migration, and rollback tests pass.
- [ ] Documentation and browser qualification are current.

## Phase 5 — TLS, baseline cache, and purge

### Outcome

Recoverable managed/custom TLS and deterministic basic caching with durable bounded purge delivery.

### Scope

- Managed ACME DNS-01 only for eligible proxied domains.
- Renewal spreading, retries, cleanup, certificate reuse, and expiry alerts.
- Validated encrypted custom certificate upload.
- Cache enablement, TTLs, object limits, origin-header behavior, query inclusion, cookie bypass, development mode, and stale-if-error.
- Epoch-based full purge and exact URL purge.
- Durable per-edge purge tasks with retry and status.

### Completion checklist

- [ ] Managed and custom TLS lifecycle passes with no private-key exposure.
- [ ] Wrong key, chain, name, expiry, and oversized PEM are rejected.
- [ ] Existing valid certificates continue during ACME failure.
- [ ] Cache MISS/HIT/BYPASS/STALE behavior matches the deterministic key.
- [ ] URL and full purge reach every eligible target and retry safely.
- [ ] Restart and failed-delivery tests preserve serving and task state.
- [ ] Public HTTPS, documentation, and browser qualification are current.

## Phase 6 — Security and DDoS readiness

### Outcome

Bounded early rejection, application protection, quarantine, and emergency controls without pretending to provide upstream scrubbing.

### Scope

- Ordered IP, CIDR, country, and continent allow/block rules.
- Standard, protected, quarantine, and bounded manual profiles.
- Client/domain request, connection, TLS, body, header, timeout, origin, and cache-admission ceilings.
- Trusted proxy handling.
- Restrict, quarantine, recover, and release states.
- Expiring emergency actions for domains, cells, edges, and pools.
- Pool service-address withdrawal from DNS.
- Security telemetry with stable reason codes.

### Completion checklist

- [ ] Rule order, permissions, imports, limits, IPv4, and IPv6 pass.
- [ ] Unknown Host/SNI and malformed traffic are rejected before expensive work.
- [ ] Quarantine migration is target-first and recoverable.
- [ ] Emergency actions persist across restart and expire safely.
- [ ] One attacked domain cannot exhaust unrelated cell resource budgets in qualification.
- [ ] Physical-uplink saturation limitations remain explicit.
- [ ] Security docs, runbooks, metrics, alerts, and browser qualification are current.

## Phase 7 — Telemetry, analytics, and usage export

### Outcome

Direct bounded telemetry and accurate operational analytics independent from serving.

### Scope

- OpenResty and DNS structured telemetry through Vector directly to ClickHouse.
- Bounded disk buffers, retries, drops, retention, and redaction.
- Request, DNS, cache, origin, TLS, security, deployment, edge, cell, and pool fields.
- Domain-scoped and administrator-global analytics.
- Bounded raw-log access.
- Idempotent PostgreSQL usage rollups and stable JSON/CSV exports.
- Partial/outage labeling.

### Scale checkpoint

- At least 20,000 domains with active analytics.
- Bounded query ranges, filters, result sizes, and execution limits.
- Controlled ClickHouse outage and backlog recovery.

### Completion checklist

- [ ] Raw traffic never passes through Laravel queues or PostgreSQL.
- [ ] Secrets, request bodies, tokens, cookies, and certificate material are absent.
- [ ] Generated traffic matches request, byte, cache, origin, security, and DNS totals.
- [ ] ClickHouse/Vector outage does not affect serving.
- [ ] Backlog recovery does not starve live traffic.
- [ ] Usage rebuild and export are stable and idempotent.
- [ ] Documentation and browser qualification are current.

## Phase 8 — Operations, recovery, and baseline release qualification

### Outcome

A deployable, observable, recoverable baseline platform with measured release evidence.

### Scope

- Component health, queue health, drift, capacity, alerts, and operations.
- Bounded global reconciliation.
- Failed-job and failed-operation inspection/retry.
- Encrypted off-host backup and authenticated restore preflight.
- Clean-host restore.
- Expand/contract migrations, canary upgrade, and rollback.
- Clock synchronization and drift monitoring.
- Capacity planning and failure runbooks.

### Completion checklist

- [ ] Existing DNS and edge traffic continues during control-plane and ClickHouse outage.
- [ ] Encrypted backup and clean replacement-host restore pass.
- [ ] PowerDNS and edge state rebuild pass.
- [ ] Queue loss is repaired by reconciliation.
- [ ] Canary upgrade and rollback pass without database restore.
- [ ] RPO, RTO, topology, hardware, and measured throughput are recorded.
- [ ] Production installation, upgrade, backup, recovery, monitoring, and capacity documentation are current.
- [ ] Baseline release browser qualification is recorded.

## Phase 9 — Edge gateway and bounded cell inventory

### Outcome

Each edge has one minimal public gateway and a bounded inventory of pre-created generic OpenResty cell slots.

### Scope

- One edge gateway container/process group.
- Fixed configurable cell-slot count created during edge installation.
- Stable cell identities independent from pool names.
- Gateway listeners on one or more service IPv4/IPv6 pairs.
- Host routing for HTTP and SNI preread routing for HTTPS.
- Trusted client-address preservation to cells.
- Unknown destination, Host, and SNI rejection.
- Signed/revisioned gateway maps with atomic activation and last-valid rollback.
- Agent discovery and reporting of all installed cell slots.
- No Docker socket in the normal edge agent.

### Scale and failure checkpoint

- Maximum declared service IP pairs, host mappings, cells, and connections per edge.
- Gateway restart and invalid-map rejection.
- One cell restart without gateway or unrelated-cell interruption.
- Gateway CPU/memory reservation under full cell load.

### Completion checklist

- [ ] Installation creates exactly the configured bounded slots.
- [ ] Agent registers inventory without dynamically creating containers.
- [ ] Gateway routes HTTP Host and TLS SNI to the assigned cell.
- [ ] Real client IP reaches the trusted cell runtime.
- [ ] Unknown or mismatched destination/Host/SNI is rejected.
- [ ] Invalid gateway state preserves the previous map.
- [ ] Gateway and cell health, saturation, revisions, and metrics are visible.
- [ ] Compose, API, UI, docs, runbooks, E2E, scale, and browser qualification are current.

## Phase 10 — Multi-cell pools, endpoints, and stable domain placement

### Outcome

A pool can use multiple cells on each participating edge and one service IP pair can front those cells.

### Scope

- Pool kinds: shared, reserved, dedicated, and quarantine.
- Explicit participating edges.
- One or more cell slots per pool per edge.
- Pool service endpoints with IPv4/IPv6 ownership separate from individual cells.
- Stable domain-to-cell placement inside a pool.
- Optional explicitly bounded replicated placement for exceptional high-capacity domains.
- Different service IP pairs for different pools on the same edge.
- Target-first domain and cell migration.
- Selective artifact/certificate delivery only to participating cells and migration targets.
- Minimum-ready-cell policy per pool and edge.
- Slot drain, release, reassignment, and capacity reservation.

### Scale checkpoint

- Declared maximum pools, endpoints, cells, domains, and mappings per edge.
- Rebalancing does not reshuffle unrelated domains.
- Adding/removing a cell does not require full-fleet artifact delivery.
- Shared, reserved, dedicated, and quarantine isolation load tests.

### Completion checklist

- [ ] One IP pair fronts three shared cells successfully.
- [ ] A second IP pair fronts reserved customer cells on the same edge.
- [ ] Stable domain placement preserves cache locality.
- [ ] Dedicated pools enforce the single-domain contract.
- [ ] Reserved pools accept only explicitly assigned domains.
- [ ] Cell shortage and minimum shared/quarantine reservations fail safely.
- [ ] Only participating cells receive domain certificates and artifacts.
- [ ] Migration, drain, rollback, scale, docs, UI, API, and browser qualification pass.

## Phase 11 — Geo-Unicast and Simple Anycast routing modes

### Outcome

Pools support either normal Geo-Unicast endpoints or externally routed Simple Anycast without introducing BGP control into Laravel.

### Scope

- `geo_unicast` and `simple_anycast` pool routing modes.
- Geo-Unicast publishes ready per-edge pool endpoints.
- Simple Anycast uses one shared IPv4 and one shared IPv6 across participating POPs.
- Anycast disables GeoDNS edge selection for the pool.
- CDNFoundry manages desired membership, configuration, TLS, purge, security, revision, and readiness.
- Provider/network tooling manages BGP, FRR, BIRD, route announcement, and withdrawal.
- Agent reports service-address presence, gateway readiness, cell readiness, active revision, and external route-advertised signal.
- A POP is ready only after required runtime state is active.
- A failed POP is excluded only after route withdrawal is confirmed.
- Shared Anycast service addresses are forbidden as origins.

### Completion checklist

- [ ] Geo-Unicast and Anycast modes cannot be ambiguously combined.
- [ ] Every Anycast POP activates the same required domain revision before ready.
- [ ] DNS returns the shared Anycast addresses without country/continent edge selection.
- [ ] Route withdrawal signal prevents unsafe POP exclusion ordering.
- [ ] One POP failure leaves healthy POPs serving.
- [ ] Control-plane outage does not stop externally announced valid POPs.
- [ ] BGP credentials and neighbor configuration are absent from CDNFoundry.
- [ ] External multi-POP, IPv4/IPv6, failure, scale, docs, and browser qualification pass.

## Phase 12 — Cache v2 and response compression

### Outcome

A persistent, bounded, observable cache with correct variants, origin protection, Gzip, and optional Brotli.

### Scope

- Persistent per-cell cache storage with explicit maximum size, inactive period, minimum free disk, temporary-storage quota, and eviction visibility.
- Small, standard, large, and streaming pool cache resource profiles.
- Canonical uncompressed cached objects.
- Gzip enabled by default for approved compressible MIME types.
- Optional Brotli from an immutable tested edge image.
- Off, standard, and maximum-savings compression profiles.
- Minimum/maximum compressible response size.
- CPU/concurrency ceilings and emergency compression disable.
- No compression for already-compressed, range/video, or excluded content.
- Correct `Vary: Accept-Encoding`.
- Query policy: all, none, include-list, or ignore-list.
- Bounded cookie bypass and cache variants.
- Status-code TTL policy for explicitly supported responses.
- Cache lock/request collapsing, admission limits, stale-if-error, and stale-while-revalidate.
- Exact URL and full epoch purge preserved.
- Compression and bandwidth-savings telemetry.

### Scale and failure checkpoint

- Cache HIT load with identity, Gzip, and Brotli clients.
- CPU saturation and fallback behavior.
- Disk-full, minimum-free-space, restart, corrupt-cache, and eviction behavior.
- High-cardinality URL/query abuse.
- Large-object and range-request qualification.

### Completion checklist

- [ ] Identical decoded content is served for identity, Gzip, and Brotli.
- [ ] Cache key does not fragment unintentionally by `Accept-Encoding`.
- [ ] MIME, size, range, and precompressed exclusions work.
- [ ] CPU pressure remains bounded and can disable Brotli/compression safely.
- [ ] Query and cookie variants remain within configured limits.
- [ ] Disk pressure cannot exhaust the host or unrelated cells.
- [ ] MISS/HIT/STALE/revalidate/purge behavior remains correct with compression.
- [ ] Metrics report origin bytes, uncompressed bytes, served bytes, encoding, and savings.
- [ ] UI, API, docs, troubleshooting, load tests, and browser qualification pass.

## Phase 13 — Simple origin resilience

### Outcome

A proxied hostname can use one primary and one backup origin with active-passive failover only.

### Scope

- Primary and optional backup origin.
- Same safety validation for both origins.
- Bounded active and passive health evidence.
- Explicit failover and recovery thresholds.
- Last-known-good origin state.
- No weighted traffic, percentage splitting, Geo origin steering, discovery, or arbitrary origin pools.
- Origin selection remains local to the cell and independent from Laravel availability.
- Failover/recovery telemetry and audit.

### Completion checklist

- [ ] Healthy primary receives normal traffic.
- [ ] Qualified primary failure moves traffic to backup.
- [ ] Flapping is bounded by thresholds and recovery delay.
- [ ] Backup failure cannot create an unbounded retry loop.
- [ ] Primary recovery is controlled and observable.
- [ ] Cache correctness is preserved across origin change.
- [ ] Both origins are protected against private/loop/platform destinations.
- [ ] Real failure, recovery, restart, scale, docs, and browser qualification pass.

## Phase 14 — Managed OWASP CRS WAF

### Outcome

Optional managed application-signature protection using a pinned OWASP Core Rule Set without a customer rule language.

### Scope

- Immutable WAF-capable OpenResty cell image.
- Pinned tested ModSecurity v3 and OWASP CRS release.
- Profiles: off, monitor, balanced, and strict.
- Detection/anomaly scoring, bounded request-body inspection, and explicit response-body policy.
- WAF-capable pool/cell resource profile.
- Bounded exclusions by rule ID plus hostname, path prefix, or argument/header name.
- Expiry, administrator note, audit, and limit for exclusions.
- No raw ModSecurity directives, SecRule editor, online rule download, uploaded scripts, or plugin system.
- Monitor-first rollout, canary image/ruleset upgrade, automatic rollout pause, and last-compatible rollback.
- Rule, anomaly, action, latency, and false-positive telemetry.

### Scale and failure checkpoint

- Baseline and attack-pattern throughput comparison.
- CPU, memory, latency, body-size, multipart, and rule-count bounds.
- Monitor/block behavior and false-positive workflow.
- WAF crash or invalid ruleset cannot stop normal non-WAF pools.

### Completion checklist

- [ ] Monitor mode never blocks but records bounded sanitized events.
- [ ] Balanced and strict presets block qualified test cases.
- [ ] Exclusions are narrow, bounded, audited, and expire correctly.
- [ ] Invalid ruleset/image preserves the previous valid WAF runtime.
- [ ] WAF resource exhaustion is isolated to assigned WAF cells.
- [ ] New CRS rollout can pause and roll back safely.
- [ ] Security docs clearly separate WAF, DDoS readiness, and volumetric limits.
- [ ] API, UI, runbooks, E2E, load, and browser qualification pass.

# Part Two — Bounded future roadmap

Part Two begins only after Part One is running successfully and a real operator or customer requirement is documented. These phases are candidates, not promises.

## Phase 15 — Fleet rollout automation and extended recovery

### Candidate scope

- Canary groups and rollout waves for gateway, agent, cell, and WAF images.
- Automatic pause on health, rejection, drift, or error thresholds.
- Automatic rollback to the last compatible image.
- Fleet compatibility reporting.
- Immutable/deletion-protected backup storage.
- Scheduled isolated restore exercises.
- Optional warm control-plane standby.

### Boundary

This automates already-proven manual procedures. It does not add a general orchestration platform, dynamic unbounded containers, Kubernetes requirement, or traffic dependency on the standby.

### Admission and completion gate

- [ ] Fleet size makes manual rollout measurably inefficient.
- [ ] Manual canary/rollback and clean restore already pass.
- [ ] Automation has bounded waves, pause, rollback, audit, and operator override.
- [ ] DNS and HTTP traffic remain independent when automation/standby is unavailable.
- [ ] Real multi-host qualification and documentation pass before release.

## Phase 16 — Protocol, DNS, certificate, private-origin, and data extensions

### Candidate capabilities

Admit separately, never as one combined project:

- HTTP/3 and QUIC.
- Secondary ACME certificate authority.
- DNSSEC signing, DS lifecycle, rollover, and recovery.
- Focused outbound private-origin connector.
- Longer-retention analytics archive/export.
- Additional proven placement policy such as region restriction or maintenance evacuation.

### Explicitly outside product direction

- Serverless workers.
- Object-storage product.
- General VPN or zero-trust suite.
- Arbitrary tunnels.
- Custom WAF or routing expression language.
- CAPTCHA or browser-challenge platform.
- Bot-scoring product.
- Billing/subscription engine.
- Reseller/organization hierarchy.
- Microservices, service mesh, Kafka, or Kubernetes requirement.

### Admission gate for every capability

- [ ] A real repeated requirement and measurable acceptance criteria exist.
- [ ] Part One cannot solve it safely.
- [ ] Typed bounded desired state and authorization are defined.
- [ ] Runtime remains independent from Laravel availability.
- [ ] Failure, disablement, rollback, compatibility, observability, and recovery are defined.
- [ ] Real-runtime, scale, documentation, and browser qualification are written before implementation is declared complete.

# Final release contract

CDNFoundry is release-qualified only when:

- every admitted Part One phase has passed its completion gate;
- all current manual browser checkpoints are recorded;
- external DNS, IPv4/IPv6, public HTTPS, multi-edge, multi-cell, Anycast, cache/compression, origin-failure, WAF, outage, scale, backup, restore, upgrade, and rollback evidence is available where applicable;
- current documentation describes implemented behavior rather than plans;
- no unexecuted test is represented as passed;
- no project boundary has been weakened to complete a phase.
