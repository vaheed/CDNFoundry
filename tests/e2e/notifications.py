#!/usr/bin/env python3
"""Qualify notification delivery against the real development stack.

A fake provider container stands in for Telegram, Discord, Slack, and a webhook receiver. The test proves that an
Alertmanager payload reaches an administrator channel of every type, that a provider that never answers does not delay
the others, that a domain user receives only events for an assigned domain, and that turning the switch off stops delivery.
It restores the notifier, the settings, and every row it created.
"""
from __future__ import annotations

import json
import os
import pathlib
import subprocess
import sys
import tempfile
import time
import urllib.request

sys.path.insert(0, os.path.dirname(__file__))
import e2e  # noqa: E402  (shared API helpers)
from e2e import ROOT as _ROOT, ApiError, artisan, expect_error, login, php_string, request, wait_for_api  # noqa: E402

ROOT = pathlib.Path(_ROOT)
COMPOSE = ["docker", "compose", "-f", e2e.COMPOSE_FILE]
NETWORK = "cdnfoundry-dev_dns"
RECEIVER = "cdnf-fake-provider"
PYTHON = "python:3.13-alpine@sha256:399babc8b49529dabfd9c922f2b5eea81d611e4512e3ed250d75bd2e7683f4b0"
SLOW_TOKEN = "999999999:SLOWSLOWSLOWSLOWSLOWSLOWSLOWSLOWSLOW"
TELEGRAM_TOKEN = "123456789:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA"
DISCORD_PATH = "/api/webhooks/123456789012345678/abcdefghijklmnopqrstuvwxyz0123456789"
SLACK_PATH = "/services/T0000000/B0000000/abcdefghijklmnopqrstuvwx"

SERVER = r'''
import json, threading, time
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
seen = []
class Handler(BaseHTTPRequestHandler):
    def log_message(self, *args): pass
    def do_GET(self):
        body = json.dumps(seen).encode()
        self.send_response(200); self.send_header("Content-Length", str(len(body))); self.end_headers(); self.wfile.write(body)
    def do_POST(self):
        raw = self.rfile.read(int(self.headers.get("Content-Length", 0)))
        seen.append({"path": self.path, "body": raw.decode(), "signature": self.headers.get("X-CDNFoundry-Signature", ""), "at": time.time()})
        if "SLOWSLOW" in self.path:
            time.sleep(25)
        self.send_response(200); self.send_header("Content-Length", "2"); self.end_headers(); self.wfile.write(b"ok")
ThreadingHTTPServer(("0.0.0.0", 8080), Handler).serve_forever()
'''


def run(*args: str, check: bool = True, env: dict[str, str] | None = None) -> subprocess.CompletedProcess[str]:
    result = subprocess.run(args, cwd=ROOT, text=True, capture_output=True, env={**os.environ, **(env or {})}, timeout=300)
    if check and result.returncode:
        raise RuntimeError(f"{' '.join(args[:4])} failed: {result.stderr[-1500:]}")
    return result


def seen(address: str) -> list[dict]:
    with urllib.request.urlopen(f"http://{address}:8080/", timeout=5) as response:
        return json.loads(response.read())


def wait_for(predicate, what: str, timeout: float = 60):
    deadline = time.monotonic() + timeout
    while time.monotonic() < deadline:
        value = predicate()
        if value:
            return value
        time.sleep(1)
    raise AssertionError(f"timed out waiting for {what}")


def alertmanager_payload(name: str) -> dict:
    alert = {"status": "firing", "labels": {"alertname": name, "team": "noc"}, "annotations": {"summary": f"{name} is firing in the notification qualification"}}
    return {"status": "firing", "alerts": [alert]}


def post_alert(token: str, payload: dict, check: bool = True) -> int:
    call = urllib.request.Request(f"{e2e.BASE_URL}/internal/alertmanager", data=json.dumps(payload).encode(), method="POST",
                                  headers={"Authorization": f"Bearer {token}", "Content-Type": "application/json", "Accept": "application/json"})
    with urllib.request.urlopen(call, timeout=10) as response:
        return response.status


def main() -> None:
    wait_for_api()
    monitoring_token = (ROOT / "docker/prometheus/dev-metrics-token").read_text().strip()
    scratch = pathlib.Path(tempfile.mkdtemp(prefix="cdnf-notify-"))
    (scratch / "server.py").write_text(SERVER)
    run("docker", "rm", "-f", RECEIVER, check=False)
    run("docker", "run", "-d", "--name", RECEIVER, "--network", NETWORK, "-v", f"{scratch}:/srv:ro", PYTHON, "python", "/srv/server.py")
    address = run("docker", "inspect", "-f", f"{{{{(index .NetworkSettings.Networks \"{NETWORK}\").IPAddress}}}}", RECEIVER).stdout.strip()
    assert address, "the fake provider has no address"
    created_users: list[str] = []
    settings_before: dict = {}
    allowlist_before: list = []
    admin_token = None
    try:
        run(*COMPOSE, "up", "-d", "--no-deps", "--force-recreate", "notifier", env={"NOTIFIER_TEST_PROVIDER_URL": f"http://{RECEIVER}:8080"})
        wait_for(lambda: "healthy" in run("docker", "inspect", "-f", "{{.State.Health.Status}}", "cdnfoundry-dev-notifier-1", check=False).stdout, "the notifier to be healthy", 120)

        admin_email, admin_password = f"e2e-notify-admin-{e2e.RUN_ID}@example.test", e2e.ADMIN_PASSWORD
        artisan("App\\Models\\User::query()->create(['name'=>'Notify Admin','email'=>" + php_string(admin_email)
                + ",'password'=>Illuminate\\Support\\Facades\\Hash::make(" + php_string(admin_password) + "),'type'=>'admin']);")
        created_users.append(admin_email)
        admin_token = login(admin_email, admin_password)
        _, original = request("GET", "/api/admin/system/settings/notifications", token=admin_token)
        settings_before = {field["key"]: field["value"] for field in original["data"]["fields"]} if "fields" in original["data"] else {}
        _, origin_before = request("GET", "/api/admin/system/settings/origin_safety", token=admin_token)
        allowlist_before = next(f["value"] for f in origin_before["data"]["fields"] if f["key"] == "private_origin_allowlist")
        request("PATCH", "/api/admin/system/settings/origin_safety", {"values": {
            **{f["key"]: f["value"] for f in origin_before["data"]["fields"]}, "private_origin_allowlist": [f"{address}/32"]}}, admin_token)

        # Administrator channels: every provider type, one of them pointed at a Telegram bot that never answers.
        channels = {}
        for payload in (
            {"type": "telegram", "name": "e2e telegram", "bot_token": TELEGRAM_TOKEN, "chat_id": "-1001234567890"},
            {"type": "telegram", "name": "e2e slow", "bot_token": SLOW_TOKEN, "chat_id": "-1001234567890"},
            {"type": "discord", "name": "e2e discord", "webhook_url": f"https://discord.com{DISCORD_PATH}"},
            {"type": "slack", "name": "e2e slack", "webhook_url": f"https://hooks.slack.com{SLACK_PATH}"},
            {"type": "webhook", "name": "e2e webhook", "webhook_url": f"http://{address}:8080/webhook"},
        ):
            _, created = request("POST", "/api/admin/notification-channels", payload, admin_token)
            channels[payload["name"]] = created
        signing_secret = channels["e2e webhook"]["meta"]["signing_secret"]
        _, listed = request("GET", "/api/admin/notification-channels", token=admin_token)
        text = json.dumps(listed)
        assert TELEGRAM_TOKEN not in text and "abcdefghijklmnop" not in text and signing_secret not in text, "a secret leaked into a list"
        expect_error(422, "POST", "/api/admin/notification-channels", payload={"type": "webhook", "name": "bad", "webhook_url": "http://127.0.0.1/x"}, token=admin_token)
        expect_error(422, "POST", "/api/admin/notification-channels", payload={"type": "discord", "name": "bad", "webhook_url": "https://evil.example" + DISCORD_PATH}, token=admin_token)

        # An Alertmanager payload reaches every type; the slow provider delays none of the others.
        started = time.time()
        assert post_alert(monitoring_token, alertmanager_payload("E2ENotifyOne")) == 200
        expected = {f"/bot{TELEGRAM_TOKEN}/sendMessage", DISCORD_PATH, SLACK_PATH, "/webhook", f"/bot{SLOW_TOKEN}/sendMessage"}
        wait_for(lambda: expected <= {row["path"] for row in seen(address)}, "an alert on every channel type", 30)
        rows = {row["path"]: row for row in seen(address)}
        elapsed = max(rows[path]["at"] for path in expected - {f"/bot{SLOW_TOKEN}/sendMessage"}) - started
        assert elapsed < 20, f"fast providers waited {elapsed:.1f}s behind the slow one"
        assert "E2ENotifyOne" in rows["/webhook"]["body"] and rows["/webhook"]["signature"].startswith("sha256=")
        # Without the monitoring token the receiver does not exist.
        try:
            post_alert("wrong-token", alertmanager_payload("E2ENotifyTwo"))
            raise AssertionError("the receiver accepted a wrong token")
        except urllib.error.HTTPError as error:
            assert error.code == 404

        # Domain users: switch on, two users with one domain each.
        request("PATCH", "/api/admin/system/settings/notifications", {"values": {**settings_before, "domain_user_channels": True}}, admin_token)
        mine, theirs = f"e2e-notify-a-{e2e.RUN_ID}.example.test", f"e2e-notify-b-{e2e.RUN_ID}.example.test"
        for suffix, domain in (("a", mine), ("b", theirs)):
            email = f"e2e-notify-{suffix}-{e2e.RUN_ID}@example.test"
            artisan("$u=App\\Models\\User::query()->create(['name'=>'Notify " + suffix + "','email'=>" + php_string(email)
                    + ",'password'=>Illuminate\\Support\\Facades\\Hash::make(" + php_string(e2e.USER_PASSWORD) + "),'type'=>'user']);"
                    "$d=App\\Models\\Domain::query()->create(['name'=>" + php_string(domain) + ",'display_name'=>" + php_string(domain)
                    + ",'revision'=>1,'lifecycle_state'=>'active']);$d->users()->attach($u->id);")
            created_users.append(email)
        tokens = {s: login(f"e2e-notify-{s}-{e2e.RUN_ID}@example.test", e2e.USER_PASSWORD) for s in ("a", "b")}
        for suffix, token_path in (("a", "/bot111111111:USERAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA/sendMessage"), ("b", "/bot222222222:USERBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBB/sendMessage")):
            _, made = request("POST", "/api/notification-channels", {
                "type": "telegram", "name": f"user {suffix}", "bot_token": token_path.split("/bot")[1].split("/")[0], "chat_id": "-100200300", "events": ["certificate.failed"]}, tokens[suffix])
        artisan("App\\Support\\Notifications\\EventPublisher::publish('certificate.failed', App\\Models\\Domain::query()->where('name'," + php_string(mine)
                + ")->first(), 'E2E failure for a', 'x');")
        wait_for(lambda: any("USERAAAA" in row["path"] for row in seen(address)), "the owner's user channel to receive its domain event", 30)
        time.sleep(3)
        everything = seen(address)
        assert not any("USERBBBB" in row["path"] for row in everything), "a user received another customer's domain event"
        assert any("E2E failure for a" in row["body"] and "USERAAAA" not in row["body"] for row in everything if row["path"] == f"/bot{TELEGRAM_TOKEN}/sendMessage"), "the administrator channel missed the domain event"

        # Turning the switch off stops user delivery and keeps the channel.
        request("PATCH", "/api/admin/system/settings/notifications", {"values": {**settings_before, "domain_user_channels": False}}, admin_token)
        before = len([row for row in seen(address) if "USERAAAA" in row["path"]])
        artisan("App\\Support\\Notifications\\EventPublisher::publish('certificate.failed', App\\Models\\Domain::query()->where('name'," + php_string(mine) + ")->first(), 'E2E after switch off', 'x');")
        time.sleep(8)
        assert len([row for row in seen(address) if "USERAAAA" in row["path"]]) == before, "a user channel sent after the switch was turned off"
        # The channel is kept, only silent.
        artisan("if (!App\\Models\\NotificationChannel::query()->where('name','user a')->exists()) { throw new Exception('user channel was deleted'); }")

        # A channel test is an operation.
        any_id = channels["e2e discord"]["data"]["id"]
        _, accepted = request("POST", f"/api/admin/notification-channels/{any_id}/test", token=admin_token)
        e2e.wait_operation(admin_token, accepted["data"]["operation_id"])
        print("notification qualification passed")
    finally:
        for email in created_users:
            artisan("$u=App\\Models\\User::query()->where('email'," + php_string(email) + ")->first(); if($u){App\\Models\\NotificationChannel::query()->where('user_id',$u->id)->delete();"
                    "foreach($u->domains as $d){$d->delete();} $u->delete();}")
        artisan("App\\Models\\NotificationChannel::query()->whereNull('user_id')->where('name','like','e2e %')->delete();")
        if admin_token is not None and settings_before:
            try:
                request("PATCH", "/api/admin/system/settings/notifications", {"values": settings_before}, admin_token)
                _, current = request("GET", "/api/admin/system/settings/origin_safety", token=admin_token)
                request("PATCH", "/api/admin/system/settings/origin_safety", {"values": {
                    **{f["key"]: f["value"] for f in current["data"]["fields"]}, "private_origin_allowlist": allowlist_before}}, admin_token)
            except ApiError:
                pass
        run("docker", "rm", "-f", RECEIVER, check=False)
        run(*COMPOSE, "up", "-d", "--no-deps", "--force-recreate", "notifier", check=False)


if __name__ == "__main__":
    main()
