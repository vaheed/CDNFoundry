from __future__ import annotations

import copy
import fcntl
import os
import shutil
from contextlib import contextmanager
from pathlib import Path
from typing import Any, Iterator

from .common import (
    StateError,
    ValidationError,
    atomic_json,
    atomic_write,
    ensure_mode,
    load_json,
    laravel_app_key,
    random_secret,
    utc_now,
    validate_env_mapping,
    validate_hostname,
    validate_ip,
    validate_node_name,
    validate_region,
    validate_release,
)

SCHEMA_VERSION = 1
ROLES = {"control", "edge", "dns", "dns-edge", "monitoring"}
MONITORING_MODES = {"disabled", "colocated", "dedicated"}
LOG_MODES = {"disabled", "centralized"}
BACKUP_MODES = {"disabled", "control", "all-stateful"}

GLOBAL_SECRET_NAMES = {
    "app-key",
    "artifact-signing-key",
    "control-db-password",
    "valkey-password",
    "grafana-admin-password",
    "grafana-clickhouse-password",
    "grafana-postgres-password",
    "clickhouse-password",
    "metrics-token",
    "telemetry-token",
    "backup-password",
    "backup-access-key",
    "backup-secret-key",
}

NODE_SECRET_NAMES = {
    "pdns-db-password",
    "pdns-api-key",
    "edge-status-token",
    "edge-bootstrap-token",
    "log-auth-token",
    "node-exporter-token",
}


class FleetState:
    def __init__(self, state_dir: Path, *, dry_run: bool = False) -> None:
        self.state_dir = state_dir.resolve()
        self.state_file = self.state_dir / "fleet.json"
        self.secrets_dir = self.state_dir / "secrets"
        self.pki_dir = self.state_dir / "pki"
        self.lock_file = self.state_dir / ".fleet.lock"
        self.backup_dir = self.state_dir / "history"
        self.dry_run = dry_run

    @contextmanager
    def locked(self, *, exclusive: bool = True) -> Iterator[None]:
        if self.dry_run and not self.state_dir.exists():
            yield
            return
        self.state_dir.mkdir(parents=True, exist_ok=True, mode=0o700)
        try:
            self.state_dir.chmod(0o700)
        except PermissionError:
            pass
        fd = os.open(self.lock_file, os.O_CREAT | os.O_RDWR, 0o600)
        try:
            mode = fcntl.LOCK_EX if exclusive else fcntl.LOCK_SH
            try:
                fcntl.flock(fd, mode | fcntl.LOCK_NB)
            except BlockingIOError as exc:
                raise StateError("Another fleet generator process is already running") from exc
            yield
        finally:
            fcntl.flock(fd, fcntl.LOCK_UN)
            os.close(fd)

    def exists(self) -> bool:
        return self.state_file.exists()

    def load(self) -> dict[str, Any]:
        state = load_json(self.state_file)
        self.validate(state, require_secrets=False)
        return state

    def init(self, config: dict[str, Any]) -> dict[str, Any]:
        if self.exists():
            raise StateError(f"Fleet state already exists at {self.state_file}")
        global_cfg = config.get("global", config)
        now = utc_now()
        state: dict[str, Any] = {
            "schema_version": SCHEMA_VERSION,
            "global": {
                "operator_domain": validate_hostname(global_cfg["operator_domain"]),
                "platform_domain": validate_hostname(global_cfg["platform_domain"]),
                "release": validate_release(global_cfg["release"]),
                "acme_email": str(global_cfg.get("acme_email", "")),
                "ipv6": bool(global_cfg.get("ipv6", False)),
            },
            "features": {
                "monitoring": {"mode": "disabled", "host": None},
                "logs": {"mode": "disabled", "host": None},
                "backups": {"mode": "disabled", "repository": None, "region": None},
            },
            "nodes": {},
            "metadata": {
                "created_at": now,
                "updated_at": now,
                "last_successful_validation": None,
                "last_successful_render": None,
                "generation": 1,
            },
        }
        self.validate(state, require_secrets=False)
        if not self.dry_run:
            self._prepare_dirs()
            self._ensure_global_secrets()
            self._write(state, backup=False)
        return state

    def _prepare_dirs(self) -> None:
        for directory in (self.state_dir, self.secrets_dir, self.pki_dir, self.backup_dir):
            directory.mkdir(parents=True, exist_ok=True, mode=0o700)
            ensure_mode(directory, 0o700)

    def _write(self, state: dict[str, Any], *, backup: bool = True) -> None:
        self.validate(state, require_secrets=not self.dry_run)
        if self.dry_run:
            return
        self._prepare_dirs()
        if backup and self.state_file.exists():
            stamp = utc_now().replace(":", "").replace("+00:00", "Z")
            target = self.backup_dir / f"fleet-{stamp}.json"
            shutil.copy2(self.state_file, target)
            target.chmod(0o600)
        state["metadata"]["updated_at"] = utc_now()
        state["metadata"]["generation"] = int(state["metadata"].get("generation", 0)) + 1
        atomic_json(self.state_file, state, 0o600)
        self._prune_history(20)

    def _prune_history(self, keep: int) -> None:
        entries = sorted(self.backup_dir.glob("fleet-*.json"), reverse=True)
        for path in entries[keep:]:
            path.unlink(missing_ok=True)

    def transaction(self) -> "StateTransaction":
        return StateTransaction(self)

    def validate(self, state: dict[str, Any], *, require_secrets: bool = True) -> None:
        if state.get("schema_version") != SCHEMA_VERSION:
            raise ValidationError(f"Unsupported fleet schema version: {state.get('schema_version')!r}")
        global_cfg = state.get("global", {})
        validate_hostname(global_cfg.get("operator_domain", ""))
        validate_hostname(global_cfg.get("platform_domain", ""))
        validate_release(global_cfg.get("release", ""))
        features = state.get("features", {})
        monitoring = features.get("monitoring", {})
        logs = features.get("logs", {})
        backups = features.get("backups", {})
        if monitoring.get("mode") not in MONITORING_MODES:
            raise ValidationError("Invalid monitoring mode")
        if logs.get("mode") not in LOG_MODES:
            raise ValidationError("Invalid logs mode")
        if backups.get("mode") not in BACKUP_MODES:
            raise ValidationError("Invalid backup mode")

        names: set[str] = set()
        hostnames: set[str] = set()
        ips: set[str] = set()
        monitoring_targets: set[str] = set()
        for key, node in state.get("nodes", {}).items():
            name = validate_node_name(key)
            if node.get("name") != name:
                raise ValidationError(f"Node key/name mismatch for {key}")
            if name in names:
                raise ValidationError(f"Duplicate node name: {name}")
            names.add(name)
            if node.get("role") not in ROLES:
                raise ValidationError(f"Invalid role for {name}: {node.get('role')}")
            validate_region(node.get("region", ""))
            validate_region(node.get("location", ""), "location")
            hostname = validate_hostname(node.get("hostname", ""))
            if hostname in hostnames:
                raise ValidationError(f"Duplicate hostname: {hostname}")
            hostnames.add(hostname)
            for field in ("public_ipv4", "public_ipv6", "bind_ipv4", "bind_ipv6", "monitor_ipv4", "monitor_ipv6", "log_ipv4", "log_ipv6"):
                value = validate_ip(node.get(field), required=field in {"public_ipv4", "bind_ipv4"})
                if value and field in {"public_ipv4", "public_ipv6", "monitor_ipv4", "monitor_ipv6", "log_ipv4", "log_ipv6"}:
                    if value in ips:
                        raise ValidationError(f"Duplicate fleet IP address: {value}")
                    ips.add(value)
            target = node.get("monitor_ipv4") or node.get("public_ipv4")
            if target in monitoring_targets:
                raise ValidationError(f"Duplicate monitoring target: {target}")
            monitoring_targets.add(target)
            validate_env_mapping(node.get("extra_env", {}))
            if node.get("release"):
                validate_release(node["release"])

            if require_secrets:
                self._validate_node_secrets(node)

        if monitoring.get("mode") == "dedicated":
            host = monitoring.get("host")
            if host not in names or state["nodes"][host]["role"] != "monitoring":
                raise ValidationError("Dedicated monitoring requires an existing monitoring-role node")
        if logs.get("mode") == "centralized" and logs.get("host") not in names:
            raise ValidationError("Centralized logs require an existing log host")
        if require_secrets:
            for secret in GLOBAL_SECRET_NAMES:
                path = self.secret_path(secret)
                if not path.exists():
                    raise ValidationError(f"Missing global secret: {secret}")
                ensure_mode(path, 0o600)

    def _validate_node_secrets(self, node: dict[str, Any]) -> None:
        required = {"node-exporter-token"}
        if node["role"] in {"dns", "dns-edge"}:
            required |= {"pdns-db-password", "pdns-api-key"}
        if node["role"] in {"edge", "dns-edge"}:
            required |= {"edge-status-token"}
        if self.load_feature_mode("logs") == "centralized":
            required |= {"log-auth-token"}
        for secret in required:
            path = self.secret_path(secret, node=node["name"])
            if not path.exists():
                raise ValidationError(f"Missing {secret} for node {node['name']}")
            ensure_mode(path, 0o600)

    def load_feature_mode(self, feature: str) -> str:
        try:
            return load_json(self.state_file)["features"][feature]["mode"]
        except Exception:
            return "disabled"

    def add_node(self, state: dict[str, Any], node: dict[str, Any]) -> dict[str, Any]:
        name = validate_node_name(node["name"])
        if name in state["nodes"]:
            raise ValidationError(f"Node already exists: {name}")
        clean = self._normalize_node(state, node)
        candidate = copy.deepcopy(state)
        candidate["nodes"][name] = clean
        self.validate(candidate, require_secrets=False)
        if not self.dry_run:
            self._ensure_node_secrets(clean)
        self._write(candidate)
        return candidate

    def update_node(self, state: dict[str, Any], name: str, changes: dict[str, Any]) -> dict[str, Any]:
        validate_node_name(name)
        if name not in state["nodes"]:
            raise ValidationError(f"Unknown node: {name}")
        merged = copy.deepcopy(state["nodes"][name])
        merged.update({k: v for k, v in changes.items() if v is not None})
        merged["name"] = name
        clean = self._normalize_node(state, merged)
        candidate = copy.deepcopy(state)
        candidate["nodes"][name] = clean
        self.validate(candidate, require_secrets=False)
        if not self.dry_run:
            self._ensure_node_secrets(clean)
        self._write(candidate)
        return candidate

    def remove_node(self, state: dict[str, Any], name: str) -> dict[str, Any]:
        validate_node_name(name)
        if name not in state["nodes"]:
            raise ValidationError(f"Unknown node: {name}")
        candidate = copy.deepcopy(state)
        candidate["nodes"].pop(name)
        for feature in ("monitoring", "logs"):
            if candidate["features"][feature].get("host") == name:
                raise ValidationError(f"Node {name} is configured as the {feature} host")
        self.validate(candidate, require_secrets=False)
        self._write(candidate)
        return candidate

    def _normalize_node(self, state: dict[str, Any], node: dict[str, Any]) -> dict[str, Any]:
        role = node.get("role")
        if role not in ROLES:
            raise ValidationError(f"Invalid role: {role!r}")
        name = validate_node_name(node["name"])
        operator_domain = state["global"]["operator_domain"]
        hostname = node.get("hostname") or f"{name}.{operator_domain}"
        return {
            "name": name,
            "role": role,
            "region": validate_region(node.get("region", "global")),
            "location": validate_region(node.get("location", node.get("region", "global")), "location"),
            "hostname": validate_hostname(hostname),
            "public_ipv4": validate_ip(node.get("public_ipv4"), required=True),
            "public_ipv6": validate_ip(node.get("public_ipv6")),
            "bind_ipv4": validate_ip(node.get("bind_ipv4") or "0.0.0.0", required=True),
            "bind_ipv6": validate_ip(node.get("bind_ipv6") or ("::" if state["global"].get("ipv6") else None)),
            "monitor_ipv4": validate_ip(node.get("monitor_ipv4")),
            "monitor_ipv6": validate_ip(node.get("monitor_ipv6")),
            "log_ipv4": validate_ip(node.get("log_ipv4")),
            "log_ipv6": validate_ip(node.get("log_ipv6")),
            "release": validate_release(node.get("release") or state["global"]["release"]),
            "extra_env": validate_env_mapping(node.get("extra_env", {})),
            "enabled": bool(node.get("enabled", True)),
            "draining": bool(node.get("draining", False)),
            "health": {
                "failure_threshold": int(node.get("health", {}).get("failure_threshold", 3)),
                "success_threshold": int(node.get("health", {}).get("success_threshold", 2)),
                "stale_after_seconds": int(node.get("health", {}).get("stale_after_seconds", 90)),
            },
        }

    def configure_feature(self, state: dict[str, Any], feature: str, config: dict[str, Any]) -> dict[str, Any]:
        candidate = copy.deepcopy(state)
        if feature == "monitoring":
            mode = config["mode"]
            if mode not in MONITORING_MODES:
                raise ValidationError("Invalid monitoring mode")
            candidate["features"][feature] = {"mode": mode, "host": config.get("host")}
        elif feature == "logs":
            mode = config["mode"]
            if mode not in LOG_MODES:
                raise ValidationError("Invalid logs mode")
            candidate["features"][feature] = {
                "mode": mode,
                "host": config.get("host"),
                "endpoint": config.get("endpoint"),
            }
            if mode == "centralized" and not self.dry_run:
                for node in candidate["nodes"].values():
                    self.ensure_secret("log-auth-token", node=node["name"])
        elif feature == "backups":
            mode = config["mode"]
            if mode not in BACKUP_MODES:
                raise ValidationError("Invalid backup mode")
            candidate["features"][feature] = {
                "mode": mode,
                "repository": config.get("repository"),
                "region": config.get("region") or "us-east-1",
            }
        else:
            raise ValidationError(f"Unknown feature: {feature}")
        self.validate(candidate, require_secrets=False)
        self._write(candidate)
        return candidate

    def secret_path(self, name: str, *, node: str | None = None) -> Path:
        if node:
            validate_node_name(node)
            return self.secrets_dir / "nodes" / node / name
        return self.secrets_dir / "global" / name

    def ensure_secret(self, name: str, *, node: str | None = None, value: str | None = None) -> Path:
        if node:
            if name not in NODE_SECRET_NAMES:
                raise ValidationError(f"Unsupported node secret: {name}")
        elif name not in GLOBAL_SECRET_NAMES:
            raise ValidationError(f"Unsupported global secret: {name}")
        path = self.secret_path(name, node=node)
        if path.exists():
            ensure_mode(path, 0o600)
            return path
        if self.dry_run:
            return path
        path.parent.mkdir(parents=True, exist_ok=True, mode=0o700)
        path.parent.chmod(0o700)
        generated = value or (laravel_app_key() if name == "app-key" else random_secret(32))
        atomic_write(path, generated + "\n", 0o600)
        return path

    def read_secret(self, name: str, *, node: str | None = None) -> str:
        path = self.secret_path(name, node=node)
        try:
            ensure_mode(path, 0o600)
            return path.read_text(encoding="utf-8").rstrip("\n")
        except FileNotFoundError as exc:
            raise StateError(f"Missing secret {name}{' for ' + node if node else ''}") from exc

    def write_secret(self, name: str, value: str, *, node: str | None = None) -> Path:
        if node:
            if name not in NODE_SECRET_NAMES:
                raise ValidationError(f"Unsupported node secret: {name}")
            validate_node_name(node)
        elif name not in GLOBAL_SECRET_NAMES:
            raise ValidationError(f"Unsupported global secret: {name}")
        clean = value.rstrip("\r\n")
        if not clean:
            raise ValidationError("Secret value must not be empty")
        path = self.secret_path(name, node=node)
        if self.dry_run:
            return path
        path.parent.mkdir(parents=True, exist_ok=True, mode=0o700)
        path.parent.chmod(0o700)
        atomic_write(path, clean + "\n", 0o600)
        return path

    def delete_secret(self, name: str, *, node: str | None = None) -> None:
        path = self.secret_path(name, node=node)
        if not self.dry_run:
            path.unlink(missing_ok=True)

    def pending_secret_path(self, name: str, *, node: str) -> Path:
        if name not in NODE_SECRET_NAMES:
            raise ValidationError(f"Unsupported node secret: {name}")
        validate_node_name(node)
        return self.secrets_dir / "pending" / "nodes" / node / name

    def prepare_secret_rotation(self, name: str, *, node: str) -> Path:
        current = self.secret_path(name, node=node)
        if not current.exists():
            raise ValidationError(f"Secret does not exist: {name}")
        pending = self.pending_secret_path(name, node=node)
        if pending.exists():
            raise ValidationError(f"A pending rotation already exists for {name} on {node}")
        if self.dry_run:
            return pending
        pending.parent.mkdir(parents=True, exist_ok=True, mode=0o700)
        pending.parent.chmod(0o700)
        atomic_write(pending, random_secret(32) + "\n", 0o600)
        return pending

    def commit_secret_rotation(self, name: str, *, node: str) -> None:
        current = self.secret_path(name, node=node)
        pending = self.pending_secret_path(name, node=node)
        if not current.exists():
            raise ValidationError(f"Secret does not exist: {name}")
        if not pending.exists():
            raise ValidationError(f"No pending rotation exists for {name} on {node}")
        if self.dry_run:
            return
        old = current.read_text(encoding="utf-8")
        new = pending.read_text(encoding="utf-8")
        archive = current.parent / ".previous"
        archive.mkdir(parents=True, exist_ok=True, mode=0o700)
        archive.chmod(0o700)
        atomic_write(archive / f"{name}-{utc_now().replace(':', '')}", old, 0o600)
        atomic_write(current, new, 0o600)
        pending.unlink()

    def abort_secret_rotation(self, name: str, *, node: str) -> None:
        pending = self.pending_secret_path(name, node=node)
        if not pending.exists():
            raise ValidationError(f"No pending rotation exists for {name} on {node}")
        if not self.dry_run:
            pending.unlink()

    def rotate_secret(self, name: str, *, node: str | None = None) -> None:
        path = self.secret_path(name, node=node)
        if not path.exists():
            raise ValidationError(f"Secret does not exist: {name}")
        if self.dry_run:
            return
        old = path.read_text(encoding="utf-8")
        archive = path.parent / ".previous"
        archive.mkdir(parents=True, exist_ok=True, mode=0o700)
        archive.chmod(0o700)
        atomic_write(archive / f"{name}-{utc_now().replace(':', '')}", old, 0o600)
        value = laravel_app_key() if name == "app-key" else random_secret(32)
        atomic_write(path, value + "\n", 0o600)

    def _ensure_global_secrets(self) -> None:
        for name in sorted(GLOBAL_SECRET_NAMES):
            self.ensure_secret(name)

    def _ensure_node_secrets(self, node: dict[str, Any]) -> None:
        self.ensure_secret("node-exporter-token", node=node["name"])
        if node["role"] in {"dns", "dns-edge"}:
            self.ensure_secret("pdns-db-password", node=node["name"])
            self.ensure_secret("pdns-api-key", node=node["name"])
        if node["role"] in {"edge", "dns-edge"}:
            self.ensure_secret("edge-status-token", node=node["name"])
        if self.load_feature_mode("logs") == "centralized":
            self.ensure_secret("log-auth-token", node=node["name"])


class StateTransaction:
    def __init__(self, store: FleetState) -> None:
        self.store = store
        self.original: dict[str, Any] | None = None
        self.candidate: dict[str, Any] | None = None

    def __enter__(self) -> dict[str, Any]:
        self.original = self.store.load()
        self.candidate = copy.deepcopy(self.original)
        return self.candidate

    def __exit__(self, exc_type: object, exc: object, tb: object) -> bool:
        if exc_type is None and self.candidate is not None:
            self.store._write(self.candidate)
        return False
