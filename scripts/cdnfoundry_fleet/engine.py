"""The Fleet engine: the one place that performs infrastructure actions.

The CLI and the local web both call these methods. They hold the unlocked vault
work directory, the transport, and the rendered bundles, and they never contain
screen or argument-parsing logic.
"""
from __future__ import annotations

import copy
import json
from pathlib import Path
from typing import Any, Callable

from . import actions, manifest as mf
from .checks import Check, node_gate, run_checks
from .common import ValidationError, atomic_json, utc_now
from .control_cli import Runner, bundle_digest
from .render import Renderer
from .ssh import SshTransport, Target, load_targets, save_target
from .state import FleetState

RELEASES = "releases"


class VaultRunner(Runner):
    """The HA commands' Runner, reaching each node with its stored key, port, and pinned host key."""

    def __init__(self, transport: SshTransport, targets: dict[str, Target], deploy_dir: str = "/opt/cdnfoundry") -> None:
        super().__init__(("ssh",), user="root", deploy_dir=deploy_dir)
        self.transport, self.targets = transport, targets

    def _ssh(self, node: dict[str, Any], remote: str, *, input_bytes: bytes | None = None, timeout: int = 1800):  # type: ignore[override]
        return self.transport.run_raw(self.targets[node["name"]], remote, stdin=input_bytes, timeout=timeout)


class FleetEngine:
    def __init__(self, work: Path, repo_root: Path, output_dir: Path, *, transport: Any = None, say: Callable[[str], None] = print) -> None:
        self.work, self.repo_root, self.output_dir, self.say = work, repo_root, output_dir, say
        self.store = FleetState(work)
        self.transport = transport or SshTransport(work)

    # -- state ------------------------------------------------------------------------------------------
    def state(self) -> dict[str, Any]:
        return self.store.load()

    def targets(self, state: dict[str, Any] | None = None) -> dict[str, Target]:
        state = state or self.state()
        return load_targets(self.work, set(state["nodes"]))

    def _release_file(self, name: str) -> Path:
        return self.work / RELEASES / name

    def release_manifest(self, which: str = "current") -> dict[str, Any] | None:
        path = self._release_file(f"{which}.json")
        return json.loads(path.read_text(encoding="utf-8")) if path.exists() else None

    def trusted_key(self) -> str:
        path = self._release_file("trusted.pub")
        if not path.exists():
            raise ValidationError("No release signing key is trusted yet; run `cdnfoundry-fleet trust-release-key --key-file ...`")
        return path.read_text(encoding="utf-8")

    def trust_release_key(self, pem: str) -> None:
        mf.public_key_from_pem(pem)
        path = self._release_file("trusted.pub")
        path.parent.mkdir(parents=True, exist_ok=True, mode=0o700)
        path.write_text(pem.strip() + "\n", encoding="utf-8")

    # -- nodes ------------------------------------------------------------------------------------------
    def set_node_ssh(self, name: str, host: str, port: int, user: str, private_key: str) -> str:
        state = self.state()
        if name not in state["nodes"]:
            raise ValidationError(f"Unknown node: {name}")
        target = Target(name, host, port, user)
        self.transport.store_key(target, private_key)
        save_target(self.work, target)
        return self.transport.pin_host_key(target)

    def _render(self, state: dict[str, Any]) -> dict[str, tuple[str, str]]:
        before = {n: bundle_digest(self.output_dir / n) for n in state["nodes"]}
        Renderer(self.repo_root, self.store, self.output_dir).render(state)
        return {n: (before[n], bundle_digest(self.output_dir / n)) for n in state["nodes"] if state["nodes"][n].get("enabled", True)}

    def _engine(self, state: dict[str, Any], plan_nodes: dict[str, dict[str, Any]] | None = None, *, mixed_versions_ok: bool = True) -> actions.Engine:
        targets = self.targets(state)
        journal = actions.Journal(self.work / "journal" / "current.json")
        services = {}
        for name in state["nodes"]:
            compose = self.output_dir / name / "compose.yml"
            if compose.exists():
                import yaml

                services[name] = set((yaml.safe_load(compose.read_text(encoding="utf-8")) or {}).get("services", {}))
        return actions.Engine(
            self.transport, targets, state["nodes"], self.output_dir, journal,
            gate=lambda n: node_gate(self.transport, targets[n], state["nodes"][n], state), switchover=self._switchover,
            mixed_versions_ok=mixed_versions_ok, services=services,
        )

    def _switchover(self, node: str) -> None:
        from .control_cli import Control

        runner = VaultRunner(self.transport, self.targets())
        Control(self.store, self.repo_root, self.output_dir, runner, say=self.say).switchover()

    def _missing_access(self, state: dict[str, Any], names: list[str]) -> None:
        missing = [n for n in names if n not in self.targets(state)]
        if missing:
            raise ValidationError(f"No SSH access stored for: {', '.join(missing)}; run `node-ssh` first")

    # -- actions ----------------------------------------------------------------------------------------
    def bootstrap_plan(self, name: str) -> actions.Plan:
        state = self.state()
        self._missing_access(state, [name])
        return actions.plan_bootstrap(name, state["global"]["release"])

    def bootstrap(self, name: str, *, log: Callable[[str], None] | None = None) -> actions.Outcome:
        plan = self.bootstrap_plan(name)
        state = self.state()
        self._render(state)
        release = {"release": state["global"]["release"], "roles": {}, "images": {}}
        return self._engine(state).apply(plan, release, log=log or self.say)

    def config_plan(self) -> tuple[actions.Plan, dict[str, tuple[str, str]]]:
        state = self.state()
        digests = self._render(state)
        changed = {n for n, (old, new) in digests.items() if old != new}
        ha_standby = (state.get("ha") or {}).get("standby")
        return actions.plan_config_change(state["nodes"], changed, state["global"]["release"], ha_standby=ha_standby), digests

    def apply_config(self, *, log: Callable[[str], None] | None = None) -> actions.Outcome:
        plan, _ = self.config_plan()
        state = self.state()
        self._missing_access(state, [s.node for s in plan.steps])
        current = self.release_manifest() or {"release": state["global"]["release"], "roles": {}, "images": {}}
        return self._engine(state).apply(plan, current, log=log or self.say)

    def verify_release(self, document: dict[str, Any], *, key_pem: str | None = None) -> dict[str, Any]:
        manifest = mf.verify(document, mf.public_key_from_pem(key_pem or self.trusted_key()))
        mf.verify_checkout(manifest, self.repo_root)
        return manifest

    def upgrade_plan(self, document: dict[str, Any], *, key_pem: str | None = None) -> tuple[actions.Plan, dict[str, Any], mf.Diff]:
        manifest = self.verify_release(document, key_pem=key_pem)
        state = self.state()
        current = self.release_manifest()
        ha_standby = (state.get("ha") or {}).get("standby")
        return actions.plan_upgrade(state["nodes"], current, manifest, ha_standby=ha_standby), manifest, mf.diff(current, manifest)

    def upgrade(self, document: dict[str, Any], *, key_pem: str | None = None, log: Callable[[str], None] | None = None) -> actions.Outcome:
        plan, manifest, _diff = self.upgrade_plan(document, key_pem=key_pem)
        state = self.state()
        self._missing_access(state, [s.node for s in plan.steps])
        candidate = copy.deepcopy(state)
        old_release = candidate["global"]["release"]
        candidate["global"]["release"] = manifest["release"]
        for node in candidate["nodes"].values():
            if node.get("release") == old_release:
                node["release"] = manifest["release"]
            node["extra_env"] = {**node.get("extra_env", {}), **manifest["env_images"]}
        self.store.validate(candidate, require_secrets=False)
        self.store._write(candidate)
        self._render(candidate)
        outcome = self._engine(candidate, mixed_versions_ok=bool(manifest.get("mixed_versions_ok", True))).apply(plan, manifest, log=log or self.say)
        if outcome.ok:
            current = self.release_manifest()
            releases = self.work / RELEASES
            releases.mkdir(parents=True, exist_ok=True, mode=0o700)
            if current:
                atomic_json(releases / "previous.json", current, 0o600)
            atomic_json(releases / "current.json", manifest, 0o600)
            (self.work / "journal" / "current.json").unlink(missing_ok=True)
        else:
            # The fleet stays on the previous release: restore the recorded release in state.
            reverted = copy.deepcopy(candidate)
            reverted["global"]["release"] = old_release
            for node in reverted["nodes"].values():
                if node.get("release") == manifest["release"]:
                    node["release"] = old_release
            self.store._write(reverted)
        return outcome

    def rollback(self, scope: list[str] | None = None, *, log: Callable[[str], None] | None = None) -> list[str]:
        current, previous = self.release_manifest(), self.release_manifest("previous")
        if not current or not previous:
            raise ValidationError("There is no previous release to return to")
        mf.rollback_allowed(current, previous)
        state = self.state()
        names = scope or actions.order_nodes(state["nodes"])
        self._missing_access(state, names)
        engine = self._engine(state)
        rolled = engine._rollback(names, [], log or self.say)
        if not scope and set(rolled) == set(names):
            releases = self.work / RELEASES
            atomic_json(releases / "current.json", previous, 0o600)
            (releases / "previous.json").unlink(missing_ok=True)
        return rolled

    # -- checks and security ----------------------------------------------------------------------------
    def doctor(self) -> list[Check]:
        state = self.state()
        targets = self.targets(state)
        roles = {n: v["role"] for n, v in state["nodes"].items()}
        mon = state["features"]["monitoring"]
        hosts = {n for n, v in state["nodes"].items() if v["role"] == "monitoring"} | ({n for n, v in state["nodes"].items() if v["role"] == "control"} if mon["mode"] == "colocated" else set())
        checks = run_checks(self.transport, targets, state["global"]["release"], roles, hosts)
        missing = [n for n in state["nodes"] if n not in targets]
        return checks + [Check(n, "SSH access stored", False, "run node-ssh") for n in missing]

    def security_status(self) -> list[dict[str, Any]]:
        from .security import classify

        return classify(self.release_manifest(), self.repo_root)


class ControlActions:
    """Backup and restore of the control plane, run on a control node through its own artisan commands."""

    def __init__(self, engine: FleetEngine) -> None:
        self.e = engine

    def _control(self, state: dict[str, Any], name: str | None) -> tuple[str, Target]:
        if name is None:
            section = state.get("ha") or {}
            name = section.get("active") or next((n for n, v in sorted(state["nodes"].items()) if v["role"] == "control"), None)
        if name is None or name not in state["nodes"] or state["nodes"][name]["role"] != "control":
            raise ValidationError("A control node is required")
        targets = self.e.targets(state)
        if name not in targets:
            raise ValidationError(f"No SSH access stored for {name}; run node-ssh first")
        return name, targets[name]

    def backup_now(self) -> str:
        state = self.e.state()
        if state["features"]["backups"]["mode"] == "disabled":
            raise ValidationError("Backups are disabled; run configure-backups first")
        name, target = self._control(state, None)
        r = self.e.transport.run(target, f"cd {actions.REMOTE_ROOT} && docker compose --env-file .env.prod exec -T core php artisan cdnf:backups:create --wait", check=True)
        return r.stdout.strip()[-300:]

    def restore_plan(self, node: str, snapshot: str, overwrite_live: bool) -> dict[str, Any]:
        state = self.e.state()
        name, target = self._control(state, node)
        r = self.e.transport.run(target, f"cd {actions.REMOTE_ROOT} && docker compose --env-file .env.prod exec -T core php artisan tinker --execute='echo Schema::hasTable(\"users\") ? DB::table(\"users\")->count() : 0;'")
        count = int(r.stdout.strip().splitlines()[-1]) if r.ok and r.stdout.strip() else -1
        if count < 0:
            raise ValidationError(f"Could not read the state of {name}; is the control node running?")
        if count > 0 and not overwrite_live:
            raise ValidationError(f"{name} holds {count} user(s). Restore into a fresh control node, or pass --overwrite-live with the preflight --operation.")
        return {"node": name, "snapshot": snapshot, "replaces": f"{count} user(s) and all control data on {name}" if count else f"nothing: {name} is empty", "confirm": f"RESTORE {snapshot}"}

    def restore(self, node: str, snapshot: str, confirm: str | None, *, overwrite_live: bool = False, operation: str | None = None) -> str:
        plan = self.restore_plan(node, snapshot, overwrite_live)
        if confirm != plan["confirm"]:
            raise ValidationError(f"Type exactly: {plan['confirm']}")
        if overwrite_live and not operation:
            raise ValidationError("Overwriting a live control node needs the preflight operation UUID (--operation)")
        state = self.e.state()
        _, target = self._control(state, node)
        arg = operation if (overwrite_live and operation) else f"--snapshot={snapshot}"
        r = self.e.transport.run(
            target, f"cd {actions.REMOTE_ROOT} && docker compose --env-file .env.prod run --rm --no-deps -e BACKUP_RESTORE_ALLOWED=true core php artisan cdnf:backups:restore {arg}", check=True,
        )
        return r.stdout.strip()[-300:]
