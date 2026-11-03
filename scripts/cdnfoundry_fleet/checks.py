"""Stack checks behind `cdnfoundry-fleet doctor` and the fleet web "Run check" button."""
from __future__ import annotations

import json
from dataclasses import asdict, dataclass
from typing import Any

from .ssh import SshError, Target


@dataclass
class Check:
    node: str
    name: str
    ok: bool
    detail: str = ""


def check_node(transport: Any, target: Target, expected_release: str) -> list[Check]:
    name = target.name
    try:
        reachable = transport.run(target, "true")
    except SshError as exc:
        return [Check(name, "Host reachable", False, str(exc))]
    if not reachable.ok:
        return [Check(name, "Host reachable", False, (reachable.stderr or "ssh failed").strip()[:200])]
    checks = [Check(name, "Host reachable", True)]
    ps = transport.run(target, "docker ps -a --format '{{json .}}'")
    if not ps.ok:
        checks.append(Check(name, "Containers healthy", False, "docker ps failed"))
    else:
        rows = [json.loads(line) for line in ps.stdout.splitlines() if line.strip()]
        bad = [r["Names"] for r in rows if "unhealthy" in r.get("Status", "") or not r.get("Status", "").startswith("Up")]
        checks.append(Check(name, "Containers healthy", not bad, ", ".join(bad[:5]) + (" and more" if len(bad) > 5 else "")))
    release = transport.run(target, "cat /opt/cdnfoundry/RELEASE 2>/dev/null")
    running = release.stdout.strip()
    checks.append(Check(name, "Release matches", running == expected_release, f"running {running or 'unknown'}, expected {expected_release}"))
    return checks


def run_checks(transport: Any, targets: dict[str, Target], release: str) -> list[Check]:
    out: list[Check] = []
    for target in targets.values():
        out += check_node(transport, target, release)
    return out


def as_dicts(checks: list[Check]) -> list[dict[str, Any]]:
    return [asdict(c) for c in checks]
