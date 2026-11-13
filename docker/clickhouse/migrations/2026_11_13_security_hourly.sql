CREATE TABLE IF NOT EXISTS cdnf.security_hourly
(
    interval_start DateTime('UTC'), domain_id UInt64, attack_band UInt8,
    action LowCardinality(String), reason LowCardinality(String),
    country LowCardinality(String), asn UInt32, requests UInt64
)
ENGINE = SummingMergeTree
PARTITION BY toYYYYMM(interval_start)
ORDER BY (domain_id, interval_start, attack_band, action, reason, country, asn)
TTL interval_start + INTERVAL 400 DAY DELETE;

CREATE MATERIALIZED VIEW IF NOT EXISTS cdnf.security_hourly_mv TO cdnf.security_hourly DEFINER = cdnf SQL SECURITY DEFINER AS
SELECT toStartOfHour(occurred_at) AS interval_start, domain_id,
       multiIf(attack_score = 0, 0, attack_score < 20, 1, attack_score < 40, 2, attack_score < 60, 3, attack_score < 80, 4, 5) AS attack_band,
       multiIf(security_action = 'block', 'blocked', security_rule != '', 'logged', 'allowed') AS action,
       security_reason AS reason, country, asn, count() AS requests
FROM cdnf.edge_events
WHERE event_type = 'request'
GROUP BY interval_start, domain_id, attack_band, action, reason, country, asn;
