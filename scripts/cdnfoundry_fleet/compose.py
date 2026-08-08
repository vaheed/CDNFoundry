from __future__ import annotations

import copy
import re
from dataclasses import dataclass
from pathlib import Path
from typing import Any, Iterable

try:
    import yaml
    from yaml.nodes import MappingNode, ScalarNode, SequenceNode
except ImportError as exc:  # pragma: no cover - handled by prerequisite installer
    raise RuntimeError("PyYAML is required; install python3-yaml") from exc

from .common import RenderError, ValidationError

VAR_RE = re.compile(r"\$\{([A-Z][A-Z0-9_]*)(?:(:?[-+?])[^}]*)?\}")
ROLE_PROFILES = {
    "control": {"control"},
    "dns": {"dns"},
    "edge": {"edge"},
    "dns-edge": {"dns", "edge"},
    "monitoring": {"telemetry"},
}


@dataclass(frozen=True)
class _ResetValue:
    value: Any


@dataclass(frozen=True)
class _OverrideValue:
    value: Any


class ComposeLoader(yaml.SafeLoader):
    """Safe YAML loader with Docker Compose's !reset and !override tags."""


def _construct_tagged(loader: ComposeLoader, node: yaml.Node, wrapper: type[_ResetValue] | type[_OverrideValue]) -> Any:
    if isinstance(node, MappingNode):
        value = loader.construct_mapping(node, deep=True)
    elif isinstance(node, SequenceNode):
        value = loader.construct_sequence(node, deep=True)
    elif isinstance(node, ScalarNode):
        value = loader.construct_scalar(node)
    else:  # pragma: no cover - PyYAML currently exposes only these node types
        raise yaml.constructor.ConstructorError(None, None, f"Unsupported tagged YAML node: {type(node).__name__}", node.start_mark)
    return wrapper(value)


ComposeLoader.add_constructor("!reset", lambda loader, node: _construct_tagged(loader, node, _ResetValue))
ComposeLoader.add_constructor("!override", lambda loader, node: _construct_tagged(loader, node, _OverrideValue))


def load_yaml(path: Path) -> dict[str, Any]:
    try:
        data = yaml.load(path.read_text(encoding="utf-8"), Loader=ComposeLoader) or {}
    except FileNotFoundError as exc:
        raise RenderError(f"Missing Compose source: {path}") from exc
    except yaml.YAMLError as exc:
        raise RenderError(f"Invalid YAML in {path}: {exc}") from exc
    if not isinstance(data, dict):
        raise RenderError(f"Compose source must be a mapping: {path}")
    return data


def dump_yaml(data: dict[str, Any]) -> str:
    return yaml.safe_dump(data, sort_keys=False, default_flow_style=False, width=120)


def merge_compose(base: dict[str, Any], overlay: dict[str, Any]) -> dict[str, Any]:
    return _deep_merge(copy.deepcopy(base), overlay)


def _deep_merge(left: Any, right: Any, key: str | None = None) -> Any:
    if isinstance(right, (_ResetValue, _OverrideValue)):
        return copy.deepcopy(right.value)
    if isinstance(left, dict) and isinstance(right, dict):
        result = copy.deepcopy(left)
        for child_key, value in right.items():
            if child_key in result:
                result[child_key] = _deep_merge(result[child_key], value, child_key)
            else:
                result[child_key] = _deep_merge(None, value, child_key)
        return result
    if isinstance(left, list) and isinstance(right, list):
        if key in {"command", "entrypoint", "healthcheck"}:
            return copy.deepcopy(right)
        return copy.deepcopy(left) + copy.deepcopy(right)
    return copy.deepcopy(right)


def profile_set(service: dict[str, Any]) -> set[str]:
    raw = service.get("profiles", [])
    if isinstance(raw, str):
        return {raw}
    return {str(item) for item in raw}


def select_services(
    compose: dict[str, Any],
    *,
    role: str,
    monitoring_enabled: bool,
    logs_enabled: bool,
    monitoring_host: bool,
) -> dict[str, Any]:
    if role not in ROLE_PROFILES:
        raise ValidationError(f"Unsupported role: {role}")
    services = compose.get("services", {})
    if not isinstance(services, dict):
        raise RenderError("Compose file has no services mapping")

    active_profiles = set(ROLE_PROFILES[role])
    if monitoring_host:
        active_profiles.add("telemetry")
    if logs_enabled:
        active_profiles.add("logs")

    selected: set[str] = set()
    for name, service in services.items():
        profiles = profile_set(service)
        if not profiles or profiles & active_profiles:
            if name == "vector" and not monitoring_enabled:
                continue
            # The control gateway already serves colocated telemetry and Grafana.
            # The dedicated gateway owns the same public ports only on monitoring-role hosts.
            if name == "telemetry-gateway" and role == "control":
                continue
            selected.add(name)

    # Tool containers are role-specific and remain behind the tools profile.
    if role == "control" and "migrate" in services:
        selected.add("migrate")
    if role in {"dns", "dns-edge"} and "pdns-migrate" in services:
        selected.add("pdns-migrate")

    # Every monitored production host exports node metrics; only the monitoring host runs the full stack.
    if monitoring_enabled and "node-exporter" in services:
        selected.add("node-exporter")
    if logs_enabled and "log-collector" in services:
        selected.add("log-collector")

    selected = dependency_closure(services, selected)
    rendered_services: dict[str, Any] = {}
    for name in sorted(selected):
        service = copy.deepcopy(services[name])
        profiles = profile_set(service)
        if name not in {"migrate", "pdns-migrate"}:
            service.pop("profiles", None)
        elif "tools" not in profiles:
            service["profiles"] = ["tools"]
        rendered_services[name] = service

    result: dict[str, Any] = {"services": rendered_services}
    for section in ("networks", "volumes", "configs", "secrets"):
        values = compose.get(section)
        if isinstance(values, dict):
            used = referenced_top_level(rendered_services, section)
            result[section] = {k: copy.deepcopy(v) for k, v in values.items() if k in used}
    return result

def prune_top_level(compose: dict[str, Any]) -> None:
    services = compose.get("services", {})
    if not isinstance(services, dict):
        return
    for section in ("networks", "volumes", "configs", "secrets"):
        values = compose.get(section)
        if isinstance(values, dict):
            used = referenced_top_level(services, section)
            compose[section] = {key: value for key, value in values.items() if key in used}


def dependency_closure(services: dict[str, Any], initial: Iterable[str]) -> set[str]:
    selected = set(initial)
    changed = True
    while changed:
        changed = False
        for name in list(selected):
            service = services.get(name)
            if not isinstance(service, dict):
                raise RenderError(f"Service {name!r} is missing")
            depends = service.get("depends_on", {})
            names = depends.keys() if isinstance(depends, dict) else depends if isinstance(depends, list) else []
            for dep in names:
                if dep not in services:
                    raise RenderError(f"Service {name!r} depends on missing service {dep!r}")
                if dep not in selected:
                    selected.add(dep)
                    changed = True
    return selected


def referenced_top_level(services: dict[str, Any], section: str) -> set[str]:
    used: set[str] = set()
    for service in services.values():
        raw = service.get(section, [])
        if isinstance(raw, dict):
            used.update(raw)
        elif isinstance(raw, list):
            for item in raw:
                if isinstance(item, str):
                    source = item.split(":", 1)[0]
                    if source and not source.startswith((".", "/", "~")) and "${" not in source:
                        used.add(source)
                elif isinstance(item, dict) and item.get("source"):
                    used.add(str(item["source"]))
    return used


def required_env(compose: dict[str, Any]) -> set[str]:
    text = dump_yaml(compose)
    required: set[str] = set()
    for name, operator in VAR_RE.findall(text):
        if operator in {"", ":?"}:
            required.add(name)
    return required


def bind_mount_sources(compose: dict[str, Any]) -> set[Path]:
    result: set[Path] = set()
    for service in compose.get("services", {}).values():
        for item in service.get("volumes", []) or []:
            if not isinstance(item, str):
                continue
            source = item.split(":", 1)[0]
            if source.startswith("./") and "${" not in source:
                result.add(Path(source[2:]))
    return result
