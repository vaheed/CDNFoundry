#!/usr/bin/env python3
"""Real OpenResty Phase 6 security/readiness qualification; no browser automation."""

import json
import os
import pathlib
import subprocess
import tempfile
import time

ROOT = pathlib.Path(__file__).resolve().parents[2]
NAME = "cdnf-phase6-security-e2e"
SLOW_CLIENT = "cdnf-phase6-slow-client-e2e"
SLOW_ORIGIN_CLIENT = "cdnf-phase6-origin-client-e2e"
EDGE_NETWORK = os.environ.get("CDNF_EDGE_NETWORK", f"{os.environ.get('COMPOSE_PROJECT_NAME', 'cdnfoundry-dev')}_edge")
MMDB_VOLUME = f"{os.environ.get('COMPOSE_PROJECT_NAME', 'cdnfoundry-dev')}_mmdb"


def run(*args: str, check: bool = True) -> subprocess.CompletedProcess[str]:
    result = subprocess.run(args, cwd=ROOT, check=False, text=True, capture_output=True)
    if check and result.returncode != 0:
        raise RuntimeError(f"command failed ({result.returncode}): {' '.join(args)}\n{result.stdout}\n{result.stderr}")
    return result


def limits(**overrides: int) -> dict[str, int]:
    values = {
        "requests_per_second": 100, "request_burst": 200, "connections_per_client": 64,
        "connections_per_domain": 512, "tls_handshakes_per_second": 50,
        "maximum_request_body_size": 1024, "maximum_header_size": 8192,
        "client_header_timeout": 5, "client_body_timeout": 5, "keepalive_timeout": 5,
        "maximum_requests_per_connection": 100, "maximum_request_duration": 30,
        "origin_max_connections": 16, "origin_connect_timeout": 2, "origin_read_timeout": 10,
        "origin_send_timeout": 10, "origin_retry_limit": 0, "origin_failure_threshold": 2,
        "origin_recovery_timeout": 10, "maximum_cache_key_length": 1024,
        "cache_admissions_per_second": 20,
    }
    values.update(overrides)
    return values


def host(domain_id: int, rules: list[dict] | None = None, state: str = "normal", **limit_overrides: int) -> dict:
    return {
        "domain": f"domain-{domain_id}.example", "domain_id": domain_id, "revision": 1,
        "settings": {"enabled": True}, "cache": {"enabled": False, "epoch": 1},
        "security": {
            "profile": "standard", "effective_profile": "standard", "state": state,
            "quarantine_policy": "manual", "allowed_methods": ["GET", "HEAD", "POST"],
            "trusted_proxy_cidrs": ["127.0.0.0/8", "::1/128"], "limits": limits(**limit_overrides),
            "rules": rules or [],
        },
        "origin": {
            "host": "origin-http", "port": 80, "scheme": "http", "host_header": "phase6-origin.example",
            "sni": None, "verify_tls": False, "connect_timeout_ms": 1000,
            "response_timeout_ms": 10000, "retry_count": 0, "websocket": False,
            "private_allowlist": ["172.16.0.0/12"], "blocked_networks": [], "blocked_addresses": [],
        },
    }


def curl(hostname: str, path: str = "/", method: str = "GET", headers: tuple[str, ...] = (), data: str | None = None) -> subprocess.CompletedProcess[str]:
    command = ["docker", "run", "--rm", "--network", f"container:{NAME}", "curlimages/curl:8.16.0", "-sS", "-D", "-", "-o", "/dev/null", "-X", method, "-H", f"Host: {hostname}"]
    command.extend([value for header in headers for value in ("-H", header)])
    if data is not None:
        command.extend(["--data-binary", data])
    command.append(f"http://127.0.0.1:8080{path}")
    return run(*command, check=False)


def status(response: subprocess.CompletedProcess[str], expected: int, reason: str | None = None) -> None:
    assert f"HTTP/1.1 {expected}" in response.stdout, response.stdout + response.stderr
    if reason:
        assert f"X-CDNFoundry-Security-Reason: {reason}".lower() in response.stdout.lower(), response.stdout


def control(task_id: str, active: bool, actions: list[str], expires_at: int | None = None) -> dict:
    payload = {"task_id": task_id, "action": "emergency_mode", "active": active, "actions": actions, "expires_at": expires_at}
    response = run("docker", "exec", NAME, "wget", "-q", "-O-", "--header=X-Edge-Status-Token: runtime-test-token", "--header=Content-Type: application/json", f"--post-data={json.dumps(payload, separators=(',', ':'))}", "http://127.0.0.1:9080/control")
    return json.loads(response.stdout)["data"]


def main() -> None:
    run("docker", "compose", "-f", "compose.dev.yml", "up", "-d", "--force-recreate", "origin-http")
    run("docker", "compose", "-f", "compose.dev.yml", "up", "-d", "mmdb-updater")
    run("docker", "build", "-f", "docker/openresty/Dockerfile", "-t", "cdnfoundry/edge-runtime:phase6", ".")
    with tempfile.TemporaryDirectory(prefix="cdnf-phase6-") as directory:
        os.chmod(directory, 0o755)
        temporary = pathlib.Path(directory)
        run("openssl", "req", "-x509", "-newkey", "rsa:2048", "-nodes", "-days", "1", "-subj", "/CN=phase6-runtime", "-keyout", str(temporary / "tls.key"), "-out", str(temporary / "tls.crt"))
        hosts = {
            "healthy.example": host(1),
            "ipv4.example": host(2, [{"id": 1, "match_type": "cidr", "value": "127.0.0.0/8", "action": "block", "priority": 1}]),
            "ipv6.example": host(3, [{"id": 2, "match_type": "cidr", "value": "::1/128", "action": "block", "priority": 1}]),
            "geo.example": host(4, [{"id": 3, "match_type": "country", "value": "US", "action": "block", "priority": 1}]),
            "rate.example": host(5, requests_per_second=2, request_burst=1),
            "connections.example": host(6, connections_per_client=1, connections_per_domain=2),
            "origin-capacity.example": host(7, origin_max_connections=1),
            "quarantine.example": host(8, state="quarantined"),
            "body.example": host(9, maximum_request_body_size=8),
        }
        state = {"schema_version": 1, "sequence": 1, "certificates": {}, "hosts": hosts}
        runtime = temporary / "active.json"
        runtime.write_text(json.dumps(state, separators=(",", ":")))
        for file in (runtime, temporary / "tls.key", temporary / "tls.crt"):
            file.chmod(0o644)
        run("docker", "run", "-d", "--name", NAME, "--network", EDGE_NETWORK,
            "--tmpfs", "/var/cache/nginx:rw,size=64m", "--tmpfs", "/var/lib/nginx/tmp:rw,size=32m",
            "--memory", "256m", "--cpus", "0.5", "--pids-limit", "96",
            "--ulimit", "nofile=65536:65536",
            "-e", "EDGE_RUNTIME_FILE=/var/lib/cdnfoundry/runtime/active.json", "-e", "EDGE_STATUS_TOKEN=runtime-test-token",
            "-e", "GEOIP_DATABASE=/mmdb/GeoLite2-City.mmdb", "-v", f"{directory}:/var/lib/cdnfoundry/runtime:ro",
            "-v", f"{temporary / 'tls.crt'}:/run/edge/tls.crt:ro", "-v", f"{temporary / 'tls.key'}:/run/edge/tls.key:ro",
            "-v", f"{MMDB_VOLUME}:/mmdb:ro", "cdnfoundry/edge-runtime:phase6")
        try:
            run("docker", "exec", NAME, "openresty", "-t")
            time.sleep(1.2)
            status(curl("unknown.example"), 421, "unknown_host")
            status(curl("healthy.example", method="TRACE"), 405, "invalid_method")
            status(curl("body.example", method="POST", data="0123456789"), 413, "body_too_large")
            status(curl("ipv4.example"), 403, "domain_restricted")
            ipv6 = run("docker", "run", "--rm", "--network", f"container:{NAME}", "curlimages/curl:8.16.0", "-g", "-sS", "-D", "-", "-o", "/dev/null", "-H", "Host: ipv6.example", "http://[::1]:8080/", check=False)
            status(ipv6, 403, "domain_restricted")
            status(curl("geo.example", headers=("X-Forwarded-For: 8.8.8.8",)), 403, "domain_restricted")
            status(curl("quarantine.example"), 429, "domain_quarantined")

            rate_results = run("docker", "run", "--rm", "--network", f"container:{NAME}", "--entrypoint", "sh", "curlimages/curl:8.16.0", "-c", "for n in 1 2 3 4 5 6; do curl -sS -D - -o /dev/null -H 'Host: rate.example' http://127.0.0.1:8080/; done", check=False)
            assert "client_rate_exceeded" in rate_results.stdout, rate_results.stdout
            status(curl("healthy.example"), 200)

            slow = subprocess.Popen(["docker", "run", "--rm", "--name", SLOW_CLIENT, "--network", f"container:{NAME}", "curlimages/curl:8.16.0", "-sS", "-H", "Host: connections.example", "http://127.0.0.1:8080/slow"], cwd=ROOT, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
            time.sleep(0.8)
            try:
                status(curl("connections.example"), 429, "client_connections_exceeded")
            finally:
                run("docker", "rm", "-f", SLOW_CLIENT, check=False); slow.wait(timeout=5)

            origin_slow = subprocess.Popen(["docker", "run", "--rm", "--name", SLOW_ORIGIN_CLIENT, "--network", f"container:{NAME}", "curlimages/curl:8.16.0", "-sS", "-H", "Host: origin-capacity.example", "-H", "X-Forwarded-For: 198.51.100.1", "http://127.0.0.1:8080/slow"], cwd=ROOT, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
            time.sleep(0.8)
            try:
                status(curl("origin-capacity.example", headers=("X-Forwarded-For: 198.51.100.2",)), 503, "origin_capacity_exceeded")
            finally:
                run("docker", "rm", "-f", SLOW_ORIGIN_CLIENT, check=False); origin_slow.wait(timeout=5)

            applied = control("phase6-emergency-1", True, ["allow_get_head_only", "disable_origin_retries"], int(time.time()) + 2)
            assert applied["accepted"] is True and applied["replayed"] is False, applied
            status(curl("healthy.example", method="POST", data="ok"), 405, "edge_emergency_mode")
            assert control("phase6-emergency-1", True, ["allow_get_head_only", "disable_origin_retries"], int(time.time()) + 2)["replayed"] is True
            time.sleep(2.2)
            status(curl("healthy.example", method="POST", data="ok"), 200)

            status_payload = run("docker", "exec", NAME, "wget", "-q", "-O-", "--header=X-Edge-Status-Token: runtime-test-token", "http://127.0.0.1:9080/passive-failures")
            decoded = json.loads(status_payload.stdout)
            assert len(decoded["security"]) <= 20 and any(event["reason_code"] == "domain_quarantined" for event in decoded["security"]), decoded
            assert decoded["cell"]["capacity"]["memory_usage"] <= 256 * 1024 * 1024, decoded["cell"]

            runtime.write_text('{"schema_version":1,"hosts":"invalid"}')
            time.sleep(1.2)
            status(curl("healthy.example"), 200)
            logs = run("docker", "logs", NAME, check=False).stdout + run("docker", "logs", NAME, check=False).stderr
            assert "invalid runtime state" in logs, logs
            print("Phase 6 real security runtime qualification passed")
        finally:
            if os.environ.get("CDNF_KEEP_FAILED_RUNTIME") != "1":
                run("docker", "rm", "-f", SLOW_CLIENT, SLOW_ORIGIN_CLIENT, check=False)
                run("docker", "rm", "-f", NAME, check=False)


if __name__ == "__main__":
    main()
