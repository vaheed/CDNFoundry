#!/usr/bin/env python3
"""A development-only exporter that serves the seed model's metrics for the last instant, and takes scenarios.

It exists so a fresh development stack shows what every dashboard and alert needs: several PoPs with traffic,
probes, latency histograms, DNS, and security events. It is never part of a production Compose file.
"""
from __future__ import annotations

import hashlib
import json
import os
import pathlib
import sys
import threading
import time
import urllib.error
import urllib.request
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

sys.path.insert(0, str(pathlib.Path(__file__).resolve().parent))

import seed_model as model  # noqa: E402

LOCK = threading.Lock()
ACTIVE: dict[str, tuple[float, float]] = {}


def switch_scenario(active: dict[str, tuple[float, float]], name: str, body: dict, now: float) -> None:
    if name == "normal":
        # End running scenarios rather than forget them: the counters keep what they counted, so they never step
        # down and rate() never reads the change as a counter reset.
        for key, (start, end) in list(active.items()):
            active[key] = (start, min(end, max(start, now)))
    else:
        start = float(body.get("start", now))
        active[name] = (start, start + float(body.get("minutes", 40)) * 60)


def render(now: float) -> str:
    scenarios = model.Scenarios(dict(ACTIVE))
    lines: list[str] = []

    def emit(name: str, labels: dict[str, str], value: float, kind: str | None = None) -> None:
        if kind:
            lines.append(f"# TYPE {name} {kind}")
        lines.append(f"{name}{{{','.join(f'{key}=\"{val}\"' for key, val in labels.items())}}} {value:.6f}")

    for pop in model.POPS:
        base = {"pop": pop["pop"], "region": pop["region"], "edge": pop["edge"]}
        total = model.total_requests(pop, now, scenarios)
        for status in model.STATUS:
            for cache in model.CACHE:
                emit("cdnf_edge_requests_total", {**base, "status_class": status, "cache_status": cache}, model.requests_total(pop, status, cache, now, scenarios))
        emit("cdnf_edge_bytes_sent_total", base, total * model.BYTES_PER_REQUEST)
        for metric, cdf, share in (("cdnf_edge_request_duration_seconds", model.EDGE_CDF, 1.0), ("cdnf_edge_origin_duration_seconds", model.ORIGIN_CDF, 0.28)):
            count = total * share
            for bound, value in zip(model.BUCKETS, model.histogram(cdf, count)):
                emit(f"{metric}_bucket", {**base, "le": str(bound)}, value)
            emit(f"{metric}_bucket", {**base, "le": "+Inf"}, count)
            emit(f"{metric}_sum", base, count * 0.12)
            emit(f"{metric}_count", base, count)
        origin_failures = total * 0.28 * 0.004
        start, end = scenarios.span("origin-down")
        if end:
            origin_failures += 5.0 * model.window(now, start, end)
        emit("cdnf_edge_origin_failures_total", base, origin_failures)
        for klass in model.SECURITY:
            emit("cdnf_edge_security_events_total", {**base, "class": klass}, model.security_total(pop, klass, now, scenarios))
        for reason in model.TLS_REASONS:
            emit("cdnf_edge_tls_failures_total", {**base, "reason": reason}, model.tls_total(pop, reason, now, scenarios))
        for rcode in model.DNS_RCODE:
            for outcome in ("answered", "response_limit"):
                emit("cdnf_dns_queries_total", {"pop": pop["pop"], "region": pop["region"], "rcode": rcode, "outcome": outcome}, model.dns_queries_total(pop, rcode, outcome, now, scenarios))
        lost = pop["pop"] == "seed-sin" and scenarios.on("pop-loss", now)
        age = now - scenarios.span("pop-loss")[0] if lost else 4.0
        emit("cdnfoundry_edge_heartbeat_age_seconds", {"edge": pop["edge"], "pop": pop["pop"]}, age)
        emit("cdnfoundry_edge_gateway_active_revision", {"edge": pop["edge"], "pop": pop["pop"]}, 99 if lost else 100)
        emit("cdnfoundry_edge_gateway_ready", {"edge": pop["edge"], "pop": pop["pop"]}, 0 if lost else 1)
        emit("cdnfoundry_edge_gateway_connections_active", {"edge": pop["edge"], "pop": pop["pop"]}, 0 if lost else 220 + int(now) % 40)
        for resource, level in (("cache", 0.38), ("memory", 0.41), ("connections", 0.22)):
            emit("cdnfoundry_cell_capacity_ratio", {"edge": pop["edge"], "pop": pop["pop"], "pool": "seed", "cell": "cell-01", "resource": resource}, level + (now % 3600) / 3600 * 0.02)
        for job, probe in (("blackbox-http", "http"), ("blackbox-tcp", "tcp"), ("blackbox-dns-udp", "dns_udp"), ("blackbox-dns-tcp", "dns_tcp")):
            labels = {"job": job, "probe": probe, "pop": pop["pop"], "region": pop["region"], "ip_version": "4", "instance": f"192.0.2.{10 + model.POPS.index(pop)}"}
            emit("probe_success", labels, 0 if lost else 1)
            emit("probe_duration_seconds", labels, 0.5 if lost else 0.03 + 0.01 * model.POPS.index(pop))
            if probe == "http":
                expiry = 5 if scenarios.on("cert-expiry", now) and pop["pop"] == "seed-iad" else 40
                emit("probe_ssl_earliest_cert_expiry", labels, now + expiry * 86400)
    start, end = scenarios.span("telemetry-drop")
    emit("vector_component_discarded_events_total", {"component_id": "clickhouse_edge", "intentional": "false"}, 5.0 * model.window(now, start, end) if end else 0.0)
    if scenarios.on("purge-stuck", now):
        emit("cdnfoundry_purge_pending_oldest_seconds", {}, now - scenarios.span("purge-stuck")[0])
    if scenarios.on("cert-expiry", now):
        emit("cdnfoundry_tls_certificates_expiring", {}, 2)
    return "\n".join(lines) + "\n"


COUNTRIES = [("DE", "EU"), ("US", "NA"), ("SG", "AS"), ("FR", "EU"), ("GB", "EU"), ("BR", "SA"), ("IN", "AS"), ("JP", "AS"), ("NL", "EU"), ("CA", "NA")]
AGENTS = ["Mozilla/5.0 (X11; Linux x86_64)", "curl/8.5.0", "Googlebot/2.1", "Mozilla/5.0 (iPhone; CPU iPhone OS 17)"]
CIPHERS = {"TLSv1.3": "TLS_AES_256_GCM_SHA384", "TLSv1.2": "ECDHE-RSA-AES128-GCM-SHA256"}


def digest(*parts: object) -> int:
    return int.from_bytes(hashlib.blake2b("|".join(map(str, parts)).encode(), digest_size=8).digest(), "big")


def timestamp(moment: float) -> str:
    return time.strftime("%Y-%m-%d %H:%M:%S", time.gmtime(moment)) + f".{int(moment * 1000) % 1000:03d}"


def event_rows(minute: int, pop: dict, scenarios: model.Scenarios, per_minute: int = 20) -> list[dict]:
    """The events one PoP delivers in one minute. The same function describes any minute, so reruns match."""
    rows = []
    moment = minute * 60.0
    flood = pop["pop"] == "seed-fra" and scenarios.on("l7-flood", moment)
    down = scenarios.on("origin-down", moment)
    for index in range(per_minute * (15 if flood else 1)):
        h = lambda salt: digest(minute, pop["pop"], index, salt)  # noqa: E731
        domain = 9002 if flood and index % 10 else model.DOMAINS[h(1) % len(model.DOMAINS)]
        pick = h(2) % 10000
        status = 200 if pick < 9300 else 304 if pick < 9700 else 404 if pick < 9970 else 503
        if down and domain == 9001 and h(3) % 10 < 7:
            status = 502
        cache = "HIT" if h(4) % 100 < 72 else ["MISS", "EXPIRED", "BYPASS", "STALE", "UNKNOWN"][h(5) % 5]
        origin = None if cache == "HIT" else int(20 + (h(6) % 1000) / 1000 * 600)
        if down and domain == 9001:
            origin = 3000 + h(7) % 2000
        security = h(8) % 1000
        klass, action, reason, rule = "", "allow", "", 0
        if flood and index % 4 == 0 or security < 3:
            klass, action, reason, rule = "waf", "block", "waf_rule_matched", 942100 + h(9) % 5
        elif security < 5:
            klass, action, reason = "cache_abuse_detected", "block", "cache_abuse_detected"
        elif security < 8:
            klass, action, reason = "block", "block", "domain_rate_exceeded"
        v6 = h(10) % 100 >= 70
        protocol = "TLSv1.3" if h(11) % 100 < 80 else "TLSv1.2"
        country = COUNTRIES[h(12) % len(COUNTRIES)]
        rows.append({
            "occurred_at": timestamp(moment + (h(13) % 60000) / 1000), "domain_id": domain, "hostname": f"site{domain - 9000}.seed.test",
            "method": "GET" if h(14) % 100 < 90 else "POST", "path": f"/assets/{h(15) % 200}.js", "status": status, "bytes_in": 400 + h(16) % 800,
            "bytes_out": 1500 + (h(17) % 1000) * 80, "bytes_sent": 2000 + (h(17) % 1000) * 90, "cache_status": cache, "origin_latency_ms": origin,
            "upstream_header_ms": None if origin is None else int(origin * 0.6), "upstream_connect_ms": None if origin is None else 3,
            "origin_error": "502" if status >= 500 and origin is not None else "", "origin_role": "primary", "origin_transition": "failover" if down and domain == 9001 and h(18) % 5 == 0 else "none",
            "security_action": action, "security_reason": reason, "security_class": klass, "edge_id": pop["edge"], "pop": pop["pop"], "region": pop["region"],
            "client_ip": f"2001:db8:{h(19) % 8:x}::{1 + h(20) % 1000}" if v6 else f"198.51.{100 + h(19) % 4}.{1 + h(20) % 250}", "ip_version": "6" if v6 else "4",
            "country": country[0], "continent": country[1], "asn": 64500 + h(21) % 12, "user_agent": AGENTS[h(22) % len(AGENTS)], "event_type": "request",
            "request_time_ms": int(5 + ((h(23) % 1000) / 1000) ** 4 * 900) + (900 if flood else 0), "server_protocol": "h2" if h(24) % 100 < 60 else "http/1.1" if h(24) % 100 < 95 else "h3",
            "ssl_protocol": protocol, "ssl_cipher": CIPHERS[protocol], "scheme": "https", "request_id": f"{h(25):016x}{h(26):016x}",
            "waf_profile": "balanced", "waf_rule_id": rule, "waf_score": 5 if rule else 0, "waf_action": "block" if rule else "inspect" if h(27) % 10 < 3 else "off",
            "waf_processing_us": 200 + h(28) % 800,
        })
    if scenarios.on("cert-expiry", moment) and pop["pop"] == "seed-iad":
        for index in range(30):
            rows.append({"occurred_at": timestamp(moment + index), "event_type": "tls", "hostname": "site3.seed.test", "tls_error": "certificate_expired", "ssl_protocol": "TLSv1.3", "edge_id": pop["edge"], "pop": pop["pop"], "region": pop["region"], "security_action": "allow"})
    if h_unknown_sni(minute, pop):
        rows.append({"occurred_at": timestamp(moment + 5), "event_type": "tls", "hostname": "unknown.invalid", "tls_error": "unknown_sni", "ssl_protocol": "TLSv1.3", "edge_id": pop["edge"], "pop": pop["pop"], "region": pop["region"], "security_action": "allow"})
    return rows


def h_unknown_sni(minute: int, pop: dict) -> bool:
    return digest(minute, pop["pop"], "sni") % 3 == 0


def dns_rows(minute: int, pop: dict, scenarios: model.Scenarios) -> list[dict]:
    moment = minute * 60.0
    flood = scenarios.on("dns-flood", moment)
    rows = []
    for index in range(10 * (40 if flood else 1)):
        h = lambda salt: digest("dns", minute, pop["pop"], index, salt)  # noqa: E731
        nxdomain = (flood and h(1) % 100 < 85) or h(1) % 100 < 12
        qname = f"{h(2):x}.seed.test" if nxdomain else f"www.site{1 + h(3) % 8}.seed.test"
        limited = h(4) % 2000 == 0 or (flood and h(4) % 100 == 0)
        rows.append({"occurred_at": timestamp(moment + (h(5) % 60000) / 1000), "domain_id": 0, "zone": qname.split(".", 1)[1] if nxdomain else qname[4:], "qname": qname, "qtype": ["A", "AAAA", "TXT", "ANY"][h(6) % 4] if h(6) % 10 else "A",
                     "rcode": "NXDOMAIN" if nxdomain else "NOERROR", "client_ip": f"198.51.{100 + h(7) % 4}.{1 + h(8) % 250}", "dns_cluster": "seed-dnsdist", "country": "ZZ", "continent": "ZZ",
                     "outcome": "response_limit" if limited else "answered", "asn": 64500 + h(9) % 12, "pop": pop["pop"], "region": pop["region"], "security_class": "dns_response_limit" if limited else ""})
    return rows


def insert(table: str, rows: list[dict]) -> None:
    if not rows:
        return
    endpoint = os.environ.get("CLICKHOUSE_URL", "http://clickhouse:8123")
    body = "\n".join(json.dumps(row) for row in rows).encode()
    query = f"INSERT INTO cdnf.{table} FORMAT JSONEachRow"
    request = urllib.request.Request(f"{endpoint}/?input_format_skip_unknown_fields=1&query={urllib.request.quote(query)}", data=body, method="POST", headers={
        "X-ClickHouse-User": os.environ.get("CLICKHOUSE_USER", "cdnf_ingest"), "X-ClickHouse-Key": os.environ.get("CLICKHOUSE_PASSWORD", "cdnf-ingest-dev-only")})
    urllib.request.urlopen(request, timeout=20).read()


def loki_lines(minute: int, scenarios: model.Scenarios) -> None:
    endpoint = os.environ.get("LOKI_URL", "http://loki:3100")
    streams = []
    for service, role in (("core", "control"), ("edge-agent", "edge"), ("edge-gateway", "edge"), ("dnsdist", "dns"), ("pdns-auth", "dns"), ("loki", "telemetry"), ("vector", "telemetry")):
        for level, count in (("info", 3), ("warning", 1 if digest(minute, service) % 3 == 0 else 0), ("error", 1 if digest(minute, service, "e") % 7 == 0 or scenarios.on("origin-down", minute * 60.0) and service == "edge-gateway" else 0)):
            values = []
            for index in range(count):
                request = f"{digest(minute, service, level, index):016x}{digest(minute, service, level, index, 1):016x}"
                if service == "core" and level == "info" and index == 0 and minute % 30 == 0:
                    request = "cdnf-seed-request-0001"  # the request the trace dashboard opens on, so its log panel has lines
                host = model.POPS[digest(minute, service, index) % 3]["pop"]
                values.append([str(int((minute * 60 + index + 1) * 1e9)), json.dumps({"message": f"{service} {level} sample", "request_id": request, "domain_id": 9001 + index}), {"request_id": request, "domain_id": str(9001 + index)}])
            if values:
                streams.append({"stream": {"environment": "development", "host": host, "role": role, "service": service, "level": level, "stream": "stdout", "collector_id": host}, "values": values})
    if streams:
        request = urllib.request.Request(f"{endpoint}/loki/api/v1/push", data=json.dumps({"streams": streams}).encode(), method="POST", headers={"Content-Type": "application/json"})
        urllib.request.urlopen(request, timeout=20).read()


def feeder() -> None:
    """Deliver each finished minute of events, DNS, and logs once, as the collectors of real PoPs would."""
    delivered = int(time.time() // 60) - 1
    while True:
        time.sleep(10)
        current = int(time.time() // 60)
        for minute in range(max(delivered + 1, current - 60), current):
            with LOCK:
                scenarios = model.Scenarios(dict(ACTIVE))
            try:
                drop = scenarios.on("telemetry-drop", minute * 60.0)
                for pop in model.POPS:
                    lost = pop["pop"] == "seed-sin" and scenarios.on("pop-loss", minute * 60.0)
                    if lost or drop and pop["pop"] == "seed-iad":
                        continue
                    insert("edge_events", event_rows(minute, pop, scenarios))
                    insert("dns_events", dns_rows(minute, pop, scenarios))
                loki_lines(minute, scenarios)
                delivered = minute
            except (urllib.error.URLError, OSError) as error:
                detail = error.read().decode()[:300] if isinstance(error, urllib.error.HTTPError) else ""
                print(f"seed feeder: {error} {detail}", file=sys.stderr, flush=True)
                break


class Handler(BaseHTTPRequestHandler):
    def log_message(self, *_args) -> None:
        pass

    def _send(self, status: int, body: str, kind: str = "text/plain; version=0.0.4") -> None:
        payload = body.encode()
        self.send_response(status)
        self.send_header("Content-Type", kind)
        self.send_header("Content-Length", str(len(payload)))
        self.end_headers()
        self.wfile.write(payload)

    def do_GET(self) -> None:  # noqa: N802
        with LOCK:
            if self.path == "/metrics":
                return self._send(200, render(time.time()))
            if self.path == "/scenarios":
                return self._send(200, json.dumps(ACTIVE), "application/json")
        self._send(404, "not found\n")

    def do_POST(self) -> None:  # noqa: N802
        length = int(self.headers.get("Content-Length") or 0)
        body = json.loads(self.rfile.read(length) or b"{}")
        name = body.get("scenario")
        if name not in model.SCENARIOS:
            return self._send(400, json.dumps({"error": "unknown scenario"}), "application/json")
        with LOCK:
            switch_scenario(ACTIVE, name, body, time.time())
        self._send(200, json.dumps(ACTIVE), "application/json")


if __name__ == "__main__":
    threading.Thread(target=feeder, daemon=True).start()
    ThreadingHTTPServer(("0.0.0.0", 9108), Handler).serve_forever()
