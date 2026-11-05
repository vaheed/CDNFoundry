---
title: Troubleshoot telemetry
description: Fix analytics 503 errors, missing events, full Vector buffers, DNS events with domain ID zero, stale usage, and masked client addresses.
---

# Troubleshoot telemetry

Use this page when analytics pages fail, events are missing, or usage numbers are
old. You need administrator access.

::: info Serving continues
A telemetry outage does not stop DNS or HTTP. During a long outage, bounded buffers
fill and then discard new events. Missing history cannot always be recovered
after the sink returns.
:::

## Analytics returns 503

The error code `analytics_unavailable` means the bounded ClickHouse request failed.
Check ClickHouse health, credentials, URL and TLS settings, the query timeout, and
the network. Serving traffic should stay healthy.

## Events are missing

Follow the path the event takes:

- OpenResty syslog or HTTP source, then Vector `safe_edge_events`.
- DNSdist dnstap, then Vector `decoded_dns_dnstap`, then `safe_dns_events`.
- The Vector sink, then the ClickHouse table.

Inspect Vector transform errors, discarded-event counters, disk-buffer bytes,
delivery errors, and ClickHouse insert errors. You cannot rebuild events dropped
after a buffer filled.

Each sink has its own disk buffer. All of them use `drop_newest` when full:

| Stream | Disk buffer |
| --- | --- |
| Edge requests and billing | 1 GiB |
| Security events | 256 MiB |
| DNS telemetry | 256 MiB |

A flood of ordinary requests cannot push out security events, and DNS has a smaller
loss budget. A long ClickHouse outage can still fill any buffer. Before you bill
from an interval with a gap, compare it with other durable records.

The administrator **Traffic and telemetry** page shows labeled Vector Prometheus series. Buffer
bytes and events are current values. Discarded events and component errors are
counters since Vector started. When the collector is available, the page shows zeros
instead of an empty panel.

## DNS events have domain ID zero

The dnstap fallback does not map question names to Laravel domain IDs, so it stores
zero by design. Zone and name analytics still work. Usage that needs an exact domain
mapping depends on enriched events.

## Usage is stale

<div class="steps">

1. Confirm the scheduler is running.
2. Confirm the `cdnf:usage:finalize` command runs. The scheduler starts it hourly,
   at 20 minutes past the hour.
3. Check ClickHouse availability.
4. Find the newest finalized `usage_rollups.interval_end`.

</div>

**Expected result:** the Usage rollups component is healthy. It becomes degraded
when no finalized interval exists or the newest one is more than three hours old.

The live 24-hour analytics range includes the newest `finalization_delay_minutes`
window. The blue **Live window included** label is information, not a health problem.
The **Finalized usage** section is separate. It reads stable PostgreSQL rollups and
shows the latest five intervals.

## Client address looks truncated

Normal views and exports apply the platform IPv4 and IPv6 prefix mask on purpose.
Raw storage is kept briefly, and access follows policy. Do not weaken masking to
debug an unrelated delivery problem.

Use the
[ClickHouse or Vector runbook](../operations/runbooks.md#clickhouse-or-vector-outage).
