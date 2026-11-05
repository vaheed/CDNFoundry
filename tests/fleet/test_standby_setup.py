from __future__ import annotations

import subprocess
import sys
from dataclasses import dataclass
from pathlib import Path
from typing import Any

import pytest

sys.path.insert(0, str(Path(__file__).resolve().parents[2] / "scripts"))

from cdnfoundry_fleet import control_cli  # noqa: E402
from cdnfoundry_fleet.common import ValidationError  # noqa: E402
from cdnfoundry_fleet.engine import FleetEngine, StandbySetup, ha_action  # noqa: E402
from cdnfoundry_fleet.ssh import Target, save_target  # noqa: E402
from cdnfoundry_fleet.state import FleetState  # noqa: E402

HOST = "control.ops.example.test"


@dataclass
class R:
    code: int = 0
    stdout: str = ""
    stderr: str = ""

    @property
    def ok(self) -> bool:
        return self.code == 0


def certificate(tmp_path: Path, host: str = HOST) -> tuple[str, str]:
    key, crt = tmp_path / "k.pem", tmp_path / "c.pem"
    subprocess.run(["openssl", "req", "-x509", "-newkey", "ec", "-pkeyopt", "ec_paramgen_curve:prime256v1", "-nodes", "-days", "60",
                    "-subj", f"/CN={host}", "-addext", f"subjectAltName=DNS:{host}", "-keyout", str(key), "-out", str(crt)], check=True, capture_output=True)
    return crt.read_text(), key.read_text()


class T:
    def __init__(self, addresses: dict[str, str], cert: tuple[str, str] | None) -> None:
        self.addresses, self.cert, self.ran = addresses, cert, []

    def pin_host_key(self, target):
        return target.host

    def ensure_fleet_key(self):
        return "ssh-ed25519 AAAA cdnfoundry-fleet"

    def run(self, target: Target, command: str, **_):
        self.ran.append((target.name, command))
        if "ip -o -4 addr" in command:
            return R(0, self.addresses.get(target.name, ""))
        if "find /data/caddy/certificates" in command:
            return R(0, f"/data/caddy/certificates/acme/{HOST}/{HOST}.crt\n" if self.cert else "")
        if " cat /data/caddy/certificates" in command and self.cert:
            return R(0, self.cert[0] if command.rstrip("'").endswith(".crt") else self.cert[1])
        return R()


def ip_lines(*cidrs: str) -> str:
    return "".join(f"{i + 2}: eth{i}    inet {c} brd 0.0.0.0 scope global eth{i}\n" for i, c in enumerate(cidrs))


@pytest.fixture()
def setup(tmp_path: Path, monkeypatch: pytest.MonkeyPatch):
    captured: dict[str, Any] = {}

    def fake_add(self, host, **kw):
        captured["host"], captured["kw"] = host, kw
        captured["cert"] = Path(kw["certificate"]).read_text()
        return {"standby": kw["name"]}

    monkeypatch.setattr(control_cli.Control, "add_standby", fake_add)
    store = FleetState(tmp_path / "state")
    state = store.init({"operator_domain": "ops.example.test", "platform_domain": "cdn.example.test", "release": "v2.0.0"})
    store.add_node(state, {"name": "control-1", "role": "control", "public_ipv4": "93.184.216.10"})
    save_target(tmp_path / "state", Target("control-1", "93.184.216.10"))
    cert = certificate(tmp_path)
    transport = T({"control-1": ip_lines("93.184.216.10/24", "10.0.0.1/24"), "control-2": ip_lines("93.184.216.20/24", "10.0.0.2/24")}, cert)
    engine = FleetEngine(tmp_path / "state", tmp_path, tmp_path / "out", transport=transport, say=lambda _m: None)
    return engine, transport, captured, cert


def test_a_host_address_is_enough_fleet_names_the_node_and_reads_everything_else(setup) -> None:
    engine, transport, captured, cert = setup
    ha_action(engine, "add-standby", host="93.184.216.20")
    kw = captured["kw"]
    assert kw["name"] == "control-2" and kw["public_ipv4"] == "93.184.216.20"
    assert kw["replication_address"] == "10.0.0.2" and kw["active_replication_address"] == "10.0.0.1"
    assert captured["cert"] == cert[0]
    assert any("PATH" in c or "apt-get" in c for n, c in transport.ran if n == "control-2")
    assert not (engine.work / "ha-incoming" / "ingress.key").exists()


def test_the_node_gets_ssh_access_recorded_and_the_next_free_name(setup) -> None:
    engine, _, captured, _ = setup
    ha_action(engine, "add-standby", host="93.184.216.20", ssh_port=2222)
    assert engine.targets(engine.state())["control-1"].port == 22
    from cdnfoundry_fleet.ssh import load_targets

    assert load_targets(engine.work)["control-2"].port == 2222


def test_two_private_addresses_or_none_are_refused_instead_of_guessed(setup) -> None:
    engine, transport, _, _ = setup
    transport.addresses["control-2"] = ip_lines("10.0.0.2/24", "192.168.5.2/24")
    with pytest.raises(ValidationError, match="several private addresses: 10.0.0.2, 192.168.5.2"):
        ha_action(engine, "add-standby", host="93.184.216.20")
    transport.addresses["control-2"] = ip_lines("93.184.216.20/24")
    with pytest.raises(ValidationError, match="no private address"):
        ha_action(engine, "add-standby", host="93.184.216.20")


def test_explicit_addresses_and_certificate_override_what_fleet_would_read(setup, tmp_path: Path) -> None:
    engine, transport, captured, _ = setup
    transport.addresses["control-2"] = ""
    other = certificate(tmp_path / ".." / "o" if False else tmp_path)
    crt, key = tmp_path / "mine.crt", tmp_path / "mine.key"
    crt.write_text(other[0])
    key.write_text(other[1])
    ha_action(engine, "add-standby", host="93.184.216.20", replication_address="10.9.9.9", active_replication_address="10.9.9.8",
              ingress_certificate=str(crt), ingress_key=str(key), name="control-b")
    assert captured["kw"]["name"] == "control-b" and captured["kw"]["replication_address"] == "10.9.9.9"
    assert captured["cert"] == other[0]
    assert not any("find /data/caddy" in c for _, c in transport.ran)


def test_a_missing_certificate_on_the_active_node_says_what_to_do(setup) -> None:
    engine, transport, _, _ = setup
    transport.cert = None
    with pytest.raises(ValidationError, match="holds no certificate for control.ops.example.test yet"):
        ha_action(engine, "add-standby", host="93.184.216.20")


def test_refresh_needs_a_standby_and_next_name_skips_taken_names(setup) -> None:
    engine, _, _, _ = setup
    with pytest.raises(ValidationError, match="no standby"):
        ha_action(engine, "refresh-ingress")
    assert StandbySetup(engine).next_name({"nodes": {"control-1": {}, "control-2": {}}}) == "control-3"
