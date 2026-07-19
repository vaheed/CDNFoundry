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
AGENT_NAME = "cdnf-phase4-agent-e2e"
DEDICATED_NAME = "cdnf-phase4-dedicated-e2e"
EDGE_NETWORK = os.environ.get(
    "CDNF_EDGE_NETWORK",
    f"{os.environ.get('COMPOSE_PROJECT_NAME', 'cdnfoundry-dev')}_edge",
)


def run(*args: str, check: bool = True) -> subprocess.CompletedProcess[str]:
    return subprocess.run(args, cwd=ROOT, check=check, text=True, capture_output=True)


def state(hosts: dict[str, str], sequence: int) -> dict:
    return {"schema_version": 1, "sequence": sequence, "hosts": {
        hostname: {"domain": hostname, "revision": sequence, "settings": {"enabled": True}, "origin": {
            "host": "origin-http", "port": 80, "scheme": "http", "host_header": origin_host,
            "sni": None, "verify_tls": False, "connect_timeout_ms": 1000,
            "response_timeout_ms": 5000, "retry_count": 0, "websocket": False,
            "health_check": None, "private_allowlist": ["172.16.0.0/12"],
            "blocked_networks": [], "blocked_addresses": [],
        }} for hostname, origin_host in hosts.items()
    }}


def add_https_origin(runtime_state: dict, hostname: str, sni: str, host_header: str = "tls-origin") -> None:
    runtime_state["hosts"][hostname] = {
        "domain": hostname,
        "revision": runtime_state["sequence"],
        "settings": {"enabled": True},
        "origin": {
            "host": "tls-origin", "port": 8443, "scheme": "https",
            "host_header": host_header, "sni": sni, "verify_tls": True,
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


def control(action: str, task_id: str, container: str = NAME) -> dict:
    response = run(
        "docker", "exec", container, "wget", "-q", "-O-",
        "--header=X-Edge-Status-Token: runtime-test-token",
        "--header=Content-Type: application/json",
        f"--post-data={json.dumps({'task_id': task_id, 'action': action}, separators=(',', ':'))}",
        "http://127.0.0.1:9080/control",
    )
    return json.loads(response.stdout)["data"]


def cache_purge(task_id: str, purge_type: str, keys: list[str], container: str = NAME) -> dict:
    payload = {
        "task_id": task_id, "action": "cache_purge", "domain": "runtime.example",
        "type": purge_type, "cache_epoch": 2, "cache_keys": keys,
    }
    response = run(
        "docker", "exec", container, "wget", "-q", "-O-",
        "--header=X-Edge-Status-Token: runtime-test-token",
        "--header=Content-Type: application/json",
        f"--post-data={json.dumps(payload, separators=(',', ':'))}",
        "http://127.0.0.1:9080/control",
    )
    return json.loads(response.stdout)["data"]


def worker_children(container: str, master: str) -> str:
    return run("docker", "exec", container, "cat", f"/proc/{master}/task/{master}/children").stdout.strip()


def main() -> None:
    run("docker", "compose", "-f", "compose.dev.yml", "up", "-d", "origin-http")
    run("docker", "build", "-t", "cdnfoundry/edge-agent:test", "edge-agent")
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
        origin_config.write_text("server { listen 8443 ssl; ssl_certificate /run/tls/tls.crt; ssl_certificate_key /run/tls/tls.key; location / { default_type application/json; return 200 '{\"tls\":true,\"sni\":\"$ssl_server_name\",\"host\":\"$host\",\"forwarded\":\"$http_forwarded\",\"xff\":\"$http_x_forwarded_for\"}'; } }\n")
        for path in (temporary / "ca.crt", temporary / "tls.key", temporary / "tls.crt", origin_config):
            path.chmod(0o644)
        agent_state = temporary / "agent-state"
        agent_state.mkdir(mode=0o755)
        (agent_state / "identity.json").write_text(json.dumps({
            "EdgeID": "runtime-test", "Certificate": (temporary / "tls.crt").read_text(),
            "PrivateKey": (temporary / "tls.key").read_text(), "PublicKey": "00",
        }))
        (agent_state / "identity.json").chmod(0o644)
        run("docker", "run", "-d", "--rm", "--name", TLS_NAME, "--network", EDGE_NETWORK,
            "--network-alias", "tls-origin", "-v", f"{origin_config}:/etc/nginx/conf.d/default.conf:ro",
            "-v", f"{directory}:/run/tls:ro", "nginx:1.30.3-alpine")
        runtime = pathlib.Path(directory) / "shared-default.json"
        initial = state({"runtime.example": "origin-one.example"}, 1)
        add_https_origin(initial, "tls.example", "tls-origin")
        add_https_origin(initial, "bad-tls.example", "wrong-origin", "wrong-host")
        initial["hosts"]["blocked.example"] = initial["hosts"]["runtime.example"] | {
            "origin": initial["hosts"]["runtime.example"]["origin"] | {"host": "127.0.0.1", "private_allowlist": []},
        }
        initial["hosts"]["policy-blocked.example"] = initial["hosts"]["runtime.example"] | {
            "origin": initial["hosts"]["runtime.example"]["origin"] | {"blocked_networks": ["172.16.0.0/12"]},
        }
        runtime.write_text(json.dumps(initial, separators=(",", ":")))
        runtime.chmod(0o644)
        quarantine_runtime = pathlib.Path(directory) / "quarantine-default.json"
        quarantine_runtime.write_text(json.dumps(state({"quarantine.example": "quarantine-origin.example"}, 1), separators=(",", ":")))
        quarantine_runtime.chmod(0o644)
        dedicated_runtime = pathlib.Path(directory) / "dedicated-test.json"
        dedicated_runtime.write_text(json.dumps(state({"dedicated.example": "dedicated-origin.example"}, 1), separators=(",", ":")))
        dedicated_runtime.chmod(0o644)
        run("docker", "run", "-d", "--rm", "--name", NAME, "--network", EDGE_NETWORK,
            "-e", "EDGE_RUNTIME_FILE=/var/lib/cdnfoundry/runtime/shared-default.json",
            "-e", "EDGE_STATUS_TOKEN=runtime-test-token",
            "-v", f"{ROOT / 'docker/nginx/openresty.conf'}:/usr/local/openresty/nginx/conf/nginx.conf:ro",
            "-v", f"{ROOT / 'docker/nginx/edge-runtime.conf'}:/etc/nginx/conf.d/default.conf:ro",
            "-v", f"{ROOT / 'docker/openresty/runtime.lua'}:/etc/cdnfoundry/runtime.lua:ro",
            "-v", f"{temporary / 'ca.crt'}:/etc/ssl/certs/ca-certificates.crt:ro",
            "-v", f"{temporary / 'tls.crt'}:/run/edge/tls.crt:ro",
            "-v", f"{temporary / 'tls.key'}:/run/edge/tls.key:ro",
            "-v", f"{directory}:/var/lib/cdnfoundry/runtime:ro", "openresty/openresty:1.31.1.1-0-alpine")
        run("docker", "run", "-d", "--rm", "--name", QUARANTINE_NAME, "--network", EDGE_NETWORK,
            "-e", "EDGE_RUNTIME_FILE=/var/lib/cdnfoundry/runtime/quarantine-default.json",
            "-e", "EDGE_STATUS_TOKEN=runtime-test-token",
            "-v", f"{ROOT / 'docker/nginx/openresty.conf'}:/usr/local/openresty/nginx/conf/nginx.conf:ro",
            "-v", f"{ROOT / 'docker/nginx/edge-runtime.conf'}:/etc/nginx/conf.d/default.conf:ro",
            "-v", f"{ROOT / 'docker/openresty/runtime.lua'}:/etc/cdnfoundry/runtime.lua:ro",
            "-v", f"{temporary / 'ca.crt'}:/etc/ssl/certs/ca-certificates.crt:ro",
            "-v", f"{temporary / 'tls.crt'}:/run/edge/tls.crt:ro",
            "-v", f"{temporary / 'tls.key'}:/run/edge/tls.key:ro",
            "-v", f"{directory}:/var/lib/cdnfoundry/runtime:ro", "openresty/openresty:1.31.1.1-0-alpine")
        run("docker", "run", "-d", "--rm", "--name", AGENT_NAME, "--network", EDGE_NETWORK,
            "-e", "EDGE_CONTROL_URL=http://127.0.0.1:1", "-e", "EDGE_STATE_DIR=/state",
            "-v", f"{agent_state}:/state", "cdnfoundry/edge-agent:test")
        run("docker", "run", "-d", "--rm", "--name", DEDICATED_NAME, "--network", EDGE_NETWORK,
            "-e", "EDGE_RUNTIME_FILE=/var/lib/cdnfoundry/runtime/dedicated-test.json", "-e", "EDGE_STATUS_TOKEN=runtime-test-token",
            "-v", f"{ROOT / 'docker/nginx/openresty.conf'}:/usr/local/openresty/nginx/conf/nginx.conf:ro",
            "-v", f"{ROOT / 'docker/nginx/edge-runtime.conf'}:/etc/nginx/conf.d/default.conf:ro",
            "-v", f"{ROOT / 'docker/openresty/runtime.lua'}:/etc/cdnfoundry/runtime.lua:ro",
            "-v", f"{temporary / 'ca.crt'}:/etc/ssl/certs/ca-certificates.crt:ro",
            "-v", f"{temporary / 'tls.crt'}:/run/edge/tls.crt:ro", "-v", f"{temporary / 'tls.key'}:/run/edge/tls.key:ro",
            "-v", f"{directory}:/var/lib/cdnfoundry/runtime:ro", "openresty/openresty:1.31.1.1-0-alpine")
        try:
            run("docker", "exec", NAME, "openresty", "-t")
            known = wait_for("runtime.example")
            assert known.returncode == 0 and '"host":"origin-one.example"' in known.stdout, known.stderr
            master = run("docker", "exec", NAME, "cat", "/usr/local/openresty/nginx/logs/nginx.pid").stdout.strip()
            children = worker_children(NAME, master)
            assert children, "OpenResty did not start worker processes"
            drained = control("drain", "runtime-drain-1")
            assert drained["accepted"] is True and drained["replayed"] is False, drained
            assert "503 Service Temporarily Unavailable" in request("runtime.example").stderr
            status = run("docker", "exec", NAME, "wget", "-q", "-O-", "--header=X-Edge-Status-Token: runtime-test-token", "http://127.0.0.1:9080/passive-failures")
            assert '"status":"drained"' in status.stdout, status.stdout
            assert isinstance(json.loads(status.stdout)["data"], list), status.stdout
            assert control("drain", "runtime-drain-1")["replayed"] is True
            control("undrain", "runtime-undrain-1")
            assert wait_for("runtime.example").returncode == 0
            full_purge = cache_purge("runtime-purge-all-1", "all", [])
            assert full_purge["accepted"] is True and full_purge["replayed"] is False and full_purge["applied_keys"] == 0, full_purge
            url_purge = cache_purge("runtime-purge-url-1", "urls", ["https|runtime.example|/app.css?a=1"])
            assert url_purge["accepted"] is True and url_purge["applied_keys"] == 1, url_purge
            assert cache_purge("runtime-purge-url-1", "urls", ["https|runtime.example|/app.css?a=1"])["replayed"] is True
            control("restart", "runtime-restart-1")
            for _ in range(20):
                time.sleep(0.25)
                if worker_children(NAME, master) != children:
                    break
            else:
                raise AssertionError("bounded cell restart did not replace OpenResty workers")
            assert run("docker", "exec", NAME, "cat", "/usr/local/openresty/nginx/logs/nginx.pid").stdout.strip() == master
            restarted_status = run("docker", "exec", NAME, "wget", "-q", "-O-", "--header=X-Edge-Status-Token: runtime-test-token", "http://127.0.0.1:9080/passive-failures")
            assert '"last_restart_at":' in restarted_status.stdout, restarted_status.stdout
            assert wait_for("runtime.example").returncode == 0
            tls_client = ("docker", "run", "--rm", "--network", f"container:{NAME}", "curlimages/curl:8.16.0",
                "-ksS", "--resolve", "runtime.example:8443:127.0.0.1")
            inbound_tls = run(*tls_client, "--http2", "https://runtime.example:8443/")
            assert '"host":"origin-one.example"' in inbound_tls.stdout, inbound_tls.stderr
            unknown_sni = run(*tls_client, "--connect-timeout", "2", "https://unknown.example:8443/", check=False)
            assert unknown_sni.returncode != 0, unknown_sni.stdout
            ipv6_client = run("docker", "run", "--rm", "--network", f"container:{NAME}", "alpine:3.22",
                "wget", "-q", "-O-", "--header=Host: runtime.example", "http://[::1]:8080/")
            assert '"host":"origin-one.example"' in ipv6_client.stdout, ipv6_client.stderr
            quarantine = wait_for("quarantine.example", QUARANTINE_NAME)
            assert quarantine.returncode == 0 and '"host":"quarantine-origin.example"' in quarantine.stdout, quarantine.stderr
            assert "421 Misdirected Request" in request("runtime.example", QUARANTINE_NAME).stderr
            assert "421 Misdirected Request" in request("quarantine.example").stderr
            dedicated = wait_for("dedicated.example", DEDICATED_NAME)
            assert dedicated.returncode == 0 and '"host":"dedicated-origin.example"' in dedicated.stdout, dedicated.stderr
            dedicated_ipv6 = run("docker", "run", "--rm", "--network", f"container:{DEDICATED_NAME}", "alpine:3.22",
                "wget", "-q", "-O-", "--header=Host: dedicated.example", "http://[::1]:8080/")
            assert '"host":"dedicated-origin.example"' in dedicated_ipv6.stdout, dedicated_ipv6.stderr
            quarantine_ipv6 = run("docker", "run", "--rm", "--network", f"container:{QUARANTINE_NAME}", "alpine:3.22",
                "wget", "-q", "-O-", "--header=Host: quarantine.example", "http://[::1]:8080/")
            assert '"host":"quarantine-origin.example"' in quarantine_ipv6.stdout, quarantine_ipv6.stderr
            secure = wait_for("tls.example")
            assert secure.returncode == 0 and '"tls":true' in secure.stdout and '"sni":"tls-origin"' in secure.stdout and '"host":"tls-origin"' in secure.stdout, secure.stderr
            untrusted = run("docker", "exec", NAME, "wget", "-q", "-O-", "--header=Host: tls.example",
                "--header=Forwarded: for=evil", "--header=X-Forwarded-For: evil", "http://127.0.0.1:8080/")
            assert "evil" not in untrusted.stdout and '"forwarded":"for=' in untrusted.stdout, untrusted.stdout
            bad_tls_attempts = 12
            for _ in range(bad_tls_attempts):
                bad_tls = request("bad-tls.example")
                assert "502 Bad Gateway" in bad_tls.stderr, f"{bad_tls.stderr}\n{bad_tls.stdout}"
            passive = run("docker", "exec", NAME, "wget", "-q", "-O-", "--header=X-Edge-Status-Token: runtime-test-token",
                "http://127.0.0.1:9080/passive-failures")
            passive_failures = json.loads(passive.stdout)["data"]
            bad_tls_failure = next(item for item in passive_failures if item["hostname"] == "bad-tls.example")
            assert bad_tls_failure["failure_count"] == bad_tls_attempts, bad_tls_failure
            blocked = request("blocked.example")
            assert "502 Bad Gateway" in blocked.stderr, blocked.stderr
            policy_blocked = request("policy-blocked.example")
            assert "502 Bad Gateway" in policy_blocked.stderr, policy_blocked.stderr
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
            assert run("docker", "inspect", "-f", "{{.State.Running}}", AGENT_NAME).stdout.strip() == "true"
            runtime.write_text('{"invalid"')
            time.sleep(1.5)
            assert request("runtime.example").returncode == 0
        finally:
            run("docker", "stop", NAME, check=False)
            run("docker", "stop", QUARANTINE_NAME, check=False)
            run("docker", "stop", AGENT_NAME, check=False)
            run("docker", "stop", DEDICATED_NAME, check=False)
            run("docker", "stop", TLS_NAME, check=False)
    print("Phase 4 OpenResty runtime qualification passed.")


if __name__ == "__main__":
    main()
