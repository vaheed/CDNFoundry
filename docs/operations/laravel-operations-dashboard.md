---
title: Laravel operations dashboard
description: Architecture, data sources, refresh behavior, limits, and failure semantics for the administrator operations overview.
---

# Laravel operations dashboard

<Screenshot name="admin-overview" alt="The administrator overview with platform health and recent operations." />

The **Overview** page in the admin panel (`/admin`) shows platform health at a glance. It answers three
questions: is the platform healthy, is traffic flowing, and what needs attention now.

It is a normal Filament page in the existing admin panel. It has no database of its own. PostgreSQL holds the
authoritative state. ClickHouse hourly aggregates, Vector probes, Redis/Horizon queue data, and health checks
add read-only evidence. If the page fails, DNS, edge runtime, TLS, cache, and security state do not change.

## What the page shows

The page has four blocks: a service status banner, KPI tiles, a list of active conditions, and an operations
timeline. A filter bar at the top sets the time range, domain, and edge. The selection is stored in the page URL.
The server checks it, so a bad value falls back to the default.

| Filter | Choices |
| --- | --- |
| **Time range** | **Last hour**, **Last 6 hours**, **Last 24 hours** (default), **Last 7 days** |
| **Domain** | **All domains**, or one domain (search by name) |
| **Edge** | **All edges**, or one edge (search by name) |
| **Compare previous period** | On by default |

An active condition is read-only and is derived from component health, failed operations, stale heartbeats,
deployment state, and telemetry freshness. The page does not keep an incident database. The **Telemetry** page
and the domain **Analytics** page stay the detailed drill-down destinations.

## Data and failure boundaries

| Surface | Source | Bound | Failure behavior |
| --- | --- | --- | --- |
| Service condition | PostgreSQL plus existing bounded health probes | Ten-second cache | Unknown or critical; never healthy by default |
| HTTP KPIs and charts | `cdnf.edge_hourly` | At most seven days and 168 hourly points per period | Explicit query-failed state |
| DNS KPIs | `cdnf.dns_hourly` plus cluster desired state | At most seven days and 168 hourly points per period | Partial or unavailable state |
| Edge health | PostgreSQL edge/cell state | Cursor-like table pagination; eager-loaded relationships | Last known state with stale heartbeat label |
| Queue health | Redis and Horizon | Seven configured queues (`interactive`, `dns`, `edge`, `verification`, `tls`, `purge`, `bulk_maintenance`) | Unavailable lane, not zero |
| Timeline | PostgreSQL operations and audit log | Latest bounded rows only | Independent empty/error state |

Every range uses complete hourly aggregates. A short range therefore does not imply raw-data precision that the
overview does not have. Raw request logs are a bounded drill-down source. The overview never scans them to
calculate KPIs.

The edge table's **Peak resource** column shows the highest reported use of memory, cache storage, temporary
storage, or active connections on any cell of that edge. It is not customer traffic load. The badge names the
limiting resource. Its tooltip shows the cell, the used amount, and the configured limit. The badge warns at 80%
and turns critical at 90%, so an idle edge can still report memory or storage pressure.

Investigation links open the right place. Runtime failures open **Operations** filtered to failed operations.
Edge-capacity conditions open **Edges**. Authoritative DNS conditions open **DNS clusters**.

The **Telemetry** page shows the KPI summary for the selected range before its charts. Aggregate tables can
cover the whole range. Raw recent logs and compression previews use the selected duration, capped at the latest
24 hours. This keeps a 7-day investigation useful without scanning unbounded raw events.

## Metrics the page does not show

The current aggregate schema has no edge p50/p95/p99 request latency, no origin latency percentiles, no DNS
latency, no queue throughput or retry counters, and no bandwidth-savings model. The page shows the valid origin
average-latency sample and the available queue depths. It labels everything else as unavailable. It never
estimates a value.

## Refresh and cache

The server caches each section for a short time. The cache key includes the role scope and the normalized
range, domain, and edge filters, so one administrator's filter never shows another's data.

| Section | Cache time |
| --- | --- |
| Service condition and active conditions (`system`) | 10 seconds |
| Queue health (`queues`) | 10 seconds |
| Operations timeline (`operations`) | 10 seconds |
| Traffic, error, latency, and cache KPIs (`traffic`) | 15 seconds |
| DNS summary (`dns`) | 30 seconds |

The edge health table refreshes every 10 seconds. The traffic and error charts refresh every 15 seconds. Each
widget polls on its own, so the page never reloads as a whole. Data freshness is computed from the cached
sections plus the latest edge heartbeat. It marks a heartbeat older than 90 seconds as delayed and older than
300 seconds as stale.

The design target is under 500 ms for a cached widget refresh and under two seconds for a common uncached
aggregate snapshot. These are targets, not measured claims.

One measurement exists. On 2026-08-01, on the development stack, the one-hour traffic snapshot took 388.18 ms
after deleting only its cache key. The immediate cache hit took 1.56 ms. The cold snapshot ran two bounded
ClickHouse aggregate queries and found no matching traffic rows. This measures the widget service only. It is
not a page render time or a production benchmark.

## Test coverage

Isolated tests cover context validation, comparison math, cache isolation, authorization, and the outage,
no-data, and stale states. The browser suite in `tests/browser` covers layout, keyboard use, dark mode, and
responsive behavior.
