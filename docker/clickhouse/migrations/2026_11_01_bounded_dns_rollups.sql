-- Key the DNS rollups by the served zone instead of the queried name (A25), and keep a bounded
-- sketch of the busiest names. Existing rollup rows stay as they are; new events use the new keys.
CREATE TABLE IF NOT EXISTS cdnf.served_zones (generation UInt64, zone String, domain_id UInt64)
ENGINE = MergeTree ORDER BY (generation, zone) TTL toDateTime(generation) + INTERVAL 2 DAY DELETE;

CREATE DICTIONARY IF NOT EXISTS cdnf.served_zone_dictionary (zone String, domain_id UInt64)
PRIMARY KEY zone
SOURCE(CLICKHOUSE(USER 'cdnf_dictionary' QUERY 'SELECT zone, domain_id FROM cdnf.served_zones WHERE generation = (SELECT max(generation) FROM cdnf.served_zones)'))
LAYOUT(COMPLEX_KEY_HASHED())
LIFETIME(MIN 30 MAX 90);

DROP VIEW IF EXISTS cdnf.dns_hourly_mv;
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

