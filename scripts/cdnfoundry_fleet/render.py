from __future__ import annotations

import copy
import json
import os
import shutil
import tempfile
from pathlib import Path
from typing import Any

from . import ha, management as mg
from .certs import PKI
from .common import RenderError, atomic_write, quote_env, sync_tree, sync_directory, exchange_directories
from .firewall import fleet_addresses, render_firewall_script
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
        if self.dry_run:
            return self._render_generation(state, node_name=node_name)
        active = self.output_dir
        active.parent.mkdir(parents=True, exist_ok=True, mode=0o700)
        candidate = Path(tempfile.mkdtemp(prefix=f".{active.name}.candidate-", dir=active.parent))
        activated = False
        try:
            if active.exists():
                shutil.copytree(active, candidate, dirs_exist_ok=True, symlinks=True)
            self.output_dir = candidate
            paths = self._render_generation(state, node_name=node_name)
            sync_tree(candidate)
            if active.exists():
                exchange_directories(candidate, active)
                activated = True
                sync_directory(active.parent)
                # Keep a complete previous fleet generation, in addition to the
                # existing per-node .previous compatibility layout.
                previous = active.with_name(active.name + ".previous")
                if previous.exists():
                    shutil.rmtree(previous)
                os.replace(candidate, previous)
            else:
                os.replace(candidate, active)
                activated = True
            sync_directory(active.parent)
            return [active / path.relative_to(candidate) for path in paths]
        finally:
            self.output_dir = active
            # After exchange, candidate contains recovery material. If archival
            # failed, retain it for the operator instead of deleting it.
            if not activated and candidate.exists():
                shutil.rmtree(candidate)

    def _render_generation(self, state: dict[str, Any], *, node_name: str | None = None) -> list[Path]:
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
        if node["role"] == "monitoring" and state["features"]["monitoring"]["mode"] == "colocated":
            raise RenderError(
                f"{node['name']} has the monitoring role, but monitoring is colocated on the control node. "
                f"Run configure-monitoring --mode dedicated --host {node['name']}, or remove the node"
            )
        watcher = ha.is_watcher(state, node)
        logs_enabled = state["features"]["logs"]["mode"] == "centralized"
        filtered = select_services(
            base,
            role=node["role"],
            monitoring_enabled=monitoring_enabled,
            logs_enabled=logs_enabled,
            monitoring_host=monitoring_host or watcher,
        )
        if watcher:
            ha.restrict_to_watcher(filtered["services"])
        self._apply_generated_overrides(state, node, filtered)
        prune_top_level(filtered)
        env = self._environment(state, node, required_env(filtered), monitoring_host=monitoring_host or watcher)

        destination = self.output_dir / node["name"]
        if self.dry_run:
            return destination
        self.output_dir.mkdir(parents=True, exist_ok=True, mode=0o700)
        self.output_dir.chmod(0o700)
        with tempfile.TemporaryDirectory(prefix=f".{node['name']}.", dir=self.output_dir) as tmp_dir:
            tmp = Path(tmp_dir)
            self._copy_runtime_files(filtered, tmp)
            certificate_node = dict(node)
            if node["role"] == "control":
                certificate_node["additional_dns_names"] = [f"edge-control.{state['global']['operator_domain']}"]
            self.pki.copy_node_material(certificate_node, tmp / "pki", include_private_key=node["role"] not in {"edge", "dns-edge"})
            atomic_write(tmp / "compose.yml", dump_yaml(filtered), 0o600)
            atomic_write(tmp / ".env.prod", self._format_env(env), 0o600)
            self._write_generated_configs(state, node, tmp, monitoring_host or watcher)
            for filename, (text, mode) in self._own_tls_files(state, filtered.get("services", {})).items():
                atomic_write(tmp / "tls" / filename, text, mode)
            atomic_write(tmp / "README.md", self._node_readme(state, node, filtered), 0o600)
            atomic_write(tmp / "validate.sh", self._validate_script(node, filtered), 0o700)
            atomic_write(tmp / "firewall.sh", render_firewall_script(state, node), 0o700)
            atomic_write(tmp / "start.sh", self._start_script(state, node), 0o700)
            atomic_write(tmp / "stop.sh", self._stop_script(), 0o700)
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
            owner = ha.telemetry_owner(state)
            return node["role"] == "control" and (owner is None or owner == node["name"])
        if feature["mode"] == "dedicated":
            return feature.get("host") == node["name"]
        return False

    def _own_tls_files(self, state: dict[str, Any], services: dict[str, Any]) -> dict[str, tuple[str, int]]:
        """Operator-supplied certificates for the names this node's Caddy serves. The two-node control ingress certificate wins for `control`."""
        names = list(mg.MANAGEMENT_NAMES) if "caddy" in services else (["grafana", "telemetry"] if "telemetry-gateway" in services else [])
        if ha.section(state).get("ingress_certificate"):
            names = [n for n in names if n != "control"]
        return mg.caddy_files(self.store.state_dir, names)

    def _apply_generated_overrides(self, state: dict[str, Any], node: dict[str, Any], compose: dict[str, Any]) -> None:
        services = compose.get("services", {})
        if self._own_tls_files(state, services):
            for gateway in ("caddy", "telemetry-gateway"):
                if gateway in services:
                    services[gateway].setdefault("volumes", []).append("./tls:/etc/caddy/own-tls:ro")
        gateway_ip = node.get("extra_env", {}).get("EDGE_HOST_GATEWAY_IPV4")
        if gateway_ip and "edge-agent" in services:
            # Some Docker hosts materialize the symbolic host-gateway as the
            # literal text "invalid IP". Embed the inspected bridge address in
            # the generated bundle so later agent recreations retain it.
            services["edge-agent"]["extra_hosts"] = [f"host-gateway:{gateway_ip}"]
        if "prometheus" in services:
            # Fleet targets include other hosts; internal networks cannot route
            # those scrapes. Keep private service networks and add outbound access.
            networks = services["prometheus"].setdefault("networks", [])
            if "egress" not in networks:
                networks.append("egress")
            compose.setdefault("networks", {}).setdefault("egress", {})
        if "node-exporter" in services:
            bind = node.get("monitor_ipv4") or node["bind_ipv4"]
            ports = [f"{bind}:9100:9100/tcp"]
            if node.get("monitor_ipv6"):
                ports.append(f"[{node['monitor_ipv6']}]:9100:9100/tcp")
            services["node-exporter"]["ports"] = ports
            networks = services["node-exporter"].setdefault("networks", [])
            if "egress" not in networks:
                networks.append("egress")
        if "dnsdist" in services and state["features"]["monitoring"]["mode"] != "disabled":
            bind = node.get("monitor_ipv4") or node["bind_ipv4"]
            services["dnsdist"].setdefault("ports", []).append(f"{bind}:8083:8083/tcp")
        if node.get("bind_ipv6"):
            self._add_ipv6_publications(services, node["bind_ipv6"])
        if "log-collector" in services:
            service = services["log-collector"]
            service["command"] = ["--config", "/etc/vector/operational.yaml"]
            service["healthcheck"] = {
                "test": ["CMD", "vector", "validate", "--no-environment", "/etc/vector/operational.yaml"],
                "interval": "30s",
                "timeout": "5s",
                "retries": 3,
            }
        if node["role"] in {"dns", "dns-edge"}:
            # A DNS node is a PowerDNS secondary with a local SQLite store; it has no database service.
            if "pdns-auth" not in services or "pdns-db" in services:
                raise RenderError(f"DNS node {node['name']} must contain pdns-auth and no PowerDNS database")
            # The database migration belongs to the control node's primary.
            services.pop("pdns-migrate", None)
        if node["role"] == "control":
            if "pdns-db" not in services or "pdns-primary" not in services:
                raise RenderError(f"Control node {node['name']} does not contain pdns-db and pdns-primary services")
        for service in ("pdns-auth", "pdns-primary"):
            if service in services:
                pdns_env = services[service].setdefault("environment", {})
                # The official PowerDNS image only consumes its documented API-key
                # environment variable; arbitrary PDNS_<setting> values do not
                # override a mounted configuration. Render the credentials into the
                # node-private configuration instead.
                for setting in ("PDNS_gpgsql_password", "PDNS_api_key", "PDNS_gpgsql_host", "PDNS_gpgsql_dbname", "PDNS_gpgsql_user"):
                    pdns_env.pop(setting, None)
                # Supplementary group matches the activated secret file. Keep the
                # image's non-root service user without exposing credentials to all users.
                services[service].setdefault("group_add", []).append("82")
        ha.apply_compose(state, node, services)
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
            # The provisioning helper is retained and pointed at the external control database;
            # this keeps the read-only role and views deterministic on first activation.
            services.pop("control-db", None)
            for service in services.values():
                depends = service.get("depends_on")
                if isinstance(depends, dict):
                    depends.pop("control-db", None)
                elif isinstance(depends, list):
                    service["depends_on"] = [name for name in depends if name != "control-db"]

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
            "CDNF_CORE_IMAGE": f"ghcr.io/vaheed/cdnfoundry-core:{node['release']}",
            "CDNF_WEB_IMAGE": f"ghcr.io/vaheed/cdnfoundry-web:{node['release']}",
            "CDNF_EDGE_CONTROL_IMAGE": f"ghcr.io/vaheed/cdnfoundry-edge-control:{node['release']}",
            "CDNF_EDGE_RUNTIME_IMAGE": f"ghcr.io/vaheed/cdnfoundry-edge-runtime:{node['release']}",
            "CDNF_EDGE_AGENT_IMAGE": f"ghcr.io/vaheed/cdnfoundry-edge-agent:{node['release']}",
            "CDNF_EDGE_GATEWAY_IMAGE": f"ghcr.io/vaheed/cdnfoundry-edge-gateway:{node['release']}",
            "CDNF_MMDB_UPDATER_IMAGE": f"ghcr.io/vaheed/cdnfoundry-mmdb-updater:{node['release']}",
            "HOST_BIND_IPV4": node["bind_ipv4"],
            "HOST_BIND_IPV6": node.get("bind_ipv6") or "::",
            "DNS_BIND_V4": node["bind_ipv4"],
            "APP_URL": f"https://control.{operator_domain}",
            "CONTROL_HOSTNAME": f"control.{operator_domain}",
            "TELEMETRY_HOSTNAME": f"telemetry.{operator_domain}",
            "GRAFANA_HOSTNAME": f"grafana.{operator_domain}",
            "APP_KEY": self._secret("app-key"),
            "EDGE_ARTIFACT_SIGNING_KEY": self._secret("artifact-signing-key"),
            "EDGE_ARTIFACT_SIGNING_NEXT_KEY": "",
            "CONTROL_DB_PASSWORD": self._secret("control-db-password"),
            "REDIS_PASSWORD": self._secret("valkey-password"),
            "CLICKHOUSE_PASSWORD": self._secret("clickhouse-password"),
            "CLICKHOUSE_INGEST_PASSWORD": self._secret("clickhouse-ingest-password"),
            "CLICKHOUSE_READER_PASSWORD": self._secret("clickhouse-reader-password"),
            "CLICKHOUSE_ZONES_PASSWORD": self._secret("clickhouse-zones-password"),
            "CLICKHOUSE_URL": self._clickhouse_url(state, node),
            "GRAFANA_ADMIN_PASSWORD": self._secret("grafana-admin-password"),
            "GRAFANA_CLICKHOUSE_PASSWORD": self._secret("grafana-clickhouse-password"),
            "GRAFANA_POSTGRES_PASSWORD": self._secret("grafana-postgres-password"),
            "METRICS_TOKEN_FILE": "./secrets/metrics-token",
            "EDGE_PROXY_SECRET_FILE": "./secrets/edge-proxy-secret.conf",
            # Production Compose PKI contract (control, edge and DNS roles).
            "EDGE_IDENTITY_CA_CERTIFICATE": "./pki/edge-identity-ca.crt",
            "EDGE_IDENTITY_CA_PRIVATE_KEY": "./pki/edge-identity-ca.key",
            "EDGE_CONTROL_SERVER_CERTIFICATE": "./pki/node.crt",
            "EDGE_CONTROL_SERVER_PRIVATE_KEY": "./pki/node.key",
            "EDGE_CONTROL_CA_CERTIFICATE": "./pki/edge-server-ca.crt",
            "EDGE_CONTROL_URL": self._edge_control_url(state),
            "EDGE_RUNTIME_TLS_CERTIFICATE": "/dev/shm/cdnfoundry/edge-runtime.crt",
            "EDGE_RUNTIME_TLS_PRIVATE_KEY": "/dev/shm/cdnfoundry/edge-runtime.key",
            "EDGE_STATE_ENCRYPTION_KEY_FILE": "/dev/shm/cdnfoundry/edge-state-encryption.key",
            # A PoP that also runs a DNS node lets its agent drive the node's local PowerDNS API.
            "EDGE_DNS_API_URL": "http://pdns-auth:8081" if node["role"] == "dns-edge" else "",
            "EDGE_DNS_API_KEY": self._optional_node_secret("pdns-api-key", node) if node["role"] == "dns-edge" else "",
            "MANAGEMENT_NETWORKS": self._management_networks(state),
            "CONTROL_PUBLIC_IPV4_ALLOWLIST": self._control_allowlist(state),
            "EDGE_PUBLIC_IPV4_ALLOWLIST": self._edge_allowlist(state),
            "LOG_SOURCE_IPV4_ALLOWLIST": self._all_public_ipv4(state),
            "CONTROL_PUBLIC_IPV6_ALLOWLIST": self._control_allowlist(state, family=6),
            "EDGE_PUBLIC_IPV6_ALLOWLIST": self._edge_allowlist(state, family=6),
            "LOG_SOURCE_IPV6_ALLOWLIST": self._all_public_ipv6(state),
            "LOG_ROLE": node["role"],
            "LOG_HOST": node["name"],
            "LOG_COLLECTOR_ID": node["name"],
            "LOKI_ENDPOINT": self._loki_url(state, node),
            "LOG_AUTH_TOKEN": self._secret("log-auth-token"),
            "EDGE_STATUS_TOKEN": self._optional_node_secret("edge-status-token", node),
            "EDGE_NETWORK_SUBNET": "172.29.250.0/24",
            "FPM_NETWORK_SUBNET": "172.31.254.0/29",
            "EDGE_ID": node.get("edge_id") or "",
            "CDNF_TELEMETRY_POP": node["name"],
            "CDNF_TELEMETRY_REGION": node["region"],
            "EDGE_BOOTSTRAP_TOKEN": self._optional_node_secret("edge-bootstrap-token", node),
            "PDNS_DB_PASSWORD": self._optional_node_secret("pdns-db-password", node),
            "PDNS_API_KEY": self._optional_node_secret("pdns-api-key", node),
            "RESTIC_REPOSITORY": state["features"]["backups"].get("repository") or "",
            "RESTIC_PASSWORD_FILE": "./secrets/restic-password",
            "BACKUP_ACCESS_KEY_ID": self._secret("backup-access-key"),
            "BACKUP_SECRET_ACCESS_KEY": self._secret("backup-secret-key"),
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
            "REDIS_CACHE_HOST": "cache",
            "VALKEY_CONFIG_FILE": "./secrets/valkey.conf",
            "REDIS_PORT": "6379",
            "ACME_DIRECTORY_URL": "https://acme-v02.api.letsencrypt.org/directory",
            "ACME_ORDER_BUDGET_PER_HOUR": "20",
            "ACME_VERIFY_TLS": "true",
            "ACME_RENEW_BEFORE_DAYS": "30",
            "ACME_DNS_TTL": "30",
            "ACME_DNS_PROBE_SERVER": "",
            "ACME_CHALLENGE_LIFETIME_MINUTES": "30",
            "ACME_INITIAL_JITTER_SECONDS": "300",
            "TLS_EXPIRY_ALERT_DAYS": "14",
            "TLS_UPLOAD_TRUST_FILE": "",
            "BACKUP_KEEP_DAILY": "14",
            "BACKUP_KEEP_WEEKLY": "8",
            "BACKUP_KEEP_MONTHLY": "6",
            "BACKUP_RESTORE_ALLOWED": "false",
            "MMDB_STALE_HOURS": "48",
            "VECTOR_METRICS_URL": "http://vector:9598/metrics",
            "WAF_RULESET_VERSION": "owasp-crs/4.30.0-modsecurity/3.0.17",
            "EDGE_IDENTITY_VALIDITY_DAYS": "365",
            "REDIS_CACHE_PORT": "6379",
            "REDIS_CACHE_URL": "",
            "EDGE_IDENTITY_CA_PRIVATE_KEY_PASSPHRASE": "",
            "GRAFANA_EXPLORE_URL": f"https://grafana.{operator_domain}/explore?left=%7B%22datasource%22:%22loki%22%7D",
            "EDGE_CONTROL_BIND": "0.0.0.0:8443",
            "EDGE_RUNTIME_VERSIONS": "{}",
            "EDGE_GATEWAY_METRICS_ADDRESS": "0.0.0.0:9105",
            "EDGE_GATEWAY_MAX_CONNECTIONS": "8192",
            "EDGE_GATEWAY_MAX_CONNECTIONS_PER_ADDRESS": "256",
            "EDGE_GATEWAY_IDLE_TIMEOUT_SECONDS": "120",
            "EDGE_GATEWAY_HALF_CLOSE_TIMEOUT_SECONDS": "30",
            "EDGE_GATEWAY_STATUS_URL": "http://host-gateway:9105/metrics",
            "EDGE_GATEWAY_ADDRESS_MAP": "{}",
            "EDGE_GATEWAY_REQUIRE_ADDRESS_MAP": "false",
            "MMDB_PROVIDER": "dbip-npm",
            "MMDB_TARGET_FILE": "GeoLite2-City.mmdb",
            "MMDB_DOWNLOAD_INTERVAL_SECONDS": "86400",
            "MMDB_DOWNLOAD_RETRIES": "5",
            "MMDB_EXPECTED_SHA256": "",
            "MMDB_CHECKSUM_URL": "",
            "MMDB_DOWNLOAD_URL": "",
            "MMDB_DOWNLOAD_HEADER": "",
            "MMDB_ASN_DOWNLOAD_URL": "",
            "MMDB_ASN_EXPECTED_SHA256": "",
            "MMDB_ASN_CHECKSUM_URL": "",
            "LOG_BUFFER_BYTES": "2147483648",
            # Bind only addresses configured on the local host. public_ipv4 may be
            # an advertised NAT or floating address and is not necessarily bindable.
            "LOG_METRICS_BIND": f"{node.get('monitor_ipv4') or node['bind_ipv4']}:9599",
            "LOKI_RETENTION_PERIOD": "336h",
            "LOKI_MAX_QUERY_LENGTH": "744h",
            "PROMETHEUS_EDGE_TARGETS_FILE": "./generated/prometheus-edge-targets.yml",
            "PROMETHEUS_PROBE_TARGETS_FILE": "./generated/prometheus-probe-targets.yml",
            "PROMETHEUS_VECTOR_TARGETS_FILE": "./generated/prometheus-vector-targets.yml",
            "VECTOR_METRICS_BIND": f"{node.get('monitor_ipv4') or node['bind_ipv4']}:9598",
            "PROMETHEUS_LOG_TARGETS_FILE": "./generated/prometheus-log-targets.yml",
            "PROMETHEUS_CONTROL_TARGETS_FILE": "./generated/prometheus-control-targets.yml",
            "PROMETHEUS_NODE_TARGETS_FILE": "./generated/prometheus-node-targets.yml",
            "PROMETHEUS_DNS_TARGETS_FILE": "./generated/prometheus-dns-targets.yml",
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
        if node["role"] in {"edge", "dns-edge"} and values.get("EDGE_BOOTSTRAP_TOKEN") and not values.get("EDGE_ID"):
            raise RenderError(
                f"Node {node['name']} has an edge bootstrap token but no EDGE_ID; configure both credentials together"
            )
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
        if node["role"] == "monitoring" and "GRAFANA_POSTGRES_HOST" in needed:
            control = next((item for item in state["nodes"].values() if item["role"] == "control"), None)
            control_env = control.get("extra_env", {}) if control else {}
            remote_host = explicit_env.get("GRAFANA_POSTGRES_HOST") or control_env.get("DB_HOST")
            if not remote_host or remote_host == "control-db":
                raise RenderError(
                    "Dedicated monitoring requires an externally reachable control PostgreSQL endpoint; "
                    "set GRAFANA_POSTGRES_HOST on the monitoring node or DB_HOST on the control node"
                )
            defaults = {
                "GRAFANA_POSTGRES_PROVISION_HOST": remote_host,
                "GRAFANA_POSTGRES_HOST": remote_host,
                "GRAFANA_POSTGRES_PROVISION_PORT": explicit_env.get("GRAFANA_POSTGRES_PORT")
                or control_env.get("DB_PORT", "5432"),
                "GRAFANA_POSTGRES_PORT": explicit_env.get("GRAFANA_POSTGRES_PORT")
                or control_env.get("DB_PORT", "5432"),
                "GRAFANA_POSTGRES_SSLMODE": explicit_env.get("GRAFANA_POSTGRES_SSLMODE")
                or control_env.get("DB_SSLMODE", "verify-full"),
                "DB_SSLMODE": control_env.get("DB_SSLMODE", "verify-full"),
            }
            for key, value in defaults.items():
                if key not in explicit_env:
                    values[key] = value
                    derived_env.add(key)
        values.update(ha.environment(state, node))
        missing = sorted(key for key in needed if key not in values)
        if missing:
            raise RenderError(
                f"Node {node['name']} is missing required environment values: {', '.join(missing)}; use extra_env"
            )
        # Minimal role environment: only variables referenced by the filtered Compose plus generated configs.
        always = {"CDNF_RELEASE", "HOST_BIND_IPV4", "HOST_BIND_IPV6", "VECTOR_METRICS_BIND"}
        if node["role"] in {"dns", "dns-edge"}:
            always |= {"PDNS_API_KEY"}
        if node["role"] == "dns-edge":
            always |= {"EDGE_DNS_API_URL", "EDGE_DNS_API_KEY"}
        if node["role"] == "control":
            always |= {"PDNS_DB_PASSWORD", "PDNS_API_KEY"} | ha.always_variables(state, node)
        if state["features"]["logs"]["mode"] == "centralized":
            always |= {
                "LOG_ROLE", "LOG_HOST", "LOG_COLLECTOR_ID", "LOKI_ENDPOINT",
                "LOG_AUTH_TOKEN", "LOG_METRICS_BIND",
            }
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

    def _secret(self, name: str, *, node: str | None = None) -> str:
        if self.dry_run:
            return f"dry-run-{name}-placeholder"
        return self.store.read_secret(name, node=node)

    def _optional_node_secret(self, name: str, node: dict[str, Any]) -> str:
        path = self.store.secret_path(name, node=node["name"])
        if self.dry_run:
            return f"dry-run-{name}-placeholder" if path.exists() else ""
        return self.store.read_secret(name, node=node["name"]) if path.exists() else ""

    @staticmethod
    def _uses_remote_control_db(node: dict[str, Any]) -> bool:
        extra = node.get("extra_env", {})
        if str(extra.get("DB_URL", "")).strip():
            return True
        host = str(extra.get("DB_HOST", "")).strip()
        return bool(host and host != "control-db")

    def _edge_control_url(self, state: dict[str, Any]) -> str:
        return f"https://edge-control.{state['global']['operator_domain']}:8443"

    @staticmethod
    def _add_ipv6_publications(services: dict[str, Any], bind: str) -> None:
        mappings = {
            "caddy": [(80, "tcp"), (443, "tcp"), (443, "udp"), (8444, "tcp")],
            "pdns-primary": [(5300, "tcp"), (5300, "udp")],
            "telemetry-gateway": [(80, "tcp"), (443, "tcp"), (8444, "tcp")],
            "dnsdist": [(53, "tcp"), (53, "udp")],
            "edge-gateway": [(80, "tcp"), (443, "tcp"), (443, "udp")],
            # Agents enroll and sync over mTLS; a PoP that has only IPv6 must still reach the control plane.
            "edge-control": [(8443, "tcp")],
        }
        for service_name, ports in mappings.items():
            if service_name in services:
                current = services[service_name].setdefault("ports", [])
                current.extend(f"[{bind}]:{port}:{port}/{protocol}" for port, protocol in ports)

    @staticmethod
    def _management_networks(state: dict[str, Any]) -> str:
        """Operator networks plus every fleet address, so the hosts keep probing and scraping one another. Empty when unrestricted."""
        configured = state["global"].get("management_networks") or []
        if not configured:
            return ""
        v4, v6 = fleet_addresses(state)
        return " ".join([*configured, *(f"{a}/32" for a in v4), *(f"{a}/128" for a in v6)])

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
            owner = ha.telemetry_owner(state)
            if owner is not None:
                return state["nodes"][owner]
            return next((node for node in state["nodes"].values() if node["role"] == "control"), None)
        name = cfg.get("host")
        return state["nodes"].get(name) if name else None

    def _clickhouse_url(self, state: dict[str, Any], node: dict[str, Any]) -> str:
        host = self._feature_host(state, "monitoring")
        if host is None:
            return "http://127.0.0.1:8123"
        if host["name"] == node["name"]:
            return "http://clickhouse:8123"
        return f"https://telemetry.{state['global']['operator_domain']}:8444"

    def _loki_url(self, state: dict[str, Any], node: dict[str, Any]) -> str:
        cfg = state["features"]["logs"]
        if cfg.get("endpoint"):
            return cfg["endpoint"]
        host = state["nodes"].get(cfg.get("host")) if cfg.get("host") else None
        if host is None:
            return "http://127.0.0.1:3100"
        if host["name"] == node["name"]:
            return "http://loki:3100"
        return f"https://telemetry.{state['global']['operator_domain']}:8444"

    def _format_env(self, env: dict[str, str]) -> str:
        return "".join(f"{key}={quote_env(value)}\n" for key, value in sorted(env.items()))

    def _copy_runtime_files(self, compose: dict[str, Any], destination: Path) -> None:
        for relative in sorted(bind_mount_sources(compose)):
            if relative.parts and relative.parts[0] in {'generated', 'pki', 'secrets', 'ha', 'tls'}:
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
            self._make_runtime_path_readable(target)

    @staticmethod
    def _make_runtime_path_readable(path: Path) -> None:
        """Make non-secret bind-mounted configuration readable by container users."""
        paths = [path]
        if path.is_dir():
            paths.extend(path.rglob("*"))
        for candidate in paths:
            current_mode = candidate.stat().st_mode & 0o777
            if candidate.is_dir():
                candidate.chmod(current_mode | 0o555)
            elif candidate.is_file():
                candidate.chmod(current_mode | 0o444)

    def _write_generated_configs(
        self, state: dict[str, Any], node: dict[str, Any], destination: Path, monitoring_host: bool
    ) -> None:
        secrets_dir = destination / "secrets"
        secrets_dir.mkdir(parents=True, exist_ok=True, mode=0o700)
        if node["role"] == "control" or monitoring_host:
            # The bundle directory is mode 0700. Group-read permission is required by
            # the non-root PHP and Prometheus processes after start.sh restricts the
            # file to the PHP worker group and grants Prometheus that same group.
            atomic_write(secrets_dir / "metrics-token", self.store.read_secret("metrics-token") + "\n", 0o640)
        if node["role"] == "control":
            atomic_write(secrets_dir / "valkey.conf", ha.valkey_config(state, node, self.store.read_secret("valkey-password")), 0o640)
            ha.write_files(self.store, self.repo_root, state, node, destination)
            # nginx includes this file as-is; PHP reads the secret from the same file.
            secret = self.store.read_secret("edge-proxy-secret")
            atomic_write(secrets_dir / "edge-proxy-secret.conf", f'fastcgi_param HTTP_X_EDGE_PROXY_SECRET "{secret}";\n', 0o640)
        if state["features"]["backups"]["mode"] != "disabled":
            atomic_write(secrets_dir / "restic-password", self.store.read_secret("backup-password") + "\n", 0o600)
        if node["role"] in {"control", "dns", "dns-edge"}:
            self._write_pdns_config(node, destination)
        if node["role"] == "control":
            pending = self.store.pending_secret_path("pdns-db-password", node=node["name"])
            if pending.exists():
                atomic_write(
                    secrets_dir / "pdns-db-password.next",
                    pending.read_text(encoding="utf-8"),
                    0o600,
                )
                atomic_write(
                    destination / "reconcile-pdns-password.py",
                    Path(__file__).with_name("pdns_rotation.py").read_text(encoding="utf-8"),
                    0o600,
                )
                atomic_write(
                    destination / "reconcile-pdns-password.sh",
                    self._pdns_reconciliation_script(),
                    0o700,
                )
        if monitoring_host:
            generated = destination / "generated"
            atomic_write(generated / "prometheus-control-targets.yml", self._prometheus_control_targets(state, node), 0o644)
            atomic_write(generated / "prometheus-node-targets.yml", self._prometheus_targets(state, node), 0o644)
            atomic_write(generated / "prometheus-dns-targets.yml", self._prometheus_service_targets(state, "dns", node), 0o644)
            atomic_write(generated / "prometheus-edge-targets.yml", self._prometheus_service_targets(state, "edge", node), 0o644)
            atomic_write(generated / "prometheus-vector-targets.yml", self._prometheus_service_targets(state, "vector", node), 0o644)
            atomic_write(generated / "prometheus-probe-targets.yml", self._prometheus_probe_targets(state), 0o644)
            atomic_write(generated / "prometheus-log-targets.yml", self._prometheus_service_targets(state, "logs", node), 0o644)
            atomic_write(generated / "geo-routing-policy.json", json.dumps(self._geo_policy(state), indent=2) + "\n", 0o600)

    def _write_pdns_config(self, node: dict[str, Any], destination: Path) -> None:
        # The control host's primary has a database; a node has only an API key.
        control = node["role"] == "control"
        path = destination / ("docker/pdns/primary.conf" if control else "docker/pdns/pdns.conf")
        if not path.exists():
            raise RenderError("PowerDNS configuration was not copied into the bundle")
        content = path.read_text(encoding="utf-8")
        replacements = {"api-key": self.store.read_secret("pdns-api-key", node=node["name"])}
        if control:
            replacements["gpgsql-password"] = self.store.read_secret("pdns-db-password", node=node["name"])
        lines = content.splitlines()
        for setting, value in replacements.items():
            indexes = [index for index, line in enumerate(lines) if line.startswith(f"{setting}=")]
            if len(indexes) != 1:
                raise RenderError(f"PowerDNS configuration must contain exactly one {setting} setting")
            lines[indexes[0]] = f"{setting}={value}"
        atomic_write(path, "\n".join(lines) + "\n", 0o600)

    def _pdns_reconciliation_script(self) -> str:
        return '''#!/usr/bin/env sh
set -eu
umask 077
cd "$(dirname "$0")"
exec python3 ./reconcile-pdns-password.py
'''

    def _prometheus_targets(self, state: dict[str, Any], monitoring_node: dict[str, Any]) -> str:
        groups = []
        for node in sorted(state["nodes"].values(), key=lambda item: item["name"]):
            if not node["enabled"]:
                continue
            address = "node-exporter" if node["name"] == monitoring_node["name"] else node.get("monitor_ipv4") or node["public_ipv4"]
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

    def _prometheus_control_targets(self, state: dict[str, Any], monitoring_node: dict[str, Any]) -> str:
        """Every control node is a scrape target, so each node of a pair is watched by the other."""
        import yaml

        groups = []
        for control in ha.control_nodes(state):
            if control["name"] == monitoring_node["name"]:
                target = "web:8080"
                labels = {"node": control["name"], "role": "control", "__scheme__": "http"}
            else:
                # Each node answers on its own address under the shared control hostname.
                target = f"{control['public_ipv4']}:443"
                labels = {"node": control["name"], "role": "control", "__scheme__": "https"}
            groups.append({"targets": [target], "labels": labels})
        return yaml.safe_dump(groups, sort_keys=False) if groups else "[]\n"

    def _prometheus_service_targets(
        self, state: dict[str, Any], service: str, monitoring_node: dict[str, Any]
    ) -> str:
        groups = []
        for node in sorted(state["nodes"].values(), key=lambda item: item["name"]):
            address = node.get("monitor_ipv4") or node["public_ipv4"]
            if not node["enabled"]:
                continue
            if service == "edge" and node["role"] not in {"edge", "dns-edge"}:
                continue
            if service == "dns" and node["role"] not in {"dns", "dns-edge"}:
                continue
            if service == "vector" and node["role"] not in {"edge", "dns", "dns-edge"}:
                continue
            if service == "logs" and state["features"]["logs"]["mode"] != "centralized":
                continue
            if node["name"] == monitoring_node["name"]:
                address = "log-collector" if service == "logs" else "dnsdist" if service == "dns" else "vector" if service == "vector" else address
            groups.append({
                "targets": [f"{address}:{'9105' if service == 'edge' else '8083' if service == 'dns' else '9598' if service == 'vector' else '9599'}"],
                "labels": {"node": node["name"], "role": node["role"]},
            })
        import yaml

        return yaml.safe_dump(groups, sort_keys=False)

    def _prometheus_probe_targets(self, state: dict[str, Any]) -> str:
        """Every PoP and nameserver address is probed from the monitoring host, over IPv4 and IPv6 separately."""
        import yaml

        groups = []
        for node in sorted(state["nodes"].values(), key=lambda item: item["name"]):
            if not node["enabled"] or node["role"] not in {"edge", "dns", "dns-edge"}:
                continue
            for version, address in (("4", node.get("public_ipv4")), ("6", node.get("public_ipv6"))):
                if not address:
                    continue
                host = f"[{address}]" if version == "6" else address
                probes = []
                if node["role"] in {"edge", "dns-edge"}:
                    probes += [("http", f"http://{host}/"), ("tcp", f"{host}:443")]
                if node["role"] in {"dns", "dns-edge"}:
                    probes += [("dns_udp", f"{host}:53"), ("dns_tcp", f"{host}:53")]
                for probe, target in probes:
                    groups.append({
                        "targets": [target],
                        "labels": {"probe": probe, "pop": node["name"], "region": node["region"], "ip_version": version},
                    })
        return yaml.safe_dump(groups, sort_keys=False) if groups else "[]\n"

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
        operator_domain = state["global"]["operator_domain"]
        control_bootstrap = ""
        edge_bootstrap = ""
        vector_buffers = ""
        if "vector" in compose.get("services", {}):
            vector_buffers = """
Vector reserves bounded disk buffers for billing, security, and DNS telemetry.
The security and DNS buffers each use the Vector-supported minimum of
268435488 bytes. A config validation alone does not instantiate these buffers;
confirm that the `vector` container is healthy and logs `Vector has started`.
"""
        if node["role"] in {"edge", "dns-edge"}:
            edge_bootstrap = """
## Edge key provisioning

Before `validate.sh` or `start.sh`, use external secret storage to provision a
dedicated bootstrap listener certificate and matching private key at
`/dev/shm/cdnfoundry/edge-runtime.crt` and
`/dev/shm/cdnfoundry/edge-runtime.key`. Provision the stable edge recovery key
as 64 hex characters at `/dev/shm/cdnfoundry/edge-state-encryption.key`.
Both private files must be on tmpfs, owned by UID 10101, and mode `0400`.
Disable host swap and restore them from external secret storage after every
host boot. Do not copy
the node identity key into these paths: that key also identifies control or
DNS services and must remain separate from the serving listener key.

Each cell checks its local `/healthz` after a bounded startup grace period.
If Vector is unavailable at startup, the cell serves using bounded Docker
stdout logs and reports the telemetry fallback in its container log. Restart
the cell after Vector recovers to restore direct syslog delivery. Edge logs
remove query strings from paths and referrers before either output is written.
Response headers use the `X-CDNF-` prefix, including `X-CDNF-Cache`,
`X-CDNF-Origin`, and `X-CDNF-Security-Reason`. Update external probes and log
parsers when rolling out the edge image; its runtime and WAF rules use the same
prefix.
"""
            if node["role"] == "dns-edge":
                edge_bootstrap += """
This combined DNS/edge bundle deliberately excludes `pki/node.key`. Provision
the matching node certificate private key from external secret storage into
`/dev/shm/cdnfoundry/node.key` (UID 10101, mode `0400`) before starting the
DNS API listener. The public certificate remains at `pki/node.crt`.
"""
        if node["role"] == "control":
            control_bootstrap = f"""
## First administrator and public readiness

Before startup, public `A` and optional `AAAA` records for
`control.{operator_domain}`, `edge-control.{operator_domain}`,
`telemetry.{operator_domain}`, and `grafana.{operator_domain}` must point to
this control node. TCP 80, 443, and 8443 must reach their documented listeners.

After `start.sh` succeeds, verify the application and create the bootstrap
administrator. The password is prompted twice and is not stored in shell
history:

```sh
curl --fail https://control.{operator_domain}/api/health
curl --fail https://control.{operator_domain}/api/ready
docker compose --env-file .env.prod exec core php artisan cdnf:admin:create \\
  --name='Operations Administrator' --email='admin@example.com'
```

Then sign in at `https://control.{operator_domain}/admin`. Create the bootstrap
administrator only once; create later users through the authenticated panel.
The public service status page is `https://control.{operator_domain}/health`.
Its first operational snapshot appears after the scheduler's next minute tick;
the page reports an unknown state when that snapshot becomes stale.
"""
        return f"""# CDNFoundry node bundle: {node['name']}

- Role: `{node['role']}`
- Region: `{node['region']}`
- Location: `{node['location']}`
- Release: `{node['release']}`
- Services: {services}
- Control database mode: {database_mode if node['role'] == 'control' else 'not applicable'}

This bundle intentionally contains only files and credentials needed by this node. For DNS roles, `pdns-db` is the node-local PostgreSQL service and `pdns-auth` connects only to `pdns-db`; it never uses the control-plane PostgreSQL service.

## Requirements

Docker Engine, Docker Compose v2, Python 3, accurate system time, CA certificates, and sufficient disk for stateful volumes. Keep the directory mode `0700` and `.env.prod`, private keys, and secret files mode `0600`. Run activation as root. On control nodes, `start.sh` restricts the edge identity CA key to mode `0640`, owner `root`, and numeric group `82` for the PHP worker. On the control node and on DNS nodes it applies the same ownership and mode to the PowerDNS configuration (`docker/pdns/primary.conf` or `docker/pdns/pdns.conf`); the generated PowerDNS service receives group `82`. Other users must not read these credentials. Pending password rotations include a Python implementation invoked by `sudo ./reconcile-pdns-password.sh`; preserve its pending file and retry after an interruption before committing Fleet state.

## Validate and start

If this bundle contains `reconcile-pdns-password.sh`, a PowerDNS database password rotation is pending. Run that script on this control host before committing the rotation on the control plane. Normal rerenders do not rotate credentials.

Run on **{node['name']}**:

```sh
cd /opt/cdnfoundry
./validate.sh
./start.sh
docker compose --env-file .env.prod ps
```

`start.sh` activates only this bundle's configured role profiles. A combined
DNS/edge node without an edge UUID starts authoritative DNS but does not start
the edge profile. After the host operator sets its edge UUID and one-time token
in `.env.prod`, the same script reads the current Compose environment and
activates both profiles. No script edit or rerender is required.

For a planned stop, use `./stop.sh`. The bundle uses Compose profiles, so a
plain `docker compose down` selects no profiled services and does nothing.
`stop.sh` activates every profile and preserves all named volumes. Never add
`-v` or `--volumes`.

{control_bootstrap}{edge_bootstrap}{vector_buffers}

## Listeners

{listeners}

Restrict management, metrics, logging, database, and DNS API ports to their exact trusted source addresses. Databases must never be publicly reachable.

## Health checks

```sh
docker compose --env-file .env.prod ps
docker compose --env-file .env.prod logs --since 10m --no-color
```

## Upgrade and rollback

Replace the bundle atomically, run `./validate.sh`, pull images, then use `./start.sh`. If validation or startup fails, restore the `.previous` bundle and rerun `./start.sh`. Never use `docker compose down -v` and never delete PostgreSQL, Valkey, ClickHouse, Loki, Prometheus, Grafana, edge-agent-state, cache, or MMDB volumes. The edge-runtime-memory volume is tmpfs; restore it from the encrypted snapshot with the external recovery key.

## Cleanup

After successful validation and the retention period, securely remove obsolete transferred archives. Do not remove the active directory, `.previous` rollback bundle, state volumes, or protected recovery copies.
"""

    def _listeners(self, node: dict[str, Any]) -> str:
        rows = ["| Listener | Exposure |", "| --- | --- |"]
        role = node["role"]
        if role in {"dns", "dns-edge"}:
            rows.append("| UDP/TCP 53 | Public authoritative DNS through DNSdist |")
        if role in {"edge", "dns-edge"}:
            rows.append("| TCP 80/443 | Public customer traffic on configured service addresses |")
        if role == "control":
            rows.append("| TCP 80/443 | Public operator UI/API |")
            rows.append("| TCP 8443 | Edge nodes only |")
            rows.append("| TCP/UDP 5300 | DNS node addresses only (TSIG-signed zone transfers from the hidden primary) |")
        rows.append("| TCP 9100 | Monitoring host/private monitoring network only |")
        return "\n".join(rows)

    @staticmethod
    def _stop_script() -> str:
        return """#!/usr/bin/env sh
set -eu
docker compose --env-file .env.prod --profile '*' stop
docker compose --env-file .env.prod --profile '*' ps
"""

    def _node_start_order(self, node: dict[str, Any]) -> str:
        if node["role"] == "control":
            dependencies = "redis" if self._uses_remote_control_db(node) else "control-db redis"
            # On a new volume the image first runs a temporary server that listens on its socket only, so the
            # container can report healthy before the real server accepts the connections the migration makes.
            ready = (
                ""
                if self._uses_remote_control_db(node)
                else "docker compose --env-file .env.prod exec -T control-db sh -c "
                "'i=0; until pg_isready -q -h 127.0.0.1 -U cdnf -d cdnf; do i=$((i+1)); [ \"$i\" -lt 60 ] || exit 1; sleep 2; done'\n"
            )
            return (
                f"docker compose --env-file .env.prod up -d --wait {dependencies}\n"
                f"{ready}"
                "docker compose --env-file .env.prod --profile tools run --rm migrate\n"
                "docker compose --env-file .env.prod up -d --wait pdns-db\n"
                "docker compose --env-file .env.prod exec -T pdns-db "
                "sh /usr/local/bin/ensure-pdns-runtime.sh\n"
                "docker compose --env-file .env.prod --profile tools run --rm pdns-migrate"
            )
        if node["role"] in {"dns", "dns-edge"}:
            return "# A DNS node keeps no database; its SQLite store is created on first start."
        return "# No database migration is required for this role."

    def _validate_script(self, node: dict[str, Any], compose: dict[str, Any]) -> str:
        identity_key_validation = ""
        pdns_validation = ""
        edge_key_validation = ""
        if node["role"] in {"edge", "dns-edge"}:
            edge_key_validation = """test ! -e pki/node.key
for key_path in /dev/shm/cdnfoundry/edge-runtime.key /dev/shm/cdnfoundry/edge-state-encryption.key; do
    test -f "$key_path"
    test "$(stat -f -c '%T' "$key_path")" = tmpfs
    test "$(stat -c '%a:%u' "$key_path")" = 400:10101
done
test "$(wc -l < /proc/swaps)" -le 1
test -f /dev/shm/cdnfoundry/edge-runtime.crt
test "$(wc -c < /dev/shm/cdnfoundry/edge-state-encryption.key)" -ge 64
test "$(wc -c < /dev/shm/cdnfoundry/edge-state-encryption.key)" -le 65
certificate_public="$(openssl x509 -in /dev/shm/cdnfoundry/edge-runtime.crt -pubkey -noout | openssl pkey -pubin -outform DER | sha256sum)"
private_public="$(openssl pkey -in /dev/shm/cdnfoundry/edge-runtime.key -pubout -outform DER | sha256sum)"
test "$certificate_public" = "$private_public"
"""
            if node["role"] == "dns-edge":
                edge_key_validation += """test -f /dev/shm/cdnfoundry/node.key
test "$(stat -f -c '%T' /dev/shm/cdnfoundry/node.key)" = tmpfs
test "$(stat -c '%a:%u' /dev/shm/cdnfoundry/node.key)" = 400:10101
dns_certificate_public="$(openssl x509 -in pki/node.crt -pubkey -noout | openssl pkey -pubin -outform DER | sha256sum)"
dns_private_public="$(openssl pkey -in /dev/shm/cdnfoundry/node.key -pubout -outform DER | sha256sum)"
test "$dns_certificate_public" = "$dns_private_public"
"""
        if node["role"] in {"control", "dns", "dns-edge"}:
            pdns_config = "docker/pdns/primary.conf" if node["role"] == "control" else "docker/pdns/pdns.conf"
            pdns_validation = f"""pdns_mode="$(stat -c '%a' {pdns_config})"
case "$pdns_mode" in
    600) ;;
    640) test "$(stat -c '%u:%g' {pdns_config})" = "0:82" ;;
    *) echo "PowerDNS credentials must be transfer-safe mode 600 or activated as root:82 mode 640; rerun start.sh as root." >&2; exit 1 ;;
esac
"""
        if node["role"] == "control":
            identity_key_validation = """identity_key_mode="$(stat -c '%a' pki/edge-identity-ca.key)"
case "$identity_key_mode" in
    600) ;;
    640)
        test "$(stat -c '%u:%g' pki/edge-identity-ca.key)" = "0:82"
        ;;
    *)
        echo "pki/edge-identity-ca.key must be transfer-safe mode 600 or activated as root:82 mode 640." >&2
        exit 1
        ;;
esac
"""
        caddy_validation = ""
        metrics_validation = ""
        if "prometheus" in compose.get("services", {}) or "core" in compose.get("services", {}):
            metrics_validation = """test "$(stat -c '%a' secrets/metrics-token)" = "640"
test "$(stat -c '%u:%g' secrets/metrics-token)" = "0:82"
"""
        caddy_configs = {
            "caddy": "/etc/caddy/Caddyfile",
            "telemetry-gateway": "/etc/caddy/Caddyfile",
        }
        for service, config in caddy_configs.items():
            if service in compose.get("services", {}):
                caddy_validation += (
                    f"docker compose --env-file .env.prod run --rm --no-deps {service} "
                    f"caddy adapt --adapter caddyfile --config {config} >/dev/null\n"
                )
        node_key_validation = "test \"$(stat -c '%a' pki/node.key)\" = 600\n" if node["role"] not in {"edge", "dns-edge"} else ""
        return f"""#!/usr/bin/env sh
set -eu
umask 077
test "$(stat -c '%a' .env.prod)" = 600
{node_key_validation}
{identity_key_validation}{metrics_validation}{pdns_validation}{edge_key_validation}docker compose --env-file .env.prod config --quiet
images="$(docker compose --env-file .env.prod --profile '*' config --images)"
printf '%s\n' "$images" | while IFS= read -r image; do
    case "$image" in
        *@sha256:*) digest="${{image##*@sha256:}}" ;;
        sha256:*) digest="${{image#sha256:}}" ;;
        *)
            echo "Mutable bundle image rejected. Set CDNF_*_IMAGE in node extra_env from the verified release manifest and render again." >&2
            exit 1
            ;;
    esac
    printf '%s' "$digest" | grep -Eq '^[0-9a-f]{{64}}$' || {{ echo "Invalid image digest in bundle." >&2; exit 1; }}
done
{caddy_validation}
openssl verify -CAfile pki/edge-server-ca.crt pki/node.crt
"""

    def _start_profiles(self, state: dict[str, Any], node: dict[str, Any]) -> str:
        profiles: list[str] = []
        if node["role"] == "control":
            profiles.append("control")
        if node["role"] in {"dns", "dns-edge"}:
            profiles.append("dns")
        if self._is_monitoring_host(state, node) or ha.is_watcher(state, node):
            profiles.append("telemetry")
        if state["features"]["logs"]["mode"] == "centralized":
            profiles.append("logs")
        return " ".join(f"--profile {profile}" for profile in profiles)

    def _start_script(self, state: dict[str, Any], node: dict[str, Any]) -> str:
        standby_script = ha.start_script(state, node)
        if standby_script is not None:
            return standby_script
        migration = self._node_start_order(node)
        if self._is_monitoring_host(state, node):
            # The telemetry schema is migrated the same way as the databases: explicitly, once per version.
            clickhouse_migration = (
                "docker compose --env-file .env.prod --profile telemetry up -d --wait clickhouse\n"
                "docker compose --env-file .env.prod --profile telemetry --profile tools run --rm clickhouse-migrate"
            )
            migration = clickhouse_migration if migration.startswith("#") else f"{migration}\n{clickhouse_migration}"
        profiles = self._start_profiles(state, node)
        activation = (
            f"""docker compose --env-file .env.prod {profiles} up -d
# Compose --wait fails on services whose healthcheck is explicitly disabled
# (for example Loki, whose image has no probe binary). Start every service,
# then wait only for services that define an active healthcheck.
healthy_services="$(docker compose --env-file .env.prod {profiles} config --format json | python3 -c '
import json, sys
services = json.load(sys.stdin)["services"]
print(" ".join(sorted(
    name for name, service in services.items()
    if (service.get("healthcheck") or {{}}).get("test") and not (service.get("healthcheck") or {{}}).get("disable")
)))
')"
if [ -n "$healthy_services" ]; then
    docker compose --env-file .env.prod {profiles} up -d --wait --wait-timeout 300 $healthy_services
fi"""
            if profiles
            else ":"
        )
        enrollment_validation = ""
        if node["role"] in {"edge", "dns-edge"}:
            enrollment_validation = """# Resolve the host's current enrollment through Compose; never source env files.
edge_identity_state="$(python3 -c '
import json, subprocess, sys, uuid
try:
    result = subprocess.run(["docker", "compose", "--env-file", ".env.prod", "--profile", "edge", "config", "--format", "json"],
                            check=True, capture_output=True, text=True, timeout=30)
    value = str(json.loads(result.stdout)["services"]["edge-agent"]["environment"].get("EDGE_ID", "")).strip()
    if value:
        uuid.UUID(value)
except (KeyError, ValueError, TypeError, OSError, subprocess.SubprocessError):
    sys.exit("Cannot read a valid EDGE_ID from Compose configuration; correct .env.prod before activation.")
print("configured" if value else "pending")
')"
"""
            activation = """if [ "$edge_identity_state" = "configured" ]; then
    """ + f"docker compose --env-file .env.prod {profiles+' ' if profiles else ''}--profile edge up -d --wait --wait-timeout 300" + """
else
    echo "Edge enrollment is pending; set EDGE_ID and its one-time bootstrap token in .env.prod, then rerun start.sh."
    """ + activation + """
fi"""
        key_permissions = ""
        if node["role"] in {"dns", "dns-edge"}:
            key_permissions = """if [ "$(id -u)" != "0" ]; then
    echo "DNS activation must run as root to restrict PowerDNS credentials to their container reader group." >&2
    exit 1
fi
chown 0:82 docker/pdns/pdns.conf
chmod 0640 docker/pdns/pdns.conf
"""
        if node["role"] == "control":
            key_permissions = """if [ "$(id -u)" != "0" ]; then
    echo "Control activation must run as root so the edge identity CA key and the PowerDNS credentials can be restricted to their container reader groups." >&2
    exit 1
fi
chown 0:82 pki/edge-identity-ca.key docker/pdns/primary.conf
chmod 0640 pki/edge-identity-ca.key docker/pdns/primary.conf
"""
        if self._is_monitoring_host(state, node) or node["role"] == "control":
            key_permissions += """if [ "$(id -u)" != "0" ]; then
    echo "Monitoring activation must run as root so the metrics token remains restricted and readable by its consumers." >&2
    exit 1
fi
chown 0:82 secrets/metrics-token
chmod 0640 secrets/metrics-token
"""
        if node["role"] == "control":
            key_permissions += ha.permissions_snippet(state, node)
            key_permissions += """chown 0:1000 secrets/valkey.conf
chmod 0640 secrets/valkey.conf
chown 0:82 secrets/edge-proxy-secret.conf
chmod 0640 secrets/edge-proxy-secret.conf
"""
        return f"""#!/usr/bin/env sh
set -eu
{key_permissions}
# Restore read/traverse access for non-secret bind-mounted configuration in case
# transfer or extraction narrowed its permissions. Private material under pki/
# and secrets/, and the credential-bearing pdns.conf, are handled above.
for runtime_path in docker generated; do
    if [ -e "$runtime_path" ]; then
        find "$runtime_path" -type d -exec chmod a+rx {{}} +
        find "$runtime_path" -type f ! -path 'docker/pdns/pdns.conf' ! -path 'docker/pdns/primary.conf' -exec chmod a+r {{}} +
    fi
done
./validate.sh
./firewall.sh
{enrollment_validation}{migration}
{activation}
docker compose --env-file .env.prod ps
"""

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
