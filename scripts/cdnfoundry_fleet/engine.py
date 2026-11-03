"""The Fleet engine: the one place that performs infrastructure actions.

The CLI and the local web both call these methods. They hold the unlocked vault
work directory, the transport, and the rendered bundles, and they never contain
screen or argument-parsing logic.
"""
from __future__ import annotations

import copy
import json
import shlex
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
        self.events: list[dict[str, Any]] = []
        self._geo: tuple[str | None, str | None] = (None, None)

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
    def public_key(self) -> str:
        """The one public key every node must authorise before it is added."""
        return self.transport.ensure_fleet_key() if hasattr(self.transport, "ensure_fleet_key") else ""

    def set_node_ssh(self, name: str, host: str, port: int, user: str) -> str:
        state = self.state()
        if name not in state["nodes"]:
            raise ValidationError(f"Unknown node: {name}")
        target = Target(name, host, port, user)
        self.public_key()
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
    def _needs_enrollment(self, state: dict[str, Any], name: str) -> bool:
        node = state["nodes"][name]
        return node["role"] in {"edge", "dns-edge"} and "EDGE_ID" not in node.get("extra_env", {})

    def bootstrap_plan(self, name: str) -> actions.Plan:
        state = self.state()
        self._missing_access(state, [name])
        return actions.plan_bootstrap(name, state["global"]["release"], enroll=self._needs_enrollment(state, name), prepull=self.release_manifest() is not None)

    def bootstrap(self, name: str, *, country: str | None = None, continent: str | None = None, log: Callable[[str], None] | None = None) -> actions.Outcome:
        plan = self.bootstrap_plan(name)
        state = self.state()
        if any(s.kind == "enroll" for s in plan.steps) and not (country and continent):
            raise ValidationError("A PoP needs its country and continent codes (they place it in Geo-DNS): pass --country and --continent")
        release = self.release_manifest()
        if release is None:
            raise ValidationError("No signed release is set. Run `cdnfoundry-fleet set-release --manifest release.json` first: Fleet installs only signed releases.")
        self._geo = (country, continent)
        self._render(state)
        engine = self._engine(state)
        engine.enroll = self._enroll
        self.events.append({"kind": "bootstrap", "node": name})
        return engine.apply(plan, release, log=log or self.say)

    def _enroll(self, name: str) -> tuple[bool, str]:
        """Register the PoP in the control plane, put its cells in the shared pool, and give the node its identity."""
        state = self.state()
        control = next((n for n, v in sorted(state["nodes"].items()) if v["role"] == "control"), None)
        targets = self.targets(state)
        if control is None or control not in targets:
            return False, "no control node with stored SSH access to enroll against"
        country, continent = self._geo
        r = self.transport.run(
            targets[control],
            f"cd {actions.REMOTE_ROOT} && docker compose --env-file .env.prod exec -T core php artisan cdnf:edges:enroll {shlex.quote(name)} --country={shlex.quote(country or '')} --continent={shlex.quote(continent or '')}",
        )
        if not r.ok:
            return False, (r.stderr or r.stdout).strip()[-300:]
        try:
            data = json.loads(r.stdout.strip().splitlines()[-1])
            edge_id, token = data["edge_id"], data["bootstrap_token"]
        except (ValueError, KeyError, IndexError):
            return False, "the control plane returned no enrollment"
        extra = dict(state["nodes"][name].get("extra_env", {}))
        extra["EDGE_ID"] = edge_id
        state = self.store.update_node(state, name, {"extra_env": extra})
        self.store.write_secret("edge-bootstrap-token", token, node=name)
        self._render(state)
        return True, f"enrolled as {edge_id}"

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

    def set_release(self, document: dict[str, Any], *, key_pem: str | None = None) -> dict[str, Any]:
        """Adopt a signed release for the first install: sets the release and image references, deploys nothing."""
        if self.release_manifest() is not None:
            raise ValidationError("A release is already running; use `upgrade` to move to another")
        manifest = self.verify_release(document, key_pem=key_pem)
        state = self.state()
        candidate = copy.deepcopy(state)
        old = candidate["global"]["release"]
        candidate["global"]["release"] = manifest["release"]
        for node in candidate["nodes"].values():
            if node.get("release") == old:
                node["release"] = manifest["release"]
            node["extra_env"] = {**node.get("extra_env", {}), **manifest["env_images"]}
        self.store.validate(candidate, require_secrets=False)
        self.store._write(candidate)
        releases = self.work / RELEASES
        releases.mkdir(parents=True, exist_ok=True, mode=0o700)
        atomic_json(releases / "current.json", manifest, 0o600)
        self.events.append({"kind": "set-release", "release": manifest["release"]})
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
        extra = [Check(n, "SSH access stored", False, "run node-ssh") for n in missing]
        ingress = self.work / "ha" / "ingress.crt"
        if (state.get("ha") or {}).get("standby") and ingress.exists():
            import subprocess

            ok = subprocess.run(["openssl", "x509", "-noout", "-checkend", str(30 * 86400)], input=ingress.read_bytes(), capture_output=True, check=False).returncode == 0
            extra.append(Check("fleet", "Standby control certificate valid for 30 days", ok, "" if ok else "run `control refresh-ingress`"))
        return checks + extra

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


class StandbySetup:
    """Everything Fleet already knows about a fleet, used to add the standby control node from a host address alone."""

    def __init__(self, engine: FleetEngine) -> None:
        self.e = engine

    def _run(self, target: Target, command: str) -> str:
        return self.e.transport.run(target, command, check=True).stdout

    def private_address(self, target: Target) -> str:
        """The node's one private (VPN or LAN) address. Replication must not cross the public internet, so Fleet will not guess."""
        from . import ha

        found: list[str] = []
        for line in self._run(target, "ip -o -4 addr show scope global").splitlines():
            parts = line.split()
            if len(parts) >= 4:
                address = parts[3].split("/")[0]
                try:
                    found.append(ha.validate_replication_address(address))
                except ValidationError:
                    continue
        found = sorted(set(found))
        if len(found) != 1:
            raise ValidationError(
                f"{target.name} has {'no private address' if not found else 'several private addresses: ' + ', '.join(found)}. "
                "Put both control nodes on a private network or VPN, or pass the address explicitly."
            )
        return found[0]

    def ingress_certificate(self, state: dict[str, Any], active: Target) -> tuple[str, str]:
        """The certificate Caddy on the active node already holds for the control hostname, so nobody supplies one."""
        host = f"control.{state['global']['operator_domain']}"
        base = f"cd {actions.REMOTE_ROOT} && docker compose --env-file .env.prod exec -T caddy"
        path = self._run(active, f"{base} find /data/caddy/certificates -type f -name {shlex.quote(host + '.crt')}").strip().splitlines()
        if not path:
            raise ValidationError(f"The active node's Caddy holds no certificate for {host} yet. Wait for it to be issued, then try again.")
        crt = path[0].strip()
        key = crt[:-4] + ".key"
        return self._run(active, f"{base} cat {shlex.quote(crt)}"), self._run(active, f"{base} cat {shlex.quote(key)}")

    def next_name(self, state: dict[str, Any]) -> str:
        n = 2
        while f"control-{n}" in state["nodes"]:
            n += 1
        return f"control-{n}"

    def add(self, host: str, *, name: str | None = None, ssh_port: int = 22, ssh_user: str = "root", replication_address: str | None = None,
            active_replication_address: str | None = None, certificate: Path | None = None, key: Path | None = None, **options: Any) -> dict[str, Any]:
        from .control_cli import Control

        e = self.e
        state = e.state()
        controls = [n for n, v in sorted(state["nodes"].items()) if v["role"] == "control"]
        if len(controls) != 1:
            raise ValidationError("add-standby needs exactly one existing control node")
        active_name = controls[0]
        targets = e.targets(state)
        if active_name not in targets:
            raise ValidationError(f"No SSH access stored for {active_name}; run node-ssh first")
        name = name or self.next_name(state)
        target = Target(name, host, ssh_port, ssh_user)
        e.public_key()
        save_target(e.work, target)
        e.transport.pin_host_key(target)
        e.say(f"{name}: installing prerequisites")
        self._run(target, actions.PREREQUISITES)
        e.say("reading the private replication addresses and the control certificate from the nodes")
        standby_address = replication_address or self.private_address(target)
        active_address = active_replication_address or self.private_address(targets[active_name])
        if certificate and key:
            cert_text, key_text = certificate.read_text(encoding="utf-8"), key.read_text(encoding="utf-8")
        else:
            cert_text, key_text = self.ingress_certificate(state, targets[active_name])
        stage = e.work / "ha-incoming"
        stage.mkdir(mode=0o700, exist_ok=True)
        (stage / "ingress.crt").write_text(cert_text, encoding="utf-8")
        (stage / "ingress.key").write_text(key_text, encoding="utf-8")
        (stage / "ingress.key").chmod(0o600)
        runner = VaultRunner(e.transport, e.targets(e.state()))
        control = Control(e.store, e.repo_root, e.output_dir, runner, say=e.say)
        try:
            result = control.add_standby(host, name=name, replication_address=standby_address, active_replication_address=active_address,
                                         certificate=stage / "ingress.crt", key=stage / "ingress.key", public_ipv4=host, **options)
        finally:
            (stage / "ingress.key").unlink(missing_ok=True)
        e.events.append({"kind": "add-standby", "node": name})
        return result

    def refresh(self) -> dict[str, Any]:
        """Re-read the control certificate from the active node (it renews there) and redeploy it to the standby."""
        e = self.e
        state = e.state()
        section = state.get("ha") or {}
        if not section.get("standby"):
            raise ValidationError("This fleet has no standby control node")
        active = e.targets(state)[section["active"]]
        cert_text, key_text = self.ingress_certificate(state, active)
        from .control_cli import Control

        stage = e.work / "ha-incoming"
        stage.mkdir(mode=0o700, exist_ok=True)
        (stage / "ingress.crt").write_text(cert_text, encoding="utf-8")
        (stage / "ingress.key").write_text(key_text, encoding="utf-8")
        (stage / "ingress.key").chmod(0o600)
        try:
            Control._validate_ingress_certificate(state, stage / "ingress.crt", stage / "ingress.key")
            ha_dir = e.work / "ha"
            (ha_dir / "ingress.crt").write_text(cert_text, encoding="utf-8")
            (ha_dir / "ingress.key").write_text(key_text, encoding="utf-8")
            (ha_dir / "ingress.key").chmod(0o600)
        finally:
            (stage / "ingress.key").unlink(missing_ok=True)
        outcome = e.apply_config()
        return {"ok": outcome.ok, "detail": outcome.detail}


def ha_action(engine: FleetEngine, action: str, **options: Any) -> dict[str, Any]:
    """The Phase 6 control commands, run with each node's stored key and pinned host key."""
    import argparse

    from . import control_cli

    if action == "add-standby":
        keep = {k: options[k] for k in ("name", "ssh_port", "ssh_user", "replication_address", "active_replication_address") if options.get(k) is not None}
        for k in ("certificate", "key"):
            if options.get(f"ingress_{k if k == 'key' else 'certificate'}"):
                keep[k] = Path(options["ingress_certificate" if k == "certificate" else "ingress_key"])
        extra = {k: options[k] for k in ("public_ipv6", "region", "bind_ipv4", "bind_ipv6", "edge_wait") if options.get(k) is not None}
        return StandbySetup(engine).add(options["host"], **keep, **extra)
    if action == "refresh-ingress":
        return StandbySetup(engine).refresh()
    state = engine.state()
    runner = VaultRunner(engine.transport, engine.targets(state))
    defaults = dict(
        control_action=action, repo_root=str(engine.repo_root), ssh_user="root", deploy_dir=actions.REMOTE_ROOT, ssh_command="ssh",
        edge_timeout=300, extra_env=[], control_address_family="ipv4", bind_ipv4="0.0.0.0", bind_ipv6=None, public_ipv6=None, region="global",
        old_active_is_powered_off=False, replication_address=None, active_replication_address=None, name="control-2",
    )
    args = argparse.Namespace(**{**defaults, **options})
    return control_cli.execute(args, engine.store, engine.output_dir, runner=runner, say=engine.say)
