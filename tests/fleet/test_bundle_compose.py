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
    state = store.init({"operator_domain": "ops.example.test", "platform_domain": "cdn.example.test", "release": "v2.0.0", "acme_email": "acme@example.test"})
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


def test_every_service_a_start_script_runs_exists_in_its_own_bundle(tmp_path: Path) -> None:
    import yaml

    store = FleetState(tmp_path / "state")
    state = store.init({"operator_domain": "ops.example.test", "platform_domain": "cdn.example.test", "release": "v2.0.0", "acme_email": "acme@example.test"})
    for name, role, ip in [("control-1", "control", "93.184.216.10"), ("dns-1", "dns", "93.184.216.11"), ("pop-1", "dns-edge", "93.184.216.12"),
                           ("pop-2", "edge", "93.184.216.13")]:
        state = store.add_node(state, {"name": name, "role": role, "public_ipv4": ip, "extra_env": images()})
    store.configure_feature(state, "monitoring", {"mode": "colocated"})
    Renderer(ROOT, store, tmp_path / "bundles").render(store.load())
    for bundle in sorted((tmp_path / "bundles").iterdir()):
        if not bundle.is_dir() or bundle.suffix == ".previous":
            continue
        services = set(yaml.safe_load((bundle / "compose.yml").read_text())["services"])
        script = (bundle / "start.sh").read_text()
        named = set(re.findall(r"(?:run --rm|exec -T|up -d(?: --wait)?(?: --wait-timeout \d+)?) ((?:[a-z][a-z0-9-]+ ?)+)", script))
        used = {w for group in named for w in group.split() if not w.startswith("-")}
        used |= set(re.findall(r"run --rm (?:--no-deps )?([a-z][a-z0-9-]+)", script))
        missing = sorted(used - services - {"sh", "psql"})
        assert not missing, f"{bundle.name}: start.sh uses services its compose file lacks: {missing}"


def test_the_origin_probe_worker_runs_only_on_edge_hosts_and_only_while_the_feature_is_enabled(tmp_path: Path) -> None:
    import yaml

    nodes = [("control-1", "control", "93.184.216.10"), ("dns-1", "dns", "93.184.216.11"), ("pop-1", "dns-edge", "93.184.216.12"), ("pop-2", "edge", "93.184.216.13")]
    store = FleetState(tmp_path / "state")
    state = store.init({"operator_domain": "ops.example.test", "platform_domain": "cdn.example.test", "release": "v2.0.0", "acme_email": "acme@example.test"})
    for name, role, ip in nodes:
        state = store.add_node(state, {"name": name, "role": role, "public_ipv4": ip, "extra_env": images()})
    store.configure_feature(state, "monitoring", {"mode": "colocated"})
    out = tmp_path / "bundles"
    renderer = Renderer(ROOT, store, out)

    def render() -> None:
        renderer.render(store.load())

    def services(name: str) -> dict:
        return yaml.safe_load((out / name / "compose.yml").read_text())["services"]

    render()
    for name, _, _ in nodes:
        assert "origin-probe" not in services(name), f"{name}: the worker is not deployed while the feature is off"
        assert "--profile origin-probe" not in (out / name / "start.sh").read_text()
    store.configure_feature(store.load(), "origin_probe", {"mode": "enabled"})
    render()
    for name, role, _ in nodes:
        start = (out / name / "start.sh").read_text()
        if role in {"edge", "dns-edge"}:
            worker = services(name)["origin-probe"]
            assert worker["read_only"] and worker["cap_drop"] == ["ALL"] and worker["mem_limit"] == "128m" and worker["pids_limit"] == 128 and worker["cpus"] == 0.25
            assert list(worker["networks"]) == ["egress"], "the worker joins the egress network alone"
            assert "ports" not in worker
            assert "--profile origin-probe" in start
            assert "EDGE_PROBE_SOCKET=/run/probe/probe.sock" in (out / name / ".env.prod").read_text()
            done = subprocess.run(["docker", "compose", "--env-file", ".env.prod", "--profile", "origin-probe", "--profile", "edge", "config", "--quiet"], cwd=out / name,
                                  capture_output=True, text=True, check=False)
            assert done.returncode == 0, f"{name}: {done.stderr.strip()[-400:]}"
        else:
            assert "origin-probe" not in services(name) and "origin-probe" not in start, f"{name} runs no edge agent, so it runs no worker"
    store.configure_feature(store.load(), "origin_probe", {"mode": "disabled"})
    render()
    assert "origin-probe" not in services("pop-2")
