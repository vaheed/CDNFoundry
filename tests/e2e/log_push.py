#!/usr/bin/env python3
"""Qualify log push against the real development stack: real ClickHouse rows, the real scheduler and `log-pusher` worker.

A fake HTTPS receiver (private CA, never trusted outside this run) stands in for two customers' endpoints. The test proves
that each receiver gets only its own domain's rows in order, that no platform field leaks, that a receiver that is down keeps
its cursor and catches up without a gap or a duplicate, that falling behind retention skips ahead and records the gap, and
that turning the administrator switch off stops delivery while keeping the destination.
"""
from __future__ import annotations

import json
import os
import pathlib
import shutil
import ssl
import subprocess
import sys
import time
import urllib.request
import uuid

sys.path.insert(0, os.path.dirname(__file__))
import e2e  # noqa: E402
from e2e import ROOT as _ROOT, ApiError, artisan, expect_error, login, php_string, request, wait_for_api  # noqa: E402

ROOT = pathlib.Path(_ROOT)
COMPOSE = ["docker", "compose", "-f", e2e.COMPOSE_FILE]
NETWORK = "cdnfoundry-dev_dns"
RECEIVER = "cdnf-fake-logs"
PYTHON = "python:3.13-alpine@sha256:399babc8b49529dabfd9c922f2b5eea81d611e4512e3ed250d75bd2e7683f4b0"
CA_DIR = ROOT / "tests/e2e/.log-push-ca"

SERVER = r'''
import gzip, json, ssl, time
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
seen, mode = [], {"down": False}
class Handler(BaseHTTPRequestHandler):
    def log_message(self, *args): pass
    def reply(self, code, body=b"ok"):
        self.send_response(code); self.send_header("Content-Length", str(len(body))); self.end_headers(); self.wfile.write(body)
    def do_GET(self):
        self.reply(200, json.dumps(seen).encode())
    def do_POST(self):
        raw = self.rfile.read(int(self.headers.get("Content-Length", 0)))
        if self.path == "/mode":
            mode["down"] = json.loads(raw)["down"]; return self.reply(200)
        if mode["down"]:
            return self.reply(503)
        lines = [json.loads(line) for line in gzip.decompress(raw).decode().splitlines() if line]
        seen.append({"path": self.path, "auth": self.headers.get("Authorization", ""), "signature": self.headers.get("X-CDNFoundry-Signature", ""), "lines": lines})
        self.reply(200)
server = ThreadingHTTPServer(("0.0.0.0", 8443), Handler)
context = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER); context.load_cert_chain("/srv/cert.pem", "/srv/key.pem")
server.socket = context.wrap_socket(server.socket, server_side=True)
server.serve_forever()
'''


def run(*args: str, check: bool = True, env: dict[str, str] | None = None) -> subprocess.CompletedProcess[str]:
    result = subprocess.run(args, cwd=ROOT, text=True, capture_output=True, env={**os.environ, **(env or {})}, timeout=300, stdin=subprocess.DEVNULL)
    if check and result.returncode:
        raise RuntimeError(f"{' '.join(args[:5])} failed: {result.stderr[-1500:]}")
    return result


def clickhouse(query: str) -> str:
    # stdin is closed so clickhouse-client never waits for more data, and a stuck client is retried instead of holding the run.
    for attempt in range(3):
        try:
            return subprocess.run([*COMPOSE, "exec", "-T", "clickhouse", "clickhouse-client", "--query", query], cwd=ROOT, text=True, capture_output=True, check=True, timeout=60, stdin=subprocess.DEVNULL).stdout.strip()
        except subprocess.TimeoutExpired:
            if attempt == 2:
                raise
    raise AssertionError("unreachable")


def fetch(address: str, path: str = "/", body: dict | None = None):
    context = ssl.create_default_context(cafile=str(CA_DIR / "cert.pem"))
    context.check_hostname = False
    call = urllib.request.Request(f"https://{address}:8443{path}", data=json.dumps(body).encode() if body is not None else None, method="POST" if body is not None else "GET")
    with urllib.request.urlopen(call, timeout=10, context=context) as response:
        return json.loads(response.read()) if body is None else None


def wait_for(predicate, what: str, timeout: float = 240, tick=None):
    deadline = time.monotonic() + timeout
    while time.monotonic() < deadline:
        if tick:
            tick()
        value = predicate()
        if value:
            return value
        time.sleep(3)
    raise AssertionError(f"timed out waiting for {what}")


def insert(domain_id: int, hostname: str, count: int, offset_seconds: int, security: bool = False, edge: str = "edge-secret-a") -> list[str]:
    ids = [str(uuid.uuid4()) for _ in range(count)]
    values = ", ".join(
        f"(now64(3) + INTERVAL {offset_seconds * 100 + index * 10} MILLISECOND, toUUID('{event_id}'), {domain_id}, '{hostname}', 'GET', '/p{index}', {403 if security else 200}, 10, 20, 'MISS', 'refused 10.0.0.5', '', "
        f"'{'block' if security else ''}', '{'rate' if security else ''}', '{edge}', '203.0.113.{70 + index}', 'DE', 'EU', 'ua', '', 'request', 'req-{event_id[:8]}', 'qh', 'cell-internal')"
        for index, event_id in enumerate(ids)
    )
    clickhouse("INSERT INTO cdnf.edge_events (occurred_at,event_id,domain_id,hostname,method,path,status,bytes_in,bytes_out,cache_status,origin_error,tls_error,security_action,security_reason,edge_id,client_ip,country,continent,user_agent,referrer,event_type,request_id,query_hash,cell) VALUES " + values)
    return ids


def dispatch() -> None:
    run(*COMPOSE, "exec", "-T", "core", "php", "artisan", "cdnf:log-push:dispatch", check=False)


def lines_for(address: str, path: str, dataset: str) -> list[dict]:
    return [line for record in fetch(address) if record["path"] == path for line in record["lines"] if line["dataset"] == dataset]


def main() -> None:
    wait_for_api()
    shutil.rmtree(CA_DIR, ignore_errors=True)
    CA_DIR.mkdir(parents=True)
    run("openssl", "req", "-x509", "-newkey", "rsa:2048", "-nodes", "-keyout", str(CA_DIR / "key.pem"), "-out", str(CA_DIR / "cert.pem"), "-days", "1", "-subj", f"/CN={RECEIVER}", "-addext", f"subjectAltName=DNS:{RECEIVER}")
    (CA_DIR / "server.py").write_text(SERVER)
    run("docker", "rm", "-f", RECEIVER, check=False)
    run("docker", "run", "-d", "--name", RECEIVER, "--network", NETWORK, "--network-alias", RECEIVER, "-v", f"{CA_DIR}:/srv:ro", PYTHON, "python", "/srv/server.py")
    address = run("docker", "inspect", "-f", f"{{{{(index .NetworkSettings.Networks \"{NETWORK}\").IPAddress}}}}", RECEIVER).stdout.strip()
    users: list[str] = []
    admin_token = None
    settings_before: dict = {}
    allowlist_before: list = []
    try:
        run(*COMPOSE, "up", "-d", "--no-deps", "clickhouse", "scheduler")
        wait_for(lambda: run(*COMPOSE, "exec", "-T", "clickhouse", "clickhouse-client", "--query", "SELECT 1", check=False).returncode == 0, "ClickHouse", 90)
        run(*COMPOSE, "up", "-d", "--no-deps", "--force-recreate", "log-pusher", env={"LOG_PUSH_TEST_CA_FILE": f"/tests/e2e/.log-push-ca/cert.pem"})
        wait_for(lambda: "healthy" in run("docker", "inspect", "-f", "{{.State.Health.Status}}", "cdnfoundry-dev-log-pusher-1", check=False).stdout, "log-pusher", 120)

        admin_email = f"e2e-logpush-admin-{e2e.RUN_ID}@example.test"
        artisan("App\\Models\\User::query()->create(['name'=>'Push Admin','email'=>" + php_string(admin_email) + ",'password'=>Illuminate\\Support\\Facades\\Hash::make(" + php_string(e2e.ADMIN_PASSWORD) + "),'type'=>'admin']);")
        users.append(admin_email)
        admin_token = login(admin_email, e2e.ADMIN_PASSWORD)
        _, before = request("GET", "/api/admin/system/settings/log_push", token=admin_token)
        settings_before = {f["key"]: f["value"] for f in before["data"]["fields"]}
        _, origin = request("GET", "/api/admin/system/settings/origin_safety", token=admin_token)
        allowlist_before = next(f["value"] for f in origin["data"]["fields"] if f["key"] == "private_origin_allowlist")
        request("PATCH", "/api/admin/system/settings/origin_safety", {"values": {**{f["key"]: f["value"] for f in origin["data"]["fields"]}, "private_origin_allowlist": [f"{address}/32"]}}, admin_token)
        request("PATCH", "/api/admin/system/settings/log_push", {"values": {**settings_before, "domain_user_log_push": True}}, admin_token)

        domains: dict[str, dict] = {}
        for suffix in ("a", "b"):
            email = f"e2e-logpush-{suffix}-{e2e.RUN_ID}@example.test"
            name = f"e2e-logpush-{suffix}-{e2e.RUN_ID}.example.test"
            artisan("$u=App\\Models\\User::query()->create(['name'=>'Push " + suffix + "','email'=>" + php_string(email) + ",'password'=>Illuminate\\Support\\Facades\\Hash::make(" + php_string(e2e.USER_PASSWORD)
                    + "),'type'=>'user']);$d=App\\Models\\Domain::query()->create(['name'=>" + php_string(name) + ",'display_name'=>" + php_string(name) + ",'revision'=>1,'lifecycle_state'=>'active']);$d->users()->attach($u->id);")
            users.append(email)
            token = login(email, e2e.USER_PASSWORD)
            _, listed = request("GET", "/api/domains", token=token)
            domains[suffix] = {"token": token, "name": name, "id": next(d["id"] for d in listed["data"] if d["name"] == name)}

        # Destinations. A gets two datasets with truncation; B gets HTTP requests only.
        a, b = domains["a"], domains["b"]
        _, made_a = request("POST", f"/api/domains/{a['id']}/log-push", {"kind": "https", "name": "A siem", "datasets": ["http_requests", "security_events", "dns_queries"], "url": f"https://{RECEIVER}:8443/ingest/a",
                                                                          "auth": "bearer", "bearer_token": "token-a-0123456789", "truncate_client_ip": True}, a["token"])
        _, made_b = request("POST", f"/api/domains/{b['id']}/log-push", {"kind": "https", "name": "B siem", "datasets": ["http_requests"], "url": f"https://{RECEIVER}:8443/ingest/b", "auth": "hmac"}, b["token"])
        assert "signing_secret" in made_b["meta"] and "token-a-0123456789" not in json.dumps(made_a)
        expect_error(422, "POST", f"/api/domains/{a['id']}/log-push", payload={"kind": "https", "name": "bad", "datasets": ["http_requests"], "url": "https://127.0.0.1/x", "auth": "hmac"}, token=a["token"])
        expect_error(404, "GET", f"/api/domains/{b['id']}/log-push", token=a["token"])
        _, test_op = request("POST", f"/api/domains/{a['id']}/log-push/{made_a['data']['id']}/test", token=a["token"])
        e2e.wait_operation(a["token"], test_op["data"]["operation_id"], 60)

        # Delivery starts at the destination's creation, so every row is stamped at or after the insert; the 30-second ingest margin holds them back briefly.
        # Rows after the destinations exist: five requests for A (three blocked), three for B, one DNS query for A, and a row of another domain id 0.
        time.sleep(2)
        a_ids = insert(a["id"], a["name"], 5, 0)
        blocked_ids = insert(a["id"], a["name"], 3, 1, security=True)
        b_ids = insert(b["id"], b["name"], 3, 0, edge="edge-secret-b")
        clickhouse(f"INSERT INTO cdnf.dns_events (occurred_at,event_id,domain_id,zone,qname,qtype,rcode,client_ip,dns_cluster,country,continent,outcome,pop) VALUES (now64(3) - INTERVAL 150 MILLISECOND, generateUUIDv4(), {a['id']}, '{a['name']}', 'www.{a['name']}', 'A', 'NOERROR', '198.51.100.9', 'dns-internal', 'DE', 'EU', 'answered', 'pop-internal')")

        def diagnose() -> str:
            states = [request("GET", f"/api/domains/{d['id']}/log-push", token=d["token"])[1]["data"] for d in (a, b)]
            counts = {p: len([1 for r in fetch(address) if r["path"] == p]) for p in ("/ingest/a", "/ingest/b")}
            per = {f"{path}:{ds}": len(lines_for(address, path, ds)) for path in ("/ingest/a", "/ingest/b") for ds in ("http_requests", "security_events", "dns_queries")}
            source = {"edge_events_a": clickhouse(f"SELECT count() FROM cdnf.edge_events WHERE domain_id={a['id']}"), "security_a": clickhouse(f"SELECT count() FROM cdnf.edge_events WHERE domain_id={a['id']} AND security_action != ''"),
                      "dns_a": clickhouse(f"SELECT count() FROM cdnf.dns_events WHERE domain_id={a['id']}"), "edge_events_b": clickhouse(f"SELECT count() FROM cdnf.edge_events WHERE domain_id={b['id']}")}
            logs = run("docker", "logs", "--tail", "30", "cdnfoundry-dev-log-pusher-1", check=False)
            return json.dumps({"delivered_lines": per, "in_clickhouse": source, "destinations": states, "receiver_posts": counts, "pusher_log": (logs.stdout + logs.stderr)[-1500:]}, indent=1)
        try:
            wait_for(lambda: len(lines_for(address, "/ingest/a", "http_requests")) >= 8 and len(lines_for(address, "/ingest/b", "http_requests")) >= 3 and len(lines_for(address, "/ingest/a", "dns_queries")) >= 1, "both receivers to have their rows", 200, dispatch)
        except AssertionError as failure:
            raise AssertionError(f"{failure}\n{diagnose()}") from None
        got_a = lines_for(address, "/ingest/a", "http_requests")
        got_b = lines_for(address, "/ingest/b", "http_requests")
        assert {x["event_id"] for x in got_a} == set(a_ids + blocked_ids), "receiver A did not get exactly its own requests"
        assert {x["event_id"] for x in got_b} == set(b_ids), "receiver B did not get exactly its own requests"
        everything = json.dumps(fetch(address))
        assert b["name"] not in json.dumps([r for r in fetch(address) if r["path"] == "/ingest/a"]), "a domain's rows reached another receiver"
        for forbidden in ("edge-secret", "cell-internal", "10.0.0.5", "pop-internal", "dns-internal", "origin_error", "edge_id"):
            assert forbidden not in everything, f"a platform field leaked: {forbidden}"
        assert all(x["client_ip"].endswith(".0") for x in got_a), "client addresses were not truncated"
        assert any(x["client_ip"] == "203.0.113.70" for x in got_b), "B did not receive full client addresses"
        times = [x["occurred_at"] for x in got_a]
        assert times == sorted(times), "rows were not delivered in time order"
        assert {x["event_id"] for x in lines_for(address, "/ingest/a", "security_events")} == set(blocked_ids)
        auth = {r["auth"] for r in fetch(address) if r["path"] == "/ingest/a"}
        assert auth == {"Bearer token-a-0123456789"}

        # A receiver that is down keeps its cursor, backs off, and catches up with no gap and no duplicate.
        fetch(address, "/mode", {"down": True})
        down_ids = insert(a["id"], a["name"], 4, 0)
        _, state = request("GET", f"/api/domains/{a['id']}/log-push", token=a["token"])
        wait_for(lambda: request("GET", f"/api/domains/{a['id']}/log-push", token=a["token"])[1]["data"][0]["consecutive_failures"] >= 1, "the failure to be recorded", 200, dispatch)
        fetch(address, "/mode", {"down": False})
        artisan("App\\Models\\LogPushDestination::query()->update(['next_run_at'=>null]);")
        wait_for(lambda: {x["event_id"] for x in lines_for(address, "/ingest/a", "http_requests")} >= set(down_ids), "the catch-up after recovery", 200, dispatch)
        after = [x["event_id"] for x in lines_for(address, "/ingest/a", "http_requests")]
        assert len(after) == len(set(after)), "a row was delivered twice"
        _, recovered = request("GET", f"/api/domains/{a['id']}/log-push", token=a["token"])
        assert recovered["data"][0]["consecutive_failures"] == 0 and recovered["data"][0]["rows_pushed"] >= 12 and recovered["data"][0]["bytes_pushed"] > 0

        # Behind the retention window: skip ahead and record the gap.
        artisan("$d=App\\Models\\LogPushDestination::query()->where('name','B siem')->first();$d->cursors=['http_requests'=>['at'=>now()->subDays(40)->format('Y-m-d H:i:s.v'),'id'=>'00000000-0000-0000-0000-000000000000']];$d->save();")
        wait_for(lambda: request("GET", f"/api/domains/{b['id']}/log-push", token=b["token"])[1]["data"][0]["last_gap_at"], "the gap to be recorded", 200, dispatch)

        # The switch off stops delivery within a minute and keeps the destination.
        request("PATCH", "/api/admin/system/settings/log_push", {"values": {**settings_before, "domain_user_log_push": False}}, admin_token)
        sent_before = len(lines_for(address, "/ingest/a", "http_requests"))
        insert(a["id"], a["name"], 2, 0)
        time.sleep(100)
        dispatch()
        time.sleep(15)
        assert len(lines_for(address, "/ingest/a", "http_requests")) == sent_before, "a push happened after the switch was turned off"
        _, kept = request("GET", f"/api/domains/{a['id']}/log-push", token=a["token"])
        assert len(kept["data"]) == 1
        print("log push qualification passed")
    finally:
        for email in users:
            artisan("$u=App\\Models\\User::query()->where('email'," + php_string(email) + ")->first(); if($u){foreach($u->domains as $d){$d->forceDelete();} $u->delete();}")
        if admin_token is not None and settings_before:
            for group, values in (("log_push", settings_before),):
                try:
                    request("PATCH", f"/api/admin/system/settings/{group}", {"values": values}, admin_token)
                    _, origin = request("GET", "/api/admin/system/settings/origin_safety", token=admin_token)
                    request("PATCH", "/api/admin/system/settings/origin_safety", {"values": {**{f["key"]: f["value"] for f in origin["data"]["fields"]}, "private_origin_allowlist": allowlist_before}}, admin_token)
                except ApiError:
                    pass
        run("docker", "rm", "-f", RECEIVER, check=False)
        shutil.rmtree(CA_DIR, ignore_errors=True)
        run(*COMPOSE, "up", "-d", "--no-deps", "--force-recreate", "log-pusher", check=False)


if __name__ == "__main__":
    main()
