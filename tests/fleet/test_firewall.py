"""The generated firewall admits internal listeners only from the fleet's own addresses."""
from __future__ import annotations

import shutil
import subprocess
import sys
from pathlib import Path

import pytest

sys.path.insert(0, str(Path(__file__).resolve().parents[2] / "scripts"))

from cdnfoundry_fleet.firewall import fleet_addresses, render_firewall_script  # noqa: E402
from cdnfoundry_fleet.render import Renderer  # noqa: E402
from cdnfoundry_fleet.state import FleetState  # noqa: E402

ROOT = Path(__file__).resolve().parents[2]
NODES = [("control-1", "control", "93.184.216.10"), ("pop-1", "dns-edge", "93.184.216.12"), ("pop-2", "edge", "93.184.216.13")]


def build(tmp_path: Path) -> tuple[FleetState, dict]:
    store = FleetState(tmp_path / "state")
    state = store.init({"operator_domain": "ops.example.test", "platform_domain": "cdn.example.test", "release": "v2.0.0", "acme_email": "acme@example.test"})
    for name, role, ip in NODES:
        state = store.add_node(state, {"name": name, "role": role, "public_ipv4": ip, "monitor_ipv4": "10.20.0." + ip.rsplit(".", 1)[1]})
    return store, store.load()


def test_sources_are_fleet_addresses_without_wildcards(tmp_path: Path) -> None:
    _, state = build(tmp_path)
    v4, v6 = fleet_addresses(state)
    assert "93.184.216.10" in v4 and "10.20.0.10" in v4
    assert "0.0.0.0" not in v4 and v6 == []


def test_script_denies_every_internal_port_and_leaves_public_ones(tmp_path: Path) -> None:
    _, state = build(tmp_path)
    script = render_firewall_script(state, state["nodes"]["pop-1"])
    for port in ("9100/tcp", "8083/tcp", "9598/tcp", "9599/tcp", "5300/tcp", "5300/udp", "9105/tcp"):
        assert port in script
    for public in ("53/", "80/", "443/", "8443/"):
        assert f'"{public}' not in script and f" {public}" not in script.split("FORWARDED_PORTS=")[1].split("\n")[0]
    assert "DOCKER-USER" in script and "cdnfoundry-firewall.service" in script


def test_every_bundle_applies_the_firewall_before_services_start(tmp_path: Path) -> None:
    store, state = build(tmp_path)
    Renderer(ROOT, store, tmp_path / "bundles").render(state)
    for name, _, _ in NODES:
        bundle = tmp_path / "bundles" / name
        assert (bundle / "firewall.sh").stat().st_mode & 0o100
        start = (bundle / "start.sh").read_text()
        assert start.index("./firewall.sh") < start.index("docker compose")


@pytest.mark.skipif(shutil.which("docker") is None, reason="docker is required")
def test_rules_load_in_a_real_network_namespace(tmp_path: Path) -> None:
    _, state = build(tmp_path)
    script = tmp_path / "firewall.sh"
    script.write_text(render_firewall_script(state, state["nodes"]["control-1"]))
    code = (
        "apk add --no-cache iptables >/dev/null && iptables -N DOCKER-USER && sh /fw/firewall.sh --no-boot-unit && "
        "iptables -S CDNF-INTERNAL-FORWARD && sh /fw/firewall.sh --no-boot-unit && iptables -S DOCKER-USER && iptables -S INPUT"
    )
    done = subprocess.run(["docker", "run", "--rm", "--cap-add", "NET_ADMIN", "-v", f"{tmp_path}:/fw:ro", "alpine:3.20", "sh", "-ec", code],
                          capture_output=True, text=True, check=False, timeout=240)
    out = done.stdout
    assert done.returncode == 0, done.stderr[-600:] + out[-600:]
    assert "--ctorigdstport 9100" in out and "-A CDNF-INTERNAL-FORWARD -p tcp -m conntrack --ctorigdstport 9100 --ctdir ORIGINAL -j DROP" in out
    assert out.count("-j CDNF-INTERNAL-FORWARD") == 1, "a second run must not duplicate the jump"
    assert out.count("-j CDNF-INTERNAL-INPUT") == 1
