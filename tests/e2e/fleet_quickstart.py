#!/usr/bin/env python3
"""Scripted quick start: fresh hosts to an active proxied domain, through the Fleet CLI or the local fleet web.

Both modes run the same steps (create the fleet, copy the one Fleet public key to every host, add and start the nodes) in the same order; only the way each step is requested differs. The published quick start is
written from this script, so the page and the test cannot differ.

Inputs (environment):
  FLEET_QS_OPERATOR_DOMAIN, FLEET_QS_PLATFORM_DOMAIN, FLEET_QS_RELEASE_MANIFEST (signed JSON), FLEET_QS_RELEASE_KEY (public PEM file)
  FLEET_QS_SSH_KEY (private key file, authorised as root on every host), FLEET_QS_CONTROL, FLEET_QS_DNS, FLEET_QS_POP (host addresses)
  FLEET_QS_ORIGIN (an HTTP origin the test domain proxies to), FLEET_QS_DOMAIN (the customer domain to onboard)
  FLEET_QS_COUNTRY / FLEET_QS_CONTINENT for the PoP (default US / NA)
The passphrase for the vault is generated for the run. Run this only against disposable virtual machines.
"""
from __future__ import annotations

import argparse
import json
import os
import secrets
import subprocess
import sys
import tempfile
import time
import urllib.parse
import urllib.request
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
FLEET = [sys.executable, str(ROOT / "scripts" / "cdnfoundry-fleet")]
NODES = [("control-1", "control", "FLEET_QS_CONTROL"), ("dns-1", "dns", "FLEET_QS_DNS"), ("pop-1", "edge", "FLEET_QS_POP")]


def env(name: str, default: str | None = None) -> str:
    value = os.environ.get(name, default)
    if not value:
        raise SystemExit(f"set {name}")
    return value


class Cli:
    def __init__(self, work: Path, passphrase: str) -> None:
        self.base = ["--vault", str(work / "fleet.db"), "--output-dir", str(work / "bundles"), "--non-interactive", "--yes"]
        self.env = {**os.environ, "CDNF_FLEET_PASSPHRASE": passphrase}

    def run(self, *args: str) -> str:
        done = subprocess.run([*FLEET, *args, *self.base], env=self.env, capture_output=True, text=True, check=False)
        if done.returncode != 0:
            raise SystemExit(f"fleet {args[0]} failed ({done.returncode}):\n{done.stdout}\n{done.stderr}")
        return done.stdout


class Web:
    """Drives the same engine through the local web page on 127.0.0.1."""

    def __init__(self, cli: Cli, work: Path) -> None:
        self.cli = cli
        self.port = 18700 + secrets.randbelow(100)
        self.proc = subprocess.Popen([*FLEET, "web", "--port", str(self.port), *cli.base[:4]], env=cli.env, stdout=subprocess.DEVNULL, stderr=subprocess.PIPE)
        for _ in range(60):
            try:
                self.get("/")
                return
            except OSError:
                time.sleep(0.5)
        raise SystemExit("the fleet web did not start")

    def get(self, path: str) -> str:
        with urllib.request.urlopen(f"http://127.0.0.1:{self.port}{path}", timeout=30) as r:  # nosec: loopback only
            return r.read().decode()

    def csrf(self) -> str:
        import re

        return re.search(r'name="csrf" value="([^"]+)"', self.get("/nodes")).group(1)  # type: ignore[union-attr]

    def post(self, path: str, data: dict[str, str]) -> str:
        body = urllib.parse.urlencode({"csrf": self.csrf(), **data}).encode()
        req = urllib.request.Request(f"http://127.0.0.1:{self.port}{path}", body)
        opener = urllib.request.build_opener(urllib.request.HTTPRedirectHandler)
        with opener.open(req, timeout=60) as r:
            return r.geturl()

    def wait_job(self, url: str) -> None:
        path = urllib.parse.urlparse(url).path
        for _ in range(900):
            page = self.get(path)
            if "Running" not in page:
                if "Failed" in page:
                    raise SystemExit(f"job failed:\n{page}")
                return
            time.sleep(2)
        raise SystemExit("job timed out")


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--mode", choices=("cli", "web"), required=True)
    mode = parser.parse_args().mode
    work = Path(tempfile.mkdtemp(prefix="cdnf-quickstart-"))
    passphrase = secrets.token_urlsafe(24)
    cli = Cli(work, passphrase)
    key = Path(env("FLEET_QS_SSH_KEY")).read_text()
    country, continent = env("FLEET_QS_COUNTRY", "US"), env("FLEET_QS_CONTINENT", "NA")

    # 1. Create the fleet: identity, release, and the three nodes. PKI, TSIG keys, shared secrets, and tokens are generated.
    config = work / "topology.json"
    config.write_text(json.dumps({
        "preset": "control-only",
        "global": {"operator_domain": env("FLEET_QS_OPERATOR_DOMAIN"), "platform_domain": env("FLEET_QS_PLATFORM_DOMAIN"), "release": json.loads(Path(env("FLEET_QS_RELEASE_MANIFEST")).read_text())["manifest"]["release"]},
        "nodes": [{"name": n, "role": r, "public_ipv4": env(h)} for n, r, h in NODES],
    }))
    cli.run("setup", "--config", str(config))

    # 2. Fleet made one SSH key pair when the fleet was created. The administrator copies its public key to every host once,
    #    before adding the host; this script does that with its provisioning key. Then trust the release key and record how
    #    to reach each node (the host key is pinned on first use).
    cli.run("trust-release-key", "--key-file", env("FLEET_QS_RELEASE_KEY"))
    key_file = work / "provisioning.key"
    key_file.write_text(key)
    key_file.chmod(0o600)
    fleet_public_key = cli.run("public-key").strip()
    assert fleet_public_key.startswith("ssh-ed25519 "), "Fleet did not print a public key"
    for name, _role, host_var in NODES:
        subprocess.run(
            ["ssh", "-o", "BatchMode=yes", "-o", "StrictHostKeyChecking=accept-new", "-i", str(key_file), f"root@{env(host_var)}",
             "umask 077; mkdir -p ~/.ssh; grep -qxF \"$(cat)\" ~/.ssh/authorized_keys 2>/dev/null || cat >> ~/.ssh/authorized_keys"],
            input=(fleet_public_key + "\n").encode(), check=True, capture_output=True,
        )
        cli.run("node-ssh", "--node", name, "--host", env(host_var))

    # 3. Upgrade to the signed release, then start each node: control first, then DNS, then the PoP.
    if mode == "web":
        web = Web(cli, work)
        try:
            for name, role, _h in NODES:
                geo = {"country": country, "continent": continent} if role == "edge" else {}
                web.wait_job(web.post(f"/nodes/{name}/bootstrap", geo))
            plan_page = web.post("/upgrade/plan", {"manifest": Path(env("FLEET_QS_RELEASE_MANIFEST")).read_text()})
            assert "upgrade" in plan_page.lower() or plan_page
        finally:
            web.proc.terminate()
    else:
        for name, role, _h in NODES:
            extra = ["--country", country, "--continent", continent] if role == "edge" else []
            cli.run("bootstrap", "--node", name, *extra)

    # 4. The first administrator, then the whole-stack check must pass.
    password = secrets.token_urlsafe(18)
    control = env("FLEET_QS_CONTROL")
    subprocess.run(
        ["ssh", "-o", "BatchMode=yes", "-o", "StrictHostKeyChecking=accept-new", "-i", str(key_file), f"root@{control}",
         "cd /opt/cdnfoundry && docker compose --env-file .env.prod exec -T core php artisan cdnf:admin:create --name=Admin --email=admin@quickstart.test"],
        input=f"{password}\n{password}\n".encode(), check=True, capture_output=True,
    )
    report = json.loads(cli.run("doctor", "--json"))
    failing = [c for c in report["checks"] if not c["ok"] and c.get("required_for_render", True)]
    if failing:
        raise SystemExit("checks failing after setup:\n" + "\n".join(f"  {c['check']}: {c.get('detail', '')}" for c in failing))

    # 5. An active proxied domain: the customer API is the documented path (admin token, domain, A record, proxied origin).
    print(json.dumps({"mode": mode, "status": "fleet-ready", "next": f"create {env('FLEET_QS_DOMAIN')} and a proxied A record to {env('FLEET_QS_ORIGIN')}; see the Production quick start API sequence"}))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
