"""Service-level impact of a configuration change.

The impact map says, for every kind of rendered input, the smallest safe action on a host. A plan row names one
service (or the host firewall), what changed, the action, the reason, the expected availability effect, how it is
validated and how it is undone. The plan is computed from two profiles of a node bundle: the digest of every file,
the digest of every environment key, and the Compose configuration hash of every service. A profile holds only
SHA-256 digests, so a secret value is never stored or printed.

The ladder, narrowest first: none, atomic file update, live reload, targeted restart, targeted recreation,
node-wide activation. A file the map does not know is node-wide: unknown inputs are never assumed harmless.
"""
from __future__ import annotations

import hashlib
import shutil
import subprocess
from dataclasses import asdict, dataclass
from pathlib import Path
from typing import Any

LADDER = ("none", "firewall", "file", "reload", "restart", "recreate", "node")
# The actions that have an activation narrower than the full one (see actions.plan_config_change). A file that no mapped service
# reads and a service the node does not run produce no row.
NARROW = ("none", "firewall", "file", "reload", "restart", "recreate")

# (path prefix or exact name, kind, services that read it, action, reason, availability, validation, rollback)
# Files are matched in order; the first match wins.
RULES: tuple[tuple[str, str, tuple[str, ...], str, str, str, str, str], ...] = (
    ("firewall.sh", "firewall", ("host",), "firewall", "Host firewall rules are rebuilt in a fresh chain and swapped in; no container is touched", "No gap: the old chain filters until the swap", "firewall.sh exits zero and the chain jump is present", "The previous chain is still in place until the swap succeeds"),
    ("deploy/production/Caddyfile.telemetry", "telemetry gateway configuration", ("telemetry-gateway",), "reload", "The gateway configuration is validated and reloaded on SIGUSR1; the process keeps serving", "No dropped connection", "caddy validate, then reload, then the reload is read back from the gateway log", "A failed validation leaves the running configuration serving; the file is restored"),
    ("deploy/production/Caddyfile", "gateway configuration", ("caddy",), "reload", "The gateway configuration is validated and reloaded on SIGUSR1; the process keeps serving", "No dropped connection", "caddy validate, then reload, then the reload is read back from the gateway log", "A failed validation leaves the running configuration serving; the file is restored"),
    ("generated/allowlists/", "address allowlists", ("caddy", "telemetry-gateway"), "reload", "The gateways import the address lists from files; the control plane reads its list on every request", "No dropped connection", "caddy validate, then reload, then the reload is read back from the gateway log", "The previous files are restored and the gateways reloaded"),
    ("generated/prometheus-", "prometheus targets", ("prometheus",), "reload", "File-based scrape targets are read by Prometheus on reload", "No interruption", "Prometheus reports its last reload as successful", "The previous file is restored and reloaded"),
    ("docker/prometheus/blackbox.yml", "blackbox exporter configuration", ("blackbox",), "restart", "The exporter reads its configuration at start", "Probe results pause for a few seconds; serving is not affected", "The exporter health check after the restart", "The previous file is restored and the exporter restarted"),
    ("docker/prometheus/", "prometheus rules", ("prometheus",), "reload", "Rules and scrape settings are read by Prometheus on reload", "No interruption", "promtool check, then reload, then Prometheus reports its last reload as successful", "The previous file is restored and reloaded"),
    ("docker/grafana/dashboards/", "grafana dashboards", ("grafana",), "file", "Grafana's provisioning watcher reads changed dashboards", "No interruption", "The dashboard is read back through the Grafana API", "The previous file is restored"),
    ("docker/grafana/provisioning/", "grafana provisioning", ("grafana",), "file", "Grafana's provisioning watcher reads changed provisioning files", "No interruption", "The provisioned object is read back through the Grafana API", "The previous file is restored"),
    ("docker/grafana/postgres/", "grafana control database provisioning", ("grafana-control-db-provision",), "restart", "The one-shot provisioning runs again; it is idempotent", "No interruption of Grafana or of the control database", "The provisioning container exits zero", "The previous file is restored and the provisioning run again"),
    ("docker/grafana/", "grafana bootstrap", ("grafana-bootstrap",), "restart", "The one-shot bootstrap runs again and applies library panels and access settings; it is idempotent", "No interruption of Grafana", "The bootstrap container exits zero", "The previous file is restored and the bootstrap run again"),
    ("docker/alertmanager/start.sh", "alertmanager start script", ("alertmanager",), "restart", "The start script runs once at start", "Alert delivery pauses for a few seconds; Prometheus keeps firing and resends", "Alertmanager health after the restart", "The previous file is restored and Alertmanager restarted"),
    ("docker/alertmanager/", "alertmanager configuration", ("alertmanager",), "reload", "Alertmanager reloads its configuration on SIGHUP", "No interruption", "amtool check-config, then reload, then Alertmanager reports its last reload as successful", "The previous file is restored and reloaded"),
    ("docker/vector/operational.yaml", "log collector pipeline", ("log-collector",), "restart", "The collector reads its pipeline at start", "Log shipping buffers on disk; serving is not affected", "vector validate before the restart", "The previous file is restored and the collector restarted"),
    ("docker/vector/", "vector pipeline", ("vector",), "restart", "Vector reads its pipeline at start", "Telemetry buffers on disk; serving is not affected", "vector validate before the restart", "The previous file is restored and Vector restarted"),
    ("docker/loki/", "loki configuration", ("loki",), "restart", "Loki reads its configuration at start", "Log search pauses briefly; serving is not affected", "Loki readiness after the restart", "The previous file is restored and Loki restarted"),
    ("docker/clickhouse/migrat", "clickhouse migrations", ("clickhouse-migrate",), "restart", "The one-shot migration runs again; every migration is idempotent", "No interruption of ClickHouse", "The migration container exits zero", "The previous file is restored; a migration is never undone"),
    ("docker/clickhouse/", "clickhouse files", ("clickhouse",), "restart", "ClickHouse reads its configuration at start", "Analytics unavailable during a restart; serving is not affected", "ClickHouse health after the restart", "The previous file is restored and ClickHouse restarted"),
    ("docker/dnsdist/", "dnsdist configuration", ("dnsdist",), "restart", "DNSdist reads its configuration at start", "This node stops answering for a few seconds; the other DNS nodes keep answering", "A DNS query after the restart", "The previous file is restored and DNSdist restarted"),
    ("docker/pdns/", "powerdns files", ("pdns-auth", "pdns-primary"), "restart", "PowerDNS reads its configuration at start", "One DNS node at a time; the other nodes keep answering", "pdns control and a query after the restart", "The previous file is restored"),
    ("docker/postgres/pdns-migrations/", "powerdns migrations", ("pdns-migrate",), "restart", "The one-shot migration runs again; every migration is idempotent", "No interruption of PowerDNS", "The migration container exits zero", "The previous file is restored; a migration is never undone"),
    ("docker/postgres/", "database files", ("pdns-db",), "recreate", "A database change is never a side effect of another change", "The database restarts; the control plane is unavailable for writes", "The database health gate", "Restore the previous file; the volume is untouched"),
    ("pki/", "node certificates", (), "node", "Node certificate material is read by several services", "Services that read it restart", "Node gate", "The previous bundle"),
    ("tls/", "tls material", (), "node", "TLS material is read by several services", "Services that read it restart", "Node gate", "The previous bundle"),
    ("secrets/", "secrets", (), "node", "Secret files are read by several services", "Services that read them restart", "Node gate", "The previous bundle"),
    ("generated/geo-routing-policy.json", "geo routing policy record", (), "none", "A record of the intended decision order; no runtime service reads it", "None", "None", "None"),
    ("README.md", "documentation", (), "none", "Documentation in the bundle only", "None", "None", "None"),
    ("start.sh", "node scripts", (), "none", "Operator scripts run on the next activation; nothing reads them while running", "None", "None", "None"),
    ("stop.sh", "node scripts", (), "none", "Operator scripts run on the next activation; nothing reads them while running", "None", "None", "None"),
    ("validate.sh", "node scripts", (), "none", "Operator scripts run on the next activation; nothing reads them while running", "None", "None", "None"),
    ("prepare-dns.sh", "node scripts", (), "none", "Operator scripts run on the next activation; nothing reads them while running", "None", "None", "None"),
)
SPECIAL = {"compose.yml", ".env.prod"}


@dataclass(frozen=True)
class Change:
    host: str
    service: str
    change: str
    action: str
    reason: str
    availability: str
    validation: str
    rollback: str

    def row(self) -> dict[str, str]:
        return asdict(self)

    def line(self) -> str:
        return f"{self.host}: {self.service}: {self.action}: {self.change}"


def _sha(data: bytes) -> str:
    return hashlib.sha256(data).hexdigest()


def env_digests(text: str) -> dict[str, str]:
    """Digest of each KEY=VALUE line; the value itself is never kept."""
    out: dict[str, str] = {}
    for line in text.splitlines():
        if line.strip() and not line.lstrip().startswith("#") and "=" in line:
            key, _, value = line.partition("=")
            out[key.strip()] = _sha(value.encode())
    return out


def service_hashes(bundle: Path) -> dict[str, str] | None:
    """Compose's own configuration hash of each service, or None when Docker Compose is not available."""
    if shutil.which("docker") is None or not (bundle / "compose.yml").exists():
        return None
    done = subprocess.run(["docker", "compose", "--env-file", ".env.prod", "--profile", "*", "config", "--hash", "*"], cwd=bundle,
                          capture_output=True, text=True, check=False, timeout=60)
    if done.returncode != 0:
        return None
    return {parts[0]: parts[1] for parts in (line.split() for line in done.stdout.splitlines()) if len(parts) == 2}


def profile(bundle: Path) -> dict[str, Any]:
    files = {str(p.relative_to(bundle)): _sha(p.read_bytes()) for p in sorted(bundle.rglob("*")) if p.is_file()}
    env_file = bundle / ".env.prod"
    return {"files": files, "env": env_digests(env_file.read_text(encoding="utf-8")) if env_file.exists() else {}, "services": service_hashes(bundle)}


def _rule(path: str):
    for rule in RULES:
        if path == rule[0] or (rule[0].endswith(("/", "-", "migrat")) and path.startswith(rule[0])):
            return rule
    return None


def plan_node(host: str, before: dict[str, Any] | None, after: dict[str, Any]) -> list[Change]:
    """One row per affected service or host step. An empty list means nothing is touched."""
    if before is None:
        return [Change(host, "node", "no baseline of the running bundle", "node", "Nothing records what this host last ran, so every input is treated as changed", "Services restart per node gate", "Node gate", "The previous bundle")]
    rows: list[Change] = []
    changed = sorted(p for p in set(before["files"]) | set(after["files"]) if before["files"].get(p) != after["files"].get(p))
    recreated: set[str] = set()
    if before["services"] is not None and after["services"] is not None:
        recreated = {s for s in set(before["services"]) | set(after["services"]) if before["services"].get(s) != after["services"].get(s)}
    else:
        if "compose.yml" in changed or ".env.prod" in changed:
            return rows + [Change(host, "node", "compose.yml or .env.prod changed and service hashes are unavailable", "node", "Without Compose's hashes the affected services cannot be named", "Services restart per node gate", "Node gate", "The previous bundle")]
    for path in changed:
        if path in SPECIAL:
            continue
        rule = _rule(path)
        if rule is None:
            rows.append(Change(host, "node", f"{path} changed and is not in the impact map", "node", "An input the map does not know is never assumed harmless", "Services restart per node gate", "Node gate", "The previous bundle"))
            continue
        _, kind, services, action, reason, availability, validation, rollback = rule
        if action == "none":
            continue
        present = after["services"]
        # A service the node does not run has nothing to change; a file that only such services read needs no action here.
        named = tuple(sv for sv in services if sv == "host" or present is None or sv in present)
        if services and not named:
            continue
        for service in named or ("node",):
            rows.append(Change(host, service, kind, action, reason, availability, validation, rollback))
    if "compose.yml" in changed or ".env.prod" in changed:
        keys = sorted(k for k in set(before["env"]) | set(after["env"]) if before["env"].get(k) != after["env"].get(k))
        for service in sorted(recreated):
            what = "configuration"
            if keys and ".env.prod" in changed:
                what = "environment: " + ", ".join(keys[:6]) + (f" and {len(keys) - 6} more" if len(keys) > 6 else "")
            rows.append(Change(host, service, what, "recreate", "Compose reports a different configuration for this service only", "This service restarts in dependency order; other services are not touched",
                               "The service health gate", "The previous bundle and its image are restored for this service"))
        if not recreated and keys:
            rows.append(Change(host, "none", "environment: " + ", ".join(keys[:6]) + " (no service reads it)", "none", "No service's configuration changed", "None", "None", "None"))
    seen: set[tuple[str, str, str]] = set()
    unique = []
    for row in rows:
        key = (row.service, row.action, row.change)
        if key not in seen:
            seen.add(key)
            unique.append(row)
    return sorted(unique, key=lambda r: (LADDER.index(r.action), r.service))


def widest(rows: list[Change]) -> str:
    return max((r.action for r in rows), key=LADDER.index, default="none")


def live_items(rows: list[Change]) -> list[tuple[str, str]]:
    """The (service, action) pairs of a narrow activation, from the rows of one host. A firewall row is the pair ("host", "firewall")."""
    return sorted({(r.service, r.action) for r in rows if r.action in {"firewall", "file", "reload", "restart", "recreate"}},
                  key=lambda item: (LADDER.index(item[1]), item[0]))
