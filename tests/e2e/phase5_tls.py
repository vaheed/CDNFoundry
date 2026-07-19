#!/usr/bin/env python3
"""Real managed TLS qualification against Pebble and authoritative DNSdist."""

from __future__ import annotations

import json
import pathlib
import secrets
import subprocess
import time
import urllib.error
import urllib.request
import uuid

ROOT = pathlib.Path(__file__).resolve().parents[2]
BASE = "http://localhost:8080"
RUN = f"{int(time.time())}-{secrets.token_hex(3)}"
EMAIL = f"phase5-tls-{RUN}@example.test"
PASSWORD = f"Phase5-{secrets.token_urlsafe(20)}"
ZONE = f"phase5-tls-{RUN}.test"


def call(method: str, path: str, payload: object | None = None, token: str | None = None) -> tuple[int, dict]:
    headers = {"Accept": "application/json"}
    body = None
    if payload is not None:
        headers["Content-Type"] = "application/json"
        headers["Idempotency-Key"] = str(uuid.uuid4())
        body = json.dumps(payload).encode()
    if token:
        headers["Authorization"] = f"Bearer {token}"
    request = urllib.request.Request(BASE + path, data=body, headers=headers, method=method)
    try:
        with urllib.request.urlopen(request, timeout=20) as response:
            raw = response.read()
            return response.status, json.loads(raw) if raw else {}
    except urllib.error.HTTPError as error:
        raise AssertionError(f"{method} {path} returned {error.code}: {error.read().decode()}") from error


def artisan(expression: str) -> None:
    subprocess.run(
        ["docker", "compose", "-f", "compose.dev.yml", "exec", "-T", "core", "php", "artisan", "tinker", f"--execute={expression}"],
        cwd=ROOT, check=True, timeout=30, stdout=subprocess.DEVNULL,
    )


def sql(statement: str) -> str:
    result = subprocess.run(
        ["docker", "compose", "-f", "compose.dev.yml", "exec", "-T", "control-db", "psql", "-U", "cdnf", "-d", "cdnf", "-Atc", statement],
        cwd=ROOT, check=True, capture_output=True, text=True, timeout=20,
    )
    return result.stdout.strip()


def dig(name: str, record_type: str) -> list[str]:
    result = subprocess.run(
        ["dig", "@127.0.0.1", "-p", "1053", name, record_type, "+short"], cwd=ROOT,
        check=True, capture_output=True, text=True, timeout=10,
    )
    return [line.strip() for line in result.stdout.splitlines() if line.strip()]


def quote(value: str) -> str:
    return "'" + value.replace("\\", "\\\\").replace("'", "\\'") + "'"


def main() -> None:
    if sql("select count(*) from dns_clusters where enabled and last_health_status='healthy'") == "0":
        raise AssertionError("Phase 5 TLS qualification requires the qualified local PowerDNS cluster")
    if sql("select count(*) from edge_pools where name='shared-default' and enabled") != "1":
        raise AssertionError("Phase 5 TLS qualification requires the shared-default edge pool")
    artisan(
        "App\\Models\\User::query()->create(["
        f"'name'=>'Phase 5 TLS E2E','email'=>{quote(EMAIL)},"
        f"'password'=>Illuminate\\Support\\Facades\\Hash::make({quote(PASSWORD)}),'type'=>'admin']);"
    )
    _, login = call("POST", "/api/auth/login", {"email": EMAIL, "password": PASSWORD, "device_name": "phase5-tls-e2e"})
    token = login["data"]["token"]
    _, created = call("POST", "/api/domains", {"name": ZONE}, token)
    domain_id = int(created["data"]["id"])
    call("POST", f"/api/admin/domains/{domain_id}/force-verify", {}, token)
    call("POST", f"/api/domains/{domain_id}/activate", {}, token)
    call("POST", f"/api/domains/{domain_id}/dns/records", {
        "type": "A", "name": "www", "ttl": 60, "mode": "proxied",
        "origin": {"host": "8.8.8.8", "port": 80, "scheme": "http", "host_header": f"www.{ZONE}",
                   "sni": None, "verify_tls": False, "connect_timeout_ms": 1000,
                   "response_timeout_ms": 5000, "retry_count": 0},
    }, token)

    deadline = time.monotonic() + 180
    last: dict = {}
    while time.monotonic() < deadline:
        _, response = call("GET", f"/api/domains/{domain_id}/tls/status", token=token)
        last = response["data"]
        order = last.get("latest_order") or {}
        if order.get("status") == "succeeded" and last.get("active_certificate"):
            break
        if order.get("status") == "failed":
            raise AssertionError(last)
        time.sleep(1)
    else:
        raise AssertionError(f"managed certificate did not become active: {last}")

    certificate = last["active_certificate"]
    assert certificate["kind"] == "managed", certificate
    assert set(certificate["names"]) == {ZONE, f"*.{ZONE}"}, certificate
    assert "private_key" not in json.dumps(last), last
    _, records = call("GET", f"/api/domains/{domain_id}/dns/records", token=token)
    assert all(not row["name"].startswith("_acme-challenge") for row in records["data"]), records
    deadline = time.monotonic() + 60
    while time.monotonic() < deadline and dig(f"_acme-challenge.{ZONE}", "TXT"):
        time.sleep(1)
    assert not dig(f"_acme-challenge.{ZONE}", "TXT"), "temporary ACME TXT record was not removed"
    raw_key = sql(f"select private_key_ciphertext from tls_certificates where id='{certificate['id']}'")
    assert "PRIVATE KEY" not in raw_key, "managed private key was stored as plaintext"
    print(json.dumps({"result": "passed", "domain_id": domain_id, "zone": ZONE, "certificate_id": certificate["id"]}))


if __name__ == "__main__":
    main()
