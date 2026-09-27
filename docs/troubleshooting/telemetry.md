---
title: Troubleshoot telemetry
description: Diagnose analytics outages, missing events, Vector buffers, ClickHouse, usage lag, and privacy.
---

# Troubleshoot telemetry

::: info Serving remains independent
A telemetry outage should never stop DNS or HTTP. Bounded buffers eventually
discard old events under a prolonged outage, so missing historical telemetry
cannot always be recovered after the sink returns.
:::

## Analytics returns 503

`analytics_unavailable` means the bounded ClickHouse request failed. Check
ClickHouse health, credentials, URL/TLS, query timeout, and network. Serving
traffic should remain healthy.

## Events are missing

Check the relevant path:

- OpenResty syslog or HTTP source → Vector `safe_edge_events`;
- DNSdist dnstap → Vector `decoded_dns_dnstap` → `safe_dns_events`;
- Vector sink → ClickHouse table.

Inspect Vector transform errors, discarded-event counters, disk-buffer bytes,
delivery errors, and ClickHouse insert errors. Events dropped after the buffer
fills cannot be reconstructed.

The edge request/billing sink has a 1 GiB disk cap, security events have a
separate 256 MiB cap, and DNS telemetry has a 256 MiB cap. All use
`drop_newest` when full. Security traffic therefore cannot be displaced by a
flood of ordinary requests, and DNS has a smaller loss budget. A prolonged
ClickHouse outage can still exhaust any cap; reconcile billing against other
durable records before using incomplete intervals.

The administrator telemetry page aggregates labeled Vector Prometheus series:
buffer bytes/events are current gauges, while discarded events and component
errors are lifetime counters since Vector started. An available collector shows
zero values explicitly instead of an empty panel.

## DNS events have domain ID zero

The dnstap fallback does not map question names to Laravel domain IDs and stores
zero by design. Zone/name analytics remain available; usage requiring exact
domain mapping depends on enriched events.

## Usage is stale

Confirm scheduler heartbeat, `cdnf:usage:finalize`, the `bulk_maintenance` lane,
ClickHouse availability, and the latest finalized `usage_rollups.interval_end`.
The component becomes degraded when no finalized interval exists or lag exceeds
three hours.

The live 24-hour analytics range intentionally includes the newest
`finalization_delay_minutes` window. The blue **Live window included** label is
informational, not degraded health. Rows in **Finalized usage** are separate,
stable PostgreSQL rollups and the page shows only the latest five finalized
intervals.

## Client address looks truncated

Normal views and exports intentionally apply the platform IPv4/IPv6 prefix mask.
Raw storage retention is short and access remains policy scoped. Do not weaken
masking to troubleshoot unrelated delivery.

Use the [ClickHouse or Vector runbook](../operations/runbooks.md#clickhouse-or-vector-outage).
