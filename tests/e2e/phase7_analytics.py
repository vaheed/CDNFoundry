#!/usr/bin/env python3
"""Real Phase 7 telemetry, analytics, outage, privacy, and usage qualification."""

import datetime as dt
import json
import os
import pathlib
import secrets
import subprocess
import time
import urllib.error
import urllib.parse
import urllib.request
import uuid

ROOT = pathlib.Path(__file__).resolve().parents[2]
COMPOSE = os.environ.get("CDNF_COMPOSE_FILE", "compose.dev.yml")
BASE_URL = os.environ.get("CDNF_BASE_URL", "http://localhost:8080").rstrip("/")
RUN_ID = f"{int(time.time())}-{secrets.token_hex(4)}"
DOMAIN = f"analytics-{RUN_ID}.phase7.test"
PASSWORD = f"Analytics-1-{secrets.token_urlsafe(18)}"
USER_EMAIL = f"phase7-user-{RUN_ID}@example.test"
STRANGER_EMAIL = f"phase7-stranger-{RUN_ID}@example.test"
ADMIN_EMAIL = f"phase7-admin-{RUN_ID}@example.test"


class ApiError(RuntimeError):
    def __init__(self, status: int, payload: object):
        super().__init__(f"HTTP {status}: {payload}")
        self.status = status
        self.payload = payload


def run(*args: str, check: bool = True, timeout: int = 60) -> subprocess.CompletedProcess[str]:
    result = subprocess.run(args, cwd=ROOT, text=True, capture_output=True, check=False, timeout=timeout)
    if check and result.returncode != 0:
        raise RuntimeError(f"command failed ({result.returncode}): {' '.join(args)}\n{result.stdout}\n{result.stderr}")
    return result


def compose(*args: str, check: bool = True, timeout: int = 60) -> subprocess.CompletedProcess[str]:
    return run("docker", "compose", "-f", COMPOSE, *args, check=check, timeout=timeout)


def php_string(value: str) -> str:
    return "'" + value.replace("\\", "\\\\").replace("'", "\\'") + "'"


def artisan(expression: str) -> str:
    return compose("exec", "-T", "core", "php", "artisan", "tinker", f"--execute={expression}").stdout.strip()


def api(method: str, path: str, payload: object | None = None, token: str | None = None,
        idempotency_key: str | None = None, raw: bool = False) -> tuple[int, object]:
    headers = {"Accept": "text/csv" if raw else "application/json"}
    data = None
    if payload is not None:
        headers["Content-Type"] = "application/json"
        data = json.dumps(payload).encode()
    if token:
        headers["Authorization"] = f"Bearer {token}"
    if idempotency_key:
        headers["Idempotency-Key"] = idempotency_key
    request = urllib.request.Request(f"{BASE_URL}{path}", data=data, headers=headers, method=method)
    try:
        with urllib.request.urlopen(request, timeout=12) as response:
            body = response.read()
            return response.status, body.decode() if raw else (json.loads(body) if body else {})
    except urllib.error.HTTPError as error:
        body = error.read()
        try:
            decoded = json.loads(body) if body else {}
        except json.JSONDecodeError:
            decoded = body.decode(errors="replace")
        raise ApiError(error.code, decoded) from error


def expect_error(status: int, method: str, path: str, **kwargs: object) -> ApiError:
    try:
        api(method, path, **kwargs)
    except ApiError as error:
        assert error.status == status, error
        return error
    raise AssertionError(f"{method} {path} unexpectedly succeeded")


def wait_for_api() -> None:
    deadline = time.monotonic() + 60
    while time.monotonic() < deadline:
        try:
            status, payload = api("GET", "/api/health")
            if status == 200 and payload.get("status") == "ok":
                return
        except (ApiError, OSError):
            pass
        time.sleep(1)
    raise RuntimeError("control-plane API did not become healthy")


def restart_vector(reconnect_sources: bool = False) -> None:
    compose("restart", "vector", timeout=90)
    deadline = time.monotonic() + 30
    while time.monotonic() < deadline:
        result = compose(
            "exec", "-T", "vector", "wget", "-qO-", "http://127.0.0.1:9598/metrics", check=False,
        )
        if result.returncode == 0 and "vector_buffer_max_size_bytes" in result.stdout:
            if reconnect_sources:
                compose("restart", "dnsdist", "edge-a", timeout=90)
                deadline = time.monotonic() + 30
                while time.monotonic() < deadline:
                    health = run("curl", "-sS", "-o", "/dev/null", "-w", "%{http_code}", "http://127.0.0.1:8081/healthz", check=False)
                    if health.returncode == 0 and health.stdout.strip() == "200":
                        return
                    time.sleep(1)
                raise RuntimeError("telemetry producers did not recover after collector restart")
            return
        time.sleep(1)
    raise RuntimeError("Vector did not become ready after restart")


def vector_event(port: int, event: dict[str, object]) -> None:
    compose(
        "exec", "-T", "vector", "wget", "-qO-", "--header", "Content-Type: application/json",
        "--post-data", json.dumps(event, separators=(",", ":")), f"http://127.0.0.1:{port}/",
    )


def clickhouse(query: str) -> str:
    return compose("exec", "-T", "clickhouse", "clickhouse-client", "--query", query).stdout.strip()


def wait_for_clickhouse(predicate, query: str, timeout: int = 60, producer=None) -> str:
    deadline = time.monotonic() + timeout
    next_production = 0.0
    last = ""
    while time.monotonic() < deadline:
        now = time.monotonic()
        if producer is not None and now >= next_production:
            producer()
            next_production = now + 2
        result = compose("exec", "-T", "clickhouse", "clickhouse-client", "--query", query, check=False)
        if result.returncode == 0:
            last = result.stdout.strip()
            if predicate(last):
                return last
        time.sleep(1)
    raise AssertionError(f"ClickHouse condition timed out; last result: {last!r}")


def iso(value: dt.datetime) -> str:
    return value.astimezone(dt.timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ")


def login(email: str) -> str:
    _, response = api("POST", "/api/auth/login", {"email": email, "password": PASSWORD, "device_name": "phase7-e2e"})
    return response["data"]["token"]


def create_scope() -> tuple[int, str, str, str]:
    output = artisan(
        "$user=App\\Models\\User::query()->create(["
        f"'name'=>'Phase 7 user','email'=>{php_string(USER_EMAIL)},'password'=>Illuminate\\Support\\Facades\\Hash::make({php_string(PASSWORD)}),'type'=>'user']);"
        "$stranger=App\\Models\\User::query()->create(["
        f"'name'=>'Phase 7 stranger','email'=>{php_string(STRANGER_EMAIL)},'password'=>Illuminate\\Support\\Facades\\Hash::make({php_string(PASSWORD)}),'type'=>'user']);"
        "$admin=App\\Models\\User::query()->create(["
        f"'name'=>'Phase 7 admin','email'=>{php_string(ADMIN_EMAIL)},'password'=>Illuminate\\Support\\Facades\\Hash::make({php_string(PASSWORD)}),'type'=>'admin']);"
        f"$domain=App\\Models\\Domain::query()->create(['name'=>{php_string(DOMAIN)},'display_name'=>'Phase 7 analytics','lifecycle_state'=>'active','revision'=>1]);"
        "$domain->users()->attach($user,['created_at'=>now()]);echo $domain->id;"
    )
    assert output.isdigit(), output
    return int(output), login(USER_EMAIL), login(STRANGER_EMAIL), login(ADMIN_EMAIL)


def qualify_ingestion_and_queries(domain_id: int, user: str, stranger: str, admin: str) -> tuple[dt.datetime, dt.datetime]:
    now = dt.datetime.now(dt.timezone.utc)
    interval_from = now.replace(minute=0, second=0, microsecond=0) - dt.timedelta(hours=2)
    interval_to = interval_from + dt.timedelta(hours=1)
    edge_id = str(uuid.uuid4())
    dns_id = str(uuid.uuid4())
    event_time = interval_from + dt.timedelta(minutes=5)
    runtime_path = f"/runtime-{RUN_ID}"

    def produce_runtime_event() -> None:
        edge = run(
            "curl", "-sS", "-o", "/dev/null", "-w", "%{http_code}", "-H", f"Host: {DOMAIN}",
            f"http://127.0.0.1:8081{runtime_path}?token=must-not-survive", check=False,
        )
        assert edge.returncode == 0 and len(edge.stdout.strip()) == 3, edge

    wait_for_clickhouse(
        lambda value: int(value or "0") >= 1,
        f"SELECT count() FROM cdnf.edge_events WHERE hostname='{DOMAIN}' AND path='{runtime_path}'",
        producer=produce_runtime_event,
    )
    dnstap_name = f"dnstap-{RUN_ID}.{DOMAIN}"
    dns_runtime = run("dig", "+time=2", "+tries=1", "@127.0.0.1", "-p", "1053", dnstap_name, "A")
    assert "status:" in dns_runtime.stdout, dns_runtime.stdout
    wait_for_clickhouse(
        lambda value: int(value or "0") >= 1,
        f"SELECT count() FROM cdnf.dns_events WHERE zone='{dnstap_name}'",
    )
    vector_event(8686, {
        "occurred_at": iso(event_time), "event_id": edge_id, "domain_id": domain_id,
        "hostname": DOMAIN, "method": "GET", "path": "/account?token=must-not-survive",
        "query": "token=must-not-survive", "authorization": "Bearer must-not-survive",
        "cookie": "session=must-not-survive", "body": "must-not-survive", "status": 503,
        "bytes_in": 17, "bytes_out": 1700, "cache_status": "MISS", "origin_latency_ms": 12,
        "origin_error": "timeout", "security_action": "block", "security_reason": "rate_limit",
        "client_ip": "192.0.2.123", "country": "IR", "continent": "AS", "edge_id": "edge-phase7",
        "event_type": "request",
    })
    vector_event(8687, {
        "occurred_at": iso(event_time + dt.timedelta(seconds=1)), "event_id": dns_id,
        "domain_id": domain_id, "zone": DOMAIN, "qname": f"www.{DOMAIN}", "qtype": "AAAA",
        "rcode": "NOERROR", "client_ip": "2001:db8:1234:5678::1", "dns_cluster": "dns-phase7",
        "country": "IR", "continent": "AS", "outcome": "answer", "query": "must-not-survive",
    })
    wait_for_clickhouse(lambda value: value == "1", f"SELECT count() FROM cdnf.edge_events WHERE event_id=toUUID('{edge_id}')")
    wait_for_clickhouse(lambda value: value == "1", f"SELECT count() FROM cdnf.dns_events WHERE event_id=toUUID('{dns_id}')")

    stored_path = clickhouse(f"SELECT path FROM cdnf.edge_events WHERE event_id=toUUID('{edge_id}') FORMAT TSV")
    assert stored_path == "/account", stored_path
    leaked = clickhouse(
        f"SELECT count() FROM cdnf.edge_events WHERE event_id=toUUID('{edge_id}') AND "
        "(position(path, 'must-not-survive') > 0 OR position(hostname, 'must-not-survive') > 0)"
    )
    assert leaked == "0", leaked

    query = urllib.parse.urlencode({"from": iso(interval_from), "to": iso(interval_to)})
    expect_error(403, "GET", f"/api/domains/{domain_id}/analytics/summary?{query}", token=stranger)
    _, summary = api("GET", f"/api/domains/{domain_id}/analytics/summary?{query}", token=user)
    assert int(summary["data"]["requests"]) >= 1 and int(summary["data"]["dns_queries"]) >= 1, summary
    assert summary["meta"]["units"] == {"bandwidth": "bytes", "latency": "milliseconds"}, summary
    for view in ("timeseries", "status-codes", "cache", "countries", "hostnames", "top-urls", "origin", "edges", "dns"):
        _, result = api("GET", f"/api/domains/{domain_id}/analytics/{view}?{query}", token=user)
        assert isinstance(result["data"], list), (view, result)
    for stream in ("requests", "dns", "errors", "security"):
        _, result = api("GET", f"/api/domains/{domain_id}/logs/{stream}?{query}", token=user)
        assert result["data"], (stream, result)
    _, request_log = api("GET", f"/api/domains/{domain_id}/logs/requests?{query}", token=user)
    _, dns_log = api("GET", f"/api/domains/{domain_id}/logs/dns?{query}", token=user)
    assert request_log["data"][0]["client_ip"] == "192.0.2.0/24", request_log
    assert dns_log["data"][0]["client_ip"] == "2001:db8:1234::/48", dns_log
    _, global_summary = api("GET", f"/api/admin/analytics/summary?{query}", token=admin)
    assert int(global_summary["data"]["requests"]) >= 1, global_summary
    for path in ("/api/admin/analytics/traffic", "/api/admin/analytics/dns", "/api/admin/logs/errors", "/api/admin/logs/security"):
        _, result = api("GET", f"{path}?{query}", token=admin)
        assert "data" in result, (path, result)
    expect_error(403, "GET", f"/api/admin/analytics/summary?{query}", token=user)
    return interval_from, interval_to


def qualify_usage(domain_id: int, user: str, admin: str, interval_from: dt.datetime, interval_to: dt.datetime) -> None:
    artisan(
        f"$job=new App\\Jobs\\BuildUsageRollups({php_string(iso(interval_from))},{php_string(iso(interval_to))},{domain_id});"
        "$job->handle(app(App\\Support\\AnalyticsStore::class));$job->handle(app(App\\Support\\AnalyticsStore::class));"
        f"echo App\\Models\\UsageRollup::query()->where('domain_id',{domain_id})->where('interval_start',{php_string(iso(interval_from))})->count();"
    )
    query = urllib.parse.urlencode({"from": iso(interval_from), "to": iso(interval_to)})
    _, usage = api("GET", f"/api/domains/{domain_id}/usage/export?{query}", token=user)
    assert usage["meta"]["contract_version"] == 1 and len(usage["data"]) == 1, usage
    assert int(usage["data"][0]["requests"]) >= 1 and int(usage["data"][0]["dns_queries"]) >= 1, usage
    _, csv = api("GET", f"/api/domains/{domain_id}/usage/export?{query}&format=csv", token=user, raw=True)
    assert "contract_version,domain_id,interval_start" in csv and ",finalized" in csv, csv
    _, admin_usage = api("GET", f"/api/admin/usage?{query}", token=admin)
    assert admin_usage["data"]["data"], admin_usage
    key = str(uuid.uuid4())
    status, operation = api("POST", "/api/admin/usage/rebuild", {
        "domain_id": domain_id, "from": iso(interval_from), "to": iso(interval_to),
    }, admin, key)
    status2, replay = api("POST", "/api/admin/usage/rebuild", {
        "domain_id": domain_id, "from": iso(interval_from), "to": iso(interval_to),
    }, admin, key)
    assert status == status2 == 202 and operation == replay, (operation, replay)


def qualify_outage(domain_id: int, user: str) -> None:
    before = run("curl", "-sS", "-o", "/dev/null", "-w", "%{http_code}", "-H", f"Host: {DOMAIN}", "http://127.0.0.1:8081/", check=False)
    assert before.returncode == 0 and len(before.stdout.strip()) == 3, before
    buffered_id = str(uuid.uuid4())
    clickhouse_stopped = False
    try:
        compose("stop", "clickhouse", timeout=90)
        clickhouse_stopped = True
        outage = expect_error(503, "GET", f"/api/domains/{domain_id}/analytics/summary", token=user)
        assert outage.payload.get("code") == "analytics_unavailable", outage.payload
        vector_event(8686, {
            "occurred_at": iso(dt.datetime.now(dt.timezone.utc)), "event_id": buffered_id,
            "domain_id": domain_id, "hostname": DOMAIN, "method": "GET", "path": "/buffered",
            "status": 200, "bytes_in": 1, "bytes_out": 2, "cache_status": "HIT",
            "client_ip": "198.51.100.7", "edge_id": "edge-phase7", "event_type": "request",
        })
        dns = run("dig", "+time=2", "+tries=1", "@127.0.0.1", "-p", "1053", f"outage.{DOMAIN}", "SOA")
        assert "status:" in dns.stdout, dns.stdout
        during = run("curl", "-sS", "-o", "/dev/null", "-w", "%{http_code}", "-H", f"Host: {DOMAIN}", "http://127.0.0.1:8081/", check=False)
        assert during.returncode == 0 and len(during.stdout.strip()) == 3, during
        metrics = compose("exec", "-T", "vector", "wget", "-qO-", "http://127.0.0.1:9598/metrics").stdout
        assert "vector_buffer" in metrics and "vector_component" in metrics, metrics[:1000]
    finally:
        if clickhouse_stopped:
            compose("start", "clickhouse", timeout=90)
    # A collector restart is safe for serving and also qualifies durable buffer
    # recovery instead of relying on process memory from before the outage.
    restart_vector()
    wait_for_clickhouse(lambda value: value == "1", f"SELECT count() FROM cdnf.edge_events WHERE event_id=toUUID('{buffered_id}')", timeout=90)


def qualify_scale(admin: str) -> None:
    clickhouse(
        "INSERT INTO cdnf.edge_events SELECT now64(3)-number%3600, generateUUIDv4(), "
        "toUInt64(900000000+number), 'scale.phase7.test', 'GET', '/scale', 200, 0, 1, 'HIT', 0, '', '', '', '', "
        "'edge-scale', '192.0.2.1', 'ZZ', 'ZZ', '', '', 'request' FROM numbers(20000)"
    )
    started = time.monotonic()
    _, result = api("GET", "/api/admin/analytics/summary", token=admin)
    elapsed = time.monotonic() - started
    assert "data" in result and elapsed < 10, (elapsed, result)


def main() -> None:
    wait_for_api()
    restart_vector(reconnect_sources=True)
    domain_id, user, stranger, admin = create_scope()
    interval_from, interval_to = qualify_ingestion_and_queries(domain_id, user, stranger, admin)
    qualify_usage(domain_id, user, admin, interval_from, interval_to)
    qualify_scale(admin)
    qualify_outage(domain_id, user)
    print("Phase 7 real-runtime telemetry, analytics, privacy, usage, scale, and outage qualification passed.")


if __name__ == "__main__":
    main()
