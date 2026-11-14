-- The security_hourly view only rolls up events inserted after it was created, so the attack score, action,
-- country, and network breakdowns stayed empty for the days of events already stored. Roll those events up once.
-- Hours at or after the first rolled-up hour are left to the view, so nothing is counted twice.
INSERT INTO cdnf.security_hourly
SELECT toStartOfHour(occurred_at) AS interval_start, domain_id,
       multiIf(attack_score = 0, 0, attack_score < 20, 1, attack_score < 40, 2, attack_score < 60, 3, attack_score < 80, 4, 5) AS attack_band,
       multiIf(security_action = 'block', 'blocked', security_rule != '', 'logged', 'allowed') AS action,
       security_reason AS reason, country, asn, count() AS requests
FROM cdnf.edge_events
WHERE event_type = 'request'
  AND occurred_at < ifNull((SELECT min(interval_start) FROM cdnf.security_hourly), now() - INTERVAL 1 MINUTE)
GROUP BY interval_start, domain_id, attack_band, action, reason, country, asn;
