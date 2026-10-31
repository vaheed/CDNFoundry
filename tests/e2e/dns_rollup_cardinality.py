#!/usr/bin/env python3
"""A random-subdomain flood adds a bounded number of DNS rollup rows, attributed to the right zone."""

import subprocess
import sys
import time
import uuid
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
IMAGE = "clickhouse/clickhouse-server:26.8.7.19-alpine@sha256:2bece7c646f08865eadc2000a60427e2964adf2b46908a6d476dfa7cc5408720"
NAME = f"cdnf-dns-rollups-{uuid.uuid4().hex[:8]}"
PASSWORDS = {"cdnf": "rollups-admin", "cdnf_ingest": "rollups-ingest", "cdnf_reader": "rollups-reader", "cdnf_zones": "rollups-zones"}
FLOOD = 200_000


def run(*command: str, check: bool = True) -> subprocess.CompletedProcess:
    result = subprocess.run(command, text=True, capture_output=True, timeout=300)
    if check and result.returncode != 0:
        raise RuntimeError(f"{' '.join(command[:5])} failed: {result.stderr.strip()[:400]}")
    return result


def query(user: str, sql: str) -> str:
    return run("docker", "exec", NAME, "clickhouse-client", "--user", user, "--password", PASSWORDS[user], "--query", sql).stdout.strip()


def events(qname_sql: str, rcode: str, count: int) -> None:
    query("cdnf_ingest", (
        "INSERT INTO cdnf.dns_events (occurred_at, zone, qname, qtype, rcode, client_ip, dns_cluster, country, continent, outcome) "
        f"SELECT now(), '', {qname_sql}, 'A', '{rcode}', '192.0.2.1', 'node-1', 'DE', 'EU', 'answered' FROM numbers({count})"))


def publish(generation: int, zones: dict[str, int]) -> None:
    rows = ", ".join(f"({generation}, '{zone}', {domain})" for zone, domain in zones.items())
    query("cdnf_zones", f"INSERT INTO cdnf.served_zones (generation, zone, domain_id) VALUES {rows}")
    query("cdnf", "SYSTEM RELOAD DICTIONARY cdnf.served_zone_dictionary")


def main() -> int:
    try:
        run("docker", "run", "-d", "--name", NAME, "--network", "none", "--memory", "3g",
            "-e", "CLICKHOUSE_DB=cdnf", "-e", "CLICKHOUSE_USER=cdnf", "-e", f"CLICKHOUSE_PASSWORD={PASSWORDS['cdnf']}",
            "-e", "CLICKHOUSE_DEFAULT_ACCESS_MANAGEMENT=1", "-e", "GRAFANA_CLICKHOUSE_PASSWORD=rollups-grafana",
            "-e", f"CLICKHOUSE_INGEST_PASSWORD={PASSWORDS['cdnf_ingest']}", "-e", f"CLICKHOUSE_READER_PASSWORD={PASSWORDS['cdnf_reader']}",
            "-e", f"CLICKHOUSE_ZONES_PASSWORD={PASSWORDS['cdnf_zones']}",
            "-v", f"{ROOT}/docker/clickhouse/init.sql:/docker-entrypoint-initdb.d/10-init.sql:ro",
            "-v", f"{ROOT}/docker/clickhouse/users.d/grafana.xml:/etc/clickhouse-server/users.d/grafana.xml:ro",
            "-v", f"{ROOT}/docker/clickhouse/users.d/telemetry.xml:/etc/clickhouse-server/users.d/telemetry.xml:ro", IMAGE)
        for _ in range(120):
            probe = run("docker", "exec", NAME, "clickhouse-client", "--user", "cdnf", "--password", PASSWORDS["cdnf"], "--query", "SELECT count() FROM system.dictionaries", check=False)
            if probe.returncode == 0:
                time.sleep(3)
                if run("docker", "exec", NAME, "clickhouse-client", "--user", "cdnf", "--password", PASSWORDS["cdnf"], "--query", "SELECT 1", check=False).returncode == 0:
                    break
            time.sleep(1)
        else:
            raise RuntimeError("ClickHouse did not initialize")

        # A generation is the Unix time of the snapshot, which the table's TTL relies on.
        first = int(time.time())
        publish(first, {"example.com": 7, "sub.example.com": 8, "other.org": 9})

        # A flood of random names under two served zones, and a flood of names nobody here serves.
        events("concat(toString(rand()), '.', toString(rand()), '.example.com.')", "NXDOMAIN", FLOOD)
        events("concat(toString(rand()), '.sub.example.com.')", "NXDOMAIN", FLOOD)
        events("concat(toString(rand()), '.', toString(rand()), '.victim-elsewhere.net.')", "REFUSED", FLOOD)
        events("'www.example.com.'", "NOERROR", 1000)
        events("'WWW.Other.ORG'", "NOERROR", 10)

        total = int(query("cdnf", "SELECT sum(queries) FROM cdnf.dns_hourly"))
        rows = int(query("cdnf", "SELECT count() FROM cdnf.dns_hourly"))
        assert total == 3 * FLOOD + 1010, f"every event must be counted exactly once, got {total}"
        assert rows <= 8, f"{3 * FLOOD} random names must add a bounded number of rollup rows, got {rows}"

        per_zone = {line.split("\t")[0]: line.split("\t")[1:] for line in query(
            "cdnf", "SELECT concat('z:', zone), domain_id, sum(queries) FROM cdnf.dns_hourly GROUP BY zone, domain_id ORDER BY zone").splitlines()}
        assert per_zone["z:example.com"] == ["7", str(FLOOD + 1000)], per_zone
        assert per_zone["z:sub.example.com"] == ["8", str(FLOOD)], "the longest served suffix wins, so the flood is charged to the subzone"
        assert per_zone["z:other.org"] == ["9", "10"], per_zone
        assert per_zone["z:"] == ["0", str(FLOOD)], "names inside no served zone share one unattributed bucket"

        names = int(query("cdnf", "SELECT sum(length(top)) FROM (SELECT topKMerge(20)(names) AS top FROM cdnf.dns_top_names GROUP BY domain_id, zone, interval_start)"))
        assert names <= 60, f"the top-name sketch must stay bounded, got {names}"
        top = query("cdnf_reader", "SELECT arrayJoin(top) FROM (SELECT topKMerge(20)(names) AS top FROM cdnf.dns_top_names WHERE domain_id = 7 GROUP BY domain_id, zone, interval_start)")
        assert "www.example.com" in top, "a name that is really asked must be among the busiest"

        # A zone that leaves the next snapshot stops being matched.
        publish(first + 1, {"example.com": 7})
        events("'x.sub.example.com.'", "NOERROR", 5)
        assert query("cdnf", "SELECT sum(queries) FROM cdnf.dns_hourly WHERE zone = 'sub.example.com'") == str(FLOOD), "a removed zone must stop receiving queries"
        assert query("cdnf", "SELECT sum(queries) FROM cdnf.dns_hourly WHERE zone = 'example.com'") == str(FLOOD + 1000 + 5)
        print(f"dns_rollup_cardinality: PASS ({3 * FLOOD + 1010} events became {rows} rollup rows)")
        return 0
    finally:
        run("docker", "rm", "-f", NAME, check=False)


if __name__ == "__main__":
    sys.exit(main())
