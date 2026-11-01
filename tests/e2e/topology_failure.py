#!/usr/bin/env python3
"""Real-runtime qualification: a stopped PoP agent turns its topology node failed, and recovery turns it healthy again.

Uses the persistent development stack. It stops and restarts one edge agent container and creates, then removes,
one administrator account. It never removes a volume.
"""

from __future__ import annotations

import json
import os
import pathlib
import secrets
import subprocess
import time
import urllib.error
import urllib.request

ROOT = pathlib.Path(__file__).resolve().parents[2]
COMPOSE = os.environ.get("CDNF_COMPOSE_FILE", "compose.dev.yml")
BASE = os.environ.get("CDNF_BASE_URL", "http://localhost:8080").rstrip("/")
AGENT = os.environ.get("CDNF_TOPOLOGY_AGENT", "edge-agent-a")
RUN = f"{int(time.time())}-{secrets.token_hex(4)}"
EMAIL = f"topology-admin-{RUN}@example.test"
PASSWORD = f"Topology-1-{secrets.token_urlsafe(20)}"
REFRESH_SECONDS = 10  # the page polls every ten seconds; the API is what each refresh reads
FRESH_SECONDS = 45  # edge_runtime.heartbeat_fresh_seconds default


def compose(*args: str, timeout: int = 180) -> subprocess.CompletedProcess[str]:
    result = subprocess.run(["docker", "compose", "-f", COMPOSE, *args], cwd=ROOT, text=True, capture_output=True, timeout=timeout, check=False)
    if result.returncode != 0:
        raise RuntimeError(f"compose {' '.join(args)} failed\n{result.stdout}\n{result.stderr}")
    return result


def request(method: str, path: str, payload: object | None = None, token: str | None = None) -> tuple[int, dict]:
    headers = {"Accept": "application/json"}
    data = None
    if payload is not None:
        headers["Content-Type"] = "application/json"
        data = json.dumps(payload).encode()
    if token:
        headers["Authorization"] = f"Bearer {token}"
    call = urllib.request.Request(f"{BASE}{path}", method=method, headers=headers, data=data)
    try:
        with urllib.request.urlopen(call, timeout=30) as response:
            return response.status, json.loads(response.read() or b"{}")
    except urllib.error.HTTPError as error:
        return error.code, json.loads(error.read() or b"{}")


def php(expression: str) -> None:
    compose("exec", "-T", "core", "php", "artisan", "tinker", f"--execute={expression}")


def edge_statuses(token: str) -> dict[str, str]:
    status, body = request("GET", "/api/admin/system/topology", token=token)
    assert status == 200, body
    return {node["label"]: node["status"] for node in body["data"]["nodes"] if node["kind"] == "edge"}


def wait_for(token: str, predicate, seconds: int, what: str) -> dict[str, str]:
    deadline = time.time() + seconds
    while time.time() < deadline:
        current = edge_statuses(token)
        if predicate(current):
            return current
        time.sleep(REFRESH_SECONDS / 2)
    raise AssertionError(f"timed out waiting for {what}: {edge_statuses(token)}")


def main() -> None:
    php(
        "App\\Models\\User::query()->create(["
        f"'name'=>'Topology runtime admin','email'=>'{EMAIL}',"
        f"'password'=>Illuminate\\Support\\Facades\\Hash::make('{PASSWORD}'),'type'=>'admin']);"
    )
    stopped = False
    try:
        status, login = request("POST", "/api/auth/login", {"email": EMAIL, "password": PASSWORD, "device_name": "topology-e2e"})
        assert status == 200, login
        token = login["data"]["token"]

        before = wait_for(token, lambda s: any(v == "healthy" for v in s.values()), 90, "a healthy PoP")
        healthy = {name for name, state in before.items() if state == "healthy"}
        assert healthy, before

        compose("stop", AGENT)
        stopped = True
        after = wait_for(token, lambda s: any(state == "failed" and name in healthy for name, state in s.items()), FRESH_SECONDS + 3 * REFRESH_SECONDS + 30, "the stopped PoP to turn failed")
        failed = {name for name, state in after.items() if state == "failed" and name in healthy}
        assert len(failed) == 1, f"exactly one PoP should fail, got {failed}"

        compose("start", AGENT)
        stopped = False
        wait_for(token, lambda s: all(s.get(name) == "healthy" for name in failed), 120, "the PoP to recover")
        print(json.dumps({"stopped_agent": AGENT, "failed_pop": sorted(failed), "recovered": True}))
    finally:
        if stopped:
            compose("start", AGENT)
        php(f"App\\Models\\User::query()->where('email','{EMAIL}')->delete();")


if __name__ == "__main__":
    main()
