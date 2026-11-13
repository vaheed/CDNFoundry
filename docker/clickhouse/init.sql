CREATE DATABASE IF NOT EXISTS cdnf;

CREATE TABLE IF NOT EXISTS cdnf.edge_events
(
    occurred_at DateTime64(3, 'UTC'),
    event_id UUID DEFAULT generateUUIDv4(),
    domain_id UInt64,
    hostname String,
    method LowCardinality(String),
    path String,
    status UInt16,
    bytes_in UInt64,
    bytes_out UInt64,
    cache_status LowCardinality(String),
    origin_latency_ms Nullable(UInt32),
    origin_error String,
    origin_role LowCardinality(String) DEFAULT 'primary',
    origin_transition LowCardinality(String) DEFAULT 'none',
    tls_error String,
    security_action LowCardinality(String),
    security_reason LowCardinality(String),
    edge_id LowCardinality(String),
    client_ip String,
    country LowCardinality(String),
    continent LowCardinality(String),
    user_agent String,
    referrer String,
    event_type LowCardinality(String) DEFAULT 'request',
    compression_encoding LowCardinality(String) DEFAULT 'identity',
    compression_ratio Float32 DEFAULT 1,
    compression_profile LowCardinality(String) DEFAULT 'off',
    compression_fallback LowCardinality(String) DEFAULT 'none',
    waf_profile LowCardinality(String) DEFAULT 'off',
    waf_rule_id UInt32 DEFAULT 0,
    waf_score UInt16 DEFAULT 0,
    waf_action LowCardinality(String) DEFAULT 'off',
    waf_processing_us UInt32 DEFAULT 0,
    waf_body_limit LowCardinality(String) DEFAULT 'none',
    waf_exclusion_id UInt64 DEFAULT 0,
    security_rule LowCardinality(String) DEFAULT '',
    attack_score UInt8 DEFAULT 0,
    attack_score_sqli UInt8 DEFAULT 0,
    attack_score_xss UInt8 DEFAULT 0,
    attack_score_rce UInt8 DEFAULT 0,
    request_time_ms UInt32 DEFAULT 0,
    upstream_connect_ms Nullable(UInt32),
    upstream_header_ms Nullable(UInt32),
    server_protocol LowCardinality(String) DEFAULT '',
    ssl_protocol LowCardinality(String) DEFAULT '',
    ssl_cipher LowCardinality(String) DEFAULT '',
    ip_version LowCardinality(String) DEFAULT '',
    scheme LowCardinality(String) DEFAULT '',
    request_id String DEFAULT '',
    query_hash String DEFAULT '',
    bytes_sent UInt64 DEFAULT 0,
    asn UInt32 DEFAULT 0,
    pop LowCardinality(String) DEFAULT '',
    region LowCardinality(String) DEFAULT '',
    security_class LowCardinality(String) DEFAULT '',
    cell LowCardinality(String) DEFAULT ''
)
ENGINE = MergeTree
PARTITION BY toYYYYMMDD(occurred_at)
ORDER BY (domain_id, occurred_at, event_id)
TTL occurred_at + INTERVAL 7 DAY DELETE
SETTINGS index_granularity = 8192;

CREATE TABLE IF NOT EXISTS cdnf.dns_events
(
    occurred_at DateTime64(3, 'UTC'),
    event_id UUID DEFAULT generateUUIDv4(),
    domain_id UInt64 DEFAULT 0,
    zone String,
    qname String,
    qtype LowCardinality(String),
    rcode LowCardinality(String),
    client_ip String,
    dns_cluster LowCardinality(String),
    country LowCardinality(String),
    continent LowCardinality(String),
    outcome LowCardinality(String),
    asn UInt32 DEFAULT 0,
    pop LowCardinality(String) DEFAULT '',
    region LowCardinality(String) DEFAULT '',
    security_class LowCardinality(String) DEFAULT ''
)
ENGINE = MergeTree
PARTITION BY toYYYYMMDD(occurred_at)
ORDER BY (domain_id, zone, occurred_at, event_id)
TTL occurred_at + INTERVAL 7 DAY DELETE
SETTINGS index_granularity = 8192;

CREATE TABLE IF NOT EXISTS cdnf.edge_hourly
(
    interval_start DateTime('UTC'), domain_id UInt64, hostname String,
    status UInt16, cache_status LowCardinality(String), country LowCardinality(String),
    continent LowCardinality(String), edge_id LowCardinality(String),
    requests UInt64, bytes_in UInt64, bytes_out UInt64,
    origin_latency_sum UInt64, origin_latency_samples UInt64,
    origin_errors UInt64, tls_failures UInt64, security_blocks UInt64
)
ENGINE = SummingMergeTree
PARTITION BY toYYYYMM(interval_start)
ORDER BY (domain_id, interval_start, hostname, status, cache_status, country, continent, edge_id)
TTL interval_start + INTERVAL 400 DAY DELETE;

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

CREATE TABLE IF NOT EXISTS cdnf.dns_hourly
(
    interval_start DateTime('UTC'), domain_id UInt64, zone String,
    qtype LowCardinality(String), rcode LowCardinality(String),
    country LowCardinality(String), continent LowCardinality(String),
    dns_cluster LowCardinality(String), queries UInt64
)
ENGINE = SummingMergeTree
PARTITION BY toYYYYMM(interval_start)
ORDER BY (domain_id, zone, interval_start, qtype, rcode, country, continent, dns_cluster)
TTL interval_start + INTERVAL 400 DAY DELETE;

-- The zones this platform serves, published by Laravel as complete snapshots (one generation each).
-- The dictionary below always reads the newest generation, so a zone that was removed stops
-- being matched once the next snapshot lands.
CREATE TABLE IF NOT EXISTS cdnf.served_zones
(
    generation UInt64, zone String, domain_id UInt64
)
ENGINE = MergeTree
ORDER BY (generation, zone)
TTL toDateTime(generation) + INTERVAL 2 DAY DELETE;

CREATE DICTIONARY IF NOT EXISTS cdnf.served_zone_dictionary
(
    zone String, domain_id UInt64
)
PRIMARY KEY zone
SOURCE(CLICKHOUSE(USER 'cdnf_dictionary' QUERY 'SELECT zone, domain_id FROM cdnf.served_zones WHERE generation = (SELECT max(generation) FROM cdnf.served_zones)'))
LAYOUT(COMPLEX_KEY_HASHED())
LIFETIME(MIN 30 MAX 90);

-- The rollup is keyed by the served zone and its domain, never by the queried name. A query name is
-- matched to the longest served zone that is a suffix of it; a name inside no served zone (for
-- example a REFUSED query for someone else's domain) falls into one bucket with an empty zone. A
-- random-subdomain flood therefore adds rows in proportion to the zones it hits, not to its names.
CREATE MATERIALIZED VIEW IF NOT EXISTS cdnf.dns_hourly_mv TO cdnf.dns_hourly DEFINER = cdnf SQL SECURITY DEFINER AS
SELECT toStartOfHour(occurred_at) AS interval_start,
       dictGetOrDefault('cdnf.served_zone_dictionary', 'domain_id', tuple(served_zone), toUInt64(0)) AS domain_id,
       served_zone AS zone, qtype, rcode, country, continent, dns_cluster, count() AS queries
FROM
(
    SELECT *, arrayFirst(candidate -> dictHas('cdnf.served_zone_dictionary', tuple(candidate)),
        arrayMap(position -> arrayStringConcat(arraySlice(splitByChar('.', lower(trimRight(qname, '.'))), position), '.'),
                 range(1, length(splitByChar('.', lower(trimRight(qname, '.')))) + 1))) AS served_zone
    FROM cdnf.dns_events
)
GROUP BY interval_start, domain_id, zone, qtype, rcode, country, continent, dns_cluster;

-- The busiest query names per hour and served zone, kept in a bounded sketch (at most 20 per row), so
-- an operator can see what is being asked without keeping every name for years.
CREATE TABLE IF NOT EXISTS cdnf.dns_top_names
(
    interval_start DateTime('UTC'), domain_id UInt64, zone String,
    names AggregateFunction(topK(20), String)
)
ENGINE = AggregatingMergeTree
PARTITION BY toYYYYMM(interval_start)
ORDER BY (domain_id, zone, interval_start)
TTL interval_start + INTERVAL 400 DAY DELETE;

CREATE MATERIALIZED VIEW IF NOT EXISTS cdnf.dns_top_names_mv TO cdnf.dns_top_names DEFINER = cdnf SQL SECURITY DEFINER AS
SELECT toStartOfHour(occurred_at) AS interval_start,
       dictGet('cdnf.served_zone_dictionary', 'domain_id', tuple(served_zone)) AS domain_id,
       served_zone AS zone, topKState(20)(lower(trimRight(qname, '.'))) AS names
FROM
(
    SELECT *, arrayFirst(candidate -> dictHas('cdnf.served_zone_dictionary', tuple(candidate)),
        arrayMap(position -> arrayStringConcat(arraySlice(splitByChar('.', lower(trimRight(qname, '.'))), position), '.'),
                 range(1, length(splitByChar('.', lower(trimRight(qname, '.')))) + 1))) AS served_zone
    FROM cdnf.dns_events
)
WHERE served_zone != ''
GROUP BY interval_start, domain_id, zone;

CREATE TABLE IF NOT EXISTS cdnf.edge_daily AS cdnf.edge_hourly
ENGINE = SummingMergeTree PARTITION BY toYYYYMM(interval_start)
ORDER BY (domain_id, interval_start, hostname, status, cache_status, country, continent, edge_id)
TTL interval_start + INTERVAL 3 YEAR DELETE;

CREATE MATERIALIZED VIEW IF NOT EXISTS cdnf.edge_daily_mv TO cdnf.edge_daily DEFINER = cdnf SQL SECURITY DEFINER AS
SELECT toStartOfDay(interval_start) AS interval_start, domain_id, hostname, status, cache_status,
       country, continent, edge_id, sum(requests) AS requests, sum(bytes_in) AS bytes_in,
       sum(bytes_out) AS bytes_out, sum(origin_latency_sum) AS origin_latency_sum,
       sum(origin_latency_samples) AS origin_latency_samples, sum(origin_errors) AS origin_errors,
       sum(tls_failures) AS tls_failures, sum(security_blocks) AS security_blocks
FROM cdnf.edge_hourly GROUP BY interval_start, domain_id, hostname, status, cache_status, country, continent, edge_id;

CREATE TABLE IF NOT EXISTS cdnf.dns_daily AS cdnf.dns_hourly
ENGINE = SummingMergeTree PARTITION BY toYYYYMM(interval_start)
ORDER BY (domain_id, zone, interval_start, qtype, rcode, country, continent, dns_cluster)
TTL interval_start + INTERVAL 3 YEAR DELETE;

CREATE MATERIALIZED VIEW IF NOT EXISTS cdnf.dns_daily_mv TO cdnf.dns_daily DEFINER = cdnf SQL SECURITY DEFINER AS
SELECT toStartOfDay(interval_start) AS interval_start, domain_id, zone, qtype, rcode,
       country, continent, dns_cluster, sum(queries) AS queries
FROM cdnf.dns_hourly GROUP BY interval_start, domain_id, zone, qtype, rcode, country, continent, dns_cluster;

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

-- Traffic volume with direction, kept small: download is bytes_sent (response including headers) and
-- upload is bytes_in (the request length from the client). The key carries exactly the four views an
-- operator asks for (edge, cell, domain, country) and nothing per-hostname, per-path, or per-client.
CREATE TABLE IF NOT EXISTS cdnf.edge_volume_1m
(
    interval_start DateTime('UTC'),
    domain_id UInt64,
    pop LowCardinality(String),
    region LowCardinality(String),
    edge_id LowCardinality(String),
    cell LowCardinality(String),
    country LowCardinality(String),
    requests SimpleAggregateFunction(sum, UInt64),
    bytes_in SimpleAggregateFunction(sum, UInt64),
    bytes_sent SimpleAggregateFunction(sum, UInt64)
)
ENGINE = AggregatingMergeTree
PARTITION BY toYYYYMMDD(interval_start)
ORDER BY (domain_id, interval_start, pop, region, edge_id, cell, country)
TTL interval_start + INTERVAL 48 HOUR DELETE;

CREATE MATERIALIZED VIEW IF NOT EXISTS cdnf.edge_volume_1m_mv TO cdnf.edge_volume_1m DEFINER = cdnf SQL SECURITY DEFINER AS
SELECT toStartOfMinute(occurred_at) AS interval_start, domain_id, pop, region, edge_id, cell, country,
       count() AS requests, sum(bytes_in) AS bytes_in, sum(bytes_sent) AS bytes_sent
FROM cdnf.edge_events
WHERE event_type != 'tls'
GROUP BY interval_start, domain_id, pop, region, edge_id, cell, country;

CREATE TABLE IF NOT EXISTS cdnf.edge_volume_hourly
(
    interval_start DateTime('UTC'),
    domain_id UInt64,
    pop LowCardinality(String),
    region LowCardinality(String),
    edge_id LowCardinality(String),
    cell LowCardinality(String),
    country LowCardinality(String),
    requests SimpleAggregateFunction(sum, UInt64),
    bytes_in SimpleAggregateFunction(sum, UInt64),
    bytes_sent SimpleAggregateFunction(sum, UInt64)
)
ENGINE = AggregatingMergeTree
PARTITION BY toYYYYMM(interval_start)
ORDER BY (domain_id, interval_start, pop, region, edge_id, cell, country)
TTL interval_start + INTERVAL 400 DAY DELETE;

CREATE MATERIALIZED VIEW IF NOT EXISTS cdnf.edge_volume_hourly_mv TO cdnf.edge_volume_hourly DEFINER = cdnf SQL SECURITY DEFINER AS
SELECT toStartOfHour(occurred_at) AS interval_start, domain_id, pop, region, edge_id, cell, country,
       count() AS requests, sum(bytes_in) AS bytes_in, sum(bytes_sent) AS bytes_sent
FROM cdnf.edge_events
WHERE event_type != 'tls'
GROUP BY interval_start, domain_id, pop, region, edge_id, cell, country;

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
