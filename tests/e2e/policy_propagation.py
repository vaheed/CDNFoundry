"""Measure how long a saved security rule takes to reach every edge, and that a rollback restores the previous policy.

Runs against the development stack's real control plane, queue and edge agents (the stack's two edges, each with its own agent).
It uses a throwaway administrator and a development domain that is already in the stack. Each trial saves one block rule and
times the save until both agents log that they activated the runtime generation carrying it; it then rolls the domain back to
the revision before the rule, checks that the rule is gone, and times the activation of the restored policy on both agents.
Every rule it adds is removed afterwards. The stack's domain is not placed on a cell, so what is timed is the control plane
and the agents' activation of the signed generation (the cell's own reload is timed by cell_generation_reload.py), not a request.
"""
from __future__ import annotations

import json
import math
import os
import secrets
import subprocess
import sys
import time
import urllib.error
import urllib.request
import uuid
from datetime import datetime

BASE = os.environ.get("CDNF_BASE_URL", "http://localhost:8080").rstrip("/")
COMPOSE = os.environ.get("CDNF_COMPOSE_FILE", "compose.dev.yml")
HOSTNAME = os.environ.get("CDNF_PROPAGATION_HOST", "test.com")
EDGES = os.environ.get("CDNF_PROPAGATION_AGENTS", "cdnfoundry-dev-edge-agent-a-1,cdnfoundry-dev-edge-agent-b-1").split(",")
TRIALS = int(os.environ.get("CDNF_PROPAGATION_TRIALS", "12"))
BOUND_SECONDS = 60.0
RUN = f"{int(time.time())}-{secrets.token_hex(3)}"
EMAIL = f"e2e-propagation-{RUN}@example.test"
PASSWORD = f"Admin-1-{secrets.token_urlsafe(18)}"
ROOT = os.path.abspath(os.path.join(os.path.dirname(__file__), "../.."))


def api(method: str, path: str, payload: object | None = None, token: str | None = None) -> dict:
    headers = {"Accept": "application/json", "Idempotency-Key": str(uuid.uuid4())} if method != "GET" else {"Accept": "application/json"}
    data = None
    if payload is not None:
        headers["Content-Type"] = "application/json"
        data = json.dumps(payload).encode()
    if token:
        headers["Authorization"] = f"Bearer {token}"
    try:
        with urllib.request.urlopen(urllib.request.Request(f"{BASE}{path}", data=data, headers=headers, method=method), timeout=10) as response:
            body = response.read()
            return json.loads(body) if body else {}
    except urllib.error.HTTPError as error:
        raise RuntimeError(f"{method} {path}: HTTP {error.code} {error.read()[:300]!r}") from error


def artisan(expression: str) -> None:
    subprocess.run(["docker", "compose", "-f", COMPOSE, "exec", "-T", "core", "php", "artisan", "tinker", f"--execute={expression}"],
                   cwd=ROOT, check=True, stdout=subprocess.DEVNULL, timeout=60)


def activations(agent: str, since: str) -> list[str]:
    """Times (RFC 3339) at which the agent activated a runtime candidate after `since`."""
    logs = subprocess.run(["docker", "logs", "--since", since, agent], capture_output=True, text=True, timeout=30)
    found = []
    for line in (logs.stdout + logs.stderr).splitlines():
        if '"runtime_candidate_activated"' in line:
            found.append(json.loads(line)["time"])
    return found


def seconds(stamp: str) -> float:
    return datetime.fromisoformat(stamp[:26].rstrip("Z") + "+00:00").timestamp()


def until(agents: list[str], started_wall: float, since: str, ceiling: float = 120.0) -> dict[str, float]:
    """Seconds from the wall-clock start until each agent has activated a candidate that began after it."""
    reached: dict[str, float] = {}
    while len(reached) < len(agents) and time.time() - started_wall < ceiling:
        for agent in agents:
            if agent not in reached:
                times = [seconds(stamp) for stamp in activations(agent, since) if seconds(stamp) >= started_wall]
                if times:
                    reached[agent] = min(times) - started_wall
        time.sleep(0.5)
    assert len(reached) == len(agents), f"not every agent activated within {ceiling} s: {reached}"
    return reached


def percentile(values: list[float], fraction: float) -> float:
    ordered = sorted(values)
    return ordered[min(len(ordered) - 1, math.ceil(fraction * len(ordered)) - 1)]


def main() -> None:
    artisan("App\\Models\\User::query()->create(['name'=>'Propagation','email'=>'" + EMAIL + "','password'=>Illuminate\\Support\\Facades\\Hash::make('" + PASSWORD + "'),'type'=>'admin']);")
    token = api("POST", "/api/auth/login", {"email": EMAIL, "password": PASSWORD, "device_name": "propagation"})["data"]["token"]
    domains = api("GET", "/api/domains?per_page=100", token=token)["data"]
    domain = next(item for item in (domains["data"] if isinstance(domains, dict) else domains) if item["name"] == HOSTNAME)["id"]
    saves: list[float] = []
    rollbacks: list[float] = []
    created: list[int] = []
    try:
        for stale in api("GET", f"/api/domains/{domain}/security/rules", token=token)["data"]["data"]:
            if (stale["name"] or "").startswith("propagation "):
                api("DELETE", f"/api/domains/{domain}/security/rules/{stale['id']}", token=token)
        time.sleep(15)
        for trial in range(TRIALS):
            path = f"/propagation-{RUN}-{trial}"
            before = api("GET", f"/api/domains/{domain}", token=token)["data"]["revision"]
            started = time.time()
            since = time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime(started - 2))
            rule = api("POST", f"/api/domains/{domain}/security/rules", {
                "name": f"propagation {trial}", "conditions": [{"field": "path", "operator": "equals", "value": path}], "action": "block", "priority": 50 + trial,
            }, token)["data"]
            created.append(rule["id"])
            reached = until(EDGES, started, since)
            saves.append(max(reached.values()))
            time.sleep(1)
            # The rollback restores the revision before the rule, and the agents activate the restored policy.
            started = time.time()
            since = time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime(started - 2))
            api("POST", f"/api/domains/{domain}/rollback", {"revision": before}, token)
            returned = until(EDGES, started, since)
            rollbacks.append(max(returned.values()))
            remaining = [item["id"] for item in api("GET", f"/api/domains/{domain}/security/rules", token=token)["data"]["data"]]
            assert rule["id"] not in remaining, "the rollback left the rule in place"
            created.pop()
            print(f"trial {trial + 1}: activated on every edge after {saves[-1]:.1f} s ({', '.join(f'{v:.1f}' for v in reached.values())}); rollback activated after {rollbacks[-1]:.1f} s")
            time.sleep(1)
    finally:
        for rule_id in created:
            try:
                api("DELETE", f"/api/domains/{domain}/security/rules/{rule_id}", token=token)
            except RuntimeError as error:
                print(f"cleanup of rule {rule_id} failed: {error}", file=sys.stderr)
        artisan(f"App\\Models\\User::query()->where('email','{EMAIL}')->delete();")
    summary = {"qualification": "policy_propagation", "trials": TRIALS, "edges": len(EDGES), "measured": "control plane to signed generation activated by each edge agent",
               "save_to_enforcement_seconds": {"p50": round(percentile(saves, 0.5), 2), "p95": round(percentile(saves, 0.95), 2), "max": round(max(saves), 2)},
               "rollback_to_previous_behavior_seconds": {"p50": round(percentile(rollbacks, 0.5), 2), "p95": round(percentile(rollbacks, 0.95), 2), "max": round(max(rollbacks), 2)},
               "bound_seconds": BOUND_SECONDS}
    print(json.dumps(summary))
    assert summary["save_to_enforcement_seconds"]["p95"] <= BOUND_SECONDS, summary
    assert summary["rollback_to_previous_behavior_seconds"]["p95"] <= BOUND_SECONDS, summary


if __name__ == "__main__":
    main()
