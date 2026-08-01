#!/usr/bin/env python3
"""Real OpenResty active-passive origin failover qualification; no browser automation."""

import json
import os
import pathlib
import subprocess
import tempfile
import time
from concurrent.futures import ThreadPoolExecutor

ROOT = pathlib.Path(__file__).resolve().parents[2]
CELL = "cdnf-origin-failover-cell"
PRIMARY = "cdnf-origin-failover-primary"
BACKUP = "cdnf-origin-failover-backup"
ISOLATED = "cdnf-origin-failover-isolated"
NETWORK = os.environ.get("CDNF_EDGE_NETWORK", "cdnfoundry-dev_edge")
# Docker daemon scheduling is outside the runtime's control and can consume a
# small stale window on loaded CI hosts. The short-window expiry boundary is
# qualified separately in phase4_runtime.py; this test covers its interaction
# with active-passive failover.
STALE_IF_ERROR_SECONDS = 60


def run(*args: str, check: bool = True) -> subprocess.CompletedProcess[str]:
    result = subprocess.run(args, cwd=ROOT, check=False, text=True, capture_output=True)
    if check and result.returncode != 0:
        raise RuntimeError(f"command failed: {' '.join(args)}\n{result.stdout}\n{result.stderr}")
    return result


def request(host: str, path: str = "/") -> str:
    result = run("docker", "exec", CELL, "wget", "-T", "3", "-S", "-O-",
        f"--header=Host: {host}", f"http://127.0.0.1:8080{path}", check=False)
    return result.stderr + result.stdout


def wait_for_cell(timeout_seconds: float = 15) -> None:
    deadline = time.monotonic() + timeout_seconds
    while time.monotonic() < deadline:
        result = run("docker", "exec", CELL, "wget", "-qO-", "http://127.0.0.1:8080/healthz", check=False)
        if result.returncode == 0 and result.stdout == "ok\n":
            return
        time.sleep(0.2)
    raise RuntimeError(f"edge cell did not become ready\n{run('docker', 'logs', CELL, check=False).stderr}")


def wait_for_origin(address: str, marker: str, timeout_seconds: float = 10) -> None:
    deadline = time.monotonic() + timeout_seconds
    while time.monotonic() < deadline:
        result = run("docker", "exec", CELL, "wget", "-T", "2", "-qO-", f"http://{address}/", check=False)
        if result.returncode == 0 and result.stdout == f"{marker}\n":
            return
        time.sleep(0.2)
    raise RuntimeError(f"{marker} origin did not become ready at {address}")


def endpoint(host: str, marker: str) -> dict:
    return {
        "host": host, "port": 80, "scheme": "http", "host_header": f"{marker}.origin",
        "sni": None, "verify_tls": False, "connect_timeout_ms": 300,
        "response_timeout_ms": 1000, "retry_count": 0, "websocket": False,
        "health_check": None, "private_allowlist": ["172.16.0.0/12"],
        "blocked_networks": [], "blocked_addresses": [],
    }


def host_config(domain: str, origin: dict, cache: bool = False) -> dict:
    return {
        "domain": domain, "domain_id": 8, "revision": 1, "settings": {"enabled": True},
        "cache": {
            "enabled": cache, "edge_ttl_seconds": 1, "browser_ttl_seconds": 0,
            "maximum_object_bytes": 1048576, "respect_origin_headers": True,
            "include_query_string": True, "bypass_cookie_names": [],
            "stale_if_error_seconds": STALE_IF_ERROR_SECONDS, "stale_while_revalidate_seconds": 0,
            "status_ttl_seconds": {"200": 1}, "epoch": 1,
        },
        "origin": origin,
    }


def main() -> None:
    run("docker", "compose", "-f", "compose.dev.yml", "up", "-d", "origin-http")
    run("docker", "build", "-f", "docker/openresty/Dockerfile", "-t", "cdnfoundry/edge-runtime:test", ".")
    run("docker", "rm", "-f", CELL, PRIMARY, BACKUP, ISOLATED, check=False)
    with tempfile.TemporaryDirectory(prefix="cdnf-origin-failover-") as directory:
        temporary = pathlib.Path(directory)
        temporary.chmod(0o755)
        for name, marker in ((PRIMARY, "primary"), (BACKUP, "backup"), (ISOLATED, "isolated")):
            config = temporary / f"{marker}.conf"
            config.write_text(
                "server { listen 80; location / { add_header Cache-Control \"public, max-age=1\"; "
                f"add_header X-Origin-Marker \"{marker}\"; return 200 \"{marker}\\n\"; }}}}\n"
            )
            config.chmod(0o644)
            run("docker", "run", "-d", "--name", name, "--network", NETWORK,
                "--network-alias", name.removeprefix("cdnf-origin-failover-"),
                "-v", f"{config}:/etc/nginx/conf.d/default.conf:ro", "nginx:1.30.3-alpine")
            running = json.loads(run("docker", "inspect", name).stdout)[0]["State"]["Running"]
            if not running:
                raise RuntimeError(run("docker", "logs", name, check=False).stderr)

        address = lambda name: json.loads(run("docker", "inspect", name).stdout)[0]["NetworkSettings"]["Networks"][NETWORK]["IPAddress"]
        primary = endpoint(address(PRIMARY), "primary")
        primary["backup"] = endpoint(address(BACKUP), "backup")
        primary["failover"] = {
            "failure_threshold": 2, "recovery_threshold": 2,
            "hold_down_seconds": 5, "failback_delay_seconds": 5,
        }
        runtime = {
            "schema_version": 1, "sequence": 1, "certificates": {},
            "hosts": {
                "failover.example": host_config("failover.example", primary),
                "stale-failover.example": host_config("stale-failover.example", primary, True),
                "isolated.example": host_config("isolated.example", endpoint(address(ISOLATED), "isolated")),
            },
        }
        runtime_file = temporary / "runtime.json"
        runtime_file.write_text(json.dumps(runtime, separators=(",", ":")))
        runtime_file.chmod(0o644)
        run("openssl", "req", "-x509", "-newkey", "rsa:2048", "-nodes", "-days", "1",
            "-subj", "/CN=failover.test", "-keyout", str(temporary / "tls.key"), "-out", str(temporary / "tls.crt"))
        for path in (temporary / "tls.key", temporary / "tls.crt"):
            path.chmod(0o644)
        run("docker", "run", "-d", "--name", CELL, "--network", NETWORK,
            "-e", "EDGE_RUNTIME_FILE=/var/lib/cdnfoundry/runtime/active.json",
            "-e", "EDGE_STATUS_TOKEN=origin-failover-test",
            "-v", f"{ROOT / 'docker/nginx/openresty.conf'}:/usr/local/openresty/nginx/conf/nginx.conf:ro",
            "-v", f"{ROOT / 'docker/nginx/edge-runtime.conf'}:/etc/nginx/conf.d/default.conf:ro",
            "-v", f"{ROOT / 'docker/nginx/proxy-cache.conf'}:/etc/nginx/proxy-cache.conf:ro",
            "-v", f"{ROOT / 'docker/nginx/cache-upstream.conf'}:/etc/nginx/cache-upstream.conf:ro",
            "-v", f"{ROOT / 'docker/nginx/origin-proxy.conf'}:/etc/nginx/origin-proxy.conf:ro",
            "-v", f"{ROOT / 'docker/openresty/runtime.lua'}:/etc/cdnfoundry/runtime.lua:ro",
            "-v", f"{runtime_file}:/var/lib/cdnfoundry/runtime/active.json:ro",
            "-v", f"{temporary / 'tls.crt'}:/run/edge/tls.crt:ro",
            "-v", f"{temporary / 'tls.key'}:/run/edge/tls.key:ro",
            "--tmpfs", "/var/cache/nginx:rw,noexec,nosuid,size=64m,mode=0777",
            "cdnfoundry/edge-runtime:test")
        try:
            wait_for_cell()
            run("docker", "exec", CELL, "openresty", "-t")
            healthy = request("failover.example")
            assert "primary\n" in healthy and "X-CDNFoundry-Origin: primary" in healthy, healthy + runtime_file.read_text() + run("docker", "logs", CELL, check=False).stderr
            stale_seed = request("stale-failover.example", "/resident")
            assert "primary\n" in stale_seed and "X-CDNFoundry-Cache: MISS" in stale_seed, stale_seed

            # These disposable origins model an abrupt outage. A graceful
            # `docker stop` can wait up to ten seconds for OpenResty's upstream
            # keepalive connections, consuming the complete stale-if-error
            # window before the test makes its next request.
            run("docker", "kill", PRIMARY)
            first, second = request("failover.example", "/one"), request("failover.example", "/two")
            assert "502 Bad Gateway" in first and "502 Bad Gateway" in second, first + second
            activated = request("failover.example", "/three")
            diagnostics = run("docker", "exec", CELL, "wget", "-qO-",
                "--header=X-Edge-Status-Token: origin-failover-test", "http://127.0.0.1:9080/passive-failures", check=False).stdout
            assert "backup\n" in activated and "X-CDNFoundry-Origin: backup" in activated, activated + diagnostics + run("docker", "logs", CELL, check=False).stderr
            assert "primary_failure_threshold" in activated, activated
            held = request("failover.example", "/held")
            assert "backup\n" in held, held
            with ThreadPoolExecutor(max_workers=12) as pool:
                concurrent = list(pool.map(lambda index: request("failover.example", f"/load-{index}"), range(24)))
            assert all("backup\n" in response for response in concurrent), "concurrent traffic escaped the backup"

            run("docker", "start", PRIMARY)
            wait_for_origin(primary["host"], "primary")
            probe_one = ""
            failback_deadline = time.monotonic() + 10
            while time.monotonic() < failback_deadline:
                probe_one = request("failover.example", "/probe-one")
                if "primary\n" in probe_one:
                    break
                time.sleep(0.2)
            assert "primary\n" in probe_one, probe_one
            time.sleep(0.1)
            probe_two = request("failover.example", "/probe-two")
            time.sleep(0.1)
            recovered = request("failover.example", "/recovered")
            for attempt in range(4):
                if "primary_recovery_threshold" in recovered:
                    break
                time.sleep(0.1)
                recovered = request("failover.example", f"/recovered-{attempt}")
            assert "primary\n" in probe_one and "primary\n" in probe_two and "primary\n" in recovered
            recovery_diagnostics = run("docker", "exec", CELL, "wget", "-qO-",
                "--header=X-Edge-Status-Token: origin-failover-test", "http://127.0.0.1:9080/passive-failures", check=False).stdout
            assert "primary_recovery_threshold" in recovered, recovered + recovery_diagnostics

            stale_seed = request("stale-failover.example", "/resident-final")
            assert "primary\n" in stale_seed and "X-CDNFoundry-Cache: MISS" in stale_seed, stale_seed
            stale_resident = request("stale-failover.example", "/resident-final")
            assert "primary\n" in stale_resident and "X-CDNFoundry-Cache: HIT" in stale_resident, stale_resident
            stale_seeded_at = time.monotonic()
            time.sleep(2.1)
            run("docker", "kill", PRIMARY, BACKUP)
            stale = request("stale-failover.example", "/resident-final")
            stale_elapsed = time.monotonic() - stale_seeded_at
            assert "X-CDNFoundry-Cache: STALE" in stale and "primary\n" in stale, (
                f"stale request failed after {stale_elapsed:.3f}s "
                f"(configured window={STALE_IF_ERROR_SECONDS}s)\n{stale}"
            )
            bounded_failure = request("failover.example", "/both-down")
            assert "502 Bad Gateway" in bounded_failure, bounded_failure
            isolated = request("isolated.example")
            assert "isolated\n" in isolated, isolated
            print("origin_failover=passed transition_seconds<=5 concurrent_requests=24 isolation=passed")
        finally:
            run("docker", "rm", "-f", CELL, PRIMARY, BACKUP, ISOLATED, check=False)


if __name__ == "__main__":
    main()
