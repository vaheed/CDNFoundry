#!/usr/bin/env python3
"""Real Phase 4 control-plane, edge distribution, pool migration, and PowerDNS qualification."""

from __future__ import annotations

import base64
import json
import os
import pathlib
import secrets
import subprocess
import tempfile
import time
import urllib.error
import urllib.request
import uuid

BASE = os.environ.get("CDNF_BASE_URL", "http://localhost:8080").rstrip("/")
COMPOSE = os.environ.get("CDNF_COMPOSE_FILE", "compose.dev.yml")
ROOT = pathlib.Path(__file__).resolve().parents[2]
RUN = f"{int(time.time())}-{secrets.token_hex(3)}"
EMAIL = f"phase4-{RUN}@example.test"
PASSWORD = f"Phase4-{secrets.token_urlsafe(20)}"
ZONE = f"phase4-{RUN}.test"
EDGE_NAMES = [f"phase4-edge-a-{RUN}", f"phase4-edge-b-{RUN}"]
EDGE_CONTROL_NAME = f"cdnf-phase4-control-{RUN}"


def edge_call(method: str, path: str, payload: object | None, identity: dict[str, str]) -> tuple[int, object]:
    arguments = [
        "docker", "run", "--rm", "--network", "cdnfoundry-dev_control",
        "-v", f"{identity['directory']}:/tls:ro", "curlimages/curl:8.16.0",
        "-sS", "--cacert", "/tls/ca.crt", "--cert", f"/tls/{identity['certificate']}",
        "--key", f"/tls/{identity['key']}", "-X", method,
        "-H", "Accept: application/json", "-w", "\n%{http_code}",
    ]
    if payload is not None:
        arguments += ["-H", "Content-Type: application/json", "--data", json.dumps(payload)]
    arguments.append(f"https://{EDGE_CONTROL_NAME}:8443{path}")
    result = subprocess.run(arguments, cwd=ROOT, check=True, capture_output=True, text=True, timeout=30)
    body, status_text = result.stdout.rsplit("\n", 1)
    status = int(status_text)
    parsed = json.loads(body) if body else {}
    if status >= 400:
        raise AssertionError(f"{method} {path} returned {status}: {body}")
    return status, parsed


def call(
    method: str,
    path: str,
    payload: object | None = None,
    token: str | None = None,
    headers: dict[str, str] | None = None,
    context: dict[str, str] | None = None,
) -> tuple[int, object]:
    if path.startswith("/edge/"):
        if context is None:
            raise AssertionError(f"an mTLS identity is required for {path}")
        return edge_call(method, path, payload, context)
    request_headers = {"Accept": "application/json", **(headers or {})}
    body = None
    if payload is not None:
        request_headers["Content-Type"] = "application/json"
        body = json.dumps(payload).encode()
        if path.startswith("/api/"):
            request_headers["Idempotency-Key"] = str(uuid.uuid4())
    if token:
        request_headers["Authorization"] = f"Bearer {token}"
    request = urllib.request.Request(BASE + path, data=body, headers=request_headers, method=method)
    try:
        with urllib.request.urlopen(request, timeout=20) as response:
            raw = response.read()
            return response.status, json.loads(raw) if raw else {}
    except urllib.error.HTTPError as error:
        raise AssertionError(f"{method} {path} returned {error.code}: {error.read().decode()}") from error


def artisan(expression: str) -> None:
    subprocess.run(
        ["docker", "compose", "-f", COMPOSE, "exec", "-T", "core", "php", "artisan", "tinker", f"--execute={expression}"],
        cwd=ROOT,
        check=True,
        timeout=45,
        stdout=subprocess.DEVNULL,
    )


def quote(value: str) -> str:
    return "'" + value.replace("\\", "\\\\").replace("'", "\\'") + "'"


def sql_value(statement: str) -> str:
    result = subprocess.run(
        ["docker", "compose", "-f", COMPOSE, "exec", "-T", "control-db", "psql", "-U", "cdnf", "-d", "cdnf", "-Atc", statement],
        cwd=ROOT, check=True, capture_output=True, text=True, timeout=20,
    )
    return result.stdout.strip()


def wait_platform_deployment(token: str) -> int:
    _, settings = call("GET", "/api/admin/system/settings/dns", token=token)
    desired = int(settings["data"]["revision"])
    deadline = time.monotonic() + 60
    while time.monotonic() < deadline:
        deployed = sql_value("SELECT COALESCE(MIN(deployed_revision),0) FROM platform_dns_deployments WHERE status='succeeded'")
        if int(deployed or 0) >= desired:
            return desired
        time.sleep(0.5)
    raise AssertionError(f"platform DNS deployment did not reach revision {desired}")


def wait_operation(token: str, operation_id: str, timeout: int = 60) -> dict:
    deadline = time.monotonic() + timeout
    while time.monotonic() < deadline:
        _, response = call("GET", f"/api/operations/{operation_id}", token=token)
        operation = response["data"]
        if operation["status"] == "succeeded":
            return operation
        if operation["status"] == "failed":
            raise AssertionError(operation)
        time.sleep(0.5)
    raise AssertionError(f"operation {operation_id} did not finish")


def dig(owner: str, record_type: str) -> list[str]:
    result = subprocess.run(
        ["dig", "@127.0.0.1", "-p", "1053", owner, record_type, "+short"],
        cwd=ROOT,
        check=True,
        text=True,
        capture_output=True,
        timeout=10,
    )
    return [line.strip() for line in result.stdout.splitlines() if line.strip()]


def wait_answers(owner: str, record_type: str, expected: set[str], timeout: int = 60) -> list[str]:
    deadline = time.monotonic() + timeout
    answers: list[str] = []
    while time.monotonic() < deadline:
        answers = dig(owner, record_type)
        if expected <= set(answers):
            return answers
        time.sleep(0.5)
    raise AssertionError(f"{owner} {record_type} did not contain {expected}; got {answers}")


def wait_deployment(token: str, domain_id: int, timeout: int = 60) -> None:
    deadline = time.monotonic() + timeout
    while time.monotonic() < deadline:
        _, response = call("GET", f"/api/domains/{domain_id}/dns/deployment", token=token)
        deployments = response["data"]["deployments"]
        revision = response["data"]["desired_revision"]
        if deployments and all(row["status"] == "succeeded" and row["deployed_revision"] == revision for row in deployments):
            return
        if any(row["status"] == "failed" for row in deployments):
            raise AssertionError(response)
        time.sleep(0.5)
    raise AssertionError("domain DNS deployment did not converge")


def wait_isolation(token: str, domain_id: int, state: str, drain_scheduled: bool | None = None) -> dict:
    deadline = time.monotonic() + 60
    placement: dict = {}
    while time.monotonic() < deadline:
        _, response = call("GET", f"/api/admin/domains/{domain_id}/isolation", token=token)
        placement = response["data"]
        scheduled = placement.get("drain_after") is not None
        if placement["state"] == state and (drain_scheduled is None or scheduled == drain_scheduled):
            return placement
        if placement["state"] == "failed":
            raise AssertionError(placement)
        time.sleep(0.5)
    raise AssertionError(f"placement did not reach {state}: {placement}")


def ensure_cluster(token: str) -> dict:
    _, response = call("GET", "/api/admin/dns/clusters", token=token)
    cluster = next((row for row in response["data"] if row["api_url"] == "http://pdns-auth:8081"), None)
    if cluster is None:
        _, created = call("POST", "/api/admin/dns/clusters", {
            "name": f"phase4-pdns-{RUN}",
            "location": "local-compose",
            "api_url": "http://pdns-auth:8081",
            "api_key": "pdns-dev-api-key",
            "server_id": "localhost",
            "nameservers": [{"hostname": "ns1.cdnf.test"}, {"hostname": "ns2.cdnf.test"}],
            "capacity_zones": 100000,
        }, token)
        cluster = created["data"]
        wait_operation(token, created["operation_id"])
        _, refreshed = call("GET", f"/api/admin/dns/clusters/{cluster['id']}", token=token)
        cluster = refreshed["data"]
    elif cluster["last_health_status"] != "healthy":
        _, test = call("POST", f"/api/admin/dns/clusters/{cluster['id']}/test", {}, token)
        wait_operation(token, test["data"]["id"])
        _, refreshed = call("GET", f"/api/admin/dns/clusters/{cluster['id']}", token=token)
        cluster = refreshed["data"]
    return cluster


def apply_platform_identity(token: str) -> None:
    settings = {
        "platform_domain": "cdnf.test",
        "proxy_hostname": "proxy.cdnf.test",
        "nameservers": [
            {"hostname": "ns1.cdnf.test", "ipv4": "192.0.2.10", "ipv6": "2001:db8::10"},
            {"hostname": "ns2.cdnf.test", "ipv4": "192.0.2.11", "ipv6": "2001:db8::11"},
        ],
        "soa_primary": "ns1.cdnf.test",
        "soa_mailbox": "hostmaster.cdnf.test",
        "soa_refresh": 3600,
        "soa_retry": 600,
        "soa_expire": 1209600,
        "soa_minimum_ttl": 60,
        "default_ttl": 60,
        "cluster_targets": ["pdns-auth:8081"],
    }
    _, preview = call("POST", "/api/admin/system/settings/dns/validate", settings, token)
    _, operation = call("PATCH", "/api/admin/system/settings/dns", {
        **settings,
        "confirmation_token": preview["data"]["confirmation_token"],
    }, token)
    wait_operation(token, operation["data"]["id"])


def start_edge_control(directory: pathlib.Path) -> pathlib.Path:
    directory.chmod(0o755)
    subprocess.run([
        "openssl", "req", "-x509", "-newkey", "rsa:2048", "-nodes", "-days", "1",
        "-subj", "/CN=Phase 4 Edge Identity CA", "-keyout", str(directory / "ca.key"), "-out", str(directory / "ca.crt"),
    ], check=True, capture_output=True, text=True, timeout=20)
    subprocess.run([
        "openssl", "req", "-newkey", "rsa:2048", "-nodes", "-subj", f"/CN={EDGE_CONTROL_NAME}",
        "-addext", f"subjectAltName=DNS:{EDGE_CONTROL_NAME}", "-keyout", str(directory / "server.key"), "-out", str(directory / "server.csr"),
    ], check=True, capture_output=True, text=True, timeout=20)
    subprocess.run([
        "openssl", "x509", "-req", "-in", str(directory / "server.csr"), "-CA", str(directory / "ca.crt"),
        "-CAkey", str(directory / "ca.key"), "-CAcreateserial", "-days", "1", "-copy_extensions", "copy", "-out", str(directory / "server.crt"),
    ], check=True, capture_output=True, text=True, timeout=20)
    for path in directory.iterdir():
        path.chmod(0o644)
    subprocess.run([
        "docker", "run", "-d", "--name", EDGE_CONTROL_NAME,
        "--network", "cdnfoundry-dev_control",
        "-v", f"{ROOT / 'core/public'}:/app/public:ro",
        "-v", f"{ROOT / 'docker/nginx/edge-control.conf'}:/etc/nginx/conf.d/default.conf:ro",
        "-v", f"{directory / 'server.crt'}:/run/secrets/edge-control-server.crt:ro",
        "-v", f"{directory / 'server.key'}:/run/secrets/edge-control-server.key:ro",
        "-v", f"{directory / 'ca.crt'}:/run/secrets/edge-identity-ca.crt:ro",
        "nginx:1.30.3-alpine",
    ], cwd=ROOT, check=True, capture_output=True, text=True, timeout=30)
    time.sleep(0.5)
    running = subprocess.run(
        ["docker", "inspect", "-f", "{{.State.Running}}", EDGE_CONTROL_NAME],
        cwd=ROOT, check=True, capture_output=True, text=True, timeout=10,
    ).stdout.strip()
    if running != "true":
        logs = subprocess.run(["docker", "logs", EDGE_CONTROL_NAME], cwd=ROOT, capture_output=True, text=True, timeout=10)
        raise AssertionError(f"edge-control failed to start: {logs.stdout}{logs.stderr}")
    health = subprocess.run([
        "docker", "run", "--rm", "--network", "cdnfoundry-dev_control", "-v", f"{directory}:/tls:ro",
        "curlimages/curl:8.16.0", "-sS", "--cacert", "/tls/ca.crt", "-o", "/dev/null", "-w", "%{http_code}",
        f"https://{EDGE_CONTROL_NAME}:8443/mtls-health",
    ], cwd=ROOT, check=True, capture_output=True, text=True, timeout=30)
    if health.stdout != "401":
        raise AssertionError(f"edge-control unauthenticated boundary returned {health.stdout}")
    return directory / "ca.crt"


def provision_edge(token: str, name: str, country: str, continent: str, ipv4: str, ipv6: str, directory: pathlib.Path, serial: str) -> dict:
    _, created = call("POST", "/api/admin/edges", {
        "name": name,
        "country_code": country,
        "continent_code": continent,
        "ipv4": ipv4,
        "ipv6": ipv6,
    }, token)
    edge = created["data"]
    key = directory / f"{name}.key"
    csr = directory / f"{name}.csr"
    certificate = directory / f"{name}.crt"
    subprocess.run([
        "openssl", "req", "-newkey", "rsa:2048", "-nodes", "-subj", f"/CN={edge['id']}",
        "-keyout", str(key), "-out", str(csr),
    ], check=True, capture_output=True, text=True, timeout=20)
    extension = directory / f"{name}.ext"
    extension.write_text("extendedKeyUsage=clientAuth\n")
    subprocess.run([
        "openssl", "x509", "-req", "-in", str(csr), "-CA", str(directory / "ca.crt"), "-CAkey", str(directory / "ca.key"),
        "-days", "1", "-set_serial", f"0x{serial}", "-extfile", str(extension), "-out", str(certificate),
    ], check=True, capture_output=True, text=True, timeout=20)
    key.chmod(0o644)
    certificate.chmod(0o644)
    artisan(
        "App\\Models\\Edge::query()->whereKey(" + quote(edge["id"]) + ")->update(["
        f"'registered_at'=>now(),'identity_certificate_serial'=>{quote(serial.upper())},"
        "'identity_certificate_expires_at'=>now()->addDay(),'identity_revoked_at'=>null,'bootstrap_token_hash'=>null,'agent_version'=>'1.0.0']);"
    )
    return {**edge, "context": {
        "directory": str(directory),
        "certificate": certificate.name,
        "key": key.name,
    }}


def heartbeat(edge: dict, active_sequence: int, quarantine_ready: bool = False) -> None:
    cells = [{
        "name": "shared-default",
        "status": "ready",
        "capacity": {"assigned_domain_count": 0, "active_connections": 0, "requests_per_second": 0},
    }]
    if quarantine_ready:
        cells.append({
            "name": "quarantine-default",
            "status": "ready",
            "capacity": {"assigned_domain_count": 0, "active_connections": 0, "requests_per_second": 0},
        })
    call("POST", "/edge/v1/heartbeat", {
        "agent_version": "1.0.0",
        "listener_ready": True,
        "active_sequence": active_sequence,
        "cells": cells,
    }, context=edge["context"])


def latest_artifact(edge: dict, after: int = 0) -> tuple[int, dict]:
    deadline = time.monotonic() + 60
    rows: list[dict] = []
    while time.monotonic() < deadline:
        _, manifest = call("GET", f"/edge/v1/config/manifest?cursor={after}", context=edge["context"])
        rows = manifest["data"]
        if rows:
            row = rows[-1]
            _, artifact = call("GET", f"/edge/v1/config/artifacts/{row['checksum']}", context=edge["context"])
            payload = json.loads(base64.b64decode(artifact["encoded_payload"]))
            return int(row["sequence"]), payload
        time.sleep(0.5)
    raise AssertionError(f"edge {edge['id']} received no artifact after {after}: {rows}")


def acknowledge(edge: dict, sequence: int) -> None:
    call("POST", "/edge/v1/config/applied", {"sequence": sequence}, context=edge["context"])


def main() -> None:
    artisan(
        "App\\Models\\User::query()->create(["
        f"'name'=>'Phase 4 E2E','email'=>{quote(EMAIL)},"
        f"'password'=>Illuminate\\Support\\Facades\\Hash::make({quote(PASSWORD)}),'type'=>'admin']);"
    )
    _, login = call("POST", "/api/auth/login", {"email": EMAIL, "password": PASSWORD, "device_name": "phase4-e2e"})
    token = login["data"]["token"]
    cluster = ensure_cluster(token)
    apply_platform_identity(token)
    if not cluster["enabled"]:
        call("POST", f"/api/admin/dns/clusters/{cluster['id']}/enable", {}, token)

    with tempfile.TemporaryDirectory(prefix="cdnf-phase4-control-") as directory:
        temporary = pathlib.Path(directory)
        start_edge_control(temporary)
        edges = [
            provision_edge(token, EDGE_NAMES[0], "IR", "AS", "203.0.113.101", "2001:db8:4::101", temporary, "401A"),
            provision_edge(token, EDGE_NAMES[1], "DE", "EU", "203.0.113.102", "2001:db8:4::102", temporary, "401B"),
        ]
        for edge in edges:
            heartbeat(edge, 0)
        wait_platform_deployment(token)

        _, pools_response = call("GET", "/api/admin/edge-pools", token=token)
        pools = pools_response["data"]["data"]
        shared = next(pool for pool in pools if pool["name"] == "shared-default")
        quarantine = next(pool for pool in pools if pool["name"] == "quarantine-default")
        shared_global = f"pool-{shared['id']}.global.all.proxy.cdnf.test"
        wait_answers(shared_global, "A", {"203.0.113.101", "203.0.113.102"})
        wait_answers(shared_global, "AAAA", {"2001:db8:4::101", "2001:db8:4::102"})

        call("POST", f"/api/admin/edges/{edges[0]['id']}/drain", {}, token)
        deadline = time.monotonic() + 60
        while time.monotonic() < deadline and set(dig(shared_global, "A")) != {"203.0.113.102"}:
            time.sleep(0.5)
        assert set(dig(shared_global, "A")) == {"203.0.113.102"}
        call("POST", f"/api/admin/edges/{edges[0]['id']}/undrain", {}, token)
        wait_answers(shared_global, "A", {"203.0.113.101", "203.0.113.102"})

        _, created_domain = call("POST", "/api/domains", {"name": ZONE}, token)
        domain_id = created_domain["data"]["id"]
        call("POST", f"/api/admin/domains/{domain_id}/force-verify", {}, token)
        call("POST", f"/api/domains/{domain_id}/activate", {}, token)
        origin = {
            "host": "8.8.8.8", "port": 80, "scheme": "http", "host_header": ZONE,
            "sni": None, "verify_tls": False, "connect_timeout_ms": 1000,
            "response_timeout_ms": 5000, "retry_count": 0,
        }
        call("POST", f"/api/domains/{domain_id}/dns/records", {
            "type": "A", "name": "@", "ttl": 60, "mode": "proxied", "origin": origin,
        }, token)
        call("POST", f"/api/domains/{domain_id}/dns/records", {
            "type": "A", "name": "www", "ttl": 60, "mode": "proxied",
            "origin": {**origin, "host": "1.1.1.1", "host_header": f"www.{ZONE}"},
        }, token)

        sequences: dict[str, int] = {}
        for edge in edges:
            sequence, payload = latest_artifact(edge)
            assert payload["pools"] == ["shared-default"], payload
            sequences[edge["id"]] = sequence
            acknowledge(edge, sequence)
        wait_isolation(token, domain_id, "active")
        wait_deployment(token, domain_id)
        assert dig(f"www.{ZONE}", "CNAME") == [f"pool-{shared['id']}.proxy.cdnf.test."]
        assert set(dig(ZONE, "A")) <= {"203.0.113.101", "203.0.113.102"}
        assert set(dig(ZONE, "AAAA")) <= {"2001:db8:4::101", "2001:db8:4::102"}

        for index, edge in enumerate(edges):
            _, detail = call("GET", f"/api/admin/edges/{edge['id']}", token=token)
            cell = next(row for row in detail["data"]["cells"] if row["edge_pool_id"] == quarantine["id"])
            call("PATCH", f"/api/admin/edge-cells/{cell['id']}", {
                "service_ipv4": f"198.51.100.{101 + index}",
                "service_ipv6": f"2001:db8:44::{101 + index}",
            }, token)
            heartbeat(edge, sequences[edge["id"]], quarantine_ready=True)

        _, moved = call("POST", f"/api/admin/domains/{domain_id}/move", {"pool_id": quarantine["id"]}, token)
        move_operation = moved["data"]["operation_id"]
        for edge in edges:
            sequence, payload = latest_artifact(edge, sequences[edge["id"]])
            assert set(payload["pools"]) == {"shared-default", "quarantine-default"}, payload
            sequences[edge["id"]] = sequence
            acknowledge(edge, sequence)
        placement = wait_isolation(token, domain_id, "draining", drain_scheduled=True)
        assert placement["active_pool_id"] == shared["id"] and placement["target_pool_id"] == quarantine["id"]
        _, moving_operation = call("GET", f"/api/operations/{move_operation}", token=token)
        assert moving_operation["data"]["status"] == "running", moving_operation
        wait_deployment(token, domain_id)
        assert dig(f"www.{ZONE}", "CNAME") == [f"pool-{quarantine['id']}.proxy.cdnf.test."]
        quarantine_global = f"pool-{quarantine['id']}.global.all.proxy.cdnf.test"
        wait_answers(quarantine_global, "A", {"198.51.100.101", "198.51.100.102"})
        wait_answers(quarantine_global, "AAAA", {"2001:db8:44::101", "2001:db8:44::102"})

        artisan(
            f"App\\Models\\DomainEdgePlacement::query()->where('domain_id',{domain_id})->update(['drain_after'=>now()->subSecond()]);"
            "Illuminate\\Support\\Facades\\Artisan::call('edge:complete-placement-drains');"
        )
        wait_isolation(token, domain_id, "deploying", drain_scheduled=False)
        for edge in edges:
            sequence, payload = latest_artifact(edge, sequences[edge["id"]])
            assert payload["pools"] == ["quarantine-default"], payload
            sequences[edge["id"]] = sequence
            acknowledge(edge, sequence)
        final = wait_isolation(token, domain_id, "active", drain_scheduled=False)
        assert final["active_pool_id"] == quarantine["id"] and final["target_pool_id"] is None
        wait_operation(token, move_operation)
        assert dig(f"www.{ZONE}", "CNAME") == [f"pool-{quarantine['id']}.proxy.cdnf.test."]

    print("phase4_control_plane_e2e=passed edges=2 pool_dns=ipv4+ipv6 drain=passed migration=acknowledged")


if __name__ == "__main__":
    try:
        main()
    finally:
        subprocess.run(["docker", "rm", "-f", EDGE_CONTROL_NAME], cwd=ROOT, check=False, capture_output=True, text=True)
        try:
            artisan(
                "foreach(App\\Models\\Domain::withTrashed()->where('name'," + quote(ZONE) + ")->get() as $d){"
                "foreach(App\\Models\\DnsCluster::all() as $c){try{app(App\\Support\\PowerDnsClient::class)->deleteZone($c,$d->name);}catch(Throwable $e){}}"
                "$d->forceDelete();}"
                "App\\Models\\Edge::query()->whereIn('name',[" + ",".join(quote(name) for name in EDGE_NAMES) + "])->delete();"
                "App\\Models\\User::query()->where('email'," + quote(EMAIL) + ")->delete();"
                "App\\Jobs\\ReconcilePlatformDnsIdentity::dispatch();"
            )
        except Exception as cleanup_error:
            print(f"warning: Phase 4 E2E cleanup failed: {cleanup_error}")
