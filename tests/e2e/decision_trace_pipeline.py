#!/usr/bin/env python3
"""Decision trace through the real pipeline: a log line with a client key and snapshot sequence goes to Vector, to ClickHouse, and out of
the API; a forged key is dropped by Vector; the client-key filter returns only that client's events."""

import datetime as dt
import urllib.parse
import uuid

import telemetry_analytics as base


def main() -> None:
    base.wait_for_api()
    domain_id, user, stranger, _ = base.create_scope()
    when = dt.datetime.now(dt.timezone.utc) - dt.timedelta(minutes=3)
    ids = {name: str(uuid.uuid4()) for name in ("kept", "other", "forged", "flush")}

    def event(name: str, offset: int, **fields: object) -> dict:
        return {"occurred_at": base.iso(when + dt.timedelta(seconds=offset)), "event_id": ids[name], "domain_id": domain_id, "hostname": "trace.e2e.test", "method": "GET",
                "path": f"/{name}", "status": 403, "bytes_in": 0, "bytes_out": 0, "cache_status": "BYPASS", "event_type": "request", "client_ip": "192.0.2.44", "country": "DE",
                "security_action": "block", "security_reason": "custom_rule_block", "security_rule": "r1", "shadow_rule": "r2", **fields}

    base.vector_event(8686, event("kept", 0, client_key="0a1b2c3d4e5f", snapshot_sequence=812, waf_rule_ids="942100"))
    base.vector_event(8686, event("other", 1, client_key="ffffffffffff", snapshot_sequence=812))
    base.vector_event(8686, event("forged", 2, client_key="192.0.2.44", snapshot_sequence=-3))
    # Vector's disk buffer can hold the last event back until another reaches the sink.
    for offset in (3, 4, 5):
        base.vector_event(8686, {**event("flush", offset, domain_id=0, hostname="flush.e2e.test", security_action="allow", security_reason="", security_rule="", shadow_rule=""), "event_id": str(uuid.uuid4())})
    base.wait_for_clickhouse(lambda value: value == "3", f"SELECT count() FROM cdnf.edge_events WHERE domain_id = {domain_id}")

    now = dt.datetime.now(dt.timezone.utc)
    window = urllib.parse.urlencode({"from": base.iso(now - dt.timedelta(hours=1)), "to": base.iso(now + dt.timedelta(seconds=30))})
    _, everything = base.api("GET", f"/api/domains/{domain_id}/security/mitigations?{window}", token=user)
    traces = {item["event_id"]: item["trace"] for item in everything["data"]}
    assert set(traces) == {ids["kept"], ids["other"], ids["forged"]}, traces.keys()
    kept = traces[ids["kept"]]
    assert kept["stage"] == "domain_rule" and kept["client_key"] == "0a1b2c3d4e5f" and kept["snapshot_sequence"] == 812, kept
    assert kept["shadow_rules"] == ["r2"] and kept["managed_rule_ids"] == [942100], kept
    forged = traces[ids["forged"]]
    assert forged["client_key"] is None and forged["snapshot_sequence"] is None and {"client_key", "snapshot_sequence"} <= set(forged["unavailable"]), forged

    _, filtered = base.api("GET", f"/api/domains/{domain_id}/security/mitigations?{window}&client_key=0a1b2c3d4e5f", token=user)
    assert [item["event_id"] for item in filtered["data"]] == [ids["kept"]], filtered["data"]
    base.expect_error(404, "GET", f"/api/domains/{domain_id}/security/mitigations?{window}", token=stranger)
    print("decision trace pipeline qualification passed")


if __name__ == "__main__":
    main()
