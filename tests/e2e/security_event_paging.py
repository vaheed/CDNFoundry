#!/usr/bin/env python3
"""Security events paging on real ClickHouse: 1,000,000 mitigated requests in 24 hours, read page by page through the API.

Every page must answer under one second, a walk must neither repeat nor skip an event, and a page from the middle of the
attack must equal what ClickHouse itself returns after the same cursor. The rows belong to one generated test domain and are
deleted at the end."""

import base64
import datetime as dt
import json
import statistics
import time

import telemetry_analytics as base

TOTAL = 1_000_000
PAGE = 100
WALKED_PAGES = 300
PAGE_BUDGET_SECONDS = 1.0


def cursor_for(occurred_at: str, event_id: str) -> str:
    raw = json.dumps({"occurred_at": occurred_at, "event_id": event_id}, separators=(",", ":")).encode()
    return base64.urlsafe_b64encode(raw).decode().rstrip("=")


def main() -> None:
    base.wait_for_api()
    domain_id, user, stranger, _ = base.create_scope()
    start = dt.datetime.now(dt.timezone.utc) - dt.timedelta(hours=23)
    base.run("docker", "compose", "-f", "compose.dev.yml", "exec", "-T", "clickhouse", "clickhouse-client", "--max_insert_threads=4", "--query",
             f"INSERT INTO cdnf.edge_events (occurred_at, event_id, domain_id, hostname, method, path, status, event_type, client_ip, security_action, security_reason, security_rule, attack_score) "
             f"SELECT addMilliseconds(toDateTime64('{start:%Y-%m-%d %H:%M:%S}', 3, 'UTC'), toInt64(number) * 80), generateUUIDv4(), {domain_id}, 'attack.e2e.test', 'GET', concat('/p', toString(number % 977)), 403, 'request', "
             f"'192.0.2.10', 'block', 'custom_rule_block', 'r1', 0 FROM numbers({TOTAL})", timeout=600)
    try:
        assert base.clickhouse(f"SELECT count() FROM cdnf.edge_events WHERE domain_id = {domain_id}").strip() == str(TOTAL)
        now = dt.datetime.now(dt.timezone.utc)
        window = f"from={base.iso(now - dt.timedelta(hours=24) + dt.timedelta(minutes=2))}&to={base.iso(now + dt.timedelta(seconds=30))}"

        timings: list[float] = []
        seen: set[str] = set()
        cursor = None
        previous = None
        for number in range(WALKED_PAGES):
            suffix = f"&cursor={cursor}" if cursor else ""
            began = time.monotonic()
            _, page = base.api("GET", f"/api/domains/{domain_id}/security/mitigations?limit={PAGE}&{window}{suffix}", token=user)
            timings.append(time.monotonic() - began)
            items = page["data"]
            assert len(items) == PAGE, (number, len(items))
            for item in items:
                assert item["event_id"] not in seen, "an event was repeated"
                assert previous is None or item["occurred_at"] <= previous, ("the order broke", item["occurred_at"], previous)
                seen.add(item["event_id"])
                previous = item["occurred_at"]
                assert item["trace"]["stage"] == "domain_rule" and item["rule"]["ref"] == "r1"
            cursor = page["meta"]["next_cursor"]
            assert cursor, "the attack ended early"

        # A page from the middle of the attack, read from the API and from ClickHouse after the same boundary.
        middle = base.clickhouse(f"SELECT toString(occurred_at), toString(event_id) FROM cdnf.edge_events WHERE domain_id = {domain_id} ORDER BY occurred_at DESC, event_id DESC LIMIT 1 OFFSET {TOTAL // 2}").strip().split("\t")
        began = time.monotonic()
        _, deep = base.api("GET", f"/api/domains/{domain_id}/security/mitigations?limit={PAGE}&{window}&cursor={cursor_for(*middle)}", token=user)
        deep_seconds = time.monotonic() - began
        timings.append(deep_seconds)
        expected = base.clickhouse(
            f"SELECT toString(event_id) FROM cdnf.edge_events WHERE domain_id = {domain_id} AND (occurred_at, event_id) < (toDateTime64('{middle[0]}', 3, 'UTC'), toUUID('{middle[1]}')) "
            f"ORDER BY occurred_at DESC, event_id DESC LIMIT {PAGE}").split()
        assert [item["event_id"] for item in deep["data"]] == expected, "the deep page differs from ClickHouse"

        # The last page of the attack ends the cursor.
        oldest = base.clickhouse(f"SELECT toString(occurred_at), toString(event_id) FROM cdnf.edge_events WHERE domain_id = {domain_id} ORDER BY occurred_at, event_id LIMIT 1 OFFSET {PAGE - 1}").strip().split("\t")
        _, last = base.api("GET", f"/api/domains/{domain_id}/security/mitigations?limit={PAGE}&{window}&cursor={cursor_for(*oldest)}", token=user)
        assert last["meta"]["next_cursor"] is None and len(last["data"]) == PAGE - 1, (len(last["data"]), last["meta"])

        base.expect_error(404, "GET", f"/api/domains/{domain_id}/security/mitigations", token=stranger)
        ordered = sorted(timings)
        worst = ordered[-1]
        print(f"pages={len(timings)} p50={statistics.median(ordered):.3f}s p95={ordered[int(len(ordered) * 0.95) - 1]:.3f}s max={worst:.3f}s deep={deep_seconds:.3f}s events={TOTAL}")
        assert worst < PAGE_BUDGET_SECONDS, f"a page took {worst:.2f}s"
        print("security event paging qualification passed")
    finally:
        base.clickhouse(f"ALTER TABLE cdnf.edge_events DELETE WHERE domain_id = {domain_id} SETTINGS mutations_sync = 1")


if __name__ == "__main__":
    main()
