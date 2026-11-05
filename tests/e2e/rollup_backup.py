#!/usr/bin/env python3
"""Real round trip of the usage-rollup backup. This does not automate a browser.

Starts the pinned ClickHouse and PostgreSQL images, creates the four rollup tables exactly as
`docker/clickhouse/init.sql` defines them, fills them, runs the control image's real `cdnf-backup-create`
into a local Restic repository, empties the tables (a lost telemetry host), and restores with the real
`cdnf-backup-restore-rollups`. It also proves that a ClickHouse outage never stops the control backup, and
that the restore refuses to load over existing rows.

Needs Docker and the image a core image tagged ghcr.io/vaheed/cdnfoundry-core:ha-rollup (docker buildx bake core --load). It creates only
`cdnf-rollup-*` containers, a network, and a throwaway volume-free temporary directory.
"""
from __future__ import annotations

import pathlib
import re
import subprocess
import tempfile
import time

ROOT = pathlib.Path(__file__).resolve().parents[2]
COMPOSE = (ROOT / "compose.prod.yml").read_text(encoding="utf-8")
CORE = "ghcr.io/vaheed/cdnfoundry-core:ha-rollup"
NETWORK = "cdnf-rollup"
TABLES = ("edge_hourly", "dns_hourly", "edge_daily", "dns_daily")


def pinned(prefix: str) -> str:
    match = re.search(rf"image: ({re.escape(prefix)}[^\s]+@sha256:[0-9a-f]+)", COMPOSE)
    assert match, prefix
    return match.group(1)


def docker(*arguments: str, check: bool = True) -> subprocess.CompletedProcess[str]:
    result = subprocess.run(("docker", *arguments), text=True, capture_output=True, check=False)
    if check and result.returncode:
        raise RuntimeError(f"docker {' '.join(arguments)} failed:\n{result.stdout}\n{result.stderr}")
    return result


def clickhouse(sql: str) -> str:
    return docker("exec", "cdnf-rollup-clickhouse", "clickhouse-client", "--user", "default", "--password", "lab-secret", "--query", sql).stdout.strip()


def table_statements() -> list[str]:
    """The four CREATE TABLE statements, verbatim from the shipped schema."""
    init = (ROOT / "docker/clickhouse/init.sql").read_text(encoding="utf-8")
    statements = []
    for table in TABLES:
        match = re.search(rf"(CREATE TABLE IF NOT EXISTS cdnf\.{table}\b.*?;)\s*(?:\n\n|\n--|\nCREATE|\Z)", init, re.S)
        assert match, table
        statements.append(match.group(1).rstrip(";"))
    return statements


def core(script: str, environment: dict[str, str], directory: pathlib.Path) -> subprocess.CompletedProcess[str]:
    command = ["run", "--rm", "--network", NETWORK, "--user", "root", "-v", f"{directory}:/lab", "--entrypoint", "sh",
               "-e", "RESTIC_REPOSITORY=/lab/repository", "-e", "RESTIC_PASSWORD_FILE=/lab/password",
               "-e", "PGHOST=cdnf-rollup-postgres", "-e", "PGUSER=cdnf", "-e", "PGPASSWORD=lab", "-e", "PGDATABASE=cdnf"]
    for key, value in environment.items():
        command += ["-e", f"{key}={value}"]
    return docker(*command, CORE, "-c", script, check=False)


def main() -> int:
    directory = pathlib.Path(tempfile.mkdtemp(prefix="cdnf-rollup-"))
    directory.chmod(0o755)
    (directory / "password").write_text("rollup-lab-password\n")
    docker("network", "create", NETWORK, check=False)
    try:
        docker("run", "-d", "--name", "cdnf-rollup-postgres", "--network", NETWORK, "-e", "POSTGRES_USER=cdnf", "-e", "POSTGRES_PASSWORD=lab", "-e", "POSTGRES_DB=cdnf", pinned("postgres:"))
        docker("run", "-d", "--name", "cdnf-rollup-clickhouse", "--network", NETWORK, "-e", "CLICKHOUSE_PASSWORD=lab-secret", "-e", "CLICKHOUSE_DEFAULT_ACCESS_MANAGEMENT=1", pinned("clickhouse/clickhouse-server:"))
        for _ in range(60):
            if docker("exec", "cdnf-rollup-clickhouse", "clickhouse-client", "--user", "default", "--password", "lab-secret", "--query", "select 1", check=False).returncode == 0 \
                    and docker("exec", "cdnf-rollup-postgres", "pg_isready", "-U", "cdnf", check=False).returncode == 0:
                break
            time.sleep(2)
        clickhouse("CREATE DATABASE IF NOT EXISTS cdnf")
        for statement in table_statements():
            clickhouse(statement)
        clickhouse("INSERT INTO cdnf.edge_hourly (interval_start, domain_id, hostname, status, cache_status, country, continent, edge_id, requests, bytes_in, bytes_out, origin_latency_sum, origin_latency_samples, origin_errors, tls_failures, security_blocks) "
                   "SELECT toStartOfHour(now()) - INTERVAL number HOUR, 7, 'a.example', 200, 'HIT', 'DE', 'EU', 'edge-1', 10 + number, 100, 2000, 5, 1, 0, 0, 0 FROM numbers(24)")
        clickhouse("INSERT INTO cdnf.dns_hourly SELECT toStartOfHour(now()) - INTERVAL number HOUR, 7, 'a.example', 'A', 'NOERROR', 'DE', 'EU', 'dns-1', 40 + number FROM numbers(24)")
        clickhouse("INSERT INTO cdnf.edge_daily SELECT toStartOfDay(interval_start), domain_id, hostname, status, cache_status, country, continent, edge_id, sum(requests), sum(bytes_in), sum(bytes_out), sum(origin_latency_sum), sum(origin_latency_samples), sum(origin_errors), sum(tls_failures), sum(security_blocks) FROM cdnf.edge_hourly GROUP BY ALL")
        before = {table: clickhouse(f"SELECT sum(requests) FROM cdnf.{table}") if "edge" in table else clickhouse(f"SELECT sum(queries) FROM cdnf.{table}") for table in ("edge_hourly", "dns_hourly", "edge_daily")}
        assert all(int(value) > 0 for value in before.values()), before

        environment = {"CLICKHOUSE_URL": "http://cdnf-rollup-clickhouse:8123", "CLICKHOUSE_USER": "default", "CLICKHOUSE_PASSWORD": "lab-secret"}
        init = core("restic init >/dev/null && cdnf-backup-create", environment, directory)
        assert init.returncode == 0, init.stdout + init.stderr
        snapshots = core("restic snapshots --json", {}, directory).stdout
        assert "cdnfoundry-rollups" in snapshots and "cdnfoundry-control" in snapshots, snapshots
        print("   backup created both a rollup snapshot and a control snapshot")

        outage_directory = pathlib.Path(tempfile.mkdtemp(prefix="cdnf-rollup-outage-"))
        outage_directory.chmod(0o755)
        (outage_directory / "password").write_text("rollup-lab-password\n")
        docker("stop", "cdnf-rollup-clickhouse")
        outage = core("restic init >/dev/null && cdnf-backup-create", environment, outage_directory)
        assert outage.returncode == 0, "a ClickHouse outage must not stop the control backup:\n" + outage.stdout + outage.stderr
        outage_snapshots = core("restic snapshots --json", {}, outage_directory).stdout
        assert "cdnfoundry-control" in outage_snapshots and "cdnfoundry-rollups" not in outage_snapshots, outage_snapshots
        print("   with ClickHouse down the control backup still succeeded")
        docker("start", "cdnf-rollup-clickhouse")
        for _ in range(30):
            if docker("exec", "cdnf-rollup-clickhouse", "clickhouse-client", "--user", "default", "--password", "lab-secret", "--query", "select 1", check=False).returncode == 0:
                break
            time.sleep(2)

        # A newer snapshot whose export was cut short must never be restored.
        cut = core("printf -- '-- table: edge_hourly\\n{\"interval_start\":\"2026-01-01 00:00:00\",\"domain_id\":\"9\"}\\n' | restic backup --stdin --stdin-filename rollups.jsonl --tag cdnfoundry-rollups --json", {}, directory)
        assert cut.returncode == 0, cut.stdout + cut.stderr
        incomplete_id = re.findall(r'"snapshot_id":"([a-f0-9]+)"', cut.stdout)[-1]

        restore_environment = {"CLICKHOUSE_URL": "http://cdnf-rollup-clickhouse:8123", "CLICKHOUSE_RESTORE_USER": "default", "CLICKHOUSE_RESTORE_PASSWORD": "lab-secret"}
        refused = core("cdnf-backup-restore-rollups latest", restore_environment, directory)
        assert refused.returncode != 0 and "refusing to load over it" in refused.stderr, refused.stdout + refused.stderr
        explicit = core(f"cdnf-backup-restore-rollups {incomplete_id}", restore_environment, directory)
        assert explicit.returncode != 0 and "not a complete rollup snapshot" in explicit.stderr, explicit.stdout + explicit.stderr
        for table in TABLES:
            clickhouse(f"TRUNCATE TABLE cdnf.{table}")
        restored = core("cdnf-backup-restore-rollups latest", restore_environment, directory)
        assert "incomplete; skipping it" in restored.stderr, restored.stderr
        assert restored.returncode == 0, restored.stdout + restored.stderr
        after = {table: clickhouse(f"SELECT sum(requests) FROM cdnf.{table}") if "edge" in table else clickhouse(f"SELECT sum(queries) FROM cdnf.{table}") for table in ("edge_hourly", "dns_hourly", "edge_daily")}
        assert after == before, (before, after)
        print("   the rollups were restored into empty tables with identical totals; a second restore was refused")
    finally:
        for name in ("clickhouse", "postgres"):
            docker("rm", "-f", f"cdnf-rollup-{name}", check=False)
        docker("network", "rm", NETWORK, check=False)
    print("rollup_backup=passed")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
