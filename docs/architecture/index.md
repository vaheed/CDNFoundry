---
title: System architecture
description: See CDNFoundry component boundaries, state ownership, and failure isolation.
---

# System architecture

CDNFoundry is a modular Laravel monolith surrounded by specialized data-plane
services. PostgreSQL stores desired state. PowerDNS data, signed edge artifacts,
runtime snapshots, cache contents, ClickHouse events, and aggregates are derived
or rebuildable.

| Plane | Components | Responsibility |
| --- | --- | --- |
| Management | Laravel, Filament, Horizon, scheduler | Authorization, validation, desired state, operations, reconciliation |
| Durable control data | PostgreSQL, Valkey | Desired state, audit, operation records, queues, sessions, cache |
| Authoritative DNS | DNSdist, PowerDNS, PowerDNS PostgreSQL | Public DNS ingress and private authoritative answers |
| Edge HTTP | Edge agent, OpenResty cells | Artifact activation, TLS selection, proxying, cache, security |
| Telemetry | Vector, ClickHouse, Prometheus, Alertmanager | Bounded event delivery, analytics, metrics, alerts |

Only DNSdist, intended OpenResty listeners, and the browser/API reverse proxy
belong on public ingress. Edge control uses mutual TLS. Telemetry and PowerDNS
API gateways are source restricted in the production overlays. Internal
databases, Valkey, ClickHouse, raw metrics, and PowerDNS itself remain private.

Continue with [Components](/architecture/components),
[Data flows](/architecture/data-flows), and [Data model](/architecture/data-model).
