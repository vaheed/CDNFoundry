#!/usr/bin/env python3
"""Attack episodes on real ClickHouse from a counted sample, read through the API.

Attack A: 300 blocked requests over 12 minutes, three client keys with 150, 100 and 50, rules r1 (200) and waf (100), a gap of four
minutes inside it. Attack B: 30 blocked requests in 3 minutes, 40 minutes later. Noise: 5 blocked requests (too few to count), 400
allowed requests, and another domain's blocks. The API must report exactly two episodes with these counts and nothing else."""

import datetime as dt
import json
import subprocess
import urllib.parse
import uuid

import telemetry_analytics as base

KEYS = [("aaaaaaaaaaaa", 150), ("bbbbbbbbbbbb", 100), ("cccccccccccc", 50)]


def main() -> None:
    base.wait_for_api()
    domain_id, user, stranger, _ = base.create_scope()
    start = (dt.datetime.now(dt.timezone.utc) - dt.timedelta(hours=3)).replace(second=0, microsecond=0)
    rows: list[dict] = []

    def add(minute: dt.datetime, count: int, domain: int = domain_id, **fields: object) -> None:
        for index in range(count):
            rows.append({"occurred_at": (minute + dt.timedelta(seconds=index % 50)).strftime("%Y-%m-%d %H:%M:%S.000"), "event_id": str(uuid.uuid4()), "domain_id": domain,
                         "hostname": "episodes.e2e.test", "method": "GET", "path": "/login", "status": 403, "event_type": "request", "client_ip": "192.0.2.5",
                         "security_action": "block", "security_reason": "custom_rule_block", "security_rule": "r1", "client_key": "aaaaaaaaaaaa", **fields})

    # Attack A: minutes 0 to 11 with a four-minute gap at 6 to 9, 25 blocked requests a minute, 300 in all.
    attack_minutes = [0, 1, 2, 3, 4, 5, 10, 11]
    plan = {minute: 0 for minute in attack_minutes}
    keys = [key for key, count in KEYS for _ in range(count // 25)]  # 6 + 4 + 2 slots of 25 requests
    slots = [(minute, slot) for minute in attack_minutes for slot in range(1 if minute not in (0, 1, 2, 3) else 2)]
    assert len(slots) == 12, len(slots)
    for (minute, _), key in zip(slots, keys):
        rule = "r1" if key != "cccccccccccc" else "waf"
        path = "/login" if key == "aaaaaaaaaaaa" else "/admin"
        add(start + dt.timedelta(minutes=minute), 25, client_key=key, security_rule=rule, path=path)
        plan[minute] += 25
    # Attack B and the noise.
    add(start + dt.timedelta(minutes=50), 10, client_key="dddddddddddd", path="/b")
    add(start + dt.timedelta(minutes=51), 10, client_key="dddddddddddd", path="/b")
    add(start + dt.timedelta(minutes=53), 10, client_key="dddddddddddd", path="/b")
    add(start - dt.timedelta(hours=1), 5, client_key="eeeeeeeeeeee")
    add(start + dt.timedelta(minutes=2), 400, security_action="allow", security_reason="", security_rule="", client_key="")
    add(start + dt.timedelta(minutes=3), 500, domain=domain_id + 1000)
    payload = "\n".join(json.dumps(row) for row in rows)
    subprocess.run(["docker", "compose", "-f", "compose.dev.yml", "exec", "-T", "clickhouse", "clickhouse-client", "--query", "INSERT INTO cdnf.edge_events FORMAT JSONEachRow"],
                   input=payload, text=True, check=True, cwd=base.ROOT, capture_output=True)
    try:
        now = dt.datetime.now(dt.timezone.utc)
        window = urllib.parse.urlencode({"from": base.iso(now - dt.timedelta(hours=5)), "to": base.iso(now + dt.timedelta(seconds=30))})
        _, result = base.api("GET", f"/api/domains/{domain_id}/security/episodes?{window}", token=user)
        episodes = result["data"]
        blocked_a = sum(plan.values())
        assert [episode["blocked"] for episode in episodes] == [30, blocked_a], [episode["blocked"] for episode in episodes]
        newest, oldest = episodes
        assert newest["duration_minutes"] == 4 and newest["peak_per_minute"] == 10, newest
        assert [(row["value"], row["blocked"]) for row in newest["top_client_keys"]] == [("dddddddddddd", 30)], newest["top_client_keys"]
        assert oldest["duration_minutes"] == 12 and oldest["peak_per_minute"] == 50, oldest
        assert [(row["value"], row["blocked"]) for row in oldest["top_client_keys"]] == [("aaaaaaaaaaaa", 150), ("bbbbbbbbbbbb", 100), ("cccccccccccc", 50)], oldest["top_client_keys"]
        assert [(row["value"], row["blocked"]) for row in oldest["top_rules"]] == [("r1", 250), ("waf", 50)], oldest["top_rules"]
        assert oldest["top_paths"] == [{"value": "/admin", "blocked": 150}, {"value": "/login", "blocked": 150}], oldest["top_paths"]

        _, clients = base.api("GET", f"/api/domains/{domain_id}/security/client-keys?{window}", token=user)
        assert [(row["value"], row["blocked"]) for row in clients["data"]]== [("aaaaaaaaaaaa", 150), ("bbbbbbbbbbbb", 100), ("cccccccccccc", 50), ("dddddddddddd", 30), ("eeeeeeeeeeee", 5)], clients["data"]
        base.expect_error(404, "GET", f"/api/domains/{domain_id}/security/episodes?{window}", token=stranger)
        print(f"episodes: {[episode['blocked'] for episode in episodes]} blocked requests")
        print("attack episode qualification passed")
    finally:
        base.clickhouse(f"ALTER TABLE cdnf.edge_events DELETE WHERE hostname = 'episodes.e2e.test' AND domain_id IN ({domain_id}, {domain_id + 1000}) SETTINGS mutations_sync = 1")


if __name__ == "__main__":
    main()
