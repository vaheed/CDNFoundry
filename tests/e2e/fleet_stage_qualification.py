#!/usr/bin/env python3
"""Qualification of the Fleet engine against real staging servers (never run against production).

  validators   upload a deliberately broken config for every validated service to a real node and require the node's own
               validator to reject it, leaving the running bundle untouched
  probe        summarise a probe log (one line per second from a host inside the fleet) and fail on any second in which no
               PoP answered DNS and accepted HTTP and HTTPS, over IPv4 and IPv6

Environment: FLEET_VAULT, FLEET_PASSPHRASE_FILE, FLEET_BUNDLES (the directory `fleet render` wrote).
"""
from __future__ import annotations

import os
import shutil
import sys
import tempfile
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT / "scripts"))

from cdnfoundry_fleet import actions  # noqa: E402
from cdnfoundry_fleet.engine import FleetEngine  # noqa: E402
from cdnfoundry_fleet.validators import validate_node_config  # noqa: E402
from cdnfoundry_fleet.vault import Vault  # noqa: E402

# (node role, file inside the bundle to break, text that makes the service's own validator refuse it, service named in the refusal)
BREAKS = [
    ("dns-edge", "docker/dnsdist/dnsdist.conf", "\nthis is not lua ((\n", "dnsdist"),
    ("dns-edge", "docker/vector/vector.yaml", "\nsources: [unclosed\n", "vector"),
    ("control", "docker/nginx/edge-control.conf", "\nthis_is_not_an_nginx_directive on;\n", "edge-control"),
    ("control", "docker/pdns/primary.conf", "\nno-such-setting=1\n", "powerdns"),
    ("control", "deploy/production/Caddyfile", "\n{ this is not a caddyfile\n", "caddy"),
    ("control", "docker/prometheus/prometheus.yml", "\nscrape_configs: [unclosed\n", "prometheus"),
    ("control", "docker/alertmanager/alertmanager.yml", "\nroute: [unclosed\n", "alertmanager"),
    ("control", "docker/vector/vector.yaml", "\nsources: [unclosed\n", "vector"),
]


def validators() -> int:
    vault = Vault(Path(os.environ["FLEET_VAULT"]), Path(os.environ["FLEET_PASSPHRASE_FILE"]).read_text().strip())
    bundles = Path(os.environ["FLEET_BUNDLES"])
    failures = 0
    with vault.session() as work:
        engine = FleetEngine(work, ROOT, bundles)
        state = engine.state()
        targets = engine.targets(state)
        for role, relative, junk, service in BREAKS:
            node = next(n for n, v in sorted(state["nodes"].items()) if v["role"] == role)
            source = bundles / node
            if not (source / relative).is_file():
                print(f"SKIP {node}: {relative} is not in its bundle")
                continue
            with tempfile.TemporaryDirectory() as tmp:
                copy = Path(tmp) / "bundle"
                shutil.copytree(source, copy)
                with (copy / relative).open("a", encoding="utf-8") as handle:
                    handle.write(junk)
                engine.transport.upload_tree(targets[node], copy, actions.REMOTE_ROOT + ".next", keep_previous=False)
            services = {s for s in __import__("yaml").safe_load((source / "compose.yml").read_text())["services"]}
            ok, detail = validate_node_config(engine.transport, targets[node], state["nodes"][node]["role"], actions.REMOTE_ROOT + ".next", services)
            verdict = (not ok) and service in detail
            failures += 0 if verdict else 1
            print(f"{'PASS' if verdict else 'FAIL'} {node}: broken {relative} -> {'rejected by ' + service if verdict else 'NOT rejected: ' + detail[:120]}")
            engine.transport.run(targets[node], f"rm -rf {actions.REMOTE_ROOT}.next")
        # The running bundle must be exactly as it was.
        for node, target in targets.items():
            r = engine.transport.run(target, f"test -e {actions.REMOTE_ROOT}/.cdnf-good && ! test -e {actions.REMOTE_ROOT}.next")
            print(f"{'PASS' if r.ok else 'FAIL'} {node}: running bundle untouched, nothing staged")
            failures += 0 if r.ok else 1
    return 1 if failures else 0


def probe(path: str) -> int:
    seconds = bad = 0
    worst = {}
    for line in Path(path).read_text().splitlines():
        parts = line.split()
        if len(parts) < 15:
            continue
        seconds += 1
        pops = {"p1": parts[2:8], "p2": parts[9:15]}
        up = {name: all(f.endswith("=1") for f in fields) for name, fields in pops.items()}
        for name, fields in pops.items():
            for f in fields:
                if f.endswith("=0"):
                    worst[(name, f[:-2])] = worst.get((name, f[:-2]), 0) + 1
        if not any(up.values()):
            bad += 1
            print("no PoP fully served at", parts[0])
    print(f"{seconds} seconds observed; {bad} with no PoP serving everything; per-PoP failed probes: {dict(sorted(worst.items()))}")
    return 1 if bad or seconds == 0 else 0


if __name__ == "__main__":
    command = sys.argv[1] if len(sys.argv) > 1 else ""
    raise SystemExit(validators() if command == "validators" else probe(sys.argv[2]) if command == "probe" and len(sys.argv) > 2 else print(__doc__) or 2)
