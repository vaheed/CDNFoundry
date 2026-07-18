#!/usr/bin/env python3
"""Real Geo-DNS qualification for type-aware default answers through DNSdist."""

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
EMAIL = f"phase3-{RUN}@example.test"
PASSWORD = f"Phase3-{secrets.token_urlsafe(20)}"
ZONE = f"phase3-{RUN}.test"


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


def dig(owner: str, record_type: str) -> str:
    return subprocess.run(
        ["dig", "@127.0.0.1", "-p", "1053", owner, record_type, "+short"],
        check=True, text=True, capture_output=True, timeout=10,
    ).stdout.strip()


def wait_deployment(token: str, domain_id: int) -> None:
    deadline = time.monotonic() + 45
    while time.monotonic() < deadline:
        _, result = call("GET", f"/api/domains/{domain_id}/dns/deployment", token=token)
        deployments = result["data"]["deployments"]
        if deployments and all(row["status"] == "succeeded" and row["deployed_revision"] == result["data"]["desired_revision"] for row in deployments):
            return
        if any(row["status"] == "failed" for row in deployments):
            raise AssertionError(result)
        time.sleep(0.5)
    raise AssertionError("Geo-DNS deployment did not converge")


def main() -> None:
    artisan("App\\Models\\User::query()->create(['name'=>'Phase 3 E2E','email'=>" + quote(EMAIL) + ",'password'=>Illuminate\\Support\\Facades\\Hash::make(" + quote(PASSWORD) + "),'type'=>'admin']);")
    _, login = call("POST", "/api/auth/login", {"email": EMAIL, "password": PASSWORD, "device_name": "phase3-e2e"})
    token = login["data"]["token"]
    _, created = call("POST", "/api/domains", {"name": ZONE}, token)
    domain_id = created["data"]["id"]
    call("POST", f"/api/admin/domains/{domain_id}/force-verify", {}, token)
    call("POST", f"/api/domains/{domain_id}/activate", {}, token)

    records = [
        ("A", "a", ["192.0.2.30"], {}),
        ("AAAA", "aaaa", ["2001:db8::30"], {}),
        ("CNAME", "alias", ["default.example.net"], {}),
        ("MX", "mail", ["mail-default.example.net"], {"priority": 10}),
        ("TXT", "txt", ["region=default"], {}),
        ("SRV", "_sip._tcp.geo", ["sip-default.example.net"], {"priority": 10, "weight": 5, "port": 5060}),
    ]
    for record_type, name, defaults, extra in records:
        call("POST", f"/api/domains/{domain_id}/dns/records", {
            "type": record_type, "name": name, "ttl": 60, "mode": "geo_dns",
            "geo": {"default": defaults, "countries": {"IR": defaults}}, **extra,
        }, token)
    wait_deployment(token, domain_id)

    expected = {
        (f"a.{ZONE}", "A"): "192.0.2.30",
        (f"aaaa.{ZONE}", "AAAA"): "2001:db8::30",
        (f"alias.{ZONE}", "CNAME"): "default.example.net.",
        (f"mail.{ZONE}", "MX"): "10 mail-default.example.net.",
        (f"txt.{ZONE}", "TXT"): '"region=default"',
        (f"_sip._tcp.geo.{ZONE}", "SRV"): "10 5 5060 sip-default.example.net.",
    }
    for query, answer in expected.items():
        output = dig(*query)
        assert answer in output, (query, output)
    print("phase3_geo_dns_e2e=passed types=A,AAAA,CNAME,MX,TXT,SRV default_runtime=passed")


if __name__ == "__main__":
    try:
        main()
    finally:
        artisan("foreach(App\\Models\\Domain::query()->where('name'," + quote(ZONE) + ")->get() as $d){foreach(App\\Models\\DnsCluster::all() as $c){try{app(App\\Support\\PowerDnsClient::class)->deleteZone($c,$d->name);}catch(Throwable $e){}} $d->delete();} App\\Models\\User::query()->where('email'," + quote(EMAIL) + ")->delete();")
