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
    tls_error String,
    security_action LowCardinality(String),
    security_reason LowCardinality(String),
    edge_id LowCardinality(String),
    client_ip String,
    country LowCardinality(String),
    continent LowCardinality(String),
    user_agent String,
    referrer String,
    event_type LowCardinality(String) DEFAULT 'request'
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
    outcome LowCardinality(String)
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

CREATE MATERIALIZED VIEW IF NOT EXISTS cdnf.edge_hourly_mv TO cdnf.edge_hourly AS
SELECT toStartOfHour(occurred_at) AS interval_start, domain_id, hostname, status,
       cache_status, country, continent, edge_id, count() AS requests,
       sum(bytes_in) AS bytes_in, sum(bytes_out) AS bytes_out,
       sum(ifNull(origin_latency_ms, 0)) AS origin_latency_sum,
       countIf(origin_latency_ms IS NOT NULL) AS origin_latency_samples,
       countIf(origin_error != '') AS origin_errors,
       countIf(tls_error != '') AS tls_failures,
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

CREATE MATERIALIZED VIEW IF NOT EXISTS cdnf.dns_hourly_mv TO cdnf.dns_hourly AS
SELECT toStartOfHour(occurred_at) AS interval_start, domain_id, zone, qtype, rcode,
       country, continent, dns_cluster, count() AS queries
FROM cdnf.dns_events
GROUP BY interval_start, domain_id, zone, qtype, rcode, country, continent, dns_cluster;

CREATE TABLE IF NOT EXISTS cdnf.edge_daily AS cdnf.edge_hourly
ENGINE = SummingMergeTree PARTITION BY toYYYYMM(interval_start)
ORDER BY (domain_id, interval_start, hostname, status, cache_status, country, continent, edge_id)
TTL interval_start + INTERVAL 3 YEAR DELETE;

CREATE MATERIALIZED VIEW IF NOT EXISTS cdnf.edge_daily_mv TO cdnf.edge_daily AS
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

CREATE MATERIALIZED VIEW IF NOT EXISTS cdnf.dns_daily_mv TO cdnf.dns_daily AS
SELECT toStartOfDay(interval_start) AS interval_start, domain_id, zone, qtype, rcode,
       country, continent, dns_cluster, sum(queries) AS queries
FROM cdnf.dns_hourly GROUP BY interval_start, domain_id, zone, qtype, rcode, country, continent, dns_cluster;
