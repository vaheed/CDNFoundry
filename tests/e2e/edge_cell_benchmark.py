#!/usr/bin/env python3
"""Reproducible capacity benchmark of one OpenResty cell, with cgroup CPU accounting.

The benchmark runs on its own Docker network and never touches the development stack: one cell from the
edge-runtime image, a static nginx origin, a UDP sink named ``vector`` that receives the syslog access log
the way the real Vector would, and a wrk load generator in its own container. It reports requests per
second, latency percentiles, errors, and the CPU the cell really consumed (from its cgroup, so throttling
and per-container usage are exact rather than sampled).

What it changes from production, and why:

- Nginx's per-client ``limit_req`` (100 r/s) and ``limit_conn`` (64) are raised in a copy of the cell's
  server configuration, and the snapshot raises the per-domain limits. One load generator is one client
  address; without this the benchmark measures the rate limiter instead of the cell. The limiters still
  run on every request, so their CPU cost is included.

Usage (each run prints one JSON line; use --repeat for repeatability):

    python3 tests/e2e/edge_cell_benchmark.py --image cdnfoundry/edge-runtime:bench --waf off --cpus 1
    python3 tests/e2e/edge_cell_benchmark.py --image cdnfoundry/edge-runtime:bench --waf balanced --cpus 1 --connections 32
    python3 tests/e2e/edge_cell_benchmark.py --image cdnfoundry/edge-runtime:bench --waf off --cpus 1 --request-rules 50
"""

from __future__ import annotations

import argparse
import hashlib
import json
import os
import pathlib
import platform
import re
import subprocess
import tempfile
import time

ROOT = pathlib.Path(__file__).resolve().parents[2]
NETWORK = "cdnf-bench"
CELL = "cdnf-bench-cell"
ORIGIN = "cdnf-bench-origin"
SINK = "vector"  # the cell's syslog target name
SINK_CONTAINER = "cdnf-bench-vector"
LOADGEN_IMAGE = "cdnf-bench-load:local"
LOADGEN_DOCKERFILE = "FROM alpine:3.22\nRUN apk add --no-cache wrk curl\n"
ORIGIN_IMAGE = "nginx:1.31.9-alpine@sha256:fb4658584bd9a916ffb6f6e365f97c25cfdb2923801f551ea08356e695f14ff7"
ORIGIN_CONF = """server {
    listen 80;
    location = /f10k.bin  { root /srv; }
    location = /f1m.bin   { root /srv; }
    location = /page.html { root /srv; default_type text/html; }
    location / { default_type application/json; return 200 '{"origin":"bench","host":"$host"}'; }
}
"""
WAF_PROFILES = {
    "off": {"name": "off", "paranoia_level": 0, "inbound_threshold": 0, "outbound_threshold": 0, "body_limit_bytes": 0, "blocking": False,
            "off_categories": [], "off_rules": [], "log_only_rules": [], "exclusions": []},
    "balanced": {"name": "balanced", "paranoia_level": 1, "inbound_threshold": 5, "outbound_threshold": 4, "body_limit_bytes": 1048576,
                 "blocking": True, "off_categories": [], "off_rules": [], "log_only_rules": [], "exclusions": []},
    "monitor": {"name": "monitor", "paranoia_level": 1, "inbound_threshold": 5, "outbound_threshold": 4, "body_limit_bytes": 1048576,
                "blocking": False, "off_categories": [], "off_rules": [], "log_only_rules": [], "exclusions": []},
    "strict": {"name": "strict", "paranoia_level": 2, "inbound_threshold": 3, "outbound_threshold": 3, "body_limit_bytes": 262144,
               "blocking": True, "off_categories": [], "off_rules": [], "log_only_rules": [], "exclusions": []},
}
WRK_SCRIPT = """
local hosts = {}
for name in string.gmatch(os.getenv("BENCH_HOSTS"), "[^,]+") do hosts[#hosts + 1] = name end
local path = os.getenv("BENCH_PATH")
local method = os.getenv("BENCH_METHOD") or "GET"
local json_body = os.getenv("BENCH_JSON_BODY") == "1"
local login_body = os.getenv("BENCH_LOGIN_BODY") == "1"
local body = os.getenv("BENCH_BODY_BYTES") and string.rep("a", tonumber(os.getenv("BENCH_BODY_BYTES"))) or nil
local counter = 0
request = function()
    counter = counter + 1
    local headers = {Host = hosts[(counter % #hosts) + 1], ["User-Agent"] = "Mozilla/5.0 (X11; Linux x86_64) bench"}
    if body then headers["Content-Type"] = "application/x-www-form-urlencoded" end
    if body and json_body then
        headers["Content-Type"] = "application/json"
        return wrk.format(method, path, headers, '{"messages":"' .. body .. '"}')
    end
    if body and login_body then return wrk.format(method, path, headers, "user=alice&field=" .. body) end
    return wrk.format(method, path, headers, body and ("field=" .. body) or nil)
end
done = function(summary, latency, requests)
    local out = {requests = summary.requests, duration_us = summary.duration, errors_connect = summary.errors.connect,
        errors_read = summary.errors.read, errors_write = summary.errors.write, errors_timeout = summary.errors.timeout,
        errors_status = summary.errors.status, p50_ms = latency:percentile(50) / 1000, p95_ms = latency:percentile(95) / 1000,
        p99_ms = latency:percentile(99) / 1000, max_ms = latency.max / 1000}
    local parts = {}
    for key, value in pairs(out) do parts[#parts + 1] = string.format('"%s":%s', key, tostring(value)) end
    io.write("BENCH_RESULT {" .. table.concat(parts, ",") .. "}\\n")
end
"""


def run(*args: str, timeout: int = 300, check: bool = True, env: dict | None = None) -> subprocess.CompletedProcess[str]:
    result = subprocess.run(args, cwd=ROOT, text=True, capture_output=True, timeout=timeout, check=False, env=env)
    if check and result.returncode != 0:
        raise RuntimeError(f"command failed: {' '.join(args)}\n{result.stdout}\n{result.stderr}")
    return result


def cgroup_path(container: str) -> pathlib.Path:
    full_id = run("docker", "inspect", "--format={{.Id}}", container).stdout.strip()
    for candidate in (f"/sys/fs/cgroup/system.slice/docker-{full_id}.scope", f"/sys/fs/cgroup/docker/{full_id}"):
        if pathlib.Path(candidate).exists():
            return pathlib.Path(candidate)
    raise RuntimeError(f"cgroup of {container} not found")


def cpu_stat(cgroup: pathlib.Path) -> dict[str, int]:
    values = {}
    for line in (cgroup / "cpu.stat").read_text().splitlines():
        key, value = line.split()
        values[key] = int(value)
    return values


def process_cpu_seconds(name: str) -> float:
    """CPU seconds of every host process with this command name (dockerd, containerd-shim...)."""
    total = 0
    ticks = os.sysconf("SC_CLK_TCK")
    for stat in pathlib.Path("/proc").glob("[0-9]*/stat"):
        try:
            text = stat.read_text()
        except OSError:
            continue
        comm = text[text.index("(") + 1:text.rindex(")")]
        if comm == name:
            fields = text[text.rindex(")") + 2:].split()
            total += int(fields[11]) + int(fields[12])
    return total / ticks


def cleanup() -> None:
    for name in (CELL, ORIGIN, SINK_CONTAINER, "cdnf-bench-load"):
        run("docker", "rm", "-f", name, check=False)
    run("docker", "network", "rm", NETWORK, check=False)


def never_matching_rules(count: int) -> dict:
    """``count`` request rules of each type whose conditions never match the benchmark request, cycling through the
    condition kinds a domain really uses: exact and prefix paths, a header, the user agent, the method, and a country."""
    kinds = [
        lambda i: [{"field": "path", "operator": "equals", "value": f"/never-{i}"}],
        lambda i: [{"field": "path", "operator": "starts_with", "value": f"/never-{i}/"}],
        lambda i: [{"field": "header", "operator": "equals", "name": "x-bench-never", "value": str(i)}],
        lambda i: [{"field": "user_agent", "operator": "contains", "value": f"never-agent-{i}"}],
        lambda i: [{"field": "method", "operator": "equals", "value": "DELETE"}, {"field": "path", "operator": "equals", "value": f"/never-{i}"}],
        lambda i: [{"field": "path", "operator": "ends_with", "value": f".never{i}"}, {"field": "country", "operator": "equals", "value": "AQ"}],
    ]

    def rules(action) -> list[dict]:
        return [{"id": index + 1, "conditions": kinds[index % len(kinds)](index), "action": action(index)} for index in range(count)]

    return {
        "redirects": rules(lambda i: {"target": "url", "url": f"/moved-{i}", "status_code": 301, "preserve_query": False}),
        "rewrites": rules(lambda i: {"path": "set", "path_value": f"/rewritten-{i}", "query": "keep"}),
        "headers": rules(lambda i: {"request": [{"op": "set", "name": "x-bench-rule", "value": str(i)}], "response": [{"op": "remove", "name": "x-bench-never"}]}),
        "caches": rules(lambda i: {"mode": "bypass"} if i % 2 else {"mode": "override", "edge_ttl_seconds": 60}),
    }


DETECTIONS = {
    "credential_check": {"login_paths": [{"path": "/login", "format": "form", "username_field": "user", "password_field": "field"}], "body_bytes": 8192},
    "sensitive_data": {"kinds": ["card", "iban", "private_key", "api_key"], "response_bytes": 16384},
    "ai_protection": {"endpoints": ["/v1/chat"], "prompt_field": "messages", "body_bytes": 16384},
}
DETECTION_PATHS = {"credential_check": ("/login", "POST"), "sensitive_data": ("/page.html", "GET"), "ai_protection": ("/v1/chat", "POST")}


def security_rule(identifier: int, conditions: list[dict], rate_limit: dict | None = None) -> dict:
    rule = {"id": identifier, "name": None, "priority": identifier, "action": "block", "conditions": conditions}
    if rate_limit:
        rule["rate_limit"] = rate_limit
    return rule


def extra_security(args: argparse.Namespace) -> dict:
    """Custom firewall rules that never match, and rate-limit rules that count every request but never trip, as the matrix asks."""
    rules = [security_rule(index + 1, [{"field": "path", "operator": "equals", "value": f"/never-{index}"}]) for index in range(args.security_rules)]
    for index in range(args.rate_limit_rules):
        rules.append(security_rule(args.security_rules + index + 1, [{"field": "path", "operator": "starts_with", "value": "/"}],
                                   {"key": "client_ip", "threshold": 1000000000, "period_seconds": 60, "duration_seconds": 10}))
    extra = {"rules": rules}
    if args.detection:
        extra[args.detection] = DETECTIONS[args.detection]
    return extra


def waf_profile(args: argparse.Namespace) -> dict:
    profile = dict(WAF_PROFILES[args.waf])
    if args.log_only_rules:
        catalog = json.loads((ROOT / "core/resources/contracts/crs-rules.json").read_text())["rules"]
        identifiers = [int(entry["id"]) for entry in catalog][:args.log_only_rules]
        profile["log_only_rules"] = identifiers
    return profile


def runtime_document(hostnames: list[str], waf: str, certificate: str, private_key: str, cache_enabled: bool, request_rules: int = 0,
                     args: argparse.Namespace | None = None) -> dict:
    limits = {
        "requests_per_second": 1000000, "request_burst": 1000000, "connections_per_client": 100000,
        "connections_per_domain": 100000, "cache_admissions_per_second": 1000000,
    }
    return {
        "schema_version": 1, "sequence": 1, "generation_id": "bench-generation-1",
        "certificates": {"bench": {"id": "bench", "certificate_pem": certificate, "chain_pem": "", "private_key_pem": private_key,
                                   "expires_at": int(time.time()) + 86400, "names": hostnames}},
        "hosts": {
            hostname: {
                "domain": hostname, "domain_id": index + 1, "revision": 1,
                "settings": {"enabled": True, "redirect_https": False, "http_versions": ["1.1", "2"]},
                "cache": {"enabled": cache_enabled, "edge_ttl_seconds": 3600, "browser_ttl_seconds": 300,
                          "maximum_object_bytes": 10485760, "respect_origin_headers": False, "include_query_string": True,
                          "ignore_cookie_names": [], "stale_if_error_seconds": 60, "epoch": 1, "development_mode_until": None},
                "origin": {"host": ORIGIN, "port": 80, "scheme": "http", "host_header": hostname, "sni": None, "verify_tls": False,
                           "connect_timeout_ms": 1000, "response_timeout_ms": 5000, "retry_count": 0, "websocket": False,
                           "health_check": None, "private_allowlist": ["172.16.0.0/12", "192.168.0.0/16", "10.0.0.0/8"],
                           "blocked_networks": [], "blocked_addresses": []},
                "security": {"limits": limits, "rules": [], **(extra_security(args) if args else {})},
                "waf": waf_profile(args) if args else WAF_PROFILES[waf],
                "tls": {"mode": "custom", "certificate_id": "bench"},
                **({"rules": never_matching_rules(request_rules)} if request_rules else {}),
            } for index, hostname in enumerate(hostnames)
        },
    }


def bench_server_config(source: pathlib.Path) -> str:
    text = source.read_text()
    text, rate = re.subn(r"zone=client_requests:10m rate=100r/s;", "zone=client_requests:10m rate=1000000r/s;", text)
    text, conn = re.subn(r"limit_conn client_connections 64;", "limit_conn client_connections 60000;", text)
    text, host = re.subn(r"limit_conn hostname_connections 512;", "limit_conn hostname_connections 60000;", text)
    if not (rate and conn and host):
        raise RuntimeError("edge-runtime.conf no longer has the per-client limits this benchmark lifts")
    return text


def start(args: argparse.Namespace, directory: pathlib.Path, hostnames: list[str]) -> None:
    run("docker", "network", "create", "--subnet", "192.168.250.0/24", NETWORK)
    srv = directory / "srv"
    srv.mkdir()
    (srv / "f10k.bin").write_bytes(os.urandom(10240))
    (srv / "f1m.bin").write_bytes(os.urandom(1048576))
    (srv / "page.html").write_text(("<p>CDNFoundry benchmark page with ordinary markup.</p>\n" * 200))
    for item in srv.iterdir():
        item.chmod(0o644)
    srv.chmod(0o755)
    (directory / "origin.conf").write_text(ORIGIN_CONF)
    run("docker", "run", "-d", "--name", ORIGIN, "--network", NETWORK, "--cpus", "4",
        "-v", f"{srv}:/srv:ro", "-v", f"{directory / 'origin.conf'}:/etc/nginx/conf.d/default.conf:ro", ORIGIN_IMAGE)
    run("docker", "run", "-d", "--name", SINK_CONTAINER, "--network-alias", SINK, "--network", NETWORK, "--cpus", "1",
        "alpine:3.22", "sh", "-c", "exec nc -lku -p 9000 > /dev/null")
    run("openssl", "req", "-x509", "-newkey", "rsa:2048", "-nodes", "-days", "1", "-subj", f"/CN={hostnames[0]}",
        "-addext", "subjectAltName=" + ",".join(f"DNS:{host}" for host in hostnames),
        "-keyout", str(directory / "edge.key"), "-out", str(directory / "edge.crt"))
    runtime = runtime_document(hostnames, args.waf, (directory / "edge.crt").read_text(), (directory / "edge.key").read_text(),
                               not args.no_cache, args.request_rules, args)
    runtime_file = directory / "active.json"
    runtime_file.write_text(json.dumps(runtime, separators=(",", ":")))
    stamp_file = directory / "active.stamp"
    stamp_file.write_text(hashlib.sha256(runtime_file.read_bytes()).hexdigest())
    os.chown(stamp_file, 10101, 10101)
    stamp_file.chmod(0o600)
    os.chown(runtime_file, 10101, 10101)
    runtime_file.chmod(0o600)
    pki = directory / "pki"
    pki.mkdir()
    for name in ("tls.crt", "tls.key"):
        target = pki / name
        target.write_text((directory / ("edge.crt" if name == "tls.crt" else "edge.key")).read_text())
        os.chown(target, 10101, 10101)
        target.chmod(0o600)
    server_conf = directory / "default.conf"
    server_conf.write_text(bench_server_config(ROOT / "docker/nginx/edge-runtime.conf"))
    server_conf.chmod(0o644)
    directory.chmod(0o755)
    command = [
        "docker", "run", "-d", "--name", CELL, "--network", NETWORK,
        "--cpus", str(args.cpus), "--memory", args.memory, "--pids-limit", "256", "--ulimit", "nofile=65536:65536",
        "--log-driver", "json-file", "--log-opt", "max-size=10m", "--log-opt", "max-file=3",
        "-e", "EDGE_CELL_NAME=bench", "-e", "EDGE_RUNTIME_FILE=/runtime/active.json",
        "-e", "EDGE_STATUS_TOKEN=bench-only", "-e", "EDGE_PROXY_PROTOCOL_TRUSTED_CIDRS=192.168.250.0/24",
        "-v", f"{runtime_file}:/runtime/active.json:ro", "-v", f"{stamp_file}:/runtime/active.stamp:ro", "-v", f"{pki}:/run/edge:ro",
        "-v", f"{server_conf}:/etc/nginx/conf.d/default.conf:ro",
        "--tmpfs", "/var/cache/nginx:rw,noexec,nosuid,size=512m",
        "--tmpfs", "/var/lib/nginx/tmp:rw,noexec,nosuid,size=64m",
    ]
    if args.mmdb_volume:
        command += ["-v", f"{args.mmdb_volume}:/mmdb:ro", "-e", "GEOIP_DATABASE=/mmdb/GeoLite2-City.mmdb"]
    for item in args.cell_env:
        command += ["-e", item]
    if args.nginx_conf:
        command += ["-v", f"{pathlib.Path(args.nginx_conf).resolve()}:/usr/local/openresty/nginx/conf/nginx.conf:ro"]
    run(*command, args.image)
    run("docker", "run", "-d", "--name", "cdnf-bench-load", "--network", NETWORK, "--cpus", str(args.loadgen_cpus),
        "--entrypoint", "sleep", LOADGEN_IMAGE, "infinity")
    subprocess.run(["docker", "exec", "-i", "cdnf-bench-load", "sh", "-c", "cat > /bench.lua"], input=WRK_SCRIPT, text=True, check=True)
    deadline = time.monotonic() + 60
    while time.monotonic() < deadline:
        probe = run("docker", "exec", "cdnf-bench-load", "curl", "-s", "-o", "/dev/null", "-w", "%{http_code}",
                    "-H", f"Host: {hostnames[0]}", f"http://{CELL}:8080{args.path}", check=False)
        if probe.stdout.strip() == "200":
            break
        time.sleep(0.5)
    else:
        raise RuntimeError("cell did not become ready:\n" + run("docker", "logs", CELL, check=False).stderr[-4000:])
    if not args.no_cache:
        for hostname in hostnames:
            for _ in range(3):
                run("docker", "exec", "cdnf-bench-load", "curl", "-s", "-o", "/dev/null", "-H", f"Host: {hostname}",
                    f"http://{CELL}:8080{args.path}")
        head = run("docker", "exec", "cdnf-bench-load", "curl", "-s", "-D", "-", "-o", "/dev/null", "-H", f"Host: {hostnames[-1]}",
                   f"http://{CELL}:8080{args.path}").stdout.lower()
        if "x-cdnf-cache: hit" not in head:
            raise RuntimeError(f"benchmark path is not a cache HIT: {head}")


def measure(args: argparse.Namespace, hostnames: list[str], label: str) -> dict:
    cgroup = cgroup_path(CELL)
    url = f"{'https' if args.scheme == 'https' else 'http'}://{hostnames[0] if args.scheme == 'https' else CELL}:{8443 if args.scheme == 'https' else 8080}{args.path}"
    env_args = ["-e", f"BENCH_HOSTS={','.join(hostnames)}", "-e", f"BENCH_PATH={args.path}", "-e", f"BENCH_METHOD={args.method}"]
    if args.body_bytes:
        env_args += ["-e", f"BENCH_BODY_BYTES={args.body_bytes}"]
    if args.detection == "ai_protection":
        env_args += ["-e", "BENCH_JSON_BODY=1"]
    if args.detection == "credential_check":
        env_args += ["-e", "BENCH_LOGIN_BODY=1"]
    exec_prefix = ["docker", "exec"] + env_args
    if args.scheme == "https":
        cell_address = run("docker", "inspect", "--format", "{{range .NetworkSettings.Networks}}{{.IPAddress}}{{end}}", CELL).stdout.strip()
        run("docker", "exec", "-u", "0", "cdnf-bench-load", "sh", "-c", f"grep -q '{hostnames[0]}' /etc/hosts || echo '{cell_address} {hostnames[0]}' >> /etc/hosts")
    before = cpu_stat(cgroup)
    dockerd_before = process_cpu_seconds("dockerd")
    shim_before = process_cpu_seconds("containerd-shim")
    started = time.monotonic()
    result = run(*exec_prefix, "cdnf-bench-load", "wrk", "-t", str(args.threads), "-c", str(args.connections), "-d", f"{args.duration}s",
                 "--timeout", "5s", "-s", "/bench.lua", url, timeout=args.duration + 60)
    elapsed = time.monotonic() - started
    after = cpu_stat(cgroup)
    dockerd_after = process_cpu_seconds("dockerd")
    shim_after = process_cpu_seconds("containerd-shim")
    line = next((item for item in result.stdout.splitlines() if item.startswith("BENCH_RESULT ")), None)
    if not line:
        raise RuntimeError(f"wrk produced no result:\n{result.stdout}\n{result.stderr}")
    data = json.loads(line.split(" ", 1)[1])
    seconds = data["duration_us"] / 1e6
    cpu_seconds = (after["usage_usec"] - before["usage_usec"]) / 1e6
    rps = data["requests"] / seconds
    errors = sum(data[key] for key in ("errors_connect", "errors_read", "errors_write", "errors_timeout", "errors_status"))
    return {
        "label": label, "waf": args.waf, "request_rules_per_type": args.request_rules, "scheme": args.scheme, "path": args.path, "method": args.method,
        "body_bytes": args.body_bytes, "cache": not args.no_cache, "log_only_rules": args.log_only_rules,
        "security_rules": args.security_rules, "rate_limit_rules": args.rate_limit_rules, "detection": args.detection, "cell_cpus": args.cpus, "connections": args.connections,
        "hostnames": len(hostnames), "duration_s": round(seconds, 2),
        "requests": data["requests"], "rps": round(rps, 1), "errors": errors, "status_errors": data["errors_status"],
        "p50_ms": round(data["p50_ms"], 2), "p95_ms": round(data["p95_ms"], 2), "p99_ms": round(data["p99_ms"], 2),
        "max_ms": round(data["max_ms"], 2),
        "cell_cpu_cores": round(cpu_seconds / elapsed, 3),
        "cell_cpu_us_per_request": round(cpu_seconds * 1e6 / max(1, data["requests"]), 1),
        "cell_throttled_periods": after["nr_throttled"] - before["nr_throttled"],
        "cell_throttled_ms": round((after["throttled_usec"] - before["throttled_usec"]) / 1000, 1),
        "cell_memory_mib": round(int((cgroup / "memory.current").read_text()) / 1048576, 1),
        "cell_memory_peak_mib": round(int((cgroup / "memory.peak").read_text()) / 1048576, 1)
        if (cgroup / "memory.peak").exists() else None,
        "cell_anon_mib": round(next(int(line.split()[1]) for line in (cgroup / "memory.stat").read_text().splitlines()
                                    if line.startswith("anon ")) / 1048576, 1),
        "dockerd_cpu_cores": round((dockerd_after - dockerd_before) / elapsed, 3),
        "containerd_shim_cpu_cores": round((shim_after - shim_before) / elapsed, 3),
    }


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("--image", required=True)
    parser.add_argument("--label", default="")
    parser.add_argument("--waf", choices=sorted(WAF_PROFILES), default="off")
    parser.add_argument("--cpus", type=float, default=1.0)
    parser.add_argument("--memory", default="1g")
    parser.add_argument("--connections", type=int, default=64)
    parser.add_argument("--threads", type=int, default=4)
    parser.add_argument("--loadgen-cpus", type=float, default=6)
    parser.add_argument("--duration", type=int, default=30)
    parser.add_argument("--hostnames", type=int, default=20)
    parser.add_argument("--path", default="/f10k.bin")
    parser.add_argument("--method", default="GET")
    parser.add_argument("--body-bytes", type=int, default=0)
    parser.add_argument("--scheme", choices=["http", "https"], default="http")
    parser.add_argument("--no-cache", action="store_true", help="disable the cache so every request goes to the origin")
    parser.add_argument("--repeat", type=int, default=1)
    parser.add_argument("--warmup", type=int, default=5)
    parser.add_argument("--mmdb-volume", default="cdnfoundry-dev_mmdb", help="Docker volume with GeoLite2-City.mmdb, or empty")
    parser.add_argument("--cell-env", action="append", default=[], help="extra NAME=value for the cell")
    parser.add_argument("--nginx-conf", default="", help="mount this file as the cell's nginx.conf (for A/B experiments)")
    parser.add_argument("--keep", action="store_true", help="leave the containers running after the benchmark")
    parser.add_argument("--log-only-rules", type=int, default=0, help="put this many CRS rules in log-only mode")
    parser.add_argument("--security-rules", type=int, default=0, help="add this many never-matching custom firewall rules to every host")
    parser.add_argument("--rate-limit-rules", type=int, default=0, help="add this many rate-limit rules that count every request and never trip")
    parser.add_argument("--detection", choices=sorted(DETECTIONS), default="", help="turn one detection on for every host")
    parser.add_argument("--request-rules", type=int, default=0, help="add this many never-matching request rules of each type to every host")
    args = parser.parse_args()
    hostnames = [f"bench-{index}.bench.test" for index in range(args.hostnames)]
    cleanup()
    image_id = run("docker", "image", "inspect", "--format={{.Id}}", args.image).stdout.strip()
    if run("docker", "image", "inspect", LOADGEN_IMAGE, check=False).returncode != 0:
        subprocess.run(["docker", "build", "-t", LOADGEN_IMAGE, "-"], input=LOADGEN_DOCKERFILE, text=True, check=True, capture_output=True)
    try:
        with tempfile.TemporaryDirectory(prefix="cdnf-bench-") as directory:
            start(args, pathlib.Path(directory), hostnames)
            if args.warmup:
                warm = argparse.Namespace(**{**vars(args), "duration": args.warmup})
                measure(warm, hostnames, "warmup")
            cpu_model = next((line.split(":", 1)[1].strip() for line in pathlib.Path("/proc/cpuinfo").read_text().splitlines()
                              if line.startswith("model name")), platform.processor())
            environment = {
                "kernel": platform.release(), "cpu_model": cpu_model, "host_logical_cpus": os.cpu_count(),
                "docker": run("docker", "version", "--format", "{{.Server.Version}}").stdout.strip(),
                "edge_image_id": image_id,
                "workers": run("docker", "exec", CELL, "cat", "/var/lib/nginx/tmp/worker-processes.conf").stdout.strip(),
            }
            for repetition in range(args.repeat):
                result = measure(args, hostnames, args.label or f"run-{repetition + 1}")
                result["environment"] = environment
                print(json.dumps(result, sort_keys=True), flush=True)
            if args.keep:
                return
    finally:
        if not args.keep:
            cleanup()


if __name__ == "__main__":
    main()
