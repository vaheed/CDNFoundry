-- Observability telemetry (Phase 9): request timings, protocol, byte counts including headers, PoP and
-- region tags from the collector, ASN, security classes, wider rollups with latency quantile states, a
-- 1-minute rollup kept 48 hours, and a 5-minute usage rollup. Every default is backfill-free: existing
-- rows keep the column default and old rollup rows stay as they are.
ALTER TABLE cdnf.edge_events
    ADD COLUMN IF NOT EXISTS request_time_ms UInt32 DEFAULT 0,
    ADD COLUMN IF NOT EXISTS upstream_connect_ms Nullable(UInt32),
    ADD COLUMN IF NOT EXISTS upstream_header_ms Nullable(UInt32),
    ADD COLUMN IF NOT EXISTS server_protocol LowCardinality(String) DEFAULT '',
    ADD COLUMN IF NOT EXISTS ssl_protocol LowCardinality(String) DEFAULT '',
    ADD COLUMN IF NOT EXISTS ssl_cipher LowCardinality(String) DEFAULT '',
    ADD COLUMN IF NOT EXISTS ip_version LowCardinality(String) DEFAULT '',
    ADD COLUMN IF NOT EXISTS scheme LowCardinality(String) DEFAULT '',
    ADD COLUMN IF NOT EXISTS request_id String DEFAULT '',
    ADD COLUMN IF NOT EXISTS bytes_sent UInt64 DEFAULT 0,
    ADD COLUMN IF NOT EXISTS asn UInt32 DEFAULT 0,
    ADD COLUMN IF NOT EXISTS pop LowCardinality(String) DEFAULT '',
    ADD COLUMN IF NOT EXISTS region LowCardinality(String) DEFAULT '',
    ADD COLUMN IF NOT EXISTS security_class LowCardinality(String) DEFAULT '';

ALTER TABLE cdnf.dns_events
    ADD COLUMN IF NOT EXISTS asn UInt32 DEFAULT 0,
    ADD COLUMN IF NOT EXISTS pop LowCardinality(String) DEFAULT '',
    ADD COLUMN IF NOT EXISTS region LowCardinality(String) DEFAULT '',
    ADD COLUMN IF NOT EXISTS security_class LowCardinality(String) DEFAULT '';

-- Live and NOC panels read the 1-minute rollup, kept 48 hours. It has no hostname, TLS, or cipher key.
CREATE TABLE IF NOT EXISTS cdnf.edge_traffic_1m
(
    interval_start DateTime('UTC'),
    domain_id UInt64,
    pop LowCardinality(String),
    region LowCardinality(String),
    edge_id LowCardinality(String),
    method LowCardinality(String),
    protocol LowCardinality(String),
    ip_version LowCardinality(String),
    status UInt16,
    cache_status LowCardinality(String),
    origin_role LowCardinality(String),
    waf_action LowCardinality(String),
    security_class LowCardinality(String),
    country LowCardinality(String),
    requests SimpleAggregateFunction(sum, UInt64),
    bytes_in SimpleAggregateFunction(sum, UInt64),
    bytes_sent SimpleAggregateFunction(sum, UInt64),
    origin_latency_sum SimpleAggregateFunction(sum, UInt64),
    origin_latency_samples SimpleAggregateFunction(sum, UInt64),
    origin_errors SimpleAggregateFunction(sum, UInt64),
    security_blocks SimpleAggregateFunction(sum, UInt64),
    edge_latency_q AggregateFunction(quantilesTDigest(0.5, 0.95, 0.99), UInt32),
    origin_latency_q AggregateFunction(quantilesTDigest(0.5, 0.95, 0.99), UInt32),
    ttfb_q AggregateFunction(quantilesTDigest(0.5, 0.95, 0.99), UInt32)
)
ENGINE = AggregatingMergeTree
PARTITION BY toYYYYMMDD(interval_start)
ORDER BY (domain_id, interval_start, pop, region, edge_id, method, protocol, ip_version, status, cache_status, origin_role, waf_action, security_class, country)
TTL interval_start + INTERVAL 48 HOUR DELETE;

CREATE MATERIALIZED VIEW IF NOT EXISTS cdnf.edge_traffic_1m_mv TO cdnf.edge_traffic_1m DEFINER = cdnf SQL SECURITY DEFINER AS
SELECT toStartOfMinute(occurred_at) AS interval_start,
       domain_id,
       pop,
       region,
       edge_id,
       method,
       server_protocol AS protocol,
       ip_version,
       status,
       cache_status,
       origin_role,
       waf_action,
       security_class,
       country,
       count() AS requests, sum(bytes_in) AS bytes_in, sum(bytes_sent) AS bytes_sent,
       sum(ifNull(origin_latency_ms, 0)) AS origin_latency_sum, countIf(origin_latency_ms IS NOT NULL) AS origin_latency_samples,
       countIf(origin_error != '') AS origin_errors, countIf(security_action = 'block') AS security_blocks,
       quantilesTDigestState(0.5, 0.95, 0.99)(request_time_ms) AS edge_latency_q,
       quantilesTDigestStateIf(0.5, 0.95, 0.99)(ifNull(origin_latency_ms, 0), origin_latency_ms IS NOT NULL) AS origin_latency_q,
       quantilesTDigestStateIf(0.5, 0.95, 0.99)(ifNull(upstream_header_ms, 0), upstream_header_ms IS NOT NULL) AS ttfb_q
FROM cdnf.edge_events
WHERE event_type != 'tls'
GROUP BY interval_start, domain_id, pop, region, edge_id, method, protocol, ip_version, status, cache_status, origin_role, waf_action, security_class, country;

CREATE TABLE IF NOT EXISTS cdnf.edge_traffic_hourly
(
    interval_start DateTime('UTC'),
    hostname String,
    domain_id UInt64,
    pop LowCardinality(String),
    region LowCardinality(String),
    edge_id LowCardinality(String),
    method LowCardinality(String),
    protocol LowCardinality(String),
    ip_version LowCardinality(String),
    status UInt16,
    cache_status LowCardinality(String),
    origin_role LowCardinality(String),
    waf_action LowCardinality(String),
    security_class LowCardinality(String),
    country LowCardinality(String),
    ssl_protocol LowCardinality(String),
    ssl_cipher LowCardinality(String),
    requests SimpleAggregateFunction(sum, UInt64),
    bytes_in SimpleAggregateFunction(sum, UInt64),
    bytes_sent SimpleAggregateFunction(sum, UInt64),
    origin_latency_sum SimpleAggregateFunction(sum, UInt64),
    origin_latency_samples SimpleAggregateFunction(sum, UInt64),
    origin_errors SimpleAggregateFunction(sum, UInt64),
    security_blocks SimpleAggregateFunction(sum, UInt64),
    edge_latency_q AggregateFunction(quantilesTDigest(0.5, 0.95, 0.99), UInt32),
    origin_latency_q AggregateFunction(quantilesTDigest(0.5, 0.95, 0.99), UInt32),
    ttfb_q AggregateFunction(quantilesTDigest(0.5, 0.95, 0.99), UInt32)
)
ENGINE = AggregatingMergeTree
PARTITION BY toYYYYMM(interval_start)
ORDER BY (domain_id, interval_start, hostname, pop, region, edge_id, method, protocol, ip_version, status, cache_status, origin_role, waf_action, security_class, country, ssl_protocol, ssl_cipher)
TTL interval_start + INTERVAL 400 DAY DELETE;

CREATE MATERIALIZED VIEW IF NOT EXISTS cdnf.edge_traffic_hourly_mv TO cdnf.edge_traffic_hourly DEFINER = cdnf SQL SECURITY DEFINER AS
SELECT toStartOfHour(occurred_at) AS interval_start,
       hostname,
       domain_id,
       pop,
       region,
       edge_id,
       method,
       server_protocol AS protocol,
       ip_version,
       status,
       cache_status,
       origin_role,
       waf_action,
       security_class,
       country,
       ssl_protocol,
       ssl_cipher,
       count() AS requests, sum(bytes_in) AS bytes_in, sum(bytes_sent) AS bytes_sent,
       sum(ifNull(origin_latency_ms, 0)) AS origin_latency_sum, countIf(origin_latency_ms IS NOT NULL) AS origin_latency_samples,
       countIf(origin_error != '') AS origin_errors, countIf(security_action = 'block') AS security_blocks,
       quantilesTDigestState(0.5, 0.95, 0.99)(request_time_ms) AS edge_latency_q,
       quantilesTDigestStateIf(0.5, 0.95, 0.99)(ifNull(origin_latency_ms, 0), origin_latency_ms IS NOT NULL) AS origin_latency_q,
       quantilesTDigestStateIf(0.5, 0.95, 0.99)(ifNull(upstream_header_ms, 0), upstream_header_ms IS NOT NULL) AS ttfb_q
FROM cdnf.edge_events
WHERE event_type != 'tls'
GROUP BY interval_start, hostname, domain_id, pop, region, edge_id, method, protocol, ip_version, status, cache_status, origin_role, waf_action, security_class, country, ssl_protocol, ssl_cipher;

CREATE TABLE IF NOT EXISTS cdnf.edge_traffic_daily
(
    interval_start DateTime('UTC'),
    hostname String,
    domain_id UInt64,
    pop LowCardinality(String),
    region LowCardinality(String),
    edge_id LowCardinality(String),
    method LowCardinality(String),
    protocol LowCardinality(String),
    ip_version LowCardinality(String),
    status UInt16,
    cache_status LowCardinality(String),
    origin_role LowCardinality(String),
    waf_action LowCardinality(String),
    security_class LowCardinality(String),
    country LowCardinality(String),
    ssl_protocol LowCardinality(String),
    ssl_cipher LowCardinality(String),
    requests SimpleAggregateFunction(sum, UInt64),
    bytes_in SimpleAggregateFunction(sum, UInt64),
    bytes_sent SimpleAggregateFunction(sum, UInt64),
    origin_latency_sum SimpleAggregateFunction(sum, UInt64),
    origin_latency_samples SimpleAggregateFunction(sum, UInt64),
    origin_errors SimpleAggregateFunction(sum, UInt64),
    security_blocks SimpleAggregateFunction(sum, UInt64),
    edge_latency_q AggregateFunction(quantilesTDigest(0.5, 0.95, 0.99), UInt32),
    origin_latency_q AggregateFunction(quantilesTDigest(0.5, 0.95, 0.99), UInt32),
    ttfb_q AggregateFunction(quantilesTDigest(0.5, 0.95, 0.99), UInt32)
)
ENGINE = AggregatingMergeTree
PARTITION BY toYYYYMM(interval_start)
ORDER BY (domain_id, interval_start, hostname, pop, region, edge_id, method, protocol, ip_version, status, cache_status, origin_role, waf_action, security_class, country, ssl_protocol, ssl_cipher)
TTL interval_start + INTERVAL 3 YEAR DELETE;

CREATE MATERIALIZED VIEW IF NOT EXISTS cdnf.edge_traffic_daily_mv TO cdnf.edge_traffic_daily DEFINER = cdnf SQL SECURITY DEFINER AS
SELECT toStartOfDay(interval_start) AS interval_start, hostname, domain_id, pop, region, edge_id, method, protocol, ip_version, status, cache_status, origin_role, waf_action, security_class, country, ssl_protocol, ssl_cipher,
       sum(requests) AS requests, sum(bytes_in) AS bytes_in, sum(bytes_sent) AS bytes_sent,
       sum(origin_latency_sum) AS origin_latency_sum, sum(origin_latency_samples) AS origin_latency_samples,
       sum(origin_errors) AS origin_errors, sum(security_blocks) AS security_blocks,
       quantilesTDigestMergeState(0.5, 0.95, 0.99)(edge_latency_q) AS edge_latency_q,
       quantilesTDigestMergeState(0.5, 0.95, 0.99)(origin_latency_q) AS origin_latency_q,
       quantilesTDigestMergeState(0.5, 0.95, 0.99)(ttfb_q) AS ttfb_q
FROM cdnf.edge_traffic_hourly
GROUP BY interval_start, hostname, domain_id, pop, region, edge_id, method, protocol, ip_version, status, cache_status, origin_role, waf_action, security_class, country, ssl_protocol, ssl_cipher;

-- Bytes per 5-minute interval, per domain and PoP, for 95th-percentile billing. bytes_sent includes headers.
CREATE TABLE IF NOT EXISTS cdnf.edge_usage_5m
(
    interval_start DateTime('UTC'), domain_id UInt64, pop LowCardinality(String), region LowCardinality(String),
    edge_id LowCardinality(String),
    requests SimpleAggregateFunction(sum, UInt64), bytes_in SimpleAggregateFunction(sum, UInt64),
    bytes_sent SimpleAggregateFunction(sum, UInt64)
)
ENGINE = AggregatingMergeTree
PARTITION BY toYYYYMM(interval_start)
ORDER BY (domain_id, interval_start, pop, region, edge_id)
TTL interval_start + INTERVAL 400 DAY DELETE;

CREATE MATERIALIZED VIEW IF NOT EXISTS cdnf.edge_usage_5m_mv TO cdnf.edge_usage_5m DEFINER = cdnf SQL SECURITY DEFINER AS
SELECT toStartOfFiveMinutes(occurred_at) AS interval_start, domain_id, pop, region, edge_id,
       count() AS requests, sum(bytes_in) AS bytes_in, sum(bytes_sent) AS bytes_sent
FROM cdnf.edge_events
WHERE event_type != 'tls'
GROUP BY interval_start, domain_id, pop, region, edge_id;

-- Handshake failures by PoP, reason, and protocol. The SNI is attacker-chosen, so it stays in the raw table only.
CREATE TABLE IF NOT EXISTS cdnf.tls_failures_hourly
(
    interval_start DateTime('UTC'), pop LowCardinality(String), region LowCardinality(String), edge_id LowCardinality(String),
    reason LowCardinality(String), ssl_protocol LowCardinality(String), failures SimpleAggregateFunction(sum, UInt64)
)
ENGINE = AggregatingMergeTree
PARTITION BY toYYYYMM(interval_start)
ORDER BY (interval_start, pop, region, edge_id, reason, ssl_protocol)
TTL interval_start + INTERVAL 400 DAY DELETE;

CREATE MATERIALIZED VIEW IF NOT EXISTS cdnf.tls_failures_hourly_mv TO cdnf.tls_failures_hourly DEFINER = cdnf SQL SECURITY DEFINER AS
SELECT toStartOfHour(occurred_at) AS interval_start, pop, region, edge_id, tls_error AS reason, ssl_protocol, count() AS failures
FROM cdnf.edge_events
WHERE event_type = 'tls'
GROUP BY interval_start, pop, region, edge_id, reason, ssl_protocol;

-- Authoritative DNS by PoP for live panels and flood detection, kept 48 hours.
CREATE TABLE IF NOT EXISTS cdnf.dns_traffic_1m
(
    interval_start DateTime('UTC'), pop LowCardinality(String), region LowCardinality(String),
    qtype LowCardinality(String), rcode LowCardinality(String), outcome LowCardinality(String),
    queries SimpleAggregateFunction(sum, UInt64)
)
ENGINE = AggregatingMergeTree
PARTITION BY toYYYYMMDD(interval_start)
ORDER BY (interval_start, pop, region, qtype, rcode, outcome)
TTL interval_start + INTERVAL 48 HOUR DELETE;

CREATE MATERIALIZED VIEW IF NOT EXISTS cdnf.dns_traffic_1m_mv TO cdnf.dns_traffic_1m DEFINER = cdnf SQL SECURITY DEFINER AS
SELECT toStartOfMinute(occurred_at) AS interval_start, pop, region, qtype, rcode, outcome, count() AS queries
FROM cdnf.dns_events
GROUP BY interval_start, pop, region, qtype, rcode, outcome;

-- Handshake failures are rows of their own, never requests: the narrow hourly rollup now counts them separately.
DROP VIEW IF EXISTS cdnf.edge_hourly_mv;
CREATE MATERIALIZED VIEW IF NOT EXISTS cdnf.edge_hourly_mv TO cdnf.edge_hourly DEFINER = cdnf SQL SECURITY DEFINER AS
SELECT toStartOfHour(occurred_at) AS interval_start, domain_id, hostname, status,
       cache_status, country, continent, edge_id, countIf(event_type != 'tls') AS requests,
       sum(bytes_in) AS bytes_in, sum(bytes_sent) AS bytes_out,
       sum(ifNull(origin_latency_ms, 0)) AS origin_latency_sum,
       countIf(origin_latency_ms IS NOT NULL) AS origin_latency_samples,
       countIf(origin_error != '') AS origin_errors,
       countIf(event_type = 'tls') AS tls_failures,
       countIf(security_action = 'block') AS security_blocks
FROM cdnf.edge_events
GROUP BY interval_start, domain_id, hostname, status, cache_status, country, continent, edge_id;
