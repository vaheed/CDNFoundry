from __future__ import annotations

import copy
import json
import os
import shutil
import tempfile
from pathlib import Path
from typing import Any

from .certs import PKI
from .common import RenderError, atomic_json, atomic_write, quote_env, sha256_file, utc_now
from .compose import (
    bind_mount_sources,
    dump_yaml,
    load_yaml,
    prune_top_level,
    required_env,
    select_services,
)
from .state import FleetState

class Renderer:
    def __init__(self, repo_root: Path, store: FleetState, output_dir: Path, *, dry_run: bool = False) -> None:
        self.repo_root = repo_root.resolve()
        self.store = store
        self.output_dir = output_dir.resolve()
        self.dry_run = dry_run
        self.pki = PKI(store.pki_dir, dry_run=dry_run)

    def render(self, state: dict[str, Any], *, node_name: str | None = None) -> list[Path]:
        names = [node_name] if node_name else sorted(state["nodes"])
        rendered: list[Path] = []
        for name in names:
            if name not in state["nodes"]:
                raise RenderError(f"Unknown node: {name}")
            node = state["nodes"][name]
            if not node.get("enabled", True):
                continue
            rendered.append(self._render_node(state, node))
        self._render_fleet_files(state)
        return rendered

    def _render_node(self, state: dict[str, Any], node: dict[str, Any]) -> Path:
        base = load_yaml(self.repo_root / "compose.prod.yml")
        monitoring_enabled = state["features"]["monitoring"]["mode"] != "disabled"
        monitoring_host = self._is_monitoring_host(state, node)
        logs_enabled = state["features"]["logs"]["mode"] == "centralized"
        filtered = select_services(
            base,
            role=node["role"],
            monitoring_enabled=monitoring_enabled,
            logs_enabled=logs_enabled,
            monitoring_host=monitoring_host,
        )
        self._apply_generated_overrides(state, node, filtered)
        prune_top_level(filtered)
        env = self._environment(state, node, required_env(filtered), monitoring_host=monitoring_host)

        destination = self.output_dir / node["name"]
        if self.dry_run:
            return destination
        self.output_dir.mkdir(parents=True, exist_ok=True, mode=0o700)
        self.output_dir.chmod(0o700)
        with tempfile.TemporaryDirectory(prefix=f".{node['name']}.", dir=self.output_dir) as tmp_dir:
            tmp = Path(tmp_dir)
            self._copy_runtime_files(filtered, tmp)
            self.pki.copy_node_material(node, tmp / "pki")
            atomic_write(tmp / "compose.yml", dump_yaml(filtered), 0o600)
            atomic_write(tmp / ".env.prod", self._format_env(env), 0o600)
            self._write_generated_configs(state, node, tmp, monitoring_host)
            atomic_write(tmp / "README.md", self._node_readme(state, node, filtered), 0o600)
            atomic_write(tmp / "validate.sh", self._validate_script(), 0o700)
            atomic_write(tmp / "start.sh", self._start_script(node), 0o700)
            self._write_manifest(tmp, state, node)
            previous = destination.with_name(destination.name + ".previous")
            if previous.exists():
                shutil.rmtree(previous)
            if destination.exists():
                os.replace(destination, previous)
            os.replace(tmp, destination)
        return destination

    def _is_monitoring_host(self, state: dict[str, Any], node: dict[str, Any]) -> bool:
        feature = state["features"]["monitoring"]
        if feature["mode"] == "colocated":
            return node["role"] == "control"
        if feature["mode"] == "dedicated":
            return feature.get("host") == node["name"]
        return False

    def _apply_generated_overrides(self, state: dict[str, Any], node: dict[str, Any], compose: dict[str, Any]) -> None:
        services = compose.get("services", {})
        if "node-exporter" in services:
            bind = node.get("monitor_ipv4") or node["bind_ipv4"]
            ports = [f"{bind}:9100:9100/tcp"]
            if node.get("monitor_ipv6"):
                ports.append(f"[{node['monitor_ipv6']}]:9100:9100/tcp")
            services["node-exporter"]["ports"] = ports
        if node.get("bind_ipv6"):
            self._add_ipv6_publications(services, node["bind_ipv6"])
        if "log-collector" in services:
            service = services["log-collector"]
            service["command"] = ["--config", "/etc/vector/generated-node.yaml"]
            volumes = [v for v in service.get("volumes", []) if "/etc/vector/operational.yaml" not in str(v)]
            volumes.append("./generated/vector-node.yaml:/etc/vector/generated-node.yaml:ro")
            service["volumes"] = volumes
            service["healthcheck"] = {
                "test": ["CMD", "vector", "validate", "--no-environment", "/etc/vector/generated-node.yaml"],
                "interval": "30s",
                "timeout": "5s",
                "retries": 3,
            }
        if node["role"] in {"dns", "dns-edge"}:
            if "pdns-db" not in services or "pdns-auth" not in services:
                raise RenderError(f"DNS node {node['name']} does not contain local pdns-db and pdns-auth services")
            # Explicitly prevent accidental use of the control database.
            pdns_env = services["pdns-auth"].setdefault("environment", {})
            pdns_env["PDNS_gpgsql_host"] = "pdns-db"
            pdns_env["PDNS_gpgsql_dbname"] = "pdns"
            pdns_env["PDNS_gpgsql_user"] = "pdns"
        if node["role"] == "control" and self._uses_remote_control_db(node):
            # External PostgreSQL is selected with DB_URL or a DB_HOST other than the
            # Compose service name. Remove the embedded database and every dependency
            # edge to it; Valkey remains host-local unless REDIS_URL/REDIS_HOST is
            # overridden separately.
            services.pop("control-db", None)
            for service in services.values():
                depends = service.get("depends_on")
                if isinstance(depends, dict):
                    depends.pop("control-db", None)
                elif isinstance(depends, list):
                    service["depends_on"] = [name for name in depends if name != "control-db"]

        if node["role"] == "monitoring":
            # A dedicated telemetry host must not accidentally start a second control database.
            # The repository's Grafana control-DB provisioning helper depends on control-db, so
            # omit that helper on a dedicated host and leave telemetry dashboards operational.
            services.pop("grafana-control-db-provision", None)
            services.pop("control-db", None)
            for service in services.values():
                depends = service.get("depends_on")
                if isinstance(depends, dict):
                    depends.pop("grafana-control-db-provision", None)
                    depends.pop("control-db", None)
                elif isinstance(depends, list):
                    service["depends_on"] = [
                        name for name in depends if name not in {"grafana-control-db-provision", "control-db"}
                    ]

    def _environment(
        self,
        state: dict[str, Any],
        node: dict[str, Any],
        needed: set[str],
        *,
        monitoring_host: bool,
    ) -> dict[str, str]:
        operator_domain = state["global"]["operator_domain"]
        values: dict[str, str] = {
            "CDNF_RELEASE": node["release"],
            "HOST_BIND_IPV4": node["bind_ipv4"],
            "HOST_BIND_IPV6": node.get("bind_ipv6") or "::",
            "DNS_BIND_V4": node["bind_ipv4"],
            "APP_URL": f"https://control.{operator_domain}",
            "CONTROL_HOSTNAME": f"control.{operator_domain}",
            "TELEMETRY_HOSTNAME": f"telemetry.{operator_domain}",
            "GRAFANA_HOSTNAME": f"grafana.{operator_domain}",
            "APP_KEY": self.store.read_secret("app-key"),
            "EDGE_ARTIFACT_SIGNING_KEY": self.store.read_secret("artifact-signing-key"),
            "CONTROL_DB_PASSWORD": self.store.read_secret("control-db-password"),
            "REDIS_PASSWORD": self.store.read_secret("valkey-password"),
            "CLICKHOUSE_PASSWORD": self.store.read_secret("clickhouse-password"),
            "CLICKHOUSE_URL": self._clickhouse_url(state),
            "GRAFANA_ADMIN_PASSWORD": self.store.read_secret("grafana-admin-password"),
            "GRAFANA_CLICKHOUSE_PASSWORD": self.store.read_secret("grafana-clickhouse-password"),
            "GRAFANA_POSTGRES_PASSWORD": self.store.read_secret("grafana-postgres-password"),
            "METRICS_TOKEN_FILE": "./secrets/metrics-token",
            # Production Compose PKI contract (control, edge and DNS roles).
            "EDGE_IDENTITY_CA_CERTIFICATE": "./pki/edge-identity-ca.crt",
            "EDGE_IDENTITY_CA_PRIVATE_KEY": "./pki/edge-identity-ca.key",
            "PDNS_CA_CERTIFICATE": "./pki/edge-server-ca.crt",
            "EDGE_CONTROL_SERVER_CERTIFICATE": "./pki/node.crt",
            "EDGE_CONTROL_SERVER_PRIVATE_KEY": "./pki/node.key",
            "EDGE_CONTROL_CA_CERTIFICATE": "./pki/edge-server-ca.crt",
            "EDGE_CONTROL_URL": self._edge_control_url(state),
            "EDGE_RUNTIME_TLS_CERTIFICATE": "./pki/node.crt",
            "EDGE_RUNTIME_TLS_PRIVATE_KEY": "./pki/node.key",
            "DNS_API_SERVER_CERTIFICATE": "./pki/node.crt",
            "DNS_API_SERVER_PRIVATE_KEY": "./pki/node.key",
            "DNS_API_HOSTNAME": node["hostname"],
            "CONTROL_PUBLIC_IPV4_ALLOWLIST": self._control_allowlist(state),
            "EDGE_PUBLIC_IPV4_ALLOWLIST": self._edge_allowlist(state),
            "LOG_SOURCE_IPV4_ALLOWLIST": self._all_public_ipv4(state),
            "CONTROL_PUBLIC_IPV6_ALLOWLIST": self._control_allowlist(state, family=6),
            "EDGE_PUBLIC_IPV6_ALLOWLIST": self._edge_allowlist(state, family=6),
            "LOG_SOURCE_IPV6_ALLOWLIST": self._all_public_ipv6(state),
            "LOG_ROLE": node["role"],
            "LOG_HOST": node["name"],
            "LOG_COLLECTOR_ID": node["name"],
            "LOKI_ENDPOINT": self._loki_url(state),
            "LOG_AUTH_TOKEN": self._optional_node_secret("log-auth-token", node),
            "NODE_EXPORTER_TOKEN": self.store.read_secret("node-exporter-token", node=node["name"]),
            "EDGE_STATUS_TOKEN": self._optional_node_secret("edge-status-token", node),
            "EDGE_ID": node.get("edge_id") or "",
            "EDGE_BOOTSTRAP_TOKEN": self._optional_node_secret("edge-bootstrap-token", node),
            "PDNS_DB_PASSWORD": self._optional_node_secret("pdns-db-password", node),
            "PDNS_API_KEY": self._optional_node_secret("pdns-api-key", node),
            "RESTIC_REPOSITORY": state["features"]["backups"].get("repository") or "",
            "RESTIC_PASSWORD_FILE": "./secrets/restic-password",
            "BACKUP_ACCESS_KEY_ID": self.store.read_secret("backup-access-key"),
            "BACKUP_SECRET_ACCESS_KEY": self.store.read_secret("backup-secret-key"),
            "BACKUP_DEFAULT_REGION": state["features"]["backups"].get("region") or "us-east-1",
            "ACME_CONTACT_EMAIL": state["global"].get("acme_email", ""),
            "SESSION_SECURE_COOKIE": "true",
            "CONTROL_BIND": "127.0.0.1:8080",
            "DB_URL": "",
            "DB_HOST": "control-db",
            "DB_PORT": "5432",
            "DB_SSLMODE": "prefer",
            "REDIS_URL": "",
            "REDIS_HOST": "redis",
            "REDIS_PORT": "6379",
            "ACME_DIRECTORY_URL": "https://acme-v02.api.letsencrypt.org/directory",
            "ACME_ORDER_BUDGET_PER_HOUR": "20",
            "EDGE_IDENTITY_CA_PRIVATE_KEY_PASSPHRASE": "",
            "GRAFANA_EXPLORE_URL": f"https://grafana.{operator_domain}/explore?left=%7B%22datasource%22:%22loki%22%7D",
            "EDGE_CONTROL_BIND": "0.0.0.0:8443",
            "EDGE_RUNTIME_VERSIONS": "{}",
            "EDGE_GATEWAY_METRICS_ADDRESS": "0.0.0.0:9105",
            "EDGE_GATEWAY_MAX_CONNECTIONS": "8192",
            "EDGE_GATEWAY_STATUS_URL": "http://host-gateway:9105/metrics",
            "EDGE_GATEWAY_ADDRESS_MAP": "{}",
            "MMDB_PROVIDER": "dbip-jsdelivr",
            "MMDB_TARGET_FILE": "GeoLite2-City.mmdb",
            "MMDB_DOWNLOAD_INTERVAL_SECONDS": "86400",
            "MMDB_DOWNLOAD_RETRIES": "5",
            "MMDB_EXPECTED_SHA256": "",
            "MMDB_DOWNLOAD_URL": "",
            "MMDB_DOWNLOAD_HEADER": "",
            "LOG_BUFFER_BYTES": "2147483648",
            "LOG_METRICS_BIND": "127.0.0.1:9599",
            "LOKI_RETENTION_PERIOD": "336h",
            "LOKI_MAX_QUERY_LENGTH": "336h",
            "PROMETHEUS_EDGE_TARGETS_FILE": "./docker/prometheus/edge-targets.prod.yml",
            "PROMETHEUS_LOG_TARGETS_FILE": "./docker/prometheus/operational-log-targets.prod.yml",
            "GRAFANA_ADMIN_USER": "admin",
            "GRAFANA_BIND": "127.0.0.1:3000",
            "GRAFANA_COOKIE_SECURE": "true",
            "GRAFANA_LOKI_URL": "http://loki:3100",
            "GRAFANA_CLICKHOUSE_HOST": "clickhouse",
            "GRAFANA_CLICKHOUSE_PORT": "9000",
            "GRAFANA_CLICKHOUSE_PROTOCOL": "native",
            "GRAFANA_CLICKHOUSE_SECURE": "false",
            "GRAFANA_CLICKHOUSE_USER": "cdnf_grafana",
            "GRAFANA_POSTGRES_HOST": "control-db",
            "GRAFANA_POSTGRES_PORT": "5432",
            "GRAFANA_POSTGRES_DATABASE": "cdnf",
            "GRAFANA_POSTGRES_USER": "cdnf_grafana",
            "GRAFANA_POSTGRES_SSLMODE": "disable",
            "GRAFANA_POSTGRES_PROVISION_HOST": "control-db",
            "GRAFANA_POSTGRES_PROVISION_PORT": "5432",
        }
        explicit_env = node.get("extra_env", {})
        derived_env: set[str] = set()
        values.update(explicit_env)
        if node["role"] == "control" and self._uses_remote_control_db(node):
            # Keep Grafana's control-database provisioning and datasource pointed at
            # the same external PostgreSQL endpoint unless the operator overrides them.
            remote_host = values.get("DB_HOST", "")
            remote_port = values.get("DB_PORT", "5432")
            remote_sslmode = values.get("DB_SSLMODE", "verify-full")
            if remote_host:
                defaults = {
                    "GRAFANA_POSTGRES_PROVISION_HOST": remote_host,
                    "GRAFANA_POSTGRES_PROVISION_PORT": remote_port,
                    "GRAFANA_POSTGRES_HOST": remote_host,
                    "GRAFANA_POSTGRES_PORT": remote_port,
                    "GRAFANA_POSTGRES_SSLMODE": remote_sslmode,
                }
                for key, value in defaults.items():
                    if key not in explicit_env:
                        values[key] = value
                        derived_env.add(key)
        missing = sorted(key for key in needed if key not in values)
        if missing:
            raise RenderError(
                f"Node {node['name']} is missing required environment values: {', '.join(missing)}; use extra_env"
            )
        # Minimal role environment: only variables referenced by the filtered Compose plus generated configs.
        always = {"CDNF_RELEASE", "HOST_BIND_IPV4", "HOST_BIND_IPV6"}
        if state["features"]["logs"]["mode"] == "centralized":
            always |= {"LOG_ROLE", "LOG_HOST", "LOG_COLLECTOR_ID", "LOKI_ENDPOINT", "LOG_AUTH_TOKEN"}
        if state["features"]["monitoring"]["mode"] != "disabled":
            always |= {"NODE_EXPORTER_TOKEN"}
        # Operator-supplied values are deliberate overrides. Include them even when
        # Compose gives the variable a default (`${VAR:-...}`), otherwise edge
        # enrollment, gateway address maps, MMDB tuning, and remote dependencies are
        # silently lost from the generated bundle.
        explicit = set(explicit_env) | derived_env
        if values.get("EDGE_BOOTSTRAP_TOKEN"):
            explicit.add("EDGE_BOOTSTRAP_TOKEN")
        return {
            key: values[key]
            for key in sorted(needed | always | explicit)
            if key in values and (values[key] != "" or key in needed)
        }

    def _optional_node_secret(self, name: str, node: dict[str, Any]) -> str:
        path = self.store.secret_path(name, node=node["name"])
        return self.store.read_secret(name, node=node["name"]) if path.exists() else ""

    @staticmethod
    def _uses_remote_control_db(node: dict[str, Any]) -> bool:
        extra = node.get("extra_env", {})
        if str(extra.get("DB_URL", "")).strip():
            return True
        host = str(extra.get("DB_HOST", "")).strip()
        return bool(host and host != "control-db")

    def _edge_control_url(self, state: dict[str, Any]) -> str:
        control = next(
            (
                item
                for item in state["nodes"].values()
                if item["role"] == "control" and item.get("enabled", True)
            ),
            None,
        )
        hostname = control["hostname"] if control else f"control.{state['global']['operator_domain']}"
        return f"https://{hostname}:8443"

    @staticmethod
    def _add_ipv6_publications(services: dict[str, Any], bind: str) -> None:
        mappings = {
            "caddy": [(80, "tcp"), (443, "tcp"), (443, "udp"), (8444, "tcp")],
            "dns-api": [(8444, "tcp")],
            "telemetry-gateway": [(80, "tcp"), (443, "tcp"), (8444, "tcp")],
            "dnsdist": [(53, "tcp"), (53, "udp")],
            "edge-gateway": [(80, "tcp"), (443, "tcp"), (443, "udp")],
        }
        for service_name, ports in mappings.items():
            if service_name in services:
                current = services[service_name].setdefault("ports", [])
                current.extend(f"[{bind}]:{port}:{port}/{protocol}" for port, protocol in ports)

    def _control_allowlist(self, state: dict[str, Any], *, family: int = 4) -> str:
        field = f"public_ipv{family}"
        return " ".join(
            node[field] for node in state["nodes"].values()
            if node["role"] == "control" and node["enabled"] and node.get(field)
        )

    def _edge_allowlist(self, state: dict[str, Any], *, family: int = 4) -> str:
        field = f"public_ipv{family}"
        return " ".join(
            node[field]
            for node in state["nodes"].values()
            if node["role"] in {"edge", "dns-edge"} and node["enabled"] and node.get(field)
        )

    def _all_public_ipv4(self, state: dict[str, Any]) -> str:
        return " ".join(node["public_ipv4"] for node in state["nodes"].values() if node["enabled"])

    def _all_public_ipv6(self, state: dict[str, Any]) -> str:
        return " ".join(node["public_ipv6"] for node in state["nodes"].values() if node["enabled"] and node.get("public_ipv6"))

    def _feature_host(self, state: dict[str, Any], feature: str) -> dict[str, Any] | None:
        cfg = state["features"][feature]
        if cfg["mode"] == "disabled":
            return None
        if cfg["mode"] == "colocated":
            return next((node for node in state["nodes"].values() if node["role"] == "control"), None)
        name = cfg.get("host")
        return state["nodes"].get(name) if name else None

    def _clickhouse_url(self, state: dict[str, Any]) -> str:
        host = self._feature_host(state, "monitoring")
        return f"https://{host['hostname']}:8444" if host else "http://127.0.0.1:8123"

    def _loki_url(self, state: dict[str, Any]) -> str:
        cfg = state["features"]["logs"]
        if cfg.get("endpoint"):
            return cfg["endpoint"]
        host = state["nodes"].get(cfg.get("host")) if cfg.get("host") else None
        return f"https://{host['hostname']}:8444" if host else "http://127.0.0.1:3100"

    def _format_env(self, env: dict[str, str]) -> str:
        return "".join(f"{key}={quote_env(value)}\n" for key, value in sorted(env.items()))

    def _copy_runtime_files(self, compose: dict[str, Any], destination: Path) -> None:
        for relative in sorted(bind_mount_sources(compose)):
            if relative.parts and relative.parts[0] in {'generated', 'pki', 'secrets'}:
                continue
            source = (self.repo_root / relative).resolve()
            try:
                source.relative_to(self.repo_root)
            except ValueError as exc:
                raise RenderError(f"Compose bind mount escapes repository: {relative}") from exc
            if not source.exists():
                raise RenderError(f"Missing runtime file referenced by Compose: {relative}")
            target = destination / relative
            target.parent.mkdir(parents=True, exist_ok=True)
            if source.is_dir():
                shutil.copytree(source, target, dirs_exist_ok=True)
            else:
                shutil.copy2(source, target)

    def _write_generated_configs(
        self, state: dict[str, Any], node: dict[str, Any], destination: Path, monitoring_host: bool
    ) -> None:
        secrets_dir = destination / "secrets"
        secrets_dir.mkdir(parents=True, exist_ok=True, mode=0o700)
        if state["features"]["monitoring"]["mode"] != "disabled":
            atomic_write(secrets_dir / "metrics-token", self.store.read_secret("metrics-token") + "\n", 0o600)
        if state["features"]["backups"]["mode"] != "disabled":
            atomic_write(secrets_dir / "restic-password", self.store.read_secret("backup-password") + "\n", 0o600)
        if state["features"]["logs"]["mode"] == "centralized":
            atomic_write(destination / "generated/vector-node.yaml", self._vector_config(node), 0o600)
        if node["role"] in {"dns", "dns-edge"}:
            pending = self.store.pending_secret_path("pdns-db-password", node=node["name"])
            if pending.exists():
                atomic_write(
                    secrets_dir / "pdns-db-password.next",
                    pending.read_text(encoding="utf-8"),
                    0o600,
                )
                atomic_write(
                    destination / "reconcile-pdns-password.sh",
                    self._pdns_reconciliation_script(),
                    0o700,
                )
        if monitoring_host:
            generated = destination / "generated"
            atomic_write(generated / "prometheus-node-targets.yml", self._prometheus_targets(state), 0o600)
            atomic_write(generated / "geo-routing-policy.json", json.dumps(self._geo_policy(state), indent=2) + "\n", 0o600)

    def _pdns_reconciliation_script(self) -> str:
        return r'''#!/usr/bin/env sh
set -eu
umask 077

cd "$(dirname "$0")"
test -f .env.prod
test -f secrets/pdns-db-password.next

# The generated env file is shell-compatible and contains the currently active password.
set -a
. ./.env.prod
set +a
next_password=$(cat secrets/pdns-db-password.next)
test -n "$PDNS_DB_PASSWORD"
test -n "$next_password"

cp -p .env.prod .env.prod.before-pdns-rotation
chmod 600 .env.prod.before-pdns-rotation

docker compose --env-file .env.prod exec -T \
  -e PGPASSWORD="$PDNS_DB_PASSWORD" \
  -e NEXT_PDNS_DB_PASSWORD="$next_password" \
  pdns-db sh -eu -c '
    psql -v ON_ERROR_STOP=1 \
      -U "${POSTGRES_USER:-pdns}" \
      -d "${POSTGRES_DB:-pdns}" \
      -v next_password="$NEXT_PDNS_DB_PASSWORD" \
      -c "ALTER ROLE pdns PASSWORD :'"'"'next_password'"'"';"
  '

python3 - "$next_password" <<'PY'
import os
import sys
from pathlib import Path

value = sys.argv[1]
path = Path(".env.prod")
tmp = path.with_name(".env.prod.next")
lines = path.read_text(encoding="utf-8").splitlines()
updated = False
with tmp.open("w", encoding="utf-8", newline="\n") as handle:
    for line in lines:
        if line.startswith("PDNS_DB_PASSWORD="):
            handle.write(f"PDNS_DB_PASSWORD={value}\n")
            updated = True
        else:
            handle.write(line + "\n")
    if not updated:
        raise SystemExit("PDNS_DB_PASSWORD is missing from .env.prod")
    handle.flush()
    os.fsync(handle.fileno())
os.chmod(tmp, 0o600)
os.replace(tmp, path)
PY

docker compose --env-file .env.prod config --quiet
printf '%s\n' 'Local PostgreSQL and .env.prod now use the pending password.'
printf '%s\n' 'On the control plane, commit the rotation, rerender this node, transfer the new bundle, and run docker compose up -d.'
'''

    def _vector_config(self, node: dict[str, Any]) -> str:
        return f'''data_dir: /vector-data-dir
sources:
  docker:
    type: docker_logs
  journal:
    type: journald
    journal_directory: /var/log/journal
transforms:
  redact:
    type: remap
    inputs: [docker, journal]
    source: |
      .fleet_node = "{node['name']}"
      .fleet_role = "{node['role']}"
      del(.label.com_docker_compose_config_hash)
      if exists(.message) {{ .message = redact(string!(.message), filters: [r'(?i)(password|token|secret|api[_-]?key)=\\S+']) }}
sinks:
  central:
    type: loki
    inputs: [redact]
    endpoint: "${{LOKI_ENDPOINT}}"
    auth:
      strategy: bearer
      token: "${{LOG_AUTH_TOKEN}}"
    encoding:
      codec: json
    labels:
      node: "{node['name']}"
      role: "{node['role']}"
    buffer:
      type: disk
      max_size: 1073741824
      when_full: drop_newest
    request:
      retry_max_duration_secs: 300
      timeout_secs: 10
healthchecks:
  enabled: true
'''

    def _prometheus_targets(self, state: dict[str, Any]) -> str:
        groups = []
        for node in sorted(state["nodes"].values(), key=lambda item: item["name"]):
            if not node["enabled"]:
                continue
            address = node.get("monitor_ipv4") or node["public_ipv4"]
            groups.append(
                {
                    "targets": [f"{address}:9100"],
                    "labels": {
                        "node": node["name"],
                        "role": node["role"],
                        "region": node["region"],
                        "location": node["location"],
                    },
                }
            )
        import yaml

        return yaml.safe_dump(groups, sort_keys=False)

    def _geo_policy(self, state: dict[str, Any]) -> dict[str, Any]:
        edges = []
        for node in state["nodes"].values():
            if node["role"] not in {"edge", "dns-edge"}:
                continue
            edges.append(
                {
                    "name": node["name"],
                    "region": node["region"],
                    "location": node["location"],
                    "ipv4": node["public_ipv4"],
                    "ipv6": node.get("public_ipv6"),
                    "enabled": node["enabled"],
                    "draining": node["draining"],
                    "failure_threshold": node["health"]["failure_threshold"],
                    "success_threshold": node["health"]["success_threshold"],
                    "stale_after_seconds": node["health"]["stale_after_seconds"],
                }
            )
        return {
            "decision_order": [
                "valid_ecs_client_subnet",
                "resolver_ip_fallback",
                "country_and_asn_policy",
                "health_filtering",
                "preferred_healthy_edge_ip",
            ],
            "selection": "deterministic",
            "address_families": "independent",
            "edges": sorted(edges, key=lambda item: item["name"]),
        }

    def _node_readme(self, state: dict[str, Any], node: dict[str, Any], compose: dict[str, Any]) -> str:
        services = ", ".join(compose.get("services", {}))
        listeners = self._listeners(node)
        start_order = self._node_start_order(node)
        database_mode = "external PostgreSQL" if self._uses_remote_control_db(node) else "embedded PostgreSQL"
        return f"""# CDNFoundry node bundle: {node['name']}

- Role: `{node['role']}`
- Region: `{node['region']}`
- Location: `{node['location']}`
- Release: `{node['release']}`
- Services: {services}
- Control database mode: {database_mode if node['role'] == 'control' else 'not applicable'}

This bundle intentionally contains only files and credentials needed by this node. For DNS roles, `pdns-db` is the node-local PostgreSQL service and `pdns-auth` connects only to `pdns-db`; it never uses the control-plane PostgreSQL service.

## Requirements

Docker Engine, Docker Compose v2, accurate system time, CA certificates, and sufficient disk for stateful volumes. Keep the directory mode `0700` and `.env.prod`, private keys, and secret files mode `0600`.

## Validate and start

If this bundle contains `reconcile-pdns-password.sh`, a PowerDNS database password rotation is pending. Run that script on this DNS host before committing the rotation on the control plane. Normal rerenders do not rotate credentials.

Run on **{node['name']}**:

```sh
cd /opt/cdnfoundry
./validate.sh
{start_order}
docker compose --env-file .env.prod up -d
docker compose --env-file .env.prod ps
```

## Listeners

{listeners}

Restrict management, metrics, logging, database, and DNS API ports to their exact trusted source addresses. Databases must never be publicly reachable.

## Health checks

```sh
docker compose --env-file .env.prod ps
docker compose --env-file .env.prod logs --since 10m --no-color
```

## Upgrade and rollback

Replace the bundle atomically, run `./validate.sh`, pull images, then use `docker compose --env-file .env.prod up -d`. If validation or startup fails, restore the `.previous` bundle and rerun the same command. Never use `docker compose down -v` and never delete PostgreSQL, Valkey, ClickHouse, Loki, Prometheus, Grafana, edge-state, cache, or MMDB volumes.

## Cleanup

After successful validation and the retention period, securely remove obsolete transferred archives. Do not remove the active directory, `.previous` rollback bundle, state volumes, or protected recovery copies.
"""

    def _listeners(self, node: dict[str, Any]) -> str:
        rows = ["| Listener | Exposure |", "| --- | --- |"]
        role = node["role"]
        if role in {"dns", "dns-edge"}:
            rows.append("| UDP/TCP 53 | Public authoritative DNS through DNSdist |")
            rows.append("| TCP 8444 | Control-plane source addresses only |")
        if role in {"edge", "dns-edge"}:
            rows.append("| TCP 80/443 | Public customer traffic on configured service addresses |")
        if role == "control":
            rows.append("| TCP 80/443 | Public operator UI/API |")
            rows.append("| TCP 8443 | Edge nodes only |")
        rows.append("| TCP 9100 | Monitoring host/private monitoring network only |")
        return "\n".join(rows)

    def _node_start_order(self, node: dict[str, Any]) -> str:
        if node["role"] in {"dns", "dns-edge"}:
            return (
                "docker compose --env-file .env.prod up -d --wait pdns-db\n"
                "docker compose --env-file .env.prod --profile tools run --rm pdns-migrate"
            )
        if node["role"] == "control":
            dependencies = "redis" if self._uses_remote_control_db(node) else "control-db redis"
            return (
                f"docker compose --env-file .env.prod up -d --wait {dependencies}\n"
                "docker compose --env-file .env.prod --profile tools run --rm migrate"
            )
        return "# No database migration is required for this role."

    def _validate_script(self) -> str:
        return """#!/usr/bin/env sh
set -eu
umask 077
test "$(stat -c '%a' .env.prod)" = 600
test "$(stat -c '%a' pki/node.key)" = 600
docker compose --env-file .env.prod config --quiet
openssl verify -CAfile pki/edge-server-ca.crt pki/node.crt
"""

    def _start_script(self, node: dict[str, Any]) -> str:
        migration = self._node_start_order(node)
        return f"""#!/usr/bin/env sh
set -eu
./validate.sh
{migration}
docker compose --env-file .env.prod up -d
docker compose --env-file .env.prod ps
"""

    def _write_manifest(self, root: Path, state: dict[str, Any], node: dict[str, Any]) -> None:
        files = []
        for path in sorted(p for p in root.rglob("*") if p.is_file()):
            relative = path.relative_to(root).as_posix()
            files.append({"path": relative, "sha256": sha256_file(path), "mode": oct(path.stat().st_mode & 0o777)})
        metadata = {
            "schema_version": 1,
            "node": node["name"],
            "role": node["role"],
            "release": node["release"],
            "rendered_at": utc_now(),
            "fleet_generation": state["metadata"]["generation"],
            "files": files,
        }
        atomic_json(root / "bundle-metadata.json", metadata, 0o600)
        checksums = "".join(f"{entry['sha256']}  {entry['path']}\n" for entry in files)
        atomic_write(root / "SHA256SUMS", checksums, 0o600)

    def _render_fleet_files(self, state: dict[str, Any]) -> None:
        if self.dry_run:
            return
        self.output_dir.mkdir(parents=True, exist_ok=True, mode=0o700)
        lines = ["# CDNFoundry fleet startup order", ""]
        order = self.start_order(state)
        for index, group in enumerate(order, 1):
            lines.append(f"## {index}. {group['label']}")
            lines.append("")
            lines.append(", ".join(group["nodes"]) or "None configured")
            lines.append("")
        lines.extend(
            [
                "Validate each node bundle before startup. Start stateful databases and migrations before dependent services. Existing DNS and HTTP serving nodes keep their last valid state when the control plane is unavailable.",
                "",
            ]
        )
        atomic_write(self.output_dir / "STARTUP-ORDER.md", "\n".join(lines), 0o600)

    @staticmethod
    def start_order(state: dict[str, Any]) -> list[dict[str, Any]]:
        enabled = [node for node in state["nodes"].values() if node["enabled"]]
        groups = [
            {"label": "Dedicated monitoring data services", "nodes": [n["name"] for n in enabled if n["role"] == "monitoring"]},
            {"label": "Control-plane database, Valkey, migrations, and control services", "nodes": [n["name"] for n in enabled if n["role"] == "control"]},
            {"label": "Local PostgreSQL, PowerDNS migrations, and authoritative DNS", "nodes": [n["name"] for n in enabled if n["role"] in {"dns", "dns-edge"}]},
            {"label": "Edge runtime and gateways", "nodes": [n["name"] for n in enabled if n["role"] in {"edge", "dns-edge"}]},
            {"label": "Monitoring exporters and centralized log collectors", "nodes": [n["name"] for n in enabled]},
        ]
        return groups
