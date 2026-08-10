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
CONTROL_NETWORK = os.environ.get(
    "CDNF_CONTROL_NETWORK",
    f"{os.environ.get('COMPOSE_PROJECT_NAME', 'cdnfoundry-dev')}_control",
)


def edge_call(method: str, path: str, payload: object | None, identity: dict[str, str]) -> tuple[int, object]:
    arguments = [
        "docker", "run", "--rm", "--network", CONTROL_NETWORK,
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
            request_headers.setdefault("Idempotency-Key", str(uuid.uuid4()))
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


def runtime_artisan(expression: str) -> None:
    subprocess.run(
        ["docker", "compose", "-f", COMPOSE, "exec", "-T", "horizon", "php", "artisan", "tinker", f"--execute={expression}"],
        cwd=ROOT,
        check=True,
        timeout=45,
        stdout=subprocess.DEVNULL,
    )


def wait_runtime_queue_idle(timeout: int = 30) -> None:
    keys = (
        "laravel-database-queues:runtime",
        "laravel-database-queues:runtime:reserved",
        "laravel-database-queues:runtime:delayed",
    )
    deadline = time.monotonic() + timeout
    idle_observations = 0
    while time.monotonic() < deadline:
        result = subprocess.run(
            ["docker", "compose", "-f", COMPOSE, "exec", "-T", "redis", "redis-cli", "--raw", "EVAL",
             "return {redis.call('llen',KEYS[1]),redis.call('zcard',KEYS[2]),redis.call('zcard',KEYS[3])}", "3", *keys],
            cwd=ROOT, check=True, capture_output=True, text=True, timeout=10,
        )
        depths = [int(value) for value in result.stdout.splitlines() if value.strip()]
        idle_observations = idle_observations + 1 if depths == [0, 0, 0] else 0
        if idle_observations >= 2:
            return
        time.sleep(0.5)
    raise AssertionError("runtime queue did not become idle")


def reconcile_platform_dns() -> None:
    wait_runtime_queue_idle()
    runtime_artisan(
        "$job = new App\\Jobs\\ReconcilePlatformDnsIdentity(); "
        "$job->handle(app(App\\Support\\PowerDnsClient::class));"
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


def reconcile_until_answers(owner: str, expected: dict[str, set[str]], timeout: int = 90) -> None:
    deadline = time.monotonic() + timeout
    answers: dict[str, list[str]] = {}
    while time.monotonic() < deadline:
        reconcile_platform_dns()
        answers = {record_type: dig(owner, record_type) for record_type in expected}
        if all(set(rows) == expected[record_type] for record_type, rows in answers.items()):
            return
        time.sleep(1)
    raise AssertionError(f"{owner} DNS did not converge to {expected}; got {answers}")


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
            {"hostname": "ns1.cdnf.test", "ipv4": "8.8.8.8", "ipv6": "2001:4860:4860::8888"},
            {"hostname": "ns2.cdnf.test", "ipv4": "1.1.1.1", "ipv6": "2606:4700:4700::1111"},
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
        "--network", CONTROL_NETWORK,
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
        "docker", "run", "--rm", "--network", CONTROL_NETWORK, "-v", f"{directory}:/tls:ro",
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
        "management_ipv4": ipv4,
        "management_ipv6": ipv6,
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
        "'identity_certificate_expires_at'=>now()->addDay(),'identity_revoked_at'=>null,'bootstrap_token_hash'=>null,'agent_version'=>'1.2.0']);"
    )
    return {**edge, "context": {
        "directory": str(directory),
        "certificate": certificate.name,
        "key": key.name,
    }}


def heartbeat(edge: dict, active_sequence: int, quarantine_ready: bool = False) -> None:
    cells = [{
        "name": name,
        "status": "ready",
        "capacity": {"assigned_domain_count": 0, "active_connections": 0, "requests_per_second": 0},
    } for name in edge.get("shared_cells", ["cell-01"])]
    if quarantine_ready and edge.get("quarantine_cell"):
        cells.append({
            "name": edge["quarantine_cell"],
            "status": "ready",
            "capacity": {"assigned_domain_count": 0, "active_connections": 0, "requests_per_second": 0},
        })
    call("POST", "/edge/v1/heartbeat", {
        "agent_version": "1.2.0",
        "listener_ready": True,
        "active_sequence": active_sequence,
        "cells": cells,
        "gateway": {"ready": True, "active_revision": max(1000, active_sequence), "routes": 1, "listeners": 2},
    }, context=edge["context"])


def latest_artifact(
    edge: dict,
    domain_id: int,
    after: int = 0,
    minimum_revision: int | None = None,
    timeout: int = 60,
) -> tuple[int, dict]:
    deadline = time.monotonic() + timeout
    observed: list[dict] = []
    while time.monotonic() < deadline:
        _, manifest = call("GET", f"/edge/v1/config/manifest?cursor={after}", context=edge["context"])
        observed = [
            row for row in manifest["data"]
            if int(row.get("domain_id") or 0) == domain_id
        ]
        rows = observed
        if minimum_revision is not None:
            rows = [row for row in rows if int(row["revision"]) >= minimum_revision]
        if rows:
            row = max(rows, key=lambda candidate: int(candidate["sequence"]))
            _, artifact = call("GET", f"/edge/v1/config/artifacts/{row['checksum']}", context=edge["context"])
            payload = json.loads(base64.b64decode(artifact["encoded_payload"]))
            return int(row["sequence"]), payload
        time.sleep(0.5)
    raise AssertionError(
        f"edge {edge['id']} received no matching artifact after {after}; "
        f"minimum_revision={minimum_revision}, observed={observed}"
    )


def acknowledge(edge: dict, sequence: int, quarantine_ready: bool = False) -> None:
    call("POST", "/edge/v1/config/applied", {"sequence": sequence}, context=edge["context"])
    heartbeat(edge, sequence, quarantine_ready=quarantine_ready)


def converge_placement_artifacts(
    token: str,
    domain_id: int,
    edges: list[dict],
    sequences: dict[str, int],
    minimum_revision: int,
    expected_state: str,
    expected_pools: set[str],
    drain_scheduled: bool,
    payload_check=None,
) -> tuple[dict, dict[str, int]]:
    deadline = time.monotonic() + 120
    applied_revisions: dict[str, int] = {}
    placement: dict = {}
    errors: dict[str, str] = {}
    while time.monotonic() < deadline:
        _, response = call("GET", f"/api/admin/domains/{domain_id}/isolation", token=token)
        placement = response["data"]
        desired_revision = max(minimum_revision, int(placement["desired_revision"]))
        for edge in edges:
            edge_id = edge["id"]
            if applied_revisions.get(edge_id, 0) >= desired_revision:
                continue
            try:
                sequence, payload = latest_artifact(
                    edge,
                    domain_id,
                    sequences[edge_id],
                    minimum_revision=desired_revision,
                    timeout=5,
                )
            except AssertionError as error:
                errors[edge_id] = str(error)
                continue
            revision = int(payload["revision"])
            assert set(payload["pools"]) == expected_pools, payload
            if payload_check is not None:
                payload_check(payload)
            sequences[edge_id] = sequence
            acknowledge(
                edge,
                sequence,
                quarantine_ready="quarantine-default" in expected_pools,
            )
            applied_revisions[edge_id] = revision
            errors.pop(edge_id, None)

        # A real agent keeps reporting readiness while it downloads and
        # acknowledges placement artifacts. Preserve that contract in this
        # direct control-plane qualification so a busy persistent database
        # cannot make the synthetic edges stale before promotion runs.
        for edge in edges:
            heartbeat(
                edge,
                sequences[edge["id"]],
                quarantine_ready="quarantine-default" in expected_pools,
            )

        _, response = call("GET", f"/api/admin/domains/{domain_id}/isolation", token=token)
        placement = response["data"]
        desired_revision = int(placement["desired_revision"])
        scheduled = placement.get("drain_after") is not None
        all_current = all(applied_revisions.get(edge["id"], 0) >= desired_revision for edge in edges)
        if placement["state"] == expected_state and all_current and (not drain_scheduled or scheduled):
            return placement, applied_revisions
        time.sleep(0.25)
    raise AssertionError(
        f"placement artifacts did not converge to {expected_state}: "
        f"placement={placement}, applied_revisions={applied_revisions}, errors={errors}"
    )


def pending_purge_task(edge: dict, purge_id: str, timeout: int = 30) -> dict:
    deadline = time.monotonic() + timeout
    rows: list[dict] = []
    while time.monotonic() < deadline:
        _, response = call("GET", "/edge/v1/tasks", context=edge["context"])
        rows = response["data"]
        task = next((row for row in rows if row.get("cache_purge_id") == purge_id), None)
        if task is not None:
            return task
        time.sleep(0.25)
    raise AssertionError(f"edge {edge['id']} received no pending task for purge {purge_id}: {rows}")


def complete_purge_task(edge: dict, task_id: str, status: str = "succeeded") -> None:
    result = {"status": "completed" if status == "succeeded" else "failed"}
    if status == "failed":
        result["failure_reason"] = "cache_purge_control_failed"
    call("POST", f"/edge/v1/tasks/{task_id}/result", {
        "status": status,
        "result": result,
    }, context=edge["context"])


def exercise_phase5_cache(token: str, domain_id: int, edges: list[dict], sequences: dict[str, int], baseline_revision: int) -> None:
    settings = {
        "enabled": True,
        "edge_ttl_seconds": 120,
        "browser_ttl_seconds": 45,
        "maximum_object_bytes": 1048576,
        "respect_origin_headers": True,
        "include_query_string": True,
        "bypass_cookie_names": ["session_id"],
        "stale_if_error_seconds": 30,
    }
    status, changed = call("PATCH", f"/api/domains/{domain_id}/cache", settings, token)
    assert status == 202 and changed["data"]["settings"]["query_policy"] == "include_all", changed
    assert all(changed["data"]["settings"][key] == value for key, value in settings.items() if key != "include_query_string"), changed

    def check_changed_cache(payload: dict) -> None:
        assert payload["cache"]["edge_ttl_seconds"] == 120, payload["cache"]
        assert payload["cache"]["bypass_cookie_names"] == ["session_id"], payload["cache"]

    _, placement = call("GET", f"/api/admin/domains/{domain_id}/isolation", token=token)
    converge_placement_artifacts(
        token, domain_id, edges, sequences, int(placement["data"]["desired_revision"]),
        "active", {"shared-default"}, drain_scheduled=False, payload_check=check_changed_cache,
    )

    call("POST", f"/api/domains/{domain_id}/rollback", {"revision": baseline_revision}, token)
    rollback_epochs: set[int] = set()

    def check_rollback_cache(payload: dict) -> None:
        assert payload["cache"]["edge_ttl_seconds"] == 3600, payload["cache"]
        rollback_epochs.add(int(payload["cache"]["epoch"]))

    _, placement = call("GET", f"/api/admin/domains/{domain_id}/isolation", token=token)
    converge_placement_artifacts(
        token, domain_id, edges, sequences, int(placement["data"]["desired_revision"]),
        "active", {"shared-default"}, drain_scheduled=False, payload_check=check_rollback_cache,
    )
    assert len(rollback_epochs) == 1, rollback_epochs

    replay_key = str(uuid.uuid4())
    headers = {"Idempotency-Key": replay_key}
    status, first = call("POST", f"/api/domains/{domain_id}/cache/purge", {"type": "all"}, token, headers=headers)
    replay_status, replay = call("POST", f"/api/domains/{domain_id}/cache/purge", {"type": "all"}, token, headers=headers)
    assert status == replay_status == 202 and first == replay, (first, replay)
    purge_id = first["data"]["id"]
    assert int(first["data"]["cache_epoch"]) > next(iter(rollback_epochs)), first

    full_tasks = [pending_purge_task(edge, purge_id) for edge in edges]
    complete_purge_task(edges[0], full_tasks[0]["id"], "failed")
    _, pending = call("GET", "/edge/v1/tasks", context=edges[0]["context"])
    assert all(row["id"] != full_tasks[0]["id"] for row in pending["data"]), pending
    complete_purge_task(edges[1], full_tasks[1]["id"])
    sql_value(f"UPDATE edge_tasks SET available_at=NOW()-INTERVAL '1 second' WHERE id='{full_tasks[0]['id']}'")
    retried = pending_purge_task(edges[0], purge_id)
    assert retried["id"] == full_tasks[0]["id"], retried
    complete_purge_task(edges[0], retried["id"])
    attempts = sql_value(f"SELECT attempts FROM edge_tasks WHERE id='{retried['id']}'")
    assert attempts == "2", attempts

    def check_purged_cache(payload: dict) -> None:
        assert int(payload["cache"]["epoch"]) == int(first["data"]["cache_epoch"]), payload["cache"]

    _, placement = call("GET", f"/api/admin/domains/{domain_id}/isolation", token=token)
    converge_placement_artifacts(
        token, domain_id, edges, sequences, int(placement["data"]["desired_revision"]),
        "active", {"shared-default"}, drain_scheduled=False, payload_check=check_purged_cache,
    )

    status, url_purge = call("POST", f"/api/domains/{domain_id}/cache/purge", {
        "type": "urls",
        "urls": [f"https://{ZONE}/asset.css?b=2&a=1", f"http://www.{ZONE}/logo.png"],
    }, token)
    assert status == 202 and url_purge["data"]["url_count"] == 2, url_purge
    expected_keys = [f"https|{ZONE}|/asset.css?a=1&b=2", f"http|www.{ZONE}|/logo.png"]
    for edge in edges:
        task = pending_purge_task(edge, url_purge["data"]["id"])
        assert task["payload"]["cache_keys"] == expected_keys, task
        complete_purge_task(edge, task["id"])

    _, persisted = call("GET", f"/api/domains/{domain_id}/cache", token=token)
    assert persisted["data"]["cache_epoch"] == first["data"]["cache_epoch"], persisted
    print("phase5_cache_control_plane_e2e=passed propagation=acked rollback=acked purge_retry=2 idempotency=replayed")


def exercise_simple_anycast(token: str, edges: list[dict]) -> None:
    _, created = call("POST", "/api/admin/edge-pools", {
        "name": f"anycast-{RUN}", "kind": "reserved", "routing_mode": "simple_anycast",
        "anycast_ipv4": "208.67.222.222", "anycast_ipv6": "2620:119:35::35",
        "minimum_ready_cells": 1, "replicas_per_edge": 1, "maximum_domains_per_cell": 20000,
    }, token)
    pool = created["data"]
    for edge in edges:
        _, detail = call("GET", f"/api/admin/edges/{edge['id']}", token=token)
        cell = next(row for row in detail["data"]["cells"] if row["edge_pool_id"] is None)
        call("PUT", f"/api/admin/edge-pools/{pool['id']}/cells/{cell['id']}", {}, token)
        edge["shared_cells"].append(cell["name"])
        heartbeat(edge, 0)

    call("POST", f"/api/admin/edge-pools/{pool['id']}/enable", {}, token)
    for edge in edges:
        heartbeat(edge, 0)
        _, candidate = call("GET", "/edge/v1/gateway/config", context=edge["context"])
        addresses = {binding["address"] for binding in candidate["data"]["bindings"] if binding["pool"] == pool["name"]}
        assert addresses == {"208.67.222.222", "2620:119:35::35"}, candidate

    hostname = f"pool-{pool['id']}.proxy.cdnf.test"
    _, status = call("GET", f"/api/admin/edge-pools/{pool['id']}", token=token)
    assert status["data"]["routing_status"] == "ready", status
    reconcile_until_answers(hostname, {
        "A": {"208.67.222.222"}, "AAAA": {"2620:119:35::35"},
    })
    call("POST", f"/api/admin/edges/{edges[0]['id']}/drain", {}, token)
    wait_answers(hostname, "A", {"208.67.222.222"})
    _, status = call("GET", f"/api/admin/edge-pools/{pool['id']}", token=token)
    assert status["data"]["routing_status"] == "degraded", status
    call("POST", f"/api/admin/edges/{edges[0]['id']}/undrain", {}, token)
    call("POST", f"/api/admin/edge-pools/{pool['id']}/withdraw", {}, token)
    reconcile_until_answers(hostname, {"A": set(), "AAAA": set()})
    call("POST", f"/api/admin/edge-pools/{pool['id']}/restore", {}, token)
    reconcile_until_answers(hostname, {"A": {"208.67.222.222"}})
    print("simple_anycast_e2e=passed pops=2 pair=dual-stack coexistence=geo-unicast pop_loss=isolated route_withdrawal=operator-recorded")


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
        service_addresses = [
            ("9.9.9.9", "2620:fe::fe"),
            ("149.112.112.112", "2620:fe::9"),
        ]
        for index, edge in enumerate(edges):
            _, detail = call("GET", f"/api/admin/edges/{edge['id']}", token=token)
            rows = detail["data"]["cells"]
            shared_cells = []
            for cell in [row for row in rows if row["edge_pool_id"] is None][:3]:
                call("PUT", f"/api/admin/edge-pools/{shared['id']}/cells/{cell['id']}", {}, token)
                shared_cells.append(cell)
            assert len(shared_cells) == 3, shared_cells
            edge["shared_cells"] = [cell["name"] for cell in shared_cells]
            quarantine_cell = next(row for row in rows if row["edge_pool_id"] is None and row["id"] not in {cell["id"] for cell in shared_cells})
            call("PUT", f"/api/admin/edge-pools/{quarantine['id']}/cells/{quarantine_cell['id']}", {}, token)
            edge["quarantine_cell"] = quarantine_cell["name"]
            call("POST", f"/api/admin/edge-pools/{shared['id']}/edges/{edge['id']}/endpoint", {
                "ipv4": service_addresses[index][0],
                "ipv6": service_addresses[index][1],
            }, token)
            heartbeat(edge, 0)
            heartbeat(edge, 0)
            _, candidate = call("GET", "/edge/v1/gateway/config", context=edge["context"])
            bindings = candidate["data"]["bindings"]
            assert len(bindings) == 2 and all(len(binding["cells"]) >= 1 for binding in bindings), candidate
        shared_global = f"pool-{shared['id']}.global.all.proxy.cdnf.test"
        wait_answers(shared_global, "A", {"9.9.9.9", "149.112.112.112"})
        wait_answers(shared_global, "AAAA", {"2620:fe::fe", "2620:fe::9"})
        exercise_simple_anycast(token, edges)

        call("POST", f"/api/admin/edges/{edges[0]['id']}/drain", {}, token)
        deadline = time.monotonic() + 60
        drained_answers: set[str] = set()
        while time.monotonic() < deadline:
            drained_answers = set(dig(shared_global, "A"))
            if "9.9.9.9" not in drained_answers and "149.112.112.112" in drained_answers:
                break
            time.sleep(0.5)
        assert "9.9.9.9" not in drained_answers and "149.112.112.112" in drained_answers, drained_answers
        call("POST", f"/api/admin/edges/{edges[0]['id']}/undrain", {}, token)
        wait_answers(shared_global, "A", {"9.9.9.9", "149.112.112.112"})

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
        call("POST", f"/api/domains/{domain_id}/dns/records", {
            "type": "MX", "name": "@", "content": "mail.example.net.", "priority": 10, "ttl": 60,
        }, token)
        call("POST", f"/api/domains/{domain_id}/dns/records", {
            "type": "TXT", "name": "@", "content": "proxy-apex=qualified", "ttl": 60,
        }, token)
        call("POST", f"/api/domains/{domain_id}/dns/records", {
            "type": "CAA", "name": "@", "content": "0 issue letsencrypt.org", "ttl": 60,
        }, token)
        # The persistent development topology may contain other enabled shared
        # pools from owner qualification. Select the pool this scenario
        # provisioned endpoints and cells for instead of relying on implicit
        # hash placement across unrelated persistent pools.
        call("POST", f"/api/admin/domains/{domain_id}/move", {"pool_id": shared["id"]}, token)
        call("POST", f"/api/domains/{domain_id}/deploy", {}, token)

        sequences: dict[str, int] = {}
        baseline_revision: int | None = None
        for edge in edges:
            sequence, payload = latest_artifact(edge, domain_id)
            assert payload["pools"] == ["shared-default"], payload
            baseline_revision = int(payload["revision"])
            sequences[edge["id"]] = sequence
            acknowledge(edge, sequence)
        wait_isolation(token, domain_id, "active")
        wait_deployment(token, domain_id)
        assert dig(f"www.{ZONE}", "CNAME") == [f"pool-{shared['id']}.proxy.cdnf.test."]
        apex_ipv4 = set(dig(ZONE, "A"))
        apex_ipv6 = set(dig(ZONE, "AAAA"))
        assert apex_ipv4 and apex_ipv4 <= set(dig(shared_global, "A")), apex_ipv4
        assert apex_ipv6 and apex_ipv6 <= set(dig(shared_global, "AAAA")), apex_ipv6
        assert dig(ZONE, "MX") == ["10 mail.example.net."]
        assert dig(ZONE, "TXT") == ['"proxy-apex=qualified"']
        assert dig(ZONE, "CAA") == ['0 issue "letsencrypt.org"']
        assert baseline_revision is not None
        exercise_phase5_cache(token, domain_id, edges, sequences, baseline_revision)

        for index, edge in enumerate(edges):
            call("POST", f"/api/admin/edge-pools/{quarantine['id']}/edges/{edge['id']}/endpoint", {
                "ipv4": f"9.9.9.{101 + index}",
                "ipv6": f"2620:fe::{101 + index}",
            }, token)
            heartbeat(edge, sequences[edge["id"]], quarantine_ready=True)
            heartbeat(edge, sequences[edge["id"]], quarantine_ready=True)

        _, moved = call("POST", f"/api/admin/domains/{domain_id}/move", {"pool_id": quarantine["id"]}, token)
        move_operation = moved["data"]["operation_id"]
        _, moving = call("GET", f"/api/admin/domains/{domain_id}/isolation", token=token)
        move_revision = int(moving["data"]["desired_revision"])
        placement, move_revisions = converge_placement_artifacts(
            token,
            domain_id,
            edges,
            sequences,
            move_revision,
            "draining",
            {"shared-default", "quarantine-default"},
            drain_scheduled=True,
        )
        assert placement["active_pool_id"] == shared["id"] and placement["target_pool_id"] == quarantine["id"]
        obsolete_target_artifacts = sql_value(
            "SELECT COUNT(*) FROM edge_artifacts "
            f"WHERE domain_id={domain_id} AND revision<{move_revision} "
            "AND payload->'pools' @> '[\"quarantine-default\"]'::jsonb"
        )
        assert obsolete_target_artifacts == "0", obsolete_target_artifacts
        _, moving_operation = call("GET", f"/api/operations/{move_operation}", token=token)
        assert moving_operation["data"]["status"] == "running", moving_operation
        wait_deployment(token, domain_id)
        assert dig(f"www.{ZONE}", "CNAME") == [f"pool-{quarantine['id']}.proxy.cdnf.test."]
        quarantine_global = f"pool-{quarantine['id']}.global.all.proxy.cdnf.test"
        wait_answers(quarantine_global, "A", {"9.9.9.101", "9.9.9.102"})
        wait_answers(quarantine_global, "AAAA", {"2620:fe::101", "2620:fe::102"})

        artisan(
            f"App\\Models\\DomainEdgePlacement::query()->where('domain_id',{domain_id})->update(['drain_after'=>now()->subSecond()]);"
            "Illuminate\\Support\\Facades\\Artisan::call('cdnf:edge:complete-placement-drains');"
        )
        wait_isolation(token, domain_id, "deploying", drain_scheduled=False)
        final, final_revisions = converge_placement_artifacts(
            token,
            domain_id,
            edges,
            sequences,
            move_revision + 1,
            "active",
            {"quarantine-default"},
            drain_scheduled=False,
        )
        assert final["active_pool_id"] == quarantine["id"] and final["target_pool_id"] is None
        wait_operation(token, move_operation)
        assert dig(f"www.{ZONE}", "CNAME") == [f"pool-{quarantine['id']}.proxy.cdnf.test."]

    coalesced_move_revisions = max(move_revisions.values()) - move_revision
    final_revision = max(final_revisions.values())
    print(
        "phase4_control_plane_e2e=passed edges=2 pool_dns=ipv4+ipv6 drain=passed "
        f"migration=acknowledged coalesced_move_revisions={coalesced_move_revisions} "
        f"final_revision={final_revision} obsolete_artifacts=0"
    )


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
                "$p=App\\Models\\EdgePool::query()->where('name'," + quote(f"anycast-{RUN}") + ")->first();"
                "if($p){App\\Models\\EdgeCell::query()->where('edge_pool_id',$p->id)->update(['edge_pool_id'=>null,'status'=>'unassigned']);$p->delete();}"
                "App\\Models\\User::query()->where('email'," + quote(EMAIL) + ")->delete();"
                "App\\Jobs\\ReconcilePlatformDnsIdentity::dispatch();"
            )
        except Exception as cleanup_error:
            print(f"warning: Phase 4 E2E cleanup failed: {cleanup_error}")
