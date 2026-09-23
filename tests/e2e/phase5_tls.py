#!/usr/bin/env python3
"""Real managed TLS qualification against Pebble and authoritative DNSdist."""

from __future__ import annotations

import json
import pathlib
import secrets
import hashlib
import socket
import ssl
import subprocess
import tempfile
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


def verify_edge_https(hostname: str, fingerprint: str) -> None:
    pebble = subprocess.run(["docker", "compose", "-f", "compose.dev.yml", "ps", "-q", "pebble"],
                            cwd=ROOT, check=True, capture_output=True, text=True, timeout=15).stdout.strip()
    if not pebble:
        raise AssertionError("Pebble container is unavailable for CA verification")
    with tempfile.TemporaryDirectory(prefix="cdnf-pebble-ca-") as directory:
        authority = pathlib.Path(directory) / "minica.pem"
        subprocess.run(["docker", "cp", f"{pebble}:/test/certs/pebble.minica.pem", str(authority)],
                       cwd=ROOT, check=True, capture_output=True, timeout=15)
        details = json.loads(subprocess.run(["docker", "inspect", pebble], cwd=ROOT, check=True,
                                            capture_output=True, text=True, timeout=15).stdout)[0]
        control_addresses = [value["IPAddress"] for name, value in details["NetworkSettings"]["Networks"].items()
                             if name.endswith("_control")]
        if len(control_addresses) != 1:
            raise AssertionError("Pebble has no unique control-network address")
        management = ssl.create_default_context(cafile=str(authority))
        management.check_hostname = False  # Docker IP is not in Pebble's server certificate.
        with urllib.request.urlopen(f"https://{control_addresses[0]}:15000/roots/0",
                                    context=management, timeout=10) as response:
            issued_root = response.read()
        if not issued_root.startswith(b"-----BEGIN CERTIFICATE-----"):
            raise AssertionError("Pebble did not return its current issuance root")
        issued_authority = pathlib.Path(directory) / "issued-root.pem"
        issued_authority.write_bytes(issued_root)
        context = ssl.create_default_context(cafile=str(issued_authority))
        for edge in ("a", "b"):
            deadline = time.monotonic() + 90
            last_error: Exception | None = None
            while time.monotonic() < deadline:
                try:
                    runtime = subprocess.run(
                        ["docker", "compose", "-f", "compose.dev.yml", "exec", "-T", f"edge-agent-{edge}",
                         "cat", "/var/lib/cdnfoundry/runtime/current/gateway.json"],
                        cwd=ROOT, check=True, capture_output=True, text=True, timeout=10,
                    )
                    gateway = json.loads(runtime.stdout)
                    addresses = [route["address"] for route in gateway["routes"]
                                 if route["hostname"] == hostname and ":" not in route["address"]
                                 and f'{route["address"]}:443' in gateway["listeners"]]
                    if not addresses:
                        raise AssertionError("The issued hostname has no active IPv4 gateway listener")
                    with socket.create_connection((addresses[0], 443), timeout=5) as connection:
                        with context.wrap_socket(connection, server_hostname=hostname) as secured:
                            observed = hashlib.sha256(secured.getpeercert(binary_form=True)).hexdigest()
                            if observed != fingerprint:
                                raise AssertionError("Edge selected a different certificate fingerprint")
                            break
                except (OSError, ssl.SSLError, AssertionError) as error:
                    last_error = error
                    time.sleep(1)
            else:
                detail = (f" verification={last_error.verify_message}"
                          if isinstance(last_error, ssl.SSLCertVerificationError) else "")
                raise AssertionError(f"verified edge HTTPS did not converge on edge {edge}: {type(last_error).__name__}{detail}")


def main() -> None:
    if sql("select count(*) from dns_clusters where enabled and last_health_status='healthy'") == "0":
        raise AssertionError("Phase 5 TLS qualification requires the qualified local PowerDNS cluster")
    if sql("select count(*) from edge_pools where name='shared-default' and enabled") != "1":
        raise AssertionError("Phase 5 TLS qualification requires the shared-default edge pool")
    if int(sql("select count(*) from edges where enabled and registered_at is not null "
               "and last_heartbeat_at > now() - interval '2 minutes'")) < 2:
        raise AssertionError("Verified edge HTTPS requires two freshly enrolled development edges")
    # Pebble does not persist its account registry when its container is
    # recreated, while the development PostgreSQL volume intentionally does.
    # Preserve the account key but force local account rediscovery so a
    # long-lived development stack remains qualifiable.
    artisan(
        "if(str_contains((string)config('services.acme.directory_url'),'pebble')){"
        "App\\Models\\AcmeAccount::query()->update(['account_url'=>null]);}"
    )
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
    artisan(
        f"$domain=App\\Models\\Domain::query()->findOrFail({domain_id});"
        "$pool=App\\Models\\EdgePool::query()->where('name','shared-default')->where('enabled',true)->firstOrFail();"
        "App\\Models\\DomainEdgePlacement::query()->updateOrCreate(['domain_id'=>$domain->id],["
        "'active_pool_id'=>$pool->id,'target_pool_id'=>null,'state'=>'active',"
        "'desired_revision'=>$domain->revision,'drain_after'=>null,'last_error'=>null]);"
        "$domain->update(['active_edge_revision'=>$domain->revision]);"
    )
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
        _, deployments = call("GET", f"/api/domains/{domain_id}/dns/deployment", token=token)
        raise AssertionError(
            f"managed certificate did not become active: tls={last}, "
            f"dns_deployments={deployments['data']}"
        )

    certificate = last["active_certificate"]
    assert certificate["kind"] == "managed", certificate
    assert set(certificate["names"]) == {ZONE, f"*.{ZONE}"}, certificate
    assert "private_key" not in json.dumps(last), last
    verify_edge_https(f"www.{ZONE}", certificate["fingerprint_sha256"])
    _, records = call("GET", f"/api/domains/{domain_id}/dns/records", token=token)
    assert all(not row["name"].startswith("_acme-challenge") for row in records["data"]), records
    deadline = time.monotonic() + 60
    while time.monotonic() < deadline and dig(f"_acme-challenge.{ZONE}", "TXT"):
        time.sleep(1)
    assert not dig(f"_acme-challenge.{ZONE}", "TXT"), "temporary ACME TXT record was not removed"
    raw_key = sql(f"select private_key_ciphertext from tls_certificates where id='{certificate['id']}'")
    assert "PRIVATE KEY" not in raw_key, "managed private key was stored as plaintext"
    print(json.dumps({"result": "passed", "domain_id": domain_id, "zone": ZONE, "certificate_id": certificate["id"]}))


def cleanup() -> None:
    artisan(
        "foreach(App\\Models\\Domain::withTrashed()->where('name'," + quote(ZONE) + ")->get() as $d){"
        "foreach(App\\Models\\DnsCluster::all() as $c){try{app(App\\Support\\PowerDnsClient::class)->deleteZone($c,$d->name);}catch(Throwable $e){}}"
        "$d->forceDelete();}"
        "App\\Models\\User::query()->where('email'," + quote(EMAIL) + ")->delete();"
    )


if __name__ == "__main__":
    try:
        main()
    finally:
        try:
            cleanup()
        except Exception as cleanup_error:
            print(f"warning: Phase 5 TLS E2E cleanup failed: {cleanup_error}")
