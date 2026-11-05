#!/usr/bin/env python3
"""Real two-node control-plane qualification. This does not automate a browser.

It renders two production control bundles with the fleet generator and runs them as two Compose projects on
this host, each with its own networks, volumes, and address (10.77.0.1 and 10.77.0.2 on the loopback
interface). A real edge agent, with its own identity, talks to them over mutual TLS. Everything the
`cdnfoundry-fleet control` commands do runs for real: base backups, streaming replication, promotion, rejoin.

The scenario follows the phase exit list: single node, add-standby on a running installation, kill the
active node, promote, refusal while the old node serves, return of the old node, rejoin, planned
switchover, a mismatched key, and removal.

Needs: Docker with Compose, root, `ip`, `openssl`, and images tagged ghcr.io/vaheed/cdnfoundry-*:ha-test
(`docker buildx bake -f docker-bake.hcl core web edge-control edge-agent mmdb-updater --load` with those tags).
The pinned third-party images are pulled when missing. Nothing is removed from any existing Docker volume;
this lab uses only volumes of its own `cdnf-ha-*` projects, which `--keep` leaves in place.
"""
from __future__ import annotations

import argparse
import http.server
import json
import os
import pathlib
import shlex
import subprocess
import sys
import tempfile
import threading
import time

ROOT = pathlib.Path(__file__).resolve().parents[2]
SHELL = ROOT / "tests/e2e/ha_node_shell.py"
ADDRESS = {"control-a": "10.77.0.1", "control-b": "10.77.0.2"}
ADDRESS6 = {"control-a": "fd77::1", "control-b": "fd77::2"}
FAMILY = 4  # the address family the edge dials; 6 makes the two control addresses an IPv6 pair
RELEASE = "ha-test"
OPERATOR = "ha.test"
EDGE_NAME = "pop-ha"
STATUS_PORT = 18099


def dial(node: str) -> str:
    """The address the edge agent holds and dials for a node in this run."""
    return ADDRESS6[node] if FAMILY == 6 else ADDRESS[node]


class Lab:
    def __init__(self, directory: pathlib.Path) -> None:
        self.directory = directory
        self.state = directory / "state"
        self.bundles = directory / "bundles"
        # A new project name per run keeps a run from adopting the volumes of an earlier one.
        self.project = f"cdnf-ha-{int(time.time()) % 100000}"
        self.env = {**os.environ, "HA_LAB_PROJECT": self.project, "HA_LAB_ROOT": str(directory), "HA_LAB_NODES": ",".join(f"{n}={a}" for n, a in ADDRESS.items())}
        self.agent = "cdnf-ha-agent"
        self.token = ""
        self.edge_id = ""
        self.status_server: http.server.HTTPServer | None = None

    # -- process helpers ---------------------------------------------------------------------------------

    @property
    def agent_volume(self) -> str:
        return f"{self.project}-agent-state"

    def run(self, *command: str, check: bool = True, input_text: str | None = None, timeout: int = 1800) -> subprocess.CompletedProcess[str]:
        result = subprocess.run(command, cwd=ROOT, text=True, capture_output=True, env=self.env, input=input_text, timeout=timeout, check=False)
        if check and result.returncode:
            raise RuntimeError(f"{shlex.join(command)} failed ({result.returncode}):\n{result.stdout[-3000:]}\n{result.stderr[-3000:]}")
        return result

    def fleet(self, *arguments: str, check: bool = True) -> subprocess.CompletedProcess[str]:
        return self.run(sys.executable, "scripts/cdnfoundry-fleet", "--state-dir", str(self.state), "--output-dir", str(self.bundles), "--repo-root", str(ROOT),
                        "--non-interactive", "--yes", *arguments, check=check)

    def control(self, *arguments: str, check: bool = True) -> subprocess.CompletedProcess[str]:
        return self.fleet("control", *arguments, "--ssh-command", f"{sys.executable} {SHELL}", check=check)

    def on(self, node: str, command: str, check: bool = True, timeout: int = 1800) -> subprocess.CompletedProcess[str]:
        return self.run(sys.executable, str(SHELL), f"root@{ADDRESS[node]}", f"cd /opt/cdnfoundry && {command}", check=check, timeout=timeout)

    def artisan(self, node: str, arguments: str, check: bool = True) -> subprocess.CompletedProcess[str]:
        return self.on(node, f"docker compose --env-file .env.prod exec -T core php artisan {arguments}", check=check)

    def status(self, node: str) -> dict:
        return json.loads(self.artisan(node, "cdnf:control:status").stdout.strip().splitlines()[-1])

    def state_json(self) -> dict:
        return json.loads((self.state / "fleet.json").read_text())

    # -- lab ---------------------------------------------------------------------------------------------

    def write_network_overrides(self) -> None:
        for index, node in enumerate(ADDRESS, start=1):
            base = index * 10
            networks = {name: {"ipam": {"config": [{"subnet": f"10.88.{base + offset}.0/24"}]}} for offset, name in enumerate(("control", "ingress", "dns-private", "telemetry", "egress"))}
            (self.directory / node).mkdir(parents=True, exist_ok=True)
            # Caddy also publishes 8444 for telemetry; the development stack on this host already holds it.
            ports = [f"{ADDRESS[node]}:80:80/tcp", f"{ADDRESS[node]}:443:443/tcp"]
            if FAMILY == 6:
                ports += [f"[{ADDRESS6[node]}]:80:80/tcp", f"[{ADDRESS6[node]}]:443:443/tcp"]
            override = {"networks": networks, "services": {"caddy": {"ports": ports}}}
            text = json.dumps(override).replace('"ports": [', '"ports": !override [')
            (self.directory / node / "compose.override.yml").write_text(text)

    def prepare(self) -> None:
        self.write_network_overrides()
        for address in ADDRESS.values():
            self.run("ip", "addr", "add", f"{address}/32", "dev", "lo", check=False)
        for address in ADDRESS6.values():
            self.run("ip", "-6", "addr", "add", f"{address}/128", "dev", "lo", check=False)
        self.directory.mkdir(parents=True, exist_ok=True)
        self.run("openssl", "req", "-x509", "-newkey", "ec", "-pkeyopt", "ec_paramgen_curve:prime256v1", "-nodes", "-keyout", str(self.directory / "ingress.key"),
                 "-out", str(self.directory / "ingress.crt"), "-days", "60", "-subj", f"/CN=control.{OPERATOR}", "-addext", f"subjectAltName=DNS:control.{OPERATOR}")
        self.fleet("init", "--operator-domain", OPERATOR, "--platform-domain", "ha-platform.test", "--release", RELEASE, "--acme-email", f"ops@{OPERATOR}")
        self.fleet("add-node", "--node", "control-a", "--role", "control", "--region", "global", "--location", "site-a", "--public-ipv4", ADDRESS["control-a"],
                   *(["--public-ipv6", ADDRESS6["control-a"], "--bind-ipv6", ADDRESS6["control-a"]] if FAMILY == 6 else []),
                   "--bind-ipv4", ADDRESS["control-a"], "--extra-env", f"EDGE_CONTROL_BIND={ADDRESS['control-a']}:8443", "--extra-env", f"CONTROL_BIND={ADDRESS['control-a']}:18080",
                   "--extra-env", f"APP_URL=http://control.{OPERATOR}:18080", "--extra-env", "FPM_NETWORK_SUBNET=10.88.15.0/29", "--extra-env", "EDGE_NETWORK_SUBNET=10.88.16.0/24", *image_environment())

    def single_node_up(self) -> None:
        self.fleet("render")
        self.run(sys.executable, str(SHELL), f"root@{ADDRESS['control-a']}", "true")  # creates the node directory
        from importlib import import_module

        sys.path.insert(0, str(ROOT / "scripts"))
        control_cli = import_module("cdnfoundry_fleet.control_cli")
        runner = control_cli.Runner((sys.executable, str(SHELL)))
        os.environ.update({key: self.env[key] for key in ("HA_LAB_ROOT", "HA_LAB_NODES", "HA_LAB_PROJECT")})
        node = self.state_json()["nodes"]["control-a"]
        runner.push(node, self.bundles / "control-a")
        self.on("control-a", "./start.sh", timeout=3600)

    def create_edge(self) -> None:
        php = (
            'require "vendor/autoload.php"; $app = require "bootstrap/app.php"; $app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();'
            ' $u = App\\Models\\User::query()->firstOrCreate(["email" => "ha@example.test"], ["name" => "HA", "password" => "ha-test-password-12345", "type" => App\\Enums\\UserType::Admin]);'
            ' echo $u->createToken("ha", ["*"])->plainTextToken;'
        )
        token = self.on("control-a", f"docker compose --env-file .env.prod exec -T core php -r {shlex.quote(php)}").stdout.strip().splitlines()[-1]
        body = json.dumps({"name": EDGE_NAME, "country_code": "DE", "continent_code": "EU"})
        out = self.run("curl", "-sS", "--max-time", "30", "-X", "POST", f"http://{ADDRESS['control-a']}:18080/api/admin/edges", "-H", f"Authorization: Bearer {token}",
                       "-H", "Content-Type: application/json", "-H", "Accept: application/json", "-H", "Idempotency-Key: 11111111-1111-4111-8111-111111111111", "-d", body).stdout
        data = json.loads(out)["data"]
        self.edge_id, self.token = data["id"], data["bootstrap_token"]

    def start_status_server(self) -> None:
        class Handler(http.server.BaseHTTPRequestHandler):
            def do_GET(self) -> None:  # noqa: N802
                body = json.dumps({"cell": {"name": "cell-01", "status": "ready", "capacity": {"active_connections": 0}}, "data": [], "security": []}).encode()
                self.send_response(200)
                self.send_header("Content-Type", "application/json")
                self.send_header("Content-Length", str(len(body)))
                self.end_headers()
                self.wfile.write(body)

            def log_message(self, *args: object) -> None:
                pass

        try:
            self.status_server = http.server.ThreadingHTTPServer(("127.0.0.1", STATUS_PORT), Handler)
        except OSError:
            return  # a previous run's endpoint is still serving
        threading.Thread(target=self.status_server.serve_forever, daemon=True).start()

    def start_agent(self, seed: str = "") -> None:
        self.run("docker", "rm", "-f", self.agent, check=False)
        volume = self.agent_volume
        self.run("docker", "run", "-d", "--name", self.agent, "--network", "host", "--restart", "unless-stopped",
                 "--add-host", f"edge-control.{OPERATOR}:{dial('control-a')}",
                 "-v", f"{volume}:/var/lib/cdnfoundry/agent", "-v", f"{self.state}/pki/edge-server-ca.crt:/run/secrets/edge-control-ca.crt:ro",
                 "-e", f"EDGE_CONTROL_URL=https://edge-control.{OPERATOR}:8443", "-e", "EDGE_CONTROL_CA_CERTIFICATE=/run/secrets/edge-control-ca.crt",
                 "-e", f"EDGE_ID={self.edge_id}", "-e", f"EDGE_BOOTSTRAP_TOKEN={self.token}", "-e", "EDGE_STATE_DIR=/var/lib/cdnfoundry/agent",
                 "-e", f"EDGE_CELL_STATUS_URLS=http://127.0.0.1:{STATUS_PORT}/passive-failures", "-e", "EDGE_STATUS_TOKEN=ha-lab-status-token",
                 "-e", "EDGE_RUNTIME_DIR=/var/lib/cdnfoundry/agent/runtime", "-e", "EDGE_CELL_ASSIGNMENTS={}", *(["-e", f"EDGE_CONTROL_ADDRESSES={seed}"] if seed else []),
                 f"ghcr.io/vaheed/cdnfoundry-edge-agent:{RELEASE}")

    def agent_state(self) -> dict:
        out = self.run("docker", "run", "--rm", "-v", f"{self.agent_volume}:/s:ro", "python:3.13-alpine", "sh", "-c", "cat /s/control-addresses.json 2>/dev/null || echo '{}'", check=False).stdout
        return json.loads(out or "{}")

    def agent_logs(self) -> str:
        return self.run("docker", "logs", "--tail", "200", self.agent, check=False).stdout + self.run("docker", "logs", "--tail", "200", self.agent, check=False).stderr

    def wait(self, label: str, seconds: int, condition) -> None:  # noqa: ANN001
        deadline = time.monotonic() + seconds
        last = None
        while time.monotonic() < deadline:
            try:
                last = condition()
                if last:
                    return
            except Exception as error:  # noqa: BLE001
                last = error
            time.sleep(3)
        raise AssertionError(f"timed out after {seconds}s waiting for {label}: {last!r}")

    def edge_heartbeat_age(self, node: str) -> float | None:
        php = (
            'require "vendor/autoload.php"; $app = require "bootstrap/app.php"; $app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();'
            ' $e = App\\Models\\Edge::query()->first(); echo $e && $e->last_heartbeat_at ? now()->diffInSeconds($e->last_heartbeat_at, true) : "none";'
        )
        value = self.on(node, f"docker compose --env-file .env.prod exec -T core php -r {shlex.quote(php)}").stdout.strip().splitlines()[-1]
        return None if value == "none" else float(value)

    def stop_node(self, node: str) -> None:
        """Kill: containers die without a graceful stop, as a lost host would."""
        names = self.on(node, "docker compose --env-file .env.prod ps -q").stdout.split()
        if names:
            self.run("docker", "kill", *names, check=False)

    def sql(self, node: str, query: str, service: str = "control-db", user: str = "cdnf", database: str = "cdnf") -> str:
        return self.on(node, f"docker compose --env-file .env.prod exec -T {service} psql -U {user} -d {database} -At -c {shlex.quote(query)}").stdout.strip()

    def cleanup(self) -> None:
        self.run("docker", "rm", "-f", self.agent, check=False)
        if self.status_server:
            self.status_server.shutdown()
        for node in ADDRESS:
            self.run(sys.executable, str(SHELL), f"root@{ADDRESS[node]}", "cd /opt/cdnfoundry 2>/dev/null && docker compose --env-file .env.prod down --remove-orphans", check=False)
        for address in ADDRESS.values():
            self.run("ip", "addr", "del", f"{address}/32", "dev", "lo", check=False)
        for address in ADDRESS6.values():
            self.run("ip", "-6", "addr", "del", f"{address}/128", "dev", "lo", check=False)


def image_environment() -> list[str]:
    """The bundle validator accepts only immutable references; a locally built image is addressed by its content ID."""
    names = {"CDNF_CORE_IMAGE": "core", "CDNF_WEB_IMAGE": "web", "CDNF_EDGE_CONTROL_IMAGE": "edge-control", "CDNF_MMDB_UPDATER_IMAGE": "mmdb-updater"}
    arguments = []
    for variable, image in names.items():
        identifier = subprocess.run(["docker", "image", "inspect", "--format", "{{.Id}}", f"ghcr.io/vaheed/cdnfoundry-{image}:{RELEASE}"],
                                    text=True, capture_output=True, check=True).stdout.strip()
        arguments += ["--extra-env", f"{variable}={identifier}"]
    return arguments


def step(message: str) -> None:
    print(f"== {message}", flush=True)


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("--family", type=int, choices=(4, 6), default=4, help="address family of the control addresses the edge dials (default 4)")
    parser.add_argument("--keep", action="store_true", help="leave the lab running for inspection")
    parser.add_argument("--directory", type=pathlib.Path, help="lab directory (default: a new temporary directory)")
    parser.add_argument("--resume-after-standby", metavar="PROJECT", help="continue a kept lab whose standby is already ready (its Compose project prefix)")
    parser.add_argument("--from-step", default="refuse", choices=[name for name, _ in STEPS], help="with --resume-after-standby, the first scenario step to run")
    arguments = parser.parse_args()
    global FAMILY
    FAMILY = arguments.family
    lab = Lab(arguments.directory or pathlib.Path(tempfile.mkdtemp(prefix="cdnf-ha-")))
    if arguments.resume_after_standby:
        lab.project = arguments.resume_after_standby
        lab.env["HA_LAB_PROJECT"] = lab.project
        lab.edge_id = ""
    try:
        run_scenario(lab, resume=bool(arguments.resume_after_standby), start=arguments.from_step)
    finally:
        if not arguments.keep:
            lab.cleanup()
    print("control_ha=passed")
    return 0


def run_scenario(lab: Lab, resume: bool = False, start: str = "refuse") -> None:
    if resume:
        # The lab already holds a ready pair and a running agent; only the agent's status endpoint (this process) is gone.
        lab.start_status_server()
        active = lab.state_json()["ha"]["active"]
        lab.wait("the edge heartbeat", 120, lambda: (lab.edge_heartbeat_age(active) is not None) and lab.edge_heartbeat_age(active) < 60)
        if start == "refuse":
            verify_standby(lab)
        return failover_and_beyond(lab, start)
    single_node_and_standby(lab)
    verify_standby(lab)
    failover_and_beyond(lab)


def single_node_and_standby(lab: Lab) -> None:
    step("single node: ha is none and is only a warning")
    lab.prepare()
    lab.single_node_up()
    status = lab.status("control-a")
    assert status["ha"] == "none" and status["edges"]["enabled"] == 0, status
    health = lab.on("control-a", "docker compose --env-file .env.prod exec -T core curl -sf http://web:8080/api/health").stdout
    assert "ok" in health

    step("an edge agent registers against the single node")
    lab.create_edge()
    lab.start_status_server()
    lab.start_agent()
    lab.wait("the edge heartbeat", 180, lambda: lab.edge_heartbeat_age("control-a") is not None)

    step("add-standby on a running installation reaches ha: ready")
    started = time.monotonic()
    lab.control("add-standby", ADDRESS["control-b"], "--name", "control-b", "--replication-address", ADDRESS["control-b"], "--active-replication-address", ADDRESS["control-a"],
                *(["--public-ipv6", ADDRESS6["control-b"], "--bind-ipv6", ADDRESS6["control-b"], "--control-address-family", "6"] if FAMILY == 6 else []),
                "--ingress-certificate", str(lab.directory / "ingress.crt"), "--ingress-key", str(lab.directory / "ingress.key"),
                "--bind-ipv4", ADDRESS["control-b"], "--extra-env", f"EDGE_CONTROL_BIND={ADDRESS['control-b']}:8443", "--extra-env", f"CONTROL_BIND={ADDRESS['control-b']}:18080",
                "--extra-env", f"APP_URL=http://control.{OPERATOR}:18080", "--extra-env", "FPM_NETWORK_SUBNET=10.88.25.0/29", "--extra-env", "EDGE_NETWORK_SUBNET=10.88.26.0/24", *image_environment(), "--edge-timeout", "600")
    print(f"   add-standby took {time.monotonic() - started:.0f}s")
    assert lab.status("control-a")["ha"] == "ready", lab.status("control-a")
    assert json.loads(lab.control("status").stdout)["ha"] == "ready"
    held = lab.agent_state()
    assert held.get("addresses") == [dial("control-a"), dial("control-b")], held



def verify_standby(lab: Lab) -> None:
    step("the standby is a real streaming replica and runs no application service")
    assert lab.sql("control-b", "select pg_is_in_recovery()") == "t"
    assert lab.sql("control-b", "select pg_is_in_recovery()", "pdns-db", "pdns", "pdns") == "t"
    running = set(lab.on("control-b", "docker compose --env-file .env.prod ps --services --status running").stdout.split())
    assert not running & {"web", "edge-control", "horizon", "scheduler", "core", "caddy", "pdns-primary"}, running
    marker = lab.sql("control-a", "select count(*) from users")
    assert lab.sql("control-b", "select count(*) from users") == marker

    step("a write on the active node reaches the standby")
    lab.sql("control-a", "update control_plane_state set updated_at = now()")
    lab.wait("replay", 30, lambda: lab.sql("control-b", "select epoch from control_plane_state") == "1")



def step_refuse(lab: Lab) -> None:
    step("promote refuses while the old active node is still serving")
    refused = lab.control("promote", check=False)
    assert refused.returncode != 0 and "still the active node" in (refused.stderr + refused.stdout), refused.stdout + refused.stderr


def step_failover(lab: Lab) -> None:
    step("kill the active node: promote moves control and the agent follows")
    lab.sql("control-a", "create table if not exists ha_before_loss (n int)")
    lab.sql("control-a", "insert into ha_before_loss values (42)")
    time.sleep(12)  # more than the replication bound: this write is older than the bound
    lab.stop_node("control-a")
    killed_at = time.monotonic()
    time.sleep(3)
    unsure = lab.control("promote", check=False)
    assert unsure.returncode != 0 and "does not prove it is down" in (unsure.stderr + unsure.stdout), unsure.stdout + unsure.stderr
    lab.control("promote", "--old-active-is-powered-off", "--edge-timeout", "300")
    took = time.monotonic() - killed_at
    assert took < 300, took
    print(f"   promoted and synced in {took:.0f}s")
    assert lab.sql("control-b", "select epoch from control_plane_state") == "2"
    assert lab.sql("control-b", "select active_node from control_plane_state") == "control-b"
    assert lab.sql("control-b", "select n from ha_before_loss") == "42", "a write older than the replication bound must survive"
    state = lab.agent_state()
    assert state["last_good"] == dial("control-b") and state["epoch"] >= 2, state
    assert lab.status("control-b")["edges"]["recent_heartbeat"] == 1


def step_old_node_returns(lab: Lab) -> None:
    step("the old active node returns: no application services, and edges ignore it")
    lab.on("control-a", "docker compose --env-file .env.prod up -d control-db redis cache pdns-db", timeout=600)
    lab.on("control-a", "docker compose --env-file .env.prod up -d web edge-control core horizon scheduler", check=False, timeout=600)
    time.sleep(40)
    guard = lab.on("control-a", "docker compose --env-file .env.prod run --rm --no-deps -T core php artisan cdnf:control:guard", check=False)
    assert guard.returncode != 0 and "superseded_by_higher_epoch" in guard.stderr + guard.stdout, guard.stdout + guard.stderr
    for service in ("horizon", "scheduler"):
        running = lab.on("control-a", f"docker compose --env-file .env.prod ps --services --status running {service}").stdout.split()
        logs = lab.on("control-a", f"docker compose --env-file .env.prod logs --no-color --tail 20 {service}").stdout
        assert service not in running and "fenced" in logs, (service, running, logs[-400:])
    # Its own edge-control may answer, but with the old epoch: the agent never talked to it after accepting the new one.
    before = lab.sql("control-a", "select coalesce(max(last_heartbeat_at)::text, 'none') from edges")
    time.sleep(35)
    assert lab.sql("control-a", "select coalesce(max(last_heartbeat_at)::text, 'none') from edges") == before
    state = lab.agent_state()
    assert state["epoch"] >= 2 and state["last_good"] == dial("control-b"), state


def step_rejoin(lab: Lab) -> None:
    step("rejoin makes the old active node a healthy standby")
    lab.on("control-a", "docker compose --env-file .env.prod stop web edge-control core horizon scheduler caddy pdns-primary", check=False)
    lab.control("rejoin")
    assert lab.sql("control-a", "select pg_is_in_recovery()") == "t"
    assert lab.status("control-b")["ha"] == "ready", lab.status("control-b")


def step_switchover(lab: Lab) -> None:
    step("a planned switchover loses no committed write")
    lab.sql("control-b", "create table if not exists ha_probe (n int)")
    lab.sql("control-b", "insert into ha_probe select generate_series(1, 500)")
    lab.control("switchover", "--edge-timeout", "300")
    assert lab.sql("control-a", "select count(*) from ha_probe") == "500"
    epoch = lab.sql("control-a", "select epoch from control_plane_state")
    assert int(epoch) >= 3, epoch
    assert lab.sql("control-b", "show default_transaction_read_only") == "on"
    lab.control("rejoin")
    assert lab.status("control-a")["ha"] == "ready", lab.status("control-a")


def step_mismatch(lab: Lab) -> None:
    step("a node with a mismatched signing key cannot reach ha: ready")
    lab.on("control-b", "cp .env.prod .env.prod.bak && sed -i 's/^EDGE_ARTIFACT_SIGNING_KEY=.*/EDGE_ARTIFACT_SIGNING_KEY=tampered-signing-key-value-not-shared/' .env.prod")
    try:
        result = json.loads(lab.control("check").stdout)
        assert result["ha"] == "degraded" and "application_secret_fingerprint_mismatch" in result["reasons"], result
        recorded = lab.status("control-a")
        assert recorded["ha"] == "degraded" and "secret_fingerprint_mismatch" in recorded["reasons"], recorded
    finally:
        lab.on("control-b", "mv .env.prod.bak .env.prod")
    assert json.loads(lab.control("check").stdout)["ha"] == "ready"
    assert lab.status("control-a")["ha"] == "ready"


def step_remove(lab: Lab) -> None:
    step("remove-standby returns the installation to ha: none")
    lab.control("remove-standby", "--edge-timeout", "300")
    final = lab.state_json()
    assert final["ha"]["mode"] == "none" and len(final["nodes"]) == 1
    active = next(iter(final["nodes"]))
    assert lab.status(active)["ha"] == "none"
    lab.wait("the agent holds one address", 120, lambda: len(lab.agent_state().get("addresses", [])) == 1)


STEPS = (("refuse", step_refuse), ("failover", step_failover), ("returns", step_old_node_returns), ("rejoin", step_rejoin),
         ("switchover", step_switchover), ("mismatch", step_mismatch), ("remove", step_remove))


def failover_and_beyond(lab: Lab, start: str = "refuse") -> None:
    names = [name for name, _ in STEPS]
    for name, function in STEPS[names.index(start):]:
        function(lab)


if __name__ == "__main__":
    raise SystemExit(main())
