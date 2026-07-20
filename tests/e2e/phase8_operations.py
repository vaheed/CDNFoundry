#!/usr/bin/env python3
"""Real Phase 8 operations, encrypted backup, and isolated restore qualification."""

from __future__ import annotations

import json
import os
import pathlib
import secrets
import subprocess
import time
import urllib.error
import urllib.request
import uuid

ROOT = pathlib.Path(__file__).resolve().parents[2]
COMPOSE = os.environ.get("CDNF_COMPOSE_FILE", "compose.dev.yml")
BASE = os.environ.get("CDNF_BASE_URL", "http://localhost:8080").rstrip("/")
RUN = f"{int(time.time())}-{secrets.token_hex(4)}"
EMAIL = f"phase8-admin-{RUN}@example.test"
PASSWORD = f"Phase8-1-{secrets.token_urlsafe(20)}"
RESTORE_CONTAINER = f"cdnfoundry-phase8-restore-{RUN}"


def run(*args: str, timeout: int = 120, check: bool = True) -> subprocess.CompletedProcess[str]:
    result = subprocess.run(args, cwd=ROOT, text=True, capture_output=True, timeout=timeout, check=False)
    if check and result.returncode != 0:
        raise RuntimeError(f"command failed: {' '.join(args)}\n{result.stdout}\n{result.stderr}")
    return result


def compose(*args: str, timeout: int = 120) -> subprocess.CompletedProcess[str]:
    return run("docker", "compose", "-f", COMPOSE, *args, timeout=timeout)


def request(method: str, path: str, payload: object | None = None, token: str | None = None,
            key: str | None = None) -> tuple[int, object]:
    headers = {"Accept": "application/json"}
    data = None
    if payload is not None:
        headers["Content-Type"] = "application/json"
        data = json.dumps(payload).encode()
    if token:
        headers["Authorization"] = f"Bearer {token}"
    if key:
        headers["Idempotency-Key"] = key
    call = urllib.request.Request(f"{BASE}{path}", method=method, headers=headers, data=data)
    try:
        with urllib.request.urlopen(call, timeout=15) as response:
            body = response.read()
            return response.status, json.loads(body) if body else {}
    except urllib.error.HTTPError as error:
        body = error.read()
        try:
            decoded = json.loads(body) if body else {}
        except json.JSONDecodeError:
            decoded = body.decode(errors="replace")
        return error.code, decoded


def php_string(value: str) -> str:
    return "'" + value.replace("\\", "\\\\").replace("'", "\\'") + "'"


def wait_backup(token: str, backup_id: str) -> dict[str, object]:
    deadline = time.monotonic() + 180
    while time.monotonic() < deadline:
        status, body = request("GET", f"/api/admin/backups/{backup_id}", token=token)
        assert status == 200, body
        backup = body["data"]
        if backup["status"] == "succeeded":
            return backup
        if backup["status"] == "failed":
            raise AssertionError(backup)
        time.sleep(1)
    raise AssertionError("backup did not finish")


def counts(container: str, user: str, database: str) -> set[str]:
    sql = "SELECT 'users='||count(*) FROM users UNION ALL SELECT 'domains='||count(*) FROM domains UNION ALL SELECT 'dns_records='||count(*) FROM dns_records UNION ALL SELECT 'migrations='||count(*) FROM migrations;"
    return set(run("docker", "exec", container, "psql", "-U", user, "-d", database, "-Atc", sql).stdout.splitlines())


def ensure_development_repository() -> None:
    command = (
        "docker", "compose", "-f", COMPOSE, "exec", "-T", "--user", "www-data", "core", "restic", "snapshots",
    )
    probe = run(*command, check=False)
    if probe.returncode == 0:
        return
    details = f"{probe.stdout}\n{probe.stderr}"
    if "repository does not exist" not in details:
        raise RuntimeError(f"unable to inspect development Restic repository:\n{details}")
    compose("exec", "-T", "--user", "www-data", "core", "restic", "init")


def main() -> None:
    ensure_development_repository()
    expression = (
        "App\\Models\\User::query()->create(["
        f"'name'=>'Phase 8 runtime admin','email'=>{php_string(EMAIL)},"
        f"'password'=>Illuminate\\Support\\Facades\\Hash::make({php_string(PASSWORD)}),'type'=>'admin']);"
    )
    compose("exec", "-T", "core", "php", "artisan", "tinker", f"--execute={expression}")
    status, login = request("POST", "/api/auth/login", {"email": EMAIL, "password": PASSWORD, "device_name": "phase8-e2e"})
    assert status == 200, login
    token = login["data"]["token"]

    status, components = request("GET", "/api/admin/system/components", token=token)
    assert status == 200 and components["data"]["status"] in {"healthy", "degraded", "unavailable"}, components
    assert set(components["data"]["queues"]) == {"interactive", "runtime", "certificate_purge", "bulk_maintenance"}
    assert request("GET", "/metrics")[0] == 404

    key = str(uuid.uuid4())
    status, created = request("POST", "/api/admin/backups", {}, token, key)
    assert status == 202, created
    replay_status, replay = request("POST", "/api/admin/backups", {}, token, key)
    assert replay_status == 202 and replay == created
    backup = wait_backup(token, created["data"]["backup_id"])
    snapshot = backup["snapshot_id"]
    assert isinstance(snapshot, str) and len(snapshot) == 64
    compose("exec", "-T", "core", "restic", "check", "--read-data-subset=100%", timeout=180)

    wrong, _ = request("POST", f"/api/admin/backups/{backup['id']}/restore", {"confirmation": "wrong", "current_password": PASSWORD}, token, str(uuid.uuid4()))
    assert wrong == 422
    restore_status, restore = request("POST", f"/api/admin/backups/{backup['id']}/restore", {"confirmation": f"RESTORE {backup['id']}", "current_password": PASSWORD}, token, str(uuid.uuid4()))
    assert restore_status == 202, restore

    run("docker", "run", "-d", "--rm", "--name", RESTORE_CONTAINER, "--network", "cdnfoundry-dev_control",
        "-e", "POSTGRES_DB=cdnf_restore", "-e", "POSTGRES_USER=cdnf_restore", "-e", "POSTGRES_PASSWORD=phase8-restore-only",
        "--tmpfs", "/var/lib/postgresql:rw,nosuid,size=1g", "postgres:18.4-alpine")
    try:
        deadline = time.monotonic() + 30
        while time.monotonic() < deadline:
            if run("docker", "exec", RESTORE_CONTAINER, "pg_isready", "-U", "cdnf_restore", "-d", "cdnf_restore", check=False).returncode == 0:
                break
            time.sleep(1)
        compose("exec", "-T", "-e", f"PGHOST={RESTORE_CONTAINER}", "-e", "PGPORT=5432", "-e", "PGDATABASE=cdnf_restore",
                "-e", "PGUSER=cdnf_restore", "-e", "PGPASSWORD=phase8-restore-only", "core", "/usr/local/bin/cdnf-backup-restore", snapshot, timeout=180)
        restored = counts(RESTORE_CONTAINER, "cdnf_restore", "cdnf_restore")
        source_sql = "SELECT 'users='||count(*) FROM users UNION ALL SELECT 'domains='||count(*) FROM domains UNION ALL SELECT 'dns_records='||count(*) FROM dns_records UNION ALL SELECT 'migrations='||count(*) FROM migrations;"
        source = set(compose("exec", "-T", "control-db", "psql", "-U", "cdnf", "-d", "cdnf", "-Atc", source_sql).stdout.splitlines())
        assert restored == source, (restored, source)
    finally:
        run("docker", "stop", RESTORE_CONTAINER, check=False)

    print(f"phase8_operations=passed backup={backup['id']} snapshot={snapshot}")


if __name__ == "__main__":
    main()
