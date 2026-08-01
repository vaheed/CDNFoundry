---
title: Laravel operations dashboard
description: Architecture, data sources, refresh behavior, limits, and failure semantics for the administrator operations overview.
---

# Laravel operations dashboard

The administrator operations overview is the existing `/admin` Filament page.
It is not a second frontend or an additional observability backend. PostgreSQL
remains authoritative for desired and operational state; ClickHouse, Vector,
Redis/Horizon, and health probes provide derived read-only evidence. Dashboard
failure never changes DNS, edge runtime, TLS, cache, or security state.

## Audited implementation plan

1. Replace the dashboard-wide ten-second Livewire poll with independently
   polling Filament widgets.
2. Add a URL-persisted, server-validated investigation context for the bounded
   quick ranges, previous-period comparison, domain, and edge.
3. Add short-lived authorization-aware caches around focused health, traffic,
   DNS, queue, edge, and operations snapshots. Identical administrator queries
   share cache entries.
4. Render ClickHouse hourly aggregates through Filament's bundled Chart.js
   widget. Keep query construction in `AnalyticsStore` and dashboard
   normalization outside Blade.
5. Derive read-only operational conditions from existing component health,
   failed operations, stale heartbeats, deployment state, and telemetry
   freshness. Do not add an incident-management database in this iteration.
6. Preserve the existing Telemetry and domain Analytics pages as detailed
   drill-down destinations while passing the selected time/domain/edge context
   in their URLs where supported.
7. Cover context validation, comparison calculations, cache isolation,
   authorization, outage/no-data/stale rendering, polling, and drill-down URLs
   with isolated tests. Browser layout, keyboard, dark-mode, and responsive
   qualification remain owner-run.

## Data and failure boundaries

| Surface | Source | Bound | Failure behavior |
| --- | --- | --- | --- |
| Service condition | PostgreSQL plus existing bounded health probes | Ten-second cache | Unknown or critical; never healthy by default |
| HTTP KPIs and charts | `cdnf.edge_hourly` | At most seven days and 168 hourly points per period | Explicit query-failed state |
| DNS KPIs | `cdnf.dns_hourly` plus cluster desired state | At most seven days and 168 hourly points per period | Partial or unavailable state |
| Edge health | PostgreSQL edge/cell state | Cursor-like table pagination; eager-loaded relationships | Last known state with stale heartbeat label |
| Queue health | Redis and Horizon | Four configured bounded lanes | Unavailable lane, not zero |
| Timeline | PostgreSQL operations and audit log | Latest bounded rows only | Independent empty/error state |

The overview supports one hour, six hours, 24 hours, and seven days, with 24
hours as the default. Every selection uses complete hourly aggregates, avoiding
a short range that could imply raw-data precision the overview does not
provide. Raw request logs remain a bounded drill-down source and are not
scanned to calculate overview KPIs.

The edge table's **Peak resource** is the highest reported utilization across
memory, cache storage, temporary storage, and active connections for any cell
on that edge. It is not customer traffic load. The badge names the limiting
resource, and its tooltip shows the cell plus used and configured limit. At
80% it warns and at 90% it becomes critical so an otherwise idle edge can still
correctly report memory or storage pressure.

Dashboard investigation links preserve an actionable target. General runtime
failures open Operations with the Failed filter already active; edge-capacity
conditions open Edges; authoritative DNS conditions open DNS clusters.

Telemetry places the selected-range KPI summary before its charts. Aggregate
tables may cover the full selected period, while raw Recent logs and compression
previews use the selected duration capped at the latest 24 hours. This keeps a
7-day investigation useful without permitting an unbounded raw-event scan.

## Metrics intentionally not inferred

The current aggregate schema does not contain edge p50/p95/p99 request latency,
origin latency percentiles, DNS latency, queue throughput/retry counters, or a
financial bandwidth-savings model. The operations overview shows the valid
origin average-latency sample and available queue depths, and labels unsupported
values as unavailable instead of estimating them.

## Refresh and cache budget

- service condition and active conditions: 10 seconds;
- edge and queue state: 10 seconds;
- traffic, error, latency, and KPI aggregates: 15 seconds;
- cache and DNS summaries: 30 seconds;
- operations timeline: 10 seconds;
- freshness evidence: 15 seconds.

Cache keys include the role scope and normalized range/domain/edge filters.
The target budget is under 500 ms for cached widget refreshes, under two seconds
for a common uncached aggregate snapshot, no unbounded query, and no page-wide
five-second refresh. Actual measurements must be recorded from a running stack;
these targets are not claims of measured performance.

On 2026-08-01, the running development stack measured the one-hour traffic
snapshot at 388.18 ms after deleting only that snapshot's cache key and 1.56 ms
on the immediate cache hit. The comparison-enabled cold snapshot issued two
bounded ClickHouse aggregate queries and returned no matching traffic rows in
that environment. This is a widget-service measurement, not an end-to-end page
render or production benchmark; no reliable pre-change timing was captured.
