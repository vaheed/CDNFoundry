#!/usr/bin/env python3
"""Non-browser Grafana HTTP API smoke qualification for the development stack."""

from __future__ import annotations

import base64
import concurrent.futures
import json
import pathlib
import subprocess
import time


ROOT = pathlib.Path(__file__).resolve().parents[2]
BASE = "http://127.0.0.1:3000"
AUTH = "Basic " + base64.b64encode(b"admin:cdnf-grafana-dev-only").decode()


def get(path: str, authenticated: bool = True) -> dict:
    command = [
        "docker", "compose", "-f", "compose.dev.yml", "exec", "-T", "grafana",
        "wget", "-qO-",
    ]
    if authenticated:
        command.append(f"--header=Authorization: {AUTH}")
    command.append(BASE + path)
    result = subprocess.run(command, cwd=ROOT, text=True, capture_output=True, check=False)
    if result.returncode != 0:
        raise AssertionError(f"Grafana API request failed for {path}: {result.stderr.strip()}")
    return json.loads(result.stdout)


def post(path: str, body: dict) -> dict:
    command = [
        "docker", "compose", "-f", "compose.dev.yml", "exec", "-T", "grafana",
        "wget", "-qO-", f"--header=Authorization: {AUTH}",
        "--header=Content-Type: application/json", "--post-data=" + json.dumps(body), BASE + path,
    ]
    result = subprocess.run(command, cwd=ROOT, text=True, capture_output=True, check=False)
    if result.returncode != 0:
        raise AssertionError(f"Grafana API POST failed for {path}: {result.stderr.strip()}")
    return json.loads(result.stdout)


def wait_healthy() -> dict:
    deadline = time.monotonic() + 120
    error = "not attempted"
    while time.monotonic() < deadline:
        try:
            health = get("/api/health", authenticated=False)
            if health.get("database") == "ok":
                return health
            error = str(health)
        except (AssertionError, json.JSONDecodeError) as exception:
            error = str(exception)
        time.sleep(2)
    raise AssertionError(f"Grafana did not become healthy within 120 seconds: {error}")


def main() -> None:
    wait_healthy()
    clickhouse_source = get("/api/datasources/uid/clickhouse")
    clickhouse_settings = clickhouse_source.get("jsonData", {})
    if clickhouse_settings.get("dialTimeout") != "5":
        raise AssertionError(f"unexpected ClickHouse dial timeout: {clickhouse_settings}")
    if clickhouse_settings.get("queryTimeout") != "30":
        raise AssertionError(f"unexpected ClickHouse query timeout: {clickhouse_settings}")
    for uid in ("prometheus", "clickhouse", "control-db"):
        body = get(f"/api/datasources/uid/{uid}/health")
        if body.get("status") not in ("OK", "Success"):
            raise AssertionError(f"datasource {uid} unhealthy: {body}")
    checks: list[tuple[str, dict, str]] = []
    search = get("/api/search?type=dash-db")
    provisioned_uids = {row.get("uid") for row in search}
    expected_uids = {"cdnf-system-command-center", "cdnf-domain-command-center"}
    if provisioned_uids != expected_uids:
        raise AssertionError(f"expected exactly two dashboards, found {provisioned_uids}")
    for uid in ("cdnf-system-command-center", "cdnf-domain-command-center"):
        body = get(f"/api/dashboards/uid/{uid}")
        if body.get("dashboard", {}).get("uid") != uid:
            raise AssertionError(f"dashboard {uid} unavailable: {body}")
        for panel in body["dashboard"].get("panels", []):
            candidates = panel.get("panels", []) if panel.get("type") == "row" else [panel]
            for candidate in candidates:
                source = candidate.get("datasource", {})
                if source.get("uid") not in ("clickhouse", "control-db"):
                    continue
                for target in candidate.get("targets", []):
                    if "rawSql" not in target:
                        continue
                    query = {
                        **target,
                        "rawSql": target["rawSql"].replace("${domain_id:raw}", "1"),
                        "datasource": source,
                        "intervalMs": 60000,
                        "maxDataPoints": 1000,
                    }
                    # A 24-hour request exercises the same plugin timeout path as
                    # the normal browser dashboard. This caught ClickHouse code
                    # 452 when queryTimeout was left above the account ceiling.
                    payload = {
                        "from": str(int((time.time() - 86400) * 1000)),
                        "to": str(int(time.time() * 1000)),
                        "queries": [query],
                    }
                    checks.append((candidate.get("title", "untitled"), payload, target.get("refId", "A")))

    def check_panel(item: tuple[str, dict, str]) -> None:
        title, payload, ref_id = item
        response = post("/api/ds/query", payload)
        result = response.get("results", {}).get(ref_id, {})
        if result.get("error"):
            raise AssertionError(f"panel query failed: {title}: {result['error']}")

    with concurrent.futures.ThreadPoolExecutor(max_workers=8) as executor:
        list(executor.map(check_panel, checks))
    print("Grafana health, datasources, dashboard UIDs, and provisioned SQL queries are healthy")


if __name__ == "__main__":
    main()
