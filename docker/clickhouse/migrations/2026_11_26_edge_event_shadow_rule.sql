ALTER TABLE cdnf.edge_events
    ADD COLUMN IF NOT EXISTS shadow_rule String DEFAULT '' AFTER security_rule;

CREATE TABLE IF NOT EXISTS cdnf.rule_matches_hourly
(
    interval_start DateTime('UTC'), domain_id UInt64, rule_ref LowCardinality(String),
    mode LowCardinality(String), requests UInt64
)
ENGINE = SummingMergeTree
PARTITION BY toYYYYMM(interval_start)
ORDER BY (domain_id, interval_start, rule_ref, mode)
TTL interval_start + INTERVAL 400 DAY DELETE;

CREATE MATERIALIZED VIEW IF NOT EXISTS cdnf.rule_matches_enforced_mv TO cdnf.rule_matches_hourly DEFINER = cdnf SQL SECURITY DEFINER AS
SELECT toStartOfHour(occurred_at) AS interval_start, domain_id, security_rule AS rule_ref, 'enforce' AS mode, count() AS requests
FROM cdnf.edge_events
WHERE event_type = 'request' AND security_rule != '' AND security_rule != 'waf'
GROUP BY interval_start, domain_id, rule_ref;

CREATE MATERIALIZED VIEW IF NOT EXISTS cdnf.rule_matches_shadow_mv TO cdnf.rule_matches_hourly DEFINER = cdnf SQL SECURITY DEFINER AS
SELECT toStartOfHour(occurred_at) AS interval_start, domain_id, arrayJoin(splitByChar(',', shadow_rule)) AS rule_ref, 'shadow' AS mode, count() AS requests
FROM cdnf.edge_events
WHERE event_type = 'request' AND shadow_rule != ''
GROUP BY interval_start, domain_id, rule_ref;
