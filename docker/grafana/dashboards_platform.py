"""Platform admin, on-call, domain, business, and sample dashboards."""
from __future__ import annotations

from dashboard_lib import *  # noqa: F403
from dashboards_common import *  # noqa: F403
from dashboards_noc import ALERT_TABLE_EXPR, ALERT_TRANSFORMS, firing_alerts

SLOS = {
    "edge_availability": "Edge availability", "edge_5xx": "Edge 5xx ratio", "edge_latency": "Edge latency", "purge_propagation": "Purge propagation",
    "config_propagation": "Config propagation", "dns_answer": "DNS answer success", "telemetry_delivery": "Telemetry delivery",
}


def last_30_days(panel: dict) -> dict:
    return over(panel, "30d")


def label_by(panel: dict, label: str) -> dict:
    """Name each series by one label, not by the measure and the label together."""
    panel["fieldConfig"]["defaults"]["displayName"] = "${__field.labels.%s}" % label
    return panel


def first_to_run_out(panel: dict) -> dict:
    panel["fieldConfig"]["defaults"]["mappings"].append({"type": "range", "options": {"from": 365, "to": 1e9, "result": {"text": "Not within a year"}}})
    return panel


def over(panel: dict, span: str) -> dict:
    """A panel that always shows `span`, whatever range the dashboard shows: monthly and daily series have no point inside six hours."""
    panel["timeFrom"] = span
    return panel


def slo_expr(kind: str) -> str:
    # One series per SLO and nothing else, so tables can join the measures on the SLO name.
    return f'max by (slo) (label_replace({{__name__=~"slo:.*:{kind}"}}, "slo", "$1", "__name__", "slo:(.*):{kind}"))'


def control_plane() -> dict:
    health = table("Component health", "Every control-plane component with its state. A red row names what to open first.", [prom("cdnfoundry_component_health", "health", instant=True, fmt="table")], w=12, h=10, sort="Value",
                   transformations=[{"id": "organize", "options": {"excludeByName": {"Time": True, "__name__": True, "job": True, "instance": True, "node": True, "role": True}, "renameByName": {"component": "Component", "status": "State", "Value": "Healthy"}}}],
                   overrides=[{"matcher": {"id": "byName", "options": "Healthy"}, "properties": [{"id": "thresholds", "value": {"mode": "absolute", "steps": THRESHOLDS["health"]}}, {"id": "custom.cellOptions", "value": {"type": "color-background"}}]}])
    slow = table("Slowest routes", "The ten routes with the highest 95th percentile time.", [prom("topk(10, histogram_quantile(0.95, sum by (le, route) (cdnf:http_latency_bucket:rate5m)))", "p95", instant=True, fmt="table")], "s", w=12, h=10, sort="Value",
                 transformations=[{"id": "organize", "options": {"excludeByName": {"Time": True}, "renameByName": {"route": "Route", "Value": "p95"}}}],
                 overrides=[{"matcher": {"id": "byName", "options": "p95"}, "properties": [{"id": "unit", "value": "s"}, {"id": "decimals", "value": 0}]}])
    rows = [
        [stat("Unhealthy", "Components reporting degraded or unavailable.", [prom("count(cdnfoundry_component_health == 0) or vector(0)", "unhealthy", instant=True)], "short", "zero_good", w=4),
         stat("Failed ops", "Operations that failed in the failed-operations window.", [prom("cdnfoundry_operations_failed_recent", "failed", instant=True)], "short", "zero_warn", w=4),
         stat("Bad jobs", "Queue jobs that failed in the same window.", [prom("cdnfoundry_failed_jobs_recent", "jobs", instant=True)], "short", "zero_warn", w=4),
         stat("API p95", "95th percentile API request time over the last 5 minutes.", [prom('histogram_quantile(0.95, sum by (le) (cdnf:http_latency_bucket:rate5m{route=~"/api/.*"}))', "p95", instant=True)], "s", "latency_s", w=4, decimals=3, idle="No API requests"),
         stat("Panel p95", "95th percentile panel request time.", [prom('histogram_quantile(0.95, sum by (le) (cdnf:http_latency_bucket:rate5m{route!~"/api/.*"}))', "p95", instant=True)], "s", "latency_s", w=4, decimals=0),
         stat("ACME fail", "Managed certificate orders that failed in the last 24 hours.", [prom('cdnfoundry_acme_orders_day{status="failed"}', "failed", instant=True)], "short", "zero_warn", w=4)],
        [health, slow],
        [timeseries("API and panel latency", "95th percentile request time for API routes and for panel routes.", [prom('histogram_quantile(0.95, sum by (le) (cdnf:http_latency_bucket:rate5m{route=~"/api/.*"}))', "API p95"), prom('histogram_quantile(0.95, sum by (le) (cdnf:http_latency_bucket:rate5m{route!~"/api/.*"}))', "panel p95")], "s", threshold="latency_s"),
         timeseries("Requests by status class", "Control-plane requests per second.", [prom("sum by (status_class) (cdnf:http_requests:rate5m)", "{{status_class}}")], "reqps", stack=True)],
        [timeseries("Horizon queue depth", "Jobs waiting in each worker lane.", [prom("cdnfoundry_queue_depth", "{{queue}}")], "short"),
         timeseries("Horizon oldest job age", "Age of the oldest waiting job in each lane.", [prom("cdnfoundry_queue_oldest_job_age_seconds", "{{queue}}")], "s", threshold="age_seconds")],
        [timeseries("Scheduler", "1 while the scheduler's durable heartbeat is fresh.", [prom('cdnfoundry_component_health{component="scheduler"}', "scheduler")], "short", threshold="health", w=8, min_value=0, max_value=1),
         timeseries("ACME orders", "Managed certificate orders that finished in the last 24 hours.", [prom("cdnfoundry_acme_orders_day", "{{status}}")], "short", w=8),
         timeseries("ACME issuance time", "Longest managed certificate issuance in the last 24 hours.", [prom("cdnfoundry_acme_issuance_seconds_max", "slowest")], "s", w=8)],
        [timeseries("Alert deliveries", "Alerts Alertmanager sent to the notifier and receivers each second, and the ones that failed. Platform notification channels (webhooks, Slack, and so on) report in the panel under Notification channels.",
                    [prom("sum by (integration) (rate(alertmanager_notifications_total[5m])) > 0", "sent {{integration}}"), prom("sum by (integration) (rate(alertmanager_notifications_failed_total[5m])) > 0", "failed {{integration}}")], "ops", threshold="zero_warn", w=24)],
    ]
    return dashboard("cdnf-control-plane", "Control plane", "cdnf-platform", "Component health, API and panel latency, Horizon lanes, scheduler runs, failed jobs, and ACME orders.", rows, runbook="component-unhealthy")


def fleet_rollouts() -> dict:
    rows = [
        [stat("Enabled edges", "PoPs that are enabled.", [pg("SELECT count(*) AS edges FROM grafana_edges WHERE enabled")], "short", w=6),
         stat("Cells ready", "Cells that report ready.", [pg("SELECT count(*) AS cells FROM grafana_edge_cells WHERE status = 'ready'")], "short", w=6),
         stat("Placements settling", "Placements that are not yet in their final state.", [pg("SELECT count(*) AS settling FROM grafana_placements WHERE state NOT IN ('active', 'idle', 'pending')")], "short", "zero_warn", w=6),
         stat("Paused", "Fleet rollouts paused on a safety threshold.", [prom("cdnfoundry_fleet_rollouts_paused", "paused", instant=True)], "short", "zero_warn", w=6)],
        [bargauge("Fleet rollouts by state", "Rollouts by state. A paused rollout stopped on a safety threshold.", [pg("SELECT s AS state, count(r.id) AS rollouts FROM unnest(ARRAY['pending', 'running', 'paused', 'succeeded', 'failed']) AS s LEFT JOIN grafana_fleet_rollouts r ON r.status = s GROUP BY s ORDER BY s")], "short", w=16),
         piechart("Placements by state", "Where domains are in moving between pools.", [pg("SELECT state, count(*) AS placements FROM grafana_placements GROUP BY state")], w=8)],
        [bargauge("Edge tasks by status", "Tasks sent to PoPs in the last 24 hours, by state.", [pg("SELECT s AS status, count(t.id) AS tasks FROM unnest(ARRAY['pending', 'running', 'succeeded', 'failed']) AS s LEFT JOIN grafana_edge_tasks t ON t.status = s AND t.created_at > now() - interval '24 hours' GROUP BY s ORDER BY s")], w=8),
         timeseries("Pool endpoints published", "Pool endpoints DNS publishes (1) and withholds (0).", [prom("sum by (reason) (cdnfoundry_pool_endpoint_published)", "{{reason}}")], "short", w=8),
         timeseries("Endpoint revision mismatches", "Endpoints that have not acknowledged their desired map.", [prom("sum(cdnfoundry_pool_endpoint_revision_mismatch)", "mismatched")], "short", threshold="zero_warn", w=8)],
        [table("Edges", "Every PoP with its heartbeat, version, and applied sequence.", [pg("SELECT name AS pop, country_code, enabled, drained, last_heartbeat_at, agent_version, active_sequence FROM grafana_edges ORDER BY name LIMIT 100")], w=24,
               overrides=[{"matcher": {"id": "byName", "options": "country_code"}, "properties": [{"id": "custom.width", "value": 110}]},
                          {"matcher": {"id": "byName", "options": "enabled"}, "properties": [{"id": "custom.width", "value": 80}]},
                          {"matcher": {"id": "byName", "options": "drained"}, "properties": [{"id": "custom.width", "value": 80}]},
                          {"matcher": {"id": "byName", "options": "agent_version"}, "properties": [{"id": "custom.width", "value": 110}]},
                          {"matcher": {"id": "byName", "options": "last_heartbeat_at"}, "properties": [{"id": "custom.width", "value": 200}]},
                          {"matcher": {"id": "byName", "options": "active_sequence"}, "properties": [{"id": "unit", "value": "none"}, {"id": "decimals", "value": 0}]}])],
    ]
    return dashboard("cdnf-fleet-rollouts", "Fleet and rollouts", "cdnf-platform", "Edges, cells, placements, pool endpoints, FleetRollout progress and pauses, and edge tasks.", rows, runbook="paused-fleet-rollout")


def data_stores() -> dict:
    rows = [
        [stat("Control database size", "Size of the control database.", [pg("SELECT pg_database_size(current_database()) AS size")], "bytes", w=6),
         stat("Connections", "Sessions connected to the control database.", [pg("SELECT count(*) AS connections FROM pg_stat_activity WHERE datname = current_database()")], "short", w=6),
         stat("Replication lag", "How far the standby is behind the active control database. Shown only with a standby.", [prom("max(cdnfoundry_control_replication_lag_seconds) or vector(0)", "lag", instant=True)], "s", "age_seconds", w=6),
         stat("Parts per partition", "Most data parts in one ClickHouse partition. High numbers slow queries.", [prom("max(ClickHouseAsyncMetrics_MaxPartCountForPartition)", "parts", instant=True)], "short", "utilization", w=6)],
        [timeseries("ClickHouse insert rate", "Rows inserted per second.", [prom("rate(ClickHouseProfileEvents_InsertedRows[5m])", "rows")], "ops"),
         timeseries("ClickHouse merges and queries", "Background merges and running queries.", [prom("ClickHouseMetrics_BackgroundMergesAndMutationsPoolTask", "merges"), prom("ClickHouseMetrics_Query", "queries")], "short")],
        [timeseries("ClickHouse disk used", "Share of the data disk ClickHouse uses.", [prom("100 * ClickHouseAsyncMetrics_DiskUsed / ClickHouseAsyncMetrics_DiskTotal", "disk")], "percent", threshold="utilization", max_value=100),
         timeseries("ClickHouse parts", "Most data parts in one partition over time.", [prom("ClickHouseAsyncMetrics_MaxPartCountForPartition", "parts")], "short")],
        [timeseries("Loki ingest", "Bytes per second Loki receives.", [prom("sum(rate(loki_distributor_bytes_received_total[5m]))", "ingest")], "Bps"),
         timeseries("Loki retention run", "Seconds since the Loki compactor last applied retention.", [prom("time() - loki_compactor_apply_retention_last_successful_run_timestamp_seconds > 0", "since last run")], "s", threshold="age_seconds")],
        [text("Valkey and PostgreSQL internals", "Valkey memory, evictions, and hit ratio, and PostgreSQL locks and slow queries, need exporters that are not deployed yet. Queue depth and age are on the Control plane dashboard, and database health is a component there.", "Valkey memory, evictions, and hit ratio, and PostgreSQL locks and slow queries need exporters that are not deployed yet. Queue depth and age are on **Control plane**, and database health is a component there.", w=24, h=3)],
    ]
    return dashboard("cdnf-data-stores", "Data stores", "cdnf-platform", "PostgreSQL, ClickHouse, and Loki health: connections, size, insert rate, parts, merges, disk, ingest, and retention.", rows, runbook="clickhouse-or-vector-outage")


def telemetry_pipeline() -> dict:
    rows = [
        [stat("Events delivered", "Events per second Vector delivers to ClickHouse.", [prom('sum(rate(vector_component_sent_events_total{component_id=~"clickhouse_.*"}[5m]))', "delivered", instant=True)], "ops", w=6, decimals=1),
         stat("Dropped (5m)", "Events dropped by a full buffer in the last 5 minutes.", [prom('sum(increase(vector_component_discarded_events_total{intentional!="true"}[5m])) or vector(0)', "dropped", instant=True)], "short", "zero_warn", w=6),
         stat("Sink errors (5m)", "Delivery errors in the last 5 minutes.", [prom("sum(increase(vector_component_errors_total[5m])) or vector(0)", "errors", instant=True)], "short", "zero_warn", w=6),
         stat("Slowest PoP delay", "Largest delay between an event and its arrival in ClickHouse, over PoPs.", [ch("SELECT max(delay) AS delay FROM (SELECT pop, dateDiff('second', max(occurred_at), now()) AS delay FROM cdnf.edge_events WHERE occurred_at > now() - INTERVAL 1 DAY GROUP BY pop)", table=True)], "s", "age_seconds", w=6)],
        [timeseries("Events sent by collector", "Events per second each Vector component delivered.", [prom('sum by (instance, component_id) (rate(vector_component_sent_events_total{component_id=~"clickhouse_.*"}[5m]))', "{{instance}} {{component_id}}")], "ops"),
         timeseries("Buffer size", "Bytes waiting in each disk buffer.", [prom("sum by (instance, component_id) (vector_buffer_size_bytes)", "{{instance}} {{component_id}}")], "bytes")],
        [timeseries("Dropped events", "Events dropped per second by component.", [prom('sum by (component_id) (rate(vector_component_discarded_events_total{intentional!="true"}[5m]))', "{{component_id}}")], "ops", threshold="zero_warn"),
         timeseries("Sink errors", "Delivery errors per second by component.", [prom("sum by (component_id) (rate(vector_component_errors_total[5m]))", "{{component_id}}")], "ops", threshold="zero_warn")],
        [table("Ingest delay by PoP", "Seconds between now and the newest event each PoP delivered.", [ch("SELECT pop, dateDiff('second', max(occurred_at), now()) AS delay_seconds FROM cdnf.edge_events WHERE occurred_at > now() - INTERVAL 1 DAY GROUP BY pop ORDER BY delay_seconds DESC", table=True)], w=24, h=6,
               overrides=[{"matcher": {"id": "byName", "options": "delay_seconds"}, "properties": [{"id": "unit", "value": "s"}, {"id": "decimals", "value": 0}]}])],
    ]
    return dashboard("cdnf-telemetry-pipeline", "Telemetry pipeline", "cdnf-platform", "Vector per role, buffers, drops, sink errors, and ingest delay from edge to ClickHouse.", rows, runbook="clickhouse-or-vector-outage")


def backups() -> dict:
    rows = [
        [stat("Last backup age", "Time since the newest verified backup.", [pg("SELECT COALESCE(extract(epoch FROM now() - max(verified_at)), 0)::bigint AS age FROM grafana_backups WHERE status = 'succeeded'")], "s", "age_seconds", w=6),
         stat("Last backup size", "Size of the newest verified backup.", [pg("SELECT size_bytes AS size FROM grafana_backups WHERE status = 'succeeded' ORDER BY verified_at DESC LIMIT 1")], "bytes", w=6),
         stat("Backups healthy", "1 when the backup component reports healthy: a fresh backup, a passing restic check, and a passing restore preflight.", [prom('cdnfoundry_component_health{component="backups"}', "healthy", instant=True)], "short", "health", w=6),
         stat("Failed backups (7d)", "Backups that failed in the last 7 days.", [pg("SELECT count(*) AS failed FROM grafana_backups WHERE status = 'failed' AND created_at > now() - interval '7 days'")], "short", "zero_warn", w=6)],
        [last_30_days(timeseries("Backup size", "Size of each verified backup over the last 30 days, whatever range the dashboard shows, because backups run once a day.", [pg("SELECT t AS time, COALESCE(max(b.size_bytes), 0) AS size FROM generate_series(date_trunc('day', $__timeFrom()::timestamptz), $__timeTo()::timestamptz, interval '1 day') AS t LEFT JOIN grafana_backups b ON b.status = 'succeeded' AND b.verified_at >= t AND b.verified_at < t + interval '1 day' GROUP BY t ORDER BY t", table=False)], "bytes")),
         table("Recent backups", "The newest backups with their result.", [pg("SELECT created_at, status, size_bytes, verified_at FROM grafana_backups ORDER BY created_at DESC LIMIT 20")],
               overrides=[{"matcher": {"id": "byName", "options": "size_bytes"}, "properties": [{"id": "unit", "value": "bytes"}, {"id": "decimals", "value": 1}, {"id": "custom.width", "value": 100}]},
                          {"matcher": {"id": "byName", "options": "status"}, "properties": [{"id": "custom.width", "value": 100}]}])],
    ]
    return dashboard("cdnf-backups", "Backups and recovery", "cdnf-platform", "Last backup age, size, restic check result, and last restore preflight.", rows, runbook="backup-stale")


def hosts_containers() -> dict:
    n = 'node=~".*"'
    rows = [
        [stat("Hosts up", "Hosts whose exporter answers.", [prom('count(up{job="node"} == 1)', "up", instant=True)], "short", w=6),
         stat("Hosts down", "Hosts whose exporter does not answer.", [prom('count(up{job="node"} == 0) or vector(0)', "down", instant=True)], "short", "zero_good", w=6),
         stat("OOM kills (24h)", "Processes the kernel killed for memory in the last day.", [prom("sum(increase(node_vmstat_oom_kill[24h])) or vector(0)", "oom", instant=True)], "short", "zero_warn", w=6),
         stat("Clock offset", "Largest clock offset on any host.", [prom("max(abs(node_timex_offset_seconds))", "offset", instant=True)], "s", "latency_s", w=6, decimals=1)],
        [timeseries("CPU", "Share of CPU in use per host.", [prom('100 * (1 - avg by (node) (rate(node_cpu_seconds_total{mode="idle"}[5m])))', "{{node}}")], "percent", max_value=100),
         timeseries("Memory", "Share of memory in use per host.", [prom("100 * (1 - node_memory_MemAvailable_bytes / node_memory_MemTotal_bytes)", "{{node}}")], "percent", max_value=100)],
        [timeseries("Disk", "Share of the root filesystem in use per host.", [prom('100 * (1 - node_filesystem_avail_bytes{mountpoint="/",fstype!~"tmpfs|overlay"} / node_filesystem_size_bytes{mountpoint="/",fstype!~"tmpfs|overlay"})', "{{node}}")], "percent", threshold="utilization", max_value=100),
         timeseries("Network", "Bits per second sent and received per host.", [prom('8 * sum by (node) (rate(node_network_transmit_bytes_total{device!~"lo|veth.*|br-.*|docker.*"}[5m]))', "{{node}} out"), prom('8 * sum by (node) (rate(node_network_receive_bytes_total{device!~"lo|veth.*|br-.*|docker.*"}[5m]))', "{{node}} in")], "bps")],
        [timeseries("Clock offset by host", "Clock offset per host.", [prom("node_timex_offset_seconds", "{{node}}")], "s", w=8),
         timeseries("Uptime", "Time since each host booted; a drop is a restart.", [prom("time() - node_boot_time_seconds", "{{node}}")], "s", w=8),
         timeseries("OOM kills", "Processes the kernel killed for memory, per hour.", [prom("sum by (node) (increase(node_vmstat_oom_kill[1h]))", "{{node}}")], "short", threshold="zero_warn", w=8, decimals=0)],
        [text("Containers", "Per-container CPU, memory, and restarts need a container metrics exporter that is not deployed yet.", "Per-container CPU, memory, and restarts need a container metrics exporter that is not deployed yet. Host figures above cover each node.", w=24, h=3)],
    ]
    return dashboard("cdnf-hosts-containers", "Hosts and containers", "cdnf-platform", "CPU, memory, disk, network, clock, restarts, and OOM kills per host.", rows, runbook="host-clock")


# Each daily check: its name, a PromQL expression that is 1 when it passes and 0 when it fails, what it checks, and what to do
# when it fails. A check whose metric is not reported at all shows "Not reported", never "Fail".
CHECKS = [
    ("PoPs hold the latest artifact", "(max(cdnfoundry_edge_gateway_active_revision) - min(cdnfoundry_edge_gateway_active_revision) <= bool 5)",
     "Every edge gateway runs a configuration revision within 5 of the newest one.", "Open Fleet and rollouts and find the edge that is behind; check its agent log."),
    ("DNS serials match desired", "(cdnfoundry_dns_deployments_drifted == bool 0)",
     "No DNS cluster serves an older zone than the control plane wants.", "Open DNS authoritative; reconcile the drifted zones from their domains."),
    ("No pool held on its last answers", "(sum(cdnfoundry_pool_dns_fail_static) == bool 0)",
     "No pool is in fail-static, where DNS keeps the last answers because no edge looks healthy.", "Open PoP fleet; bring an edge of that pool back to ready."),
    ("No edge identity near expiry", "(min(cdnfoundry_edge_identity_expires_seconds) > bool 30 * 86400)",
     "Every edge client certificate is valid for more than 30 days.", "Rotate the identity of the edge named in Fleet and rollouts."),
    ("No placement stuck deploying", 'cdnfoundry_component_health{component="edge_placements"}',
     "No domain has waited too long for a new pool or cell to become ready.", "Open the Operations screen in the admin panel and read the failing placement."),
    ("Queue latency under bound", "(max(cdnfoundry_queue_oldest_job_age_seconds) < bool 900)",
     "The oldest waiting job in every queue is younger than 15 minutes.", "Open Control plane; scale the busy lane or restart its worker."),
    ("Watchdog delivered within the hour", '(sum(increase(alertmanager_notifications_total{integration="webhook"}[1h])) > bool 0)',
     "Alertmanager delivered its always-firing watchdog in the last hour, so alerts can reach people.", "Check the notifier container and the alert receiver in Platform settings."),
    ("No certificate near expiry unrenewed", "(cdnfoundry_tls_certificates_expiring == bool 0)",
     "No active certificate is close to expiry without a renewal in progress.", "Open TLS posture; renew or reissue the certificate named there."),
    ("Backup fresh and checks pass", 'cdnfoundry_component_health{component="backups"}',
     "The last backup is recent enough and its restore check passed.", "Open Backups; run a backup with Fleet and read why the last one failed."),
    ("Vector drops nothing, ClickHouse accepts", '(sum(increase(vector_component_discarded_events_total{intentional!="true"}[1h])) == bool 0) * (sum(increase(vector_component_errors_total{component_id=~"clickhouse_.*"}[1h])) == bool 0)',
     "In the last hour Vector dropped no event by accident and ClickHouse refused no write.", "Open Telemetry pipeline; check ClickHouse disk and Vector buffers."),
    ("Control HA state not degraded", '(cdnfoundry_control_ha_state{state="degraded"} == bool 0)',
     "The two-node control plane is not in its degraded state.", "Run the Fleet control status command and follow the control HA runbook."),
    ("Valkey linked, secrets match", "(cdnfoundry_control_valkey_replica_linked == bool 1) * (cdnfoundry_control_fingerprint_mismatch == bool 0)",
     "The standby Valkey follows the primary and both control nodes hold the same secrets.", "Re-run the control HA setup step for the standby node."),
    ("PoPs hold the control addresses", "(cdnfoundry_control_edges_missing_addresses == bool 0)",
     "Every edge knows the addresses of both control nodes.", "Push the control addresses again from Fleet."),
    ("Services run on the primary node", 'cdnfoundry_component_health{component="control_ha"}',
     "The control services run on the node that holds the primary role.", "Follow the control HA runbook to fence and fail over."),
    ("Standby watched", '(min(up{job="node",role="control"}) == bool 1)',
     "Prometheus reaches the node exporter of every control node.", "Check the standby host and its node exporter."),
]

# The state of each check: 2 pass, 0 fail, 1 not reported. min() drops the metric labels, so each check is exactly one row.
STATE_PASS, STATE_UNKNOWN, STATE_FAIL = 2, 1, 0


def _check_state(name: str, expr: str, about: str, todo: str) -> str:
    labels = lambda inner: (f'label_replace(label_replace(label_replace({inner}, "check", "{name}", "", ""), '
                            f'"about", "{about}", "", ""), "todo", "{todo}", "", "")')
    return f"({labels(f'min({expr}) * 2')} or {labels('vector(1)')})"


ALL_CHECKS = " or ".join(_check_state(*check) for check in CHECKS)


def daily_checks() -> dict:
    count = lambda state: f"count(({ALL_CHECKS}) == {state}) or vector(0)"
    failing = stat("Failing", "Checks that fail now. Each one is listed first in the table below with what to do.", [prom(count(STATE_FAIL), "failing", instant=True)], "short", "zero_good", w=8, h=4)
    unknown = stat("Not reported", "Checks whose metric is not reported, usually because the part is not installed (a second control node) or has not reported yet.", [prom(count(STATE_UNKNOWN), "not reported", instant=True)], "short", "neutral", w=8, h=4)
    passing = stat("Passing", "Checks that pass now.", [prom(count(STATE_PASS), "passing", instant=True)], "short", "neutral", w=8, h=4)
    checks = table("Daily checks", "Every daily check, failures first: its state, what it checks, and what to do when it fails.",
                   [prom(ALL_CHECKS, "{{check}}", instant=True, fmt="table")], w=24, h=30,
                   transformations=[
                       {"id": "organize", "options": {"excludeByName": {"Time": True}, "indexByName": {"Value": 0, "check": 1, "about": 2, "todo": 3},
                                                      "renameByName": {"Value": "State", "check": "Check", "about": "What it checks", "todo": "When it fails"}}},
                       {"id": "sortBy", "options": {"sort": [{"field": "State", "desc": False}]}}],
                   overrides=[{"matcher": {"id": "byName", "options": "State"}, "properties": [
                       {"id": "mappings", "value": [{"type": "value", "options": {
                           str(STATE_FAIL): {"text": "Fail", "color": "red", "index": 0},
                           str(STATE_UNKNOWN): {"text": "Not reported", "color": "text", "index": 1},
                           str(STATE_PASS): {"text": "Pass", "color": "green", "index": 2}}}]},
                       {"id": "custom.cellOptions", "value": {"type": "color-text"}}, {"id": "custom.width", "value": 130}, {"id": "custom.align", "value": "left"}]},
                              {"matcher": {"id": "byName", "options": "Check"}, "properties": [{"id": "custom.width", "value": 300}]},
                              # The explanations wrap instead of being cut off.
                              {"matcher": {"id": "byRegexp", "options": "^(What it checks|When it fails)$"}, "properties": [{"id": "custom.cellOptions", "value": {"type": "auto", "wrapText": True}}]}])
    rows = [[failing, unknown, passing], [checks]]
    return dashboard("cdnf-daily-checks", "Daily checks", "cdnf-platform", "Every daily check the platform makes visible: which fail, which are not reported, and what to do.", rows, runbook="component-unhealthy")


def slos() -> dict:
    status = table("SLO status", "Each SLO with its target, the bad-event ratio over 30 days, the error budget left, and the current burn rate.", [
        prom(slo_expr("objective"), "Target", instant=True, fmt="table"), prom(slo_expr("bad_ratio_30d"), "Bad", instant=True, fmt="table"),
        prom(slo_expr("budget_remaining"), "Budget", instant=True, fmt="table"),
        prom(f'{slo_expr("bad_ratio_rate1h")} / on(slo) {slo_expr("error_budget")}', "Burn", instant=True, fmt="table")], w=24, h=9, sort="Budget",
        transformations=[{"id": "merge", "options": {}}, {"id": "filterFieldsByName", "options": {"include": {"names": ["slo", "Value #A", "Value #B", "Value #C", "Value #D"]}}}, {"id": "organize", "options": {"excludeByName": {"Time": True}, "indexByName": {"SLO": 0, "Target": 1, "Bad ratio (30d)": 2, "Budget left": 3, "Burn rate (1h)": 4}, "renameByName": {"slo": "SLO", "Value #A": "Target", "Value #B": "Bad ratio (30d)", "Value #C": "Budget left", "Value #D": "Burn rate (1h)"}}}],
        overrides=[{"matcher": {"id": "byName", "options": "Target"}, "properties": [{"id": "unit", "value": "percentunit"}, {"id": "decimals", "value": 2}]},
                   {"matcher": {"id": "byName", "options": "Bad ratio (30d)"}, "properties": [{"id": "unit", "value": "percentunit"}, {"id": "decimals", "value": 3}]},
                   {"matcher": {"id": "byName", "options": "Budget left"}, "properties": [{"id": "unit", "value": "percentunit"}, {"id": "thresholds", "value": {"mode": "absolute", "steps": THRESHOLDS["budget"]}}, {"id": "custom.cellOptions", "value": {"type": "color-background"}}]},
                   {"matcher": {"id": "byName", "options": "Burn rate (1h)"}, "properties": [{"id": "decimals", "value": 1}]}])
    rows = [
        [status],
        [bargauge("Error budget remaining", "Share of each SLO's 30-day error budget that is left.", [prom(slo_expr("budget_remaining"), "{{slo}}", instant=True)], "percentunit", "budget", w=12, max_value=1),
         timeseries("Burn rate", "Hours of budget burned per hour, by SLO. Above 1 uses the budget faster than it accrues.", [prom(f'{slo_expr("bad_ratio_rate1h")} / on(slo) {slo_expr("error_budget")}', "{{slo}}")], "short", w=12)],
        [firing_alerts(24, 7, "Burn-rate alerts")],
    ]
    return dashboard("cdnf-slos", "SLOs", "cdnf-oncall", "Each SLO with its target, current value, remaining error budget, and burn rate.", rows, runbook="edge-availability-burn", extra_variables=[
        {"name": "slo", "label": "SLO", "type": "custom", "query": ",".join(SLOS), "current": {"text": "edge_5xx", "value": "edge_5xx"}, "hide": 2, "options": [], "skipUrlSync": False}])


def incident() -> dict:
    alert_var = {"name": "alertname", "label": "Alert", "type": "query", "datasource": PROM, "query": {"query": 'label_values(ALERTS{alertname!="Watchdog"}, alertname)', "refId": "alertname"}, "definition": 'label_values(ALERTS{alertname!="Watchdog"}, alertname)',
                 "includeAll": True, "multi": True, "allValue": ".*", "current": {"selected": True, "text": ["All"], "value": ["$__all"]}, "refresh": 2, "hide": 0, "options": [], "sort": 1}
    rows = [
        [firing_alerts(24, 7, "Alerts in the selected range")],
        [timeseries("Alert state", "Firing alerts over the selected range. Pick the range around the incident.", [prom('count by (alertname, team) (ALERTS{alertstate="firing",alertname=~"$alertname",alertname!="Watchdog"})', "{{alertname}} ({{team}})")], "short"),
         timeseries("Requests and errors", "Requests and 5xx per second over the same range.", [prom(RPS, "requests"), prom(f'sum({req(",status_class=\"5xx\"")})', "5xx")], "reqps")],
        [timeseries("Affected PoPs", "5xx per second by PoP, to see where it started.", [prom(f'sum by (pop) ({req(",status_class=\"5xx\"")})', "{{pop}}")], "reqps"),
         bargauge("Affected domains", "Hostnames with the most 5xx responses in the range.", [ch(top("hostname", "sumIf(requests, status >= 500)", "errors", hostname=True), table=True)], "short", "zero_warn")],
        [logs("Related error logs", "Error and critical logs from every service in the same range.", [loki('{level=~"error|critical"}')], h=10)],
    ]
    return dashboard("cdnf-incident", "Incident view", "cdnf-oncall", "One alert or time range with its metrics, related logs, annotations, and affected domains and PoPs.", rows, runbook="component-unhealthy", extra_variables=[alert_var])


def logs_explorer() -> dict:
    service = {"name": "service", "label": "Service", "type": "query", "datasource": LOKI, "query": {"label": "service", "refId": "service", "stream": "", "type": 1}, "definition": "label_values(service)", "includeAll": True, "multi": True, "allValue": ".+",
               "current": {"selected": True, "text": ["All"], "value": ["$__all"]}, "refresh": 2, "hide": 0, "options": [], "sort": 1}
    level = {"name": "level", "label": "Level", "type": "custom", "query": "debug,info,warning,error,critical", "includeAll": True, "multi": True, "allValue": ".+",
             "current": {"selected": True, "text": ["All"], "value": ["$__all"]}, "hide": 0, "options": [{"selected": True, "text": "All", "value": "$__all"}] + [{"selected": False, "text": v, "value": v} for v in ("debug", "info", "warning", "error", "critical")]}
    saved = ("**Saved queries.** Each runs in the panels below.\n\n"
             "| Service | LogQL |\n|---|---|\n"
             + "\n".join(f"| {name} | `{{service=\"{name}\"}}` |" for name in ("core", "edge-agent", "edge-gateway", "dnsdist", "pdns-auth", "loki", "vector")) + "\n")
    rows = [
        [text("Saved queries", "LogQL for each service. Pick a service and level above, or paste one of these into Explore.", saved, w=24, h=6)],
        [logs("Logs", "Operational logs for the selected services and levels. Use the Live button for a live tail.", [loki('{service=~"$service", level=~"$level"}')], h=12)],
        [timeseries("Log volume by level", "Lines per second by level.", [loki('sum by (level) (count_over_time({service=~"$service"} [$__auto]))', legend="{{level}}")], "short", stack=True, w=12),
         timeseries("Error lines by service", "Error and critical lines per interval, by service.", [loki('sum by (service) (count_over_time({service=~"$service", level=~"error|critical"} [$__auto]))', legend="{{service}}")], "short", threshold="zero_warn", w=12)],
    ]
    return dashboard("cdnf-logs-explorer", "Logs explorer", "cdnf-oncall", "Saved LogQL for each service, with level and service filters and live tail.", rows, runbook="operational-error-logs", extra_variables=[service, level])


def request_trace() -> dict:
    request = {"name": "request_id", "label": "Request ID", "type": "textbox", "query": "", "current": {"text": "", "value": ""}, "hide": 0, "options": [{"selected": True, "text": "", "value": ""}], "skipUrlSync": False}
    rows = [
        [table("Edge log", "The edge log line for the request: PoP, edge, domain, status, timings, cache, and security result.", [ch(
            "SELECT occurred_at AS time, pop, edge_id, hostname, method, path, status, cache_status, request_time_ms, upstream_header_ms, origin_latency_ms, origin_error, origin_role, security_action, security_class, server_protocol, ssl_protocol "
            "FROM cdnf.edge_events WHERE request_id = '${request_id}' AND request_id != '' AND occurred_at > now() - INTERVAL 7 DAY ORDER BY occurred_at LIMIT 20", table=True)], w=24, h=7)],
        [stat("Edge time", "Time the edge took to serve the request.", [ch("SELECT request_time_ms AS ms FROM cdnf.edge_events WHERE request_id = '${request_id}' AND request_id != '' LIMIT 1", table=True)], "ms", "latency_ms", w=6),
         stat("Origin time", "Time the origin took to answer.", [ch("SELECT origin_latency_ms AS ms FROM cdnf.edge_events WHERE request_id = '${request_id}' AND request_id != '' LIMIT 1", table=True)], "ms", "latency_ms", w=6),
         stat("Status", "The response status the client received.", [ch("SELECT status FROM cdnf.edge_events WHERE request_id = '${request_id}' AND request_id != '' LIMIT 1", table=True)], "none", w=6),
         stat("Cache", "The cache status of the request.", [ch("SELECT cache_status FROM cdnf.edge_events WHERE request_id = '${request_id}' AND request_id != '' LIMIT 1", table=True)], "none", w=6, text=True)],
        [logs("Operational logs for the request", "Operational log lines that mention the request ID.", [loki('{service=~".+"} |= "${request_id}" | json | request_id="${request_id}"')], h=10)],
    ]
    return dashboard("cdnf-request-trace", "Request trace", "cdnf-oncall", "One request ID from the edge log to the origin result to operational logs.", rows, runbook="operational-error-logs", extra_variables=[request])


def domain_overview() -> dict:
    d = "domain_id IN (${domain:csv})"
    rows = [
        [stat("Requests", "Requests per second for the selected domains.", [ch(traffic_sql("sum(requests) / greatest(1, dateDiff('second', $__fromTime, $__toTime)) AS rps"), table=True)], "reqps", w=4, decimals=2),
         stat("Bandwidth", "Average bits per second sent to clients.", [ch(traffic_sql("8 * sum(bytes_sent) / greatest(1, dateDiff('second', $__fromTime, $__toTime)) AS bps"), table=True)], "bps", w=4, decimals=1),
         stat("5xx ratio", "Share of responses that are server errors.", [ch(traffic_sql(f"{CH_ERR_PCT} AS ratio"), table=True)], "percent", "error_percent", w=4, decimals=2),
         stat("Cache hit", "Share of requests served from cache.", [ch(traffic_sql(f"{CH_HIT_PCT} AS ratio"), table=True)], "percent", "hit_percent", w=4, decimals=1),
         stat("Edge p95", "95th percentile time to serve a request.", [ch(traffic_sql(f"{CH_P95} AS p95"), table=True)], "ms", "latency_ms", w=4, decimals=0),
         stat("Origin p95", "95th percentile origin response time.", [ch(traffic_sql(f"{CH_ORIGIN_P95} AS p95"), table=True)], "ms", "latency_ms", w=4, decimals=0)],
        row("Traffic and cache"), [timeseries("Requests", "Requests per second by status class.", [ch(split("concat(toString(intDiv(status, 100)), 'xx') AS class", "sum(requests)", "requests", per_second=True))], "reqps", stack=True),
                                  timeseries("Cache status", "Requests per second by cache status.", [ch(split("cache_status", "sum(requests)", "requests", per_second=True))], "reqps", stack=True)],
        row("Origin and security"), [timeseries("Origin latency", "95th percentile origin time and origin errors.", [ch(traffic_sql(f"{CH_ORIGIN_P95} AS origin_p95", time=True))], "ms"),
                                    timeseries("Security events", "Blocked and classified requests per second.", [ch(split("security_class", "sum(requests)", "events", where="security_class != ''", per_second=True))], "reqps", stack=True)],
        row("TLS, DNS, purges, and changes"), [
            table("TLS certificates", "The domain's certificates and when they expire.", [pg(f"SELECT domain_id, kind, status, expires_at FROM grafana_tls_certificates WHERE {d} ORDER BY expires_at LIMIT 20")], w=8,
                  overrides=[{"matcher": {"id": "byName", "options": "status"}, "properties": [{"id": "custom.minWidth", "value": 110}]}, {"matcher": {"id": "byName", "options": "domain_id"}, "properties": [{"id": "custom.width", "value": 90}]}]),
            over(timeseries("DNS queries", "DNS queries per hour for the domain's zone over the last 24 hours, whatever range the dashboard shows.", [ch("SELECT $__timeInterval(interval_start) AS time, rcode, sum(queries) AS queries FROM cdnf.dns_hourly WHERE $__timeFilter(interval_start) AND $__conditionalAll(domain_id IN (${domain:csv}), $domain) GROUP BY time, rcode ORDER BY time")], "short", w=8, stack=True), "24h"),
            table("Purge history", "Recent cache purges for the domain.", [pg(f"SELECT created_at, type, status FROM grafana_cache_purges WHERE {d} ORDER BY created_at DESC LIMIT 20")], w=8)],
        [table("Configuration changes", "Edge configuration published for the domain, newest first.", [pg(f"SELECT created_at, kind, revision FROM grafana_edge_artifacts WHERE {d} ORDER BY created_at DESC LIMIT 20")], w=12),
         table("Requests by status code", "The status codes the domain returned in the range.", [ch(top("toString(status) AS code", CH_REQUESTS, "requests", limit=15), table=True)], w=12)],
    ]
    return dashboard("cdnf-domain-overview", "Domain overview", "cdnf-domains", "Traffic, cache, origin, security, TLS, DNS, purge history, and configuration changes for one domain.", rows, runbook="domain-5xx-ratio-high", parent=("cdnf-now", "Now"))


def domain_comparison() -> dict:
    rows = [
        [table("Domains side by side", "The selected hostnames with requests, errors, cache hit, bytes, and latency, for support to compare.", [ch(traffic_sql(
            f"sum(requests) AS request_count, {CH_ERR_PCT} AS error_percent, {CH_HIT_PCT} AS hit_percent, sum(bytes_sent) AS bytes, {CH_P95} AS p95_ms", dims="hostname", hostname=True, order="request_count DESC", limit=30), table=True)], w=24, h=9,
            transformations=[{"id": "organize", "options": {"renameByName": {"hostname": "Domain", "request_count": "Requests", "error_percent": "5xx %", "hit_percent": "Cache hit %", "bytes": "Bytes sent", "p95_ms": "p95"}}}],
            overrides=[{"matcher": {"id": "byName", "options": "Bytes sent"}, "properties": [{"id": "unit", "value": "bytes"}]},
                       {"matcher": {"id": "byRegexp", "options": ".* %"}, "properties": [{"id": "unit", "value": "percent"}, {"id": "decimals", "value": 2}]},
                       {"matcher": {"id": "byName", "options": "p95"}, "properties": [{"id": "unit", "value": "ms"}, {"id": "decimals", "value": 0}]}])],
        [over(timeseries("Requests by domain", "Requests per second for the selected hostnames.", [ch(split("hostname", "sum(requests)", "requests", hostname=True, per_second=True))], "reqps"), "24h"),
         over(timeseries("5xx by domain", "Server errors per second for the selected hostnames.", [ch(split("hostname", "sumIf(requests, status >= 500)", "errors", hostname=True, per_second=True))], "reqps", threshold="zero_warn"), "24h")],
        [over(timeseries("Cache hit by domain", "Share of requests served from cache.", [ch(split("hostname", CH_HIT_PCT, "hit", hostname=True))], "percent", threshold="hit_percent"), "24h"),
         over(timeseries("Latency by domain", "95th percentile edge time.", [ch(split("hostname", CH_P95, "p95", hostname=True))], "ms", threshold="latency_ms"), "24h")],
    ]
    return dashboard("cdnf-domain-comparison", "Domain comparison", "cdnf-domains", "Several domains side by side, for support.", rows, runbook="domain-5xx-ratio-high")


def volume(dimension: str, column: str) -> str:
    # A bar needs a text label: a numeric domain id would be read as a second value, and a full edge id is wider than the bar.
    label = {"domain_id": "concat('domain ', toString(domain_id))", "edge_id": "substring(toString(edge_id), 31)"}.get(dimension, dimension)
    return (f"SELECT {label} AS {dimension}_label, sum({column}) AS bytes FROM cdnf.edge_volume_hourly WHERE interval_start >= toStartOfHour($__fromTime) AND interval_start <= $__toTime "
            f"AND $__conditionalAll(domain_id IN (${{domain:csv}}), $domain) GROUP BY {dimension}_label ORDER BY bytes DESC LIMIT 10")


def usage_billing() -> dict:
    month = "toStartOfMonth(interval_start)"
    rows = [
        [text("Reports", "Where the monthly usage report is.", "The monthly usage report (CSV and PDF) is built from these rollups and listed in the administration panel under **Operations → Usage reports**. Its totals equal the tables below for the same range.", w=24, h=3)],
        [stat("Bandwidth this month", "Bytes sent to clients this month, headers included.", [ch("SELECT sum(bytes_sent) AS bytes FROM cdnf.edge_usage_5m WHERE interval_start >= toStartOfMonth(now())", table=True)], "bytes", w=6),
         stat("p95 bandwidth", "Bits per second at the 95th percentile of 5-minute intervals this month, all domains together.", [ch("SELECT quantile(0.95)(bps) AS p95 FROM (SELECT interval_start, 8 * sum(bytes_sent) / 300 AS bps FROM cdnf.edge_usage_5m WHERE interval_start >= toStartOfMonth(now()) GROUP BY interval_start)", table=True)], "bps", w=6, decimals=1),
         stat("Requests this month", "Requests served this month.", [pg("SELECT COALESCE(sum(requests), 0) AS requests FROM grafana_usage_rollups WHERE interval_start >= date_trunc('month', now())")], "short", w=6),
         stat("DNS this month", "Authoritative DNS queries this month.", [pg("SELECT COALESCE(sum(dns_queries), 0) AS queries FROM grafana_usage_rollups WHERE interval_start >= date_trunc('month', now())")], "short", w=6)],
        [table("Usage by domain", "Bandwidth, 95th-percentile bandwidth, and requests per domain for the selected range.", [ch(
            "SELECT domain_id, sum(sent) AS bytes, quantile(0.95)(bps) AS p95_bps, sum(reqs) AS request_count FROM "
            "(SELECT domain_id, interval_start, sum(bytes_sent) AS sent, sum(requests) AS reqs, 8 * sum(bytes_sent) / 300 AS bps FROM cdnf.edge_usage_5m WHERE $__timeFilter(interval_start) AND $__conditionalAll(domain_id IN (${domain:csv}), $domain) GROUP BY domain_id, interval_start) "
            "GROUP BY domain_id ORDER BY bytes DESC LIMIT 100", table=True)], w=16,
            overrides=[{"matcher": {"id": "byName", "options": "bytes"}, "properties": [{"id": "unit", "value": "bytes"}]}, {"matcher": {"id": "byName", "options": "p95_bps"}, "properties": [{"id": "unit", "value": "bps"}, {"id": "decimals", "value": 1}]}]),
         table("Usage by region", "Bandwidth and requests per region for the selected range.", [ch("SELECT region, sum(bytes_sent) AS bytes, sum(requests) AS requests FROM cdnf.edge_usage_5m WHERE $__timeFilter(interval_start) AND $__conditionalAll(region IN (${region:singlequote}), $region) GROUP BY region ORDER BY bytes DESC", table=True)], w=8,
               overrides=[{"matcher": {"id": "byName", "options": "bytes"}, "properties": [{"id": "unit", "value": "bytes"}]}])],
        [label_by(timeseries("Bandwidth by PoP", "Bits per second sent, per PoP, at 5-minute resolution.", [ch("SELECT $__timeInterval(interval_start) AS time, pop, 8 * sum(bytes_sent) / 300 AS bps FROM cdnf.edge_usage_5m WHERE $__timeFilter(interval_start) AND $__conditionalAll(pop IN (${pop:singlequote}), $pop) GROUP BY time, pop ORDER BY time")], "bps", stack=True), "pop"),
         over(timeseries("Monthly bandwidth", "Bytes sent per month, over the last year.", [ch(f"SELECT {month} AS time, sum(bytes_sent) AS bytes FROM cdnf.edge_traffic_daily WHERE interval_start > now() - INTERVAL 12 MONTH AND " + SCOPE + " GROUP BY time ORDER BY time")], "bytes"), "1y")],
        [table("Billing rollups", "Hourly usage rollups kept in the control database, which the monthly report uses.", [pg("SELECT date_trunc('day', interval_start) AS day, sum(requests) AS requests, sum(bytes_out) AS bytes_out, sum(cache_hits) AS cache_hits, sum(dns_queries) AS dns_queries FROM grafana_usage_rollups WHERE interval_start > now() - interval '62 days' GROUP BY 1 ORDER BY 1 DESC LIMIT 62")], w=24,
               overrides=[{"matcher": {"id": "byName", "options": "bytes_out"}, "properties": [{"id": "unit", "value": "bytes"}]}])],
        row("Download and upload"),
        [bargauge("Download by edge", "Bytes sent to clients, headers included, per edge host. SQL: SELECT edge_id, sum(bytes_sent) FROM cdnf.edge_volume_hourly WHERE interval_start >= toStartOfHour($__fromTime) GROUP BY edge_id", [ch(volume("edge_id", "bytes_sent"), table=True)], "bytes", w=6),
         bargauge("Download by cell", "Bytes sent to clients per serving cell. SQL: SELECT cell, sum(bytes_sent) FROM cdnf.edge_volume_hourly WHERE interval_start >= toStartOfHour($__fromTime) GROUP BY cell", [ch(volume("cell", "bytes_sent"), table=True)], "bytes", w=6),
         bargauge("Download by domain", "Bytes sent to clients per domain.", [ch(volume("domain_id", "bytes_sent"), table=True)], "bytes", w=6),
         bargauge("Download by country", "Bytes sent to clients per client country.", [ch(volume("country", "bytes_sent"), table=True)], "bytes", w=6)],
        [bargauge("Upload by edge", "Request bytes received from clients (the request length, headers included) per edge host.", [ch(volume("edge_id", "bytes_in"), table=True)], "bytes", w=6),
         bargauge("Upload by cell", "Request bytes received from clients per serving cell.", [ch(volume("cell", "bytes_in"), table=True)], "bytes", w=6),
         bargauge("Upload by domain", "Request bytes received from clients per domain.", [ch(volume("domain_id", "bytes_in"), table=True)], "bytes", w=6),
         bargauge("Upload by country", "Request bytes received from clients per client country.", [ch(volume("country", "bytes_in"), table=True)], "bytes", w=6)],
        [timeseries("Bandwidth by edge", "Bits per second each edge host sent (download) and received (upload), live from Prometheus. Origin-side traffic is not measured.", [prom("8 * sum by (edge) (rate(cdnf_edge_bytes_sent_total[5m]))", "download {{edge}}"), prom("8 * sum by (edge) (rate(cdnf_edge_bytes_received_total[5m]))", "upload {{edge}}")], "bps", w=24)],
    ]
    return dashboard("cdnf-usage-billing", "Usage and billing", "cdnf-business", "Monthly bandwidth, 95th-percentile bandwidth, requests, and DNS queries per domain, pool, and region, from rollups only.", rows, runbook="egress-anomaly")


def growth() -> dict:
    rows = [
        [stat("Domains", "Domains that are not deleted.", [pg("SELECT count(*) AS domains FROM domains WHERE deleted_at IS NULL")], "short", w=6),
         stat("New domains (30d)", "Domains created in the last 30 days.", [pg("SELECT count(*) AS domains FROM domains WHERE deleted_at IS NULL AND created_at > now() - interval '30 days'")], "short", w=6),
         stat("Traffic growth (30d)", "Requests in the last 30 days against the 30 days before.", [ch("SELECT 100 * (sumIf(requests, interval_start > now() - INTERVAL 30 DAY) / nullIf(sumIf(requests, interval_start <= now() - INTERVAL 30 DAY), 0) - 1) AS growth FROM cdnf.edge_traffic_daily WHERE interval_start > now() - INTERVAL 60 DAY AND " + SCOPE, table=True)], "percent", w=6, decimals=1),
         stat("Bandwidth growth (30d)", "Bytes sent in the last 30 days against the 30 days before.", [ch("SELECT 100 * (sumIf(bytes_sent, interval_start > now() - INTERVAL 30 DAY) / nullIf(sumIf(bytes_sent, interval_start <= now() - INTERVAL 30 DAY), 0) - 1) AS growth FROM cdnf.edge_traffic_daily WHERE interval_start > now() - INTERVAL 60 DAY AND " + SCOPE, table=True)], "percent", w=6, decimals=1)],
        [over(timeseries("Domains by month", "Domains created per month, over the last year.", [pg("SELECT date_trunc('month', created_at) AS time, count(*) AS domains FROM domains WHERE deleted_at IS NULL GROUP BY 1 ORDER BY 1", table=False)], "short"), "1y"),
         over(timeseries("Traffic by month", "Requests per month, over the last year.", [ch("SELECT toStartOfMonth(interval_start) AS time, sum(requests) AS requests FROM cdnf.edge_traffic_daily WHERE " + SCOPE + " GROUP BY time ORDER BY time")], "short"), "1y")],
        [over(timeseries("Bandwidth by month", "Bytes sent per month, over the last year.", [ch("SELECT toStartOfMonth(interval_start) AS time, sum(bytes_sent) AS bytes FROM cdnf.edge_traffic_daily WHERE " + SCOPE + " GROUP BY time ORDER BY time")], "bytes"), "1y"),
         table("Top growing domains", "Hostnames whose requests grew most against the previous 30 days.", [ch(
             "SELECT hostname, sumIf(requests, interval_start > now() - INTERVAL 30 DAY) AS last_30d, sumIf(requests, interval_start <= now() - INTERVAL 30 DAY) AS previous_30d, last_30d - previous_30d AS growth "
             "FROM cdnf.edge_traffic_daily WHERE interval_start > now() - INTERVAL 60 DAY AND hostname != '' AND " + SCOPE + " GROUP BY hostname ORDER BY growth DESC LIMIT 15", table=True)])],
    ]
    return dashboard("cdnf-growth", "Growth", "cdnf-business", "Domains, traffic, and bandwidth by month, and top growing domains.", rows, runbook="egress-anomaly")


def capacity() -> dict:
    forecast = 'predict_linear(cdnfoundry_cell_capacity_ratio{resource="%s"}[7d], 30 * 86400)'
    rows = [
        [stat("Hottest cache", "Highest cache fill of any cell now.", [prom('max(cdnfoundry_cell_capacity_ratio{resource="cache"})', "cache", instant=True)], "percentunit", "ratio_utilization", w=6, decimals=0),
         stat("Hottest connections", "Highest connection use of any cell now.", [prom('max(cdnfoundry_cell_capacity_ratio{resource="connections"})', "connections", instant=True)], "percentunit", "ratio_utilization", w=6, decimals=0),
         stat("Hottest CPU", "Highest CPU use of any host now.", [prom('max(100 * (1 - avg by (node) (rate(node_cpu_seconds_total{mode="idle"}[5m]))))', "cpu", instant=True)], "percent", "utilization", w=6, decimals=0),
         first_to_run_out(stat("First to run out", "Days until the first cache is forecast to fill, by a linear forecast from the last 7 days. Shows \"Not within a year\" when no cache is on course to fill.", [prom('clamp_max(clamp_min(min(((1 - cdnfoundry_cell_capacity_ratio{resource="cache"}) / clamp_min(deriv(cdnfoundry_cell_capacity_ratio{resource="cache"}[7d]), 1e-12)) / 86400), 0), 365)', "days", instant=True)], "d", "neutral", w=6, decimals=0))],
        [table("Forecast: first PoP to run out", "Forecast cache fill in 30 days per PoP and cell. A value at or above 1 means the cache is full by then. The first row runs out first.", [prom(f'sort_desc({forecast % "cache"})', "forecast", instant=True, fmt="table")], "percentunit", w=12, sort="Value",
               transformations=[{"id": "filterFieldsByName", "options": {"include": {"names": ["pop", "cell", "Value"]}}}, {"id": "organize", "options": {"excludeByName": {"Time": True}, "indexByName": {"PoP": 0, "Cell": 1, "Fill in 30 days": 2}, "renameByName": {"pop": "PoP", "cell": "Cell", "Value": "Fill in 30 days"}}}],
               overrides=[{"matcher": {"id": "byName", "options": "Fill in 30 days"}, "properties": [{"id": "thresholds", "value": {"mode": "absolute", "steps": THRESHOLDS["ratio_utilization"]}}, {"id": "custom.cellOptions", "value": {"type": "color-background"}}]}]),
         timeseries("Cache headroom per PoP", "Free share of the cache volume, per PoP.", [prom('1 - max by (pop) (cdnfoundry_cell_capacity_ratio{resource="cache"})', "{{pop}}")], "percentunit", min_value=0, max_value=1)],
        [timeseries("Connection headroom per PoP", "Free share of the connection budget, per PoP.", [prom('1 - max by (pop) (cdnfoundry_cell_capacity_ratio{resource="connections"})', "{{pop}}")], "percentunit", min_value=0, max_value=1),
         timeseries("Memory headroom per host", "Free share of memory per host.", [prom("node_memory_MemAvailable_bytes / node_memory_MemTotal_bytes", "{{node}}")], "percentunit", min_value=0, max_value=1)],
        [timeseries("Peak bandwidth per PoP", "Largest 5-minute bandwidth each PoP carried.", [prom(f"8 * sum by (pop) (cdnf:edge_bytes_sent:rate5m{{{S}}})", "{{pop}}")], "bps"),
         timeseries("Forecast bandwidth", "Linear forecast of total bandwidth for the next 30 days from the last 7 days.", [prom(f"predict_linear((8 * sum(cdnf:edge_bytes_sent:rate5m{{{S}}}))[7d:1h], 30 * 86400)", "forecast")], "bps")],
    ]
    return dashboard("cdnf-capacity", "Capacity", "cdnf-business", "Headroom per PoP and pool for bandwidth, connections, cache, and CPU, and a 30-day forecast that names the first PoP to run out.", rows, runbook="cell-cache-or-compression-pressure")


def query_samples() -> dict:
    rows = [
        [text("How to use these", "The queries behind each sample run in the panels below.", "Each panel below answers a common question and shows its query in the panel description. Copy it into Explore to change it.", w=24, h=3)],
        [bargauge("Top domains by egress", "Which domains sent the most bytes? SQL: SELECT hostname, sum(bytes_sent) FROM cdnf.edge_traffic_hourly WHERE $__timeFilter(interval_start) GROUP BY hostname ORDER BY 2 DESC LIMIT 10", [ch(top("hostname", CH_BYTES, "bytes", hostname=True), table=True)], "bytes", w=8),
         bargauge("Which PoP is slowest", "Which PoP has the highest 95th percentile edge time? PromQL: topk(5, 1000 * histogram_quantile(0.95, sum by (le, pop) (cdnf:edge_latency_bucket:rate5m)))", [prom(f"topk(5, 1000 * histogram_quantile(0.95, sum by (le, pop) (cdnf:edge_latency_bucket:rate5m{{{S}}})))", "{{pop}}", instant=True)], "ms", "latency_ms", w=8),
         bargauge("Who triggered a WAF rule", "Which client networks triggered the most WAF matches? SQL: group edge_events by masked client network where waf_rule_id != 0.", [ch(f"SELECT {'if(position(client_ip, \':\') > 0, toString(IPv6CIDRToRange(toIPv6OrZero(client_ip), 48).1) || \'/48\', toString(IPv4CIDRToRange(toIPv4OrZero(client_ip), 24).1) || \'/24\')'} AS network, count() AS matches FROM cdnf.edge_events WHERE occurred_at > now() - INTERVAL 7 DAY AND waf_rule_id != 0 GROUP BY network ORDER BY matches DESC LIMIT 10", table=True)], "short", w=8)],
        [timeseries("Error rate by status", "What is the rate of each 5xx status? PromQL: sum by (status_class) (cdnf:edge_requests:rate5m{status_class=\"5xx\"})", [prom('sum by (status_class) (cdnf:edge_requests:rate5m{status_class=~"4xx|5xx"})', "{{status_class}}")], "reqps", w=8),
         over(timeseries("Slowest origins over time", "Which hostnames have a slow origin? SQL: quantilesTDigestMerge over origin_latency_q by hostname.", [ch(split("hostname", CH_ORIGIN_P95, "origin_p95", hostname=True))], "ms", w=8), "24h"),
         logs("Recent errors", "What errors did services log? LogQL: {level=~\"error|critical\"}", [loki('{level=~"error|critical"}')], w=8, h=8)],
    ]
    return dashboard("cdnf-query-samples", "Query samples", "cdnf-samples", "Documented PromQL, SQL, and LogQL for the common questions, each in a panel that runs.", rows, runbook="operational-error-logs")


DASHBOARDS = [
    ("30-platform-admin", control_plane), ("30-platform-admin", fleet_rollouts), ("30-platform-admin", data_stores), ("30-platform-admin", telemetry_pipeline),
    ("30-platform-admin", backups), ("30-platform-admin", hosts_containers), ("30-platform-admin", daily_checks),
    ("40-on-call-slo", slos), ("40-on-call-slo", incident), ("40-on-call-slo", logs_explorer), ("40-on-call-slo", request_trace),
    ("50-domains", domain_overview), ("50-domains", domain_comparison),
    ("60-business-capacity", usage_billing), ("60-business-capacity", growth), ("60-business-capacity", capacity),
    ("90-samples", query_samples),
]
