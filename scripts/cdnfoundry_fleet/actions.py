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
from .checks import dns_port_conflicts
from .validators import validate_node_config

# Telemetry and monitoring go last: serving never depends on them.
ROLE_ORDER = ["dns", "dns-edge", "edge", "control", "monitoring"]
REMOTE_ROOT = "/opt/cdnfoundry"
# First starts pull images and run migrations, so activation and pre-pull get long, explicit bounds.
LONG_STEP_SECONDS = 1800
PREREQUISITES = (
    "set -e; need=0; for c in docker tar python3 openssl; do command -v $c >/dev/null || need=1; done; "
    "docker compose version >/dev/null 2>&1 || need=1; "
    "if [ $need = 1 ]; then command -v apt-get >/dev/null || { echo 'install Docker with Compose v2, tar, python3 and openssl' >&2; exit 1; }; "
    "DEBIAN_FRONTEND=noninteractive apt-get update -qq && DEBIAN_FRONTEND=noninteractive apt-get install -y -qq docker.io docker-compose-v2 python3 openssl ca-certificates tar; fi; "
    "install -d -m 0700 /opt/cdnfoundry.parent 2>/dev/null; true"
)


class ActionError(FleetError):
    pass


class Transport(Protocol):
    def run(self, target: Target, command: str, *, stdin: bytes | None = None, check: bool = False, timeout: int | None = None) -> Any: ...
    def upload_tree(self, target: Target, local: Path, remote: str) -> None: ...


@dataclass
class Step:
    id: str
    node: str
    kind: str  # pin | prereq | enroll | provision | prepull | upload | validate | activate | gate | rollback | switchover
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
        if role in {"edge", "dns-edge"}:
            plan.steps.append(Step(f"{name}:provision", name, "provision"))
        plan.steps.append(Step(f"{name}:upload", name, "upload"))
        plan.steps.append(Step(f"{name}:validate", name, "validate"))
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
        mixed_versions_ok: bool = True, services: dict[str, set[str]] | None = None,
        enroll: Callable[[str], tuple[bool, str]] | None = None,
        provision: Callable[[str], tuple[bool, str]] | None = None,
        roots: dict[str, str] | None = None,
    ) -> None:
        self.services = services or {}
        self.enroll = enroll
        self.provision = provision
        self.roots = roots or {}
        self.t, self.targets, self.nodes, self.bundles = transport, targets, nodes, bundles
        self.journal, self.gate, self.switchover = journal, gate, switchover
        self.mixed_versions_ok = mixed_versions_ok

    def journal_key(self, plan: Plan) -> str:
        """A resumed run may skip a step only for the same plan over the same bundle bytes: a re-rendered bundle must be uploaded again."""
        import hashlib

        digest = hashlib.sha256(plan.id.encode())
        for node in sorted({s.node for s in plan.steps}):
            root = self.bundles / node
            if root.is_dir():
                for item in sorted(p for p in root.rglob("*") if p.is_file()):
                    digest.update(str(item.relative_to(root)).encode())
                    digest.update(item.read_bytes())
        return digest.hexdigest()[:24]

    def apply(self, plan: Plan, manifest: dict[str, Any], *, log: Callable[[str], None] = lambda _m: None) -> Outcome:
        state = self.journal.load(self.journal_key(plan))
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
                # Before the swap the node still runs its old release; there is nothing to undo on it.
                swapped = step.kind in {"activate", "gate"}
                rolled = self._rollback([step.node] if swapped else [], upgraded if not self.mixed_versions_ok else [], log)
                state["done"] = []
                self.journal.save(state)
                return Outcome(False, done, step.id, detail, rolled)
            done.append(step.id)
            state["done"] = done
            self.journal.save(state)
            if step.kind == "gate":
                upgraded.append(step.node)
        return Outcome(True, done)

    def root(self, node: str) -> str:
        return self.roots.get(node, REMOTE_ROOT)

    def _stage(self, node: str, manifest: dict[str, Any]) -> Path:
        """Copy the rendered bundle and stamp the release the node will run."""
        import shutil
        import tempfile

        staged = Path(tempfile.mkdtemp(prefix="cdnf-stage-"))
        shutil.copytree(self.bundles / node, staged / "bundle", dirs_exist_ok=True)
        (staged / "bundle" / "RELEASE").write_text(str(manifest["release"]) + "\n", encoding="utf-8")
        return staged / "bundle"

    def _run(self, step: Step, manifest: dict[str, Any]) -> tuple[bool, str]:
        target = self.targets[step.node]
        remote = self.root(step.node)
        role = self.nodes[step.node]["role"]
        if step.kind == "prepull":
            for name in manifest["roles"].get(role, {}).get("images", []):
                digest = manifest["images"][name]
                image = mf.pull_ref(name, digest)
                if not self.t.run(target, f"docker pull {image}", timeout=LONG_STEP_SECONDS).ok:
                    return False, f"could not pull {image}"
                pulled = _pulled_digest(self.t, target, image)
                try:
                    mf.verify_pulled(manifest, {name: pulled or ""})
                except mf.ManifestError as exc:
                    return False, str(exc)
            return True, ""
        if step.kind == "upload":
            self.t.upload_tree(target, self._stage(step.node, manifest), remote + ".next", keep_previous=False)
            return True, ""
        if step.kind == "pin":
            pin = getattr(self.t, "pin_host_key", None)
            if pin:
                pin(target)
            return True, ""
        if step.kind == "enroll":
            return self.enroll(step.node) if self.enroll else (False, "no enrollment available")
        if step.kind == "provision":
            return self.provision(step.node) if self.provision else (False, "no provisioning available")
        if step.kind == "prereq":
            r = self.t.run(target, PREREQUISITES)
            if not r.ok:
                return False, (r.stderr or r.stdout).strip()[-300:]
            return dns_port_conflicts(self.t, target, {**self.nodes[step.node], "name": step.node})
        if step.kind == "validate":
            return validate_node_config(self.t, target, role, remote + ".next", self.services.get(step.node, set()))
        if step.kind == "switchover":
            if self.switchover:
                self.switchover(step.node)
            return True, ""
        if step.kind == "activate":
            # Safe to run twice: a retry after a dropped connection finds the swap already done and only starts the services.
            r = self.t.run(
                target,
                # Only a bundle that passed its health gate (it carries .cdnf-good) becomes the rollback target; a broken one is discarded.
                f"set -e; if [ -d {remote}.next ]; then rm -rf {remote}.previous; if [ -d {remote} ]; then if [ -e {remote}/.cdnf-good ]; then mv {remote} {remote}.previous; else rm -rf {remote}; fi; fi; mv {remote}.next {remote}; fi; "
                f"cd {remote} && ./start.sh",
                timeout=LONG_STEP_SECONDS,
            )
            return r.ok, "" if r.ok else ((r.stderr or r.stdout).strip()[-600:] or f"start.sh exited with {r.code} and printed nothing")
        if step.kind == "gate":
            ok, detail = self.gate(step.node)
            if ok:
                self.t.run(target, f"touch {remote}/.cdnf-good")
            return ok, detail
        raise ActionError(f"Unknown step kind: {step.kind}")

    def _rollback(self, failed: list[str], also: list[str], log: Callable[[str], None]) -> list[str]:
        rolled: list[str] = []
        for node in [*failed, *reversed(also)]:
            target = self.targets[node]
            remote = self.root(node)
            try:
                r = self.t.run(
                    target,
                    f"set -e; test -d {remote}.previous; rm -rf {remote}.failed; mv {remote} {remote}.failed; "
                    f"mv {remote}.previous {remote}; cd {remote} && ./start.sh",
                    timeout=LONG_STEP_SECONDS,
                )
            except SshError as exc:
                log(f"{node}: rollback interrupted: {exc}")
                continue
            detail = "" if r.ok else " " + ((r.stderr or r.stdout).strip()[-300:] or f"exit {r.code}")
            log(f"{node}: rollback {'ok' if r.ok else 'FAILED'}{detail}")
            if r.ok:
                rolled.append(node)
        return rolled


def plan_bootstrap(name: str, release: str, *, enroll: bool = False, prepull: bool = False, provision: bool = False) -> Plan:
    plan = Plan("bootstrap", release)
    kinds = (("pin", "prereq") + (("prepull",) if prepull else ()) + (("enroll",) if enroll else ()) + (("provision",) if provision else ())
             + ("upload", "validate", "activate", "gate"))
    for kind in kinds:
        plan.steps.append(Step(f"{name}:{kind}", name, kind))
    return plan


def plan_config_change(nodes: dict[str, dict[str, Any]], changed: set[str], release: str, *, ha_standby: str | None = None) -> Plan:
    """Deploy re-rendered bundles to the nodes whose bundle changed; others are not touched."""
    plan = Plan("config", release)
    for name in order_nodes(nodes, ha_standby=ha_standby):
        if name not in changed:
            plan.skipped.append(name)
            continue
        kinds = (("provision",) if nodes[name]["role"] in {"edge", "dns-edge"} else ()) + ("upload", "validate", "activate", "gate")
        for kind in kinds:
            plan.steps.append(Step(f"{name}:{kind}", name, kind))
    return plan
