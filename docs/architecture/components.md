---
title: Architecture components
description: Reference the responsibility and failure boundary of every CDNFoundry service.
---

# Architecture components

## Control plane

| Component | Implementation | Responsibility |
| --- | --- | --- |
| `core` | PHP 8.5, Laravel 13, Filament 5 | API, panels, policies, desired state |
| `web` | Nginx | Browser/API ingress to PHP-FPM |
| `horizon` | Laravel Horizon | Four isolated Redis queue lanes |
| `scheduler` | Laravel scheduler | Periodic bounded dispatch, retention, heartbeat |
| `control-db` | PostgreSQL 18 | Authoritative desired and operational state |
| `redis` | Valkey 9 | Queues, sessions, cache, locks |
| `edge-control` | Nginx plus the core image | Mutual-TLS edge-agent ingress |

`core`, Horizon, and the scheduler are independent processes. Application image
startup creates writable directories but deliberately does not migrate.

## DNS

| Component | Responsibility |
| --- | --- |
| `dnsdist` | Only public authoritative DNS endpoint, backend selection, bounded dnstap |
| `pdns-auth` | Private PowerDNS authoritative service |
| `pdns-db` | Rebuildable PowerDNS runtime schema |
| `pdns-migrate` | Explicit PowerDNS runtime migration tool |
| `dns-api` overlay | Source-restricted TLS proxy for the private PowerDNS API |

PowerAdmin exists only in the development-tools profile and is diagnostic.
Direct edits are drift.

## Edge

| Component | Responsibility |
| --- | --- |
| `edge-agent` | Enrollment, signed artifacts, atomic activation, heartbeat, tasks |
| `edge` | Shared OpenResty cell |
| `edge-quarantine` | Smaller isolated quarantine cell |
| `mmdb-updater` | Download, validate, and atomically activate the GeoIP database |

OpenResty selects certificates and domain configuration from data-driven
runtime JSON. It applies bounded request, connection, header, body, origin, and
cache policies. No normal domain change generates an Nginx server block or reload.

## Telemetry

| Component | Responsibility |
| --- | --- |
| `vector` | Redact, normalize, buffer, and deliver edge/DNS events |
| `log-collector` | One per host; normalize/redact bounded operational container and optional journal logs |
| `clickhouse` | Raw events plus hourly and daily materialized aggregates |
| `loki` | Bounded retained operational logs using TSDB and filesystem object storage |
| `prometheus` | Metrics and alert evaluation |
| `alertmanager` | Alert routing |
| `node-exporter` | Host resource and clock metrics |
| `grafana` | Exactly two provisioned read-only operations command centers |
| `grafana-control-db-provision` | Idempotent one-shot creation and restriction of the PostgreSQL Grafana role |
| Edge gateway | Binds configured service IPv4/IPv6 addresses and routes by destination plus validated Host/SNI; sends PROXY protocol version 2 to private cell listeners |

The traffic Vector has separate 1 GiB disk buffers for edge and DNS sinks. The
independent host collectors have 2 GiB production disk buffers. Both drop newest
events when full, so Loki failure cannot disturb ClickHouse ingestion or serving.
ClickHouse exposes private server metrics to Prometheus on
port 9363. Grafana reads Prometheus, six CDNFoundry ClickHouse telemetry tables,
three ClickHouse monitoring tables, four PostgreSQL domain inventory columns,
and the sanitized `grafana_domain_operational_metadata` view, plus Loki. It has no write
credential and no ingestion or serving role. Telemetry or observability loss is
visible but never blocks serving.

## Development-only components

Pebble provides a local ACME directory, two Nginx origin fixtures provide HTTP
and HTTPS origins, `dev-pki` initializes persistent development certificates,
and PowerAdmin provides runtime diagnostics. None belongs in production.
