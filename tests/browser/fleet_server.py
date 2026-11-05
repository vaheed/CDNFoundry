#!/usr/bin/env python3
"""Serves the local fleet web for the browser suite: a disposable vault with seeded nodes, on 127.0.0.1 only.

Nothing here touches a real fleet. Every node points at a closed local port, so a check fails fast and realistically.
"""
from __future__ import annotations

import os
import secrets
import sys
import tempfile
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT / "scripts"))

from cdnfoundry_fleet.cli import main  # noqa: E402
from cdnfoundry_fleet.ssh import Target, save_target  # noqa: E402
from cdnfoundry_fleet.state import FleetState  # noqa: E402
from cdnfoundry_fleet.vault import Vault  # noqa: E402
from cdnfoundry_fleet.web import FleetWeb, make_server  # noqa: E402

PORT = int(os.environ.get("CDNF_FLEET_BROWSER_PORT", "18765"))
NODES = [
    ("control-1", "control", "203.0.113.10", None),
    ("dns-1", "dns", "203.0.113.20", "2001:db8::20"),
    ("dns-2", "dns", "203.0.113.21", None),
    ("pop-tehran-a", "edge", "203.0.113.30", None),
    ("pop-frankfurt-with-a-very-long-point-of-presence-name", "edge", "203.0.113.31", "2001:db8::31"),
    ("monitoring-1", "monitoring", "203.0.113.40", None),
]


def main_() -> None:
    passphrase = secrets.token_urlsafe(18)
    os.environ["CDNF_FLEET_PASSPHRASE"] = passphrase
    work = Path(tempfile.mkdtemp(prefix="cdnf-fleet-browser-"))
    vault_path = work / "fleet.db"
    main(["init", "--vault", str(vault_path), "--non-interactive", "--operator-domain", "ops.example.test",
          "--platform-domain", "cdn.example.test", "--release", "v2.0.0"])
    vault = Vault(vault_path, passphrase)
    with vault.session() as session:
        store = FleetState(session)
        state = store.load()
        for name, role, ipv4, ipv6 in NODES:
            state = store.add_node(state, {"name": name, "role": role, "public_ipv4": ipv4, "public_ipv6": ipv6})
            save_target(session, Target(name, "127.0.0.1", 1, "root"))
    vault.record("setup", '{"operator": "fixture", "title": "Seed the browser fixture"}')
    server = make_server(FleetWeb(vault, ROOT, work / "bundles"), "127.0.0.1", PORT)
    print(f"fleet web fixture on http://127.0.0.1:{PORT}/", flush=True)
    server.serve_forever()


if __name__ == "__main__":
    main_()
