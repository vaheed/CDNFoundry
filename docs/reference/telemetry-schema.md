---
title: Telemetry schema
description: Reference CDNFoundry edge and DNS event fields, redaction, aggregation, and retention.
---

# Telemetry schema

::: danger Telemetry can contain sensitive metadata
Apply the redaction and retention rules on this page before you export data.
Do not put tokens, request bodies, certificate material, raw customer data, or
unbounded headers into labels, logs, dashboards, or incident evidence.
:::

Vector cleans every event before it reaches ClickHouse. It cuts each field to
the length below and removes control characters. Vector drops an event it cannot
transform and counts the drop in its metrics. Vector sets `pop` and `region`
from the collector's own settings and never reads them from the event.

## Edge events

| Field | Type or bound |
| --- | --- |
| `occurred_at` | UTC millisecond timestamp |
| `event_id` | generated UUID |
| `domain_id` | unsigned integer |
| `hostname` | string, 253 characters |
| `method` | upper-case string, 16 characters |
| `path` | query removed, 2,048 characters |
| `status` | unsigned 16-bit integer |
| `bytes_in`, `bytes_out` | unsigned integers |
| `cache_status` | low-cardinality string, 24 characters |
| `origin_latency_ms` | nullable unsigned integer |
| `origin_error`, `tls_error` | bounded strings, 256 characters |
| `origin_role` | `primary` or `backup` (8 characters) |
| `origin_transition` | stable reason, 32 characters |
| `security_action` | low-cardinality string, 24 characters |
| `security_reason` | low-cardinality string, 64 characters |
| `edge_id` | low-cardinality string, 64 characters |
| `client_ip` | string, 45 characters |
| `country`, `continent` | upper-case two-character code |
| `user_agent` | 256 characters |
| `referrer` | query removed, 512 characters |
| `event_type` | low-cardinality string, 32 characters |
| `compression_encoding` | `identity`, `gzip`, or `br` (16 characters) |
| `compression_ratio` | float. A value outside 1 to 100,000 is stored as 1 |
| `compression_profile` | low-cardinality string, 24 characters |
| `compression_fallback` | stable reason, 32 characters |
| `waf_profile` | `off`, `monitor`, `balanced`, or `strict` |
| `waf_rule_id` | numeric managed-rule ID; zero means no match |
| `waf_score` | bounded unsigned anomaly score |
| `waf_action` | `off`, `allow`, `detect`, `block`, or `excluded` |
| `waf_processing_us` | bounded processing time in microseconds |
| `waf_body_limit` | `none` or `exceeded` |
| `waf_exclusion_id` | numeric audit reference; zero means none |
| `request_time_ms` | unsigned integer, 0 when the timing is missing or over 3,600 seconds |
| `upstream_connect_ms`, `upstream_header_ms` | nullable unsigned integers, from the last upstream attempt |
| `server_protocol` | `h2`, `h3`, `http/1.1`, or empty |
| `ssl_protocol`, `ssl_cipher` | 16 and 64 characters |
| `scheme` | `http`, `https`, or empty |
| `ip_version` | `4`, `6`, or empty, read from `client_ip` |
| `request_id` | letters, digits, and hyphens only, 64 characters |
| `bytes_sent` | unsigned integer; falls back to `bytes_out` when the cell does not report it |
| `asn` | unsigned 32-bit integer; zero when unknown |
| `pop`, `region` | 64 characters, set by the collector |
| `security_class` | empty, `block`, `cache_abuse_detected`, `emergency_mode`, `waf_body_limit`, or `waf` |

## DNS events

| Field | Type or bound |
| --- | --- |
| `occurred_at`, `event_id` | UTC timestamp and generated UUID |
| `domain_id` | unsigned integer; dnstap fallback uses zero |
| `zone`, `qname` | 253 characters |
| `qtype`, `rcode` | upper-case, 16 characters |
| `client_ip` | 45 characters |
| `dns_cluster` | 64 characters |
| `country`, `continent` | two-character code |
| `outcome` | 32 characters. `response_limit` marks a reply DNSdist truncated because a client went over its rate |
| `asn` | unsigned 32-bit integer; zero when unknown |
| `pop`, `region` | 64 characters, set by the collector |
| `security_class` | empty, or `dns_response_limit` |

Vector accepts only client, resolver, or authoritative response frames from the
DNSdist dnstap stream. A dnstap event has no domain, so Vector sets `domain_id`
to zero. It uses the question name as the zone and `dnsdist` as the cluster.

## Removed fields

Both transforms delete authorization, cookies, request bodies, and bodies. Edge
events also lose the query string of the path and the referrer. Vector removes
control characters from stored text fields. WAF telemetry never contains matched
values, raw ModSecurity messages, request bodies, query values, cookie values, or
customer rule text.

Vector accepts edge syslog lines only when they carry the node's telemetry proof,
so a container on the same host cannot forge events.

## Aggregates

ClickHouse materialized views roll raw events into smaller tables as they arrive.

| Rollup | Groups by | Kept |
| --- | --- | --- |
| Raw `edge_events` and `dns_events` | one row per event | 7 days |
| Edge hourly (`edge_hourly`) | domain, hour, hostname, status, cache status, geography, edge | 400 days |
| Edge daily (`edge_daily`) | the same keys, per day | 3 years |
| Traffic 1-minute (`edge_traffic_1m`) | domain, PoP, region, edge, method, protocol, IP version, status, cache status, origin role, WAF action, security class, country. It has no hostname, so it stays small | 48 hours |
| Traffic hourly and daily (`edge_traffic_hourly`, `edge_traffic_daily`) | the 1-minute keys plus hostname and TLS version and cipher | 400 days, 3 years |
| DNS hourly (`dns_hourly`) | domain, zone, hour, type, response code, geography, cluster | 400 days |
| DNS daily (`dns_daily`) | the same keys, per day | 3 years |
| DNS 1-minute (`dns_traffic_1m`) | live DNS view | 48 hours |
| DNS top names (`dns_top_names`) | the 20 busiest query names for each hour and zone | 400 days |

Edge counters include requests, bytes, latency sum and sample count, origin
errors, TLS failures, and security blocks. The traffic rollups also keep p50,
p95, and p99 latency estimates.

The DNS rollups group by the zone you serve, not by the query name. A query for a
name in no served zone falls into one bucket with an empty zone. A flood of
random subdomain queries therefore adds rows in proportion to the zones it
hits, not to the names it asks for.

The retention periods come from `docker/clickhouse/init.sql` and the files in
`docker/clickhouse/migrations`.
