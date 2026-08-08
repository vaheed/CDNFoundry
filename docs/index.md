---
layout: home
title: CDNFoundry private CDN software
description: Build and operate a production private CDN with authoritative DNS, bounded OpenResty edge delivery, TLS, caching, security, analytics, and multi-region operations.
keywords: private CDN software, self-hosted CDN, ISP CDN, on-premises CDN, authoritative DNS, edge caching, PowerDNS, DNSdist, OpenResty, Laravel CDN
hero:
  name: CDNFoundry
  text: Build and operate your own private CDN
  tagline: Production-oriented authoritative DNS, bounded edge delivery, TLS, caching, security, telemetry, and multi-region operations.
  actions:
    - theme: brand
      text: Get started
      link: /getting-started/
    - theme: alt
      text: Learn CDN fundamentals
      link: /concepts/cdn-fundamentals
    - theme: alt
      text: Production quick start
      link: /deployment/production-quick-start
features:
  - title: Learn how a CDN works
    details: Follow DNS, TLS, edge routing, cache, origins, desired state, and failure behavior from first principles.
    link: /concepts/cdn-fundamentals
  - title: Architecture
    details: Compare production reference designs, planes, durable state ownership, bounded cells, and failure isolation.
    link: /architecture/production-reference-architectures
  - title: Domain users
    details: Add domains, manage DNS, proxy origins, TLS, cache, security, analytics, and usage.
    link: /guides/
  - title: Administrators
    details: Manage users, system DNS identity, DNS clusters, edge pools, operations, settings, and recovery.
    link: /operations/
  - title: API clients
    details: Use the Sanctum API, stable errors, idempotent mutations, cursor pagination, and operation receipts.
    link: /reference/api/
  - title: Operators
    details: Deploy bounded Compose roles, monitor health, rotate certificates, restore backups, and scale safely.
    link: /deployment/
  - title: Developers
    details: Work in the supported Compose environment and qualify PHP, Go, runtime, and documentation changes.
    link: /development/
  - title: Contributors
    details: Follow the product invariants, scope rules, testing contract, and documentation checks.
    link: /contributing/
---

CDNFoundry is a small, production-oriented private CDN. A Laravel and Filament
control plane owns desired state in PostgreSQL; DNS and HTTP traffic remain on
PowerDNS, DNSdist, and bounded OpenResty cells. External changes run
asynchronously through revisioned reconciliation, and invalid candidates never
replace the last valid runtime state.

## What CDNFoundry includes

| Capability | What the stack provides |
| --- | --- |
| Control plane | Laravel modular monolith, Filament administrator and domain-user panels, Sanctum API, policies, audit history, idempotent operations, Horizon, and Scheduler |
| Authoritative DNS | Public DNSdist in front of private PowerDNS, DNS-only and proxied records, Geo-DNS, revisioned reconciliation, cluster health, and UDP/TCP serving |
| Edge delivery | Stable placement across bounded OpenResty cells, explicit origins, host and SNI routing, shared and quarantine pools, draining, and last-valid snapshots |
| TLS | Managed DNS-01 certificates, encrypted private keys, custom certificate upload, renewal scheduling, and per-host certificate selection |
| Cache and performance | Deterministic cache keys, bounded admission and storage, stale serving, URL purge, epoch-based full purge, Gzip, Brotli, and origin failover |
| Security | Tenant policies, origin destination validation, trusted-client-IP handling, rate controls, managed WAF rules, quarantine, and bounded runtime resources |
| Analytics and operations | Vector pipelines, ClickHouse telemetry, Prometheus metrics, Grafana dashboards, operational logs, health checks, backups, upgrades, and recovery workflows |

## How the stack is separated

```mermaid
flowchart TB
    ExternalDNS["Independent external DNS<br/>management hostnames"] --> Control
    ExternalDNS --> DNSAPI["Restricted DNS API"]
    ExternalDNS --> EdgeControl["Edge-control mTLS ingress"]
    Users["Internet users"] -->|"DNS"| DNS["DNSdist + PowerDNS"]
    Users -->|"HTTP/HTTPS"| Gateway["Edge gateway"]
    Gateway --> Edge["Bounded OpenResty cells"]
    Edge --> Origin["Validated customer origins"]
    Admins["Administrators"] --> Control["Laravel + Filament"]
    Control --> State[("PostgreSQL desired state")]
    Control -->|"asynchronous reconciliation"| DNSAPI
    DNSAPI --> DNS
    EdgeAgent["Edge agent"] -->|"outbound mTLS"| EdgeControl
    EdgeControl --> Control
    Control -->|"signed snapshots through agent"| EdgeAgent
    EdgeAgent --> Edge
    DNS -. "best-effort telemetry" .-> Vector["Vector"]
    Edge -. "best-effort telemetry" .-> Vector
    Vector --> ClickHouse[("ClickHouse telemetry")]
    Prometheus["Prometheus metrics"] --> Grafana["Grafana operations"]
    ClickHouse -->|"bounded read-only queries"| Grafana
    State -->|"sanitized read-only metadata"| Grafana
    Admins -->|"separate operator access"| Grafana
```

The serving path does not depend on Laravel. DNS queries terminate at DNSdist
and PowerDNS; HTTP and HTTPS terminate at the edge gateway and OpenResty cells.
The control plane commits desired state, queues bounded external work, and
delivers revisioned runtime artifacts asynchronously. PostgreSQL is the durable
source of truth, while PowerDNS data, edge snapshots, artifacts, and telemetry
aggregates are rebuildable runtime state.

## Production deployment model

CDNFoundry deploys with immutable container images and generated, role-filtered
Docker Compose bundles. The smallest documented production fleet uses one
control node and two combined DNS/edge nodes in separate failure domains. Larger
fleets can separate control, DNS, edge, and monitoring roles across regions
without introducing a second application backend or a per-domain runtime.

- Start with the [production reference architectures](architecture/production-reference-architectures.md) to choose role placement and failure domains.
- Follow the [production quick start](deployment/production-quick-start.md) for a dependency-ordered first installation.
- Use the [feature guides](guides/index.md) for domains, DNS, origins, TLS, cache, security, and analytics.
- Keep the [operations runbooks](operations/runbooks.md) available for diagnosis, rollback, backup, and recovery.

## Who it is for

CDNFoundry is designed for companies, hosting providers, and ISPs that operate
their own authoritative DNS and edge capacity. It favors predictable failure,
bounded resource use, explicit infrastructure ownership, and a small operational
surface. It is not a hosted CDN service and does not claim upstream volumetric
DDoS protection when network capacity is saturated.

Grafana is a read-only operations component in the telemetry role. It has no
request-path or reconciliation responsibility: an observability outage cannot
change desired state or stop DNS and HTTP serving.

Start with [the product overview](getting-started/index.md), or choose a destination
from the audience cards above. The [documentation audit](contributing/documentation-audit.md)
records how this site was reconstructed from the implementation and what remains
outside the verified product boundary.
