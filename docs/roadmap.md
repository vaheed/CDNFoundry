---
title: Roadmap and implementation status
description: CDNFoundry product contract, current implementation boundary, completion gates, and future admission rules.
---

# Roadmap and implementation status

This page is the product contract referenced by `AGENTS.md`. The original
long-form roadmap, appendices, and dated evidence are preserved at
`docs/legacy/roadmap.md`; they are historical, not current documentation.

## Product contract

CDNFoundry is a small production-grade private CDN with predictable failure
behaviour, bounded resource use, and low operational complexity.

- One Laravel modular monolith and two Filament panels own management.
- PostgreSQL owns desired state.
- DNS, HTTP, security decisions, certificate selection, and raw telemetry never
  pass through Laravel.
- External effects are asynchronous, revisioned, idempotent, coalesced, and
  last-valid-state preserving.
- Scale comes from workers, DNS capacity, ClickHouse capacity, edges, and
  bounded OpenResty cells.
- A domain does not receive a default process, container, worker, timer, Nginx
  server block, cache directory, or reload.

Part One is implemented through the code surfaces described below. A phase is
release complete only when implementation, current documentation,
agent-owned automated/runtime qualification, and owner-run manual browser
qualification are each recorded.

## Phase 1: foundation, access, and system identity

Implemented behaviour:

- administrator and assigned-domain-user panels;
- Sanctum login, profile, password, token, disable/enable, audit, and idempotency;
- policy-aware domain and operation binding;
- typed system DNS identity preview, exact confirmation, asynchronous apply;
- health, readiness, operation, Horizon, scheduler, and Compose foundations.

### Phase 1 completion gate

| Gate | Status |
| --- | --- |
| Implementation | Present in current code |
| Documentation | Current in this site |
| Automated/runtime qualification | Covered by current PHP and `tests/e2e/e2e.py`; rerun for each release |
| Manual browser qualification | Not recorded for the current revision |

## Phase 2: domains and authoritative DNS

Implemented behaviour:

- normalized domain lifecycle, assignment, delegation verification, activation,
  delayed deprovisioning, tombstones, and reclaim cooldown;
- A, AAAA, CNAME, MX, TXT, NS, CAA, SRV, and reverse-zone PTR;
- transactional bulk changes, BIND import/export, monotonic serials;
- DNS cluster management, health tests, deterministic reconciliation, drift and
  last-valid-zone preservation;
- DNSdist-only public ingress and separate PowerDNS runtime migrations.

### Phase 2 completion gate

| Gate | Status |
| --- | --- |
| Implementation | Present in current code |
| Documentation | Current in domain, DNS, deployment, and reference guides |
| Automated/runtime qualification | Covered by PHP, `phase2_dns.py`, and separate `phase2_scale.py`; rerun for each release |
| Manual browser qualification | Not recorded for the current revision |

## Phase 3: Geo-DNS

Implemented behaviour:

- country, continent, then default answer selection;
- bounded targets and geography vocabulary;
- IPv4/IPv6 preview and unknown fallback;
- deterministic PowerDNS Lua compilation;
- validated, atomically updated local MMDB with last-valid preservation.

CAA remains DNS-only because it is not in the qualified Geo-DNS runtime type
list.

### Phase 3 completion gate

| Gate | Status |
| --- | --- |
| Implementation | Present in current code |
| Documentation | Current in Geo-DNS and DNS references |
| Automated/runtime qualification | Covered by PHP and `phase3_geo_dns.py`; external vantage points remain owner evidence |
| Manual browser qualification | Not recorded for the current revision |

## Phase 4: proxy, edge routing, and edge agent

Implemented behaviour:

- safe explicit origin per proxied hostname;
- shared, quarantine, and exceptional dedicated pool model;
- edge/cell desired state, addresses, enrollment, mutual TLS, identity rotation;
- signed incremental artifacts and bounded full recovery snapshots;
- atomic agent activation, persistent acknowledgements, heartbeat/capacity;
- target-first placement and source drain;
- data-driven OpenResty request path with no per-domain reload.

### Phase 4 completion gate

| Gate | Status |
| --- | --- |
| Implementation | Present in Laravel, Go, Nginx, and Lua |
| Documentation | Current in edge, proxy, architecture, API, and deployment guides |
| Automated/runtime qualification | Covered by PHP, Go, `phase4_control_plane.py`, `phase4_mtls.py`, and `phase4_runtime.py`; rerun for each release |
| Manual browser qualification | Not recorded for the current revision |

## Phase 5: TLS, cache, and purge

Implemented behaviour:

- managed ACME DNS-01 only for eligible proxied domains;
- bounded name sets, renewal, retries, cleanup, alerts, and certificate reuse;
- validated encrypted custom certificate upload;
- deterministic cache settings, bypass/admission, development mode, stale grace;
- epoch full purge, exact URL purge, durable per-edge delivery and retry;
- revision rollback without decrementing revision numbers.

### Phase 5 completion gate

| Gate | Status |
| --- | --- |
| Implementation | Present in current code |
| Documentation | Current in TLS, cache, API, operations, and troubleshooting |
| Automated/runtime qualification | Covered by PHP, `phase5_tls.py`, and cumulative edge/runtime programs; rerun for each release |
| Manual browser/public HTTPS qualification | Not recorded for the current revision |

## Phase 6: security and DDoS readiness

Implemented behaviour:

- ordered bounded allow/block rules and imports;
- standard, protected, quarantine, and bounded manual profiles;
- IPv4/IPv6 trusted client and rule handling;
- readiness states, bounded reason codes, target-first quarantine and recovery;
- persisted expiring emergency actions for domains, cells, edges, and pools;
- cell resource isolation and explicit volumetric-limit statement.

### Phase 6 completion gate

| Gate | Status |
| --- | --- |
| Implementation | Present in control and edge runtimes |
| Documentation | Current in security, hardening, limits, and runbooks |
| Automated/runtime qualification | Covered by PHP and `phase6_security.py`; rerun for each release |
| Manual browser/real-traffic qualification | Not recorded for the current revision |

## Phase 7: logs, analytics, and usage export

Implemented behaviour:

- direct Vector-to-ClickHouse edge and DNS telemetry;
- bounded/redacted schemas and disk buffers;
- raw logs, aggregates, outage/partial labels, IPv4/IPv6 masking;
- domain/admin analytics separation;
- idempotent hourly PostgreSQL usage rollups and stable JSON/CSV export;
- telemetry failure independent from serving.

### Phase 7 completion gate

| Gate | Status |
| --- | --- |
| Implementation | Present in current code and telemetry configuration |
| Documentation | Current in analytics, schema, monitoring, and troubleshooting |
| Automated/runtime qualification | Covered by PHP and `phase7_analytics.py`; rerun for each release |
| Manual browser qualification | Not recorded for the current revision |

## Phase 8: operations and production qualification

Implemented behaviour:

- component/queue/capacity health, token-protected metrics, alerts;
- failed-job and operation management, bounded global reconciliation;
- encrypted Restic backup, authenticated restore preflight, maintenance executor;
- explicit migration, production image, split-host, IPv6, and external-data contracts;
- upgrade, recovery, throughput, MMDB, restart, and isolation programs.

### Phase 8 completion gate

| Gate | Status |
| --- | --- |
| Implementation | Present in current code and infrastructure |
| Documentation | Current in deployment, operations, security, development, and upgrade guides |
| Automated/runtime qualification | Covered by PHP and phase 8 Python programs; full external environment evidence remains operator-owned |
| Manual browser and production release qualification | Not recorded for the current revision |

## Current release boundary

Because the manual browser and external production evidence is not recorded for
the current revision, Part One is implementation-complete but not represented
here as owner release-qualified. Historical evidence remains in
`docs/legacy/phase-*-qualification.md`.

## Future admission

Potential future work includes HTTP/3, alternative certificate workflows,
private origin connectivity, larger fleet automation, extended disaster
recovery, and proven customer extensions. None is implemented or promised by
the current product.

A future capability is admitted only when:

- a real operator/user problem and measurable acceptance criteria exist;
- it preserves the product invariants and request-path independence;
- it has typed bounded state, authorization, failure behaviour, rollback,
  observability, real-runtime tests, documentation, and manual qualification;
- disabling it leaves Part One functional;
- it does not introduce speculative microservices, Kubernetes requirements,
  plugins, billing hierarchies, custom expression languages, or per-domain
  runtime processes.

## Final architecture rule

Keep management in one understandable Laravel application. Keep DNS and HTTP
traffic independent. Store desired behaviour as validated bounded data. Use the
same reconciliation discipline for every external change. Preserve the last
valid state, isolate edge resources by bounded cells, and never claim
volumetric protection after physical capacity is saturated.
