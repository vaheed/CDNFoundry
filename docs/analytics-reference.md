# Analytics fields and units

All analytics timestamps and buckets are UTC. Aggregate endpoints accept `from`
and `to` ISO 8601 timestamps, default to the previous 24 hours, and reject
ranges longer than 90 days. `top-urls` reads short-retention raw data and is
limited to 24 hours. Responses include `meta.from`, `meta.to`,
`meta.finalized_until`, `meta.partial`, `meta.sampling`, and explicit units.

| Field | Meaning | Unit |
|---|---|---|
| `requests` | HTTP request count | count |
| `bytes_in`, `bytes_out` | Request and response payload transferred | bytes |
| `cache_hits`, `cache_ratio` | Cache hits and hits divided by requests | count, ratio `0..1` |
| `status` | HTTP response status | code |
| `origin_latency_sum`, `average_latency_ms` | Observed origin latency | milliseconds |
| `origin_errors`, `tls_failures`, `security_blocks` | Failure or block counts | count |
| `dns_queries`, `queries` | DNS response count | count |
| `qtype`, `rcode` | DNS question type and response code | label |
| `country`, `continent` | ISO country and continent vocabulary; `ZZ` is unknown | label |
| `hostname`, `path`, `edge_id`, `dns_cluster` | Bounded dimensions | label |

Domain routes are policy-scoped to the route-bound domain. Administrator routes
use global scope. Summaries use hourly materialized aggregates. Result sizes,
rows read, memory, execution time, and HTTP duration are independently bounded.
`analytics_unavailable` with HTTP 503 means ClickHouse could not answer; it does
not describe DNS or edge serving health.

No sampling is currently used. The most recent finalization-delay window is
marked partial because late Vector delivery can still change its aggregate.
