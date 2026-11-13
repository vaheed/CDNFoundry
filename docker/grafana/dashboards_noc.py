"""Overview and NOC dashboards."""
from __future__ import annotations

from dashboard_lib import *  # noqa: F403
from dashboards_common import *  # noqa: F403


def pop_table_query(expr: str, ref_name: str) -> dict:
    return prom(expr, ref_name, instant=True, fmt="table")


def with_span(panel: dict, span: str) -> dict:
    """A panel that always shows `span`: hourly series have no point inside a short range."""
    panel["timeFrom"] = span
    return panel


def wall(panel: dict) -> dict:
    panel["options"]["text"] = {"valueSize": 52, "titleSize": 18}
    return panel


ALERT_TABLE_EXPR = 'ALERTS_FOR_STATE{alertname!="Watchdog"} * 1000 and ignoring(alertstate) ALERTS{alertstate="firing",alertname!="Watchdog"}'
ALERT_TRANSFORMS = [
    {"id": "filterFieldsByName", "options": {"include": {"names": ["alertname", "team", "severity", "pop", "dashboard", "Value"]}}},
    {"id": "organize", "options": {
        "excludeByName": {"Time": True, "__name__": True},
        "renameByName": {"alertname": "Cause", "team": "Team", "severity": "Severity", "Value": "Since", "dashboard": "Dashboard", "pop": "PoP"},
        "indexByName": {"Cause": 0, "Team": 1, "Severity": 2, "PoP": 3, "Since": 4, "Dashboard": 5}}},
]


def firing_alerts(w: int = 24, h: int = 8, title: str = "Firing alerts") -> dict:
    panel = table("Firing alerts", "Every firing alert with its team, severity, and start time. Open the dashboard column to see the cause at the time it started.",
                  [prom(ALERT_TABLE_EXPR, "firing", instant=True, fmt="table")], w=w, h=h, transformations=ALERT_TRANSFORMS, sort="Since",
                  overrides=[
                      {"matcher": {"id": "byName", "options": "Cause"}, "properties": [{"id": "custom.minWidth", "value": 220}]},
                      {"matcher": {"id": "byName", "options": "Team"}, "properties": [{"id": "custom.width", "value": 90}]},
                      {"matcher": {"id": "byName", "options": "Severity"}, "properties": [{"id": "custom.width", "value": 90}]},
                      {"matcher": {"id": "byName", "options": "PoP"}, "properties": [{"id": "custom.width", "value": 110}]},
                      {"matcher": {"id": "byName", "options": "Since"}, "properties": [{"id": "unit", "value": "dateTimeFromNow"}, {"id": "custom.width", "value": 130}]},
                      {"matcher": {"id": "byName", "options": "Dashboard"}, "properties": [
                          {"id": "mappings", "value": DASHBOARD_TITLES},
                          {"id": "links", "value": [{"title": "Open dashboard at the start time", "url": "/d/${__value.raw}?from=${__data.fields.Since}&to=now"}]}]},
                  ])
    return shared("cdnf-lib-firing-alerts", panel, w=w, h=h)


def firing_alerts_compact(w: int = 6, h: int = 10, title: str = "Alerts") -> dict:
    """Cause and age only, for a narrow tile on the wall display."""
    panel = table(title, "Every firing alert with how long it has been firing. The full table with team, severity, and dashboard is on Now.",
                  [prom(ALERT_TABLE_EXPR, "firing", instant=True, fmt="table")], w=w, h=h, sort="Since", transformations=[
                      {"id": "filterFieldsByName", "options": {"include": {"names": ["alertname", "Value"]}}},
                      {"id": "organize", "options": {"excludeByName": {"Time": True}, "renameByName": {"alertname": "Cause", "Value": "Since"}, "indexByName": {"Cause": 0, "Since": 1}}}],
                  overrides=[{"matcher": {"id": "byName", "options": "Cause"}, "properties": [{"id": "custom.minWidth", "value": 150}]},
                             {"matcher": {"id": "byName", "options": "Since"}, "properties": [{"id": "unit", "value": "dateTimeFromNow"}, {"id": "custom.width", "value": 110}]}])
    return shared("cdnf-lib-firing-alerts-compact", panel, w=w, h=h)


def probe_table(w: int = 12, h: int = 8) -> dict:
    return shared("cdnf-lib-probe-results", table("Probe results", "The latest result of every synthetic probe, per PoP and IP version. 1 is up, 0 is down.",
                 [prom('probe_success{job=~"blackbox-.*"}', "probe", instant=True, fmt="table")], w=w, h=h, sort="Value",
                 transformations=[{"id": "organize", "options": {"excludeByName": {"Time": True, "__name__": True, "instance": True, "probe": True, "node": True},
                                  "renameByName": {"job": "Probe", "pop": "PoP", "ip_version": "IP", "region": "Region", "Value": "Up"},
                                  "indexByName": {"Up": 0, "PoP": 1, "Probe": 2, "IP": 3, "Region": 4}}}],
                 overrides=[{"matcher": {"id": "byName", "options": "IP"}, "properties": [{"id": "custom.width", "value": 60}, {"id": "mappings", "value": [{"type": "value", "options": {"4": {"text": "IPv4"}, "6": {"text": "IPv6"}}}]}]},
                     {"matcher": {"id": "byName", "options": "Up"}, "properties": [{"id": "custom.width", "value": 60},
                     {"id": "thresholds", "value": {"mode": "absolute", "steps": THRESHOLDS["health"]}},
                     {"id": "custom.cellOptions", "value": {"type": "color-background"}}]},
                     {"matcher": {"id": "byName", "options": "PoP"}, "properties": [{"id": "links", "value": [{"title": "Open PoP detail", "url": "/d/cdnf-pop-detail?var-pop=${__value.raw}"}]}]}]), w=w, h=h)


def requests_by_status(w: int = 12, h: int = 8) -> dict:
    return shared("cdnf-lib-requests-by-status", timeseries("Requests by status class", "Requests per second across the selected PoPs, split by response class.",
                      [prom(f'sum by (status_class) ({req()})', "{{status_class}}")], "reqps", w=w, h=h, stack=True), w=w, h=h)


def p95_by_pop(w: int = 12, h: int = 8) -> dict:
    return shared("cdnf-lib-edge-p95-by-pop", timeseries("Edge p95 by PoP", "95th percentile time to serve a request, per PoP.", [prom(f'1000 * histogram_quantile(0.95, sum by (le, pop) (cdnf:edge_latency_bucket:rate5m{{{S}}}))', "{{pop}}")], "ms", decimals=0, min_value=0), w=w, h=h)


HEALTH_STATS = lambda: [
    shared("cdnf-lib-health-unhealthy-components", stat("Unhealthy", "Control-plane components reporting degraded or unavailable.", [prom("count(cdnfoundry_component_health == 0) or vector(0)", "unhealthy", instant=True)], "short", "zero_good", link="/d/cdnf-control-plane")),
    shared("cdnf-lib-health-stale-edges", stat("Stale", "Enabled edges whose heartbeat is older than the freshness limit.", [prom("cdnfoundry_edges_stale", "stale", instant=True)], "short", "zero_warn", link="/d/cdnf-pop-fleet")),
    shared("cdnf-lib-health-failed-operations", stat("Failed ops", "Operations that failed in the failed-operations window.", [prom("cdnfoundry_operations_failed_recent", "failed", instant=True)], "short", "zero_warn", link="/d/cdnf-control-plane")),
    shared("cdnf-lib-health-dns-drift", stat("DNS drift", "DNS deployments that have not reached their desired revision.", [prom("cdnfoundry_dns_deployments_drifted", "drift", instant=True)], "short", "zero_warn", link="/d/cdnf-dns-authoritative")),
    shared("cdnf-lib-health-firing-alerts", stat("Alerts", "Alerts firing now, not counting the heartbeat.", [prom(FIRING, "firing", instant=True)], "short", "zero_warn")),
    shared("cdnf-lib-health-paused-rollouts", stat("Paused", "Fleet rollouts paused on a safety threshold.", [prom("cdnfoundry_fleet_rollouts_paused", "paused", instant=True)], "short", "zero_warn", link="/d/cdnf-fleet-rollouts")),
]
TRAFFIC_STATS = lambda: [
    shared("cdnf-lib-traffic-requests", stat("Requests", "Requests per second across the selected PoPs.", [prom(RPS, "requests", instant=True)], "reqps", decimals=1, link="/d/cdnf-global-traffic")),
    shared("cdnf-lib-traffic-bandwidth", stat("Bandwidth", "Bits per second sent to clients, headers included.", [prom(BYTES, "bandwidth", instant=True)], "bps", decimals=1)),
    shared("cdnf-lib-traffic-5xx-ratio", stat("5xx ratio", "Share of responses that are server errors.", [prom(ERR_PCT, "5xx", instant=True)], "percent", "error_percent", decimals=2)),
    shared("cdnf-lib-traffic-cache-hit", stat("Cache hit", "Share of requests served from cache.", [prom(HIT_PCT, "hit", instant=True)], "percent", "hit_percent", decimals=1, link="/d/cdnf-cache")),
    shared("cdnf-lib-traffic-edge-p95", stat("Edge p95", "95th percentile time to serve a request.", [prom(P95_MS, "p95", instant=True)], "ms", "latency_ms", decimals=0, link="/d/cdnf-edge-latency")),
    shared("cdnf-lib-traffic-origin-p95", stat("Origin p95", "95th percentile time an origin takes to answer.", [prom(ORIGIN_P95_MS, "origin", instant=True)], "ms", "latency_ms", decimals=0, link="/d/cdnf-origin-health")),
]


def now_dashboard() -> dict:
    status = stat("Platform status", "One sentence that names what is wrong, or says everything reports in.", [prom("cdnfoundry_status_summary", "{{summary}}", instant=True, fmt="table")], "none", w=24, h=3)
    status["options"]["reduceOptions"]["fields"] = "/^summary$/"
    status["options"]["text"] = {"valueSize": 28}
    pops = table("PoPs", "Each PoP with its traffic, 5xx ratio, and heartbeat age.", [
        prom(f"sum by (pop) ({req()})", "Requests", instant=True, fmt="table"),
        prom(f'100 * sum by (pop) ({req(",status_class=\"5xx\"")}) / sum by (pop) ({req()})', "5xx", instant=True, fmt="table"),
        prom('max by (pop) (cdnfoundry_edge_heartbeat_age_seconds{pop=~"$pop"})', "Heartbeat", instant=True, fmt="table"),
    ], w=12, h=8, transformations=[{"id": "merge", "options": {}}, {"id": "organize", "options": {"excludeByName": {"Time": True}, "renameByName": {"pop": "PoP", "Value #A": "Requests/s", "Value #B": "5xx %", "Value #C": "Heartbeat age"}}}],
        overrides=[{"matcher": {"id": "byName", "options": "PoP"}, "properties": [{"id": "custom.width", "value": 120}]},
                   {"matcher": {"id": "byName", "options": "Requests/s"}, "properties": [{"id": "unit", "value": "reqps"}, {"id": "decimals", "value": 1}, {"id": "custom.width", "value": 110}]},
                   {"matcher": {"id": "byName", "options": "5xx %"}, "properties": [{"id": "unit", "value": "percent"}, {"id": "decimals", "value": 2}, {"id": "custom.width", "value": 80}]},
                   {"matcher": {"id": "byName", "options": "Heartbeat age"}, "properties": [{"id": "unit", "value": "s"}, {"id": "custom.minWidth", "value": 130}]}])
    rows = [
        [status], [firing_alerts(24, 12)],
        row("Health"), HEALTH_STATS(),
        row("Global traffic"), TRAFFIC_STATS(), [requests_by_status(), p95_by_pop()],
        row("PoPs"), [pops, timeseries("Requests by PoP", "Requests per second for each PoP.", [prom(f"sum by (pop) ({req()})", "{{pop}}")], "reqps", stack=True)],
        row("DNS"), [
            stat("DNS queries", "Authoritative queries per second.", [prom(DNS_QPS, "qps", instant=True)], "reqps", w=6, decimals=1, link="/d/cdnf-dns-authoritative"),
            stat("SERVFAIL ratio", "Share of DNS answers that are SERVFAIL.", [prom(f'100 * sum(cdnf:dns_queries:rate5m{{{SP},rcode="SERVFAIL"}}) / sum(cdnf:dns_queries:rate5m{{{SP}}})', "servfail", instant=True)], "percent", "error_percent", w=6, decimals=2),
            timeseries("DNS answers by code", "Queries per second by response code.", [prom(f"sum by (rcode) (cdnf:dns_queries:rate5m{{{SP}}})", "{{rcode}}")], "reqps", stack=True)],
        row("Telemetry pipeline"), [
            stat("Events dropped", "Telemetry events Vector dropped in the last 5 minutes.", [prom('sum(increase(vector_component_discarded_events_total{intentional!="true"}[5m])) or vector(0)', "dropped", instant=True)], "short", "zero_warn", w=6, link="/d/cdnf-telemetry-pipeline"),
            stat("Delivery errors", "Telemetry delivery errors in the last 5 minutes.", [prom("sum(increase(vector_component_errors_total[5m])) or vector(0)", "errors", instant=True)], "short", "zero_warn", w=6),
            timeseries("Events delivered", "Events per second Vector delivered to ClickHouse.", [prom('sum by (component_id) (rate(vector_component_sent_events_total{component_id=~"clickhouse_.*"}[5m]))', "{{component_id}}")], "ops")],
    ]
    return dashboard("cdnf-now", "Now", "cdnf-overview", "What is wrong right now, and where to look. The home dashboard for every team.", rows, runbook="alerting-heartbeat", parent=None, refresh="10s", time_from="now-1h", tags=["home"])


def noc_wall() -> dict:
    pop_map = geomap("PoPs by health", "Every enabled PoP by country, green when its heartbeat is fresh and red when it is not.", [pg(
        "SELECT country_code AS country, name AS pop, CASE WHEN last_heartbeat_at > now() - interval '3 minutes' THEN 1 ELSE 0 END AS healthy FROM grafana_edges WHERE enabled = true")], w=8, h=10)
    stats = [
        wall(stat("Requests", "Requests per second across all PoPs.", [prom(RPS, "requests", instant=True)], "reqps", decimals=0, h=5)),
        wall(stat("Bandwidth", "Bits per second sent to clients.", [prom(BYTES, "bandwidth", instant=True)], "bps", decimals=1, h=5)),
        wall(stat("5xx ratio", "Share of responses that are server errors.", [prom(ERR_PCT, "5xx", instant=True)], "percent", "error_percent", decimals=2, h=5)),
        wall(stat("Cache hit", "Share of requests served from cache.", [prom(HIT_PCT, "hit", instant=True)], "percent", "hit_percent", decimals=1, h=5)),
        wall(stat("Edge p95", "95th percentile time to serve a request.", [prom(P95_MS, "p95", instant=True)], "ms", "latency_ms", decimals=0, h=5)),
        wall(stat("Alerts", "Alerts firing now.", [prom(FIRING, "firing", instant=True)], "short", "zero_warn", h=5)),
    ]
    rows = [stats, [pop_map, probe_table(8, 10), firing_alerts_compact(8, 10)], [requests_by_status(12, 8), timeseries("5xx and p95", "5xx ratio and edge p95 on one axis pair.", [prom(ERR_PCT, "5xx %"), prom(P95_MS, "p95 ms")], "short", w=12, h=8)]]
    return dashboard("cdnf-noc-wall", "NOC wall", "cdnf-noc", "Built for a kiosk on a large screen: global traffic, a map of PoPs, probes, and alerts, with no scrolling.", rows,
                     runbook="edge-5xx-ratio-high", refresh="10s", time_from="now-1h", tags=["kiosk"])


def global_traffic() -> dict:
    rows = [
        TRAFFIC_STATS(),
        [timeseries("Requests by region", "Requests per second by region.", [prom(f"sum by (region) ({req()})", "{{region}}")], "reqps", stack=True),
         timeseries("Requests by PoP", "Requests per second by PoP.", [prom(f"sum by (pop) ({req()})", "{{pop}}")], "reqps", stack=True)],
        [timeseries("Bandwidth by PoP", "Bits per second sent to clients, per PoP.", [prom(f"8 * sum by (pop) (cdnf:edge_bytes_sent:rate5m{{{S}}})", "{{pop}}")], "bps", stack=True),
         timeseries("Requests by protocol", "Requests per second by HTTP version.", [ch(split("protocol", CH_REQUESTS, "requests", per_second=True))], "reqps", stack=True)],
        [timeseries("Requests by IP version", "Requests per second from IPv4 and IPv6 clients.", [ch(split("if(ip_version = '', 'not recorded', concat('IPv', ip_version)) AS ip_version", CH_REQUESTS, "requests", per_second=True))], "reqps", stack=True, w=8),
         bargauge("Top countries", "Countries sending the most requests.", [ch(top("country", CH_REQUESTS, "requests"), table=True)], "short", w=8),
         bargauge("Top ASNs (last 7 days)", "Client networks sending the most requests. ASNs are kept in raw events only, so this covers at most 7 days.",
                  [ch("SELECT concat('AS', toString(asn)) AS asn_label, count() AS request_count FROM cdnf.edge_events WHERE $__timeFilter(occurred_at) AND occurred_at > now() - INTERVAL 7 DAY AND asn != 0 AND event_type = 'request' GROUP BY asn_label ORDER BY request_count DESC LIMIT 10", table=True)], "short", w=8)],
        [bargauge("Top domains by requests", "Hostnames with the most requests.", [ch(top("hostname", CH_REQUESTS, "requests", hostname=True), table=True)], "short", w=8),
         bargauge("Top domains by bandwidth", "Hostnames that sent the most bytes.", [ch(top("hostname", CH_BYTES, "bytes", hostname=True), table=True)], "bytes", w=8),
         bargauge("Top domains by errors", "Hostnames with the most 5xx responses.", [ch(top("hostname", "sumIf(requests, status >= 500)", "errors", hostname=True), table=True)], "short", "zero_warn", w=8)],
    ]
    return dashboard("cdnf-global-traffic", "Global traffic", "cdnf-noc", "Every traffic measure summed across all PoPs, then split by region, PoP, protocol, IP version, country, ASN, and domain.", rows, runbook="edge-5xx-ratio-high")


def pop_fleet() -> dict:
    def node_pop(expr: str) -> str:
        return f'label_replace({expr}, "pop", "$1", "node", "(.*)")'
    fleet = table("PoP fleet", "One row per PoP with health, heartbeat age, applied revision, traffic, errors, latency, host load, and cache fill.", [
        prom('max by (pop) (cdnfoundry_edge_heartbeat_age_seconds{pop=~"$pop"})', "Heartbeat", instant=True, fmt="table"),
        prom('max by (pop) (cdnfoundry_edge_gateway_active_revision{pop=~"$pop"})', "Applied", instant=True, fmt="table"),
        prom('max(cdnfoundry_edge_gateway_active_revision)', "Latest", instant=True, fmt="table"),
        prom(f"sum by (pop) ({req()})", "Requests", instant=True, fmt="table"),
        prom(f'100 * sum by (pop) ({req(",status_class=\"5xx\"")}) / sum by (pop) ({req()})', "5xx", instant=True, fmt="table"),
        prom(f'1000 * histogram_quantile(0.95, sum by (le, pop) (cdnf:edge_latency_bucket:rate5m{{{S}}}))', "p95", instant=True, fmt="table"),
        prom(node_pop('100 * (1 - avg by (node) (rate(node_cpu_seconds_total{mode="idle"}[5m])))'), "CPU", instant=True, fmt="table"),
        prom(node_pop('100 * (1 - node_memory_MemAvailable_bytes / node_memory_MemTotal_bytes)'), "Memory", instant=True, fmt="table"),
        prom(node_pop('100 * (1 - node_filesystem_avail_bytes{mountpoint="/",fstype!~"tmpfs|overlay"} / node_filesystem_size_bytes{mountpoint="/",fstype!~"tmpfs|overlay"})'), "Disk", instant=True, fmt="table"),
        prom('100 * max by (pop) (cdnfoundry_cell_capacity_ratio{resource="cache",pop=~"$pop"})', "Cache", instant=True, fmt="table"),
    ], w=24, h=10, transformations=[{"id": "merge", "options": {}}, {"id": "organize", "options": {"excludeByName": {"Time": True, "node": True},
        "renameByName": {"pop": "PoP", **{f"Value #{chr(65 + i)}": name for i, name in enumerate(["Heartbeat age", "Applied revision", "Latest revision", "Requests/s", "5xx %", "p95 ms", "CPU %", "Memory %", "Disk %", "Cache fill %"])}}}}],
        overrides=[{"matcher": {"id": "byName", "options": "Heartbeat age"}, "properties": [{"id": "unit", "value": "s"}, {"id": "thresholds", "value": {"mode": "absolute", "steps": THRESHOLDS["age_seconds"]}}, {"id": "custom.cellOptions", "value": {"type": "color-text"}}]},
                   {"matcher": {"id": "byRegexp", "options": "(Applied|Latest) revision"}, "properties": [{"id": "unit", "value": "none"}, {"id": "decimals", "value": 0}]},
                   {"matcher": {"id": "byName", "options": "Requests/s"}, "properties": [{"id": "unit", "value": "reqps"}, {"id": "decimals", "value": 1}]},
                   {"matcher": {"id": "byName", "options": "5xx %"}, "properties": [{"id": "unit", "value": "percent"}, {"id": "decimals", "value": 2}]},
                   {"matcher": {"id": "byName", "options": "p95 ms"}, "properties": [{"id": "unit", "value": "ms"}, {"id": "decimals", "value": 0}]},
                   {"matcher": {"id": "byRegexp", "options": "(CPU|Memory|Disk|Cache fill) %"}, "properties": [{"id": "unit", "value": "percent"}, {"id": "decimals", "value": 0}]}])
    rows = [
        [fleet],
        [stat("Edges behind", "Enabled PoPs whose applied revision is older than the latest.", [prom("count(cdnfoundry_edge_gateway_active_revision < scalar(max(cdnfoundry_edge_gateway_active_revision))) or vector(0)", "behind", instant=True)], "short", "zero_warn", w=6),
         stat("Version drift", "PoPs running component versions that differ from the desired versions.", [prom("sum(cdnfoundry_edge_runtime_version_drift) or vector(0)", "drift", instant=True)], "short", "zero_warn", w=6),
         stat("Paused", "Fleet rollouts paused on a safety threshold.", [prom("cdnfoundry_fleet_rollouts_paused", "paused", instant=True)], "short", "zero_warn", w=6),
         stat("Gateways unready", "Enabled gateways that are not ready.", [prom("cdnfoundry_edge_gateways_unready", "unready", instant=True)], "short", "zero_good", w=6)],
        [timeseries("Heartbeat age by PoP", "Seconds since each PoP last reported.", [prom('max by (pop) (cdnfoundry_edge_heartbeat_age_seconds{pop=~"$pop"})', "{{pop}}")], "s", threshold="age_seconds"),
         timeseries("Applied revision by PoP", "The configuration revision each PoP serves.", [prom('max by (pop) (cdnfoundry_edge_gateway_active_revision{pop=~"$pop"})', "{{pop}}")], "none", decimals=0)],
    ]
    return dashboard("cdnf-pop-fleet", "PoP fleet", "cdnf-noc", "One row per PoP: health, heartbeat, applied revision, traffic, errors, latency, host load, and cache fill.", rows, runbook="stale-edge-heartbeat")


def pop_detail() -> dict:
    rows = [
        TRAFFIC_STATS(),
        row("Edges and cells"), [
            stat("Ready gateways", "Gateways that are ready in the selected PoPs.", [prom('sum(cdnfoundry_edge_gateway_ready{pop=~"$pop"})', "ready", instant=True)], "short", "neutral", w=6),
            stat("Active connections", "Connections the gateways hold now.", [prom('sum(cdnfoundry_edge_gateway_connections_active{pop=~"$pop"})', "connections", instant=True)], "short", "neutral", w=6),
            stat("Rejected connections", "Connections the gateways refused, per second.", [prom('sum(rate(cdnfoundry_edge_gateway_connections_rejected_total{pop=~"$pop"}[5m]))', "rejected", instant=True)], "reqps", "zero_warn", w=6, decimals=2),
            stat("Candidate rejections", "Routing maps the gateways refused in the last hour.", [prom('sum(increase(cdnfoundry_edge_gateway_candidate_rejections_total{pop=~"$pop"}[1h])) or vector(0)', "rejections", instant=True)], "short", "zero_warn", w=6)],
        [timeseries("Cell capacity", "How full each cell's cache, memory, and connection budget is.", [prom('cdnfoundry_cell_capacity_ratio{pop=~"$pop"}', "{{pop}} {{cell}} {{resource}}")], "percentunit", threshold="ratio_utilization", max_value=1),
         timeseries("Gateway connections", "Active gateway connections per PoP.", [prom('sum by (pop) (cdnfoundry_edge_gateway_connections_active{pop=~"$pop"})', "{{pop}}")], "short")],
        row("Host"), [
            timeseries("CPU", "Host CPU used.", [prom('100 * (1 - avg by (node) (rate(node_cpu_seconds_total{mode="idle",node=~"$pop"}[5m])))', "{{node}}")], "percent", w=8, max_value=100),
            timeseries("Memory", "Host memory used.", [prom('100 * (1 - node_memory_MemAvailable_bytes{node=~"$pop"} / node_memory_MemTotal_bytes{node=~"$pop"})', "{{node}}")], "percent", w=8, max_value=100),
            timeseries("Network", "Host network traffic in bits per second.", [prom('8 * sum by (node) (rate(node_network_transmit_bytes_total{node=~"$pop",device!~"lo|veth.*|br-.*|docker.*"}[5m]))', "{{node}} out")], "bps", w=8)],
        row("Logs"), [logs("PoP logs", "Operational logs from the selected PoPs.", [loki('{host=~"$pop", service=~".+"}')], h=10)],
    ]
    return dashboard("cdnf-pop-detail", "PoP detail", "cdnf-noc", "One PoP's edges, cells, gateway, cache, upstream connections, host resources, and logs.", rows, runbook="edge-or-cell-failure")


def origin_health() -> dict:
    rows = [
        [stat("Origin p95", "95th percentile origin response time.", [prom(ORIGIN_P95_MS, "p95", instant=True)], "ms", "latency_ms", w=6, decimals=0),
         stat("Origin failures", "Origin failures per second.", [prom(f"sum(cdnf:edge_origin_failures:rate5m{{{S}}})", "failures", instant=True)], "reqps", "zero_warn", w=6, decimals=3),
         stat("Failure ratio", "Share of origin requests that failed.", [prom(f'100 * sum(cdnf:edge_origin_failures:rate5m{{{S}}}) / sum({req(",cache_status!~\"HIT|NONE\"")})', "ratio", instant=True)], "percent", "error_percent", w=6, decimals=2),
         stat("Failover events (1h)", "Requests that moved to a backup origin in the last hour.", [ch("SELECT count() AS events FROM cdnf.edge_events WHERE occurred_at > now() - INTERVAL 1 HOUR AND origin_transition != 'none'", table=True)], "short", "zero_warn", w=6)],
        [timeseries("Origin latency percentiles", "Median, 95th, and 99th percentile origin response time.", [ch("SELECT time, q[1] AS p50, q[2] AS p95, q[3] AS p99 FROM (" + traffic_sql("quantilesTDigestMerge(0.5, 0.95, 0.99)(origin_latency_q) AS q", time=True) + ")")], "ms", w=12),
         timeseries("Origin failures", "Origin failures per second by PoP.", [prom(f"sum by (pop) (cdnf:edge_origin_failures:rate5m{{{S}}})", "{{pop}}")], "reqps", w=12)],
        [bargauge("Slowest origins", "Hostnames with the slowest origin responses.", [ch(top("hostname", CH_ORIGIN_P95, "p95", hostname=True), table=True)], "ms", "latency_ms", w=8),
         bargauge("Failing origins", "Hostnames with the most origin failures.", [ch(top("hostname", "sum(origin_errors)", "failures", hostname=True), table=True)], "short", "zero_warn", w=8),
         bargauge("Status codes at the edge", "Response codes returned to clients.", [ch(top("toString(status) AS code", CH_REQUESTS, "requests", limit=12), table=True)], "short", w=8)],
        [with_span(timeseries("Failover events", "Requests per minute that moved between primary and backup origin.", [ch("SELECT toStartOfInterval(occurred_at, INTERVAL 5 minute) AS time, origin_transition, count() AS events FROM cdnf.edge_events WHERE $__timeFilter(occurred_at) AND origin_transition != 'none' GROUP BY time, origin_transition ORDER BY time")], "short", w=24), "6h")],
    ]
    return dashboard("cdnf-origin-health", "Origin health", "cdnf-noc", "Origin latency percentiles, error codes, connect failures, failover events, and the slowest and failing origins.", rows, runbook="origin-failure")


def cache() -> dict:
    rows = [
        [stat("Hit ratio (requests)", "Share of requests served from cache.", [prom(HIT_PCT, "hit", instant=True)], "percent", "hit_percent", w=6, decimals=1),
         stat("Hit ratio (bytes)", "Share of bytes served from cache.", [ch(traffic_sql("100 * sumIf(bytes_sent, cache_status = 'HIT') / nullIf(sum(bytes_sent), 0) AS ratio"), table=True)], "percent", "hit_percent", w=6, decimals=1),
         stat("Egress saved", "Bytes sent from cache instead of fetched from an origin.", [ch(traffic_sql("sumIf(bytes_sent, cache_status = 'HIT') AS saved"), table=True)], "bytes", w=6),
         stat("Purge pending age", "Age of the oldest purge not yet applied on every PoP.", [prom("cdnfoundry_purge_pending_oldest_seconds", "age", instant=True)], "s", "age_seconds", w=6)],
        [timeseries("Cache status mix", "Requests per second by cache status.", [prom(f"sum by (cache_status) ({req()})", "{{cache_status}}")], "reqps", stack=True),
         timeseries("Hit ratio over time", "Requests served from cache, as a share of all requests.", [prom(HIT_PCT, "hit %")], "percent", threshold="hit_percent", max_value=100)],
        [timeseries("Cache fill by PoP", "How full the cache volume of each cell is.", [prom('max by (pop) (cdnfoundry_cell_capacity_ratio{resource="cache",pop=~"$pop"})', "{{pop}}")], "percentunit", threshold="ratio_utilization", max_value=1),
         with_span(timeseries("Purge volume", "Cache purge requests per hour over the last 7 days, whatever range the dashboard shows.", [pg("SELECT t AS time, count(c.id) AS purges FROM generate_series(date_trunc('hour', $__timeFrom()::timestamptz), $__timeTo()::timestamptz, interval '1 hour') AS t LEFT JOIN grafana_cache_purges c ON c.created_at >= t AND c.created_at < t + interval '1 hour' GROUP BY t ORDER BY t", table=False)], "short"), "7d")],
        [timeseries("Purge propagation time", "Longest time a purge took to apply on every PoP, over the last hour.", [prom("cdnfoundry_purge_propagation_seconds_max", "slowest purge")], "s"),
         timeseries("Cache fill by cell", "How full each cell's cache volume is. The cells do not count evictions, so a volume near full is the signal.", [prom('max by (pop, cell) (cdnfoundry_cell_capacity_ratio{resource="cache",pop=~"$pop"})', "{{pop}} {{cell}}")], "percentunit", threshold="ratio_utilization", max_value=1)],
    ]
    return dashboard("cdnf-cache", "Cache", "cdnf-noc", "Hit ratio by requests and by bytes, status mix, egress saved, cache fill, purge volume, and purge propagation time.", rows, runbook="cache-hit-collapse")


def edge_latency() -> dict:
    rows = [
        [stat("Edge p50", "Median time to serve a request.", [prom(P50_MS, "p50", instant=True)], "ms", "latency_ms", w=6, decimals=0),
         stat("Edge p95", "95th percentile time to serve a request.", [prom(P95_MS, "p95", instant=True)], "ms", "latency_ms", w=6, decimals=0),
         stat("Origin p95 vs edge p95", "The origin p95 divided by the edge p95. Above 1 means the origins are slower than the edge answers, which is expected when most requests are cache hits.", [prom(f"({ORIGIN_P95_MS}) / ({P95_MS})", "ratio", instant=True)], "suffix: x", "neutral", w=6, decimals=1),
         stat("TTFB p95", "95th percentile time for an origin to send its first byte.", [ch(traffic_sql(f"{CH_TTFB_P95} AS ttfb"), table=True)], "ms", "latency_ms", w=6, decimals=0)],
        [timeseries("Edge latency by PoP", "95th percentile edge time per PoP.", [ch(split("pop", CH_P95, "p95"))], "ms"),
         timeseries("Edge latency by protocol", "95th percentile edge time per HTTP version.", [ch(split("protocol", CH_P95, "p95"))], "ms")],
        [timeseries("Edge and origin time", "95th percentile edge time next to 95th percentile origin time.", [ch(traffic_sql(f"{CH_P95} AS edge_p95, {CH_ORIGIN_P95} AS origin_p95", time=True))], "ms"),
         bargauge("Slowest countries", "Countries with the highest 95th percentile edge time.", [ch(top("country", CH_P95, "p95"), table=True)], "ms", "latency_ms")],
    ]
    return dashboard("cdnf-edge-latency", "Edge latency", "cdnf-noc", "TTFB and total edge time by PoP, protocol, and country, with the origin share of each.", rows, runbook="edge-latency-burn")


def dns_authoritative() -> dict:
    rows = [
        [stat("Queries", "Authoritative queries per second.", [prom(DNS_QPS, "qps", instant=True)], "reqps", w=6, decimals=1),
         stat("SERVFAIL ratio", "Share of answers that are SERVFAIL.", [prom(f'100 * sum(cdnf:dns_queries:rate5m{{{SP},rcode="SERVFAIL"}}) / sum(cdnf:dns_queries:rate5m{{{SP}}})', "servfail", instant=True)], "percent", "error_percent", w=6, decimals=2),
         stat("Backends up", "DNSdist backends marked up.", [prom("sum(dnsdist_server_status)", "up", instant=True)], "short", "neutral", w=6),
         stat("Serial pending age", "Age of the oldest DNS deployment that has not reached its desired serial.", [prom("cdnfoundry_dns_serial_pending_oldest_seconds", "age", instant=True)], "s", "age_seconds", w=6)],
        [timeseries("Queries by response code", "Queries per second by response code.", [prom(f"sum by (rcode) (cdnf:dns_queries:rate5m{{{SP}}})", "{{rcode}}")], "reqps", stack=True),
         timeseries("Queries by PoP", "Queries per second by PoP.", [prom(f"sum by (pop) (cdnf:dns_queries:rate5m{{{SP}}})", "{{pop}}")], "reqps", stack=True)],
        [timeseries("DNSdist backend health", "1 when DNSdist sends queries to the backend.", [prom("dnsdist_server_status", "{{server}} {{instance}}")], "short", threshold="health"),
         timeseries("DNSdist latency", "Average backend response time reported by DNSdist.", [prom("dnsdist_latency_avg100", "{{instance}} last 100"), prom("dnsdist_latency_avg1000", "{{instance}} last 1000")], "µs")],
        [timeseries("Cache hits in DNSdist", "Packet-cache hits. DNSdist runs without a packet cache by design, so this stays at zero.", [prom("rate(dnsdist_cache_hits[5m])", "{{instance}}")], "ops", w=8),
         timeseries("DNS deployments pending", "Domains whose zone has not reached its desired revision on every DNS target.", [prom("cdnfoundry_dns_deployments_drifted", "drifted")], "short", w=8),
         timeseries("Answers by region", "Geo-DNS answers by region of the answering PoP.", [prom(f"sum by (region) (cdnf:dns_queries:rate5m{{{SP}}})", "{{region}}")], "reqps", w=8, stack=True)],
    ]
    return dashboard("cdnf-dns-authoritative", "DNS authoritative", "cdnf-noc", "QPS, response codes, latency, DNSdist backend health, serial propagation, and Geo-DNS answers by region.", rows, runbook="dns-cluster-failure")


def synthetic_probes() -> dict:
    rows = [
        [stat("Probes up", "Probes that succeeded in their last run.", [prom('sum(probe_success{job=~"blackbox-.*"})', "up", instant=True)], "short", "neutral", w=6),
         stat("Probes down", "Probes that failed in their last run.", [prom('count(probe_success{job=~"blackbox-.*"} == 0) or vector(0)', "down", instant=True)], "short", "zero_good", w=6),
         stat("Slowest probe", "Longest probe duration.", [prom('max(probe_duration_seconds{job=~"blackbox-.*"})', "slowest", instant=True)], "s", "latency_s", w=6, decimals=2),
         stat("Soonest certificate", "Days until the earliest certificate a probe saw expires.", [prom('min(probe_ssl_earliest_cert_expiry - time()) / 86400', "days", instant=True)], "d", "neutral", w=6, decimals=0)],
        [probe_table(24, 8)],
        [timeseries("Probe success by PoP", "Share of probes that succeeded, per PoP and IP version.", [prom('avg by (pop, ip_version) (probe_success{job=~"blackbox-.*"})', "{{pop}} IPv{{ip_version}}")], "percentunit", threshold="health", min_value=0, max_value=1),
         timeseries("Probe duration by PoP", "Time a probe takes, per PoP and IP version.", [prom('max by (pop, ip_version) (probe_duration_seconds{job=~"blackbox-.*"})', "{{pop}} IPv{{ip_version}}")], "s", threshold="latency_s")],
    ]
    return dashboard("cdnf-synthetic-probes", "Synthetic probes", "cdnf-noc", "Each probe's result, latency, and certificate expiry, per PoP and per IP version.", rows, runbook="synthetic-probe-failing")


def propagation() -> dict:
    rows = [
        [stat("Oldest pending purge", "Age of the oldest purge not yet applied on every PoP.", [prom("cdnfoundry_purge_pending_oldest_seconds", "purge", instant=True)], "s", "age_seconds", w=6),
         stat("Oldest config", "Age of the oldest published edge artifact a PoP has not applied.", [prom("cdnfoundry_config_pending_oldest_seconds", "config", instant=True)], "s", "age_seconds", w=6),
         stat("Slowest purge (1h)", "Longest request-to-applied time of a purge in the last hour.", [prom("cdnfoundry_purge_propagation_seconds_max", "slowest", instant=True)], "s", "age_seconds", w=6),
         stat("Oldest DNS serial", "Age of the oldest DNS deployment that has not reached its serial.", [prom("cdnfoundry_dns_serial_pending_oldest_seconds", "dns", instant=True)], "s", "age_seconds", w=6)],
        [timeseries("Purge propagation", "Age of the oldest pending purge and the slowest finished purge.", [prom("cdnfoundry_purge_pending_oldest_seconds", "oldest pending"), prom("cdnfoundry_purge_propagation_seconds_max", "slowest in the last hour")], "s", threshold="age_seconds"),
         timeseries("Config propagation", "Age of the oldest published artifact a PoP has not applied.", [prom("cdnfoundry_config_pending_oldest_seconds", "oldest unapplied")], "s", threshold="age_seconds")],
        [table("Slowest PoP", "Edges that have not applied the newest published artifact, with how many artifacts each is behind.", [pg(
            "SELECT e.name AS pop, (SELECT max(sequence) FROM grafana_edge_artifacts a WHERE a.edge_id = e.id) - e.active_sequence AS artifacts_behind, "
            "COALESCE(extract(epoch FROM now() - (SELECT min(created_at) FROM grafana_edge_artifacts a WHERE a.edge_id = e.id AND a.sequence > e.active_sequence)), 0)::int AS oldest_unapplied_seconds "
            "FROM grafana_edges e WHERE e.enabled = true ORDER BY oldest_unapplied_seconds DESC, artifacts_behind DESC LIMIT 50")], w=24, h=8,
            overrides=[{"matcher": {"id": "byName", "options": "oldest_unapplied_seconds"}, "properties": [{"id": "unit", "value": "s"}]}])],
    ]
    return dashboard("cdnf-propagation", "Config & purge propagation", "cdnf-noc", "Artifact publish to applied, per PoP, with the slowest PoP named.", rows, runbook="propagation-burn")


DASHBOARDS = [
    ("00-overview", now_dashboard), ("10-noc", noc_wall), ("10-noc", global_traffic), ("10-noc", pop_fleet), ("10-noc", pop_detail),
    ("10-noc", origin_health), ("10-noc", cache), ("10-noc", edge_latency), ("10-noc", dns_authoritative), ("10-noc", synthetic_probes), ("10-noc", propagation),
]
