"""SOC dashboards."""
from __future__ import annotations

from dashboard_lib import *  # noqa: F403
from dashboards_common import *  # noqa: F403
from dashboards_noc import firing_alerts, with_span

# Client addresses are shown only as the network the platform's privacy setting allows: /24 for IPv4 and /48 for IPv6.
NETWORK = "if(position(client_ip, ':') > 0, toString(IPv6CIDRToRange(toIPv6OrZero(client_ip), 48).1) || '/48', toString(IPv4CIDRToRange(toIPv4OrZero(client_ip), 24).1) || '/24')"
RAW = "$__timeFilter(occurred_at) AND occurred_at > now() - INTERVAL 7 DAY AND event_type != 'tls'"
# Rules are rare, so their tables always look at the last seven days; the range would often leave them empty.
RAW_WEEK = "occurred_at > now() - INTERVAL 7 DAY AND event_type != 'tls'"
BLOCKED = "security_action != 'allow'"
SEC = "sum(requests)"
SCOPE = "$__conditionalAll(region IN (${region:singlequote}), $region) AND $__conditionalAll(pop IN (${pop:singlequote}), $pop) AND $__conditionalAll(edge_id IN (${edge:singlequote}), $edge) AND $__conditionalAll(domain_id IN (${domain:csv}), $domain)"


def security_overview() -> dict:
    rows = [
        [stat("Blocked requests", "Requests the edge refused in the selected range.", [ch(traffic_sql("sum(security_blocks) AS blocked"), table=True)], "short", "zero_warn", w=6),
         stat("WAF matches", "Requests the WAF matched in the selected range.", [ch(traffic_sql("sumIf(requests, security_class = 'waf') AS waf"), table=True)], "short", "zero_warn", w=6),
         stat("Emergency modes", "Emergency modes switched on and not expired.", [prom("cdnfoundry_emergency_modes_active", "emergency", instant=True)], "short", "zero_warn", w=6, link="/d/cdnf-security-overview"),
         stat("Under attack alerts", "Security alerts firing now.", [prom('count(ALERTS{alertstate="firing",team="soc"}) or vector(0)', "alerts", instant=True)], "short", "zero_warn", w=6)],
        [timeseries("Security events by reason", "Security events by class: waf, block, cache abuse, emergency mode, and WAF body limit.", [ch(split("security_class", "sum(requests)", "events", where="security_class != ''", per_second=True))], "reqps", stack=True),
         timeseries("Blocks per PoP", "Blocked requests per second by PoP.", [prom(f"sum by (pop) (cdnf:edge_security_events:rate5m{{{S}}})", "{{pop}}")], "reqps", stack=True)],
        [bargauge("Top attacked domains", "Hostnames with the most blocked requests.", [ch(top("hostname", "sum(security_blocks)", "blocked", hostname=True, where="security_blocks > 0"), table=True)], "short", "zero_warn"),
         timeseries("Blocks over time", "Blocked requests per second.", [ch(traffic_sql("sum(security_blocks) AS blocked", time=True, per_second=True))], "reqps", threshold="zero_warn")],
        row("Application protection"),
        [timeseries("Attack score bands", "Requests scored as an attack per interval, by band, over the last 7 days at most. Band 1 is a weak signal (score under 20), band 5 a near-certain attack (80 and over). Requests scored zero are not drawn.",
                    [ch("SELECT $__timeInterval(occurred_at) AS time, concat('band ', toString(multiIf(attack_score < 20, 1, attack_score < 40, 2, attack_score < 60, 3, attack_score < 80, 4, 5))) AS band, count() AS requests FROM cdnf.edge_events WHERE $__timeFilter(occurred_at) AND occurred_at > now() - INTERVAL 7 DAY AND event_type = 'request' AND attack_score > 0 AND $__conditionalAll(domain_id IN (${domain:csv}), $domain) GROUP BY time, band ORDER BY time")], "short", stack=True, w=12),
         bargauge("Blocks and logs by reason", "Why the edge acted on a request: rate limits, custom rules, the WAF, and cache abuse protection.",
                  [ch("SELECT security_reason AS reason_name, count() AS requests FROM cdnf.edge_events WHERE $__timeFilter(occurred_at) AND occurred_at > now() - INTERVAL 7 DAY AND event_type = 'request' AND security_reason != '' AND $__conditionalAll(domain_id IN (${domain:csv}), $domain) GROUP BY reason_name ORDER BY requests DESC LIMIT 10", table=True)], "short", "zero_warn", w=12)],
        [timeseries("Client-side reports", "Browser reports of blocked scripts and connections that reached the edge, per hour.",
                    [ch("SELECT $__timeInterval(occurred_at) AS time, count() AS reports FROM cdnf.edge_events WHERE $__timeFilter(occurred_at) AND occurred_at > now() - INTERVAL 7 DAY AND client_report != '' AND $__conditionalAll(domain_id IN (${domain:csv}), $domain) GROUP BY time ORDER BY time")], "short", threshold="zero_warn", w=12),
         bargauge("Custom rules that matched", "Domain rules that matched most, by rule name, over the last 7 days.",
                  [ch("SELECT security_rule AS rule_name, count() AS matches FROM cdnf.edge_events WHERE occurred_at > now() - INTERVAL 7 DAY AND security_rule != '' AND $__conditionalAll(domain_id IN (${domain:csv}), $domain) GROUP BY rule_name ORDER BY matches DESC LIMIT 10", table=True)], "short", w=12)],
        [firing_alerts(24, 8, "Security alerts")],
    ]
    return dashboard("cdnf-security-overview", "Security overview", "cdnf-soc", "Blocks, WAF matches, emergency-mode domains, security events by reason, and the top attacked domains, all with trends.", rows, runbook="waf-block-spike", parent=("cdnf-now", "Now"))


def waf() -> dict:
    rule_var = {"name": "rule_id", "label": "WAF rule", "type": "textbox", "query": "0", "current": {"text": "0", "value": "0"}, "hide": 0, "options": [{"selected": True, "text": "0", "value": "0"}], "skipUrlSync": False}
    rows = [
        [stat("WAF actions", "Requests the WAF inspected and matched.", [ch(traffic_sql("sumIf(requests, waf_action != 'off') AS actions"), table=True)], "short", w=6),
         stat("Body-limit hits", "Requests refused because their body exceeded the WAF inspection limit.", [ch(traffic_sql("sumIf(requests, security_class = 'waf_body_limit') AS hits"), table=True)], "short", "zero_warn", w=6),
         stat("Exclusions in use", "WAF exclusions that have not expired.", [pg("SELECT count(*) AS exclusions FROM grafana_waf_exclusions WHERE expires_at > now()")], "short", w=6),
         stat("Processing time p95", "95th percentile WAF processing time per request.", [ch(f"SELECT quantile(0.95)(waf_processing_us) / 1000 AS ms FROM cdnf.edge_events WHERE {RAW} AND waf_action != 'off'", table=True)], "ms", "latency_ms", w=6, decimals=2)],
        [timeseries("WAF actions", "Requests per second by WAF action.", [ch(split("waf_action", "sum(requests)", "requests", where="waf_action != 'off'", per_second=True))], "reqps", stack=True),
         timeseries("WAF processing time", "Average and 95th percentile WAF processing time.", [ch(f"SELECT $__timeInterval(occurred_at) AS time, avg(waf_processing_us) / 1000 AS average, quantile(0.95)(waf_processing_us) / 1000 AS p95 FROM cdnf.edge_events WHERE {RAW} AND waf_action != 'off' GROUP BY time ORDER BY time")], "ms")],
        [table("Top rules", "The WAF rules matched most often in the last 7 days. Select a rule to list its requests below.",
               [ch(f"SELECT waf_rule_id AS rule, count() AS matches, round(avg(waf_score), 1) AS average_score FROM cdnf.edge_events WHERE {RAW_WEEK} AND waf_rule_id != 0 GROUP BY waf_rule_id ORDER BY matches DESC LIMIT 15", table=True)], w=12,
               overrides=[{"matcher": {"id": "byName", "options": "rule"}, "properties": [{"id": "links", "value": [{"title": "Show requests for this rule", "url": "/d/cdnf-waf?${__url_time_range}&var-rule_id=${__value.raw}"}]}]}]),
         table("Top rules by domain", "Which hostnames trigger each rule.", [ch(f"SELECT hostname, waf_rule_id AS rule, count() AS matches FROM cdnf.edge_events WHERE {RAW_WEEK} AND waf_rule_id != 0 GROUP BY hostname, waf_rule_id ORDER BY matches DESC LIMIT 15", table=True)], w=12)],
        [table("Requests for the selected rule", "Requests that matched the rule in the WAF rule field above. Choose a rule from the top rules table.",
               [ch(f"SELECT occurred_at AS time, request_id, hostname, path, status, waf_score, waf_action FROM cdnf.edge_events WHERE {RAW_WEEK} AND waf_rule_id = toUInt32OrZero('${{rule_id}}') AND waf_rule_id != 0 ORDER BY occurred_at DESC LIMIT 100", table=True)], w=24, h=8,
               overrides=[{"matcher": {"id": "byName", "options": "request_id"}, "properties": [{"id": "links", "value": [{"title": "Trace this request", "url": "/d/cdnf-request-trace?var-request_id=${__value.raw}"}]}]}])],
    ]
    return dashboard("cdnf-waf", "WAF", "cdnf-soc", "Actions, rules, scores, processing time, body-limit hits, exclusions in use, and the top rules by domain.", rows, runbook="waf-block-spike", extra_variables=[rule_var])


def l7_flood() -> dict:
    baseline = table("Request rate against baseline", "Each domain's request rate in the last 10 minutes against its average over the previous day. A ratio above 4 is a flood candidate.", [ch(
        "SELECT cur.domain_id AS domain, cur.requests / 600 AS rate_now, base.requests / 86400 AS rate_baseline, (cur.requests / 600) / nullIf(base.requests / 86400, 0) AS ratio "
        "FROM (SELECT domain_id, sum(requests) AS requests FROM cdnf.edge_traffic_1m WHERE interval_start > now() - INTERVAL 10 MINUTE AND " + SCOPE + " GROUP BY domain_id) AS cur "
        "LEFT JOIN (SELECT domain_id, sum(requests) AS requests FROM cdnf.edge_traffic_hourly WHERE interval_start BETWEEN now() - INTERVAL 25 HOUR AND now() - INTERVAL 1 HOUR AND " + SCOPE + " GROUP BY domain_id) AS base USING domain_id "
        "ORDER BY ratio DESC LIMIT 20", table=True)], w=24, h=8, overrides=[
        {"matcher": {"id": "byName", "options": "ratio"}, "properties": [{"id": "decimals", "value": 1}, {"id": "thresholds", "value": {"mode": "absolute", "steps": THRESHOLDS["flood_ratio"]}}, {"id": "custom.cellOptions", "value": {"type": "color-text"}}]},
        {"matcher": {"id": "byRegexp", "options": "rate_.*"}, "properties": [{"id": "unit", "value": "reqps"}, {"id": "decimals", "value": 2}]}])
    rows = [
        [baseline],
        [bargauge("Top client networks", "Client networks sending the most requests, shown as the /24 or /48 the privacy setting allows.", [ch(f"SELECT {NETWORK} AS network, count() AS requests FROM cdnf.edge_events WHERE {RAW} GROUP BY network ORDER BY requests DESC LIMIT 10", table=True)], "short", w=8),
         bargauge("Top ASNs", "Autonomous systems sending the most requests in the last 7 days.", [ch(f"SELECT concat('AS', toString(asn)) AS asn_label, count() AS request_count FROM cdnf.edge_events WHERE {RAW} AND asn != 0 GROUP BY asn_label ORDER BY request_count DESC LIMIT 10", table=True)], "short", w=8),
         bargauge("Top countries", "Countries sending the most requests.", [ch(top("country", CH_REQUESTS, "requests"), table=True)], "short", w=8)],
        [bargauge("Top user agents", "User agents seen most often in the last 7 days.", [ch(f"SELECT substring(user_agent, 1, 60) AS agent, count() AS requests FROM cdnf.edge_events WHERE {RAW} GROUP BY agent ORDER BY requests DESC LIMIT 10", table=True)], "short", w=8),
         bargauge("Top paths", "Paths requested most often in the last 7 days.", [ch(f"SELECT path, count() AS requests FROM cdnf.edge_events WHERE {RAW} GROUP BY path ORDER BY requests DESC LIMIT 10", table=True)], "short", w=8),
         timeseries("Cache abuse events", "Requests flagged as cache abuse, per second.", [ch(traffic_sql("sumIf(requests, security_class = 'cache_abuse_detected') AS events", time=True, per_second=True))], "reqps", threshold="zero_warn", w=8)],
        [timeseries("Requests by PoP", "Request rate per PoP; a flood shows as one line leaving the others.", [prom(f"sum by (pop) ({req()})", "{{pop}}")], "reqps", w=24)],
    ]
    return dashboard("cdnf-l7-flood", "L7 attack and flood", "cdnf-soc", "Request rate per domain against its baseline, and the top client networks, ASNs, countries, user agents, and paths during a spike.", rows, runbook="request-flood")


def dns_abuse() -> dict:
    rows = [
        [stat("NXDOMAIN share", "Share of answers that are NXDOMAIN.", [prom(f'100 * sum(cdnf:dns_queries:rate5m{{{SP},rcode="NXDOMAIN"}}) / sum(cdnf:dns_queries:rate5m{{{SP}}})', "nxdomain", instant=True)], "percent", "error_percent", w=6, decimals=1),
         stat("Response-limit hits", "Truncated answers per second sent to limit a client.", [prom(f'sum(cdnf:dns_queries:rate5m{{{SP},outcome="response_limit"}})', "limited", instant=True)], "reqps", "zero_warn", w=6, decimals=2),
         stat("Queries", "Authoritative queries per second.", [prom(DNS_QPS, "qps", instant=True)], "reqps", w=6, decimals=1),
         stat("REFUSED share", "Share of answers that are REFUSED.", [prom(f'100 * sum(cdnf:dns_queries:rate5m{{{SP},rcode="REFUSED"}}) / sum(cdnf:dns_queries:rate5m{{{SP}}})', "refused", instant=True)], "percent", "error_percent", w=6, decimals=1)],
        [timeseries("NXDOMAIN rate", "NXDOMAIN answers per second; a random-subdomain flood is a steep rise.", [prom(f'sum by (pop) (cdnf:dns_queries:rate5m{{{SP},rcode="NXDOMAIN"}})', "{{pop}}")], "reqps", threshold="zero_warn"),
         timeseries("Response-limit hits", "Truncated answers per second by PoP.", [prom(f'sum by (pop) (cdnf:dns_queries:rate5m{{{SP},outcome="response_limit"}})', "{{pop}}")], "reqps", threshold="zero_warn")],
        [bargauge("Top queried zones", "Zones that receive the most queries.", [ch("SELECT if(zone = '', '(outside served zones)', zone) AS zone_name, sum(queries) AS query_count FROM cdnf.dns_hourly WHERE interval_start >= toStartOfHour($__fromTime) AND interval_start <= $__toTime GROUP BY zone_name ORDER BY query_count DESC LIMIT 10", table=True)], "short", w=8),
         bargauge("Zones with the most NXDOMAIN", "Zones where random names are asked most often.", [ch("SELECT if(zone = '', '(outside served zones)', zone) AS zone_name, sum(queries) AS query_count FROM cdnf.dns_hourly WHERE interval_start >= toStartOfHour($__fromTime) AND interval_start <= $__toTime AND rcode = 'NXDOMAIN' GROUP BY zone_name ORDER BY query_count DESC LIMIT 10", table=True)], "short", "zero_warn", w=8),
         bargauge("Query types", "Query types by volume. ANY, TXT, and DNSKEY are the ones used to amplify.", [ch("SELECT qtype, sum(queries) AS query_count FROM cdnf.dns_hourly WHERE interval_start >= toStartOfHour($__fromTime) AND interval_start <= $__toTime GROUP BY qtype ORDER BY query_count DESC LIMIT 10", table=True)], "short", w=8)],
        [bargauge("Top client networks", "Client networks sending the most DNS queries in the last 7 days, as /24 or /48.", [ch(f"SELECT {NETWORK} AS network, count() AS queries FROM cdnf.dns_events WHERE $__timeFilter(occurred_at) AND occurred_at > now() - INTERVAL 7 DAY GROUP BY network ORDER BY queries DESC LIMIT 10", table=True)], "short", w=12),
         with_span(timeseries("Amplification-prone queries", "ANY, TXT, and DNSKEY queries per hour over the last 24 hours, whatever range the dashboard shows.", [ch("SELECT $__timeInterval(interval_start) AS time, qtype, sum(queries) AS queries FROM cdnf.dns_hourly WHERE interval_start >= toStartOfHour($__fromTime) AND interval_start <= $__toTime AND qtype IN ('ANY', 'TXT', 'DNSKEY') GROUP BY time, qtype ORDER BY time")], "short", w=12), "24h")],
    ]
    return dashboard("cdnf-dns-abuse", "DNS abuse", "cdnf-soc", "Random-subdomain and NXDOMAIN floods, response-limit hits, top queried zones and client networks, and amplification-prone query types.", rows, runbook="dns-flood")


def tls_posture() -> dict:
    rows = [
        [stat("Handshake failures", "TLS handshakes that failed in the selected range.", [ch("SELECT sum(failures) AS failures FROM cdnf.tls_failures_hourly WHERE interval_start >= toStartOfHour($__fromTime) AND interval_start <= $__toTime", table=True)], "short", "zero_warn", w=6),
         stat("Expiring in 14 days", "Active certificates that expire within 14 days.", [pg("SELECT count(*) AS expiring FROM grafana_tls_certificates WHERE status = 'active' AND expires_at < now() + interval '14 days'")], "short", "zero_good", w=6),
         stat("ACME fail", "Managed certificate orders that failed in the last 24 hours.", [prom('cdnfoundry_acme_orders_day{status="failed"}', "failed", instant=True)], "short", "zero_warn", w=6),
         stat("Slowest issuance", "Longest managed certificate issuance in the last 24 hours.", [prom("cdnfoundry_acme_issuance_seconds_max", "slowest", instant=True)], "s", "age_seconds", w=6)],
        [with_span(timeseries("Handshake failures by reason", "Failed handshakes per hour by reason over the last 24 hours, whatever range the dashboard shows.", [ch("SELECT $__timeInterval(interval_start) AS time, reason, sum(failures) AS failures FROM cdnf.tls_failures_hourly WHERE interval_start >= toStartOfHour($__fromTime) AND interval_start <= $__toTime GROUP BY time, reason ORDER BY time")], "short", stack=True), "24h"),
         with_span(timeseries("Handshake failures by protocol", "Failed handshakes per hour by TLS version over the last 24 hours, whatever range the dashboard shows.", [ch("SELECT $__timeInterval(interval_start) AS time, ssl_protocol AS protocol, sum(failures) AS failures FROM cdnf.tls_failures_hourly WHERE interval_start >= toStartOfHour($__fromTime) AND interval_start <= $__toTime GROUP BY time, protocol ORDER BY time")], "short", stack=True), "24h")],
        [piechart("Protocol mix", "Requests by TLS version.", [ch(top("ssl_protocol", CH_REQUESTS, "requests", coarse=True, where="ssl_protocol != ''"), table=True)], "short"),
         piechart("Cipher mix", "Requests by negotiated cipher.", [ch(top("ssl_cipher", CH_REQUESTS, "requests", coarse=True, where="ssl_cipher != ''"), table=True)], "short"),
         table("Certificates by expiry", "Active certificates, soonest to expire first.", [pg("SELECT domain_id, kind, status, expires_at, extract(day FROM expires_at - now())::int AS days_left FROM grafana_tls_certificates WHERE status = 'active' ORDER BY expires_at LIMIT 50")], w=8)],
        [table("Recent ACME failures", "Managed certificate orders that failed, newest first.", [pg("SELECT domain_id, status, started_at, finished_at FROM grafana_tls_orders WHERE status = 'failed' ORDER BY finished_at DESC NULLS LAST LIMIT 20")], w=24)],
    ]
    return dashboard("cdnf-tls-posture", "TLS posture", "cdnf-soc", "Handshake failures by reason, protocol and cipher mix, certificates by expiry, and ACME failures.", rows, runbook="tls-handshake-failures")


def access_audit() -> dict:
    rows = [
        [stat("Sign-ins", "Successful sign-ins in the last hour.", [prom('sum(increase(cdnfoundry_logins_total{result="success"}[1h])) or vector(0)', "ok", instant=True)], "short", w=6),
         stat("Failed sign-ins (1h)", "Failed sign-ins in the last hour.", [prom('sum(increase(cdnfoundry_logins_total{result="failure"}[1h])) or vector(0)', "failed", instant=True)], "short", "zero_warn", w=6),
         stat("New-network admin sign-ins", "Administrator sign-ins from a network unused for 30 days, in the last 15 minutes.", [prom("cdnfoundry_admin_logins_new_network_recent", "new", instant=True)], "short", "zero_warn", w=6),
         stat("Token requests", "API requests made with a token, per second.", [prom("sum(rate(cdnfoundry_api_token_requests_total[5m])) or vector(0)", "tokens", instant=True)], "reqps", w=6, decimals=2)],
        [timeseries("Sign-ins by result", "Sign-ins per second by result and surface.", [prom('sum by (result, surface) (cdnf:logins:rate5m) or label_replace(label_replace(vector(0), "result", "none", "", ""), "surface", "none", "", "")', "{{result}} {{surface}}")], "reqps"),
         timeseries("Token use by ability", "API requests per second by token ability.", [prom('sum by (ability) (rate(cdnfoundry_api_token_requests_total[5m])) or label_replace(vector(0), "ability", "none", "", "")', "{{ability}}")], "reqps", stack=True)],
        [table("Recent sign-ins", "Successful sign-ins by account and network. Addresses are masked to /24 or /48.", [pg("SELECT created_at AS time, actor_id AS account, network FROM grafana_audit_events WHERE action = 'auth.login' ORDER BY created_at DESC LIMIT 50")], w=12),
         table("Settings and rule changes", "Audit entries for platform settings, domains, and security rules.", [pg("SELECT created_at AS time, actor_id AS account, action FROM grafana_audit_events WHERE (action LIKE 'settings.%' OR action LIKE 'platform.%' OR action LIKE 'domain.%' OR action LIKE 'security.%' OR action LIKE 'waf.%') ORDER BY created_at DESC LIMIT 50")], w=12)],
        [with_span(timeseries("Audit entries per hour", "All audit entries per hour over the last 7 days, whatever range the dashboard shows.", [pg("SELECT t AS time, count(a.id) AS entries FROM generate_series(date_trunc('hour', $__timeFrom()::timestamptz), $__timeTo()::timestamptz, interval '1 hour') AS t LEFT JOIN grafana_audit_events a ON a.created_at >= t AND a.created_at < t + interval '1 hour' GROUP BY t ORDER BY t", table=False)], "short", w=24), "7d")],
    ]
    return dashboard("cdnf-access-audit", "Access and audit", "cdnf-soc", "Admin and user sign-ins, failed sign-ins, token use by ability, and audit entries for settings, domain, and security-rule changes.", rows, runbook="failed-login-burst")


def telemetry_integrity() -> dict:
    rows = [
        [stat("Rejected events (1h)", "Events dropped by authentication or validation in Vector in the last hour.", [prom('sum(increase(vector_component_discarded_events_total{component_id=~"decoded_.*|safe_.*"}[1h])) or vector(0)', "rejected", instant=True)], "short", "zero_warn", w=6),
         stat("Dropped at buffers (1h)", "Events dropped because a buffer was full, in the last hour.", [prom('sum(increase(vector_component_discarded_events_total{component_id=~"clickhouse_.*"}[1h])) or vector(0)', "dropped", instant=True)], "short", "zero_warn", w=6),
         stat("Insert failures (1h)", "ClickHouse inserts Vector could not complete, in the last hour.", [prom('sum(increase(vector_component_errors_total{component_id=~"clickhouse_.*"}[1h])) or vector(0)', "failures", instant=True)], "short", "zero_warn", w=6),
         stat("Ingest delay", "Seconds between now and the newest event stored in ClickHouse.", [ch("SELECT dateDiff('second', max(occurred_at), now()) AS delay FROM cdnf.edge_events WHERE occurred_at > now() - INTERVAL 1 DAY", table=True)], "s", "age_seconds", w=6)],
        [timeseries("Rejected and forged events", "Events Vector refused, by component.", [prom('sum by (component_id) (rate(vector_component_discarded_events_total{component_id=~"decoded_.*|safe_.*"}[5m])) or label_replace(vector(0), "component_id", "none", "", "")', "{{component_id}}")], "ops"),
         timeseries("Dropped events and insert failures", "Buffer drops and delivery errors per second.", [prom('sum(rate(vector_component_discarded_events_total{component_id=~"clickhouse_.*"}[5m])) or vector(0)', "buffer drops"), prom('sum(rate(vector_component_errors_total{component_id=~"clickhouse_.*"}[5m])) or vector(0)', "insert failures")], "ops", threshold="zero_warn")],
        [timeseries("Events stored by PoP", "Events per second ClickHouse stored, per PoP. A PoP that goes flat is not delivering.", [ch(split("pop", "sum(requests)", "events", per_second=True))], "reqps", w=24)],
    ]
    return dashboard("cdnf-telemetry-integrity", "Telemetry integrity", "cdnf-soc", "Rejected and forged events, drops, and insert failures, so the SOC knows whether the data is complete.", rows, runbook="telemetry-integrity")


DASHBOARDS = [("20-soc", factory) for factory in (security_overview, waf, l7_flood, dns_abuse, tls_posture, access_audit, telemetry_integrity)]
