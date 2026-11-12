---
title: Architecture components
description: Reference the responsibility and failure boundary of every CDNFoundry service.
---

# Architecture components

This page lists each CDNFoundry service, what it does, and what happens when it
fails. Service names match `compose.prod.yml`. See
[Services and ports](../reference/services-and-ports.md) for profiles and listeners.

::: info The data plane is separate
DNS queries, visitor HTTP and TLS traffic, certificate selection, security
decisions, and raw telemetry never pass through `core`. This separation keeps a
management outage from becoming a serving outage.
:::

## Control plane

| Component | Implementation | Responsibility |
| --- | --- | --- |
| `core` | PHP 8.5, Laravel 13, Filament 5 | API, panels, policies, desired state |
| `web` | Nginx | Browser/API ingress to PHP-FPM |
| `horizon` | Laravel Horizon | Seven separate queues: `interactive`, `dns`, `edge`, `verification`, `tls`, `purge`, and `bulk_maintenance` |
| `scheduler` | Laravel scheduler | Periodic bounded dispatch, retention, heartbeat |
| `control-db` | PostgreSQL 18 | Authoritative desired and operational state |
| `redis` | Valkey 9 | Queues, sessions, locks (append-only file, no eviction) |
| `cache` | Valkey 9 | Application cache (no persistence, least-recently-used eviction) |
| `edge-control` | Nginx plus the core image | Mutual-TLS entry point for edge agents |

`core`, Horizon, and the scheduler are separate processes. Starting the
application image does not run database migrations. You run them explicitly.

## DNS

| Component | Responsibility |
| --- | --- |
| `dnsdist` | The only public authoritative DNS endpoint. Chooses a backend and sends limited dnstap events |
| `pdns-auth` | A private PowerDNS DNS node. It is a secondary that pulls zones from `pdns-primary`; its local SQLite store is a cache the primary can rebuild |
| `pdns-db` | PostgreSQL database for the hidden primary. Rebuildable from the control database |
| `pdns-migrate` | One-shot tool for PowerDNS runtime migrations |
| `pdns-primary` | The hidden primary PowerDNS on the control host. Laravel writes zones here. DNS nodes pull them over TSIG-signed transfers on port 5300 |

PowerAdmin exists only in the development Compose file and is for diagnosis.
CDNFoundry treats any edit made there as drift.

## Edge

| Component | Responsibility |
| --- | --- |
| `edge-agent` | Enrollment, signed artifacts, atomic activation, heartbeat, tasks |
| `cell-01` through `cell-08` | Fixed-name, resource-limited OpenResty slots. The control plane assigns each slot to a pool |
| `edge-gateway` | Listens on the service addresses on the host network. Routes by destination, Host, and SNI to the assigned cells |
| `mmdb-updater` | Downloads, validates, and activates the GeoIP database in one step |

OpenResty reads certificates and domain settings from runtime JSON. It applies
limits on requests, connections, headers, bodies, origins, and cache. A normal
domain change creates no Nginx server block and no reload. See
[Edges, pools, and cells](../concepts/edges-and-cells.md).

## Telemetry

| Component | Responsibility |
| --- | --- |
| `vector` | Redacts, normalizes, buffers, and delivers edge and DNS events |
| `log-collector` | One per host. Normalizes and redacts container logs and optional journal logs |
| `clickhouse` | Raw events plus materialized aggregates (for example `edge_hourly` and `dns_hourly`) |
| `loki` | Operational logs with limited retention, on local filesystem storage |
| `prometheus` | Metrics and alert rules |
| `alertmanager` | Alert routing to the control plane's private alert receiver |
| `notifier` | Laravel queue worker for the `notifications` lane; the only sender to Telegram, Discord, Slack, and webhooks |
| `node-exporter` | Host resource and clock metrics |
| `grafana` | Provisioned read-only dashboards in folders such as overview, NOC, SOC, and platform admin |
| `grafana-control-db-provision` | One-shot job that creates the restricted PostgreSQL role for Grafana. Safe to repeat |

The `edge-gateway` binds the configured service IPv4 and IPv6 addresses. It
sends PROXY protocol version 2 to the private cell listeners.

The traffic Vector has a separate disk buffer for each ClickHouse sink: 1 GiB
for edge events, and 256 MiB each for security events and DNS events. The host
log collectors have their own disk buffer, 2 GiB in `.env.prod.example`
(`LOG_BUFFER_BYTES`). Every buffer drops the newest events when it is full. A
Loki failure therefore cannot disturb ClickHouse ingestion or serving.

Grafana reads Prometheus, ClickHouse telemetry tables, Loki, and a sanitized
PostgreSQL view named `grafana_domain_operational_metadata`. It has no write
credential and no role in ingestion or serving. If telemetry or monitoring
fails, you see the gap, but serving continues.

## Development-only components

`compose.dev.yml` adds Pebble (a local ACME directory), two Nginx origin
fixtures for HTTP and HTTPS origins, `dev-pki` (development certificates), and
PowerAdmin (DNS diagnostics). None of them belongs in production.
