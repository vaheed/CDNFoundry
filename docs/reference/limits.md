---
title: Limits reference
description: Reference CDNFoundry API, DNS, edge, cache, security, analytics, and resource bounds.
---

# Limits reference

::: caution Limits protect shared capacity
These ceilings are correctness and failure-isolation controls, not sizing
recommendations. Do not remove them to solve load; measure the constrained
resource and add bounded capacity instead.
:::

These limits are enforced by application validation or the shipped runtime.
Platform settings may make a limit stricter within the documented bound.

## API and bulk work

| Resource | Limit |
| --- | --- |
| Normal list page | 50 or 100 items depending on endpoint |
| Edge routing snapshot | 250 edges per page |
| Edge manifest | 500 artifacts |
| Full edge snapshot | 100,000 domain artifacts, 96 MiB response |
| BIND import | 1 MiB and 5,000 records |
| Asynchronous import threshold | over 64 KiB or over 100 physical lines |
| URL purge | 100 URLs, 2,048 characters each, 128 KiB payload |
| Outstanding purges | 100 per domain |
| Security rules | 1,000 per domain, 500 per import |
| Usage export | 10,000 rows |
| TLS upload | 16 KiB leaf, 64 KiB chain, 16 KiB key |

## Queue lanes

| Lane | Production processes | Worker memory | Tries | Timeout |
| --- | --- | --- | --- | --- |
| `interactive` | 4 | 128 MiB | 1 | 60 s |
| `runtime` | 3 | 128 MiB | 3 | 120 s |
| `certificate_purge` | 2 | 128 MiB | 3 | 120 s |
| `bulk_maintenance` | 1 | 192 MiB | 2 | 300 s |

Health marks a queue degraded above depth 1,000 or oldest age 900 seconds.

## Security profiles

| Limit | Standard | Protected | Quarantine |
| --- | ---: | ---: | ---: |
| Requests/second | 100 | 50 | 10 |
| Request burst | 200 | 75 | 10 |
| Connections/client | 64 | 24 | 4 |
| Connections/domain | 512 | 256 | 48 |
| TLS handshakes/second | 50 | 20 | 5 |
| Maximum body bytes | 16,777,216 | 8,388,608 | 1,048,576 |
| Maximum header bytes | 32,768 | 16,384 | 8,192 |
| Header timeout seconds | 10 | 7 | 5 |
| Body timeout seconds | 30 | 15 | 10 |
| Keepalive seconds | 30 | 15 | 5 |
| Requests/connection | 1,000 | 250 | 50 |
| Maximum request seconds | 60 | 30 | 15 |
| Origin connections | 128 | 64 | 16 |
| Origin connect seconds | 3 | 2 | 1 |
| Origin read/send seconds | 30 | 15 | 10 |
| Origin retry limit | 2 | 1 | 0 |
| Origin failure threshold | 10 | 5 | 3 |
| Origin recovery seconds | 30 | 60 | 120 |
| Maximum cache-key bytes | 4,096 | 2,048 | 1,024 |
| Cache admissions/second | 50 | 20 | 5 |

Manual profile values cannot exceed the standard ceiling.

## Standard cell resources

| Cell | Memory | CPU | PID limit | Cache max |
| --- | ---: | ---: | ---: | ---: |
| Shared | 2 GiB | 2 | 256 | 192 MiB |
| Quarantine | 512 MiB | 0.5 | 128 | separate 192 MiB volume |
| Edge agent | 128 MiB | 0.25 | 64 | none |

The OpenResty runtime uses 10 MiB general limit state, 32 MiB security state,
10 MiB client-connection state, 10 MiB hostname-connection state, and 10 MiB
request-rate state. Attacker-controlled keys remain length and lifetime bounded.

## Analytics and telemetry

| Resource | Limit |
| --- | --- |
| Aggregate query range | 90 days |
| Raw query range | 24 hours |
| Vector sink batch | 1,000 events or 2 seconds |
| Vector disk buffer | 1 GiB per ClickHouse sink |
| Vector dnstap frame | 102,400 bytes |
| Vector dnstap connections | 32 |
| Raw event TTL | 7 days |
| Hourly aggregate TTL | 400 days |
| Daily aggregate TTL | 3 years |

## Runtime health

Edge capacity health scans at most 1,000 cells and marks pressure at 80% of a
reported memory, cache, temporary-storage, or connection limit. The default
fresh-heartbeat threshold is 45 seconds.
