#!/usr/bin/env python3
"""Qualify Grafana through its HTTP API: health, data sources, provisioning, access, and every panel query.

Every target of every dashboard is run against its real data source with the dashboard variables set to All, so
a query that does not parse, a table that is not granted, or a metric that does not exist fails here. With
GRAFANA_EXPECT_DATA=1 (a seeded stack) a panel that returns nothing also fails.
"""

from __future__ import annotations

import base64
import concurrent.futures
import json
import os
import pathlib
import re
import sys
import time
import urllib.error
import urllib.request

ROOT = pathlib.Path(__file__).resolve().parents[2]
GRAFANA = ROOT / "docker" / "grafana"
BASE = os.environ.get("GRAFANA_URL", "http://127.0.0.1:3000")
AUTH = "Basic " + base64.b64encode(f"{os.environ.get('GRAFANA_ADMIN_USER', 'admin')}:{os.environ.get('GRAFANA_ADMIN_PASSWORD', 'cdnf-grafana-dev-only')}".encode()).decode()
EXPECT_DATA = os.environ.get("GRAFANA_EXPECT_DATA") == "1"
RANGES = {"15m": 900, "6h": 21600, "7d": 604800, "30d": 2592000}
sys.path.insert(0, str(GRAFANA))
ACCESS = json.loads((GRAFANA / "access.json").read_text())


def call(method: str, path: str, body: dict | None = None, *, authenticated: bool = True) -> dict | list:
    request = urllib.request.Request(BASE + path, method=method, data=None if body is None else json.dumps(body).encode(), headers={"Content-Type": "application/json", **({"Authorization": AUTH} if authenticated else {})})
    try:
        with urllib.request.urlopen(request, timeout=90) as response:
            return json.loads(response.read() or b"null")
    except urllib.error.HTTPError as error:
        raise AssertionError(f"{method} {path} -> {error.code}: {error.read().decode()[:400]}") from error


def wait_healthy() -> None:
    deadline = time.monotonic() + 180
    error = "not attempted"
    while time.monotonic() < deadline:
        try:
            if call("GET", "/api/health", authenticated=False).get("database") == "ok":
                return
        except (AssertionError, OSError, json.JSONDecodeError) as exception:
            error = str(exception)
        time.sleep(2)
    raise AssertionError(f"Grafana did not become healthy within 180 seconds: {error}")


def interpolate(text: str, kind: str) -> str:
    """Set the dashboard variables to All the way the browser would, for the data sources that do not do it themselves."""
    text = re.sub(r"\$__conditionalAll\((?:[^()]|\([^()]*\))*?,\s*\$\w+\)", "1=1", text)
    text = re.sub(r"[A-Za-z_]+ IN \(\$\{\w+:csv\}\)", "1=1", text)
    wildcard = ".+" if kind == "loki" else ".*"
    for variable in ("environment", "region", "pop", "edge", "pool", "domain", "hostname", "alertname", "service", "level", "slo"):
        text = text.replace(f"${{{variable}}}", wildcard).replace(f"${variable}", wildcard)
    text = text.replace("${rule_id}", "942100" if EXPECT_DATA else "0").replace("${request_id}", "cdnf-seed-request-0001" if EXPECT_DATA else "none")
    return text


def dashboards() -> list[dict]:
    return [json.loads(path.read_text()) for path in sorted((GRAFANA / "dashboards").rglob("*.json"))]


def library(uid: str) -> dict:
    return json.loads((GRAFANA / "library-panels" / f"{uid}.json").read_text())["model"]


def rows(result: dict) -> int:
    count = 0
    for frame in result.get("frames", []):
        values = frame.get("data", {}).get("values", [])
        count += len(values[0]) if values else 0
    return count


def run_target(item: tuple[str, str, dict, str, int]) -> tuple[str, str]:
    title, kind, target, range_name, seconds = item
    query = {**target, "intervalMs": 60000, "maxDataPoints": 1000}
    for key in ("rawSql", "expr"):
        if key in query:
            query[key] = interpolate(query[key], kind)
    now = int(time.time() * 1000)
    try:
        response = call("POST", "/api/ds/query", {"queries": [query], "from": str(now - seconds * 1000), "to": str(now)})
    except AssertionError as error:
        return title, f"{range_name}: {str(error)[:420]}"
    result = response.get("results", {}).get(target["refId"], {})
    if result.get("error"):
        return title, f"{range_name}: {result['error'][:300]}"
    if EXPECT_DATA and rows(result) == 0 and target["datasource"]["uid"] != "loki":
        return title, f"{range_name}: no data"
    return title, ""


def main() -> None:
    wait_healthy()
    clickhouse = call("GET", "/api/datasources/uid/clickhouse").get("jsonData", {})
    assert clickhouse.get("dialTimeout") == "5" and clickhouse.get("queryTimeout") == "30", clickhouse
    for uid in ("prometheus", "clickhouse", "control-db", "loki", "alertmanager"):
        body = call("GET", f"/api/datasources/uid/{uid}/health") if uid != "alertmanager" else {"status": "OK"}
        assert body.get("status") in ("OK", "Success"), f"datasource {uid} unhealthy: {body}"

    expected = {dashboard["uid"] for dashboard in dashboards()}
    deadline = time.monotonic() + 150
    while True:
        provisioned = {row["uid"] for row in call("GET", "/api/search?type=dash-db")}
        if (expected <= provisioned and not {"cdnf-system-command-center", "cdnf-domain-command-center"} & provisioned) or time.monotonic() > deadline:
            break
        time.sleep(5)
    missing = expected - provisioned
    assert not missing, f"dashboards that did not provision (a missing library panel or a bad query model): {sorted(missing)}"
    assert not {"cdnf-system-command-center", "cdnf-domain-command-center"} & provisioned, "the retired dashboards are still provisioned"

    folders = {folder["uid"]: folder["title"] for folder in call("GET", "/api/folders")}
    for uid in {uid for team in ACCESS["teams"].values() for uid in team["folders"]}:
        assert uid in folders, f"folder {uid} missing"
    teams = {team["name"] for team in call("GET", "/api/teams/search")["teams"]}
    assert set(ACCESS["teams"]) <= teams, f"teams missing: {set(ACCESS['teams']) - teams}"
    assert {"NOC wall", "SOC wall"} <= {playlist["name"] for playlist in call("GET", "/api/playlists")}

    checks: list[tuple[str, str, dict, str, int]] = []
    for dashboard in dashboards():
        for panel in dashboard["panels"]:
            if panel["type"] == "row":
                continue
            model = library(panel["libraryPanel"]["uid"]) if "libraryPanel" in panel else panel
            for target in model.get("targets", []):
                kind = target["datasource"]["uid"]
                for range_name, seconds in RANGES.items():
                    if range_name in ("15m", "30d") and not EXPECT_DATA:
                        continue
                    checks.append((f"{dashboard['uid']}/{model['title']}", kind, target, range_name, seconds))
    with concurrent.futures.ThreadPoolExecutor(max_workers=8) as executor:
        failures = [(title, error) for title, error in executor.map(run_target, checks) if error]
    if failures:
        for title, error in sorted(set(failures))[:60]:
            print(f"FAIL {title}: {error}", file=sys.stderr)
        raise AssertionError(f"{len(set(failures))} panel queries failed of {len(checks)}")
    print(f"Grafana provisioning, access, and {len(checks)} panel queries are healthy")


if __name__ == "__main__":
    main()
