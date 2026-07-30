#!/usr/bin/env python3
"""Non-browser real Loki/Vector qualification for operational logs."""

from __future__ import annotations

import base64
import json
import pathlib
import subprocess
import time
import urllib.parse
import urllib.request


ROOT = pathlib.Path(__file__).resolve().parents[2]
FIXTURE = "cdnfoundry-operational-log-qualification"


def run(*args: str, check: bool = True) -> subprocess.CompletedProcess[str]:
    return subprocess.run(args, cwd=ROOT, text=True, capture_output=True, check=check)


def loki_query(query: str) -> list[dict]:
    url = "http://127.0.0.1:3100/loki/api/v1/query_range?" + urllib.parse.urlencode(
        {"query": query, "limit": "100", "start": str(time.time_ns() - 120_000_000_000)}
    )
    with urllib.request.urlopen(url, timeout=5) as response:
        payload = json.load(response)
    return payload.get("data", {}).get("result", [])


def grafana_loki_health() -> dict:
    request = urllib.request.Request("http://127.0.0.1:3000/api/datasources/uid/loki/health")
    token = base64.b64encode(b"admin:cdnf-grafana-dev-only").decode()
    request.add_header("Authorization", f"Basic {token}")
    with urllib.request.urlopen(request, timeout=5) as response:
        return json.load(response)


def main() -> None:
    run("docker", "compose", "-f", "compose.dev.yml", "up", "-d", "loki", "log-collector", "grafana")
    run("docker", "rm", "-f", FIXTURE, check=False)
    secret = "vector-secret-must-not-reach-loki"
    operational = json.dumps(
        {
            "level": "error",
            "event": "qualification_failure",
            "message": f"candidate failed password={secret}",
            "domain_id": "42",
            "operation_id": "qualification-operation",
        }
    )
    access_event = json.dumps(
        {
            "event_type": "request",
            "occurred_at": "2026-07-30T00:00:00Z",
            "hostname": "filtered.example.test",
            "status": 200,
        }
    )
    try:
        run(
            "docker", "run", "-d", "--name", FIXTURE,
            "--label", "com.docker.compose.service=qualification",
            "alpine:3.22", "sh", "-c",
            f"printf '%s\\n' '{operational}' '{access_event}'; sleep 30",
        )
        deadline = time.monotonic() + 45
        entries: list[dict] = []
        while time.monotonic() < deadline:
            try:
                streams = loki_query('{service="qualification"}')
                entries = [json.loads(value[1]) for stream in streams for value in stream.get("values", [])]
                if any(entry.get("event") == "qualification_failure" for entry in entries):
                    break
            except (OSError, ValueError, json.JSONDecodeError):
                pass
            time.sleep(2)
        else:
            raise AssertionError("Vector did not deliver the qualification event to Loki")

        encoded = json.dumps(entries)
        if secret in encoded or "[REDACTED]" not in encoded:
            raise AssertionError(f"central redaction failed: {encoded}")
        if "filtered.example.test" in encoded:
            raise AssertionError("OpenResty access-event shape was duplicated into Loki")
        event = next(entry for entry in entries if entry.get("event") == "qualification_failure")
        expected = {
            "level": "error", "environment": "development", "service": "qualification",
            "role": "development", "host": "development", "collector_id": "development-01",
            "domain_id": "42", "operation_id": "qualification-operation",
        }
        for key, value in expected.items():
            if event.get(key) != value:
                raise AssertionError(f"normalized {key} mismatch: {event}")
        health = grafana_loki_health()
        if health.get("status") not in ("OK", "Success"):
            raise AssertionError(f"Grafana Loki datasource is unhealthy: {health}")
        print("Loki readiness, Vector delivery, normalization, redaction, traffic filtering, and Grafana health passed")
    finally:
        run("docker", "rm", "-f", FIXTURE, check=False)


if __name__ == "__main__":
    main()
