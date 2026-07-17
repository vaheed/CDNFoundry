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
TLS_NAME = "cdnf-phase4-tls-origin-e2e"
QUARANTINE_NAME = "cdnf-phase4-quarantine-e2e"


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


def add_https_origin(runtime_state: dict, hostname: str, sni: str) -> None:
    runtime_state["hosts"][hostname] = {
        "domain": hostname,
        "revision": runtime_state["sequence"],
        "settings": {"enabled": True},
        "origin": {
            "host": "tls-origin", "port": 8443, "scheme": "https",
            "host_header": "tls-origin", "sni": sni, "verify_tls": True,
            "connect_timeout_ms": 1000, "response_timeout_ms": 5000,
            "retry_count": 0, "websocket": False, "health_check": None,
            "private_allowlist": ["172.16.0.0/12"],
        },
    }


def request(host: str, container: str = NAME) -> subprocess.CompletedProcess[str]:
    return run("docker", "exec", container, "wget", "-S", "-O-", f"--header=Host: {host}", "http://127.0.0.1:8080/", check=False)


def raw_request(payload: str) -> str:
    return run("docker", "exec", NAME, "sh", "-c", "printf '%s' \"$1\" | nc 127.0.0.1 8080", "sh", payload, check=False).stdout


def wait_for(host: str, container: str = NAME) -> subprocess.CompletedProcess[str]:
    result = request(host, container)
    for _ in range(9):
        if result.returncode == 0:
            return result
        time.sleep(0.5)
        result = request(host, container)
    return result


def main() -> None:
    run("docker", "compose", "-f", "compose.dev.yml", "up", "-d", "origin-http")
    with tempfile.TemporaryDirectory(prefix="cdnf-phase4-") as directory:
        os.chmod(directory, 0o755)
        temporary = pathlib.Path(directory)
        run("openssl", "req", "-x509", "-newkey", "rsa:2048", "-nodes", "-days", "1",
            "-subj", "/CN=CDNFoundry Phase 4 Test CA", "-keyout", str(temporary / "ca.key"),
            "-out", str(temporary / "ca.crt"))
        run("openssl", "req", "-newkey", "rsa:2048", "-nodes", "-subj", "/CN=tls-origin",
            "-addext", "subjectAltName=DNS:tls-origin", "-keyout", str(temporary / "tls.key"),
            "-out", str(temporary / "tls.csr"))
        run("openssl", "x509", "-req", "-in", str(temporary / "tls.csr"), "-CA", str(temporary / "ca.crt"),
            "-CAkey", str(temporary / "ca.key"), "-CAcreateserial", "-days", "1", "-copy_extensions", "copy",
            "-out", str(temporary / "tls.crt"))
        origin_config = temporary / "origin.conf"
        origin_config.write_text("server { listen 8443 ssl; ssl_certificate /run/tls/tls.crt; ssl_certificate_key /run/tls/tls.key; location / { default_type application/json; return 200 '{\"tls\":true,\"host\":\"$host\",\"forwarded\":\"$http_forwarded\",\"xff\":\"$http_x_forwarded_for\"}'; } }\n")
        for path in (temporary / "ca.crt", temporary / "tls.key", temporary / "tls.crt", origin_config):
            path.chmod(0o644)
        run("docker", "run", "-d", "--rm", "--name", TLS_NAME, "--network", "cdnfoundry-dev_edge",
            "--network-alias", "tls-origin", "-v", f"{origin_config}:/etc/nginx/conf.d/default.conf:ro",
            "-v", f"{directory}:/run/tls:ro", "nginx:1.30.3-alpine")
        runtime = pathlib.Path(directory) / "shared-default.json"
        initial = state({"runtime.example": "origin-one.example"}, 1)
        add_https_origin(initial, "tls.example", "tls-origin")
        add_https_origin(initial, "bad-tls.example", "wrong-origin")
        initial["hosts"]["blocked.example"] = initial["hosts"]["runtime.example"] | {
            "origin": initial["hosts"]["runtime.example"]["origin"] | {"host": "127.0.0.1", "private_allowlist": []},
        }
        runtime.write_text(json.dumps(initial, separators=(",", ":")))
        runtime.chmod(0o644)
        quarantine_runtime = pathlib.Path(directory) / "quarantine-default.json"
        quarantine_runtime.write_text(json.dumps(state({"quarantine.example": "quarantine-origin.example"}, 1), separators=(",", ":")))
        quarantine_runtime.chmod(0o644)
        run("docker", "run", "-d", "--rm", "--name", NAME, "--network", "cdnfoundry-dev_edge",
            "-e", "EDGE_RUNTIME_FILE=/var/lib/cdnfoundry/runtime/shared-default.json",
            "-v", f"{ROOT / 'docker/nginx/openresty.conf'}:/usr/local/openresty/nginx/conf/nginx.conf:ro",
            "-v", f"{ROOT / 'docker/nginx/edge-runtime.conf'}:/etc/nginx/conf.d/default.conf:ro",
            "-v", f"{ROOT / 'docker/openresty/runtime.lua'}:/etc/cdnfoundry/runtime.lua:ro",
            "-v", f"{temporary / 'ca.crt'}:/etc/ssl/certs/ca-certificates.crt:ro",
            "-v", f"{directory}:/var/lib/cdnfoundry/runtime:ro", "openresty/openresty:1.31.1.1-0-alpine")
        run("docker", "run", "-d", "--rm", "--name", QUARANTINE_NAME, "--network", "cdnfoundry-dev_edge",
            "-e", "EDGE_RUNTIME_FILE=/var/lib/cdnfoundry/runtime/quarantine-default.json",
            "-v", f"{ROOT / 'docker/nginx/openresty.conf'}:/usr/local/openresty/nginx/conf/nginx.conf:ro",
            "-v", f"{ROOT / 'docker/nginx/edge-runtime.conf'}:/etc/nginx/conf.d/default.conf:ro",
            "-v", f"{ROOT / 'docker/openresty/runtime.lua'}:/etc/cdnfoundry/runtime.lua:ro",
            "-v", f"{temporary / 'ca.crt'}:/etc/ssl/certs/ca-certificates.crt:ro",
            "-v", f"{directory}:/var/lib/cdnfoundry/runtime:ro", "openresty/openresty:1.31.1.1-0-alpine")
        try:
            run("docker", "exec", NAME, "openresty", "-t")
            known = wait_for("runtime.example")
            assert known.returncode == 0 and '"host":"origin-one.example"' in known.stdout, known.stderr
            quarantine = wait_for("quarantine.example", QUARANTINE_NAME)
            assert quarantine.returncode == 0 and '"host":"quarantine-origin.example"' in quarantine.stdout, quarantine.stderr
            assert "421 Misdirected Request" in request("runtime.example", QUARANTINE_NAME).stderr
            assert "421 Misdirected Request" in request("quarantine.example").stderr
            secure = wait_for("tls.example")
            assert secure.returncode == 0 and '"tls":true' in secure.stdout and '"host":"tls-origin"' in secure.stdout, secure.stderr
            untrusted = run("docker", "exec", NAME, "wget", "-q", "-O-", "--header=Host: tls.example",
                "--header=Forwarded: for=evil", "--header=X-Forwarded-For: evil", "http://127.0.0.1:8080/")
            assert "evil" not in untrusted.stdout and '"forwarded":"for=' in untrusted.stdout, untrusted.stdout
            bad_tls = request("bad-tls.example")
            assert "502 Bad Gateway" in bad_tls.stderr, bad_tls.stderr
            blocked = request("blocked.example")
            assert "502 Bad Gateway" in blocked.stderr, blocked.stderr
            unknown = request("unknown.example")
            assert "421 Misdirected Request" in unknown.stderr, unknown.stderr
            ambiguous = raw_request("POST / HTTP/1.1\r\nHost: runtime.example\r\nContent-Length: 4\r\nTransfer-Encoding: chunked\r\n\r\n0\r\n\r\n")
            assert " 400 " in ambiguous, ambiguous
            pid = run("docker", "exec", NAME, "cat", "/usr/local/openresty/nginx/logs/nginx.pid").stdout.strip()
            scaled_hosts = {f"scale-{index}.example": "origin-scale.example" for index in range(2000)}
            scaled_hosts.update({"runtime.example": "origin-one.example", "hot.example": "origin-two.example", "disabled.example": "disabled.example"})
            updated = state(scaled_hosts, 2)
            updated["hosts"]["disabled.example"]["settings"]["enabled"] = False
            runtime.write_text(json.dumps(updated, separators=(",", ":")))
            hot = wait_for("hot.example")
            assert hot.returncode == 0 and '"host":"origin-two.example"' in hot.stdout, hot.stderr
            assert request("scale-1999.example").returncode == 0
            assert "503 Service Temporarily Unavailable" in request("disabled.example").stderr
            assert "421 Misdirected Request" in request("tls.example").stderr
            assert run("docker", "exec", NAME, "cat", "/usr/local/openresty/nginx/logs/nginx.pid").stdout.strip() == pid
            run("docker", "stop", QUARANTINE_NAME)
            assert request("runtime.example").returncode == 0
            runtime.write_text('{"invalid"')
            time.sleep(1.5)
            assert request("runtime.example").returncode == 0
        finally:
            run("docker", "stop", NAME, check=False)
            run("docker", "stop", QUARANTINE_NAME, check=False)
            run("docker", "stop", TLS_NAME, check=False)
    print("Phase 4 OpenResty runtime qualification passed.")


if __name__ == "__main__":
    main()
