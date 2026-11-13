---
title: Limits reference
description: Reference CDNFoundry API, DNS, edge, cache, security, analytics, and resource bounds.
---

# Limits reference

::: warning Limits protect shared capacity
These ceilings are correctness and failure-isolation controls, not sizing
recommendations. Do not remove them to solve load; measure the constrained
resource and add bounded capacity instead.
:::

These limits are enforced by application validation or the shipped runtime.
[Platform settings](platform-settings.md) can make some limits stricter within
their documented bounds.

## API and bulk work

| Resource | Limit |
| --- | --- |
| Normal list page | 50 or 100 items depending on endpoint |
| Active API tokens | 50 per user across password login, API creation, and panel creation |
| Edge routing snapshot | 250 edges per page |
| Edge manifest | 500 artifacts |
| Full edge snapshot | 100,000 domain artifacts, 96 MiB response |
| BIND import | 1 MiB and 5,000 records |
| Asynchronous import threshold | over 64 KiB or over 100 physical lines |
| URL purge | 100 URLs, 2,048 characters each, 128 KiB payload |
| Cache key | 128 query arguments; none is cached beyond that |
| Cache path rules | 16 per domain; ignored cookie names 32 |
| Persisted URL purges per PoP | 50,000 keys, kept one year |
| Outstanding purges (pending or running) | 1,000 per domain |
| Security rules | 1,000 per domain, 500 per import, 5 conditions per rule, 100 values per list condition, 20 rate-limit rules (ceiling 50) |
| Usage export | 10,000 rows |
| TLS upload | 16 KiB leaf, 64 KiB chain, 16 KiB key. Keys: RSA 2048 to 4096 bits, or EC P-256 or P-384 |

## Queue lanes

| Lane | Production processes | Worker memory | Tries | Timeout |
| --- | --- | --- | --- | --- |
| `interactive` | 4 | 128 MiB | 1 | 60 s |
| `dns` | 2 | 128 MiB | 3 | 120 s |
| `edge` | 3 | 128 MiB | 3 | 120 s |
| `verification` | 2 | 128 MiB | 3 | 120 s |
| `tls` | 2 | 128 MiB | 3 | 120 s |
| `purge` | 1 | 128 MiB | 3 | 120 s |
| `bulk_maintenance` | 1 | 192 MiB | 2 | 300 s |

Health marks a queue degraded when its depth is above 1,000 or its oldest job is older than 900 seconds.

## Security profiles

| Limit | Standard | Protected | Quarantine |
| --- | ---: | ---: | ---: |
| Requests/second | 100 | 50 | 10 |
| Request burst | 200 | 75 | 10 |
| Connections/client | 64 | 24 | 4 |
| Connections/domain | 512 | 256 | 48 |
| TLS handshakes/second per client | 50 | 20 | 5 |
| TLS handshakes/second per domain (cell ceiling, 20 × per client) | 1,000 | 400 | 100 |
| Maximum body bytes | 8,388,608 | 4,194,304 | 1,048,576 |
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

Compose sets these limits in `compose.prod.yml`. Every OpenResty cell uses the
same limits.

| Container | Memory | CPU | PID limit |
| --- | ---: | ---: | ---: |
| OpenResty cell (each of the eight slots) | 512 MiB | 0.5 | 128 |
| Edge agent | 256 MiB | 0.25 | 64 |

Each cell caches on its own volume. Four cache areas bound the disk use of one
cell:

| Cache area | Maximum size | Key index memory |
| --- | ---: | ---: |
| `small` | 256 MiB | 8 MiB |
| `standard` | 1 GiB | 16 MiB |
| `large` | 4 GiB | 32 MiB |
| `streaming` | 8 GiB | 32 MiB |

The OpenResty runtime also reserves shared memory for limit state:

| Shared state | Size |
| --- | ---: |
| Runtime limits | 10 MiB |
| Security limits | 32 MiB |
| Traffic limits | 16 MiB |
| Cache limits | 16 MiB |
| Cache purges | 16 MiB |
| Domain limits | 8 MiB |
| Connections per client address | 10 MiB |
| Connections per hostname | 10 MiB |
| Request rate per client address | 10 MiB |

Attacker-controlled keys stay bounded in length and lifetime.

## Analytics and telemetry

| Resource | Limit |
| --- | --- |
| Aggregate query range | 90 days |
| Raw query range | 24 hours |
| Vector batch, request and DNS sinks | 1,000 events or 2 seconds |
| Vector batch, security sink | 500 events or 1 second |
| Vector disk buffer, request sink | 1 GiB |
| Vector disk buffer, security and DNS sinks | 256 MiB each |
| Vector buffer when full | Drops the newest events; serving never stops |
| Vector dnstap frame | 102,400 bytes |
| Vector dnstap connections | 32 |
| Raw event TTL | 7 days |
| Hourly aggregate TTL | 400 days |
| Daily aggregate TTL | 3 years |

## Runtime health

Edge capacity health scans at most 1,000 cells. It marks a cell as under
pressure at 80% of a reported memory, cache, temporary-storage, or connection
limit. It also reports degraded when more than 1,000 cells exist, because the
scan is then incomplete. The default fresh-heartbeat threshold is 45 seconds
(`heartbeat_fresh_seconds` in [Platform settings](platform-settings.md)).
