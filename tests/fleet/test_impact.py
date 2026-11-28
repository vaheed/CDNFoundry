"""The impact map: for each kind of change, the files and services that may change, and nothing else (no host needed)."""
from __future__ import annotations

import re
import shutil
import sys
from pathlib import Path

import pytest

sys.path.insert(0, str(Path(__file__).resolve().parents[2] / "scripts"))

from cdnfoundry_fleet import impact  # noqa: E402
from cdnfoundry_fleet.render import Renderer  # noqa: E402
from cdnfoundry_fleet.state import FleetState  # noqa: E402

ROOT = Path(__file__).resolve().parents[2]
D = "sha256:" + "a" * 64
NODES = [("control-1", "control", "93.184.216.10"), ("dns-1", "dns", "93.184.216.11"), ("pop-1", "dns-edge", "93.184.216.12"), ("pop-2", "edge", "93.184.216.13")]
needs_docker = pytest.mark.skipif(shutil.which("docker") is None, reason="docker is required for Compose configuration hashes")


def images() -> dict[str, str]:
    return {n: f"registry.example/{n.lower()}@{D}" for n in set(re.findall(r"\$\{(CDNF_[A-Z_]+_IMAGE)", (ROOT / "compose.prod.yml").read_text()))}


class Fleet:
    def __init__(self, tmp: Path) -> None:
        self.store = FleetState(tmp / "state")
        self.out = tmp / "bundles"
        state = self.store.init({"operator_domain": "ops.example.test", "platform_domain": "cdn.example.test", "release": "v2.0.0", "acme_email": "acme@example.test"})
        for name, role, ip in NODES:
            state = self.store.add_node(state, {"name": name, "role": role, "public_ipv4": ip, "extra_env": images()})
        self.store.configure_feature(state, "monitoring", {"mode": "colocated"})
        self.render()

    def render(self) -> dict[str, dict]:
        Renderer(ROOT, self.store, self.out).render(self.store.load())
        return {name: impact.profile(self.out / name) for name, _, _ in NODES}


@pytest.fixture(scope="module")
def baseline(tmp_path_factory: pytest.TempPathFactory) -> tuple[Fleet, dict[str, dict]]:
    fleet = Fleet(tmp_path_factory.mktemp("impact"))
    return fleet, fleet.render()


def plan(before: dict[str, dict], after: dict[str, dict]) -> dict[str, list[impact.Change]]:
    return {n: impact.plan_node(n, before[n], after[n]) for n in before}


@needs_docker
def test_an_identical_render_has_an_empty_plan(baseline) -> None:
    fleet, before = baseline
    assert all(rows == [] for rows in plan(before, fleet.render()).values())


@needs_docker
def test_a_host_firewall_address_change_touches_the_firewall_and_no_container(baseline) -> None:
    fleet, before = baseline
    state = fleet.store.load()
    state = fleet.store.update_node(state, "pop-2", {"public_ipv4": "93.184.216.99"})
    rows = plan(before, fleet.render())
    for name in ("control-1", "dns-1", "pop-1"):
        assert any(r.action == "firewall" for r in rows[name]), name
    assert not any(r.action == "recreate" and r.service == "node" for r in sum(rows.values(), []))
    assert fleet.store.update_node(fleet.store.load(), "pop-2", {"public_ipv4": "93.184.216.13"})  # restore for the other tests
    fleet.render()


@needs_docker
def test_a_prometheus_target_file_is_a_reload_of_prometheus_only(baseline) -> None:
    fleet, before = baseline
    target = fleet.out / "control-1" / "generated" / "prometheus-node-targets.yml"
    original = target.read_bytes()
    try:
        target.write_text(original.decode() + "# edited\n")
        rows = impact.plan_node("control-1", before["control-1"], impact.profile(fleet.out / "control-1"))
    finally:
        target.write_bytes(original)
    assert [(r.service, r.action) for r in rows] == [("prometheus", "reload")]


@needs_docker
def test_a_dashboard_edit_leaves_the_databases_and_the_application_alone(baseline) -> None:
    fleet, before = baseline
    dashboard = next((fleet.out / "control-1" / "docker" / "grafana" / "library-panels").glob("*.json"))
    original = dashboard.read_bytes()
    try:
        dashboard.write_bytes(original + b"\n")
        rows = impact.plan_node("control-1", before["control-1"], impact.profile(fleet.out / "control-1"))
    finally:
        dashboard.write_bytes(original)
    assert [(r.service, r.action) for r in rows] == [("grafana-bootstrap", "restart")]


@needs_docker
def test_a_prometheus_rule_edit_is_a_reload(baseline) -> None:
    fleet, before = baseline
    rules = fleet.out / "control-1" / "docker" / "prometheus" / "slo-rules.yml"
    original = rules.read_bytes()
    try:
        rules.write_bytes(original + b"\n")
        rows = impact.plan_node("control-1", before["control-1"], impact.profile(fleet.out / "control-1"))
    finally:
        rules.write_bytes(original)
    assert [(r.service, r.action) for r in rows] == [("prometheus", "reload")]


@needs_docker
def test_a_provisioned_dashboard_edit_is_a_file_update_for_grafana_only(baseline) -> None:
    fleet, before = baseline
    dashboard = next((fleet.out / "control-1" / "docker" / "grafana" / "dashboards").rglob("*.json"))
    original = dashboard.read_bytes()
    try:
        dashboard.write_bytes(original + b"\n")
        rows = impact.plan_node("control-1", before["control-1"], impact.profile(fleet.out / "control-1"))
    finally:
        dashboard.write_bytes(original)
    assert [(r.service, r.action) for r in rows] == [("grafana", "file")]


@needs_docker
def test_an_input_the_map_does_not_know_is_node_wide(baseline) -> None:
    fleet, before = baseline
    stray = fleet.out / "dns-1" / "unmapped-input.conf"
    try:
        stray.write_text("x")
        rows = impact.plan_node("dns-1", before["dns-1"], impact.profile(fleet.out / "dns-1"))
    finally:
        stray.unlink()
    assert impact.widest(rows) == "node"


@needs_docker
def test_a_changed_environment_value_names_the_services_and_never_prints_the_value(baseline) -> None:
    fleet, before = baseline
    env = fleet.out / "control-1" / ".env.prod"
    original = env.read_text()
    key = original.splitlines()[0].partition("=")[0]
    try:
        env.write_text(original.replace(f"{key}=", f"{key}=changed-secret-value-", 1))
        after = impact.profile(fleet.out / "control-1")
        rows = impact.plan_node("control-1", before["control-1"], after)
    finally:
        env.write_text(original)
    assert all("changed-secret-value" not in str(r.row()) for r in rows)
    assert all(r.action in {"recreate", "none"} for r in rows)


def test_without_a_baseline_the_plan_is_node_wide(tmp_path: Path) -> None:
    (tmp_path / "compose.yml").write_text("services: {}\n")
    rows = impact.plan_node("h", None, impact.profile(tmp_path))
    assert impact.widest(rows) == "node"


def test_without_compose_hashes_a_compose_change_is_node_wide() -> None:
    old = {"files": {"compose.yml": "a"}, "env": {}, "services": None}
    new = {"files": {"compose.yml": "b"}, "env": {}, "services": None}
    assert impact.widest(impact.plan_node("h", old, new)) == "node"


def test_documentation_and_scripts_alone_touch_nothing() -> None:
    old = {"files": {"README.md": "a", "start.sh": "a"}, "env": {}, "services": {}}
    new = {"files": {"README.md": "b", "start.sh": "b"}, "env": {}, "services": {}}
    assert impact.plan_node("h", old, new) == []


def test_env_digests_keep_no_value() -> None:
    digests = impact.env_digests("A=secret\n# c\nB=other\n")
    assert set(digests) == {"A", "B"} and "secret" not in str(digests)


def _change_management_networks(fleet: Fleet, before: dict[str, dict]) -> list[impact.Change]:
    state = fleet.store.load()
    original = list(state["global"].get("management_networks", []))
    state["global"]["management_networks"] = [*original, "198.51.100.0/24"]
    fleet.store._write(state)
    try:
        return impact.plan_node("control-1", before["control-1"], fleet.render()["control-1"])
    finally:
        state["global"]["management_networks"] = original
        fleet.store._write(state)
        fleet.render()


@needs_docker
def test_a_management_network_change_recreates_no_container_and_reloads_only_the_gateway(baseline) -> None:
    """The address lists are files that the gateways import and the control plane reads per request (Stage E root cause 2)."""
    fleet, before = baseline
    rows = _change_management_networks(fleet, before)
    assert not [r for r in rows if r.action in {"recreate", "restart", "node"}], [r.line() for r in rows]
    assert {(r.service, r.action) for r in rows} == {("caddy", "reload")}  # the telemetry gateway runs on a monitoring host, not beside the control services
    assert all("198.51.100" not in str(r.row()) for r in rows)


@needs_docker
def test_an_edge_public_address_change_reloads_the_gateway_and_recreates_nothing_on_control(baseline) -> None:
    fleet, before = baseline
    state = fleet.store.update_node(fleet.store.load(), "pop-2", {"public_ipv4": "93.184.216.98"})
    try:
        rows = impact.plan_node("control-1", before["control-1"], fleet.render()["control-1"])
    finally:
        fleet.store.update_node(fleet.store.load(), "pop-2", {"public_ipv4": "93.184.216.13"})
        fleet.render()
    assert not [r for r in rows if r.action in {"recreate", "node"}], [r.line() for r in rows]
    assert {"caddy", "prometheus"} <= {r.service for r in rows}


def test_every_reload_the_map_names_has_a_command() -> None:
    from cdnfoundry_fleet import actions

    for rule in impact.RULES:
        if rule[3] == "reload":
            for service in rule[2]:
                assert service in actions.RELOAD_COMMANDS, service
