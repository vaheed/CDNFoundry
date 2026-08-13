from __future__ import annotations

import base64
import hashlib
import ipaddress
import json
import os
import re
import secrets
import stat
import tempfile
from pathlib import Path
from typing import Any, Iterable


class FleetError(Exception):
    """Base class for expected operator errors."""


class ValidationError(FleetError):
    pass


class StateError(FleetError):
    pass


class RenderError(FleetError):
    pass


NODE_RE = re.compile(r"^[a-z][a-z0-9-]{1,62}$")
HOST_RE = re.compile(
    r"^(?=.{1,253}$)(?:[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?\.)*"
    r"[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?$"
)
REGION_RE = re.compile(r"^[A-Za-z0-9][A-Za-z0-9_. -]{0,63}$")
ENV_KEY_RE = re.compile(r"^[A-Z][A-Z0-9_]*$")


def utc_now() -> str:
    from datetime import datetime, timezone

    return datetime.now(timezone.utc).replace(microsecond=0).isoformat()


def random_secret(bytes_: int = 32) -> str:
    return secrets.token_hex(bytes_)


def laravel_app_key() -> str:
    """Return an AES-256 Laravel key containing exactly 32 decoded bytes."""
    return "base64:" + base64.b64encode(secrets.token_bytes(32)).decode("ascii")


def validate_laravel_app_key(value: str) -> str:
    if not value.startswith("base64:"):
        raise ValidationError("Application key must use Laravel's base64: format")
    try:
        decoded = base64.b64decode(value.removeprefix("base64:"), validate=True)
    except (ValueError, TypeError) as exc:
        raise ValidationError("Application key contains invalid base64 data") from exc
    if len(decoded) != 32:
        raise ValidationError("Application key must decode to exactly 32 bytes for AES-256-CBC")
    return value


def ensure_mode(path: Path, mode: int) -> None:
    current = stat.S_IMODE(path.stat().st_mode)
    if current != mode:
        path.chmod(mode)


def atomic_write(path: Path, data: str | bytes, mode: int = 0o600) -> None:
    path.parent.mkdir(parents=True, exist_ok=True, mode=0o700)
    try:
        path.parent.chmod(0o700)
    except PermissionError:
        pass
    payload = data.encode("utf-8") if isinstance(data, str) else data
    fd, tmp_name = tempfile.mkstemp(prefix=f".{path.name}.", dir=path.parent)
    tmp = Path(tmp_name)
    try:
        os.fchmod(fd, mode)
        with os.fdopen(fd, "wb", closefd=True) as handle:
            handle.write(payload)
            handle.flush()
            os.fsync(handle.fileno())
        os.replace(tmp, path)
        os.chmod(path, mode)
        dir_fd = os.open(path.parent, os.O_RDONLY)
        try:
            os.fsync(dir_fd)
        finally:
            os.close(dir_fd)
    finally:
        tmp.unlink(missing_ok=True)


def atomic_json(path: Path, value: Any, mode: int = 0o600) -> None:
    atomic_write(path, json.dumps(value, indent=2, sort_keys=True) + "\n", mode)


def load_json(path: Path) -> Any:
    try:
        return json.loads(path.read_text(encoding="utf-8"))
    except FileNotFoundError as exc:
        raise StateError(f"Missing file: {path}") from exc
    except json.JSONDecodeError as exc:
        raise StateError(f"Invalid JSON in {path}: {exc}") from exc


def sha256_file(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as handle:
        for chunk in iter(lambda: handle.read(1024 * 1024), b""):
            digest.update(chunk)
    return digest.hexdigest()


def validate_node_name(value: str) -> str:
    if not NODE_RE.fullmatch(value):
        raise ValidationError(
            "Node name must start with a lowercase letter and contain only lowercase letters, digits, and hyphens"
        )
    return value

def validate_hostname(value: str) -> str:
    value = value.rstrip(".")
    if not HOST_RE.fullmatch(value):
        raise ValidationError(f"Invalid hostname: {value!r}")
    return value.lower()


def validate_region(value: str, label: str = "region") -> str:
    if not REGION_RE.fullmatch(value):
        raise ValidationError(f"Invalid {label}: {value!r}")
    return value


def validate_ip(value: str | None, *, required: bool = False) -> str | None:
    if value in (None, ""):
        if required:
            raise ValidationError("An IP address is required")
        return None
    try:
        return str(ipaddress.ip_address(value))
    except ValueError as exc:
        raise ValidationError(f"Invalid IP address: {value!r}") from exc


def validate_release(value: str) -> str:
    if not re.fullmatch(r"[A-Za-z0-9][A-Za-z0-9._+-]{0,127}", value):
        raise ValidationError("Release must be an exact tag or commit identifier")
    if value in {"latest", "main", "master"}:
        raise ValidationError("Moving release identifiers are not allowed")
    return value


def validate_env_mapping(values: dict[str, Any]) -> dict[str, str]:
    clean: dict[str, str] = {}
    for key, value in values.items():
        if not ENV_KEY_RE.fullmatch(key):
            raise ValidationError(f"Invalid environment key: {key!r}")
        text = str(value)
        if "\x00" in text or "\n" in text or "\r" in text:
            raise ValidationError(f"Environment value for {key} contains a line break or NUL")
        clean[key] = text
    return clean


def validate_gateway_address_map(value: str) -> str:
    try:
        configured = json.loads(value)
    except json.JSONDecodeError as exc:
        raise ValidationError("EDGE_GATEWAY_ADDRESS_MAP must be a JSON object") from exc
    if not isinstance(configured, dict) or len(configured) > 64:
        raise ValidationError("EDGE_GATEWAY_ADDRESS_MAP must be a JSON object with at most 64 IP address pairs")

    private_networks = (
        ipaddress.ip_network("10.0.0.0/8"),
        ipaddress.ip_network("172.16.0.0/12"),
        ipaddress.ip_network("192.168.0.0/16"),
        ipaddress.ip_network("fc00::/7"),
    )
    local_addresses: set[str] = set()
    for advertised_raw, local_raw in configured.items():
        if not isinstance(advertised_raw, str) or not isinstance(local_raw, str):
            raise ValidationError("EDGE_GATEWAY_ADDRESS_MAP keys and values must be IP address strings")
        try:
            advertised = ipaddress.ip_address(advertised_raw)
            local = ipaddress.ip_address(local_raw)
        except ValueError as exc:
            raise ValidationError("EDGE_GATEWAY_ADDRESS_MAP contains an invalid IP address") from exc
        if advertised.is_unspecified or local.is_unspecified:
            raise ValidationError("EDGE_GATEWAY_ADDRESS_MAP does not allow wildcard addresses")
        if advertised.version != local.version:
            raise ValidationError("EDGE_GATEWAY_ADDRESS_MAP address pairs must use the same IP family")
        if advertised != local and not any(local in network for network in private_networks):
            raise ValidationError(
                "EDGE_GATEWAY_ADDRESS_MAP local IP must be private or exactly match its advertised IP"
            )
        canonical_local = str(local)
        if canonical_local in local_addresses:
            raise ValidationError("EDGE_GATEWAY_ADDRESS_MAP local addresses must be unique")
        local_addresses.add(canonical_local)
    return value


def unique_nonempty(values: Iterable[str | None]) -> bool:
    items = [item for item in values if item]
    return len(items) == len(set(items))


def quote_env(value: str) -> str:
    # Docker env files accept unquoted values; reject line breaks at validation time.
    if value == "" or re.search(r"[\s#'\"\\]", value):
        escaped = value.replace("\\", "\\\\").replace('"', '\\"')
        return f'"{escaped}"'
    return value
