---
title: Troubleshoot telemetry
description: Diagnose analytics outages, missing events, Vector buffers, ClickHouse, usage lag, and privacy.
---

# Troubleshoot telemetry

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

## DNS events have domain ID zero

The dnstap fallback does not map question names to Laravel domain IDs and stores
zero by design. Zone/name analytics remain available; usage requiring exact
domain mapping depends on enriched events.

## Usage is stale

Confirm scheduler heartbeat, `usage:finalize`, the `bulk_maintenance` lane,
ClickHouse availability, and the latest finalized `usage_rollups.interval_end`.
The component becomes degraded when no finalized interval exists or lag exceeds
three hours.

## Client address looks truncated

Normal views and exports intentionally apply the platform IPv4/IPv6 prefix mask.
Raw storage retention is short and access remains policy scoped. Do not weaken
masking to troubleshoot unrelated delivery.

Use the [ClickHouse or Vector runbook](/operations/runbooks#clickhouse-or-vector-outage).
