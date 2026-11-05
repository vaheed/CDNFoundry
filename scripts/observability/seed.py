#!/usr/bin/env python3
"""Load deterministic traffic into ClickHouse, Prometheus, and Loki on a development stack, and start a scenario.

    make dev-observability-seed SCENARIO=normal
    make dev-observability-seed SCENARIO=l7-flood

It writes only fake PoPs (seed-fra, seed-iad, seed-sin), fake domains (ids 9001-9008), and fake hostnames
(site1.seed.test and so on). It refuses to run against anything that is not the development Compose project.
"""
from __future__ import annotations

import argparse
import json
import os
import pathlib
import struct
import subprocess
import sys
import time
import urllib.error
import urllib.request

sys.path.insert(0, str(pathlib.Path(__file__).resolve().parent))

import seed_exporter as exporter  # noqa: E402
import seed_model as model  # noqa: E402

ROOT = pathlib.Path(__file__).resolve().parents[2]
COMPOSE = ["docker", "compose", "-f", str(ROOT / "compose.dev.yml"), "--profile", "seed"]
PROMETHEUS = "http://127.0.0.1:9090"
LOKI = "http://127.0.0.1:3100"
EXPORTER = "http://127.0.0.1:9108"
GRAFANA = "http://127.0.0.1:3000"
SCENARIO_MINUTES = {"normal": 0, "origin-down": 40, "l7-flood": 30, "dns-flood": 30, "pop-loss": 40, "cert-expiry": 60, "purge-stuck": 30, "telemetry-drop": 30}


def clickhouse(sql: str) -> None:
    subprocess.run([*COMPOSE, "exec", "-T", "clickhouse", "clickhouse-client", "--user", "cdnf", "--password", "cdnf-dev-only", "--multiquery"], input=sql, text=True, check=True, cwd=ROOT)


def backfill_clickhouse() -> None:
    """Thirty days of normal events, generated inside ClickHouse so the rollups fill through their own views."""
    pops = "[" + ",".join(f"('{p['pop']}','{p['region']}','{p['edge']}')" for p in model.POPS) + "]"

    def select(span: str, density: str, start: str) -> str:
        return f"""
INSERT INTO cdnf.edge_events (occurred_at, domain_id, hostname, method, path, status, bytes_in, bytes_out, bytes_sent, cache_status, origin_latency_ms, upstream_header_ms,
    origin_error, origin_role, origin_transition, security_action, security_reason, security_class, edge_id, pop, region, client_ip, ip_version, country, continent, asn,
    user_agent, event_type, request_time_ms, server_protocol, ssl_protocol, ssl_cipher, scheme, request_id, waf_profile, waf_rule_id, waf_score, waf_action, waf_processing_us)
SELECT
    {start} + toIntervalSecond(cityHash64(n, 99) % ({density})) AS occurred_at,
    9001 + cityHash64(n, 1) % 8 AS domain_id,
    concat('site', toString(domain_id - 9000), '.seed.test'),
    if(cityHash64(n, 2) % 100 < 90, 'GET', 'POST'),
    concat('/assets/', toString(cityHash64(n, 3) % 200), '.js'),
    multiIf(cityHash64(n, 4) % 10000 < 9300, 200, cityHash64(n, 4) % 10000 < 9700, 304, cityHash64(n, 4) % 10000 < 9970, 404, 503) AS status,
    400 + cityHash64(n, 5) % 800,
    1500 + (cityHash64(n, 6) % 1000) * 80,
    2000 + (cityHash64(n, 6) % 1000) * 90,
    if(cityHash64(n, 7) % 100 < 72, 'HIT', arrayElement(['MISS','EXPIRED','BYPASS','STALE','UNKNOWN'], 1 + cityHash64(n, 8) % 5)) AS cache,
    if(cache = 'HIT', NULL, toUInt32(20 + (cityHash64(n, 9) % 1000) / 1000 * 600)) AS origin,
    if(cache = 'HIT', NULL, toUInt32(origin * 0.6)),
    if(status >= 500 AND cache != 'HIT', '502', ''), 'primary', if(cityHash64(n, 30) % 2000 = 0, 'failover', 'none'),
    multiIf(cityHash64(n, 10) % 1000 < 8, 'block', 'allow') AS action,
    multiIf(cityHash64(n, 10) % 1000 < 3, 'waf_rule_matched', cityHash64(n, 10) % 1000 < 5, 'cache_abuse_detected', cityHash64(n, 10) % 1000 < 8, 'domain_rate_exceeded', ''),
    multiIf(cityHash64(n, 10) % 1000 < 3, 'waf', cityHash64(n, 10) % 1000 < 5, 'cache_abuse_detected', cityHash64(n, 10) % 1000 < 8, 'block', ''),
    pop_row.3, pop_row.1, pop_row.2,
    if(cityHash64(n, 11) % 100 < 70, concat('198.51.', toString(100 + cityHash64(n, 12) % 4), '.', toString(1 + cityHash64(n, 13) % 250)), concat('2001:db8:', hex(cityHash64(n, 12) % 8), '::', toString(1 + cityHash64(n, 13) % 1000))) AS ip,
    if(position(ip, ':') > 0, '6', '4'),
    arrayElement(['DE','US','SG','FR','GB','BR','IN','JP','NL','CA'], 1 + cityHash64(n, 14) % 10) AS country,
    arrayElement(['EU','NA','AS','EU','EU','SA','AS','AS','EU','NA'], 1 + cityHash64(n, 14) % 10),
    64500 + cityHash64(n, 15) % 12,
    arrayElement(['Mozilla/5.0 (X11; Linux x86_64)', 'curl/8.5.0', 'Googlebot/2.1', 'Mozilla/5.0 (iPhone; CPU iPhone OS 17)'], 1 + cityHash64(n, 16) % 4),
    'request',
    toUInt32(5 + pow((cityHash64(n, 17) % 1000) / 1000, 4) * 900),
    multiIf(cityHash64(n, 18) % 100 < 60, 'h2', cityHash64(n, 18) % 100 < 95, 'http/1.1', 'h3'),
    if(cityHash64(n, 19) % 100 < 80, 'TLSv1.3', 'TLSv1.2') AS tls,
    if(tls = 'TLSv1.3', 'TLS_AES_256_GCM_SHA384', 'ECDHE-RSA-AES128-GCM-SHA256'),
    'https',
    concat(lower(hex(cityHash64(n, 20))), lower(hex(cityHash64(n, 21)))),
    'balanced', if(cityHash64(n, 10) % 1000 < 3, 942100 + cityHash64(n, 22) % 5, 0), if(cityHash64(n, 10) % 1000 < 3, 5, 0),
    if(cityHash64(n, 10) % 1000 < 3, 'block', if(cityHash64(n, 23) % 10 < 3, 'inspect', 'off')), 200 + cityHash64(n, 24) % 800
FROM (SELECT number AS n, arrayElement({pops}, 1 + number % 3) AS pop_row FROM numbers({span}))
"""
    # The last 48 hours at one-minute resolution, then 28 days at a thinner but steady rate.
    recent = int(2880 * 3 * 20)
    older = int(28 * 24 * 3 * 120)
    clickhouse(select(str(recent), "60 * 2880", "now() - INTERVAL 2880 MINUTE").replace("FROM (SELECT number AS n", "FROM (SELECT number AS n", 1))
    clickhouse(select(str(older), "3600 * 24 * 28", "now() - INTERVAL 30 DAY"))
    clickhouse("""
INSERT INTO cdnf.edge_events (occurred_at, domain_id, hostname, method, path, status, bytes_sent, bytes_out, cache_status, origin_latency_ms, request_time_ms, edge_id, pop, region,
    server_protocol, ssl_protocol, scheme, request_id, event_type, security_action)
VALUES (now() - INTERVAL 1 HOUR, 9001, 'site1.seed.test', 'GET', '/assets/trace-sample.js', 200, 4800, 4400, 'MISS', 120, 180, '00000000-0000-4000-8000-0000000000a1', 'seed-fra', 'eu',
    'h2', 'TLSv1.3', 'https', 'cdnf-seed-request-0001', 'request', 'allow');
INSERT INTO cdnf.edge_events (occurred_at, event_type, hostname, tls_error, ssl_protocol, edge_id, pop, region, security_action)
SELECT now() - toIntervalSecond(cityHash64(number) % (86400 * 28)), 'tls', 'unknown.invalid', 'unknown_sni', 'TLSv1.3', 'seed', 'seed-fra', 'eu', 'allow' FROM numbers(4000);
INSERT INTO cdnf.dns_events (occurred_at, domain_id, zone, qname, qtype, rcode, client_ip, dns_cluster, country, continent, outcome, asn, pop, region)
SELECT now() - toIntervalSecond(cityHash64(number, 1) % (86400 * 28)), 0, 'seed.test', concat('www.site', toString(1 + cityHash64(number, 2) % 8), '.seed.test'),
       arrayElement(['A','AAAA','TXT','ANY'], 1 + cityHash64(number, 3) % 4), if(cityHash64(number, 4) % 100 < 12, 'NXDOMAIN', 'NOERROR'),
       concat('198.51.', toString(100 + cityHash64(number, 5) % 4), '.', toString(1 + cityHash64(number, 6) % 250)), 'seed-dnsdist', 'ZZ', 'ZZ', 'answered',
       64500 + cityHash64(number, 7) % 12, 'seed-fra', 'eu'
FROM numbers(120000);
""")


# --- Prometheus remote write: a snappy block of literals around a protobuf WriteRequest -----------------------

def varint(value: int) -> bytes:
    out = bytearray()
    while True:
        byte = value & 0x7F
        value >>= 7
        out.append(byte | (0x80 if value else 0))
        if not value:
            return bytes(out)


def field(number: int, payload: bytes) -> bytes:
    return varint(number << 3 | 2) + varint(len(payload)) + payload


def write_request(series: list[tuple[dict[str, str], list[tuple[float, int]]]]) -> bytes:
    body = b""
    for labels, samples in series:
        message = b"".join(field(1, field(1, k.encode()) + field(2, v.encode())) for k, v in sorted(labels.items()))
        message += b"".join(field(2, b"\x09" + struct.pack("<d", value) + b"\x10" + varint(timestamp)) for value, timestamp in samples)
        body += field(1, message)
    return body


def snappy(data: bytes) -> bytes:
    out = bytearray(varint(len(data)))
    for start in range(0, len(data), 65536):
        chunk = data[start:start + 65536]
        length = len(chunk) - 1
        out += bytes([61 << 2]) + length.to_bytes(2, "little") + chunk
    return bytes(out)


def remote_write(series: list[tuple[dict[str, str], list[tuple[float, int]]]]) -> None:
    for start in range(0, len(series), 400):
        payload = snappy(write_request(series[start:start + 400]))
        request = urllib.request.Request(f"{PROMETHEUS}/api/v1/write", data=payload, method="POST", headers={
            "Content-Encoding": "snappy", "Content-Type": "application/x-protobuf", "X-Prometheus-Remote-Write-Version": "0.1.0"})
        try:
            urllib.request.urlopen(request, timeout=60).read()
        except urllib.error.HTTPError as error:
            raise SystemExit(f"Prometheus rejected the backfill: {error.code} {error.read().decode()[:300]}") from error


def backfill_prometheus(hours: int = 48) -> None:
    """Raw counters and the recorded 5-minute rates for the last `hours`, from the same functions the exporter uses."""
    now = int(time.time() // 60) * 60
    steps = [now - minute * 60 for minute in range(hours * 60, 0, -1)]
    scenarios = model.Scenarios()
    series: dict[tuple, list[tuple[float, int]]] = {}

    def add(name: str, labels: dict[str, str], t: int, value: float) -> None:
        key = (name, tuple(sorted(labels.items())))
        series.setdefault(key, []).append((value, t * 1000))

    for t in steps:
        for pop in model.POPS:
            base = {"pop": pop["pop"], "region": pop["region"], "edge": pop["edge"]}
            before = t - 300
            for status in model.STATUS:
                for cache in model.CACHE:
                    labels = {**base, "status_class": status, "cache_status": cache}
                    add("cdnf_edge_requests_total", labels, t, model.requests_total(pop, status, cache, t, scenarios))
                    add("cdnf:edge_requests:rate5m", labels, t, (model.requests_total(pop, status, cache, t, scenarios) - model.requests_total(pop, status, cache, before, scenarios)) / 300)
            total, previous = model.total_requests(pop, t, scenarios), model.total_requests(pop, before, scenarios)
            add("cdnf_edge_bytes_sent_total", base, t, total * model.BYTES_PER_REQUEST)
            add("cdnf:edge_bytes_sent:rate5m", base, t, (total - previous) * model.BYTES_PER_REQUEST / 300)
            for metric, recorded, cdf, share in (("cdnf_edge_request_duration_seconds", "cdnf:edge_latency_bucket:rate5m", model.EDGE_CDF, 1.0), ("cdnf_edge_origin_duration_seconds", "cdnf:edge_origin_latency_bucket:rate5m", model.ORIGIN_CDF, 0.28)):
                for bound, fraction in zip([*model.BUCKETS, "+Inf"], [*cdf, 1.0]):
                    add(f"{metric}_bucket", {**base, "le": str(bound)}, t, total * share * fraction)
                    add(recorded, {**base, "le": str(bound)}, t, (total - previous) * share * fraction / 300)
                add(f"{metric}_count", base, t, total * share)
                add(f"{metric}_sum", base, t, total * share * 0.12)
            failures = total * 0.28 * 0.004
            add("cdnf_edge_origin_failures_total", base, t, failures)
            add("cdnf:edge_origin_failures:rate5m", base, t, (total - previous) * 0.28 * 0.004 / 300)
            for klass in model.SECURITY:
                add("cdnf_edge_security_events_total", {**base, "class": klass}, t, model.security_total(pop, klass, t, scenarios))
                add("cdnf:edge_security_events:rate5m", {**base, "class": klass}, t, (model.security_total(pop, klass, t, scenarios) - model.security_total(pop, klass, before, scenarios)) / 300)
            for reason in model.TLS_REASONS:
                add("cdnf_edge_tls_failures_total", {**base, "reason": reason}, t, model.tls_total(pop, reason, t, scenarios))
                add("cdnf:edge_tls_failures:rate5m", {**base, "reason": reason}, t, (model.tls_total(pop, reason, t, scenarios) - model.tls_total(pop, reason, before, scenarios)) / 300)
            for rcode in model.DNS_RCODE:
                for outcome in ("answered", "response_limit"):
                    labels = {"pop": pop["pop"], "region": pop["region"], "rcode": rcode, "outcome": outcome}
                    value = model.dns_queries_total(pop, rcode, outcome, t, scenarios)
                    add("cdnf_dns_queries_total", labels, t, value)
                    add("cdnf:dns_queries:rate5m", labels, t, (value - model.dns_queries_total(pop, rcode, outcome, before, scenarios)) / 300)
            add("cdnfoundry_edge_heartbeat_age_seconds", {"edge": pop["edge"], "pop": pop["pop"]}, t, 4.0)
            add("cdnfoundry_edge_gateway_active_revision", {"edge": pop["edge"], "pop": pop["pop"]}, t, 100)
            for job, probe in (("blackbox-http", "http"), ("blackbox-tcp", "tcp"), ("blackbox-dns-udp", "dns_udp"), ("blackbox-dns-tcp", "dns_tcp")):
                labels = {"job": job, "probe": probe, "pop": pop["pop"], "region": pop["region"], "ip_version": "4", "instance": f"192.0.2.{10 + model.POPS.index(pop)}"}
                add("probe_success", labels, t, 1)
    items = [({"__name__": key[0], **dict(key[1])}, samples) for key, samples in series.items()]
    remote_write(items)
    print(f"prometheus: {len(items)} series, {sum(len(s) for _, s in items)} samples")


def backfill_loki(hours: int = 6) -> None:
    os.environ["LOKI_URL"] = LOKI  # the exporter container uses the Compose name; the host uses the published port
    now = int(time.time() // 60)
    for minute in range(now - hours * 60, now):
        exporter.loki_lines(minute, model.Scenarios())


def activate(name: str) -> None:
    minutes = SCENARIO_MINUTES[name]
    body = json.dumps({"scenario": name, "minutes": minutes, "start": time.time() - 60}).encode()
    request = urllib.request.Request(f"{EXPORTER}/scenarios", data=body, method="POST", headers={"Content-Type": "application/json"})
    urllib.request.urlopen(request, timeout=10).read()
    if name != "normal":
        annotate(name, minutes)


def annotate(name: str, minutes: int) -> None:
    """One annotation spanning the scenario's window, so a dashboard shows when the seeded event happened."""
    import base64
    now = int(time.time() * 1000)
    body = json.dumps({"time": now - 60000, "timeEnd": now + minutes * 60000, "tags": ["seed", name], "text": f"Seed scenario: {name}"}).encode()
    auth = base64.b64encode(b"admin:cdnf-grafana-dev-only").decode()
    request = urllib.request.Request(f"{GRAFANA}/api/annotations", data=body, method="POST", headers={"Content-Type": "application/json", "Authorization": f"Basic {auth}"})
    try:
        urllib.request.urlopen(request, timeout=10).read()
    except (urllib.error.URLError, OSError) as error:
        print(f"annotation skipped: {error}", file=sys.stderr)


def wait_for_exporter() -> None:
    for _ in range(60):
        try:
            urllib.request.urlopen(f"{EXPORTER}/metrics", timeout=3).read()
            return
        except (urllib.error.URLError, OSError):
            time.sleep(2)
    raise SystemExit("the seed exporter did not start")


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("--scenario", default="normal", choices=sorted(SCENARIO_MINUTES))
    parser.add_argument("--skip-backfill", action="store_true", help="only start the scenario; history is already loaded")
    arguments = parser.parse_args()
    subprocess.run([*COMPOSE, "up", "-d", "--build", "seed-exporter"], check=True, cwd=ROOT)
    wait_for_exporter()
    if not arguments.skip_backfill:
        backfill_clickhouse()
        backfill_prometheus()
        backfill_loki()
    activate(arguments.scenario)
    print(f"seeded scenario {arguments.scenario}")


if __name__ == "__main__":
    main()
