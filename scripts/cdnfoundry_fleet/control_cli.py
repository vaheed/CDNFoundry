"""`cdnfoundry-fleet control ...`: add, switch, rejoin, and remove the optional standby control node.

Every action that changes a host runs one of the node's own `ha/*.sh` scripts over SSH, so what ran is the
file in that node's bundle. This module orders the steps, stops at the first failure, and decides what
may run: it never promotes while the old active node could still be serving.
"""
from __future__ import annotations

import hashlib
import io
import json
import shlex
import socket
import ssl
import subprocess
import tarfile
import time
from pathlib import Path
from typing import Any, Callable

from . import ha
from .common import FleetError, ValidationError, atomic_write, utc_now, validate_ip, validate_node_name
from .render import Renderer
from .state import FleetState

DEFAULT_SSH = ("ssh", "-o", "BatchMode=yes", "-o", "ConnectTimeout=10")
COMPOSE = "docker compose --env-file .env.prod"
EDGE_WAIT_SECONDS = 900
PROMOTE_EDGE_WAIT_SECONDS = 300
READY_WAIT_SECONDS = 600


class Runner:
    """Runs commands on a node and replaces its deployed bundle. `ssh_command` is replaceable for tests."""

    def __init__(self, ssh_command: tuple[str, ...] = DEFAULT_SSH, *, user: str = "root", deploy_dir: str = "/opt/cdnfoundry") -> None:
        self.ssh_command = tuple(ssh_command)
        self.user = user
        self.deploy_dir = deploy_dir

    def target(self, node: dict[str, Any]) -> str:
        return f"{self.user}@{node['public_ipv4']}"

    def _ssh(self, node: dict[str, Any], remote: str, *, input_bytes: bytes | None = None, timeout: int = 1800) -> subprocess.CompletedProcess[bytes]:
        return subprocess.run([*self.ssh_command, self.target(node), remote], input=input_bytes, capture_output=True, timeout=timeout, check=False)

    def run(self, node: dict[str, Any], command: str, *, timeout: int = 1800) -> str:
        result = self._ssh(node, f"cd {shlex.quote(self.deploy_dir)} && {command}", timeout=timeout)
        if result.returncode != 0:
            tail = result.stderr.decode("utf-8", "replace").strip().splitlines()[-5:]
            raise FleetError(f"{node['name']}: `{command}` failed ({result.returncode}): {' | '.join(tail)}")
        return result.stdout.decode("utf-8", "replace")

    def push(self, node: dict[str, Any], bundle: Path) -> None:
        """Extract beside the deployed directory and swap, keeping the previous directory for rollback."""
        buffer = io.BytesIO()
        with tarfile.open(fileobj=buffer, mode="w:gz") as archive:
            archive.add(bundle, arcname=".")
        directory = shlex.quote(self.deploy_dir)
        script = (
            f"set -e; rm -rf {directory}.new; install -d -m 0700 {directory}.new; tar -xzf - -C {directory}.new; "
            f"if [ -d {directory} ]; then rm -rf {directory}.previous; mv {directory} {directory}.previous; fi; mv {directory}.new {directory}"
        )
        result = self._ssh(node, script, input_bytes=buffer.getvalue())
        if result.returncode != 0:
            raise FleetError(f"{node['name']}: pushing the bundle failed: {result.stderr.decode('utf-8', 'replace').strip()[-300:]}")


def bundle_digest(path: Path) -> str:
    """Content digest of one rendered node bundle; equal digests mean the host needs no change."""
    digest = hashlib.sha256()
    if not path.exists():
        return ""
    for item in sorted(p for p in path.rglob("*") if p.is_file()):
        digest.update(str(item.relative_to(path)).encode())
        digest.update(item.read_bytes())
    return digest.hexdigest()


def probe_control_state(address: str, hostname: str, *, timeout: float = 5.0) -> dict[str, Any] | None:
    """Ask a control node, by address and under the shared hostname, what it is.

    None means nothing answered. A node whose certificate does not verify still answers, and an answering
    node is alive: the question here is only whether it is serving, so the check is repeated without
    certificate verification and nothing from the reply is trusted beyond its role and epoch.
    """
    for context in (ssl.create_default_context(), _unverified_context()):
        try:
            with socket.create_connection((address, 443), timeout=timeout) as raw:
                with context.wrap_socket(raw, server_hostname=hostname) as tls:
                    tls.sendall(f"GET /control-state HTTP/1.0\r\nHost: {hostname}\r\nAccept: application/json\r\n\r\n".encode())
                    data = b""
                    while chunk := tls.recv(4096):
                        data += chunk
                        if len(data) > 65536:
                            break
        except ssl.SSLCertVerificationError:
            continue
        except OSError:
            return None
        body = data.split(b"\r\n\r\n", 1)[1] if b"\r\n\r\n" in data else b""
        try:
            parsed = json.loads(body.decode("utf-8", "replace") or "{}")
        except ValueError:
            return {"role": "unknown"}
        return parsed if isinstance(parsed, dict) else {"role": "unknown"}
    return None


def _unverified_context() -> ssl.SSLContext:
    context = ssl.create_default_context()
    context.check_hostname = False
    context.verify_mode = ssl.CERT_NONE
    return context


class Control:
    def __init__(self, store: FleetState, repo_root: Path, output_dir: Path, runner: Runner, *, say: Callable[[str], None], sleep: Callable[[float], None] = time.sleep,
                 probe: Callable[[str, str], dict[str, Any] | None] = probe_control_state, now: Callable[[], float] = time.monotonic) -> None:
        self.store, self.repo_root, self.output_dir, self.runner = store, repo_root, output_dir, runner
        self.say, self.sleep, self.probe, self.now = say, sleep, probe, now

    # -- helpers -----------------------------------------------------------------------------------------

    def _active_and_standby(self, state: dict[str, Any]) -> tuple[dict[str, Any], dict[str, Any]]:
        if not ha.enabled(state):
            raise ValidationError("This fleet has no standby control node (`ha: none`)")
        section = ha.section(state)
        return state["nodes"][section["active"]], state["nodes"][section["standby"]]

    def _render(self, state: dict[str, Any]) -> dict[str, tuple[str, str]]:
        """Render every node; return {node: (digest before, digest after)}."""
        before = {name: bundle_digest(self.output_dir / name) for name in state["nodes"]}
        Renderer(self.repo_root, self.store, self.output_dir).render(state)
        return {name: (before[name], bundle_digest(self.output_dir / name)) for name in state["nodes"] if state["nodes"][name].get("enabled", True)}

    def _deploy_changed(self, state: dict[str, Any], digests: dict[str, tuple[str, str]], *, skip: frozenset[str] | set[str] = frozenset()) -> list[str]:
        changed = []
        for name, (old, new) in digests.items():
            if old == new or name in skip:
                continue
            node = state["nodes"][name]
            self.say(f"updating {name}")
            self.runner.push(node, self.output_dir / name)
            self.runner.run(node, "./start.sh", timeout=1800)
            changed.append(name)
        return changed

    def _artisan(self, node: dict[str, Any], arguments: str) -> str:
        return self.runner.run(node, f"{COMPOSE} exec -T core php artisan {arguments}")

    def node_status(self, node: dict[str, Any]) -> dict[str, Any]:
        return json.loads(self.runner.run(node, "./ha/status.sh").strip().splitlines()[-1])

    def application_status(self, node: dict[str, Any]) -> dict[str, Any]:
        return json.loads(self._artisan(node, "cdnf:control:status").strip().splitlines()[-1])

    def _wait(self, label: str, seconds: int, condition: Callable[[], bool]) -> None:
        deadline = self.now() + seconds
        while True:
            if condition():
                return
            if self.now() >= deadline:
                raise FleetError(f"Timed out after {seconds}s waiting for {label}")
            self.sleep(5)

    @staticmethod
    def _address(state: dict[str, Any], node: dict[str, Any]) -> str:
        """The address edges dial for this node: its IPv4 or IPv6 address, as chosen when the standby was added."""
        return node["public_ipv6"] if ha.section(state).get("address_family") == 6 else node["public_ipv4"]

    def _addresses(self, state: dict[str, Any]) -> str:
        return ",".join(self._address(state, node) for node in ha.control_nodes(state))

    # -- evaluation --------------------------------------------------------------------------------------

    @staticmethod
    def assess(active: dict[str, Any], standby: dict[str, Any], application: dict[str, Any], lag_limit: int = 10) -> tuple[str, list[str]]:
        """`ready` only when both databases stream within the bound, Valkey is linked, the secrets match, and every PoP holds the list."""
        reasons: list[str] = []
        for key in ("control_db", "pdns_db"):
            replica = standby.get(key) or {}
            if not replica.get("in_recovery") or not replica.get("streaming"):
                reasons.append(f"{key}_not_streaming")
            elif int(replica.get("lag_seconds") or 0) > lag_limit:
                reasons.append(f"{key}_lag")
        if (standby.get("valkey") or {}).get("link") != "up":
            reasons.append("valkey_not_linked")
        shared = ("edge_identity_ca_certificate", "edge_identity_ca_private_key", "edge_server_ca_certificate", "edge_proxy_secret", "valkey_password", "replication_password", "ingress_certificate")
        if any((active.get("files") or {}).get(key) != (standby.get("files") or {}).get(key) or (active.get("files") or {}).get(key) in (None, "missing") for key in shared):
            reasons.append("secret_fingerprint_mismatch")
        if not active.get("application") or active.get("application") != standby.get("application"):
            reasons.append("application_secret_fingerprint_mismatch")
        if int(application.get("edges_missing_addresses", 1)) > 0:
            reasons.append("edges_missing_control_addresses")
        return ("ready" if not reasons else "degraded"), reasons

    def check(self, state: dict[str, Any], *, record: bool = True) -> dict[str, Any]:
        active, standby = self._active_and_standby(state)
        active_status, standby_status = self.node_status(active), self.node_status(standby)
        application = self.application_status(active)
        verdict, reasons = self.assess(active_status, standby_status, application)
        if record:
            fingerprints = json.dumps({**(active_status.get("files") or {}), **{f"app_{k}": v for k, v in (active_status.get("application") or {}).items()}}, sort_keys=True)
            standby_fingerprints = json.dumps({**(standby_status.get("files") or {}), **{f"app_{k}": v for k, v in (standby_status.get("application") or {}).items()}}, sort_keys=True)
            replica = {"control_db": standby_status.get("control_db") or {}, "pdns_db": standby_status.get("pdns_db") or {}}
            lag = max(int(item.get("lag_seconds") or 0) for item in replica.values())
            linked = (standby_status.get("valkey") or {}).get("link") == "up"
            for node, role, prints in ((active, "active", fingerprints), (standby, "standby", standby_fingerprints)):
                observed = f" --lag={lag} --valkey-linked={int(linked)}" if role == "standby" else ""
                self._artisan(active, "cdnf:control:manage record --name={} --role={} --address={} --fingerprints={}{}".format(
                    node["name"], role, ha.replication_address(state, node["name"]) or node["public_ipv4"], shlex.quote(prints), observed))
        return {"ha": verdict, "reasons": reasons, "active": active["name"], "standby": standby["name"], "application": application}

    # -- actions -----------------------------------------------------------------------------------------

    def add_standby(self, host: str, *, name: str, replication_address: str, active_replication_address: str, certificate: Path, key: Path,
                    public_ipv4: str | None = None, public_ipv6: str | None = None, region: str | None = None, bind_ipv4: str = "0.0.0.0",
                    extra_env: dict[str, str] | None = None, address_family: int = 4, bind_ipv6: str | None = None, edge_wait: int = EDGE_WAIT_SECONDS) -> dict[str, Any]:
        state = self.store.load()
        validate_node_name(name)
        controls = [node for node in state["nodes"].values() if node["role"] == "control" and node.get("enabled", True)]
        resuming = ha.enabled(state) and ha.section(state).get("standby") == name
        if not resuming:
            if ha.enabled(state):
                raise ValidationError("A standby already exists; remove it first")
            if len(controls) != 1:
                raise ValidationError("add-standby needs exactly one existing control node")
            if address_family not in (4, 6):
                raise ValidationError("The control address family is 4 or 6")
            if address_family == 6 and not (public_ipv6 and controls[0].get("public_ipv6")):
                raise ValidationError("IPv6 control addresses need a public IPv6 address on both control nodes (--public-ipv6)")
            address = validate_ip(public_ipv4 or host, required=True)
            ha.validate_replication_address(replication_address)
            ha.validate_replication_address(active_replication_address)
            certificate_text, key_text = self._validate_ingress_certificate(state, certificate, key)
            active = controls[0]
            ha_dir = self.store.state_dir / "ha"
            ha_dir.mkdir(mode=0o700, exist_ok=True)
            atomic_write(ha_dir / "ingress.crt", certificate_text, 0o644)
            atomic_write(ha_dir / "ingress.key", key_text, 0o600)
            section = {
                "mode": "two-node", "active": active["name"], "standby": name, "ingress_certificate": True,
                "replication_address": {active["name"]: active_replication_address, name: replication_address},
                "telemetry_node": active["name"], "started_at": utc_now(), "address_family": address_family,
            }
            self.store.ensure_secret("control-replication-password")
            state = self.store.add_node(state, {"name": name, "role": "control", "region": region or active["region"], "location": active["location"],
                                               "public_ipv4": address, "public_ipv6": public_ipv6, "bind_ipv4": bind_ipv4, "bind_ipv6": bind_ipv6,
                                                "extra_env": extra_env or {}}, top_level={"ha": section})
            for secret in ("pdns-db-password", "pdns-api-key"):
                self.store.write_secret(secret, self.store.read_secret(secret, node=active["name"]), node=name)
        state = self.store.load()
        active, standby = self._active_and_standby(state)

        self.say("1/5 rendering both control addresses into every allowlist and configuration")
        digests = self._render(state)
        unchanged_elsewhere = [n for n, (a, b) in digests.items() if n not in (active["name"], standby["name"]) and a != b]
        # Only nodes whose bundle changed are touched. The standby is deployed at step 4.
        self._deploy_changed(state, digests, skip={standby["name"]})
        if unchanged_elsewhere:
            self.say(f"updated other nodes: {', '.join(unchanged_elsewhere)}")

        self.say("2/5 publishing the control address list to the edges")
        self._artisan(active, f"cdnf:control:manage addresses --addresses={self._addresses(state)}")
        self._artisan(active, f"cdnf:control:manage record --name={active['name']} --role=active --address={ha.replication_address(state, active['name'])}")
        self._wait("every enabled PoP to report the control address list", edge_wait,
                   lambda: int(self.application_status(active).get("edges_missing_addresses", 1)) == 0)

        self.say("3/5 preparing the active node as a replication source")
        self.runner.run(active, "./ha/prepare-source.sh")

        self.say("4/5 deploying the standby and copying both databases")
        self.runner.push(standby, self.output_dir / standby["name"])
        self.runner.run(standby, "./ha/seed-standby.sh", timeout=7200)

        self.say("5/5 checking replication, secrets, and PoPs")
        result: dict[str, Any] = {}

        def ready() -> bool:
            nonlocal result
            result = self.check(self.store.load())
            return result["ha"] == "ready"

        try:
            self._wait("ha: ready", READY_WAIT_SECONDS, ready)
        except FleetError as exc:
            raise FleetError(f"{exc}; reasons: {', '.join(result.get('reasons', ['unknown']))}") from exc
        with self.store.transaction() as candidate:
            candidate["metadata"]["last_successful_render"] = utc_now()
        return result

    @staticmethod
    def _validate_ingress_certificate(state: dict[str, Any], certificate: Path, key: Path) -> tuple[str, str]:
        """The standby must hold a valid certificate for the control hostname before it ever serves (it cannot issue one while idle)."""
        hostname = f"control.{state['global']['operator_domain']}"
        certificate_text, key_text = certificate.read_text(encoding="utf-8"), key.read_text(encoding="utf-8")
        checks = [
            (["openssl", "x509", "-noout", "-checkend", str(14 * 86400)], certificate_text, "expires within 14 days or is not a certificate"),
            (["openssl", "x509", "-noout", "-checkhost", hostname], certificate_text, f"does not cover {hostname}"),
        ]
        for command, text, problem in checks:
            result = subprocess.run(command, input=text.encode(), capture_output=True, check=False)
            if result.returncode != 0 or b"does NOT match" in result.stdout:
                raise ValidationError(f"The ingress certificate {problem}")
        public = subprocess.run(["openssl", "x509", "-noout", "-pubkey"], input=certificate_text.encode(), capture_output=True, check=False).stdout
        derived = subprocess.run(["openssl", "pkey", "-pubout"], input=key_text.encode(), capture_output=True, check=False).stdout
        if not public or public != derived:
            raise ValidationError("The ingress private key does not match the ingress certificate")
        return certificate_text, key_text

    def demote(self, state: dict[str, Any]) -> str:
        active, _ = self._active_and_standby(state)
        positions = self.runner.run(active, "./ha/demote.sh").strip().splitlines()[-1]
        with self.store.transaction() as candidate:
            candidate["ha"]["demoted"] = {"node": active["name"], "positions": positions, "at": utc_now()}
        self.say(f"{active['name']} is demoted; its databases are read-only")
        return positions

    def promote(self, *, old_active_is_powered_off: bool = False, edge_wait: int = PROMOTE_EDGE_WAIT_SECONDS) -> dict[str, Any]:
        state = self.store.load()
        old, new = self._active_and_standby(state)
        hostname = f"control.{state['global']['operator_domain']}"
        demoted = ha.section(state).get("demoted") or {}
        positions = demoted.get("positions", "") if demoted.get("node") == old["name"] else ""
        if not old_active_is_powered_off and not positions:
            answer = self.probe(self._address(state, old), hostname)
            if answer is None:
                raise ValidationError(f"{old['name']} did not answer, which does not prove it is down. Run `control demote` on it first, or confirm it is powered off with --old-active-is-powered-off.")
            if answer.get("role") == "active":
                raise ValidationError(f"{old['name']} is still the active node and is serving. Run `control demote` on it first (or `control switchover`).")
            if answer.get("role") != "standby":
                raise ValidationError(f"{old['name']} answers, but its role could not be read ({answer.get('role', 'unknown')}). Run `control demote` on it, or confirm that it is powered off with --old-active-is-powered-off.")
        epoch = self.runner.run(new, f"./ha/promote.sh {shlex.quote(positions)}".rstrip(), timeout=3600).strip().splitlines()[-1]
        with self.store.transaction() as candidate:
            section = candidate["ha"]
            section["active"], section["standby"] = new["name"], old["name"]
            section["standby_needs_rejoin"] = True
            section.pop("demoted", None)
        state = self.store.load()
        self.say(f"{new['name']} promoted at epoch {epoch}")
        digests = self._render(state)
        # The new active starts its application services under its active bundle.
        self.runner.push(new, self.output_dir / new["name"])
        self.runner.run(new, "./start.sh", timeout=3600)
        self._artisan(new, f"cdnf:control:manage addresses --addresses={self._addresses(state)}")
        self._deploy_changed(state, digests, skip={old["name"], new["name"]})
        status: dict[str, Any] = {}

        def syncing() -> bool:
            nonlocal status
            status = self.application_status(new)
            edges = status.get("edges") or {}
            return int(edges.get("enabled", 0)) > 0 and int(edges.get("recent_heartbeat", 0)) >= int(edges.get("enabled", 0))

        self._wait("every edge to sync with the new active node", edge_wait, syncing)
        zone = state["global"]["operator_domain"]
        record_type = "AAAA" if ha.section(state).get("address_family") == 6 else "A"
        record_change = [f"control.{zone}. 60 IN {record_type} {self._address(state, new)}", f"edge-control.{zone}. 60 IN {record_type} {self._address(state, new)}"]
        return {"epoch": int(epoch), "active": new["name"], "needs_rejoin": old["name"], "dns_changes": record_change}

    def switchover(self, **kwargs: Any) -> dict[str, Any]:
        state = self.store.load()
        self.demote(state)
        return self.promote(**kwargs)

    def rejoin(self) -> dict[str, Any]:
        state = self.store.load()
        active, standby = self._active_and_standby(state)
        if not ha.section(state).get("standby_needs_rejoin"):
            raise ValidationError("No former active node is waiting to rejoin")
        self.runner.push(standby, self.output_dir / standby["name"])
        self.runner.run(standby, "./ha/rejoin.sh", timeout=7200)
        with self.store.transaction() as candidate:
            candidate["ha"].pop("standby_needs_rejoin", None)
        self._artisan(active, f"cdnf:control:manage record --name={standby['name']} --role=standby --address={ha.replication_address(state, standby['name'])}")
        result: dict[str, Any] = {}

        def ready() -> bool:
            nonlocal result
            result = self.check(self.store.load())
            return result["ha"] == "ready"

        self._wait("ha: ready", READY_WAIT_SECONDS, ready)
        return result

    def remove_standby(self, *, edge_wait: int = EDGE_WAIT_SECONDS) -> dict[str, Any]:
        state = self.store.load()
        active, standby = self._active_and_standby(state)
        self.runner.run(active, "./ha/release-source.sh")
        self._artisan(active, "cdnf:control:manage remove-standby")
        self._wait("every enabled PoP to report the single control address", edge_wait,
                   lambda: int(self.application_status(active).get("edges_missing_addresses", 1)) == 0)
        try:
            self.runner.run(standby, "./stop.sh")
        except FleetError as exc:
            self.say(f"the standby could not be stopped ({exc}); stop it by hand")
        with self.store.transaction() as candidate:
            candidate["ha"] = {"mode": "none"}
            candidate["nodes"].pop(standby["name"], None)
        state = self.store.load()
        digests = self._render(state)
        self._deploy_changed(state, digests)
        return {"ha": "none", "active": active["name"], "removed": standby["name"]}


def add_parser(sub: Any, common: Any) -> None:
    control = sub.add_parser("control", parents=[common], help="optional two-node control plane: standby, promotion, rejoin")
    actions = control.add_subparsers(dest="control_action", required=True)
    shared = common
    for command in ("status", "check"):
        action = actions.add_parser(command, parents=[shared], help="show high availability state" if command == "status" else "compare secrets and replication on both nodes and record the result")
        action.add_argument("--json", action="store_true")
    add = actions.add_parser("add-standby", parents=[shared], help="add a warm standby control node to a running installation")
    add.add_argument("host", help="address or hostname of the new node")
    add.add_argument("--name", required=True)
    add.add_argument("--public-ipv4")
    add.add_argument("--public-ipv6")
    add.add_argument("--region")
    add.add_argument("--bind-ipv4", default="0.0.0.0")
    add.add_argument("--bind-ipv6")
    add.add_argument("--control-address-family", type=int, choices=(4, 6), default=4,
                     help="the address family edges dial: the two control addresses are the nodes' IPv4 or IPv6 addresses")
    add.add_argument("--extra-env", action="append", default=[], metavar="KEY=VALUE", help="per-node environment override, repeatable")
    add.add_argument("--replication-address", required=True, help="the standby's private (VPN) address")
    add.add_argument("--active-replication-address", required=True, help="the active node's private (VPN) address")
    add.add_argument("--ingress-certificate", required=True, help="operator-supplied certificate for control.<operator-zone>")
    add.add_argument("--ingress-key", required=True)
    add.add_argument("--edge-timeout", type=int, default=EDGE_WAIT_SECONDS)
    promote = actions.add_parser("promote", parents=[shared], help="promote the standby after the active node is lost or demoted")
    promote.add_argument("--old-active-is-powered-off", action="store_true")
    promote.add_argument("--edge-timeout", type=int, default=PROMOTE_EDGE_WAIT_SECONDS)
    actions.add_parser("demote", parents=[shared], help="stop the application services on the active node and make its databases read-only")
    switch = actions.add_parser("switchover", parents=[shared], help="demote the active node, then promote the standby, losing no committed write")
    switch.add_argument("--edge-timeout", type=int, default=PROMOTE_EDGE_WAIT_SECONDS)
    actions.add_parser("rejoin", parents=[shared], help="rebuild the former active node as the new standby")
    remove = actions.add_parser("remove-standby", parents=[shared], help="remove the standby and return to a single node")
    remove.add_argument("--edge-timeout", type=int, default=EDGE_WAIT_SECONDS)
    for action in actions.choices.values():
        action.add_argument("--ssh-user", default="root")
        action.add_argument("--deploy-dir", default="/opt/cdnfoundry")
        action.add_argument("--ssh-command", default=" ".join(DEFAULT_SSH), help="command used to reach a node; replaceable for tests")


def execute(args: Any, store: FleetState, output_dir: Path, *, runner: Runner | None = None, say: Callable[[str], None] | None = None,
            probe: Callable[[str, str], dict[str, Any] | None] = probe_control_state) -> dict[str, Any]:
    runner = runner or Runner(tuple(shlex.split(args.ssh_command)), user=args.ssh_user, deploy_dir=args.deploy_dir)
    control = Control(store, Path(args.repo_root), output_dir, runner, say=say or (lambda message: print(message, flush=True)), probe=probe)
    action = args.control_action
    state = store.load()
    if action in {"status", "check"}:
        if not ha.enabled(state):
            return {"ha": "none", "warning": "single control node: no standby"}
        return control.check(state, record=action == "check")
    if action == "add-standby":
        return control.add_standby(args.host, name=args.name, replication_address=args.replication_address,
                                   active_replication_address=args.active_replication_address, certificate=Path(args.ingress_certificate),
                                   key=Path(args.ingress_key), public_ipv4=args.public_ipv4, public_ipv6=args.public_ipv6, region=args.region, bind_ipv4=args.bind_ipv4, address_family=args.control_address_family, bind_ipv6=args.bind_ipv6,
                                   extra_env=dict(item.split("=", 1) for item in args.extra_env if "=" in item), edge_wait=args.edge_timeout)
    if action == "demote":
        return {"positions": control.demote(state)}
    if action == "promote":
        return control.promote(old_active_is_powered_off=args.old_active_is_powered_off, edge_wait=args.edge_timeout)
    if action == "switchover":
        return control.switchover(edge_wait=args.edge_timeout)
    if action == "rejoin":
        return control.rejoin()
    if action == "remove-standby":
        return control.remove_standby(edge_wait=args.edge_timeout)
    raise ValidationError(f"Unsupported control action: {action}")
