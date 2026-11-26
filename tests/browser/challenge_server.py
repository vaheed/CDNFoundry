#!/usr/bin/env python3
"""The disposable stack of the challenge browser suite: one origin and two real OpenResty cells that differ only in their
difficulty, each published on a loopback port, on a network of its own. It never touches the development stack or a volume.
It runs until it is stopped, and removes everything it started when it is."""

import json
import os
import pathlib
import signal
import subprocess
import sys
import tempfile
import time

ROOT = pathlib.Path(__file__).resolve().parents[2]
IMAGE = os.environ.get("CDNF_CHALLENGE_IMAGE", "cdnfoundry/edge-runtime:challenge-browser")
NETWORK = "cdnf-challenge-browser"
ORIGIN = "cdnf-challenge-browser-origin"
# name, loopback port, base difficulty, cap
CELLS = [("cdnf-challenge-browser-base", 18090, 16, 16), ("cdnf-challenge-browser-max", 18091, 17, 17)]
HOSTNAME = "localhost"


def run(*args: str, check: bool = True) -> subprocess.CompletedProcess[str]:
    result = subprocess.run(args, cwd=ROOT, text=True, capture_output=True, check=False)
    if check and result.returncode:
        raise RuntimeError(f"command failed: {' '.join(args)}\n{result.stdout}\n{result.stderr}")
    return result


def teardown() -> None:
    run("docker", "rm", "-f", ORIGIN, *[cell[0] for cell in CELLS], check=False)
    run("docker", "network", "rm", NETWORK, check=False)


def main() -> None:
    teardown()
    run("docker", "build", "-q", "-f", "docker/openresty/Dockerfile", "-t", IMAGE, ".")
    run("docker", "network", "create", NETWORK)
    signal.signal(signal.SIGTERM, lambda *_: (teardown(), sys.exit(0)))
    with tempfile.TemporaryDirectory(prefix="cdnf-challenge-browser-") as directory:
        temporary = pathlib.Path(directory)
        temporary.chmod(0o755)
        conf = temporary / "origin.conf"
        conf.write_text('server { listen 80; location / { default_type text/html; return 200 "<!doctype html><title>Origin</title><h1>Origin reached</h1>"; } }\n')
        conf.chmod(0o644)
        run("docker", "run", "-d", "--name", ORIGIN, "--network", NETWORK, "-v", f"{conf}:/etc/nginx/conf.d/default.conf:ro", "nginx:1.30.3-alpine")
        origin_ip = run("docker", "inspect", "-f", "{{(index .NetworkSettings.Networks \"" + NETWORK + "\").IPAddress}}", ORIGIN).stdout.strip()
        config = {
            "domain": HOSTNAME, "domain_id": 801, "revision": 1, "settings": {"enabled": True}, "cache": {"enabled": False, "epoch": 1},
            "compression": {"profile_name": "off", "maximum_active_requests": 0},
            "security": {"state": "normal", "allowed_methods": ["GET", "HEAD", "POST"], "trusted_proxy_cidrs": [],
                         "limits": {"maximum_header_size": 32768, "maximum_request_body_size": 8388608, "requests_per_second": 100000, "request_burst": 100000,
                                    "connections_per_client": 4096, "connections_per_domain": 100000},
                         "rules": [{"id": 1, "name": None, "priority": 1, "action": "challenge", "conditions": [{"field": "path", "operator": "equals", "value": "/"}]}]},
            "waf": {"name": "off", "paranoia_level": 0, "inbound_threshold": 5, "outbound_threshold": 4, "body_limit_bytes": 0, "blocking": False,
                    "ruleset": "owasp-crs/4.30.0-modsecurity/3.0.17", "exclusions": []},
            "origin": {"host": origin_ip, "port": 80, "scheme": "http", "host_header": "origin", "sni": None, "verify_tls": False, "connect_timeout_ms": 500,
                       "response_timeout_ms": 2000, "retry_count": 0, "websocket": False, "health_check": None,
                       "private_allowlist": ["172.16.0.0/12"], "blocked_networks": [], "blocked_addresses": []},
        }
        runtime = temporary / "runtime.json"
        runtime.write_text(json.dumps({"schema_version": 1, "sequence": 1, "generation_id": "challenge-browser-1", "certificates": {}, "hosts": {HOSTNAME: config}}))
        runtime.chmod(0o644)
        run("openssl", "req", "-x509", "-newkey", "rsa:2048", "-nodes", "-days", "1", "-subj", f"/CN={HOSTNAME}", "-keyout", str(temporary / "tls.key"), "-out", str(temporary / "tls.crt"))
        for item in (temporary / "tls.key", temporary / "tls.crt"):
            item.chmod(0o644)
        for name, port, bits, cap in CELLS:
            run("docker", "run", "-d", "--name", name, "--network", NETWORK, "-p", f"127.0.0.1:{port}:8080",
                "-e", "EDGE_RUNTIME_FILE=/var/lib/cdnfoundry/runtime/active.json", "-e", "EDGE_STATUS_TOKEN=browser",
                "-e", f"EDGE_CHALLENGE_BITS={bits}", "-e", f"EDGE_CHALLENGE_MAX_BITS={cap}",
                "-v", f"{runtime}:/var/lib/cdnfoundry/runtime/active.json:ro", "-v", f"{temporary / 'tls.crt'}:/run/edge/tls.crt:ro", "-v", f"{temporary / 'tls.key'}:/run/edge/tls.key:ro",
                "--tmpfs", "/var/cache/nginx:rw,noexec,nosuid,size=64m,mode=0777", IMAGE)
        deadline = time.monotonic() + 90
        for _, port, _, _ in CELLS:
            while time.monotonic() < deadline:
                ready = run("curl", "-s", "-o", "/dev/null", "-w", "%{http_code}", "-H", f"Host: {HOSTNAME}", "-H", "Accept: text/html", f"http://127.0.0.1:{port}/", check=False).stdout
                if ready == "403":
                    break
                time.sleep(1)
            else:
                raise RuntimeError(f"the cell on port {port} did not start")
        print("challenge browser stack ready", flush=True)
        try:
            while True:
                time.sleep(3600)
        finally:
            teardown()


if __name__ == "__main__":
    try:
        main()
    except KeyboardInterrupt:
        teardown()
