#!/usr/bin/env python3
"""Qualify the split ClickHouse accounts on a real ClickHouse server.

The ingest account (Vector on every edge and DNS node) may only insert events.
The reader account (Laravel) is read-only and cannot lift its server-side limits.
"""
import subprocess
import sys
import time
import uuid
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
IMAGE = "clickhouse/clickhouse-server:26.8.7.19-alpine@sha256:2bece7c646f08865eadc2000a60427e2964adf2b46908a6d476dfa7cc5408720"
NAME = f"cdnf-clickhouse-roles-{uuid.uuid4().hex[:8]}"
PASSWORDS = {"cdnf": "roles-admin", "cdnf_ingest": "roles-ingest", "cdnf_reader": "roles-reader", "cdnf_zones": "roles-zones"}


def run(*command: str, check: bool = True) -> subprocess.CompletedProcess:
    result = subprocess.run(command, text=True, capture_output=True)
    if check and result.returncode != 0:
        raise RuntimeError(f"{' '.join(command[:4])} failed: {result.stderr.strip()}")
    return result


def query(user: str, sql: str, check: bool = True) -> subprocess.CompletedProcess:
    return run("docker", "exec", NAME, "clickhouse-client", "--user", user, "--password", PASSWORDS[user], "--query", sql, check=check)


def denied(user: str, sql: str) -> None:
    result = query(user, sql, check=False)
    assert result.returncode != 0, f"{user} was allowed to run: {sql}"


def main() -> int:
    try:
        run("docker", "run", "-d", "--name", NAME, "--network", "none", "--memory", "2g",
            "-e", "CLICKHOUSE_DB=cdnf", "-e", "CLICKHOUSE_USER=cdnf", "-e", f"CLICKHOUSE_PASSWORD={PASSWORDS['cdnf']}",
            "-e", "CLICKHOUSE_DEFAULT_ACCESS_MANAGEMENT=1", "-e", "GRAFANA_CLICKHOUSE_PASSWORD=roles-grafana",
            "-e", f"CLICKHOUSE_INGEST_PASSWORD={PASSWORDS['cdnf_ingest']}", "-e", f"CLICKHOUSE_READER_PASSWORD={PASSWORDS['cdnf_reader']}",
            "-e", f"CLICKHOUSE_ZONES_PASSWORD={PASSWORDS['cdnf_zones']}",
            "-v", f"{ROOT}/docker/clickhouse/init.sql:/docker-entrypoint-initdb.d/10-init.sql:ro",
            "-v", f"{ROOT}/docker/clickhouse/users.d/grafana.xml:/etc/clickhouse-server/users.d/grafana.xml:ro",
            "-v", f"{ROOT}/docker/clickhouse/users.d/telemetry.xml:/etc/clickhouse-server/users.d/telemetry.xml:ro", IMAGE)
        for _ in range(90):
            if query("cdnf", "SELECT count() FROM cdnf.edge_events", check=False).returncode == 0:
                break
            time.sleep(1)
        else:
            raise RuntimeError("ClickHouse did not initialize")

        # The image restarts the server once the init scripts have run; wait for the final one.
        for _ in range(90):
            if query("cdnf", "SELECT count() FROM system.dictionaries", check=False).returncode == 0:
                time.sleep(3)
                if query("cdnf", "SELECT count() FROM system.dictionaries", check=False).returncode == 0:
                    break
            time.sleep(1)
        for statement in (ROOT / "docker/clickhouse/migrations/2026_10_30_materialized_view_definer.sql").read_text().split(";"):
            if "ALTER" in statement:
                query("cdnf", statement.split("\n", 2)[-1] if statement.lstrip().startswith("--") else statement)
        insert = "INSERT INTO cdnf.edge_events (occurred_at, domain_id, hostname, method, path, status, bytes_out) VALUES (now(), 42, 'roles.test', 'GET', '/', 200, 123)"
        # The image restarts the server after running the init scripts.
        for _ in range(60):
            if query("cdnf_ingest", insert, check=False).returncode == 0:
                break
            time.sleep(1)
        else:
            raise RuntimeError("The ingest account could not insert events")
        query("cdnf_ingest", "INSERT INTO cdnf.dns_events (occurred_at, zone, qname, qtype, rcode) VALUES (now(), 'roles.test', 'roles.test', 'A', 'NOERROR')")
        denied("cdnf_ingest", "SELECT count() FROM cdnf.edge_events")
        denied("cdnf_ingest", "SELECT count() FROM system.users")
        denied("cdnf_ingest", "DROP TABLE cdnf.edge_events")
        denied("cdnf_ingest", "TRUNCATE TABLE cdnf.edge_events")
        denied("cdnf_ingest", "ALTER TABLE cdnf.edge_events DELETE WHERE 1")
        denied("cdnf_ingest", "CREATE USER intruder IDENTIFIED BY 'x'")
        denied("cdnf_ingest", "INSERT INTO cdnf.edge_hourly (interval_start) VALUES (now())")

        # Rollups are fed by materialized views; the ingest account must not need extra rights.
        deadline = time.time() + 20
        while time.time() < deadline and query("cdnf", "SELECT sum(requests) FROM cdnf.edge_hourly").stdout.strip() in ("", "0"):
            time.sleep(1)
        assert query("cdnf", "SELECT sum(requests) FROM cdnf.edge_hourly").stdout.strip() != "0", "rollup did not receive the insert"

        assert query("cdnf_reader", "SELECT count() FROM cdnf.edge_events").stdout.strip() == "1"
        query("cdnf_reader", "SELECT count() FROM cdnf.dns_hourly")
        denied("cdnf_reader", insert)
        denied("cdnf_reader", "DROP TABLE cdnf.edge_events")
        denied("cdnf_reader", "SELECT 1 SETTINGS readonly=0")
        denied("cdnf_reader", "SELECT 1 SETTINGS max_execution_time=3600")
        denied("cdnf_reader", "SELECT count() FROM system.users")

        # The zone publisher can add served zones and do nothing else.
        query("cdnf_zones", "INSERT INTO cdnf.served_zones (generation, zone, domain_id) VALUES (1, 'roles.test', 42)")
        denied("cdnf_zones", "SELECT count() FROM cdnf.served_zones")
        denied("cdnf_zones", "INSERT INTO cdnf.dns_events (occurred_at, zone, qname, qtype, rcode) VALUES (now(), 'x', 'x', 'A', 'NOERROR')")
        denied("cdnf_zones", "DROP TABLE cdnf.served_zones")
        denied("cdnf_zones", "TRUNCATE TABLE cdnf.served_zones")
        denied("cdnf_zones", "SELECT count() FROM system.users")
        # The dictionary user cannot be used from outside the server, and is not the ingest or reader path.
        assert run("docker", "exec", NAME, "clickhouse-client", "--user", "cdnf_dictionary", "--query", "SELECT 1", check=False).returncode == 0, "the dictionary user must work from inside"
        denied("cdnf_reader", "SELECT count() FROM cdnf.served_zones")
        query("cdnf_reader", "SELECT count() FROM cdnf.dns_top_names")
        print("clickhouse_roles: PASS")
        return 0
    finally:
        run("docker", "rm", "-f", NAME, check=False)


if __name__ == "__main__":
    sys.exit(main())
