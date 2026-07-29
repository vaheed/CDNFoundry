---
title: Telemetry schema
description: Reference CDNFoundry edge and DNS event fields, redaction, aggregation, and retention.
---

# Telemetry schema

Vector normalizes events before ClickHouse. Invalid transformations are dropped
and reported through Vector metrics.

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
| `origin_role` | `primary` or `backup` |
| `origin_transition` | stable reason, 32 characters |
| `security_action` | low-cardinality string, 24 characters |
| `security_reason` | low-cardinality string, 64 characters |
| `edge_id` | low-cardinality string, 64 characters |
| `client_ip` | string, 45 characters |
| `country`, `continent` | upper-case two-character code |
| `user_agent` | 256 characters |
| `referrer` | 512 characters |
| `event_type` | low-cardinality string, 32 characters |
| `compression_encoding` | `identity`, `gzip`, or `br` |
| `compression_ratio` | float clamped to 1–100,000 |
| `compression_profile` | low-cardinality string, 24 characters |
| `compression_fallback` | stable reason, 32 characters |
| `waf_profile` | `off`, `monitor`, `balanced`, or `strict` |
| `waf_rule_id` | numeric managed-rule ID; zero means no match |
| `waf_score` | bounded unsigned anomaly score |
| `waf_action` | `off`, `allow`, `detect`, `block`, or `excluded` |
| `waf_processing_us` | bounded processing time in microseconds |
| `waf_body_limit` | `none` or `exceeded` |
| `waf_exclusion_id` | numeric audit reference; zero means none |

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
| `outcome` | 32 characters |

DNSdist dnstap accepts only client, resolver, or authoritative response frames.
Fallback events use the question name as the zone and `dnsdist` as the cluster.

## Removed fields

Both transforms delete authorization, cookies, request bodies, and bodies. Edge
events also delete query strings. Control characters are removed from stored
text fields. WAF telemetry never contains matched values, raw ModSecurity
messages, request bodies, query values, cookie values, or customer rule text.

## Aggregates

Materialized views write hourly and daily edge aggregates by domain, interval,
hostname, status, cache status, geography, and edge. Counters include requests,
bytes, latency sum/sample count, origin errors, TLS failures, and security
blocks.

DNS aggregates group queries by domain, zone, interval, type, response code,
geography, and cluster.

Raw tables retain seven days, hourly tables 400 days, and daily tables three
years in `docker/clickhouse/init.sql`.
