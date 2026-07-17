#!/usr/bin/env python3
"""Real OpenResty Phase 4 HTTP runtime qualification; no browser automation."""

import json
import os
import pathlib
import subprocess
import tempfile
import time

ROOT = pathlib.Path(__file__).resolve().parents[2]
NAME = "cdnf-phase4-runtime-e2e"


def run(*args: str, check: bool = True) -> subprocess.CompletedProcess[str]:
    return subprocess.run(args, cwd=ROOT, check=check, text=True, capture_output=True)


def state(hosts: dict[str, str], sequence: int) -> dict:
    return {"schema_version": 1, "sequence": sequence, "hosts": {
        hostname: {"domain": hostname, "revision": sequence, "settings": {"enabled": True}, "origin": {
            "host": "origin-http", "port": 80, "scheme": "http", "host_header": origin_host,
            "sni": None, "verify_tls": False, "connect_timeout_ms": 1000,
            "response_timeout_ms": 5000, "retry_count": 0, "websocket": False,
            "health_check": None, "private_allowlist": ["172.16.0.0/12"],
        }} for hostname, origin_host in hosts.items()
    }}


def request(host: str) -> subprocess.CompletedProcess[str]:
    return run("docker", "exec", NAME, "wget", "-S", "-O-", f"--header=Host: {host}", "http://127.0.0.1:8080/", check=False)


def wait_for(host: str) -> subprocess.CompletedProcess[str]:
    result = request(host)
    for _ in range(9):
        if result.returncode == 0:
            return result
        time.sleep(0.5)
        result = request(host)
    return result


def main() -> None:
    run("docker", "compose", "-f", "compose.dev.yml", "up", "-d", "origin-http")
    with tempfile.TemporaryDirectory(prefix="cdnf-phase4-") as directory:
        os.chmod(directory, 0o755)
        runtime = pathlib.Path(directory) / "active.json"
        runtime.write_text(json.dumps(state({"runtime.example": "origin-one.example"}, 1), separators=(",", ":")))
        runtime.chmod(0o644)
        run("docker", "run", "-d", "--rm", "--name", NAME, "--network", "cdnfoundry-dev_edge",
            "-v", f"{ROOT / 'docker/nginx/edge-runtime.conf'}:/etc/nginx/conf.d/default.conf:ro",
            "-v", f"{ROOT / 'docker/openresty/runtime.lua'}:/etc/cdnfoundry/runtime.lua:ro",
            "-v", f"{directory}:/var/lib/cdnfoundry/runtime:ro", "openresty/openresty:1.31.1.1-0-alpine")
        try:
            run("docker", "exec", NAME, "openresty", "-t")
            known = wait_for("runtime.example")
            assert known.returncode == 0 and '"host":"origin-one.example"' in known.stdout, known.stderr
            unknown = request("unknown.example")
            assert "421 Misdirected Request" in unknown.stderr, unknown.stderr
            pid = run("docker", "exec", NAME, "cat", "/usr/local/openresty/nginx/logs/nginx.pid").stdout.strip()
            runtime.write_text(json.dumps(state({"runtime.example": "origin-one.example", "hot.example": "origin-two.example"}, 2), separators=(",", ":")))
            hot = wait_for("hot.example")
            assert hot.returncode == 0 and '"host":"origin-two.example"' in hot.stdout, hot.stderr
            assert run("docker", "exec", NAME, "cat", "/usr/local/openresty/nginx/logs/nginx.pid").stdout.strip() == pid
        finally:
            run("docker", "stop", NAME, check=False)
    print("Phase 4 OpenResty runtime qualification passed.")


if __name__ == "__main__":
    main()
