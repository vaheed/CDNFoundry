#!/usr/bin/env python3
"""Managed WAF verification baseline: detection, false positives and the chunked-body result, on a real cell.

The harness replays three versioned corpora (``waf_corpus.py``) against one real OpenResty cell from the edge-runtime image, at
every protection level and paranoia level, over the network. It needs no hook inside the runtime: the cell's own access log says
what the WAF decided and a stub origin says what reached the origin.

- the OWASP CRS regression tests of the pinned commit, with each expected rule's paranoia level, so in-scope detection is counted;
- CDNFoundry evasion cases (chunked bodies, HTTP/2 without a content length, encodings, header limits, oversized bodies);
- a clean corpus of 10,300 synthetic requests in seven classes.

Every request carries its own client address (IPv4 and IPv6 alternate) in X-Forwarded-For, which the throwaway cell trusts, so the
log joins one request to one row and no per-client protection contains the harness. Nginx's per-client limits are lifted in a copy
of the cell's server configuration, as the capacity benchmark does. Nothing here is production traffic and nothing is customer data.

The result is one machine-readable record. ``compare`` reads two records and exits 1 when detection fell or false positives rose,
which is how later phases check themselves against this baseline.

    python3 tests/e2e/waf_baseline.py run --image cdnfoundry/edge-runtime:waf-qualification --output record.json
    python3 tests/e2e/waf_baseline.py compare baseline.json record.json
"""

from __future__ import annotations

import argparse
import collections
import concurrent.futures
import datetime
import hashlib
import json
import math
import os
import pathlib
import platform
import socket
import subprocess
import sys
import tempfile
import threading
import time

sys.path.insert(0, str(pathlib.Path(__file__).resolve().parent))
import waf_corpus as corpus  # noqa: E402

ROOT = corpus.ROOT
NETWORK = "cdnf-waf-baseline"
SUBNET = "192.168.251.0/24"
CELL_PORT = 8080
CELL = "cdnf-waf-baseline-cell"
ORIGIN = "cdnf-waf-baseline-origin"
DEFAULT_IMAGE = "cdnfoundry/edge-runtime:waf-qualification"
RECORD_VERSION = 1
PROFILES = {
    "off": {"paranoia_level": 0, "inbound_threshold": 0, "outbound_threshold": 0, "body_limit_bytes": 0, "blocking": False},
    "monitor": {"paranoia_level": 1, "inbound_threshold": 5, "outbound_threshold": 4, "body_limit_bytes": 1048576, "blocking": False},
    "balanced": {"paranoia_level": 1, "inbound_threshold": 5, "outbound_threshold": 4, "body_limit_bytes": 1048576, "blocking": True},
    "strict": {"paranoia_level": 2, "inbound_threshold": 3, "outbound_threshold": 3, "body_limit_bytes": 262144, "blocking": True},
}
PARANOIA_LEVELS = (1, 2, 3, 4)
EXPECTATION_HOSTS = {"balanced": "balanced-pl1", "strict": "strict-pl2"}  # the evasion corpus states what these two must do
ORIGIN_SOURCE = '''
import http.server, json

class Handler(http.server.BaseHTTPRequestHandler):
    protocol_version = "HTTP/1.1"
    def handle_any(self):
        if "chunked" in (self.headers.get("Transfer-Encoding") or "").lower():
            while True:
                size = int((self.rfile.readline().strip() or b"0").split(b";")[0], 16)
                if size == 0:
                    while self.rfile.readline().strip():
                        pass
                    break
                self.rfile.read(size)
                self.rfile.readline()
        else:
            self.rfile.read(int(self.headers.get("Content-Length") or 0))
        print(json.dumps({"c": self.headers.get("X-Forwarded-For", ""), "m": self.command}), flush=True)
        reply = b"origin-ok"
        self.send_response(200)
        self.send_header("Content-Length", str(len(reply)))
        self.end_headers()
        if self.command != "HEAD":
            self.wfile.write(reply)
    do_GET = do_POST = do_PUT = do_PATCH = do_DELETE = do_HEAD = do_OPTIONS = handle_any
    def log_message(self, *args):
        pass

http.server.ThreadingHTTPServer(("0.0.0.0", 80), Handler).serve_forever()
'''


def run(*args: str, check: bool = True, timeout: int = 600) -> subprocess.CompletedProcess[str]:
    result = subprocess.run(args, cwd=ROOT, text=True, capture_output=True, check=False, timeout=timeout)
    if check and result.returncode:
        raise RuntimeError(f"command failed: {' '.join(args)}\n{result.stdout}\n{result.stderr}")
    return result


# --- addresses and hosts ----------------------------------------------------------------------------------------------------------

class ClientAddresses:
    """A unique client address per request, alternating IPv4 and IPv6, each IPv6 address in its own /64."""

    def __init__(self) -> None:
        self.counter = 0
        self.lock = threading.Lock()

    def next(self) -> str:
        with self.lock:
            self.counter += 1
            number = self.counter
        if number % 2:
            return f"10.{(number >> 16) & 255}.{(number >> 8) & 255}.{number & 255}"
        return f"2001:db8:{(number >> 16) & 0xffff:x}:{number & 0xffff:x}::1"


def host_matrix(only: list[str] | None = None) -> list[dict]:
    hosts = [{"name": "off", "profile": "off", "paranoia": 0}]
    for profile in ("monitor", "balanced", "strict"):
        for level in PARANOIA_LEVELS:
            hosts.append({"name": f"{profile}-pl{level}", "profile": profile, "paranoia": level})
    selected = [item for item in hosts if not only or item["name"] in only]
    for index, item in enumerate(selected):
        item["hostname"] = f"{item['name']}.baseline.test"
        item["domain_id"] = 300 + index
    return selected


def snapshot_host(item: dict, origin_ip: str) -> dict:
    profile = {**PROFILES[item["profile"]]}
    if item["profile"] != "off":
        profile["paranoia_level"] = item["paranoia"]
    return {
        "domain": item["hostname"], "domain_id": item["domain_id"], "revision": 1,
        "settings": {"enabled": True, "redirect_https": False, "http_versions": ["1.1", "2"]},
        "cache": {"enabled": False, "epoch": 1},
        "compression": {"profile_name": "off", "maximum_active_requests": 0},
        "tls": {"mode": "custom", "certificate_id": "baseline"},
        "security": {
            "state": "normal", "allowed_methods": ["GET", "HEAD", "POST", "PUT", "PATCH", "DELETE", "OPTIONS"],
            "trusted_proxy_cidrs": ["0.0.0.0/0", "::/0"],
            "limits": {"maximum_header_size": 32768, "maximum_request_body_size": 8388608, "requests_per_second": 1000000,
                       "request_burst": 1000000, "connections_per_client": 100000, "connections_per_domain": 100000},
            "rules": [],
        },
        "waf": {"name": item["profile"], **profile, "ruleset": "owasp-crs/4.30.0-modsecurity/3.0.17", "exclusions": [],
                "off_categories": [], "off_rules": [], "log_only_rules": []},
        "origin": {"host": origin_ip, "port": 80, "scheme": "http", "host_header": "waf-origin", "sni": None, "verify_tls": False,
                   "connect_timeout_ms": 1000, "response_timeout_ms": 10000, "retry_count": 0, "websocket": False, "health_check": None,
                   "private_allowlist": ["192.168.0.0/16", "172.16.0.0/12", "10.0.0.0/8"], "blocked_networks": [], "blocked_addresses": []},
    }


def cleanup() -> None:
    run("docker", "rm", "-f", CELL, ORIGIN, check=False)
    run("docker", "network", "rm", NETWORK, check=False)


def container_ip(name: str) -> str:
    return run("docker", "inspect", "--format", f'{{{{(index .NetworkSettings.Networks "{NETWORK}").IPAddress}}}}', name).stdout.strip()


def start_cell(args: argparse.Namespace, directory: pathlib.Path, hosts: list[dict]) -> tuple[str, str]:
    import edge_cell_benchmark as benchmark

    run("docker", "network", "create", "--subnet", SUBNET, NETWORK)
    (directory / "origin.py").write_text(ORIGIN_SOURCE)
    run("docker", "run", "-d", "--name", ORIGIN, "--network", NETWORK, "--cpus", "2", "--log-opt", "max-size=512m",
        "-v", f"{directory / 'origin.py'}:/origin.py:ro", "python:3.13-alpine3.22", "python", "/origin.py")
    origin_ip = container_ip(ORIGIN)
    names = [item["hostname"] for item in hosts]
    run("openssl", "req", "-x509", "-newkey", "rsa:2048", "-nodes", "-days", "1", "-subj", "/CN=baseline.test",
        "-addext", "subjectAltName=" + ",".join(f"DNS:{name}" for name in names),
        "-keyout", str(directory / "tls.key"), "-out", str(directory / "tls.crt"))
    certificates = {"baseline": {"id": "baseline", "certificate_pem": (directory / "tls.crt").read_text(), "chain_pem": "",
                                 "private_key_pem": (directory / "tls.key").read_text(), "expires_at": int(time.time()) + 86400, "names": names}}
    runtime = directory / "runtime.json"
    runtime.write_text(json.dumps({"schema_version": 1, "sequence": 1, "generation_id": "waf-baseline-1", "certificates": certificates,
                                   "hosts": {item["hostname"]: snapshot_host(item, origin_ip) for item in hosts}}, separators=(",", ":")))
    server_conf = directory / "default.conf"
    server_conf.write_text(benchmark.bench_server_config(ROOT / "docker/nginx/edge-runtime.conf"))
    for item in (runtime, server_conf, directory / "tls.key", directory / "tls.crt"):
        item.chmod(0o644)
    directory.chmod(0o755)
    run("docker", "run", "-d", "--name", CELL, "--network", NETWORK, "--cpus", str(args.cpus), "--memory", args.memory,
        "--pids-limit", "512", "--ulimit", "nofile=65536:65536", "--log-driver", "json-file", "--log-opt", "max-size=2g", "--log-opt", "max-file=1",
        "-e", "EDGE_CELL_NAME=waf-baseline", "-e", "EDGE_RUNTIME_FILE=/var/lib/cdnfoundry/runtime/active.json",
        "-e", "EDGE_STATUS_TOKEN=baseline-only", "-e", "EDGE_ACCESS_LOG_STDOUT=true",
        "-v", f"{runtime}:/var/lib/cdnfoundry/runtime/active.json:ro",
        "-v", f"{directory / 'tls.crt'}:/run/edge/tls.crt:ro", "-v", f"{directory / 'tls.key'}:/run/edge/tls.key:ro",
        "-v", f"{server_conf}:/etc/nginx/conf.d/default.conf:ro",
        "--tmpfs", "/var/cache/nginx:rw,noexec,nosuid,size=256m,mode=0777", "--tmpfs", "/var/lib/nginx/tmp:rw,noexec,nosuid,size=128m", args.image)
    cell_ip = container_ip(CELL)
    deadline = time.monotonic() + 90
    while time.monotonic() < deadline:
        probe = subprocess.run(["curl", "-s", "-o", "/dev/null", "-w", "%{http_code}", "--max-time", "3", "-H", f"Host: {names[0]}",
                                f"http://{cell_ip}:8080/ready"], capture_output=True, text=True, check=False)
        if probe.stdout == "200":
            return cell_ip, origin_ip
        time.sleep(1)
    raise RuntimeError("the cell did not become ready:\n" + run("docker", "logs", CELL, check=False).stderr[-3000:])


# --- sending ----------------------------------------------------------------------------------------------------------------------

def send_bytes(cell_ip: str, payload: bytes, timeout: float = 30) -> tuple[int, float]:
    """One request over its own connection. Returns the status (0 for no answer) and the seconds until the status line."""
    started = time.monotonic()
    try:
        with socket.create_connection((cell_ip, CELL_PORT), timeout=timeout) as connection:
            connection.settimeout(timeout)
            try:
                connection.sendall(payload)
            except OSError:
                pass  # the cell may refuse a large body early; its answer is still readable
            data = b""
            while b"\r\n" not in data and len(data) < 4096:
                piece = connection.recv(4096)
                if not piece:
                    break
                data += piece
            elapsed = time.monotonic() - started
            line = data.split(b"\r\n", 1)[0].split(b" ")
            return (int(line[1]) if len(line) > 1 and line[1].isdigit() else 0), elapsed
    except OSError:
        return 0, time.monotonic() - started


def send_curl(cell_ip: str, request: corpus.CorpusRequest, hostname: str, client: str) -> tuple[int, float]:
    tls = request.transport == "h2"
    port = 8443 if tls else 8080
    command = ["curl", "-s", "-o", "/dev/null", "-w", "%{http_code}", "--max-time", "30", "--resolve", f"{hostname}:{port}:{cell_ip}",
               "--http2" if tls else "--http2-prior-knowledge", "-H", f"X-Forwarded-For: {client}"]
    if tls:
        command.append("-k")
    for name, value in request.headers:
        command += ["-H", f"{name}: {value}"]
    if request.body:
        command += ["-X", request.method, "-T", "-"]  # a streamed upload has no Content-Length
    elif request.method != "GET":
        command += ["-X", request.method]
    command.append(f"{'https' if tls else 'http'}://{hostname}:{port}{request.path}")
    started = time.monotonic()
    result = subprocess.run(command, input=request.body or None, capture_output=True, check=False, timeout=60)
    return int(result.stdout.decode() or 0), time.monotonic() - started


def send_request(cell_ip: str, request: corpus.CorpusRequest, hostname: str, client: str) -> tuple[int, float]:
    if request.transport in ("h2", "h2c"):
        return send_curl(cell_ip, request, hostname, client)
    return send_bytes(cell_ip, corpus.with_host_and_client(request, hostname, client))


# --- decisions --------------------------------------------------------------------------------------------------------------------

def container_rows(name: str, since: str, prefix: str) -> list[dict]:
    result = run("docker", "logs", "--since", since, name, check=False, timeout=900)
    rows = []
    for line in (result.stdout + "\n" + result.stderr).splitlines():
        if line.startswith(prefix):
            try:
                rows.append(json.loads(line))
            except ValueError:
                pass
    return rows


def decide(status: int, row: dict | None, reached: bool) -> str:
    """blocked: the WAF denied it. logged: it reached the origin but the WAF scored or monitored it. passed: nothing flagged it.
    refused: the cell rejected it before the origin for another reason (limits, malformed). error: no answer at all.
    unlogged: it reached the origin but the cell logged no row for it, which is a harness or logging fault, never a pass."""
    if row is None and not reached:
        # No access-log row joined: the cell rejected it before the access phase (limits, malformed framing), so the status is the evidence.
        return "error" if status == 0 else "refused"
    if row is None:
        return "unlogged"
    if row.get("waf_action") == "block" and not reached:
        return "blocked"
    if not reached:
        return "refused"
    if row.get("waf_action") == "monitor" and (row.get("waf_score") or 0) > 0:
        return "logged"
    if (row.get("attack_score") or 99) < 99:
        return "logged"
    return "passed"


def docker_cell_rows(since: str) -> list[dict]:
    return container_rows(CELL, since, '{"occurred_at"')


def docker_origin_rows(since: str) -> list[dict]:
    return container_rows(ORIGIN, since, '{"c"')


def replay(cell_ip: str, hostname: str, requests: list[corpus.CorpusRequest], addresses: ClientAddresses, workers: int,
           started_at: str, cell_log=docker_cell_rows, origin_log=docker_origin_rows, settle: float = 3) -> list[dict]:
    """Replay on one host. Each result carries the request, its client address, status, latency and the joined decision."""
    clients = [addresses.next() for _ in requests]
    results: list[dict] = [{} for _ in requests]

    def one(index: int) -> None:
        status, seconds = send_request(cell_ip, requests[index], hostname, clients[index])
        results[index] = {"status": status, "seconds": seconds}

    if any(request.transport in ("h2", "h2c") for request in requests):
        workers = min(workers, 4)
    with concurrent.futures.ThreadPoolExecutor(max_workers=workers) as executor:
        list(executor.map(one, range(len(requests))))
    time.sleep(settle)  # the access log and the origin log are written after the answer
    cell_rows = {row["client_ip"]: row for row in cell_log(started_at) if row.get("hostname") == hostname}
    reached = {row["c"] for row in origin_log(started_at)}
    for request, client, result in zip(requests, clients, results):
        row = cell_rows.get(client)
        result.update({"id": request.identifier, "category": request.category, "client": client, "row": row,
                       "reached": client in reached, "decision": decide(result["status"], row, client in reached)})
    return results


# --- aggregation ------------------------------------------------------------------------------------------------------------------

def wilson(successes: int, total: int, z: float = 1.96) -> list[float]:
    """95% Wilson score interval of a rate, so no rate is quoted without its denominator and uncertainty."""
    if total == 0:
        return [0.0, 1.0]
    p = successes / total
    centre = (p + z * z / (2 * total)) / (1 + z * z / total)
    margin = z * math.sqrt(p * (1 - p) / total + z * z / (4 * total * total)) / (1 + z * z / total)
    return [round(max(0.0, centre - margin), 5), round(min(1.0, centre + margin), 5)]


def percentile(values: list[float], fraction: float) -> float:
    ordered = sorted(values)
    return round(ordered[min(len(ordered) - 1, int(len(ordered) * fraction))] * 1000, 2) if ordered else 0.0


def rates(results: list[dict]) -> dict:
    count = collections.Counter(item["decision"] for item in results)
    total = len(results)
    detected = count["blocked"] + count["logged"]
    blocks = [item for item in results if item["decision"] == "blocked" and "rule_ids" in item]
    # A block that names a rule other than the blocking evaluation 949110 explains itself; the baseline of an image that predates
    # matched-rule reporting has none, and says so with zero here.
    explained = sum(1 for item in blocks if item["rule_ids"] and item.get("rule_id") not in (None, 0, 949110))
    return {"blocks_with_matched_rule": explained, "blocks_checked": len(blocks), "requests": total, "decisions": dict(sorted(count.items())), "blocked": count["blocked"], "logged": count["logged"],
            "passed": count["passed"], "refused": count["refused"], "unanswered": count["error"] + count["unlogged"],
            "block_rate": round(count["blocked"] / total, 5) if total else 0.0, "block_rate_ci95": wilson(count["blocked"], total),
            "detection_rate": round(detected / total, 5) if total else 0.0, "detection_rate_ci95": wilson(detected, total),
            "pass_rate": round(count["passed"] / total, 5) if total else 0.0}


def summarise_host(item: dict, crs: list[dict], evasion: list[dict], clean: list[dict]) -> dict:
    level = item["paranoia"] or 1
    attacks = [entry for entry in crs if entry["category"] != "negative"]
    in_scope = [entry for entry in attacks if entry["floor"] <= level]
    by_category: dict[str, dict] = {}
    for category in sorted({entry["category"] for entry in in_scope}):
        by_category[category] = rates([entry for entry in in_scope if entry["category"] == category])
    hard = [entry for entry in clean if corpus.is_hard_negative(entry["id"])]
    clean = [entry for entry in clean if not corpus.is_hard_negative(entry["id"])]
    by_class = {name: rates([entry for entry in clean if entry["category"] == name]) for name in sorted({entry["category"] for entry in clean})}
    by_method = {name: rates([entry for entry in clean if entry["method"] == name]) for name in sorted({entry["method"] for entry in clean})}
    latencies = [entry["seconds"] for entry in clean if entry["decision"] in ("passed", "logged")]
    return {
        "profile": item["profile"], "paranoia_level": item["paranoia"], "hostname_label": item["name"],
        "crs_attacks_in_scope": rates(in_scope), "crs_attacks_in_scope_by_category": by_category,
        "crs_attacks_above_paranoia": rates([entry for entry in attacks if entry["floor"] > level]),
        "crs_stages_without_expected_rules": rates([entry for entry in crs if entry["category"] == "negative"]),
        "clean_hard_negatives": rates(hard), "clean_hard_negatives_by_class": {name: rates([entry for entry in hard if entry["category"] == name])
                                                                              for name in sorted({entry["category"] for entry in hard})},
        "evasion": rates(evasion),
        "clean": rates(clean), "clean_by_class": by_class, "clean_by_method": by_method,
        "clean_false_positive_rate": rates(clean)["block_rate"], "clean_false_positive_rate_ci95": rates(clean)["block_rate_ci95"],
        "hard_negative_false_positive_rate": rates(hard)["block_rate"], "hard_negative_false_positive_rate_ci95": rates(hard)["block_rate_ci95"],
        "clean_client_latency_ms": {"p50": percentile(latencies, 0.50), "p95": percentile(latencies, 0.95), "p99": percentile(latencies, 0.99),
                                    "note": "client-observed with 16 concurrent connections; the capacity benchmark records CPU per request"},
    }


def slim(results: list[dict], requests: list[corpus.CorpusRequest], floors: dict[str, int] | None = None) -> list[dict]:
    """The fields the record keeps for each request. ``results`` is in the order of ``requests``."""
    return [{"id": entry["id"], "category": entry["category"], "method": request.method, "decision": entry["decision"], "status": entry["status"],
             "seconds": entry["seconds"], "floor": (floors or {}).get(entry["id"], 1), "waf_score": (entry["row"] or {}).get("waf_score"),
             "attack_score": (entry["row"] or {}).get("attack_score"), "reason": (entry["row"] or {}).get("security_reason"),
             "rule_id": (entry["row"] or {}).get("waf_rule_id"), "rule_ids": (entry["row"] or {}).get("waf_rule_ids", "")}
            for entry, request in zip(results, requests)]


# --- the record -------------------------------------------------------------------------------------------------------------------

def git_state() -> dict:
    commit = run("git", "rev-parse", "HEAD", check=False).stdout.strip()
    diff = run("git", "diff", "HEAD", check=False).stdout
    status = run("git", "status", "--porcelain", check=False).stdout
    return {"commit": commit, "dirty": bool(status.strip()), "diff_sha256": hashlib.sha256(diff.encode()).hexdigest() if diff else None}


def environment(args: argparse.Namespace) -> dict:
    cpu = next((line.split(":", 1)[1].strip() for line in pathlib.Path("/proc/cpuinfo").read_text().splitlines() if line.startswith("model name")), "")
    image = json.loads(run("docker", "image", "inspect", args.image).stdout)[0]
    versions = run("docker", "exec", CELL, "cat", "/etc/cdnfoundry/ruleset-versions.json", check=False).stdout.strip()
    return {
        "kernel": platform.release(), "cpu_model": cpu, "host_logical_cpus": os.cpu_count(), "cell_cpu_quota": args.cpus, "cell_memory_limit": args.memory,
        "docker": run("docker", "version", "--format", "{{.Server.Version}}").stdout.strip(), "network": f"docker bridge {SUBNET}, one host, loopback-free",
        "image": {"reference": args.image, "id": image["Id"], "repo_digests": image.get("RepoDigests", []), "created": image["Created"]},
        "ruleset_versions": json.loads(versions) if versions else None, "pinned_crs_commit": corpus.pinned_crs_commit(),
        "generator": "Python 3 threads on the same host, 16 concurrent connections (4 for HTTP/2 through curl)",
        "label": "development host: this record alone does not close fleet qualification",
    }


def check_expectations(cases: list[corpus.CorpusRequest], per_host: dict[str, list[dict]]) -> list[dict]:
    outcomes = []
    for case in cases:
        entry = {"id": case.identifier, "class": case.category, "transport": case.transport, "note": case.note, "results": {}, "expectations": {}}
        for host_name, results in per_host.items():
            found = next((item for item in results if item["id"] == case.identifier), None)
            if found:
                entry["results"][host_name] = {"decision": found["decision"], "status": found["status"]}
        for profile, expected in case.expect.items():
            observed = entry["results"].get(EXPECTATION_HOSTS[profile], {}).get("decision")
            ok = {"block": observed == "blocked", "detect": observed in ("blocked", "logged"), "pass": observed == "passed",
                  "refuse": observed == "refused"}[expected]
            entry["expectations"][profile] = {"expected": expected, "observed": observed, "met": ok}
        outcomes.append(entry)
    return outcomes


def build_record(args: argparse.Namespace) -> dict:
    hosts = host_matrix(args.only)
    crs_requests, crs_stats, crs_digest = [], {}, None
    if not args.skip_crs:
        checkout = corpus.checkout_crs(pathlib.Path(args.crs_dir))
        crs_requests, crs_stats = corpus.crs_regression_requests(checkout)
        crs_digest = corpus.corpus_digest(crs_requests)
    evasion_requests, evasion_stats = corpus.evasion_corpus()
    clean_requests = corpus.clean_corpus(scale=args.clean_scale)
    if args.clean_scale == 1.0 and len(clean_requests) < corpus.MINIMUM_CLEAN_REQUESTS:
        raise RuntimeError("the clean corpus is smaller than the required 10,000 requests")
    floors = {request.identifier: request.paranoia_floor for request in crs_requests}
    addresses = ClientAddresses()
    record: dict = {
        "record_version": RECORD_VERSION, "kind": "waf-baseline", "created_at": datetime.datetime.now(datetime.timezone.utc).isoformat(timespec="seconds"),
        "label": args.label, "git": git_state(),
        "corpora": {
            "crs": {"commit": corpus.pinned_crs_commit(), "stages": crs_stats, "digest": crs_digest},
            "evasion": {**evasion_stats, "digest": corpus.corpus_digest(evasion_requests)},
            "clean": {"version": corpus.CLEAN_CORPUS_VERSION, "seed": corpus.CLEAN_CORPUS_SEED, "scale": args.clean_scale, "requests": len(clean_requests),
                      "classes": corpus.clean_class_counts(clean_requests),
                      "hard_negatives": sum(1 for request in clean_requests if corpus.is_hard_negative(request.identifier)), "digest": corpus.corpus_digest(clean_requests),
                      "provenance": "generated from a seed, never recorded from a live system; coverage input, not proof of accuracy"},
        },
        "hosts": {}, "evasion_cases": [],
    }
    cleanup()
    try:
        with tempfile.TemporaryDirectory(prefix="cdnf-waf-baseline-") as directory:
            cell_ip, _ = start_cell(args, pathlib.Path(directory), hosts)
            record["environment"] = environment(args)
            evasion_results: dict[str, list[dict]] = {}
            for item in hosts:
                started = time.monotonic()
                since = (datetime.datetime.now(datetime.timezone.utc) - datetime.timedelta(seconds=2)).strftime("%Y-%m-%dT%H:%M:%SZ")
                crs_results = slim(replay(cell_ip, item["hostname"], crs_requests, addresses, args.workers, since), crs_requests, floors) if crs_requests else []
                evasion_run = replay(cell_ip, item["hostname"], evasion_requests, addresses, args.workers, since)
                clean_results = slim(replay(cell_ip, item["hostname"], clean_requests, addresses, args.workers, since), clean_requests)
                evasion_results[item["name"]] = slim(evasion_run, evasion_requests)
                record["hosts"][item["name"]] = summarise_host(item, crs_results, evasion_results[item["name"]], clean_results)
                record["hosts"][item["name"]]["replay_seconds"] = round(time.monotonic() - started, 1)
                print(f"{item['name']}: crs in-scope detection {record['hosts'][item['name']]['crs_attacks_in_scope']['detection_rate']}, "
                      f"clean false positives {record['hosts'][item['name']]['clean_false_positive_rate']}", flush=True)
            record["evasion_cases"] = check_expectations(evasion_requests, evasion_results)
            chunked = next((entry for entry in record["evasion_cases"] if entry["id"] == "evasion:xss-chunked"), None)
            record["chunked_body_result"] = {"case": "evasion:xss-chunked", "results": chunked["results"] if chunked else {},
                                             "note": "recorded as observed on this image; the baseline does not change behavior"}
            record["unmet_expectations"] = [{"id": entry["id"], "profile": profile, **detail} for entry in record["evasion_cases"]
                                            for profile, detail in entry["expectations"].items() if not detail["met"]]
    finally:
        cleanup()
    return record


def compare(old: dict, new: dict, tolerance: float) -> list[str]:
    problems = []
    for name, current in new["hosts"].items():
        previous = old["hosts"].get(name)
        if not previous:
            continue
        before, after = previous["crs_attacks_in_scope"]["detection_rate"], current["crs_attacks_in_scope"]["detection_rate"]
        if after < before - tolerance:
            problems.append(f"{name}: in-scope CRS detection fell from {before} to {after}")
        before, after = previous["clean_false_positive_rate"], current["clean_false_positive_rate"]
        if after > before + tolerance:
            problems.append(f"{name}: clean false positives rose from {before} to {after}")
        old_unanswered, new_unanswered = previous["clean"]["unanswered"], current["clean"]["unanswered"]
        if new_unanswered > old_unanswered:
            problems.append(f"{name}: unanswered clean requests rose from {old_unanswered} to {new_unanswered}")
    return problems


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    commands = parser.add_subparsers(dest="command", required=True)
    runner = commands.add_parser("run", help="replay the corpora against a real cell and write the record")
    runner.add_argument("--image", default=DEFAULT_IMAGE)
    runner.add_argument("--output", required=True)
    runner.add_argument("--label", default="")
    runner.add_argument("--cpus", type=float, default=2.0)
    runner.add_argument("--memory", default="2g")
    runner.add_argument("--workers", type=int, default=16)
    runner.add_argument("--only", nargs="*", help="host names such as balanced-pl1 (default: every profile and paranoia level)")
    runner.add_argument("--clean-scale", type=float, default=1.0, help="below 1.0 for development runs; a baseline uses 1.0")
    runner.add_argument("--skip-crs", action="store_true")
    runner.add_argument("--crs-dir", default=os.environ.get("CDNF_CRS_DIR", str(pathlib.Path.home() / ".cache" / "cdnfoundry-waf-baseline" / "crs")))
    runner.add_argument("--strict", action="store_true", help="exit 1 when an evasion expectation is not met")
    comparer = commands.add_parser("compare", help="compare a record with a baseline; exit 1 when detection fell or false positives rose")
    comparer.add_argument("baseline")
    comparer.add_argument("record")
    comparer.add_argument("--tolerance", type=float, default=0.005)
    args = parser.parse_args()
    if args.command == "compare":
        problems = compare(json.loads(pathlib.Path(args.baseline).read_text()), json.loads(pathlib.Path(args.record).read_text()), args.tolerance)
        print("\n".join(problems) if problems else "no regression against the baseline")
        return 1 if problems else 0
    record = build_record(args)
    pathlib.Path(args.output).write_text(json.dumps(record, indent=1, sort_keys=True) + "\n")
    print(f"record written to {args.output}; unmet evasion expectations: {len(record['unmet_expectations'])}")
    return 1 if args.strict and record["unmet_expectations"] else 0


if __name__ == "__main__":
    sys.exit(main())
