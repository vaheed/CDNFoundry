#!/usr/bin/env python3
"""A DNS host outage self-heals: the operation stays running, then succeeds (real stack, no browser)."""

from __future__ import annotations

import json
import os
import secrets
import subprocess
import time
import urllib.request
import uuid

BASE = os.environ.get("CDNF_BASE_URL", "http://localhost:8080").rstrip("/")
COMPOSE = os.environ.get("CDNF_COMPOSE_FILE", "compose.dev.yml")
ROOT = os.path.abspath(os.path.join(os.path.dirname(__file__), "../.."))
RUN = f"{int(time.time())}-{secrets.token_hex(3)}"
EMAIL = f"dns-outage-{RUN}@example.test"
PASSWORD = f"Phase3-{secrets.token_urlsafe(20)}"
ZONE = f"dns-outage-{RUN}.test"


def call(method: str, path: str, payload: object | None = None, token: str | None = None) -> tuple[int, object]:
    headers = {"Accept": "application/json"}
    body = None
    if payload is not None:
        headers["Content-Type"] = "application/json"
        headers["Idempotency-Key"] = str(uuid.uuid4())
        body = json.dumps(payload).encode()
    if token:
        headers["Authorization"] = f"Bearer {token}"
    request = urllib.request.Request(BASE + path, data=body, headers=headers, method=method)
    with urllib.request.urlopen(request, timeout=15) as response:
        raw = response.read()
        return response.status, json.loads(raw) if raw else {}


def quote(value: str) -> str:
    return "'" + value.replace("\\", "\\\\").replace("'", "\\'") + "'"


def artisan(expression: str) -> None:
    subprocess.run(
        ["docker", "compose", "-f", COMPOSE, "exec", "-T", "core", "php", "artisan", "tinker", f"--execute={expression}"],
        cwd=ROOT, check=True, timeout=30, stdout=subprocess.DEVNULL,
    )


def dig(owner: str, record_type: str, subnet: str | None = None) -> str:
    return subprocess.run(
        ["dig", "@127.0.0.1", "-p", "1053", owner, record_type, "+short", *([f"+subnet={subnet}"] if subnet else [])],
        check=True, text=True, capture_output=True, timeout=10,
    ).stdout.strip()


def dig(owner: str, record_type: str) -> str:
    return subprocess.run(["dig", "@127.0.0.1", "-p", "1053", owner, record_type, "+short", "+timeout=2", "+tries=1"],
                          check=False, text=True, capture_output=True, timeout=15).stdout.strip()


def main() -> None:
    artisan("App\\Models\\User::query()->create(['name'=>'DNS outage','email'=>" + quote(EMAIL) + ",'password'=>Illuminate\\Support\\Facades\\Hash::make(" + quote(PASSWORD) + "),'type'=>'admin']);")
    _, login = call("POST", "/api/auth/login", {"email": EMAIL, "password": PASSWORD, "device_name": "dns-outage"})
    token = login["data"]["token"]
    _, created = call("POST", "/api/domains", {"name": ZONE}, token)
    domain_id = created["data"]["id"]
    call("POST", f"/api/admin/domains/{domain_id}/force-verify", {}, token)
    call("POST", f"/api/domains/{domain_id}/activate", {}, token)
    pdns = subprocess.run(["docker", "compose", "-f", COMPOSE, "ps", "-q", "pdns-auth"], cwd=ROOT, text=True, capture_output=True, check=True).stdout.strip()
    assert pdns, "pdns-auth is not running"
    subprocess.run(["docker", "stop", pdns], check=True, capture_output=True)
    try:
        _, record = call("POST", f"/api/domains/{domain_id}/dns/records", {"type": "A", "name": "outage", "content": "192.0.2.77", "ttl": 60, "mode": "dns_only"}, token)
        _, queued = call("POST", f"/api/domains/{domain_id}/dns/reconcile", {}, token)
        operation_id = queued["data"]["id"]
        deadline = time.monotonic() + 90
        seen_running_with_error = False
        while time.monotonic() < deadline:
            _, op = call("GET", f"/api/operations/{operation_id}", token=token)
            assert op["data"]["status"] != "failed", f"the operation failed during a short outage: {op}"
            if op["data"]["status"] == "running" and op["data"].get("error"):
                seen_running_with_error = True
                break
            time.sleep(3)
        assert seen_running_with_error, "the operation never showed a retrying state during the outage"
    finally:
        subprocess.run(["docker", "start", pdns], check=True, capture_output=True)

    deadline = time.monotonic() + 240
    while time.monotonic() < deadline:
        _, op = call("GET", f"/api/operations/{operation_id}", token=token)
        assert op["data"]["status"] != "failed", op
        if op["data"]["status"] == "succeeded":
            break
        time.sleep(5)
    else:
        raise AssertionError("the DNS operation did not self-heal after the host returned")
    deadline = time.monotonic() + 60
    while "192.0.2.77" not in dig(f"outage.{ZONE}", "A") and time.monotonic() < deadline:
        time.sleep(2)
    _, ops = call("GET", "/api/admin/operations", token=token)
    summary = [(r["id"][:8], r["type"], r["status"], r.get("error")) for r in ops["data"][:6]]
    served = dig(f"outage.{ZONE}", "A")
    assert "192.0.2.77" in served, f"the record is not served after recovery: op={operation_id} dig={served!r} ops={summary}"
    print("dns_outage_recovery_e2e=passed operation_running_during_outage=true succeeded_after_recovery=true")


if __name__ == "__main__":
    try:
        main()
    finally:
        artisan("foreach(App\\Models\\Domain::query()->where('name'," + quote(ZONE) + ")->get() as $d){foreach(App\\Models\\DnsCluster::all() as $c){try{app(App\\Support\\PowerDnsClient::class)->deleteZone($c,$d->name);}catch(Throwable $e){}} $d->delete();} App\\Models\\User::query()->where('email'," + quote(EMAIL) + ")->delete();")
