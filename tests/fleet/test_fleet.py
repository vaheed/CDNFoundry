from __future__ import annotations

import argparse
import json
import os
import stat
import sys
from pathlib import Path

import pytest
import yaml

REPO_PATCH = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(REPO_PATCH / "scripts"))

from cdnfoundry_fleet.common import ValidationError
from cdnfoundry_fleet.render import Renderer
from cdnfoundry_fleet.state import FleetState


@pytest.fixture()
def source_repo(tmp_path: Path) -> Path:
    root = tmp_path / "repo"
    (root / "deploy/production").mkdir(parents=True)
    (root / "docker/postgres").mkdir(parents=True)
    (root / "docker/pdns").mkdir(parents=True)
    (root / "docker/dnsdist").mkdir(parents=True)
    (root / "docker/mmdb").mkdir(parents=True)
    (root / "docker/prometheus").mkdir(parents=True)
    (root / "docker/vector").mkdir(parents=True)
    for path in [
        "docker/postgres/pdns-schema.sql",
        "docker/pdns/pdns.conf",
        "docker/dnsdist/dnsdist.conf",
        "docker/prometheus/prometheus.yml",
        "docker/vector/operational.yaml",
    ]:
        target = root / path
        target.write_text("# fixture\n", encoding="utf-8")

    compose = {
        "services": {
            "control-db": {
                "image": "postgres:18-alpine",
                "profiles": ["control"],
                "environment": {"POSTGRES_PASSWORD": "${CONTROL_DB_PASSWORD:?required}"},
                "volumes": ["control-db:/var/lib/postgresql"],
            },
            "redis": {
                "image": "valkey:9-alpine",
                "profiles": ["control"],
                "command": ["valkey-server", "--requirepass", "${REDIS_PASSWORD:?required}"],
                "volumes": ["redis:/data"],
            },
            "core": {
                "image": "core:${CDNF_RELEASE:?required}",
                "profiles": ["control"],
                "environment": {
                    "APP_KEY": "${APP_KEY:?required}",
                    "EDGE_ARTIFACT_SIGNING_KEY": "${EDGE_ARTIFACT_SIGNING_KEY:?required}",
                    "EDGE_IDENTITY_CA_CERTIFICATE": "${EDGE_IDENTITY_CA_CERTIFICATE:?required}",
                    "EDGE_IDENTITY_CA_PRIVATE_KEY": "${EDGE_IDENTITY_CA_PRIVATE_KEY:?required}",
                    "PDNS_CA_CERTIFICATE": "${PDNS_CA_CERTIFICATE:?required}",
                },
                "volumes": [
                    "${EDGE_IDENTITY_CA_CERTIFICATE:?required}:/run/pki/edge-identity-ca.crt:ro",
                    "${EDGE_IDENTITY_CA_PRIVATE_KEY:?required}:/run/pki/edge-identity-ca.key:ro",
                    "${PDNS_CA_CERTIFICATE:?required}:/run/pki/pdns-ca.crt:ro",
                ],
                "depends_on": {"control-db": {"condition": "service_started"}, "redis": {"condition": "service_started"}},
            },
            "edge-control": {
                "image": "edge-control:${CDNF_RELEASE:?required}",
                "profiles": ["control"],
                "environment": {
                    "EDGE_CONTROL_SERVER_CERTIFICATE": "${EDGE_CONTROL_SERVER_CERTIFICATE:?required}",
                    "EDGE_CONTROL_SERVER_PRIVATE_KEY": "${EDGE_CONTROL_SERVER_PRIVATE_KEY:?required}",
                },
                "volumes": [
                    "${EDGE_CONTROL_SERVER_CERTIFICATE:?required}:/run/pki/server.crt:ro",
                    "${EDGE_CONTROL_SERVER_PRIVATE_KEY:?required}:/run/pki/server.key:ro",
                ],
            },
            "migrate": {
                "image": "core:${CDNF_RELEASE:?required}",
                "profiles": ["tools"],
                "environment": {"CONTROL_DB_PASSWORD": "${CONTROL_DB_PASSWORD:?required}"},
            },
            "pdns-db": {
                "image": "postgres:18-alpine",
                "profiles": ["dns"],
                "environment": {
                    "POSTGRES_DB": "pdns",
                    "POSTGRES_USER": "pdns",
                    "POSTGRES_PASSWORD": "${PDNS_DB_PASSWORD:?required}",
                },
                "volumes": ["pdns-db:/var/lib/postgresql", "./docker/postgres/pdns-schema.sql:/init.sql:ro"],
            },
            "mmdb-updater": {
                "image": "mmdb:${CDNF_RELEASE:?required}",
                "profiles": ["dns", "edge"],
                "environment": {"MMDB_PROVIDER": "${MMDB_PROVIDER:-dbip-jsdelivr}"},
                "volumes": ["mmdb:/mmdb"],
            },
            "pdns-auth": {
                "image": "pdns:5",
                "profiles": ["dns"],
                "environment": {
                    "PDNS_gpgsql_password": "${PDNS_DB_PASSWORD:?required}",
                    "PDNS_api_key": "${PDNS_API_KEY:?required}",
                    "DNS_API_SERVER_CERTIFICATE": "${DNS_API_SERVER_CERTIFICATE:?required}",
                    "DNS_API_SERVER_PRIVATE_KEY": "${DNS_API_SERVER_PRIVATE_KEY:?required}",
                },
                "depends_on": {"pdns-db": {"condition": "service_started"}, "mmdb-updater": {"condition": "service_started"}},
                "volumes": ["./docker/pdns/pdns.conf:/etc/pdns.conf:ro", "mmdb:/mmdb:ro"],
            },
            "pdns-migrate": {
                "image": "postgres:18-alpine",
                "profiles": ["tools"],
                "environment": {"PGPASSWORD": "${PDNS_DB_PASSWORD:?required}"},
                "volumes": ["./docker/postgres/pdns-schema.sql:/migration.sql:ro"],
            },
            "dnsdist": {
                "image": "dnsdist:2",
                "profiles": ["dns"],
                "depends_on": {"pdns-auth": {"condition": "service_started"}},
                "ports": ["${DNS_BIND_V4:-0.0.0.0}:53:53/udp"],
                "volumes": ["./docker/dnsdist/dnsdist.conf:/etc/dnsdist.conf:ro"],
            },
            "edge-agent": {
                "image": "edge:${CDNF_RELEASE:?required}",
                "profiles": ["edge"],
                "environment": {
                    "EDGE_STATUS_TOKEN": "${EDGE_STATUS_TOKEN:?required}",
                    "EDGE_CONTROL_URL": "${EDGE_CONTROL_URL:?required}",
                    "EDGE_CONTROL_CA_CERTIFICATE": "${EDGE_CONTROL_CA_CERTIFICATE:?required}",
                },
                "volumes": [
                    "${EDGE_CONTROL_CA_CERTIFICATE:?required}:/run/edge-control-ca.crt:ro",
                    "${EDGE_RUNTIME_TLS_CERTIFICATE:?required}:/run/node.crt:ro",
                    "${EDGE_RUNTIME_TLS_PRIVATE_KEY:?required}:/run/node.key:ro",
                ],
            },
            "cell-01": {
                "image": "cell:${CDNF_RELEASE:?required}",
                "profiles": ["edge"],
                "depends_on": {"mmdb-updater": {"condition": "service_started"}},
                "volumes": ["mmdb:/mmdb:ro", "cell-01-cache:/cache"],
            },
            "vector": {
                "image": "vector:latest",
                "profiles": ["telemetry", "edge"],
                "environment": {
                    "CLICKHOUSE_ENDPOINT": "${CLICKHOUSE_URL:?required}",
                    "CLICKHOUSE_PASSWORD": "${CLICKHOUSE_PASSWORD:?required}",
                },
            },
            "clickhouse": {
                "image": "clickhouse:latest",
                "profiles": ["telemetry"],
                "environment": {"CLICKHOUSE_PASSWORD": "${CLICKHOUSE_PASSWORD:?required}"},
                "volumes": ["clickhouse:/var/lib/clickhouse"],
            },
            "prometheus": {
                "image": "prometheus:latest",
                "profiles": ["telemetry"],
                "volumes": ["./docker/prometheus/prometheus.yml:/etc/prometheus.yml:ro", "prometheus:/prometheus"],
            },
            "node-exporter": {
                "image": "node-exporter:latest",
                "profiles": ["telemetry"],
            },
            "log-collector": {
                "image": "vector:latest",
                "profiles": ["logs"],
                "environment": {
                    "LOG_ROLE": "${LOG_ROLE:?required}",
                    "LOG_HOST": "${LOG_HOST:?required}",
                    "LOG_COLLECTOR_ID": "${LOG_COLLECTOR_ID:?required}",
                    "LOKI_ENDPOINT": "${LOKI_ENDPOINT:?required}",
                    "LOG_AUTH_TOKEN": "${LOG_AUTH_TOKEN:?required}",
                },
                "volumes": ["./docker/vector/operational.yaml:/etc/vector/operational.yaml:ro"],
            },
        },
        "volumes": {
            "control-db": {},
            "redis": {},
            "pdns-db": {},
            "mmdb": {},
            "cell-01-cache": {},
            "clickhouse": {},
            "prometheus": {},
        },
    }
    (root / "compose.prod.yml").write_text(yaml.safe_dump(compose, sort_keys=False), encoding="utf-8")
    for name in [
        "compose.control-host.yml",
        "compose.dns-host.yml",
        "compose.edge-host.yml",
        "compose.dns-edge-host.yml",
        "compose.telemetry-host.yml",
        "compose.external-control-data.yml",
        "compose.host-journal.yml",
    ]:
        (root / "deploy/production" / name).write_text("services: {}\n", encoding="utf-8")
    return root


@pytest.fixture()
def store(tmp_path: Path) -> FleetState:
    state = FleetState(tmp_path / "state")
    with state.locked():
        state.init(
            {
                "operator_domain": "ops.example.com",
                "platform_domain": "example.net",
                "release": "v1.0.0",
                "acme_email": "ops@example.com",
            }
        )
    return state


def node(name: str, role: str, ip: str, *, region: str = "eu", location: str | None = None) -> dict[str, object]:
    return {
        "name": name,
        "role": role,
        "region": region,
        "location": location or name,
        "public_ipv4": ip,
        "bind_ipv4": "0.0.0.0",
    }


def add(store: FleetState, payload: dict[str, object]) -> dict[str, object]:
    with store.locked():
        return store.add_node(store.load(), payload)


def env_values(path: Path) -> dict[str, str]:
    return dict(line.split("=", 1) for line in path.read_text(encoding="utf-8").splitlines() if line)


def test_dns_hosts_get_unique_stable_local_database_credentials(store: FleetState, source_repo: Path, tmp_path: Path) -> None:
    add(store, node("dns-ashburn", "dns", "192.0.2.11"))
    add(store, node("dns-frankfurt", "dns", "192.0.2.12"))
    output = tmp_path / "bundles"
    renderer = Renderer(source_repo, store, output)
    renderer.render(store.load())

    first = env_values(output / "dns-ashburn/.env.prod")
    second = env_values(output / "dns-frankfurt/.env.prod")
    assert first["PDNS_DB_PASSWORD"] != second["PDNS_DB_PASSWORD"]
    assert first["PDNS_API_KEY"] != second["PDNS_API_KEY"]

    renderer.render(store.load())
    rerendered = env_values(output / "dns-ashburn/.env.prod")
    assert rerendered["PDNS_DB_PASSWORD"] == first["PDNS_DB_PASSWORD"]
    assert rerendered["PDNS_API_KEY"] == first["PDNS_API_KEY"]


def test_dns_bundle_uses_local_postgres_and_has_no_control_secrets(store: FleetState, source_repo: Path, tmp_path: Path) -> None:
    add(store, node("dns-singapore", "dns", "192.0.2.20"))
    output = tmp_path / "bundles"
    Renderer(source_repo, store, output).render(store.load())
    compose = yaml.safe_load((output / "dns-singapore/compose.yml").read_text(encoding="utf-8"))
    env = env_values(output / "dns-singapore/.env.prod")

    assert "pdns-db" in compose["services"]
    assert "pdns-auth" in compose["services"]
    assert "control-db" not in compose["services"]
    assert compose["services"]["pdns-auth"]["environment"]["PDNS_gpgsql_host"] == "pdns-db"
    assert "PDNS_DB_PASSWORD" in env
    assert "CONTROL_DB_PASSWORD" not in env
    assert "APP_KEY" not in env
    assert "GRAFANA_ADMIN_PASSWORD" not in env


def test_disabled_monitoring_does_not_require_clickhouse_credentials_on_edge(store: FleetState, source_repo: Path, tmp_path: Path) -> None:
    add(store, node("edge-dubai", "edge", "192.0.2.30"))
    output = tmp_path / "bundles"
    Renderer(source_repo, store, output).render(store.load())
    compose = yaml.safe_load((output / "edge-dubai/compose.yml").read_text(encoding="utf-8"))
    env = env_values(output / "edge-dubai/.env.prod")
    assert "vector" not in compose["services"]
    assert "CLICKHOUSE_PASSWORD" not in env


def test_duplicate_ip_and_malicious_node_input_are_rejected(store: FleetState) -> None:
    add(store, node("edge-one", "edge", "192.0.2.40"))
    with pytest.raises(ValidationError, match="Duplicate fleet IP"):
        add(store, node("edge-two", "edge", "192.0.2.40"))
    with pytest.raises(ValidationError):
        add(store, node("bad;rm-rf", "edge", "192.0.2.41"))


def test_failed_update_preserves_previous_valid_state(store: FleetState) -> None:
    add(store, node("edge-one", "edge", "192.0.2.50"))
    before = store.state_file.read_bytes()
    with store.locked():
        with pytest.raises(ValidationError):
            store.update_node(store.load(), "edge-one", {"public_ipv4": "not-an-ip"})
    assert store.state_file.read_bytes() == before


def test_monitoring_targets_cover_every_host_and_update_after_removal(store: FleetState, source_repo: Path, tmp_path: Path) -> None:
    add(store, node("control-1", "control", "192.0.2.60"))
    add(store, node("monitor-1", "monitoring", "192.0.2.61"))
    add(store, node("dns-1", "dns", "192.0.2.62"))
    add(store, node("edge-1", "edge", "192.0.2.63"))
    with store.locked():
        store.configure_feature(store.load(), "monitoring", {"mode": "dedicated", "host": "monitor-1"})
    output = tmp_path / "bundles"
    Renderer(source_repo, store, output).render(store.load())
    targets = yaml.safe_load((output / "monitor-1/generated/prometheus-node-targets.yml").read_text(encoding="utf-8"))
    assert {group["labels"]["node"] for group in targets} == {"control-1", "monitor-1", "dns-1", "edge-1"}

    with store.locked():
        store.remove_node(store.load(), "edge-1")
    Renderer(source_repo, store, output).render(store.load(), node_name="monitor-1")
    targets = yaml.safe_load((output / "monitor-1/generated/prometheus-node-targets.yml").read_text(encoding="utf-8"))
    assert {group["labels"]["node"] for group in targets} == {"control-1", "monitor-1", "dns-1"}


def test_heavy_four_dns_ten_edge_topology_validates(store: FleetState) -> None:
    for index, location in enumerate(["ashburn", "frankfurt", "singapore", "sao-paulo"], 1):
        add(store, node(f"dns-{location}", "dns", f"192.0.2.{70 + index}", region=f"r{index}", location=location))
    edges = ["ashburn", "los-angeles", "sao-paulo", "frankfurt", "johannesburg", "dubai", "mumbai", "singapore", "tokyo", "sydney"]
    for index, location in enumerate(edges, 1):
        add(store, node(f"edge-{location}", "edge", f"198.51.100.{index}", region=f"r{index % 4}", location=location))
    state = store.load()
    store.validate(state)
    assert len([n for n in state["nodes"].values() if n["role"] == "dns"]) == 4
    assert len([n for n in state["nodes"].values() if n["role"] == "edge"]) == 10


def test_file_permissions_and_redacted_metadata(store: FleetState, source_repo: Path, tmp_path: Path) -> None:
    add(store, node("dns-sydney", "dns", "192.0.2.90"))
    output = tmp_path / "bundles"
    Renderer(source_repo, store, output).render(store.load())
    bundle = output / "dns-sydney"
    assert stat.S_IMODE((bundle / ".env.prod").stat().st_mode) == 0o600
    assert stat.S_IMODE((bundle / "pki/node.key").stat().st_mode) == 0o600
    assert stat.S_IMODE(store.state_file.stat().st_mode) == 0o600
    metadata = (bundle / "bundle-metadata.json").read_text(encoding="utf-8")
    pdns_password = env_values(bundle / ".env.prod")["PDNS_DB_PASSWORD"]
    assert pdns_password not in metadata


def test_dry_run_does_not_create_state_or_secrets(tmp_path: Path) -> None:
    store = FleetState(tmp_path / "dry-state", dry_run=True)
    state = store.init({"operator_domain": "ops.example.com", "platform_domain": "example.net", "release": "v1.0.0"})
    assert state["schema_version"] == 1
    assert not store.state_dir.exists()


def test_explicit_rotation_changes_only_target_dns_node(store: FleetState) -> None:
    add(store, node("dns-one", "dns", "192.0.2.101"))
    add(store, node("dns-two", "dns", "192.0.2.102"))
    one_before = store.read_secret("pdns-db-password", node="dns-one")
    two_before = store.read_secret("pdns-db-password", node="dns-two")
    store.rotate_secret("pdns-db-password", node="dns-one")
    assert store.read_secret("pdns-db-password", node="dns-one") != one_before
    assert store.read_secret("pdns-db-password", node="dns-two") == two_before


def test_geo_policy_records_independent_address_families_and_flap_thresholds(store: FleetState, source_repo: Path, tmp_path: Path) -> None:
    add(store, {**node("edge-tokyo", "edge", "192.0.2.110"), "public_ipv6": "2001:db8::110"})
    add(store, node("monitor-1", "monitoring", "192.0.2.111"))
    with store.locked():
        store.configure_feature(store.load(), "monitoring", {"mode": "dedicated", "host": "monitor-1"})
    output = tmp_path / "bundles"
    Renderer(source_repo, store, output).render(store.load(), node_name="monitor-1")
    policy = json.loads((output / "monitor-1/generated/geo-routing-policy.json").read_text(encoding="utf-8"))
    assert policy["address_families"] == "independent"
    assert policy["decision_order"][0] == "valid_ecs_client_subnet"
    edge = policy["edges"][0]
    assert edge["failure_threshold"] == 3
    assert edge["success_threshold"] == 2


def test_cli_parser_exposes_all_commands_and_help() -> None:
    from cdnfoundry_fleet.cli import parser

    cli = parser()
    commands = {
        "setup",
        "status",
        "doctor",
        "init",
        "add-node",
        "update-node",
        "configure-edge-registration",
        "clear-edge-bootstrap-token",
        "set-secret",
        "remove-node",
        "list-nodes",
        "configure-monitoring",
        "configure-logs",
        "configure-backups",
        "render",
        "validate",
        "show-start-order",
        "adopt-existing",
        "rotate-secret",
    }
    subparsers = next(action for action in cli._actions if isinstance(action, argparse._SubParsersAction))
    assert set(subparsers.choices) == commands
    args = cli.parse_args(["update-node", "--node", "dns-one", "--location", "New Location"])
    assert args.command == "update-node"
    assert args.node == "dns-one"
    assert args.location == "New Location"


@pytest.mark.parametrize(
    "command",
    [
        "setup",
        "status",
        "doctor",
        "init",
        "add-node",
        "update-node",
        "configure-edge-registration",
        "clear-edge-bootstrap-token",
        "set-secret",
        "remove-node",
        "list-nodes",
        "configure-monitoring",
        "configure-logs",
        "configure-backups",
        "render",
        "validate",
        "show-start-order",
        "adopt-existing",
        "rotate-secret",
    ],
)
def test_every_command_supports_help(command: str) -> None:
    from cdnfoundry_fleet.cli import parser

    with pytest.raises(SystemExit) as exc:
        parser().parse_args([command, "--help"])
    assert exc.value.code == 0


def test_pdns_rotation_is_prepared_reconciled_and_committed(store: FleetState, source_repo: Path, tmp_path: Path) -> None:
    add(store, node("dns-rotate", "dns", "192.0.2.120"))
    current = store.read_secret("pdns-db-password", node="dns-rotate")
    pending = store.prepare_secret_rotation("pdns-db-password", node="dns-rotate")
    assert pending.exists()
    assert pending.read_text(encoding="utf-8").strip() != current
    assert store.read_secret("pdns-db-password", node="dns-rotate") == current

    output = tmp_path / "bundles"
    Renderer(source_repo, store, output).render(store.load(), node_name="dns-rotate")
    bundle = output / "dns-rotate"
    assert (bundle / "reconcile-pdns-password.sh").exists()
    assert stat.S_IMODE((bundle / "reconcile-pdns-password.sh").stat().st_mode) == 0o700
    assert (bundle / "secrets/pdns-db-password.next").read_text(encoding="utf-8") == pending.read_text(encoding="utf-8")
    assert env_values(bundle / ".env.prod")["PDNS_DB_PASSWORD"] == current

    next_value = pending.read_text(encoding="utf-8").strip()
    store.commit_secret_rotation("pdns-db-password", node="dns-rotate")
    assert store.read_secret("pdns-db-password", node="dns-rotate") == next_value
    assert not pending.exists()


def test_pdns_rotation_can_be_aborted_without_changing_current_secret(store: FleetState) -> None:
    add(store, node("dns-abort", "dns", "192.0.2.121"))
    current = store.read_secret("pdns-db-password", node="dns-abort")
    pending = store.prepare_secret_rotation("pdns-db-password", node="dns-abort")
    store.abort_secret_rotation("pdns-db-password", node="dns-abort")
    assert not pending.exists()
    assert store.read_secret("pdns-db-password", node="dns-abort") == current


def test_disabled_monitoring_bundle_does_not_receive_metrics_secret(store: FleetState, source_repo: Path, tmp_path: Path) -> None:
    add(store, node("edge-no-metrics", "edge", "192.0.2.122"))
    output = tmp_path / "bundles"
    Renderer(source_repo, store, output).render(store.load())
    assert not (output / "edge-no-metrics/secrets/metrics-token").exists()


def test_common_cli_options_work_before_or_after_subcommand() -> None:
    from cdnfoundry_fleet.cli import parser

    before = parser().parse_args(
        [
            "--state-dir",
            "/tmp/state-before",
            "--repo-root",
            "/tmp/repo-before",
            "validate",
        ]
    )
    after = parser().parse_args(
        [
            "validate",
            "--state-dir",
            "/tmp/state-after",
            "--repo-root",
            "/tmp/repo-after",
        ]
    )
    assert before.state_dir == "/tmp/state-before"
    assert before.repo_root == "/tmp/repo-before"
    assert after.state_dir == "/tmp/state-after"
    assert after.repo_root == "/tmp/repo-after"


def test_cli_end_to_end_dns_nodes_keep_separate_local_credentials(source_repo: Path, tmp_path: Path) -> None:
    import subprocess

    cli = Path(__file__).resolve().parents[2] / "scripts/cdnfoundry-fleet"
    state_dir = tmp_path / "cli-state"
    output_dir = tmp_path / "cli-bundles"

    def run(*args: str) -> subprocess.CompletedProcess[str]:
        return subprocess.run(
            [
                str(cli),
                "--state-dir",
                str(state_dir),
                "--output-dir",
                str(output_dir),
                "--repo-root",
                str(source_repo),
                *args,
            ],
            check=True,
            text=True,
            stdout=subprocess.PIPE,
            stderr=subprocess.PIPE,
        )

    run(
        "init",
        "--operator-domain",
        "ops.example.com",
        "--platform-domain",
        "example.net",
        "--release",
        "v1.0.0",
        "--non-interactive",
    )
    run(
        "add-node",
        "--node",
        "dns-one",
        "--role",
        "dns",
        "--region",
        "eu",
        "--location",
        "frankfurt",
        "--public-ipv4",
        "192.0.2.130",
        "--non-interactive",
    )
    run(
        "add-node",
        "--node",
        "dns-two",
        "--role",
        "dns",
        "--region",
        "asia",
        "--location",
        "singapore",
        "--public-ipv4",
        "192.0.2.131",
        "--non-interactive",
    )
    run("validate")
    run("render")

    one = env_values(output_dir / "dns-one/.env.prod")
    two = env_values(output_dir / "dns-two/.env.prod")
    assert one["PDNS_DB_PASSWORD"] != two["PDNS_DB_PASSWORD"]
    assert "CONTROL_DB_PASSWORD" not in one
    assert "CONTROL_DB_PASSWORD" not in two


def test_production_docs_contain_required_diagrams_and_machine_scoped_commands() -> None:
    root = Path(__file__).resolve().parents[2]
    quick = (root / "docs/deployment/production-quick-start.md").read_text(encoding="utf-8")
    reference = (root / "docs/deployment/production-fleet.md").read_text(encoding="utf-8")
    assert reference.count("```mermaid") >= 7
    assert "control-plane machine" in quick
    assert "target DNS host" in quick
    assert "own local PostgreSQL" in reference
    assert "never uses the control-plane" in reference
    assert "docker compose down -v" in quick


def test_heavy_example_script_builds_complete_fleet(source_repo: Path, tmp_path: Path) -> None:
    import shutil
    import subprocess

    patch_root = Path(__file__).resolve().parents[2]
    shutil.copytree(patch_root / "scripts", source_repo / "scripts", dirs_exist_ok=True)
    example_target = source_repo / "deploy/production/examples/heavy-production.sh"
    example_target.parent.mkdir(parents=True, exist_ok=True)
    shutil.copy2(patch_root / "deploy/production/examples/heavy-production.sh", example_target)
    example_target.chmod(0o755)

    state_dir = tmp_path / "heavy-state"
    env = {
        **os.environ,
        "STATE_DIR": str(state_dir),
        "OUTPUT_DIR": str(state_dir / "bundles"),
        "OPERATOR_DOMAIN": "ops.example.com",
        "PLATFORM_DOMAIN": "example.net",
        "RELEASE": "v1.0.0",
    }
    subprocess.run([str(example_target)], cwd=source_repo, env=env, check=True, text=True, capture_output=True)
    state = json.loads((state_dir / "fleet.json").read_text(encoding="utf-8"))
    assert len(state["nodes"]) == 18
    assert len([n for n in state["nodes"].values() if n["role"] == "dns"]) == 4
    assert len([n for n in state["nodes"].values() if n["role"] == "edge"]) == 10
    assert len([n for n in state["nodes"].values() if n["role"] == "monitoring"]) == 3
    assert len([p for p in (state_dir / "bundles").iterdir() if p.is_dir() and not p.name.endswith(".previous")]) == 18



def test_compose_loader_supports_reset_and_override_tags(tmp_path: Path) -> None:
    from cdnfoundry_fleet.compose import load_yaml, merge_compose

    base_path = tmp_path / "base.yml"
    overlay_path = tmp_path / "overlay.yml"
    base_path.write_text(
        "services:\n  app:\n    command: [old]\n    ports: [80:80]\n    environment:\n      OLD: value\n",
        encoding="utf-8",
    )
    overlay_path.write_text(
        "services:\n  app:\n    command: !override [new]\n    ports: !reset []\n    environment: !override\n      NEW: value\n",
        encoding="utf-8",
    )
    merged = merge_compose(load_yaml(base_path), load_yaml(overlay_path))
    assert merged["services"]["app"]["command"] == ["new"]
    assert merged["services"]["app"]["ports"] == []
    assert merged["services"]["app"]["environment"] == {"NEW": "value"}


def test_control_monitoring_bundle_uses_project_pki_contract(store: FleetState, source_repo: Path, tmp_path: Path) -> None:
    add(store, node("control-1", "control", "192.0.2.140"))
    with store.locked():
        store.configure_feature(store.load(), "monitoring", {"mode": "colocated", "host": None})
    output = tmp_path / "bundles"
    Renderer(source_repo, store, output).render(store.load())
    bundle = output / "control-1"
    env = env_values(bundle / ".env.prod")
    assert env["EDGE_IDENTITY_CA_CERTIFICATE"] == "./pki/edge-identity-ca.crt"
    assert env["EDGE_IDENTITY_CA_PRIVATE_KEY"] == "./pki/edge-identity-ca.key"
    assert env["PDNS_CA_CERTIFICATE"] == "./pki/edge-server-ca.crt"
    assert env["EDGE_CONTROL_SERVER_CERTIFICATE"] == "./pki/node.crt"
    assert (bundle / "pki/edge-identity-ca.crt").exists()
    assert (bundle / "pki/edge-identity-ca.key").exists()
    assert (bundle / "pki/edge-server-ca.crt").exists()
    compose = yaml.safe_load((bundle / "compose.yml").read_text(encoding="utf-8"))
    assert "core" in compose["services"]
    assert "clickhouse" in compose["services"]
    assert "prometheus" in compose["services"]


def test_edge_bundle_has_control_url_and_server_ca(store: FleetState, source_repo: Path, tmp_path: Path) -> None:
    add(store, node("control-1", "control", "192.0.2.141"))
    add(store, node("edge-1", "edge", "192.0.2.142"))
    output = tmp_path / "bundles"
    Renderer(source_repo, store, output).render(store.load(), node_name="edge-1")
    env = env_values(output / "edge-1/.env.prod")
    assert env["EDGE_CONTROL_URL"] == "https://control-1.ops.example.com:8443"
    assert env["EDGE_CONTROL_CA_CERTIFICATE"] == "./pki/edge-server-ca.crt"
    assert (output / "edge-1/pki/edge-server-ca.crt").exists()


def test_setup_command_generates_control_monitoring_fleet(source_repo: Path, tmp_path: Path) -> None:
    import subprocess

    cli = Path(__file__).resolve().parents[2] / "scripts/cdnfoundry-fleet"
    state_dir = tmp_path / "setup-state"
    output_dir = tmp_path / "setup-bundles"
    result = subprocess.run(
        [
            str(cli),
            "--state-dir", str(state_dir),
            "--output-dir", str(output_dir),
            "--repo-root", str(source_repo),
            "setup",
            "--operator-domain", "ops.example.com",
            "--platform-domain", "example.net",
            "--release", "v1.0.0",
            "--preset", "control-monitoring",
            "--control-ipv4", "192.0.2.150",
            "--non-interactive",
        ],
        check=True,
        text=True,
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
    )
    assert "Generated 1 node bundle" in result.stdout
    assert (output_dir / "control-1/compose.yml").exists()
    state = json.loads((state_dir / "fleet.json").read_text(encoding="utf-8"))
    assert state["features"]["monitoring"]["mode"] == "colocated"


def test_generate_production_env_wrapper_runs_complete_setup(source_repo: Path, tmp_path: Path) -> None:
    import shutil
    import subprocess

    shutil.copytree(REPO_PATCH / "scripts", source_repo / "scripts")
    env = os.environ.copy()
    env["CDNFOUNDRY_FLEET_STATE_DIR"] = str(tmp_path / "wrapper-state")
    env["CDNFOUNDRY_FLEET_OUTPUT_DIR"] = str(tmp_path / "wrapper-bundles")
    result = subprocess.run(
        [
            str(source_repo / "scripts/generate-production-env.sh"),
            "--operator-domain", "ops.example.com",
            "--platform-domain", "example.net",
            "--release", "v1.0.0",
            "--preset", "control-monitoring",
            "--control-ipv4", "192.0.2.160",
            "--non-interactive",
        ],
        env=env,
        check=True,
        text=True,
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
    )
    assert "Starting CDNFoundry production fleet generator" in result.stdout
    assert "Generated 1 node bundle" in result.stdout
    assert (tmp_path / "wrapper-bundles/control-1/compose.yml").exists()


def test_status_and_doctor_are_actionable(source_repo: Path, tmp_path: Path) -> None:
    import subprocess

    cli = REPO_PATCH / "scripts/cdnfoundry-fleet"
    state_dir = tmp_path / "status-state"
    output_dir = tmp_path / "status-bundles"
    common = [
        str(cli),
        "--state-dir", str(state_dir),
        "--output-dir", str(output_dir),
        "--repo-root", str(source_repo),
    ]
    subprocess.run(
        common
        + [
            "setup",
            "--operator-domain", "ops.example.com",
            "--platform-domain", "example.net",
            "--release", "v1.0.0",
            "--preset", "control-monitoring",
            "--control-ipv4", "192.0.2.161",
            "--non-interactive",
        ],
        check=True,
        text=True,
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
    )
    status = subprocess.run(common + ["status"], check=True, text=True, stdout=subprocess.PIPE)
    assert "Monitoring: colocated" in status.stdout
    assert "control-1: control" in status.stdout
    doctor = subprocess.run(common + ["doctor"], check=True, text=True, stdout=subprocess.PIPE)
    assert "Doctor result: ready" in doctor.stdout


def test_optional_extra_env_is_preserved_for_manual_edge_registration(store: FleetState, source_repo: Path, tmp_path: Path) -> None:
    add(store, node("control-1", "control", "192.0.2.170"))
    payload = node("edge-1", "edge", "192.0.2.171")
    payload["extra_env"] = {
        "EDGE_ID": "11111111-2222-3333-4444-555555555555",
        "EDGE_BOOTSTRAP_TOKEN": "one-time-token",
        "EDGE_GATEWAY_ADDRESS_MAP": '{"198.51.100.10":"10.20.0.10"}',
    }
    add(store, payload)
    output = tmp_path / "bundles"
    Renderer(source_repo, store, output).render(store.load(), node_name="edge-1")
    env = env_values(output / "edge-1/.env.prod")
    assert env["EDGE_ID"] == "11111111-2222-3333-4444-555555555555"
    assert env["EDGE_BOOTSTRAP_TOKEN"] == "one-time-token"
    assert json.loads(env["EDGE_GATEWAY_ADDRESS_MAP"]) == '{"198.51.100.10":"10.20.0.10"}'


def test_edge_registration_command_uses_protected_token_file(source_repo: Path, tmp_path: Path) -> None:
    import subprocess

    cli = REPO_PATCH / "scripts/cdnfoundry-fleet"
    state_dir = tmp_path / "edge-registration-state"
    output_dir = tmp_path / "edge-registration-bundles"
    common = [
        str(cli),
        "--state-dir", str(state_dir),
        "--output-dir", str(output_dir),
        "--repo-root", str(source_repo),
    ]
    subprocess.run(
        common + [
            "init", "--operator-domain", "ops.example.com", "--platform-domain", "example.net",
            "--release", "v1.0.0", "--non-interactive",
        ],
        check=True,
    )
    subprocess.run(
        common + [
            "add-node", "--node", "control-1", "--role", "control", "--region", "global",
            "--location", "primary", "--public-ipv4", "192.0.2.172", "--non-interactive",
        ],
        check=True,
    )
    subprocess.run(
        common + [
            "add-node", "--node", "edge-1", "--role", "edge", "--region", "eu",
            "--location", "ams", "--public-ipv4", "192.0.2.173", "--non-interactive",
        ],
        check=True,
    )
    token_file = tmp_path / "bootstrap-token"
    token_file.write_text("protected-one-time-token\n", encoding="utf-8")
    token_file.chmod(0o600)
    subprocess.run(
        common + [
            "configure-edge-registration", "--node", "edge-1",
            "--edge-id", "11111111-2222-3333-4444-555555555555",
            "--bootstrap-token-file", str(token_file), "--non-interactive",
        ],
        check=True,
    )
    subprocess.run(common + ["render", "--node", "edge-1"], check=True)
    env = env_values(output_dir / "edge-1/.env.prod")
    assert env["EDGE_ID"] == "11111111-2222-3333-4444-555555555555"
    assert env["EDGE_BOOTSTRAP_TOKEN"] == "protected-one-time-token"
    secret_path = state_dir / "secrets/nodes/edge-1/edge-bootstrap-token"
    assert stat.S_IMODE(secret_path.stat().st_mode) == 0o600

    subprocess.run(common + ["clear-edge-bootstrap-token", "--node", "edge-1", "--non-interactive"], check=True)
    subprocess.run(common + ["render", "--node", "edge-1"], check=True)
    env = env_values(output_dir / "edge-1/.env.prod")
    assert env["EDGE_ID"] == "11111111-2222-3333-4444-555555555555"
    assert "EDGE_BOOTSTRAP_TOKEN" not in env
    assert not secret_path.exists()


def test_remote_control_postgres_removes_embedded_database(store: FleetState, source_repo: Path, tmp_path: Path) -> None:
    payload = node("control-1", "control", "192.0.2.174")
    payload["extra_env"] = {
        "DB_HOST": "postgres.internal.example",
        "DB_PORT": "5432",
        "DB_SSLMODE": "verify-full",
    }
    add(store, payload)
    with store.locked():
        store.configure_feature(store.load(), "monitoring", {"mode": "colocated", "host": None})
    output = tmp_path / "bundles"
    Renderer(source_repo, store, output).render(store.load(), node_name="control-1")
    compose = yaml.safe_load((output / "control-1/compose.yml").read_text(encoding="utf-8"))
    assert "control-db" not in compose["services"]
    for service in compose["services"].values():
        depends = service.get("depends_on", {})
        if isinstance(depends, dict):
            assert "control-db" not in depends
        elif isinstance(depends, list):
            assert "control-db" not in depends
    env = env_values(output / "control-1/.env.prod")
    assert env["DB_HOST"] == "postgres.internal.example"
    assert env["DB_PORT"] == "5432"
    assert env["DB_SSLMODE"] == "verify-full"
    assert env["GRAFANA_POSTGRES_HOST"] == "postgres.internal.example"
    assert env["GRAFANA_POSTGRES_PROVISION_HOST"] == "postgres.internal.example"
    start = (output / "control-1/start.sh").read_text(encoding="utf-8")
    assert "up -d --wait redis" in start
    assert "up -d --wait control-db redis" not in start


def test_set_secret_replaces_external_database_password(source_repo: Path, tmp_path: Path) -> None:
    import subprocess

    cli = REPO_PATCH / "scripts/cdnfoundry-fleet"
    state_dir = tmp_path / "secret-state"
    common = [str(cli), "--state-dir", str(state_dir), "--repo-root", str(source_repo)]
    subprocess.run(
        common + [
            "init", "--operator-domain", "ops.example.com", "--platform-domain", "example.net",
            "--release", "v1.0.0", "--non-interactive",
        ],
        check=True,
    )
    password_file = tmp_path / "postgres-password"
    password_file.write_text("remote-database-password\n", encoding="utf-8")
    password_file.chmod(0o600)
    subprocess.run(
        common + [
            "set-secret", "--secret", "control-db-password", "--from-file", str(password_file),
            "--non-interactive",
        ],
        check=True,
    )
    stored = state_dir / "secrets/global/control-db-password"
    assert stored.read_text(encoding="utf-8").strip() == "remote-database-password"
    assert stat.S_IMODE(stored.stat().st_mode) == 0o600


def test_three_node_example_builds_control_and_two_combined_pops(source_repo: Path, tmp_path: Path) -> None:
    import shutil
    import subprocess

    shutil.copytree(REPO_PATCH / "scripts", source_repo / "scripts", dirs_exist_ok=True)
    target = source_repo / "deploy/production/examples/three-node-production.sh"
    target.parent.mkdir(parents=True, exist_ok=True)
    shutil.copy2(REPO_PATCH / "deploy/production/examples/three-node-production.sh", target)
    target.chmod(0o755)
    state_dir = tmp_path / "three-node-state"
    env = {
        **os.environ,
        "STATE_DIR": str(state_dir),
        "OUTPUT_DIR": str(state_dir / "bundles"),
        "OPERATOR_DOMAIN": "ops.example.com",
        "PLATFORM_DOMAIN": "example.net",
        "RELEASE": "v1.0.0",
        "ENABLE_MONITORING": "1",
    }
    subprocess.run([str(target)], cwd=source_repo, env=env, check=True, text=True, capture_output=True)
    state = json.loads((state_dir / "fleet.json").read_text(encoding="utf-8"))
    assert len(state["nodes"]) == 3
    assert len([n for n in state["nodes"].values() if n["role"] == "control"]) == 1
    assert len([n for n in state["nodes"].values() if n["role"] == "dns-edge"]) == 2
    assert state["features"]["monitoring"]["mode"] == "colocated"
    assert state["features"]["logs"]["host"] == "control-1"


def test_quick_starts_document_manual_mtls_and_remote_postgres() -> None:
    root = Path(__file__).resolve().parents[2]
    small = (root / "docs/deployment/production-quick-start.md").read_text(encoding="utf-8")
    large = (root / "docs/deployment/production-quick-start-large-fleet.md").read_text(encoding="utf-8")
    assert "configure-edge-registration" in small
    assert "clear-edge-bootstrap-token" in small
    assert "edge-agent-state" in small
    assert "10 edge-only nodes" in large
    assert "4 DNS-only nodes" in large
    assert "3 monitoring nodes" in large
    assert "CONTROL_DB_MODE=embedded" in large
    assert "CONTROL_DB_MODE=remote" in large
    assert "set-secret --secret control-db-password" in large
