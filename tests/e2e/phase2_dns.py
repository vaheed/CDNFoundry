#!/usr/bin/env python3
"""Real Phase 2 authoritative DNS qualification through Laravel, PowerDNS, and DNSdist."""

from __future__ import annotations

import json
import os
import secrets
import subprocess
import time
import urllib.error
import urllib.request
import uuid

BASE = os.environ.get("CDNF_BASE_URL", "http://localhost:8080").rstrip("/")
COMPOSE = os.environ.get("CDNF_COMPOSE_FILE", "compose.dev.yml")
ROOT = os.path.abspath(os.path.join(os.path.dirname(__file__), "../.."))
RUN = f"{int(time.time())}-{secrets.token_hex(3)}"
EMAIL = f"phase2-{RUN}@example.test"
PASSWORD = f"Phase2-{secrets.token_urlsafe(20)}"
ZONE = f"phase2-{RUN}.test"


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


def artisan(expression: str) -> None:
    subprocess.run(["docker", "compose", "-f", COMPOSE, "exec", "-T", "core", "php", "artisan", "tinker", f"--execute={expression}"], cwd=ROOT, check=True, timeout=30, stdout=subprocess.DEVNULL)


def quote(value: str) -> str:
    return "'" + value.replace("\\", "\\\\").replace("'", "\\'") + "'"


def dig(owner: str, record_type: str, tcp: bool = False) -> str:
    command = ["dig", "@127.0.0.1", "-p", "1053", owner, record_type, "+short"]
    if tcp:
        command.append("+tcp")
    return subprocess.run(command, check=True, text=True, capture_output=True, timeout=10).stdout.strip()


def dig_authority(owner: str, record_type: str) -> str:
    return subprocess.run(["dig", "@127.0.0.1", "-p", "1053", owner, record_type, "+noall", "+authority"], check=True, text=True, capture_output=True, timeout=10).stdout.strip()


def compose(*arguments: str) -> None:
    subprocess.run(["docker", "compose", "-f", COMPOSE, *arguments], cwd=ROOT, check=True, timeout=90, stdout=subprocess.DEVNULL)


def runtime_queue_size() -> int:
    result = subprocess.run(["docker", "compose", "-f", COMPOSE, "exec", "-T", "redis", "valkey-cli", "LLEN", "laravel-database-queues:runtime"], cwd=ROOT, check=True, text=True, capture_output=True, timeout=10)
    return int(result.stdout.strip())


def wait_http() -> None:
    deadline = time.monotonic() + 60
    while time.monotonic() < deadline:
        try:
            status, _ = call("GET", "/api/health")
            if status == 200:
                return
        except (OSError, urllib.error.URLError):
            pass
        time.sleep(1)
    raise AssertionError("control plane did not recover")


def wait_dns(expected: str = "192.0.2.20") -> None:
    deadline = time.monotonic() + 30
    while time.monotonic() < deadline:
        try:
            if expected in dig(ZONE, "A"):
                return
        except subprocess.SubprocessError:
            pass
        time.sleep(0.5)
    raise AssertionError("authoritative DNS did not recover")


def wait_deployment(token: str, domain_id: int) -> None:
    deadline = time.monotonic() + 45
    while time.monotonic() < deadline:
        _, result = call("GET", f"/api/domains/{domain_id}/dns/deployment", token=token)
        deployments = result["data"]["deployments"]
        if deployments and all(item["status"] == "succeeded" and item["deployed_revision"] == result["data"]["desired_revision"] for item in deployments):
            return
        if any(item["status"] == "failed" for item in deployments):
            raise AssertionError(result)
        time.sleep(0.5)
    raise AssertionError("DNS deployment did not converge")


def main() -> None:
    artisan("App\\Models\\User::query()->create(['name'=>'Phase 2 E2E','email'=>" + quote(EMAIL) + ",'password'=>Illuminate\\Support\\Facades\\Hash::make(" + quote(PASSWORD) + "),'type'=>'admin']);")
    _, login = call("POST", "/api/auth/login", {"email": EMAIL, "password": PASSWORD, "device_name": "phase2-e2e"})
    token = login["data"]["token"]
    settings = {
        "platform_domain": "cdnf.test", "proxy_hostname": "proxy.cdnf.test",
        "nameservers": [{"hostname": "ns1.cdnf.test", "ipv4": "192.0.2.10", "ipv6": "2001:db8::10"}, {"hostname": "ns2.cdnf.test", "ipv4": "192.0.2.11", "ipv6": "2001:db8::11"}],
        "soa_primary": "ns1.cdnf.test", "soa_mailbox": "hostmaster.cdnf.test", "soa_refresh": 3600, "soa_retry": 600, "soa_expire": 1209600, "soa_minimum_ttl": 300, "default_ttl": 300, "cluster_targets": ["pdns-auth:8081"],
    }
    call("PATCH", "/api/admin/system/settings/dns", settings, token)
    _, clusters = call("GET", "/api/admin/dns/clusters", token=token)
    cluster = next((item for item in clusters["data"] if item["api_url"] == "http://pdns-auth:8081"), None)
    if cluster is None:
        _, created = call("POST", "/api/admin/dns/clusters", {"name": f"phase2-{RUN}", "location": "e2e", "api_url": "http://pdns-auth:8081", "api_key": "pdns-dev-api-key", "server_id": "localhost", "nameservers": [{"hostname": "ns1.cdnf.test"}, {"hostname": "ns2.cdnf.test"}]}, token)
        cluster = created["data"]
    _, created_domain = call("POST", "/api/domains", {"name": ZONE}, token)
    domain_id = created_domain["data"]["id"]
    call("POST", f"/api/admin/domains/{domain_id}/force-verify", {}, token)
    call("POST", f"/api/domains/{domain_id}/activate", {}, token)
    records = [
        ("A", "@", "192.0.2.20", {}), ("AAAA", "@", "2001:db8::20", {}),
        ("CNAME", "alias", ZONE + ".", {}), ("MX", "@", "mail.example.net.", {"priority": 10}),
        ("TXT", "txt", "phase2-real-runtime", {}), ("NS", "delegated", "ns.example.net.", {}),
        ("CAA", "@", '0 issue "letsencrypt.org"', {}),
        ("SRV", "_sip._tcp", "sip.example.net.", {"priority": 10, "weight": 20, "port": 5060}),
        ("PTR", "ptr", "target.example.net.", {}),
    ]
    apex_a_id = None
    for record_type, name, content, extra in records:
        _, created_record = call("POST", f"/api/domains/{domain_id}/dns/records", {"type": record_type, "name": name, "content": content, "ttl": 60, **extra}, token)
        if record_type == "A" and name == "@":
            apex_a_id = created_record["data"]["id"]
    wait_deployment(token, domain_id)
    expected = {
        (ZONE, "A"): "192.0.2.20", (ZONE, "AAAA"): "2001:db8::20", (f"alias.{ZONE}", "CNAME"): ZONE + ".",
        (ZONE, "MX"): "10 mail.example.net.", (f"txt.{ZONE}", "TXT"): '"phase2-real-runtime"',
        (f"delegated.{ZONE}", "NS"): "ns.example.net.", (ZONE, "CAA"): '0 issue "letsencrypt.org"',
        (f"_sip._tcp.{ZONE}", "SRV"): "10 20 5060 sip.example.net.", (f"ptr.{ZONE}", "PTR"): "target.example.net.",
    }
    for query, value in expected.items():
        output = dig_authority(*query) if query[1] == "NS" else dig(*query)
        assert value in output, (query, output)
    assert "192.0.2.20" in dig(ZONE, "A", tcp=True)
    ipv6_transport = subprocess.run(["dig", "@::1", "-p", "1053", ZONE, "AAAA", "+short"], check=True, text=True, capture_output=True, timeout=10).stdout
    assert "2001:db8::20" in ipv6_transport

    compose("stop", "horizon")
    assert runtime_queue_size() == 0
    for index in range(100):
        call("PATCH", f"/api/domains/{domain_id}/dns/records/{apex_a_id}", {"content": f"192.0.2.{(index % 200) + 21}"}, token)
    queued_updates = runtime_queue_size()
    assert queued_updates == 1, f"expected one coalesced runtime job, found {queued_updates}"
    compose("start", "horizon")
    wait_deployment(token, domain_id)
    assert "192.0.2.120" in dig(ZONE, "A")

    compose("stop", "core", "web", "horizon", "scheduler", "control-db", "redis")
    assert "192.0.2.120" in dig(ZONE, "A")
    compose("start", "control-db", "redis", "core", "web", "horizon", "scheduler")
    wait_http()
    compose("restart", "pdns-auth", "dnsdist", "horizon", "core")
    wait_http()
    wait_dns("192.0.2.120")
    assert "phase2-real-runtime" in dig(f"txt.{ZONE}", "TXT")
    print("phase2_dns_e2e=passed types=9 ipv4_transport=passed ipv6_transport=passed coalesced_updates=100:1 control_outage=passed restart=passed")


if __name__ == "__main__":
    try:
        main()
    finally:
        compose("start", "control-db", "redis", "core", "web", "horizon", "scheduler", "pdns-auth", "dnsdist")
        wait_http()
        artisan("$d=App\\Models\\Domain::query()->where('name'," + quote(ZONE) + ")->first(); if($d){foreach(App\\Models\\DnsCluster::all() as $c){try{app(App\\Support\\PowerDnsClient::class)->deleteZone($c,$d->name);}catch(Throwable $e){}} $d->delete();} App\\Models\\User::query()->where('email'," + quote(EMAIL) + ")->delete();")
