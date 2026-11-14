#!/usr/bin/env python3
"""Security alerts on the real development stack: one attack, one notification.

A domain gets an alert threshold. The edges' mitigation counters for it are stored as the control plane stores them, and the
real scheduler runs the check every minute. A fake provider container stands in for the receiver of a webhook channel. The
attack keeps blocking requests for more than two scheduler runs, and the receiver must hold exactly one alert.
It restores the notifier, the settings, and every row it created.
"""
from __future__ import annotations

import json
import os
import pathlib
import sys
import tempfile
import time

sys.path.insert(0, os.path.dirname(__file__))
import notifications as n  # noqa: E402
from e2e import ApiError, expect_error, login, php_string, request, wait_for_api  # noqa: E402
from telemetry_analytics import artisan  # noqa: E402  (this helper returns the command's output)
import e2e  # noqa: E402


def alerts(address: str) -> list[dict]:
    deliveries = []
    for row in n.seen(address):
        if row["path"] == "/webhook":
            body = json.loads(row["body"])
            if body.get("event") == "security.mitigations_high":
                deliveries.append(body)
    return deliveries


def main() -> None:
    wait_for_api()
    scratch = pathlib.Path(tempfile.mkdtemp(prefix="cdnf-alert-"))
    (scratch / "server.py").write_text(n.SERVER)
    n.run("docker", "rm", "-f", n.RECEIVER, check=False)
    n.run("docker", "run", "-d", "--name", n.RECEIVER, "--network", n.NETWORK, "-v", f"{scratch}:/srv:ro", n.PYTHON, "python", "/srv/server.py")
    address = n.run("docker", "inspect", "-f", f"{{{{(index .NetworkSettings.Networks \"{n.NETWORK}\").IPAddress}}}}", n.RECEIVER).stdout.strip()
    assert address, "the fake provider has no address"
    admin_token = None
    allowlist_before: list = []
    domain_name = f"alert-{e2e.RUN_ID}.e2e.test"
    try:
        n.run(*n.COMPOSE, "up", "-d", "--no-deps", "--force-recreate", "notifier", env={"NOTIFIER_TEST_PROVIDER_URL": f"http://{n.RECEIVER}:8080"})
        n.wait_for(lambda: "healthy" in n.run("docker", "inspect", "-f", "{{.State.Health.Status}}", "cdnfoundry-dev-notifier-1", check=False).stdout, "the notifier to be healthy", 120)
        admin_email, password = f"e2e-alert-admin-{e2e.RUN_ID}@example.test", e2e.ADMIN_PASSWORD
        artisan("App\\Models\\User::query()->create(['name'=>'Alert Admin','email'=>" + php_string(admin_email) + ",'password'=>Illuminate\\Support\\Facades\\Hash::make(" + php_string(password) + "),'type'=>'admin']);")
        admin_token = login(admin_email, password)
        _, origin = request("GET", "/api/admin/system/settings/origin_safety", token=admin_token)
        allowlist_before = next(f["value"] for f in origin["data"]["fields"] if f["key"] == "private_origin_allowlist")
        request("PATCH", "/api/admin/system/settings/origin_safety", {"values": {**{f["key"]: f["value"] for f in origin["data"]["fields"]}, "private_origin_allowlist": [f"{address}/32"]}}, admin_token)
        request("POST", "/api/admin/notification-channels", {"type": "webhook", "name": "e2e alert webhook", "webhook_url": f"http://{address}:8080/webhook"}, admin_token)

        # The domain's owner sets the alert through the API; a stranger cannot.
        domain_id = int(artisan(
            f"$u=App\\Models\\User::query()->where('email',{php_string(admin_email)})->first();"
            f"$d=App\\Models\\Domain::query()->create(['name'=>{php_string(domain_name)},'display_name'=>'alert','lifecycle_state'=>'active','nameservers_verified_at'=>now(),'revision'=>1]);"
            "$d->users()->attach($u->id,['created_at'=>now()]);echo $d->id;"))
        request("PUT", f"/api/domains/{domain_id}/security/alert", {"threshold": 500, "period_minutes": 5}, admin_token)
        _, stored = request("GET", f"/api/domains/{domain_id}/security/alert", token=admin_token)
        assert stored["data"]["alert"] == {"threshold": 500, "period_minutes": 5}, stored
        expect_error(422, "PUT", f"/api/domains/{domain_id}/security/alert", payload={"threshold": 0, "period_minutes": 5}, token=admin_token)

        # A quiet period: counters below the threshold send nothing, however many scheduler runs pass.
        artisan(f"foreach(['custom_rule_block'=>200,'waf_block'=>100] as $reason=>$count){{App\\Models\\SecurityEvent::query()->create(['domain_id'=>{domain_id},'reason_code'=>$reason,'bucket_at'=>now()->startOfHour(),"
                "'occurred_at'=>now(),'details'=>['count'=>$count,'reported'=>$count,'minutes'=>[(string)intdiv(now()->timestamp,60)=>$count]]]);}")
        artisan("Illuminate\\Support\\Facades\\Artisan::call('cdnf:notifications:security-mitigations');")
        assert alerts(address) == [], alerts(address)

        # The attack: more blocked requests are reported. The scheduler sends one alert, and none follow while it continues.
        artisan(f"App\\Models\\SecurityEvent::query()->create(['domain_id'=>{domain_id},'reason_code'=>'platform_rule_block','bucket_at'=>now()->startOfHour(),"
                "'occurred_at'=>now(),'details'=>['count'=>900,'reported'=>900,'minutes'=>[(string)intdiv(now()->timestamp,60)=>900]]]);")
        delivered = n.wait_for(lambda: alerts(address), "the scheduler to send the alert", 150)
        assert len(delivered) == 1 and domain_name in json.dumps(delivered[0]) and "1,200 requests" in json.dumps(delivered[0]), delivered
        time.sleep(130)  # at least two more scheduler runs while the attack goes on
        artisan(f"App\\Models\\SecurityEvent::query()->create(['domain_id'=>{domain_id},'reason_code'=>'waf_block','bucket_at'=>now()->startOfHour(),"
                "'occurred_at'=>now(),'details'=>['count'=>4000,'reported'=>4000,'minutes'=>[(string)intdiv(now()->timestamp,60)=>4000]]]);")
        time.sleep(70)
        assert len(alerts(address)) == 1, alerts(address)
        print("security alert qualification passed: one attack produced one alert")
    finally:
        artisan("$d=App\\Models\\Domain::query()->where('name'," + php_string(domain_name) + ")->first(); if($d){$d->securityEvents()->delete(); $d->users()->detach(); $d->delete();}"
                "$u=App\\Models\\User::query()->where('email'," + php_string(f"e2e-alert-admin-{e2e.RUN_ID}@example.test") + ")->first(); if($u){$u->delete();}"
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
