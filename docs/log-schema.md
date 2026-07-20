# Telemetry log schema

Vector is the only collector. DNSdist emits DNSTap responses and OpenResty emits
structured request events directly to Vector; Vector validates a fixed schema
and writes ClickHouse. Laravel, PostgreSQL, and Valkey never ingest raw traffic.

## Edge events

`occurred_at`, `event_id`, `domain_id`, `hostname`, `method`, `path`, `status`,
`bytes_in`, `bytes_out`, `cache_status`, nullable `origin_latency_ms`,
`origin_error`, `tls_error`, `security_action`, `security_reason`, `edge_id`,
`client_ip`, `country`, `continent`, bounded `user_agent`, bounded `referrer`,
and `event_type`.

`event_type=request` is normal traffic. Deployment/health values are reserved
for operational edge events. Error logs select status 5xx, origin errors, or TLS
errors; security logs select blocked events. Paths exclude query strings.

## DNS events

`occurred_at`, `event_id`, `domain_id`, `zone`, `qname`, `qtype`, `rcode`,
`client_ip`, `dns_cluster`, `country`, `continent`, and `outcome`.

DNSTap does not carry the Laravel domain ID. Those rows use `domain_id=0`; domain
queries match the exact zone/name suffix after policy authorization. API-supplied
structured DNS events may include the durable domain ID.

Raw endpoints allow at most 24 hours and return at most 100 items. Pagination is
newest-first with an opaque `meta.next_cursor`. Normal API and UI results mask
IPv4 to `/24` and IPv6 to `/48`; ClickHouse raw storage retains the source
address only for the short raw-retention interval.

