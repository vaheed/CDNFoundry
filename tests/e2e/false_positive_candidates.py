#!/usr/bin/env python3
"""False-positive candidates on real ClickHouse from a counted sample, read through the API.

Client keys, each with its events:
  aaaa: 6 rule blocks (r1) then a solved challenge          -> candidate (6 blocked, 1 solved, r1)
  bbbb: 3 rule blocks (r2) then 2 solved challenges          -> candidate (3, 2, r2)
  cccc: a solved challenge first, then 4 blocks              -> not a candidate (the block came after)
  dddd: only challenge prompts, then a solved challenge      -> not a candidate (being asked is not a block)
  eeee: 9 blocks and no solved challenge                     -> not a candidate
  ffff: blocks then a solve, on another domain               -> not a candidate here
The API must list exactly aaaa and bbbb, ordered by blocked requests, and refuse a stranger."""

import datetime as dt
import json
import subprocess
import urllib.parse
import uuid

import telemetry_analytics as base


def main() -> None:
    base.wait_for_api()
    domain_id, user, stranger, _ = base.create_scope()
    start = (dt.datetime.now(dt.timezone.utc) - dt.timedelta(hours=2)).replace(second=0, microsecond=0)
    rows: list[dict] = []

    def add(offset_seconds: int, key: str, count: int = 1, domain: int = domain_id, **fields: object) -> None:
        for index in range(count):
            rows.append({"occurred_at": (start + dt.timedelta(seconds=offset_seconds + index)).strftime("%Y-%m-%d %H:%M:%S.000"), "event_id": str(uuid.uuid4()), "domain_id": domain,
                         "hostname": "candidates.e2e.test", "method": "GET", "path": "/", "status": 403, "event_type": "request", "client_ip": "192.0.2.5",
                         "security_action": "block", "security_reason": "custom_rule_block", "security_rule": "r1", "client_key": key, **fields})

    solved = {"method": "POST", "path": "/.cdnf/challenge", "status": 204, "security_action": "allow", "security_reason": "challenge_solved", "security_rule": ""}
    prompt = {"security_reason": "challenge_required", "security_rule": ""}
    add(0, "aaaaaaaaaaaa", 6)
    add(60, "aaaaaaaaaaaa", **solved)
    add(0, "bbbbbbbbbbbb", 3, security_rule="r2")
    add(30, "bbbbbbbbbbbb", 2, **solved)
    add(0, "cccccccccccc", **solved)
    add(60, "cccccccccccc", 4)
    add(0, "dddddddddddd", 5, **prompt)
    add(30, "dddddddddddd", **solved)
    add(0, "eeeeeeeeeeee", 9)
    add(0, "ffffffffffff", 5, domain=domain_id + 1000)
    add(60, "ffffffffffff", domain=domain_id + 1000, **solved)
    payload = "\n".join(json.dumps(row) for row in rows)
    subprocess.run(["docker", "compose", "-f", "compose.dev.yml", "exec", "-T", "clickhouse", "clickhouse-client", "--query", "INSERT INTO cdnf.edge_events FORMAT JSONEachRow"],
                   input=payload, text=True, check=True, cwd=base.ROOT, capture_output=True)
    try:
        now = dt.datetime.now(dt.timezone.utc)
        window = urllib.parse.urlencode({"from": base.iso(now - dt.timedelta(hours=5)), "to": base.iso(now + dt.timedelta(seconds=30))})
        _, result = base.api("GET", f"/api/domains/{domain_id}/security/false-positive-candidates?{window}", token=user)
        found = [(row["client_key"], row["blocked"], row["solved"], row["top_rule"]) for row in result["data"]]
        assert found == [("aaaaaaaaaaaa", 6, 1, "r1"), ("bbbbbbbbbbbb", 3, 2, "r2")], found
        assert result["data"][0]["first_blocked"] < result["data"][0]["last_solved"], result["data"][0]
        assert set(result["meta"]["unavailable"]) == {"bot_breakdown", "reputation_breakdown"}, result["meta"]
        base.expect_error(404, "GET", f"/api/domains/{domain_id}/security/false-positive-candidates?{window}", token=stranger)
        print(f"candidates: {found}")
        print("false-positive candidate qualification passed")
    finally:
        base.clickhouse(f"ALTER TABLE cdnf.edge_events DELETE WHERE hostname = 'candidates.e2e.test' AND domain_id IN ({domain_id}, {domain_id + 1000}) SETTINGS mutations_sync = 1")


if __name__ == "__main__":
    main()
