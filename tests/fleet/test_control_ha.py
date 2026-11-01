"""The two-node control plane in the fleet generator: what is rendered, what the commands run, and in what order."""
from __future__ import annotations

import json
import shutil
import subprocess
from pathlib import Path
from typing import Any

import pytest
import yaml

from test_fleet import REPO_PATCH, add, env_values, node, source_repo, store  # noqa: F401  (fixtures)

from cdnfoundry_fleet import control_cli, ha
from cdnfoundry_fleet.common import FleetError, ValidationError
from cdnfoundry_fleet.control_cli import Control, Runner, bundle_digest
from cdnfoundry_fleet.render import Renderer
from cdnfoundry_fleet.state import FleetState

ACTIVE_FILES = {
    "edge_identity_ca_certificate": "aaaa", "edge_identity_ca_private_key": "bbbb", "edge_server_ca_certificate": "cccc",
    "edge_proxy_secret": "dddd", "valkey_password": "eeee", "replication_password": "ffff", "ingress_certificate": "1111",
}


class FakeRunner(Runner):
    """Records every call and answers like the node scripts would."""

    def __init__(self, *, mismatched_standby: bool = False) -> None:
        super().__init__(("fake",))
        self.calls: list[tuple[str, str]] = []
        self.pushed: list[str] = []
        self.edges_missing = [2, 0]
        self.mismatched_standby = mismatched_standby
        self.fail_on: str | None = None
        self.standby_name = "control-2"

    def push(self, node: dict[str, Any], bundle: Path) -> None:
        assert bundle.is_dir()
        self.calls.append((node["name"], "push"))
        self.pushed.append(node["name"])

    def run(self, node: dict[str, Any], command: str, *, timeout: int = 0) -> str:
        self.calls.append((node["name"], command))
        if self.fail_on and self.fail_on in command:
            raise FleetError(f"{node['name']}: {command} failed")
        if "ha/status.sh" in command:
            standby = node["name"] == self.standby_name
            files = dict(ACTIVE_FILES)
            if standby and self.mismatched_standby:
                files["edge_identity_ca_private_key"] = "other"
            database = {"in_recovery": True, "streaming": True, "lag_seconds": 1} if standby else {"in_recovery": False, "streaming_replicas": 1}
            return json.dumps({"node": node["name"], "control_db": database, "pdns_db": database, "valkey": {"role": "slave" if standby else "master", "link": "up" if standby else "none"},
                               "files": files, "application": {"app_key": "k1", "artifact_signing_key": "k2"}})
        if "cdnf:control:status" in command:
            missing = self.edges_missing.pop(0) if len(self.edges_missing) > 1 else self.edges_missing[0]
            return json.dumps({"ha": "degraded", "edges_missing_addresses": missing, "edges": {"enabled": 2, "recent_heartbeat": 2}})
        if "ha/demote.sh" in command:
            return "control-db=0/1000,pdns-db=0/2000\n"
        if "ha/promote.sh" in command:
            return "2\n"
        return ""

    def commands(self) -> list[str]:
        return [f"{name}: {command}" for name, command in self.calls]

    def index(self, fragment: str, node: str | None = None) -> int:
        for position, (name, command) in enumerate(self.calls):
            if fragment in command and (node is None or name == node):
                return position
        raise AssertionError(f"{fragment!r} was never run: {self.commands()}")


@pytest.fixture()
def repo(source_repo: Path) -> Path:  # noqa: F811
    shutil.copytree(REPO_PATCH / "deploy/production/ha", source_repo / "deploy/production/ha")
    shutil.copy2(REPO_PATCH / "docker/prometheus/watcher.yml", source_repo / "docker/prometheus/watcher.yml")
    compose_path = source_repo / "compose.prod.yml"
    compose = yaml.safe_load(compose_path.read_text(encoding="utf-8"))
    compose["services"]["caddy"] = {"image": "caddy:2", "profiles": ["control"], "volumes": ["./deploy/production/Caddyfile:/etc/caddy/Caddyfile:ro"],
                                    "environment": {"CONTROL_PUBLIC_IPV4_ALLOWLIST": "${CONTROL_PUBLIC_IPV4_ALLOWLIST}", "CONTROL_HOSTNAME": "${CONTROL_HOSTNAME:?required}"}}
    compose_path.write_text(yaml.safe_dump(compose, sort_keys=False), encoding="utf-8")
    compose["services"]["cache"] = {"image": "valkey:9", "profiles": ["control"], "volumes": ["${VALKEY_CONFIG_FILE:?required}:/run/secrets/valkey.conf:ro"]}
    compose["services"]["prometheus"]["volumes"] = ["./docker/prometheus/prometheus.yml:/etc/prometheus/prometheus.yml:ro", "prometheus:/prometheus"]
    compose_path.write_text(yaml.safe_dump(compose, sort_keys=False), encoding="utf-8")
    (source_repo / "deploy/production/Caddyfile").write_text("# fixture\n", encoding="utf-8")
    return source_repo


@pytest.fixture()
def certificate(tmp_path: Path) -> tuple[Path, Path]:
    cert, key = tmp_path / "ingress.crt", tmp_path / "ingress.key"
    subprocess.run(["openssl", "req", "-x509", "-newkey", "ec", "-pkeyopt", "ec_paramgen_curve:prime256v1", "-nodes", "-keyout", str(key), "-out", str(cert),
                    "-days", "90", "-subj", "/CN=control.ops.example.com", "-addext", "subjectAltName=DNS:control.ops.example.com"], check=True, capture_output=True)
    return cert, key


def fleet(store: FleetState) -> None:  # noqa: F811
    add(store, node("control-1", "control", "192.0.2.10"))
    add(store, node("edge-1", "edge", "192.0.2.30"))
    add(store, node("dns-1", "dns", "192.0.2.40"))


def control(store: FleetState, repo: Path, tmp_path: Path, runner: FakeRunner, **kwargs: Any) -> Control:  # noqa: F811
    messages: list[str] = []
    probe = kwargs.pop("probe", lambda address, hostname: None)
    clock = [0.0]

    def sleep(seconds: float) -> None:
        clock[0] += seconds

    return Control(store, repo, tmp_path / "bundles", runner, say=messages.append, sleep=sleep, probe=probe, now=lambda: clock[0])


def standby_added(store: FleetState, repo: Path, tmp_path: Path, certificate: tuple[Path, Path], runner: FakeRunner | None = None) -> tuple[Control, FakeRunner]:  # noqa: F811
    fleet(store)
    with store.locked():
        Renderer(repo, store, tmp_path / "bundles").render(store.load())
    runner = runner or FakeRunner()
    instance = control(store, repo, tmp_path, runner)
    with store.locked():
        instance.add_standby("192.0.2.11", name="control-2", replication_address="10.9.0.2", active_replication_address="10.9.0.1",
                             certificate=certificate[0], key=certificate[1])
    return instance, runner


def test_a_second_control_node_is_refused_without_the_standby_workflow(store: FleetState) -> None:  # noqa: F811
    add(store, node("control-1", "control", "192.0.2.10"))
    with pytest.raises(ValidationError, match="control add-standby"):
        add(store, node("control-2", "control", "192.0.2.11"))


def test_add_standby_runs_in_order_and_reaches_ready(store: FleetState, repo: Path, tmp_path: Path, certificate: tuple[Path, Path]) -> None:  # noqa: F811
    instance, runner = standby_added(store, repo, tmp_path, certificate)

    order = [runner.index("start.sh", "control-1"), runner.index("cdnf:control:manage addresses"), runner.index("ha/prepare-source.sh"),
             runner.index("push", "control-2"), runner.index("ha/seed-standby.sh"), runner.index("ha/status.sh")]
    assert order == sorted(order), runner.commands()
    # Edges and DNS hosts are never touched: no push, no command.
    assert not {name for name, _ in runner.calls} & {"edge-1", "dns-1"}
    assert "--addresses=192.0.2.10,192.0.2.11" in runner.commands()[runner.index("cdnf:control:manage addresses")]
    state = store.load()
    assert ha.section(state)["mode"] == "two-node" and ha.role_of(state, "control-2") == "standby"
    recorded = [command for _, command in runner.calls if "--role=standby" in command and "--fingerprints=" in command]
    assert recorded and "--lag=1" in recorded[-1] and "--valkey-linked=1" in recorded[-1], recorded
    assert instance.check(state)["ha"] == "ready"


def test_add_standby_does_not_change_an_edge_or_dns_bundle(store: FleetState, repo: Path, tmp_path: Path, certificate: tuple[Path, Path]) -> None:  # noqa: F811
    fleet(store)
    output = tmp_path / "bundles"
    with store.locked():
        Renderer(repo, store, output).render(store.load())
    before = {name: bundle_digest(output / name) for name in ("edge-1", "dns-1")}
    standby_added_after = FakeRunner()
    instance = control(store, repo, tmp_path, standby_added_after)
    with store.locked():
        instance.add_standby("192.0.2.11", name="control-2", replication_address="10.9.0.2", active_replication_address="10.9.0.1",
                             certificate=certificate[0], key=certificate[1])
    assert {name: bundle_digest(output / name) for name in ("edge-1", "dns-1")} == before


def test_both_control_addresses_reach_every_allowlist_and_the_peer_configuration(store: FleetState, repo: Path, tmp_path: Path, certificate: tuple[Path, Path]) -> None:  # noqa: F811
    standby_added(store, repo, tmp_path, certificate)
    output = tmp_path / "bundles"
    active_env, standby_env = env_values(output / "control-1/.env.prod"), env_values(output / "control-2/.env.prod")

    for env in (active_env, standby_env):
        assert env["CONTROL_PUBLIC_IPV4_ALLOWLIST"].split() == ["192.0.2.10", "192.0.2.11"]
    assert (active_env["CONTROL_NODE_NAME"], active_env["CONTROL_PEER_ADDRESSES"]) == ("control-1", "192.0.2.11")
    assert (standby_env["CONTROL_NODE_NAME"], standby_env["CONTROL_PEER_ADDRESSES"]) == ("control-2", "192.0.2.10")
    assert standby_env["CONTROL_REPLICATION_BIND"] == "10.9.0.2"


def test_standby_bundle_replicates_and_starts_only_replicas(store: FleetState, repo: Path, tmp_path: Path, certificate: tuple[Path, Path]) -> None:  # noqa: F811
    standby_added(store, repo, tmp_path, certificate)
    bundle = tmp_path / "bundles/control-2"
    compose = yaml.safe_load((bundle / "compose.yml").read_text(encoding="utf-8"))

    assert "10.9.0.2:5432:5432/tcp" in compose["services"]["control-db"]["ports"]
    assert "10.9.0.2:5433:5432/tcp" in compose["services"]["pdns-db"]["ports"]
    assert "hba_file=/etc/postgresql/pg_hba.conf" in compose["services"]["control-db"]["command"]
    # Replication is outbound from internal networks, so these services also join egress.
    for service in ("control-db", "pdns-db", "redis"):
        assert "egress" in compose["services"][service]["networks"], service
    assert "egress" not in (tmp_path / "bundles/control-1/compose.yml").read_text(encoding="utf-8").split("control-db:")[1].split("redis:")[0]
    valkey = (bundle / "secrets/valkey.conf").read_text(encoding="utf-8")
    assert "replicaof 10.9.0.1 6379" in valkey and "masterauth " in valkey
    cache = (bundle / "secrets/valkey-cache.conf").read_text(encoding="utf-8")
    assert yaml.safe_load((bundle / "compose.yml").read_text(encoding="utf-8"))["services"]["cache"]["volumes"] == ["./secrets/valkey-cache.conf:/run/secrets/valkey.conf:ro"]
    assert cache.startswith("requirepass ") and "replicaof" not in cache and "masterauth" not in cache
    assert "replicaof" not in (tmp_path / "bundles/control-1/secrets/valkey.conf").read_text(encoding="utf-8")
    assert "exec ./ha/start-standby.sh" in (bundle / "start.sh").read_text(encoding="utf-8")
    assert "start-standby" not in (tmp_path / "bundles/control-1/start.sh").read_text(encoding="utf-8")
    hba = (bundle / "ha/pg_hba.conf").read_text(encoding="utf-8")
    assert "host replication replicator 10.9.0.1/32 scram-sha-256" in hba
    assert "host all all 0.0.0.0/0" not in hba
    for script in ha.HA_SCRIPTS:
        assert (bundle / "ha" / script).stat().st_mode & 0o100
    # The scripts get names and addresses, never a secret, from ha.env.
    ha_env = (bundle / "ha/ha.env").read_text(encoding="utf-8")
    password = store.read_secret("control-replication-password")
    assert password not in ha_env and "HA_PEER_ADDRESS=10.9.0.1" in ha_env
    assert oct((bundle / "ha/replication.pgpass").stat().st_mode & 0o777) == "0o600"


def test_the_standby_runs_only_a_watcher_and_the_active_keeps_telemetry(store: FleetState, repo: Path, tmp_path: Path, certificate: tuple[Path, Path]) -> None:  # noqa: F811
    fleet(store)
    with store.locked():
        store.configure_feature(store.load(), "monitoring", {"mode": "colocated", "host": None})
    standby_added(store, repo, tmp_path, certificate) if False else None
    runner = FakeRunner()
    with store.locked():
        Renderer(repo, store, tmp_path / "bundles").render(store.load())
    instance = control(store, repo, tmp_path, runner)
    certificate_pair = certificate
    with store.locked():
        instance.add_standby("192.0.2.11", name="control-2", replication_address="10.9.0.2", active_replication_address="10.9.0.1",
                             certificate=certificate_pair[0], key=certificate_pair[1])
    active = yaml.safe_load((tmp_path / "bundles/control-1/compose.yml").read_text(encoding="utf-8"))["services"]
    watcher = yaml.safe_load((tmp_path / "bundles/control-2/compose.yml").read_text(encoding="utf-8"))["services"]

    assert "clickhouse" in active and "prometheus" in active
    assert "clickhouse" not in watcher and "vector" not in watcher
    assert "prometheus" in watcher and "node-exporter" in watcher
    assert any(str(volume).endswith(":/etc/prometheus/prometheus.yml:ro") and "watcher.yml" in str(volume) for volume in watcher["prometheus"]["volumes"])
    targets = yaml.safe_load((tmp_path / "bundles/control-2/generated/prometheus-node-targets.yml").read_text(encoding="utf-8"))
    assert {"control-1", "control-2"} <= {group["labels"]["node"] for group in targets}


def test_replication_must_stay_on_a_private_network(store: FleetState, repo: Path, tmp_path: Path, certificate: tuple[Path, Path]) -> None:  # noqa: F811
    fleet(store)
    instance = control(store, repo, tmp_path, FakeRunner())
    with store.locked(), pytest.raises(ValidationError, match="private"):
        instance.add_standby("192.0.2.11", name="control-2", replication_address="8.8.8.8", active_replication_address="10.9.0.1",
                             certificate=certificate[0], key=certificate[1])
    assert "control-2" not in store.load()["nodes"]


def test_an_ingress_certificate_that_does_not_cover_the_control_name_is_refused(store: FleetState, repo: Path, tmp_path: Path) -> None:  # noqa: F811
    fleet(store)
    cert, key = tmp_path / "wrong.crt", tmp_path / "wrong.key"
    subprocess.run(["openssl", "req", "-x509", "-newkey", "ec", "-pkeyopt", "ec_paramgen_curve:prime256v1", "-nodes", "-keyout", str(key), "-out", str(cert),
                    "-days", "90", "-subj", "/CN=other.example.org", "-addext", "subjectAltName=DNS:other.example.org"], check=True, capture_output=True)
    instance = control(store, repo, tmp_path, FakeRunner())
    with store.locked(), pytest.raises(ValidationError, match="does not cover"):
        instance.add_standby("192.0.2.11", name="control-2", replication_address="10.9.0.2", active_replication_address="10.9.0.1", certificate=cert, key=key)
    assert "control-2" not in store.load()["nodes"]


def test_a_standby_with_a_mismatched_secret_never_reaches_ready(store: FleetState, repo: Path, tmp_path: Path, certificate: tuple[Path, Path]) -> None:  # noqa: F811
    fleet(store)
    runner = FakeRunner(mismatched_standby=True)
    instance = control(store, repo, tmp_path, runner)
    with store.locked(), pytest.raises(FleetError, match="secret_fingerprint_mismatch"):
        instance.add_standby("192.0.2.11", name="control-2", replication_address="10.9.0.2", active_replication_address="10.9.0.1",
                             certificate=certificate[0], key=certificate[1])


def test_add_standby_stops_at_the_first_failure_and_can_be_resumed(store: FleetState, repo: Path, tmp_path: Path, certificate: tuple[Path, Path]) -> None:  # noqa: F811
    fleet(store)
    runner = FakeRunner()
    runner.fail_on = "ha/prepare-source.sh"
    instance = control(store, repo, tmp_path, runner)
    with store.locked(), pytest.raises(FleetError, match="prepare-source"):
        instance.add_standby("192.0.2.11", name="control-2", replication_address="10.9.0.2", active_replication_address="10.9.0.1",
                             certificate=certificate[0], key=certificate[1])
    assert not any("seed-standby" in command for _, command in runner.calls)
    runner.fail_on, runner.calls = None, []
    with store.locked():
        result = instance.add_standby("192.0.2.11", name="control-2", replication_address="10.9.0.2", active_replication_address="10.9.0.1",
                                      certificate=certificate[0], key=certificate[1])
    assert result["ha"] == "ready"


def test_promote_refuses_while_the_old_active_is_serving(store: FleetState, repo: Path, tmp_path: Path, certificate: tuple[Path, Path]) -> None:  # noqa: F811
    instance, runner = standby_added(store, repo, tmp_path, certificate)
    runner.calls.clear()
    instance.probe = lambda address, hostname: {"epoch": 1, "role": "active"}
    with store.locked(), pytest.raises(ValidationError, match="still the active node"):
        instance.promote()
    instance.probe = lambda address, hostname: {"role": "unknown"}
    with store.locked(), pytest.raises(ValidationError, match="role could not be read"):
        instance.promote()
    instance.probe = lambda address, hostname: None
    with store.locked(), pytest.raises(ValidationError, match="does not prove it is down"):
        instance.promote()
    assert runner.calls == [] or not any("promote.sh" in command for _, command in runner.calls)
    assert ha.section(store.load())["active"] == "control-1"


def test_promote_swaps_roles_starts_the_new_active_and_prints_the_dns_change(store: FleetState, repo: Path, tmp_path: Path, certificate: tuple[Path, Path]) -> None:  # noqa: F811
    instance, runner = standby_added(store, repo, tmp_path, certificate)
    runner.calls.clear()
    with store.locked():
        result = instance.promote(old_active_is_powered_off=True)

    assert result["epoch"] == 2 and result["active"] == "control-2" and result["needs_rejoin"] == "control-1"
    assert result["dns_changes"] == ["control.ops.example.com. 60 IN A 192.0.2.11", "edge-control.ops.example.com. 60 IN A 192.0.2.11"]
    order = [runner.index("ha/promote.sh", "control-2"), runner.index("push", "control-2"), runner.index("start.sh", "control-2")]
    assert order == sorted(order)
    assert "control-1" not in {name for name, _ in runner.calls}, "a powered-off node is never contacted"
    state = store.load()
    assert ha.section(state)["active"] == "control-2" and ha.section(state)["standby_needs_rejoin"] is True
    # The new active's bundle is the active kind and has no replica line; the old node's bundle is the standby kind.
    assert "replicaof" not in (tmp_path / "bundles/control-2/secrets/valkey.conf").read_text(encoding="utf-8")
    assert "replicaof 10.9.0.2 6379" in (tmp_path / "bundles/control-1/secrets/valkey.conf").read_text(encoding="utf-8")


def test_a_planned_switchover_promotes_only_to_the_positions_the_old_active_reported(store: FleetState, repo: Path, tmp_path: Path, certificate: tuple[Path, Path]) -> None:  # noqa: F811
    instance, runner = standby_added(store, repo, tmp_path, certificate)
    runner.calls.clear()
    with store.locked():
        instance.switchover()

    assert runner.index("ha/demote.sh", "control-1") < runner.index("ha/promote.sh", "control-2")
    command = runner.commands()[runner.index("ha/promote.sh")]
    assert "control-db=0/1000,pdns-db=0/2000" in command


def test_rejoin_rebuilds_the_old_active_as_the_standby(store: FleetState, repo: Path, tmp_path: Path, certificate: tuple[Path, Path]) -> None:  # noqa: F811
    instance, runner = standby_added(store, repo, tmp_path, certificate)
    with store.locked(), pytest.raises(ValidationError, match="waiting to rejoin"):
        instance.rejoin()
    with store.locked():
        instance.promote(old_active_is_powered_off=True)
    runner.calls.clear()
    runner.standby_name = "control-1"
    with store.locked():
        result = instance.rejoin()

    assert runner.index("push", "control-1") < runner.index("ha/rejoin.sh", "control-1")
    assert result["ha"] == "ready"
    assert "standby_needs_rejoin" not in ha.section(store.load())


def test_remove_standby_returns_to_one_node(store: FleetState, repo: Path, tmp_path: Path, certificate: tuple[Path, Path]) -> None:  # noqa: F811
    instance, runner = standby_added(store, repo, tmp_path, certificate)
    runner.calls.clear()
    runner.edges_missing = [0]
    with store.locked():
        result = instance.remove_standby()

    assert result["ha"] == "none"
    assert runner.index("ha/release-source.sh", "control-1") < runner.index("cdnf:control:manage remove-standby") < runner.index("stop.sh", "control-2")
    state = store.load()
    assert "control-2" not in state["nodes"] and ha.section(state)["mode"] == "none"
    assert "CONTROL_PEER_ADDRESSES" not in env_values(tmp_path / "bundles/control-1/.env.prod") or not env_values(tmp_path / "bundles/control-1/.env.prod")["CONTROL_PEER_ADDRESSES"]
    assert not {name for name, _ in runner.calls} & {"edge-1", "dns-1"}


def test_the_control_group_is_in_the_command_line(tmp_path: Path) -> None:
    from cdnfoundry_fleet.cli import parser

    args = parser().parse_args(["control", "add-standby", "192.0.2.11", "--name", "control-2", "--replication-address", "10.9.0.2",
                                "--active-replication-address", "10.9.0.1", "--ingress-certificate", "c", "--ingress-key", "k"])
    assert args.control_action == "add-standby" and args.edge_timeout == control_cli.EDGE_WAIT_SECONDS
    assert parser().parse_args(["control", "promote", "--old-active-is-powered-off"]).old_active_is_powered_off is True


def test_a_control_bundle_contains_the_pdns_migration_its_start_script_runs(store: FleetState, tmp_path: Path) -> None:  # noqa: F811
    add(store, node("control-1", "control", "192.0.2.140"))
    add(store, node("dns-1", "dns", "192.0.2.141"))
    output = tmp_path / "real"
    Renderer(REPO_PATCH, store, output).render(store.load())

    control = yaml.safe_load((output / "control-1/compose.yml").read_text(encoding="utf-8"))["services"]
    assert "pdns-migrate" in control and control["pdns-migrate"]["profiles"] == ["tools"]
    assert "run --rm pdns-migrate" in (output / "control-1/start.sh").read_text(encoding="utf-8")
    assert "pdns-migrate" not in yaml.safe_load((output / "dns-1/compose.yml").read_text(encoding="utf-8"))["services"]


def test_ipv6_control_addresses_reach_the_edges_and_the_dns_change_is_an_aaaa_record(store: FleetState, repo: Path, tmp_path: Path, certificate: tuple[Path, Path]) -> None:  # noqa: F811
    payload = node("control-1", "control", "192.0.2.10")
    payload["public_ipv6"] = "2001:db8::10"
    add(store, payload)
    runner = FakeRunner()
    instance = control(store, repo, tmp_path, runner)
    with store.locked():
        instance.add_standby("192.0.2.11", name="control-2", replication_address="fd77::2", active_replication_address="fd77::1",
                             certificate=certificate[0], key=certificate[1], public_ipv6="2001:db8::11", address_family=6)
    assert "--addresses=2001:db8::10,2001:db8::11" in runner.commands()[runner.index("cdnf:control:manage addresses")]
    standby_env = env_values(tmp_path / "bundles/control-2/.env.prod")
    assert standby_env["CONTROL_PEER_ADDRESSES"] == "192.0.2.10,2001:db8::10"
    compose = yaml.safe_load((tmp_path / "bundles/control-2/compose.yml").read_text(encoding="utf-8"))
    assert "[fd77::2]:5432:5432/tcp" in compose["services"]["control-db"]["ports"]
    with store.locked():
        result = instance.promote(old_active_is_powered_off=True)
    assert result["dns_changes"] == ["control.ops.example.com. 60 IN AAAA 2001:db8::11", "edge-control.ops.example.com. 60 IN AAAA 2001:db8::11"]


def test_ipv6_control_addresses_need_an_ipv6_address_on_both_nodes(store: FleetState, repo: Path, tmp_path: Path, certificate: tuple[Path, Path]) -> None:  # noqa: F811
    fleet(store)
    instance = control(store, repo, tmp_path, FakeRunner())
    with store.locked(), pytest.raises(ValidationError, match="public IPv6 address on both"):
        instance.add_standby("192.0.2.11", name="control-2", replication_address="10.9.0.2", active_replication_address="10.9.0.1",
                             certificate=certificate[0], key=certificate[1], address_family=6)
