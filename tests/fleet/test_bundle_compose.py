"""Every role's rendered bundle must be accepted by Docker Compose itself, not just by Fleet's own checks."""
from __future__ import annotations

import re
import shutil
import subprocess
import sys
from pathlib import Path

import pytest

sys.path.insert(0, str(Path(__file__).resolve().parents[2] / "scripts"))

from cdnfoundry_fleet.render import Renderer  # noqa: E402
from cdnfoundry_fleet.state import FleetState  # noqa: E402

ROOT = Path(__file__).resolve().parents[2]
D = "sha256:" + "a" * 64
pytestmark = pytest.mark.skipif(shutil.which("docker") is None, reason="docker is required")


def images() -> dict[str, str]:
    names = set(re.findall(r"\$\{(CDNF_[A-Z_]+_IMAGE)", (ROOT / "compose.prod.yml").read_text()))
    return {n: f"registry.example/{n.lower()}@{D}" for n in names}


def test_every_role_bundle_passes_docker_compose_config(tmp_path: Path) -> None:
    store = FleetState(tmp_path / "state")
    state = store.init({"operator_domain": "ops.example.test", "platform_domain": "cdn.example.test", "release": "v2.0.0", "acme_email": "acme@example.test",
                        "alert_webhook_url": "https://alerts.example.test/hook"})
    nodes = [("control-1", "control", "93.184.216.10"), ("dns-1", "dns", "93.184.216.11"), ("pop-1", "dns-edge", "93.184.216.12"),
             ("pop-2", "edge", "93.184.216.13")]
    for name, role, ip in nodes:
        state = store.add_node(state, {"name": name, "role": role, "public_ipv4": ip, "extra_env": images()})
    store.configure_feature(state, "monitoring", {"mode": "colocated"})
    state = store.load()
    out = tmp_path / "bundles"
    Renderer(ROOT, store, out).render(state)
    for name, role, _ in nodes:
        done = subprocess.run(["docker", "compose", "--env-file", ".env.prod", "--profile", "*", "config", "--quiet"], cwd=out / name,
                              capture_output=True, text=True, check=False)
        assert done.returncode == 0, f"{name} ({role}): {done.stderr.strip()[-400:]}"
