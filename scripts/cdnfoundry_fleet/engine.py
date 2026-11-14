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

from . import actions, manifest as mf, provision as tmpfs_material
from .checks import Check, gate_with_retry, node_gate, run_checks
from .common import FleetError, ValidationError, atomic_json, load_json, utc_now
from .control_cli import Runner, bundle_digest
from .inventory import collect as collect_inventory, redact
from .progress import Tracker, plan_steps
from .render import Renderer
from . import management as mg
from .ssh import SshError, SshTransport, Target, load_targets, save_target
from .state import FleetState, validate_management_networks

RELEASES = "releases"
INVENTORY = "inventory"


class VaultRunner(Runner):
    """The HA commands' Runner, reaching each node with its stored key, port, and pinned host key."""

    def __init__(self, transport: SshTransport, targets: dict[str, Target], deploy_dir: str = "/opt/cdnfoundry") -> None:
        super().__init__(("ssh",), user="root", deploy_dir=deploy_dir)
        self.transport, self.targets = transport, targets

    def _ssh(self, node: dict[str, Any], remote: str, *, input_bytes: bytes | None = None, timeout: int = 1800):  # type: ignore[override]
        return self.transport.run_raw(self.targets[node["name"]], remote, stdin=input_bytes, timeout=timeout)


class FleetEngine:
    def __init__(self, work: Path, repo_root: Path, output_dir: Path, *, transport: Any = None, say: Callable[[str], None] = print, progress: Tracker | None = None) -> None:
        self.work, self.repo_root, self.output_dir, self.say = work, repo_root, output_dir, say
        self.progress = progress
        self.store = FleetState(work)
        self.transport = transport or SshTransport(work)
        self.events: list[dict[str, Any]] = []
        self._prepared: set[str] = set()
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
        self._release_file("signing.key").unlink(missing_ok=True)
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

    def ensure_targets(self, *, user: str = "root", port: int = 22) -> list[str]:
        """Give every host without SSH details the default login (root on port 22) and return the hosts changed."""
        state = self.state()
        have = self.targets(state)
        added = [n for n in sorted(state["nodes"]) if n not in have]
        for name in added:
            save_target(self.work, Target(name, state["nodes"][name]["public_ipv4"], port, user))
        if added:
            self.public_key()
        return added

    def test_connection(self, name: str) -> None:
        """Pin the host key on first use, then run a no-op as the stored SSH user. Raises SshError with ssh's own reason."""
        state = self.state()
        self._missing_access(state, [name])
        target = self.targets(state)[name]
        self.public_key()
        self.transport.pin_host_key(target)
        result = self.transport.run(target, "true")
        if not result.ok:
            raise SshError(f"{name}: the login worked but the test command failed: {(result.stderr or result.stdout).strip()[:200]}")

    # -- install tracking, DNS, certificates, and the guided deploy ------------------------------------------------
    def installed(self) -> set[str]:
        path = self.work / "deploy" / "installed.json"
        return set(json.loads(path.read_text(encoding="utf-8"))) if path.exists() else set()

    def mark_installed(self, name: str) -> None:
        atomic_json(self.work / "deploy" / "installed.json", sorted(self.installed() | {name}))

    def deploy_session(self) -> dict[str, Any] | None:
        """What the last install was asked to do, kept until it succeeds so an interrupted install can continue."""
        path = self.work / "deploy" / "session.json"
        return json.loads(path.read_text(encoding="utf-8")) if path.exists() else None

    def remember_deploy(self, locations: dict[str, tuple[str | None, str | None]], release: str) -> None:
        atomic_json(self.work / "deploy" / "session.json", {"geo": {n: list(v) for n, v in locations.items()}, "release": release, "started": utc_now()}, 0o600)

    def forget_deploy(self) -> None:
        (self.work / "deploy" / "session.json").unlink(missing_ok=True)

    def steps_done_in_journal(self) -> int:
        path = self.work / "journal" / "current.json"
        return len(json.loads(path.read_text(encoding="utf-8")).get("done", [])) if path.exists() else 0

    def pending_nodes(self, state: dict[str, Any] | None = None) -> list[str]:
        """Hosts not installed yet, in the order they must start."""
        state = state or self.state()
        order: list[str] = []
        for group in Renderer.start_order(state):
            order += [n for n in group["nodes"] if n not in order]
        done = self.installed()
        return [n for n in order if n not in done]

    def dns_names(self) -> tuple[list[dict[str, Any]], list[dict[str, Any]]]:
        return mg.dns_records(self.state())

    def check_dns(self) -> dict[str, tuple[bool, str]]:
        """Asks the public resolver 1.1.1.1, the way the certificate authority will see the names."""
        records, glue = mg.dns_records(self.state())
        return {r["name"]: mg.check_name(r["name"], r["ips"]) for r in records + glue}

    def management_certificates(self) -> dict[str, dict[str, Any]]:
        return mg.stored(self.work, self.state())

    def set_management_certificate(self, names: list[str], certificate_pem: str, key_pem: str) -> dict[str, Any]:
        state = self.state()
        hosts = [mg.hostname(state, n) for n in names]
        info = mg.validate_certificate(certificate_pem, key_pem, hosts)
        for name in names:
            mg.save_certificate(self.work, name, certificate_pem, key_pem)
        self.events.append({"kind": "management-certificate", "names": names})
        return info

    def remove_management_certificate(self, name: str) -> bool:
        mg.hostname(self.state(), name)
        return mg.remove_certificate(self.work, name)

    def generate_local_certificates(self, names: list[str]) -> None:
        """Local-CA certificates for names that have no operator certificate and cannot get an automatic one."""
        state = self.state()
        for name in names:
            mg.hostname(state, name)
        mg.generate_local(self.work, state, names)
        self.events.append({"kind": "local-certificate", "names": names})

    def local_ca(self) -> str | None:
        return mg.local_ca_certificate(self.work)

    def management_networks(self) -> list[str]:
        return list(self.state()["global"].get("management_networks", []))

    def set_management_networks(self, networks: str) -> None:
        """Replace the whole list. Empty removes the restriction. The next config run carries a change to the hosts."""
        state = self.state()
        state["global"]["management_networks"] = validate_management_networks(networks)
        self.store._write(state)

    def change_management_networks(self, add: str = "", remove: str = "") -> list[str]:
        """Add and remove networks at any time without retyping the rest. Each argument holds one or more CIDRs or addresses.

        A bare address means one host (/32 or /128). Removing a network that is not listed is an error, so a typo is
        never silently accepted. Removing the last network lifts the restriction, which is reported to the caller.
        """
        state = self.state()
        current = list(state["global"].get("management_networks", []))
        wanted_remove = validate_management_networks(remove)
        missing = [item for item in wanted_remove if item not in current]
        if missing:
            raise ValidationError(f"Not in the management networks: {', '.join(missing)}")
        updated = validate_management_networks([*[item for item in current if item not in wanted_remove], *validate_management_networks(add)])
        state["global"]["management_networks"] = updated
        self.store._write(state)
        return updated

    def features(self) -> dict[str, dict[str, Any]]:
        """The monitoring, central log, and backup settings of the plan."""
        return copy.deepcopy(self.state()["features"])

    def configure_feature(self, feature: str, config: dict[str, Any]) -> None:
        """Set monitoring, central logs, or backups. The next config run carries the change to the hosts."""
        self.store.configure_feature(self.state(), feature, config)
        self.events.append({"kind": f"configure-{feature}", "mode": config.get("mode")})

    def deploy_blockers(self, pending: list[str]) -> list[str]:
        problems = []
        if self.release_manifest() is None:
            problems.append("No signed release is set")
        if not pending:
            problems.append("Every host is already installed")
        return problems

    def deploy(self, locations: dict[str, tuple[str | None, str | None]] | None = None, *, log: Callable[[str], None] | None = None) -> actions.Outcome | None:
        """Install every pending host in start order. Returns the failed outcome, or None when all succeeded.

        A management name that has no operator certificate and does not resolve to its host in 1.1.1.1 cannot get a Let's Encrypt
        certificate. It gets a local one instead, so the control plane still opens over HTTPS.
        """
        say = log or self.say
        locations = locations or {}
        state = self.state()
        pending = self.pending_nodes(state)
        blockers = self.deploy_blockers(pending)
        if blockers:
            raise ValidationError("Cannot deploy yet: " + "; ".join(blockers))
        waiting = mg.unready(state, self.work, self.check_dns())
        if waiting:
            for _key, reason in waiting:
                say(f"DNS not ready, {reason}")
            say("Let's Encrypt cannot validate these names yet, so Fleet generates local certificates for them. Browsers warn until you trust the local CA or replace them.")
            self.generate_local_certificates([key for key, _ in waiting])
        if self.progress:
            self.progress.plan([{"id": f"{n}:connect", "node": n, "kind": "connect", "label": f"{n}: connect"} for n in pending]
                               + [step for n in pending for step in plan_steps(self.bootstrap_plan(n))])
        for name in pending:
            say(f"{name}: testing the SSH connection")
            if self.progress:
                self.progress.step(f"{name}:connect", "running")
            try:
                self.test_connection(name)
            except FleetError as exc:
                if self.progress:
                    self.progress.step(f"{name}:connect", "failed", str(exc))
                raise
            if self.progress:
                self.progress.step(f"{name}:connect", "ok")
        release = self.release_manifest()
        if len(pending) > 1 and release is not None:
            # Connect, install prerequisites, and pull images on every host at once; the host-by-host installs below skip what is done.
            combined = actions.Plan("deploy", state["global"]["release"], steps=[step for n in pending for step in self.bootstrap_plan(n).steps])
            failed = self._engine(state).prepare(combined, release, log=say)
            if failed is not None:
                return failed
        for name in pending:
            country, continent = locations.get(name, (None, None))
            outcome = self.bootstrap(name, country=country, continent=continent, log=say)
            if not outcome.ok:
                return outcome
        if any(state["nodes"][n]["role"] == "control" for n in pending):
            if not ControlActions(self).ensure_recovery_point(say):
                say("Backups are disabled: this install has no recovery point. Run configure-backups, then backup-now.")
        return None

    def _render(self, state: dict[str, Any]) -> dict[str, tuple[str, str]]:
        for node in state["nodes"].values():
            self.store._ensure_node_secrets(node)  # a vault from an older release may lack a secret the compose file needs
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
        built = actions.Engine(
            self.transport, targets, state["nodes"], self.output_dir, journal,
            gate=lambda n: self._gate_and_record(n, lambda: gate_with_retry(lambda: node_gate(self.transport, targets[n], state["nodes"][n], state), report=self._report_gate)),
            switchover=self._switchover,
            mixed_versions_ok=mixed_versions_ok, services=services, provision=self._provision, progress=self.progress,
        )
        built.prepared = self._prepared
        return built

    def _deployed_file(self) -> Path:
        return self.work / "deploy" / "deployed.json"

    def deployed_digests(self) -> dict[str, str]:
        """The digest of the bundle each host last ran successfully. Config changes are measured against it, not against the last render."""
        path = self._deployed_file()
        return {k: str(v) for k, v in load_json(path).items()} if path.exists() else {}

    def _report_gate(self, text: str) -> None:
        if self.progress:
            self.progress.line(text)

    def _gate_and_record(self, name: str, gate: Callable[[], tuple[bool, str]]) -> tuple[bool, str]:
        ok, detail = gate()
        if ok:
            recorded = self.deployed_digests()
            recorded[name] = bundle_digest(self.output_dir / name)
            atomic_json(self._deployed_file(), recorded, 0o600)
        return ok, detail

    def _provision(self, name: str) -> tuple[bool, str]:
        """Issue the node's in-memory key material from the vault's PKI and write it to the host's tmpfs."""
        state = self.state()
        node = state["nodes"][name]
        files = tmpfs_material.issue(self.work, self.repo_root, node)
        return tmpfs_material.install(self.transport, self.targets(state)[name], files)

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
        return actions.plan_bootstrap(name, state["global"]["release"], enroll=self._needs_enrollment(state, name), prepull=self.release_manifest() is not None,
                                      provision=tmpfs_material.needs_material(state["nodes"][name]["role"]))

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
        outcome = engine.apply(plan, release, log=log or self.say)
        if outcome.ok:
            self.mark_installed(name)
            if state["nodes"][name]["role"] == "control":
                ok, detail = self._seed_dns_identity(name)
                (log or self.say)(f"{name}: " + ("platform DNS identity stored in the panel" if ok else f"platform DNS identity not stored ({detail}); enter it under System DNS identity"))
                ok, detail = self._register_dns_primary(name)
                (log or self.say)(f"{name}: " + ("PowerDNS primary registered in the panel" if ok else f"could not register the PowerDNS primary ({detail}); add it under DNS clusters"))
        return outcome

    def _seed_dns_identity(self, name: str) -> tuple[bool, str]:
        """Store the platform DNS identity Fleet already knows: the platform domain, the ns1 and ns2 glue, and standard SOA values.

        Only IPv4 glue is sent. An IPv6 glue address is accepted only after an enrolled edge has seen it answer, which cannot be true yet.
        The control plane keeps an identity that already exists unchanged.
        """
        state = self.state()
        platform = state["global"]["platform_domain"]
        _records, glue = mg.dns_records(state)
        nameservers = [{"hostname": g["name"], "ipv4": next((a for a in g["ips"] if ":" not in a), None)} for g in glue]
        if len(nameservers) < 2 or not all(n["ipv4"] for n in nameservers):
            return False, "needs two DNS nodes with a public IPv4 address"
        identity = {
            "platform_domain": platform, "proxy_hostname": f"proxy.{platform}", "nameservers": nameservers,
            "soa_primary": f"ns1.{platform}", "soa_mailbox": f"hostmaster.{platform}",
            "soa_refresh": 3600, "soa_retry": 600, "soa_expire": 604800, "soa_minimum_ttl": 300, "default_ttl": 300,
        }
        r = self.transport.run(
            self.targets(state)[name],
            f"cd {actions.REMOTE_ROOT} && docker compose --env-file .env.prod exec -T core php artisan cdnf:dns:identity:seed {shlex.quote(json.dumps(identity))}",
        )
        return r.ok, "" if r.ok else (r.stderr or r.stdout).strip()[-200:]

    def _register_dns_primary(self, name: str) -> tuple[bool, str]:
        """Register the private PowerDNS primary as a DNS cluster. The control plane tests it and enables it once healthy."""
        state = self.state()
        node = state["nodes"][name]
        endpoint = node.get("bind_ipv4") if node.get("bind_ipv4") not in (None, "", "0.0.0.0") else node["public_ipv4"]
        platform = state["global"]["platform_domain"]
        names = "".join(f" --nameserver={shlex.quote(f'{label}.{platform}')}" for label in ("ns1", "ns2"))
        key = "$(sed -n 's/^PDNS_API_KEY=//p' .env.prod)"
        r = self.transport.run(
            self.targets(state)[name],
            f"cd {actions.REMOTE_ROOT} && docker compose --env-file .env.prod exec -T -e PDNS_API_KEY=\"{key}\" core php artisan cdnf:dns:register-primary"
            f" --transfer-endpoint={shlex.quote(f'{endpoint}:5300')}{names}",
        )
        return r.ok, "" if r.ok else (r.stderr or r.stdout).strip()[-200:]

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
            f"cd {actions.REMOTE_ROOT} && docker compose --env-file .env.prod exec -T core php artisan cdnf:edges:enroll {shlex.quote(name)} --country={shlex.quote(country or '')} --continent={shlex.quote(continent or '')}"
            f" --ipv4={shlex.quote(state['nodes'][name]['public_ipv4'])}" + (f" --ipv6={shlex.quote(state['nodes'][name]['public_ipv6'])}" if state['nodes'][name].get('public_ipv6') else ""),
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
        deployed = self.deployed_digests()
        changed = {n for n, (old, new) in digests.items() if deployed.get(n, old) != new}
        ha_standby = (state.get("ha") or {}).get("standby")
        return actions.plan_config_change(state["nodes"], changed, state["global"]["release"], ha_standby=ha_standby), digests

    def apply_config(self, *, log: Callable[[str], None] | None = None) -> actions.Outcome:
        plan, _ = self.config_plan()
        state = self.state()
        self._missing_access(state, [s.node for s in plan.steps])
        current = self.release_manifest() or {"release": state["global"]["release"], "roles": {}, "images": {}}
        return self._engine(state).apply(plan, current, log=log or self.say)

    def target_release(self) -> str:
        """The release to install or upgrade to.

        A first install uses the release chosen at setup. An upgrade uses the tag the checkout is on, else the release chosen at setup.
        """
        import subprocess

        if self.release_manifest() is None:
            return self.state()["global"]["release"]

        tag = subprocess.run(["git", "-C", str(self.repo_root), "describe", "--tags", "--exact-match"], capture_output=True, text=True, check=False)
        return tag.stdout.strip() if tag.returncode == 0 and tag.stdout.strip() else self.state()["global"]["release"]

    def automatic_release(self, release: str | None = None, *, digest: Any = None) -> dict[str, Any]:
        """Build and sign the manifest for the target release, creating Fleet's own signing key on first use.

        The key lives only in the encrypted vault. The signature proves the manifest came from this Fleet and this checkout, not from CI.
        """
        from cryptography.hazmat.primitives import serialization
        from cryptography.hazmat.primitives.asymmetric.ed25519 import Ed25519PrivateKey

        from . import release as rel

        private_file, public_file = self._release_file("signing.key"), self._release_file("trusted.pub")
        if not private_file.exists():
            if public_file.exists():
                raise ValidationError("You pinned your own release key, so Fleet cannot sign for you. Give it the signed manifest (--manifest FILE)")
            key = Ed25519PrivateKey.generate()
            self.trust_release_key(key.public_key().public_bytes(serialization.Encoding.PEM, serialization.PublicFormat.SubjectPublicKeyInfo).decode())
            private_file.touch(mode=0o600)
            private_file.write_bytes(key.private_bytes(serialization.Encoding.PEM, serialization.PrivateFormat.PKCS8, serialization.NoEncryption()))
        manifest = rel.automatic_manifest(self.repo_root, release or self.target_release(), **({"digest": digest} if digest else {}))
        return rel.sign_file(manifest, private_file.read_bytes())

    def verify_release(self, document: dict[str, Any], *, key_pem: str | None = None) -> dict[str, Any]:
        manifest = mf.verify(document, mf.public_key_from_pem(key_pem or self.trusted_key()))
        mf.verify_checkout(manifest, self.repo_root)
        return manifest

    def set_release(self, document: dict[str, Any] | None = None, *, key_pem: str | None = None) -> dict[str, Any]:
        """Adopt a signed release for the first install: sets the release and image references, deploys nothing."""
        if self.release_manifest() is not None:
            raise ValidationError("A release is already running; use `upgrade` to move to another")
        manifest = self.verify_release(document or self.automatic_release(), key_pem=key_pem)
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

    def upgrade_plan(self, document: dict[str, Any] | None = None, *, key_pem: str | None = None) -> tuple[actions.Plan, dict[str, Any], mf.Diff]:
        manifest = self.verify_release(document or self.automatic_release(), key_pem=key_pem)
        state = self.state()
        current = self.release_manifest()
        ha_standby = (state.get("ha") or {}).get("standby")
        return actions.plan_upgrade(state["nodes"], current, manifest, ha_standby=ha_standby), manifest, mf.diff(current, manifest)

    def upgrade(self, document: dict[str, Any] | None = None, *, key_pem: str | None = None, log: Callable[[str], None] | None = None) -> actions.Outcome:
        document = document or self.automatic_release()
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
        say = log or self.say
        targets = self.targets(state)
        already = []
        for n in names:
            running = self.transport.run(targets[n], f"cat {actions.REMOTE_ROOT}/RELEASE 2>/dev/null").stdout.strip()
            if running == previous["release"]:
                say(f"{n}: already runs {running}")
                already.append(n)
        rolled = already + engine._rollback([n for n in names if n not in already], [], say)
        if not scope and set(rolled) == set(names):
            candidate = copy.deepcopy(state)
            was = candidate["global"]["release"]
            candidate["global"]["release"] = previous["release"]
            for node in candidate["nodes"].values():
                if node.get("release") == was:
                    node["release"] = previous["release"]
                node["extra_env"] = {**node.get("extra_env", {}), **previous.get("env_images", {})}
            self.store.validate(candidate, require_secrets=False)
            self.store._write(candidate)
            releases = self.work / RELEASES
            atomic_json(releases / "current.json", previous, 0o600)
            (releases / "previous.json").unlink(missing_ok=True)
        return rolled

    # -- inventory --------------------------------------------------------------------------------------
    def inventory(self) -> dict[str, dict[str, Any]]:
        """The last hardware and system inventory of each host, as recorded by `collect_inventory`."""
        out: dict[str, dict[str, Any]] = {}
        for name in self.state()["nodes"]:
            path = self.work / INVENTORY / f"{name}.json"
            if path.exists():
                try:
                    out[name] = json.loads(path.read_text(encoding="utf-8"))
                except ValueError:
                    continue
        return out

    def collect_inventory(self, names: list[str] | None = None, *, workers: int = 6) -> dict[str, dict[str, Any]]:
        """Read the inventory of the given hosts (all when none are named) in parallel and record each one that answered."""
        from concurrent.futures import ThreadPoolExecutor

        state = self.state()
        targets = self.targets(state)
        chosen = [n for n in (names or sorted(state["nodes"])) if n in state["nodes"]]
        if self.progress:
            self.progress.plan([{"id": f"{n}:inventory", "node": n, "kind": "inventory", "label": f"{n}: read hardware"} for n in chosen])

        def one(name: str) -> tuple[str, dict[str, Any] | None, str]:
            if name not in targets:
                return name, None, "no SSH login saved"
            if self.progress:
                self.progress.step(f"{name}:inventory", "running")
            try:
                inv = collect_inventory(self.transport, targets[name])
            except SshError as exc:
                return name, None, str(exc)
            return name, inv or None, "" if inv else "the host answered, but not with an inventory"

        out: dict[str, dict[str, Any]] = {}
        with ThreadPoolExecutor(max_workers=max(1, min(workers, len(chosen) or 1))) as pool:
            for name, inv, problem in pool.map(one, chosen):
                if inv:
                    atomic_json(self.work / INVENTORY / f"{name}.json", inv, 0o600)
                    out[name] = inv
                    self.say(f"{name}: {inv.get('cpu_cores') or '?'} cores, {round((inv.get('mem_total_mb') or 0) / 1024, 1)} GB memory, {inv.get('kernel')}")
                else:
                    self.say(f"{name}: no inventory ({problem})")
                if self.progress:
                    self.progress.step(f"{name}:inventory", "ok" if inv else "failed", problem)
        return out

    def probe_nodes(self, *, workers: int = 6) -> dict[str, dict[str, Any]]:
        """A read-only look at every host right now, for the live view. Nothing is recorded; an unreachable host is a result, not an error."""
        from concurrent.futures import ThreadPoolExecutor

        state = self.state()
        targets = self.targets(state)

        def one(name: str) -> tuple[str, dict[str, Any]]:
            if name not in targets:
                return name, {"ok": False, "error": "No SSH login saved"}
            try:
                inv = collect_inventory(self.transport, targets[name])
            except SshError as exc:
                return name, {"ok": False, "error": str(exc)[:200]}
            return name, ({"ok": True, "inventory": inv} if inv else {"ok": False, "error": "The host answered without an inventory"})

        names = sorted(state["nodes"])
        with ThreadPoolExecutor(max_workers=max(1, min(workers, len(names) or 1))) as pool:
            return dict(pool.map(one, names))

    def container_log(self, name: str, container: str, lines: int = 300) -> str:
        """The tail of one container's log, read-only. The container must be one the host reported, so the name never reaches a shell unchecked."""
        import re as _re

        if not _re.fullmatch(r"[A-Za-z0-9][A-Za-z0-9_.-]{0,63}", container):
            raise ValidationError("That is not a container name")
        known = {c["name"] for c in (self.inventory().get(name) or {}).get("containers", [])}
        if container not in known:
            raise ValidationError("Fleet has not seen that container on this host. Refresh the hardware inventory first.")
        target = self.targets().get(name)
        if target is None:
            raise ValidationError("No SSH login saved for this host")
        result = self.transport.run(target, f"docker logs --tail {int(max(1, min(lines, 1000)))} --timestamps {container} 2>&1", timeout=30)
        return redact((result.stdout or result.stderr)[-200_000:])

    # -- checks and security ----------------------------------------------------------------------------
    def doctor(self, only: list[str] | None = None) -> list[Check]:
        state = self.state()
        targets = {n: t for n, t in self.targets(state).items() if only is None or n in only}
        roles = {n: v["role"] for n, v in state["nodes"].items()}
        mon = state["features"]["monitoring"]
        hosts = {n for n, v in state["nodes"].items() if v["role"] == "monitoring"} | ({n for n, v in state["nodes"].items() if v["role"] == "control"} if mon["mode"] == "colocated" else set())
        checks = run_checks(self.transport, targets, state["global"]["release"], roles, hosts, self.progress)
        missing = [n for n in state["nodes"] if n not in targets and (only is None or n in only)]
        extra = [Check(n, "SSH access stored", False, "run node-ssh") for n in missing]
        ingress = self.work / "ha" / "ingress.crt"
        if (state.get("ha") or {}).get("standby") and ingress.exists():
            import subprocess

            ok = subprocess.run(["openssl", "x509", "-noout", "-checkend", str(30 * 86400)], input=ingress.read_bytes(), capture_output=True, check=False).returncode == 0
            extra.append(Check("fleet", "Standby control certificate valid for 30 days", ok, "" if ok else "run `control refresh-ingress`"))
        if state["features"]["backups"]["mode"] != "disabled" and any(v["role"] == "control" for v in state["nodes"].values()):
            try:
                verified = ControlActions(self).verified_backup_count()
            except FleetError:
                verified = -1
            if verified == 0:
                extra.append(Check("fleet", "A verified backup exists", False, "the recovery point is nothing; run `backup-now`"))
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

    def verified_backup_count(self) -> int:
        """How many backups the control database records as succeeded and verified against the repository."""
        state = self.e.state()
        _, target = self._control(state, None)
        r = self.e.transport.run(target, f"cd {actions.REMOTE_ROOT} && docker compose --env-file .env.prod exec -T core php artisan tinker --execute='echo DB::table(\"backups\")->where(\"status\", \"succeeded\")->whereNotNull(\"verified_at\")->count();'")
        try:
            return int(r.stdout.strip().splitlines()[-1]) if r.ok and r.stdout.strip() else -1
        except ValueError:
            return -1

    def ensure_recovery_point(self, log: Callable[[str], None] | None = None) -> bool:
        """Take the first backup and confirm it is recorded as verified. A fleet whose only recovery point is "nothing" is not ready.

        Returns False when backups are disabled by choice, which the caller reports as a warning rather than success. Raises when
        a backup was expected and did not verify.
        """
        say = log or self.e.say
        if self.e.state()["features"]["backups"]["mode"] == "disabled":
            return False
        if self.verified_backup_count() > 0:
            return True
        say("No verified backup exists yet; taking the first one before reporting the install ready")
        self.backup_now()
        if self.verified_backup_count() < 1:
            raise ValidationError("The first backup finished but is not recorded as verified. The install is not ready: fix the backup repository, then run `backup-now`")
        return True

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
        own = mg.tls_dir(self.e.work)
        if (own / "control.crt").exists() and (own / "control.key").exists():
            # An operator or Fleet-local certificate is what the active node serves, so the standby serves the same one.
            return (own / "control.crt").read_text(encoding="utf-8"), (own / "control.key").read_text(encoding="utf-8")
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
        manifest = e.release_manifest()
        if manifest:  # a node that may be promoted must run the same digest-pinned images as the active node
            options["extra_env"] = {**manifest["env_images"], **(options.get("extra_env") or {})}
        reachable = {**e.targets(e.state()), name: target}  # the standby is not in the plan yet, but Fleet must already reach it
        runner = VaultRunner(e.transport, reachable)
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
