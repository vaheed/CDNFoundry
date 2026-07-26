---
layout: home
title: CDNFoundry documentation
description: Deploy, operate, use, integrate, and develop the CDNFoundry private CDN.
keywords: private CDN, build private CDN, ISP CDN software, self-hosted CDN, on-premises CDN, PowerDNS, OpenResty
hero:
  name: CDNFoundry
  text: Private CDN documentation
  tagline: Verified guidance for the control plane, authoritative DNS, edge runtime, telemetry, and production operations.
  actions:
    - theme: brand
      text: Get started
      link: /getting-started/
    - theme: alt
      text: Production quick start
      link: /deployment/production-quick-start
features:
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

```mermaid
flowchart LR
    Users["Internet users"] -->|"DNS"| DNS["DNSdist + PowerDNS"]
    Users -->|"HTTP/HTTPS"| Edge["Bounded OpenResty cells"]
    Edge --> Origin["Validated customer origins"]
    Admins["Administrators"] --> Control["Laravel + Filament"]
    Control --> State[("PostgreSQL desired state")]
    Control -->|"asynchronous reconciliation"| DNS
    Control -->|"signed snapshots"| Edge
    DNS -. "best-effort telemetry" .-> Analytics["Vector + ClickHouse"]
    Edge -. "best-effort telemetry" .-> Analytics
```

::: info Designed for private operators and ISPs
CDNFoundry is intended for organizations that operate their own authoritative
DNS and edge capacity. It favors predictable failure, bounded resources, and
operational clarity over a hyperscale public-cloud feature surface.
:::

Start with [the product overview](/getting-started/), or choose a destination
from the audience cards above. The [documentation audit](/contributing/documentation-audit)
records how this site was reconstructed from the implementation and what remains
outside the verified product boundary.
