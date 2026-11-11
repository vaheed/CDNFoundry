"""Optional two-node control plane: state, validation, and the pieces the renderer adds to a control bundle.

One node is active and the other is a warm standby. The fleet state records which is which; every
control bundle carries the node's own scripts under `ha/`, so promotion never depends on this tool
being able to reach the node's Docker daemon in any way except through those scripts.
"""
from __future__ import annotations

import ipaddress
import shutil
from pathlib import Path
from typing import Any

from .common import RenderError, ValidationError, atomic_write

HA_SCRIPT_SOURCE = Path("deploy/production/ha")
HA_SCRIPTS = (
    "common.sh",
    "prepare-source.sh",
    "seed-standby.sh",
    "start-standby.sh",
    "status.sh",
    "demote.sh",
    "promote.sh",
    "rejoin.sh",
    "release-source.sh",
    "permissions.sh",
)
# Container port -> published port. Each database listens on 5432 inside its container.
CONTROL_DB_PORT = 5432
PDNS_DB_PORT = 5433
VALKEY_PORT = 6379
# Docker networks reach the databases from other containers; the peer reaches replication only.
DOCKER_RANGES = ("10.0.0.0/8", "172.16.0.0/12", "192.168.0.0/16", "fc00::/7")
HA_MODES = {"none", "two-node"}


def section(state: dict[str, Any]) -> dict[str, Any]:
    value = state.get("ha")
    return value if isinstance(value, dict) else {"mode": "none"}


def enabled(state: dict[str, Any]) -> bool:
    return section(state).get("mode") == "two-node"


def role_of(state: dict[str, Any], name: str) -> str | None:
    ha = section(state)
    if ha.get("mode") != "two-node":
        return None
    if name == ha.get("active"):
        return "active"
    if name == ha.get("standby"):
        return "standby"
    return None


def peer_of(state: dict[str, Any], name: str) -> dict[str, Any] | None:
    ha = section(state)
    other = ha.get("standby") if name == ha.get("active") else ha.get("active") if name == ha.get("standby") else None
    return state["nodes"].get(other) if other else None


def control_nodes(state: dict[str, Any]) -> list[dict[str, Any]]:
    """Enabled control nodes, the active one first, so the address list is stable."""
    nodes = [node for node in state["nodes"].values() if node["role"] == "control" and node.get("enabled", True)]
    ha = section(state)
    order = {ha.get("active"): 0, ha.get("standby"): 1}
    return sorted(nodes, key=lambda node: (order.get(node["name"], 2), node["name"]))


def telemetry_owner(state: dict[str, Any]) -> str | None:
    """The control node that runs the single-instance telemetry stack (ClickHouse, Grafana, Loki).

    It stays on the node that already ran it, whichever node is active: those services keep no desired
    state, and Phase 2 keeps serving independent of them.
    """
    ha = section(state)
    if ha.get("mode") == "two-node" and ha.get("telemetry_node") in state["nodes"]:
        return str(ha["telemetry_node"])
    return None


def is_watcher(state: dict[str, Any], node: dict[str, Any]) -> bool:
    """The other control node: Prometheus, Alertmanager, and a node exporter, so it watches the telemetry owner."""
    return (
        node["role"] == "control"
        and enabled(state)
        and state["features"]["monitoring"]["mode"] == "colocated"
        and telemetry_owner(state) not in (None, node["name"])
    )


TELEMETRY_ONLY = {
    "clickhouse", "clickhouse-migrate", "loki", "loki-data-init", "grafana", "grafana-plugins-init",
    "grafana-control-db-provision", "telemetry-gateway", "vector",
}


def restrict_to_watcher(services: dict[str, Any]) -> None:
    for name in TELEMETRY_ONLY:
        services.pop(name, None)
    for service in services.values():
        depends = service.get("depends_on")
        if isinstance(depends, dict):
            for name in TELEMETRY_ONLY:
                depends.pop(name, None)
        elif isinstance(depends, list):
            service["depends_on"] = [name for name in depends if name not in TELEMETRY_ONLY]
    prometheus = services.get("prometheus")
    if prometheus is not None:
        prometheus["volumes"] = [
            "./docker/prometheus/watcher.yml:/etc/prometheus/prometheus.yml:ro" if str(volume).endswith(":/etc/prometheus/prometheus.yml:ro") else volume
            for volume in prometheus.get("volumes", [])
        ]


def replication_address(state: dict[str, Any], name: str) -> str:
    return str(section(state).get("replication_address", {}).get(name, ""))


def validate_replication_address(value: str) -> str:
    """Replication carries database and queue traffic, so it must stay on a private network or VPN."""
    try:
        address = ipaddress.ip_address(value)
    except ValueError as exc:
        raise ValidationError(f"Invalid replication address: {value!r}") from exc
    carrier_grade = address.version == 4 and address in ipaddress.ip_network("100.64.0.0/10")
    if address.is_loopback or address.is_unspecified or address.is_multicast or not (address.is_private or carrier_grade):
        raise ValidationError(
            f"Replication address {value} is not a private address. Put both control nodes on a private network or VPN "
            "and use their addresses there; database and queue replication must not cross the public internet."
        )
    return str(address)


def validate(state: dict[str, Any]) -> None:
    ha = section(state)
    if ha.get("mode", "none") not in HA_MODES:
        raise ValidationError("Invalid ha mode")
    controls = [name for name, node in state.get("nodes", {}).items() if node.get("role") == "control" and node.get("enabled", True)]
    if ha.get("mode", "none") == "none":
        if len(controls) > 1:
            raise ValidationError("A second control node requires `control add-standby`; the fleet has no other multi-control mode")
        return
    active, standby = ha.get("active"), ha.get("standby")
    if not active or not standby or active == standby:
        raise ValidationError("ha requires distinct active and standby nodes")
    for name in (active, standby):
        node = state["nodes"].get(name)
        if node is None or node.get("role") != "control":
            raise ValidationError(f"HA node {name} must be an existing control node")
        validate_replication_address(replication_address(state, name))
    if sorted(controls) != sorted((active, standby)):
        raise ValidationError("A two-node control plane has exactly the active and the standby node enabled")


def environment(state: dict[str, Any], node: dict[str, Any]) -> dict[str, str]:
    """Variables only a two-node control bundle needs."""
    if node["role"] != "control":
        return {}
    if not enabled(state):
        # Empty on a single node: the application then applies no HA fencing.
        return {"CONTROL_NODE_NAME": "", "CONTROL_PEER_ADDRESSES": ""}
    peer = peer_of(state, node["name"])
    assert peer is not None
    addresses = [peer["public_ipv4"]] + ([peer["public_ipv6"]] if peer.get("public_ipv6") else [])
    return {
        "CONTROL_NODE_NAME": node["name"],
        "CONTROL_PEER_ADDRESSES": ",".join(addresses),
        "CONTROL_REPLICATION_BIND": replication_address(state, node["name"]),
    }


def always_variables(state: dict[str, Any], node: dict[str, Any]) -> set[str]:
    return set(environment(state, node))


def valkey_config(state: dict[str, Any], node: dict[str, Any], password: str) -> str:
    """Queues and locks only; Valkey holds no desired state. Both nodes know the password for either role."""
    lines = [f"requirepass {password}"]
    if node["role"] == "control" and enabled(state):
        lines.append(f"masterauth {password}")
        if role_of(state, node["name"]) == "standby":
            lines.append(f"replicaof {replication_address(state, peer_of(state, node['name'])['name'])} {VALKEY_PORT}")  # type: ignore[index]
    return "\n".join(lines) + "\n"


def pg_hba(state: dict[str, Any], node: dict[str, Any]) -> str:
    peer = peer_of(state, node["name"])
    assert peer is not None
    peer_address = ipaddress.ip_address(replication_address(state, peer["name"]))
    peer_network = f"{peer_address}/{32 if peer_address.version == 4 else 128}"
    lines = [
        "# Rendered by cdnfoundry-fleet. Application access from the node's own containers; replication only from the peer.",
        "local all all trust",
        "host all all 127.0.0.1/32 trust",
        "host all all ::1/128 trust",
        "local replication all trust",
    ]
    lines += [f"host all all {network} scram-sha-256" for network in DOCKER_RANGES]
    lines += [f"host replication replicator {network} scram-sha-256" for network in (peer_network, *DOCKER_RANGES)]
    return "\n".join(lines) + "\n"


def apply_compose(state: dict[str, Any], node: dict[str, Any], services: dict[str, Any]) -> None:
    """Replication listeners, access rules, and the passfile; only for a two-node control bundle."""
    if node["role"] != "control" or not enabled(state):
        return
    bind = replication_address(state, node["name"])
    host = f"[{bind}]" if ":" in bind else bind
    for name, port in (("control-db", CONTROL_DB_PORT), ("pdns-db", PDNS_DB_PORT)):
        service = services.get(name)
        if service is None:
            continue
        service["command"] = ["postgres", "-c", "hba_file=/etc/postgresql/pg_hba.conf", "-c", "wal_keep_size=512MB", "-c", "max_replication_slots=8"]
        service.setdefault("ports", []).append(f"{host}:{port}:5432/tcp")
        service.setdefault("volumes", []).extend(
            ["./ha/pg_hba.conf:/etc/postgresql/pg_hba.conf:ro", "./ha/replication.pgpass:/run/secrets/replication.pgpass:ro"]
        )
    if "redis" in services:
        services["redis"].setdefault("ports", []).append(f"{host}:{VALKEY_PORT}:6379/tcp")
    # The databases and Valkey sit on internal networks. Replication is an outbound connection to the peer,
    # so each of them also joins the egress network; the listeners stay bound to the replication address only.
    for name in ("control-db", "pdns-db", "redis"):
        service = services.get(name)
        if service is None:
            continue
        networks = service.setdefault("networks", [])
        if isinstance(networks, dict):
            networks.setdefault("egress", {})
        elif "egress" not in networks:
            networks.append("egress")
    # The application cache shares the Valkey password but must never follow the queue instance's primary.
    cache = services.get("cache")
    if cache is not None:
        cache["volumes"] = [
            "./secrets/valkey-cache.conf:/run/secrets/valkey.conf:ro" if str(volume).endswith(":/run/secrets/valkey.conf:ro") else volume
            for volume in cache.get("volumes", [])
        ]
    if "caddy" in services and section(state).get("ingress_certificate"):
        services["caddy"].setdefault("volumes", []).append("./ha/tls.d:/etc/caddy/tls.d:ro")


def ha_env_file(state: dict[str, Any], node: dict[str, Any]) -> str:
    peer = peer_of(state, node["name"])
    assert peer is not None
    values = {
        "HA_NODE": node["name"],
        "HA_ROLE": role_of(state, node["name"]) or "",
        "HA_PEER_ADDRESS": replication_address(state, peer["name"]),
        "HA_SELF_ADDRESS": replication_address(state, node["name"]),
        "HA_CONTROL_DB_PORT": str(CONTROL_DB_PORT),
        "HA_PDNS_DB_PORT": str(PDNS_DB_PORT),
    }
    return "".join(f"{key}={value}\n" for key, value in values.items())


def write_files(store: Any, repo_root: Path, state: dict[str, Any], node: dict[str, Any], destination: Path) -> None:
    """The node's `ha/` directory and the secrets its scripts read."""
    if node["role"] != "control" or not enabled(state):
        return
    source = repo_root / HA_SCRIPT_SOURCE
    target = destination / "ha"
    target.mkdir(parents=True, exist_ok=True, mode=0o700)
    for script in HA_SCRIPTS:
        path = source / script
        if not path.exists():
            raise RenderError(f"Missing HA script in the repository: {HA_SCRIPT_SOURCE / script}")
        copy = target / script
        shutil.copy2(path, copy)
        copy.chmod(0o700)
    password = store.read_secret("control-replication-password")
    atomic_write(destination / "secrets" / "valkey-cache.conf", f"requirepass {store.read_secret('valkey-password')}\n", 0o640)
    atomic_write(target / "ha.env", ha_env_file(state, node), 0o600)
    atomic_write(target / "pg_hba.conf", pg_hba(state, node), 0o644)
    atomic_write(destination / "secrets" / "replication-password", password + "\n", 0o600)
    # libpq reads this inside the database containers (uid 70); start.sh sets the owner.
    peer = peer_of(state, node["name"])
    assert peer is not None
    peer_address = replication_address(state, peer["name"])
    atomic_write(target / "replication.pgpass", "".join(f"{peer_address}:{port}:*:replicator:{password}\n" for port in (CONTROL_DB_PORT, PDNS_DB_PORT)), 0o600)
    if section(state).get("ingress_certificate"):
        tls = target / "tls.d"
        tls.mkdir(parents=True, exist_ok=True, mode=0o700)
        certificate = store.state_dir / "ha" / "ingress.crt"
        key = store.state_dir / "ha" / "ingress.key"
        if not certificate.exists() or not key.exists():
            raise RenderError("The operator-supplied control ingress certificate is missing from the fleet state")
        atomic_write(tls / "ingress.crt", certificate.read_text(encoding="utf-8"), 0o644)
        atomic_write(tls / "ingress.key", key.read_text(encoding="utf-8"), 0o600)
        atomic_write(tls / "control.caddy", "tls /etc/caddy/tls.d/ingress.crt /etc/caddy/tls.d/ingress.key\n", 0o644)
        atomic_write(target / "ingress.crt", certificate.read_text(encoding="utf-8"), 0o644)


def start_script(state: dict[str, Any], node: dict[str, Any]) -> str | None:
    """A standby starts its replicas only. The application services wait for `promote`."""
    if node["role"] == "control" and role_of(state, node["name"]) == "standby":
        return """#!/usr/bin/env sh
set -eu
if [ "$(id -u)" != "0" ]; then
    echo "Control activation must run as root so replication credentials and shared secrets keep their owners." >&2
    exit 1
fi
./ha/permissions.sh
./validate.sh
./firewall.sh
exec ./ha/start-standby.sh
"""
    return None


def permissions_snippet(state: dict[str, Any], node: dict[str, Any]) -> str:
    if node["role"] == "control" and enabled(state):
        return "chown 70:70 ha/replication.pgpass\nchmod 0600 ha/replication.pgpass\nchown 0:1000 secrets/valkey-cache.conf\nchmod 0640 secrets/valkey-cache.conf\n"
    return ""
