"""Plan, then apply, for Fleet infrastructure actions.

An action is a list of steps. `plan` is pure. `apply` runs the steps in order,
records each finished step in a journal, and stops at the first failure. Running
the same action again reads the journal and continues after the last finished
step, so a dropped SSH connection never needs manual cleanup. Every step command
is idempotent on the node.
"""
from __future__ import annotations

import json
from dataclasses import asdict, dataclass, field
from pathlib import Path
from typing import Any, Callable, Protocol

from . import manifest as mf
from .common import FleetError, atomic_json, load_json, utc_now
from .ssh import SshError, Target

# Telemetry and monitoring go last: serving never depends on them.
ROLE_ORDER = ["dns", "dns-edge", "edge", "control", "monitoring"]
REMOTE_ROOT = "/opt/cdnfoundry"


class ActionError(FleetError):
    pass


class Transport(Protocol):
    def run(self, target: Target, command: str, *, stdin: bytes | None = None, check: bool = False) -> Any: ...
    def upload_tree(self, target: Target, local: Path, remote: str) -> None: ...


@dataclass
class Step:
    id: str
    node: str
    kind: str  # prepull | upload | activate | gate | rollback | switchover
    detail: str = ""

    def label(self) -> str:
        return f"{self.node}: {self.kind}" + (f" ({self.detail})" if self.detail else "")


@dataclass
class Plan:
    action: str
    target_release: str
    steps: list[Step] = field(default_factory=list)
    skipped: list[str] = field(default_factory=list)

    @property
    def id(self) -> str:
        import hashlib

        return hashlib.sha256(json.dumps([self.action, self.target_release, [s.id for s in self.steps]]).encode()).hexdigest()[:16]

    def describe(self) -> list[str]:
        out = [f"{self.action} to {self.target_release}"]
        out += [f"  {i + 1}. {s.label()}" for i, s in enumerate(self.steps)]
        out += [f"  unchanged, not restarted: {n}" for n in self.skipped]
        return out


def order_nodes(nodes: dict[str, dict[str, Any]], *, ha_standby: str | None = None) -> list[str]:
    """Canary first, then one node at a time per role. A control standby is upgraded before its active node."""
    names: list[str] = []
    for role in ROLE_ORDER:
        group = sorted(n for n, v in nodes.items() if v["role"] == role and v.get("enabled", True))
        if role == "control" and ha_standby in group:
            group.remove(ha_standby)
            group.insert(0, ha_standby)
        names += group
    return names


def plan_upgrade(nodes: dict[str, dict[str, Any]], current: dict[str, Any] | None, target: dict[str, Any], *, ha_standby: str | None = None) -> Plan:
    d = mf.diff(current, target)
    plan = Plan("upgrade", target["release"])
    for name in order_nodes(nodes, ha_standby=ha_standby):
        role = nodes[name]["role"]
        if not mf.node_needs_restart(role, d, target):
            plan.skipped.append(name)
            continue
        plan.steps.append(Step(f"{name}:prepull", name, "prepull"))
        plan.steps.append(Step(f"{name}:upload", name, "upload"))
        if role == "control" and ha_standby and name != ha_standby:
            plan.steps.append(Step(f"{name}:switchover", name, "switchover", "planned switchover before upgrading the old active node"))
        plan.steps.append(Step(f"{name}:activate", name, "activate"))
        plan.steps.append(Step(f"{name}:gate", name, "gate"))
    return plan


@dataclass
class Outcome:
    ok: bool
    done: list[str]
    failed: str | None = None
    detail: str = ""
    rolled_back: list[str] = field(default_factory=list)


class Journal:
    def __init__(self, path: Path) -> None:
        self.path = path

    def load(self, plan_id: str) -> dict[str, Any]:
        if self.path.exists():
            data = load_json(self.path)
            if data.get("plan") == plan_id:
                return data
        return {"plan": plan_id, "done": [], "log": []}

    def save(self, data: dict[str, Any]) -> None:
        atomic_json(self.path, data, 0o600)


def _pulled_digest(transport: Transport, target: Target, image: str) -> str | None:
    r = transport.run(target, f"docker image inspect --format '{{{{index .RepoDigests 0}}}}' {image}")
    if not r.ok:
        return None
    return r.stdout.strip().rsplit("@", 1)[-1] or None


class Engine:
    def __init__(
        self, transport: Transport, targets: dict[str, Target], nodes: dict[str, dict[str, Any]], bundles: Path,
        journal: Journal, *, gate: Callable[[str], tuple[bool, str]], switchover: Callable[[str], None] | None = None,
        mixed_versions_ok: bool = True,
    ) -> None:
        self.t, self.targets, self.nodes, self.bundles = transport, targets, nodes, bundles
        self.journal, self.gate, self.switchover = journal, gate, switchover
        self.mixed_versions_ok = mixed_versions_ok

    def apply(self, plan: Plan, manifest: dict[str, Any], *, log: Callable[[str], None] = lambda _m: None) -> Outcome:
        state = self.journal.load(plan.id)
        done: list[str] = list(state["done"])
        upgraded: list[str] = []
        for step in plan.steps:
            if step.id in done:
                if step.kind == "gate":
                    upgraded.append(step.node)
                continue
            try:
                ok, detail = self._run(step, manifest)
            except SshError as exc:  # transport fault: stop, keep the journal, the next run resumes here
                state["log"].append({"at": utc_now(), "step": step.id, "ok": False, "detail": str(exc)})
                self.journal.save(state)
                return Outcome(False, done, step.id, f"{exc} (run the action again to resume)")
            state["log"].append({"at": utc_now(), "step": step.id, "ok": ok, "detail": detail})
            log(f"{step.label()}: {'ok' if ok else 'FAILED'} {detail}".strip())
            if not ok:
                rolled = self._rollback(step.node, upgraded if not self.mixed_versions_ok else [], log)
                state["done"] = []
                self.journal.save(state)
                return Outcome(False, done, step.id, detail, rolled)
            done.append(step.id)
            state["done"] = done
            self.journal.save(state)
            if step.kind == "gate":
                upgraded.append(step.node)
        return Outcome(True, done)

    def _run(self, step: Step, manifest: dict[str, Any]) -> tuple[bool, str]:
        target = self.targets[step.node]
        remote = f"{REMOTE_ROOT}/{step.node}"
        role = self.nodes[step.node]["role"]
        if step.kind == "prepull":
            for name in manifest["roles"].get(role, {}).get("images", []):
                digest = manifest["images"][name]
                image = f"{name}@{digest}"
                if not self.t.run(target, f"docker pull {image}").ok:
                    return False, f"could not pull {image}"
                pulled = _pulled_digest(self.t, target, image)
                try:
                    mf.verify_pulled(manifest, {name: pulled or ""})
                except mf.ManifestError as exc:
                    return False, str(exc)
            return True, ""
        if step.kind == "upload":
            self.t.upload_tree(target, self.bundles / step.node, remote)
            return True, ""
        if step.kind == "switchover":
            if self.switchover:
                self.switchover(step.node)
            return True, ""
        if step.kind == "activate":
            r = self.t.run(target, f"cd {remote} && docker compose config -q && docker compose up -d --remove-orphans")
            return r.ok, "" if r.ok else (r.stderr or r.stdout).strip()[:300]
        if step.kind == "gate":
            return self.gate(step.node)
        raise ActionError(f"Unknown step kind: {step.kind}")

    def _rollback(self, failed: str, also: list[str], log: Callable[[str], None]) -> list[str]:
        rolled: list[str] = []
        for node in [failed, *reversed(also)]:
            target = self.targets[node]
            remote = f"{REMOTE_ROOT}/{node}"
            r = self.t.run(
                target,
                f"set -e; test -d {remote}.previous; rm -rf {remote}.failed; mv {remote} {remote}.failed; "
                f"mv {remote}.previous {remote}; cd {remote} && docker compose up -d --remove-orphans",
            )
            log(f"{node}: rollback {'ok' if r.ok else 'FAILED'}")
            if r.ok:
                rolled.append(node)
        return rolled
