#!/usr/bin/env python3
"""Security analytics on real ClickHouse: a counted sample of requests, queried through the API, must match exactly."""

import datetime as dt
import json
import subprocess
import urllib.parse
import uuid

import telemetry_analytics as base


def sample_rows(domain_id: int) -> list[dict]:
    now = dt.datetime.now(dt.timezone.utc) - dt.timedelta(minutes=5)
    rows: list[dict] = []

    def add(count: int, **fields: object) -> None:
        for _ in range(count):
            rows.append({
                "occurred_at": now.strftime("%Y-%m-%d %H:%M:%S.000"), "event_id": str(uuid.uuid4()), "domain_id": domain_id, "hostname": "sec.e2e.test",
                "method": "GET", "status": 200, "event_type": "request", "client_ip": "192.0.2.10", "security_action": "allow", "security_reason": "",
                "security_rule": "", "attack_score": 0, **fields,
            })

    add(40, path="/a", user_agent="curl/8", country="DE", asn=64500)
    add(30, path="/b", user_agent="Mozilla/5.0", country="US", asn=15169, attack_score=95)
    add(15, path="/search", user_agent="sqlmap/1.7", country="US", asn=15169, attack_score=4, status=403, security_action="block", security_reason="waf_block", security_rule="waf")
    add(10, path="/admin", user_agent="curl/8", country="DE", asn=64500, status=403, security_action="block", security_reason="custom_rule_block", security_rule="r1")
    add(5, path="/watch", user_agent="Mozilla/5.0", country="FR", asn=0, attack_score=55, security_reason="custom_rule_log", security_rule="r2")
    return rows


def main() -> None:
    base.wait_for_api()
    domain_id, user, stranger, _ = base.create_scope()
    rows = sample_rows(domain_id)
    payload = "\n".join(json.dumps(row) for row in rows)
    subprocess.run(["docker", "compose", "-f", "compose.dev.yml", "exec", "-T", "clickhouse", "clickhouse-client", "--query", "INSERT INTO cdnf.edge_events FORMAT JSONEachRow"],
                   input=payload, text=True, check=True, cwd=base.ROOT, capture_output=True)
    base.wait_for_clickhouse(lambda value: value == "100", f"SELECT sum(requests) FROM cdnf.security_hourly WHERE domain_id = {domain_id}")

    now = dt.datetime.now(dt.timezone.utc)
    query = urllib.parse.urlencode({"from": base.iso(now - dt.timedelta(hours=2)), "to": base.iso(now + dt.timedelta(seconds=30))})

    def view(name: str) -> list[dict]:
        _, result = base.api("GET", f"/api/domains/{domain_id}/analytics/security/{name}?{query}", token=user)
        return [{key: (int(value) if str(value).isdigit() else value) for key, value in row.items()} for row in result["data"]]

    assert view("bands") == [
        {"attack_band": 0, "requests": 50, "mitigated": 10}, {"attack_band": 1, "requests": 15, "mitigated": 15},
        {"attack_band": 3, "requests": 5, "mitigated": 5}, {"attack_band": 5, "requests": 30, "mitigated": 0},
    ], view("bands")
    assert view("actions") == [{"action": "allowed", "requests": 70, "mitigated": 0}, {"action": "blocked", "requests": 25, "mitigated": 25}, {"action": "logged", "requests": 5, "mitigated": 5}], view("actions")
    assert view("countries") == [{"country": "US", "requests": 45, "mitigated": 15}, {"country": "DE", "requests": 50, "mitigated": 10}, {"country": "FR", "requests": 5, "mitigated": 5}], view("countries")
    assert view("asns") == [{"asn": 15169, "requests": 45, "mitigated": 15}, {"asn": 64500, "requests": 50, "mitigated": 10}, {"asn": 0, "requests": 5, "mitigated": 5}], view("asns")
    assert view("paths")[:3] == [{"path": "/search", "requests": 15, "mitigated": 15}, {"path": "/admin", "requests": 10, "mitigated": 10}, {"path": "/watch", "requests": 5, "mitigated": 5}], view("paths")
    agents = {row["user_agent"]: row for row in view("agents")}
    assert agents["sqlmap/1.7"]["mitigated"] == 15 and agents["curl/8"] == {"user_agent": "curl/8", "requests": 50, "mitigated": 10} and agents["Mozilla/5.0"]["requests"] == 35, agents
    # The totals agree with each other and with the sample, and another user's domain stays out of reach.
    assert sum(row["requests"] for row in view("bands")) == sum(row["requests"] for row in view("countries")) == len(rows)
    base.expect_error(404, "GET", f"/api/domains/{domain_id}/analytics/security/bands?{query}", token=stranger)
    # The list of mitigated requests holds exactly the 30 blocked or logged ones, newest first, with their rules.
    _, mitigated = base.api("GET", f"/api/domains/{domain_id}/security/mitigations", token=user)
    assert len(mitigated["data"]) == 30 and {item["rule"]["ref"] for item in mitigated["data"]} == {"waf", "r1", "r2"}, mitigated["meta"]
    _, filtered = base.api("GET", f"/api/domains/{domain_id}/security/mitigations?action=blocked&score_max=10&country=US", token=user)
    assert len(filtered["data"]) == 15 and all(item["rule"]["type"] == "managed" for item in filtered["data"]), filtered
    print("security analytics query qualification passed")


if __name__ == "__main__":
    main()
