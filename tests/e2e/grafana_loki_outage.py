#!/usr/bin/env python3
"""A Loki outage reads as an error in Grafana, never as an empty result.

Grafana reports a failed query as an error on the panel (the red corner and the message), and a query that succeeded and matched
nothing as the panel's own empty text. This runs the same LogQL against the real Loki, where a filter nothing matches succeeds with no
rows, and against a throwaway Loki data source that points at a closed port, where the query must fail. The throwaway data source is
removed afterwards. It never stops or changes the real Loki.
"""

import pathlib
import sys
import time

sys.path.insert(0, str(pathlib.Path(__file__).resolve().parent))
from grafana_observability import call, wait_healthy  # noqa: E402

DEAD = "cdnf-dead-loki"
EXPR = '{service=~".+"} |= "no-line-has-this-marker-7f3a"'


def query(uid: str) -> dict:
    now = int(time.time() * 1000)
    body = {"queries": [{"refId": "A", "datasource": {"type": "loki", "uid": uid}, "expr": EXPR, "queryType": "range", "intervalMs": 60000, "maxDataPoints": 1000}],
            "from": str(now - 900_000), "to": str(now)}
    try:
        return call("POST", "/api/ds/query", body)  # type: ignore[return-value]
    except AssertionError as error:
        return {"results": {"A": {"error": str(error)}}}


def main() -> None:
    wait_healthy()
    real = query("loki")["results"]["A"]
    assert not real.get("error"), f"the real Loki must answer a filter that matches nothing: {real.get('error')}"
    frames = real.get("frames", [])
    assert sum(len(frame.get("data", {}).get("values", [[]])[0]) for frame in frames) == 0, "nothing matches the marker, so the result is empty"
    try:
        call("POST", "/api/datasources", {"name": DEAD, "uid": DEAD, "type": "loki", "access": "proxy", "url": "http://127.0.0.1:9", "jsonData": {"timeout": 3}})
        dead = query(DEAD)["results"]["A"]
        assert dead.get("error"), f"an unreachable Loki must be an error, not an empty result: {dead}"
        assert not dead.get("frames") or sum(len(f.get("data", {}).get("values", [[]])[0]) for f in dead["frames"]) == 0
        print("Loki outage: the real Loki answers an empty match without an error; an unreachable Loki is an error")
    finally:
        try:
            call("DELETE", f"/api/datasources/uid/{DEAD}")
        except AssertionError:
            pass
    print("e2e grafana loki outage qualification passed")


if __name__ == "__main__":
    main()
