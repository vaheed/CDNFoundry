#!/usr/bin/env python3
"""Phase 1 non-UI end-to-end qualification against the real development stack."""

from __future__ import annotations

import json
import os
import secrets
import subprocess
import sys
import time
import urllib.error
import urllib.request
import uuid


BASE_URL = os.environ.get("CDNF_BASE_URL", "http://localhost:8080").rstrip("/")
COMPOSE_FILE = os.environ.get("CDNF_COMPOSE_FILE", "compose.dev.yml")
ROOT = os.path.abspath(os.path.join(os.path.dirname(__file__), "../.."))
RUN_ID = f"{int(time.time())}-{secrets.token_hex(4)}"
ADMIN_EMAIL = f"phase1-e2e-admin-{RUN_ID}@example.test"
USER_EMAIL = f"phase1-e2e-user-{RUN_ID}@example.test"
ADMIN_PASSWORD = f"Admin-1-{secrets.token_urlsafe(18)}"
USER_PASSWORD = f"User-1-{secrets.token_urlsafe(18)}"


class ApiError(RuntimeError):
    def __init__(self, status: int, payload: object):
        super().__init__(f"HTTP {status}: {payload}")
        self.status = status
        self.payload = payload


def request(
    method: str,
    path: str,
    payload: object | None = None,
    token: str | None = None,
    idempotency_key: str | None = None,
) -> tuple[int, object]:
    headers = {"Accept": "application/json"}
    data = None
    if payload is not None:
        headers["Content-Type"] = "application/json"
        data = json.dumps(payload).encode()
    if token:
        headers["Authorization"] = f"Bearer {token}"
    if idempotency_key:
        headers["Idempotency-Key"] = idempotency_key

    call = urllib.request.Request(f"{BASE_URL}{path}", data=data, headers=headers, method=method)
    try:
        with urllib.request.urlopen(call, timeout=5) as response:
            body = response.read()
            return response.status, json.loads(body) if body else {}
    except urllib.error.HTTPError as error:
        body = error.read()
        parsed = json.loads(body) if body else {}
        raise ApiError(error.code, parsed) from error


def expect_error(status: int, method: str, path: str, **kwargs: object) -> ApiError:
    try:
        request(method, path, **kwargs)
    except ApiError as error:
        if error.status == status:
            return error
        raise
    raise AssertionError(f"{method} {path} unexpectedly succeeded; expected HTTP {status}")


def artisan(expression: str) -> None:
    subprocess.run(
        [
            "docker", "compose", "-f", COMPOSE_FILE, "exec", "-T", "core",
            "php", "artisan", "tinker", f"--execute={expression}",
        ],
        cwd=ROOT,
        check=True,
        stdout=subprocess.DEVNULL,
        timeout=30,
    )


def php_string(value: str) -> str:
    return "'" + value.replace("\\", "\\\\").replace("'", "\\'") + "'"


def wait_for_api() -> None:
    deadline = time.monotonic() + 60
    while time.monotonic() < deadline:
        try:
            status, payload = request("GET", "/api/health")
            if status == 200 and payload.get("status") == "ok":
                return
        except (ApiError, OSError):
            pass
        time.sleep(1)
    raise RuntimeError("API did not become healthy within 60 seconds")


def login(email: str, password: str) -> str:
    _, payload = request("POST", "/api/auth/login", {
        "email": email,
        "password": password,
        "device_name": "phase1-python-e2e",
    })
    return payload["data"]["token"]


def main() -> None:
    wait_for_api()
    _, ready = request("GET", "/api/ready")
    assert ready["status"] == "ready", ready

    artisan(
        "App\\Models\\User::query()->create(["
        f"'name'=>'Phase 1 E2E Admin','email'=>{php_string(ADMIN_EMAIL)},"
        f"'password'=>Illuminate\\Support\\Facades\\Hash::make({php_string(ADMIN_PASSWORD)}),"
        "'type'=>'admin']);"
    )

    admin_token = login(ADMIN_EMAIL, ADMIN_PASSWORD)
    _, me = request("GET", "/api/me", token=admin_token)
    assert me["data"]["email"] == ADMIN_EMAIL

    create_key = str(uuid.uuid4())
    user_payload = {
        "name": "Phase 1 E2E User",
        "email": USER_EMAIL,
        "password": USER_PASSWORD,
        "type": "user",
    }
    first_status, first = request(
        "POST", "/api/admin/users", user_payload, admin_token, create_key
    )
    replay_status, replay = request(
        "POST", "/api/admin/users", user_payload, admin_token, create_key
    )
    assert first_status == replay_status == 201
    assert first == replay
    user_id = first["data"]["id"]

    changed_payload = {**user_payload, "name": "Conflicting replay"}
    conflict = expect_error(
        409,
        "POST",
        "/api/admin/users",
        payload=changed_payload,
        token=admin_token,
        idempotency_key=create_key,
    )
    assert conflict.payload["error"]["code"] == "idempotency_conflict"

    user_token = login(USER_EMAIL, USER_PASSWORD)
    expect_error(403, "GET", "/api/admin/users", token=user_token)
    expect_error(403, "GET", "/api/admin/system/settings", token=user_token)

    _, settings = request("GET", "/api/admin/system/settings/dns_lifecycle", token=admin_token)
    fields = {field["key"]: field["value"] for field in settings["data"]["fields"]}
    original_delay = fields["deprovision_delay_days"]
    alternate_delay = 8 if original_delay == 7 else 7
    try:
        status, changed = request(
            "PATCH", "/api/admin/system/settings",
            {"group": "dns_lifecycle", "values": {"deprovision_delay_days": alternate_delay}},
            admin_token, str(uuid.uuid4()),
        )
        assert status == 200 and changed["data"]["operation"] is None
        changed_fields = {field["key"]: field["value"] for field in changed["data"]["setting"]["fields"]}
        assert changed_fields["deprovision_delay_days"] == alternate_delay
    finally:
        request(
            "PATCH", "/api/admin/system/settings",
            {"group": "dns_lifecycle", "values": {"deprovision_delay_days": original_delay}},
            admin_token, str(uuid.uuid4()),
        )

    _, created_token = request(
        "POST", "/api/me/tokens", {"name": "phase1-e2e"}, user_token, str(uuid.uuid4())
    )
    token_id = created_token["data"]["id"]
    assert created_token["data"]["token"]
    _, tokens = request("GET", "/api/me/tokens", token=user_token)
    assert any(str(item["id"]) == str(token_id) for item in tokens["data"])
    request(
        "DELETE", f"/api/me/tokens/{token_id}", token=user_token,
        idempotency_key=str(uuid.uuid4()),
    )

    request(
        "POST", f"/api/admin/users/{user_id}/disable", {}, admin_token, str(uuid.uuid4())
    )
    expect_error(401, "GET", "/api/me", token=user_token)
    request(
        "POST", f"/api/admin/users/{user_id}/enable", {}, admin_token, str(uuid.uuid4())
    )
    user_token = login(USER_EMAIL, USER_PASSWORD)

    dns_payload = {
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
        "soa_minimum_ttl": 300,
        "default_ttl": 300,
        "cluster_targets": ["pdns-auth:8081"],
    }
    _, validated = request(
        "POST", "/api/admin/system/settings/dns/validate", dns_payload,
        admin_token, str(uuid.uuid4()),
    )
    assert validated["data"]["valid"] is True
    status, operation = request(
        "PATCH", "/api/admin/system/settings/dns", dns_payload | {"confirmation_token": validated["data"]["confirmation_token"]},
        admin_token, str(uuid.uuid4()),
    )
    assert status == 202
    operation_id = operation["data"]["id"]
    deadline = time.monotonic() + 30
    while time.monotonic() < deadline:
        _, current = request("GET", f"/api/operations/{operation_id}", token=admin_token)
        operation_status = current["data"]["status"]
        if operation_status == "succeeded":
            break
        if operation_status == "failed":
            raise AssertionError(current)
        time.sleep(0.5)
    else:
        raise AssertionError(f"operation {operation_id} did not finish")

    request(
        "POST", f"/api/admin/users/{user_id}/disable", {}, admin_token, str(uuid.uuid4())
    )
    request(
        "POST", f"/api/admin/users/{user_id}/enable", {}, admin_token, str(uuid.uuid4())
    )
    request(
        "DELETE", f"/api/admin/users/{user_id}", token=admin_token,
        idempotency_key=str(uuid.uuid4()),
    )
    _, audits = request("GET", "/api/admin/audit-logs", token=admin_token)
    actions = {item["action"] for item in audits["data"]}
    assert {"user.created", "user.disabled", "user.enabled", "user.deleted"} <= actions

    print("phase1_backend_e2e=passed")


if __name__ == "__main__":
    try:
        main()
    finally:
        try:
            artisan(
                "App\\Models\\User::query()->whereIn('email',["
                f"{php_string(USER_EMAIL)},{php_string(ADMIN_EMAIL)}])->delete();"
            )
        except Exception as cleanup_error:
            print(f"warning: E2E cleanup failed: {cleanup_error}", file=sys.stderr)
