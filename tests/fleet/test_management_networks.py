"""The management-network allowlist is validated, stored, and rendered into the control bundle."""
from __future__ import annotations

import sys
from pathlib import Path

import pytest

sys.path.insert(0, str(Path(__file__).resolve().parents[2] / "scripts"))

from cdnfoundry_fleet.common import ValidationError  # noqa: E402
from cdnfoundry_fleet.render import Renderer  # noqa: E402
from cdnfoundry_fleet.state import FleetState, validate_management_networks  # noqa: E402

ROOT = Path(__file__).resolve().parents[2]
BASE = {"operator_domain": "ops.example.test", "platform_domain": "cdn.example.test", "release": "v2.0.0", "acme_email": "acme@example.test"}


def test_validation_normalizes_and_rejects() -> None:
    assert validate_management_networks("203.0.113.7, 2001:db8::/32 203.0.113.7/32") == ["203.0.113.7/32", "2001:db8::/32"]
    assert validate_management_networks("") == [] and validate_management_networks(None) == []
    for bad in ("not-a-network", "0.0.0.0/0", "::/0", "203.0.113.0/33"):
        with pytest.raises(ValidationError):
            validate_management_networks(bad)
    with pytest.raises(ValidationError):
        validate_management_networks([f"10.{i // 256}.{i % 256}.0/24" for i in range(65)])


def test_control_bundle_carries_operator_networks_and_fleet_addresses(tmp_path: Path) -> None:
    store = FleetState(tmp_path / "state")
    state = store.init({**BASE, "management_networks": ["198.51.100.0/24"]})
    state = store.add_node(state, {"name": "control-1", "role": "control", "public_ipv4": "93.184.216.10"})
    Renderer(ROOT, store, tmp_path / "bundles").render(store.load())
    lists = tmp_path / "bundles/control-1/generated/allowlists"
    assert "MANAGEMENT_NETWORKS" not in (tmp_path / "bundles/control-1/.env.prod").read_text(), "a list in an environment value would recreate containers"
    gateway, plain = (lists / "management-networks.caddy").read_text(), (lists / "management-networks.txt").read_text()
    assert gateway.startswith("remote_ip ") and "198.51.100.0/24" in gateway and "93.184.216.10/32" in gateway
    assert "198.51.100.0/24" in plain and "93.184.216.10/32" in plain


def test_unrestricted_fleet_renders_an_empty_value(tmp_path: Path) -> None:
    store = FleetState(tmp_path / "state")
    state = store.init(BASE)
    store.add_node(state, {"name": "control-1", "role": "control", "public_ipv4": "93.184.216.10"})
    Renderer(ROOT, store, tmp_path / "bundles").render(store.load())
    lists = tmp_path / "bundles/control-1/generated/allowlists"
    assert (lists / "management-networks.caddy").read_text() == "remote_ip 0.0.0.0/0 ::/0\n"  # the gateway matches every address
    assert (lists / "management-networks.txt").read_text().strip() == ""  # the control plane reads an empty list as no restriction
