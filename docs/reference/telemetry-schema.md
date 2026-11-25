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
| `path` | raw request path as sent (percent-encoded), query removed, 2,048 characters |
| `query_hash` | first 16 hex characters of the SHA-256 of the raw query, empty when there is none |
| `status` | unsigned 16-bit integer |
| `bytes_in` | upload: the request length received from the client, request line, headers, and body |
| `bytes_out` | response body bytes sent to the client |
| `cache_status` | low-cardinality string, 24 characters |
| `origin_latency_ms` | nullable unsigned integer |
| `origin_error`, `tls_error` | bounded strings, 256 characters |
| `origin_role` | `primary` or `backup` (8 characters) |
| `origin_transition` | stable reason, 32 characters |
| `security_action` | low-cardinality string, 24 characters |
| `security_reason` | low-cardinality string, 64 characters |
| `request_rule` | Up to eight comma-separated `redirect:id`, `rewrite:id`, `header:id`, or `cache:id` entries; at most 256 characters. Empty when no rule acted or the value failed validation |
| `edge_id` | the edge host: the collector sets it, so a cell name is never an edge identity. 64 characters |
| `cell` | the serving cell, as the runtime named it (letters, digits, `.`, `_`, `-`; 64 characters). Empty on rows written before the column existed |
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
| `waf_rule_id` | numeric managed-rule ID of the rule that best names why a request was blocked: the first matching CRS rule outside protocol enforcement (920, 921), or the first match when only those matched. `949110` (the blocking evaluation) only when a block has no matched rule to name, which is the inline fallback after a scoring failure. Zero when nothing blocked |
| `waf_rule_ids` | up to eight six-digit CRS rule IDs that matched, in match order, joined by commas, for every scored request that added points. IDs only: nothing the request contained. The category of a rule is its first three digits (`941` is cross-site scripting; the catalog is in [Managed WAF](waf.md#categories)). Empty when nothing matched or the request was not scored by the scoring listener. Anything that is not up to eight six-digit IDs is dropped before storage |
| `waf_score` | On a scored block, the total anomaly points capped at 100. On an inline fallback block, the configured threshold that was reached or exceeded. Zero otherwise; this is not a probability |
| `waf_action` | `off` (profile off), `monitor` (detection only), `pass` (inspected and allowed), or `block`. A block also sets `security_action=block` and `security_reason=waf_block` |
| `waf_processing_us` | bounded processing time in microseconds |
| `waf_body_limit` | the body-inspection result: `inspected` (the whole body was scored), `no_body` (the request had no body or an empty one), `exceeded` (over the limit), `unreadable`, `unsupported_enc` (a compressed body), `scoring_failed` (the scoring step failed and the request was inspected inline), or `none` when the profile is off |
| `waf_exclusion_id` | numeric audit reference; zero means none |
| `request_time_ms` | unsigned integer, 0 when the timing is missing or over 3,600 seconds |
| `upstream_connect_ms`, `upstream_header_ms` | nullable unsigned integers, from the last upstream attempt |
| `server_protocol` | `h2`, `h3`, `http/1.1`, or empty |
| `ssl_protocol`, `ssl_cipher` | 16 and 64 characters |
| `scheme` | `http`, `https`, or empty |
| `ip_version` | `4`, `6`, or empty, read from `client_ip` |
| `request_id` | letters, digits, and hyphens only, 64 characters |
| `bytes_sent` | download: the full response sent to the client, status line and headers included; falls back to `bytes_out` when the cell does not report it |
| `asn` | unsigned 32-bit integer. Zero means the cell has no autonomous-system database or the address is not in it; see `MMDB_ASN_DOWNLOAD_URL` in [Configuration](configuration.md) |
| `pop`, `region` | 64 characters, set by the collector |
| `tls_error` on `event_type=tls` rows | a cell reason (`unknown_sni`, `handshake_rate_exceeded`, `tls_disabled`, `certificate_unavailable`, `certificate_expired`, `certificate_invalid`) or a gateway reason prefixed `gateway_` (`invalid_hello`, `unknown_route`, `handshake_budget`, `gateway_unavailable`) |
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
| Volume 1-minute and hourly (`edge_volume_1m`, `edge_volume_hourly`) | domain, PoP, region, edge, cell, country. Counters: requests, `bytes_in` (upload) and `bytes_sent` (download). Nothing per hostname, path, or client | 48 hours, 400 days |
| DNS hourly (`dns_hourly`) | domain, zone, hour, type, response code, geography, cluster | 400 days |
| DNS daily (`dns_daily`) | the same keys, per day | 3 years |
| DNS 1-minute (`dns_traffic_1m`) | live DNS view | 48 hours |
| DNS top names (`dns_top_names`) | the 20 busiest query names for each hour and zone | 400 days |

Edge counters include requests, bytes, latency sum and sample count, origin
errors, TLS failures, and security blocks. The traffic rollups also keep p50,
p95, and p99 latency estimates.

### Download, upload, and what is not measured

Download is `bytes_sent` and upload is `bytes_in`, both counted at the cell that
served the client. Origin-side traffic (what the cell fetched from your origin)
is not measured. Prometheus carries `cdnf_edge_bytes_sent_total` (download) and
`cdnf_edge_bytes_received_total` (upload) by edge, PoP, and region only; domain,
country, and cell stay in ClickHouse. The rollups were rebuilt from the same
events, so a counted test transfer equals them exactly in the qualification run
(`tests/e2e/traffic_volume_accuracy.py`, tolerance 2 percent).

Rows written before the `cell` column and the volume rollups existed are not
back-filled; their cell is empty and they are absent from the volume rollups.

The DNS rollups group by the zone you serve, not by the query name. A query for a
name in no served zone falls into one bucket with an empty zone. A flood of
random subdomain queries therefore adds rows in proportion to the zones it
hits, not to the names it asks for.

The retention periods come from `docker/clickhouse/init.sql` and the files in
`docker/clickhouse/migrations`.
