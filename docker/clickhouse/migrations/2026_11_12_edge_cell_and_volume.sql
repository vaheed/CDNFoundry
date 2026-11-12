-- Cell and traffic direction. The raw table gains the cell that served the request (edge_id is the
-- edge host the collector runs on). Two new compact rollups answer download and upload by edge, cell,
-- domain, and country without altering the existing rollup keys. Backfill-free: rows written before
-- this migration keep an empty cell and are not in the new rollups.
ALTER TABLE cdnf.edge_events
    ADD COLUMN IF NOT EXISTS cell LowCardinality(String) DEFAULT '';

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
