"""Plan, then apply, for Fleet infrastructure actions.

An action is a list of steps. `plan` is pure. `apply` runs the steps in order,
records each finished step in a journal, and stops at the first failure. Running
the same action again reads the journal and continues after the last finished
step, so a dropped SSH connection never needs manual cleanup. Every step command
is idempotent on the node.
"""
from __future__ import annotations

import json
import threading
import time
from concurrent.futures import FIRST_COMPLETED, ThreadPoolExecutor, wait
from dataclasses import asdict, dataclass, field
from pathlib import Path
from typing import Any, Callable, Protocol

from . import manifest as mf
from .common import FleetError, atomic_json, load_json, utc_now
from .progress import Tracker, plan_steps
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
    "DEBIAN_FRONTEND=noninteractive apt-get update -qq && DEBIAN_FRONTEND=noninteractive apt-get install -y -qq docker.io docker-compose-v2 python3 openssl ca-certificates tar; fi"
)


PARALLEL_KINDS = ("pin", "prereq", "prepull")  # per-host work that touches nothing a host serves, so all hosts do it at once
HOST_WORKERS = 6
IMAGE_WORKERS = 4
PULL_HEARTBEAT_SECONDS = 20


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
        progress: Tracker | None = None,
    ) -> None:
        self.progress = progress
        self.services = services or {}
        self.enroll = enroll
        self.provision = provision
        self.roots = roots or {}
        self.t, self.targets, self.nodes, self.bundles = transport, targets, nodes, bundles
        self.journal, self.gate, self.switchover = journal, gate, switchover
        self.mixed_versions_ok = mixed_versions_ok
        self._log: Callable[[str], None] = lambda _m: None
        self.prepared: set[str] = set()

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
        track = self.progress
        self._log = log
        if track:
            track.plan(plan_steps(plan))
        prepared, failure = self._prepare_hosts(plan, manifest, state, done)
        if failure is not None:
            return failure
        for step in plan.steps:
            if step.id in prepared or step.id in self.prepared:
                continue
            if step.id in done:
                if step.kind == "gate":
                    upgraded.append(step.node)
                if track:
                    track.step(step.id, "skipped", "already done in an earlier run")
                continue
            if track and track.cancel_requested:
                self.journal.save(state)
                return Outcome(False, done, step.id, "Stopped by the operator before this step (run the action again to resume)")
            if track:
                track.step(step.id, "running")
            try:
                ok, detail = self._run(step, manifest)
            except SshError as exc:  # transport fault: stop, keep the journal, the next run resumes here
                state["log"].append({"at": utc_now(), "step": step.id, "ok": False, "detail": str(exc)})
                self.journal.save(state)
                if track:
                    track.step(step.id, "failed", str(exc))
                return Outcome(False, done, step.id, f"{exc} (run the action again to resume)")
            if track:
                track.step(step.id, "ok" if ok else "failed", detail)
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

    def prepare(self, plan: Plan, manifest: dict[str, Any], *, log: Callable[[str], None] = lambda _m: None) -> Outcome | None:
        """Run only the side-by-side preparation of a plan (connect, prerequisites, image pulls) for several hosts. None means every host is ready.

        The steps it finishes are remembered in `prepared`, so the per-host runs that follow do not repeat them. It keeps its own journal.
        """
        self._log = log
        if self.progress:
            self.progress.plan(plan_steps(plan))
        saved, self.journal = self.journal, Journal(self.journal.path.with_name("prepare.json"))
        try:
            state = self.journal.load(self.journal_key(plan))
            done = state["done"]
            _ran, failure = self._prepare_hosts(plan, manifest, state, done)
        finally:
            self.journal = saved
        self.prepared |= {sid for sid in done if sid.rsplit(":", 1)[-1] in PARALLEL_KINDS}
        return failure

    def _prepare_hosts(self, plan: Plan, manifest: dict[str, Any], state: dict[str, Any], done: list[str]) -> tuple[set[str], Outcome | None]:
        """Connect, install prerequisites, and pull images on every host at the same time, before any host is changed.

        Nothing here changes what a host serves, so a failure leaves every host on its old release and needs no rollback.
        Hosts run side by side; each host keeps its own steps in order. One failure stops new steps, lets the running ones finish,
        and keeps the journal so the same action resumes.
        """
        chains: dict[str, list[Step]] = {}
        for st in plan.steps:
            if st.kind in PARALLEL_KINDS and st.id not in done and st.id not in self.prepared:
                chains.setdefault(st.node, []).append(st)
        if len(chains) < 2:
            return set(), None
        track, lock = self.progress, threading.Lock()
        failures: list[tuple[Step, str]] = []
        ran: set[str] = set()
        self._log(f"Preparing {len(chains)} hosts at the same time: {', '.join(sorted(chains))}")

        def chain(steps: list[Step]) -> None:
            for st in steps:
                if failures or (track and track.cancel_requested):
                    return
                if track:
                    track.step(st.id, "running")
                try:
                    ok, detail = self._run(st, manifest)
                except SshError as exc:
                    ok, detail = False, f"{exc} (run the action again to resume)"
                with lock:
                    state["log"].append({"at": utc_now(), "step": st.id, "ok": ok, "detail": detail})
                    if ok:
                        done.append(st.id)
                        ran.add(st.id)
                        state["done"] = done
                    else:
                        failures.append((st, detail))
                    self.journal.save(state)
                if track:
                    track.step(st.id, "ok" if ok else "failed", detail)
                self._log(f"{st.label()}: {'ok' if ok else 'FAILED'} {detail}".strip())
                if not ok:
                    return

        with ThreadPoolExecutor(max_workers=min(HOST_WORKERS, len(chains))) as pool:
            for future in [pool.submit(chain, steps) for steps in chains.values()]:
                future.result()
        if failures:
            step, detail = failures[0]
            return ran, Outcome(False, list(done), step.id, detail, [])
        remaining = next((st for steps in chains.values() for st in steps if st.id not in done), None)
        if remaining is not None:
            return ran, Outcome(False, list(done), remaining.id, "Stopped by the operator before this step (run the action again to resume)")
        return ran, None

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

    def _remote(self, node: str, target: Target, command: str) -> Any:
        """Run a long command on a host. When the transport can stream, every output line reaches the log as it appears,
        and a quiet period is reported, so a slow start never looks like a hang."""
        stream = getattr(self.t, "run_stream", None)
        if stream is None:
            return self.t.run(target, command, timeout=LONG_STEP_SECONDS)
        return stream(target, command, lambda line: self._log(f"{node}: {line}") if line.strip() else None, timeout=LONG_STEP_SECONDS,
                      on_quiet=lambda seconds: self._log(f"{node}: still working, no output for {seconds}s in this step"))

    FAILING_CONTAINER_LOGS = (
        "cd {remote} 2>/dev/null || exit 0; "
        "for id in $(docker compose --env-file .env.prod --profile '*' ps -a --format '{{{{.Name}}}} {{{{.State}}}} {{{{.Health}}}} {{{{.ExitCode}}}}' 2>/dev/null "
        "| awk '$2==\"restarting\" || $2==\"dead\" || $3==\"unhealthy\" || ($2==\"exited\" && $4!=\"0\") {{print $1}}' | head -4); do "
        "echo \"--- $id ---\"; docker logs --tail 12 \"$id\" 2>&1 | cut -c1-400; done"
    )

    def _with_container_logs(self, node: str, target: Target, remote: str, detail: str) -> str:
        """Append the last log lines of every failing container, so the failure shows its cause and not only its name."""
        try:
            r = self.t.run(target, self.FAILING_CONTAINER_LOGS.format(remote=remote), timeout=45)
        except Exception as exc:  # diagnostics must never hide the original failure
            return f"{detail}\n(could not read container logs: {exc})"
        logs = (r.stdout or "").strip()[-2400:]
        if not logs:
            return detail
        self._log(f"{node}: logs of failing containers:\n{logs}")
        return f"{detail}\n{logs}"

    def _pull_images(self, node: str, target: Target, role: str, manifest: dict[str, Any]) -> tuple[bool, str]:
        """Pull and verify every image of the role, a few at a time, saying how far it is so the log never goes quiet."""
        names = list(manifest["roles"].get(role, {}).get("images", []))
        if not names:
            return True, ""
        say, total, started = self._log, len(names), time.time()

        def pull(name: str) -> tuple[bool, str]:
            digest = manifest["images"][name]
            image = mf.pull_ref(name, digest)
            began = time.time()
            say(f"{node}: pulling {name}")
            pull_result = self.t.run(target, f"docker pull {image}", timeout=LONG_STEP_SECONDS)
            if not pull_result.ok:
                reason = (pull_result.stderr or pull_result.stdout).strip().splitlines()
                return False, f"could not pull {image}" + (f": {reason[-1][-300:]}" if reason else "")
            pulled = _pulled_digest(self.t, target, image)
            try:
                mf.verify_pulled(manifest, {name: pulled or ""})
            except mf.ManifestError as exc:
                return False, str(exc)
            say(f"{node}: {name} ready ({round(time.time() - began)}s)")
            return True, ""

        failed: tuple[bool, str] | None = None
        with ThreadPoolExecutor(max_workers=min(IMAGE_WORKERS, total)) as pool:
            pending = {pool.submit(pull, n) for n in names}
            finished = 0
            while pending:
                ready, pending = wait(pending, timeout=PULL_HEARTBEAT_SECONDS, return_when=FIRST_COMPLETED)
                for future in ready:
                    if future.cancelled():
                        continue
                    finished += 1
                    result = future.result()
                    if not result[0] and failed is None:
                        failed = result
                if failed is not None:
                    for future in pending:
                        future.cancel()
                if pending and not ready:
                    say(f"{node}: still pulling, {finished} of {total} images ready after {round(time.time() - started)}s")
        return failed if failed is not None else (True, f"{total} images")

    def _run(self, step: Step, manifest: dict[str, Any]) -> tuple[bool, str]:
        target = self.targets[step.node]
        remote = self.root(step.node)
        role = self.nodes[step.node]["role"]
        if step.kind == "prepull":
            return self._pull_images(step.node, target, role, manifest)
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
            # Only a bundle that passed its health gate (it carries .cdnf-good) becomes the rollback target; a broken one is discarded.
            command = (f"set -e; if [ -d {remote}.next ]; then rm -rf {remote}.previous; if [ -d {remote} ]; then if [ -e {remote}/.cdnf-good ]; then mv {remote} {remote}.previous; else rm -rf {remote}; fi; fi; mv {remote}.next {remote}; fi; "
                       f"cd {remote} && ./start.sh")
            r = self._remote(step.node, target, command)
            if r.ok:
                return True, ""
            detail = (r.stderr or r.stdout).strip()[-600:] or f"start.sh exited with {r.code} and printed nothing"
            return False, self._with_container_logs(step.node, target, remote, detail)
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
