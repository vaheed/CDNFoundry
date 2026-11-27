"""The Loki investigation templates of the Logs explorer.

One reusable LogQL query per class of event. Every template selects by the four low-cardinality labels Vector sets (environment, host,
service, level) and filters the parsed JSON line by the identifiers below, so a template never adds a label. The same table feeds the
dashboard and `tests/e2e/loki_templates.py`, which pushes a known line per class to Loki and reads it back through each template.
"""
from __future__ import annotations

# The identifiers an operator can narrow by. Each is a field of the structured log line, applied after `| json`, never a label.
# (variable name, parsed field, label shown on the dashboard)
FIELDS = (
    ("domain", "domain_id", "Domain ID"), ("edge", "edge_id", "Edge ID"), ("cell", "cell", "Cell"), ("request_id", "request_id", "Request ID"),
    ("operation_id", "operation_id", "Operation ID"), ("task_id", "task_id", "Task ID"), ("revision", "revision_id", "Revision"),
    ("event_type", "event", "Event type"),
)

# (key, title, what it answers, service regex, optional case-insensitive line filter)
TEMPLATES = (
    ("errors", "All errors and critical events", "Every error and critical line, from every service.", ".+", None),
    ("control_plane", "Control plane", "Application, gateway and web logs of the control plane.", "core|web|caddy|migrate|telemetry-gateway", None),
    ("jobs", "Jobs and scheduler", "Queue workers, the scheduler, notifications and log push.", "horizon|scheduler|notifier|log-pusher", None),
    ("edge_agent", "Edge agent", "The agent's tasks, enrollment, heartbeats and artifact delivery.", "edge-agent", None),
    ("gateway_cells", "Gateway and cells", "The PoP gateway, its control listener and every cell.", "edge-gateway|edge-control|cell-0[1-8]", None),
    ("origin", "Origin connectivity and TLS", "Origin connection, timeout, destination and TLS verification lines.", "edge-agent|edge-gateway|cell-0[1-8]", "origin|upstream|tls|handshake|destination"),
    ("dns", "DNS and zone synchronization", "PowerDNS, DNSdist and zone reconciliation.", "pdns-auth|pdns-primary|pdns-migrate|dnsdist|core|horizon", "zone|dns|pdns|serial|catalog"),
    ("certificates", "Certificate issuance and renewal", "ACME orders, DNS-01 validation and renewal.", "core|horizon|scheduler", "certificate|acme|dns-01|renewal|order"),
    ("waf", "WAF runtime", "ModSecurity and managed ruleset lines from the cells.", "cell-0[1-8]|edge-gateway", "waf|modsecurity|crs|ruleset"),
    ("challenge", "Challenge failures", "Browser challenge refusals and verification failures.", "cell-0[1-8]|edge-gateway|edge-agent", "challenge"),
    ("rate_reputation", "Shared rate-limit and reputation", "Rate reports, reputation bands and their aggregation.", "edge-agent|core|horizon|cell-0[1-8]", "rate.?limit|reputation|containment|report"),
    ("deployment", "Configuration deployment and revision mismatch", "Snapshots, artifacts, revisions and acknowledgements.", "edge-agent|core|horizon", "revision|snapshot|artifact|mismatch|activation"),
    ("telemetry", "Telemetry delivery", "Vector, the log collector, Loki and ClickHouse delivery, buffers and drops.", "vector|log-collector|loki|clickhouse|clickhouse-migrate", "buffer|dropped|sink|insert|delivery|discard"),
    ("database_queue", "Database and queue", "PostgreSQL, the cache, queues and connection errors.", "control-db|pdns-db|cache|redis|horizon|core", "database|postgres|queue|deadlock|connection|redis|migration"),
    ("backup_recovery", "Backup, rollback and recovery", "Backups, restores and rollbacks the control plane records. Fleet runs on the operator host and does not log to Loki.", "core|horizon|scheduler|migrate", "backup|restore|rollback|recovery"),
    ("correlation", "Request and operation correlation", "Every service's lines for one request, operation or task. Fill an identifier above.", ".+", None),
)

TEXT = "free_text"


def logql(service: str, line_filter: str | None, *, environment: str = "$environment", host: str = "$host", level: str = "$level",
          fields: dict[str, str] | None = None, text: str = "$free_text") -> str:
    """The LogQL of one template. `fields` gives the value of each variable; the dashboard passes the variable references."""
    values = fields or {variable: f"${variable}" for variable, _, _ in FIELDS}
    selector = f'{{environment=~"{environment}", host=~"{host}", service=~"{service}", level=~"{level}"}}'
    query = selector
    if line_filter:
        query += f' |~ "(?i){line_filter}"'
    if text:
        query += f' |~ "(?i){text}"'
    query += " | json"
    for variable, parsed, _ in FIELDS:
        query += f' | {parsed}=~".*{values[variable]}.*"'
    return query


# A template that is about one severity ignores the severity picker.
FIXED_LEVEL = {"errors": "error|critical"}


def template_logql(key: str, **kwargs: object) -> str:
    """The LogQL of the template named `key`, with the variables as the dashboard writes them unless `kwargs` replaces them."""
    for entry_key, _, _, service, line_filter in TEMPLATES:
        if entry_key == key:
            if key in FIXED_LEVEL:
                kwargs["level"] = FIXED_LEVEL[key]
            return logql(service, line_filter, **kwargs)  # type: ignore[arg-type]
    raise KeyError(key)
