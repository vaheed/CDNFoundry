#!/usr/bin/env python3
"""Client-side script reports on the real development stack: a new script is listed once and notified once.

Browser reports are stored in the real ClickHouse the way Vector writes them. The real scheduler syncs them every minute. The first
script is the baseline and sends nothing; after the baseline window a second script appears in the domain's list and a fake
webhook receiver must hold exactly one notification, however many scheduler runs follow. It restores the notifier, the settings,
and every row it created.
"""
from __future__ import annotations

import json
import os
import pathlib
import subprocess
import sys
import tempfile
import time

sys.path.insert(0, os.path.dirname(__file__))
import notifications as n  # noqa: E402
from e2e import ApiError, expect_error, login, php_string, request, wait_for_api  # noqa: E402
from telemetry_analytics import artisan  # noqa: E402  (this helper returns the command's output)
import e2e  # noqa: E402


def delivered(address: str) -> list[dict]:
    found = []
    for row in n.seen(address):
        if row["path"] == "/webhook":
            body = json.loads(row["body"])
            if body.get("event") == "client_script.new":
                found.append(body)
    return found


def report(domain_id: int, value: str) -> None:
    row = {"occurred_at": time.strftime("%Y-%m-%d %H:%M:%S", time.gmtime()) + ".000", "domain_id": domain_id, "hostname": "shop.e2e.test", "method": "POST", "path": "/.cdnf/csp-report",
           "status": 204, "client_report": value}
    subprocess.run(["docker", "compose", "-f", "compose.dev.yml", "exec", "-T", "clickhouse", "clickhouse-client", "--query", "INSERT INTO cdnf.edge_events FORMAT JSONEachRow"],
                   input=json.dumps(row), text=True, check=True, cwd=e2e.ROOT, capture_output=True)


def main() -> None:
    wait_for_api()
    scratch = pathlib.Path(tempfile.mkdtemp(prefix="cdnf-scripts-"))
    (scratch / "server.py").write_text(n.SERVER)
    n.run("docker", "rm", "-f", n.RECEIVER, check=False)
    n.run("docker", "run", "-d", "--name", n.RECEIVER, "--network", n.NETWORK, "-v", f"{scratch}:/srv:ro", n.PYTHON, "python", "/srv/server.py")
    address = n.run("docker", "inspect", "-f", f"{{{{(index .NetworkSettings.Networks \"{n.NETWORK}\").IPAddress}}}}", n.RECEIVER).stdout.strip()
    assert address, "the fake provider has no address"
    admin_token = None
    allowlist_before: list = []
    domain_name = f"scripts-{e2e.RUN_ID}.e2e.test"
    admin_email = f"e2e-scripts-admin-{e2e.RUN_ID}@example.test"
    try:
        n.run(*n.COMPOSE, "up", "-d", "--no-deps", "--force-recreate", "notifier", env={"NOTIFIER_TEST_PROVIDER_URL": f"http://{n.RECEIVER}:8080"})
        n.wait_for(lambda: "healthy" in n.run("docker", "inspect", "-f", "{{.State.Health.Status}}", "cdnfoundry-dev-notifier-1", check=False).stdout, "the notifier to be healthy", 120)
        artisan("App\\Models\\User::query()->create(['name'=>'Scripts Admin','email'=>" + php_string(admin_email) + ",'password'=>Illuminate\\Support\\Facades\\Hash::make(" + php_string(e2e.ADMIN_PASSWORD) + "),'type'=>'admin']);")
        admin_token = login(admin_email, e2e.ADMIN_PASSWORD)
        _, origin = request("GET", "/api/admin/system/settings/origin_safety", token=admin_token)
        allowlist_before = next(f["value"] for f in origin["data"]["fields"] if f["key"] == "private_origin_allowlist")
        request("PATCH", "/api/admin/system/settings/origin_safety", {"values": {**{f["key"]: f["value"] for f in origin["data"]["fields"]}, "private_origin_allowlist": [f"{address}/32"]}}, admin_token)
        request("POST", "/api/admin/notification-channels", {"type": "webhook", "name": "e2e scripts webhook", "webhook_url": f"http://{address}:8080/webhook"}, admin_token)
        domain_id = int(artisan(
            f"$u=App\\Models\\User::query()->where('email',{php_string(admin_email)})->first();"
            f"$d=App\\Models\\Domain::query()->create(['name'=>{php_string(domain_name)},'display_name'=>'scripts','lifecycle_state'=>'active','nameservers_verified_at'=>now(),'revision'=>1]);"
            "$d->users()->attach($u->id,['created_at'=>now()]);echo $d->id;"))
        sightings = f"/api/domains/{domain_id}/security/client-side/scripts"
        expect_error(422, "PUT", f"/api/domains/{domain_id}/security/client-side", payload={"maximum_reports_per_minute": 5}, token=admin_token)
        status, _ = request("PUT", f"/api/domains/{domain_id}/security/client-side", {"baseline_minutes": 60}, admin_token)
        assert status == 202, status

        # The first script is the baseline: listed as approved, nothing sent.
        report(domain_id, "script|/checkout|https://cdn.e2e.test")
        listed = n.wait_for(lambda: request("GET", sightings, token=admin_token)[1]["data"]["data"], "the scheduler to record the first script", 150)
        assert [(row["kind"], row["page"], row["resource"], row["status"]) for row in listed] == [("script", "/checkout", "https://cdn.e2e.test", "approved")], listed
        time.sleep(5)
        assert delivered(address) == [], delivered(address)

        # The baseline window ends. A new script and a new connection are listed and sent as one notification.
        artisan(f"$d=App\\Models\\Domain::query()->find({domain_id});$d->update(['client_security'=>[...$d->client_security,'enabled_at'=>now()->subHours(3)->toIso8601String()]]);")
        report(domain_id, "script|/checkout|https://evil.e2e.test")
        report(domain_id, "connection|/checkout|https://exfil.e2e.test")
        sent = n.wait_for(lambda: delivered(address), "the scheduler to send the notification", 150)
        assert len(sent) == 1 and "2 new scripts or connections" in json.dumps(sent[0]) and domain_name in json.dumps(sent[0]), sent
        _, listed = request("GET", sightings + "?status=new", token=admin_token)
        assert sorted(row["resource"] for row in listed["data"]["data"]) == ["https://evil.e2e.test", "https://exfil.e2e.test"], listed
        time.sleep(130)  # at least two more scheduler runs read the same reports again
        assert len(delivered(address)) == 1, delivered(address)
        _, again = request("GET", sightings, token=admin_token)
        assert len(again["data"]["data"]) == 3, again

        # Approving makes them quiet in the list; a stranger sees nothing.
        _, approved = request("POST", sightings + "/approve", {}, admin_token)
        assert approved["data"]["approved"] == 2, approved
        _, rest = request("GET", sightings + "?status=new", token=admin_token)
        assert rest["data"]["data"] == [], rest
        print("client-side script qualification passed: one new script produced one notification")
    finally:
        artisan("$d=App\\Models\\Domain::query()->where('name'," + php_string(domain_name) + ")->first(); if($d){$d->clientScriptSightings()->delete(); $d->users()->detach(); $d->delete();}"
                "$u=App\\Models\\User::query()->where('email'," + php_string(admin_email) + ")->first(); if($u){$u->delete();}"
                "App\\Models\\NotificationChannel::query()->whereNull('user_id')->where('name','like','e2e %')->delete();")
        if admin_token is not None:
            try:
                _, current = request("GET", "/api/admin/system/settings/origin_safety", token=admin_token)
                request("PATCH", "/api/admin/system/settings/origin_safety", {"values": {**{f["key"]: f["value"] for f in current["data"]["fields"]}, "private_origin_allowlist": allowlist_before}}, admin_token)
            except ApiError:
                pass
        n.run("docker", "rm", "-f", n.RECEIVER, check=False)
        n.run(*n.COMPOSE, "up", "-d", "--no-deps", "--force-recreate", "notifier", check=False)


if __name__ == "__main__":
    main()
