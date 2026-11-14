#!/usr/bin/env python3
"""Real OpenResty HTTP runtime qualification; no browser automation."""

import json
import re
import hashlib
import os
import pathlib
import subprocess
import tempfile
import time
import zlib
from concurrent.futures import ThreadPoolExecutor

ROOT = pathlib.Path(__file__).resolve().parents[2]
NAME = "cdnf-e2e-runtime-e2e"
TLS_NAME = "cdnf-e2e-tls-origin-e2e"
QUARANTINE_NAME = "cdnf-e2e-quarantine-e2e"
AGENT_NAME = "cdnf-e2e-agent-e2e"
DEDICATED_NAME = "cdnf-e2e-dedicated-e2e"
GRACEFUL_CLIENT_NAME = "cdnf-e2e-graceful-client-e2e"
COMPRESSION_CLIENT_NAME = "cdnf-compression-pressure-client-e2e"
EDGE_NETWORK = os.environ.get(
    "CDNF_EDGE_NETWORK",
    f"{os.environ.get('COMPOSE_PROJECT_NAME', 'cdnfoundry-dev')}_edge",
)


def run(*args: str, check: bool = True) -> subprocess.CompletedProcess[str]:
    result = subprocess.run(args, cwd=ROOT, check=False, text=True, capture_output=True)
    if check and result.returncode != 0:
        raise RuntimeError(f"command failed ({result.returncode}): {' '.join(args)}\n{result.stdout}\n{result.stderr}")
    return result


def state(hosts: dict[str, str], sequence: int) -> dict:
    return {"schema_version": 1, "sequence": sequence, "generation_id": f"e2e-generation-{sequence}", "certificates": {}, "hosts": {
        hostname: {"domain": hostname, "revision": sequence, "settings": {"enabled": True}, "cache": {
            "enabled": True, "edge_ttl_seconds": 3600, "browser_ttl_seconds": 300,
            "maximum_object_bytes": 104857600, "respect_origin_headers": True,
            "include_query_string": True, "ignore_cookie_names": ["session_id"],
            "stale_if_error_seconds": 60, "epoch": 1, "development_mode_until": None,
        }, "compression": {
            "profile_name": "standard", "gzip": True, "brotli": False,
            "minimum_bytes": 1024, "maximum_bytes": 10485760,
            "maximum_active_requests": 32,
        }, "origin": {
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


def add_serving_certificate(runtime_state: dict, certificate: str, private_key: str) -> None:
    runtime_state["certificates"]["runtime-test-certificate"] = {
        "id": "runtime-test-certificate", "certificate_pem": certificate, "chain_pem": "",
        "private_key_pem": private_key, "expires_at": int(time.time()) + 3600,
        "names": list(runtime_state["hosts"].keys()),
    }
    for config in runtime_state["hosts"].values():
        config["tls"] = {"mode": "custom", "certificate_id": "runtime-test-certificate"}


def request(host: str, container: str = NAME) -> subprocess.CompletedProcess[str]:
    return run("docker", "exec", container, "wget", "-S", "-O-", f"--header=Host: {host}", "http://127.0.0.1:8080/", check=False)


def request_with(host: str, path: str = "/", headers: tuple[str, ...] = (), container: str = NAME) -> subprocess.CompletedProcess[str]:
    command = ["docker", "exec", container, "wget", "-S", "-O-", f"--header=Host: {host}"]
    command.extend(f"--header={header}" for header in headers)
    command.append(f"http://127.0.0.1:8080{path}")
    return run(*command, check=False)


def response_headers(host: str, path: str = "/") -> str:
    return run(
        "docker", "run", "--rm", "--network", f"container:{NAME}", "curlimages/curl:8.16.0",
        "-sS", "--max-redirs", "0", "-D", "-", "-o", "/dev/null", "-H", f"Host: {host}",
        f"http://127.0.0.1:8080{path}", check=False,
    ).stdout


def raw_request(payload: str) -> str:
    return run("docker", "exec", NAME, "sh", "-c", "printf '%s' \"$1\" | nc 127.0.0.1 8080", "sh", payload, check=False).stdout


def wait_for(host: str, container: str = NAME) -> subprocess.CompletedProcess[str]:
    # A restarted runtime reloads persisted generations before it listens again;
    # 30 seconds is a bounded budget for that, not a fixed sleep.
    result = request(host, container)
    for _ in range(60):
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


def cache_purge(task_id: str, purge_type: str, keys: list[str], container: str = NAME, purge_id: int | None = None) -> dict:
    payload = {
        "task_id": task_id, "action": "cache_purge", "domain": "runtime.example",
        "type": purge_type, "cache_epoch": 2, "cache_keys": keys,
        "purge_id": purge_id if purge_id is not None else int(zlib.crc32(task_id.encode())) + 1,
    }
    response = run(
        "docker", "exec", container, "wget", "-q", "-O-",
        "--header=X-Edge-Status-Token: runtime-test-token",
        "--header=Content-Type: application/json",
        f"--post-data={json.dumps(payload, separators=(',', ':'))}",
        "http://127.0.0.1:9080/control",
    )
    return json.loads(response.stdout)["data"]


def compression_emergency(task_id: str, active: bool) -> dict:
    payload = {
        "task_id": task_id, "action": "emergency_mode", "active": active,
        "actions": ["disable_compression"] if active else [],
    }
    if active:
        payload["expires_at"] = int(time.time()) + 60
    response = run(
        "docker", "exec", NAME, "wget", "-q", "-O-",
        "--header=X-Edge-Status-Token: runtime-test-token",
        "--header=Content-Type: application/json",
        f"--post-data={json.dumps(payload, separators=(',', ':'))}",
        "http://127.0.0.1:9080/control",
    )
    return json.loads(response.stdout)["data"]


def security_overlay(task_id: str, sequence: int, state_name: str, limits: dict | None, container: str = NAME) -> subprocess.CompletedProcess[str]:
    payload = {"task_id": task_id, "action": "security_overlay", "domain_id": 7701, "sequence": sequence, "state": state_name}
    if limits is not None:
        payload["limits"] = limits
    return run(
        "docker", "exec", container, "wget", "-q", "-O-",
        "--header=X-Edge-Status-Token: runtime-test-token",
        "--header=Content-Type: application/json",
        f"--post-data={json.dumps(payload, separators=(',', ':'))}",
        "http://127.0.0.1:9080/control", check=False,
    )


def curl(host: str, path: str = "/", headers: tuple[str, ...] = (), method: str = "GET", data: str | None = None, container: str = NAME) -> tuple[int, str, str]:
    """One request from inside the cell's network namespace. Returns the status, the raw header block, and the body."""
    command = ["docker", "run", "--rm", "--network", f"container:{container}", "curlimages/curl:8.16.0", "-sS", "-i", "--max-redirs", "0", "-X", method, "-H", f"Host: {host}"]
    for header in headers:
        command += ["-H", header]
    if data is not None:
        command += ["--data-binary", data]
    command.append(f"http://127.0.0.1:8080{path}")
    output = run(*command, check=False).stdout
    head, _, body = re.split(r"(\r?\n\r?\n)", output, maxsplit=1) if re.search(r"\r?\n\r?\n", output) else (output, "", "")
    return int(head.split()[1]), head, body


def incident_overlay(task_id: str, sequence: int, seconds: int, challenge: bool = True, container: str = NAME, **overrides) -> subprocess.CompletedProcess[str]:
    incident = {"expires_at": int(time.time()) + seconds, "waf": {"mode": "block", "sensitivity": "high"}, "methods": ["GET", "HEAD", "OPTIONS", "POST"],
                "max_body_bytes": 1024, "challenge": challenge, "challenge_bypass_prefixes": ["/api/"], **overrides}
    payload = {"task_id": task_id, "action": "security_overlay", "domain_id": 7701, "sequence": sequence, "state": "restricted",
               "limits": {"requests_per_second": 1000, "request_burst": 1000}, "incident": incident}
    return run("docker", "exec", container, "wget", "-q", "-O-", "--header=X-Edge-Status-Token: runtime-test-token", "--header=Content-Type: application/json",
               f"--post-data={json.dumps(payload, separators=(',', ':'))}", "http://127.0.0.1:9080/control", check=False)


def solve_challenge(seed: str, bits: int = 16) -> int:
    counter = 0
    while True:
        digest = hashlib.sha256(f"{seed}:{counter}".encode()).digest()
        leading = 0
        for byte in digest:
            if byte == 0:
                leading += 8
                continue
            leading += 8 - byte.bit_length()
            break
        if leading >= bits:
            return counter
        counter += 1


def qualify_incident_profile() -> None:
    html = ("Accept: text/html",)
    assert curl("overlay.example")[0] == 200, "no profile, no challenge"
    attack = "/?q=%3Cscript%3Ealert(document.cookie)%3C%2Fscript%3E%20union%20select%20password%20from%20users"
    assert curl("overlay.example", attack)[0] == 200, "this domain's own firewall profile is off, so the payload passes"
    assert incident_overlay("incident-bad-method", 6, 60, methods=["GET", "TRACE"]).returncode != 0, "an unknown method is refused"
    assert incident_overlay("incident-bad-bypass", 6, 60, challenge_bypass_prefixes=["no-slash"]).returncode != 0
    assert incident_overlay("incident-on", 7, 300).returncode == 0

    # A page load with no token gets the challenge page; anything else with no token is refused outright.
    status, head, body = curl("overlay.example", "/", html)
    assert status == 403 and "Checking your browser" in body and "noscript" in body and 'role="status"' in body and "Content-Security-Policy" in head and "no-store" in head.lower(), (status, head)
    seed = re.search(r'const seed="([0-9a-f.]+)"', body).group(1)
    assert curl("overlay.example", "/", ("Accept: */*",))[0] == 403 and "Checking" not in curl("overlay.example", "/", ("Accept: */*",))[2]
    # Marked API paths are not challenged, and the profile's method and body limits apply.
    assert curl("overlay.example", "/api/status")[0] != 403
    assert curl("overlay.example", "/", method="PUT", data="x")[0] == 405 and curl("overlay.example", "/", method="DELETE")[0] == 405
    assert curl("overlay.example", "/", method="POST", data="a" * 2048)[0] == 413

    # A wrong answer, a forged seed, and a seed for another client are refused.
    assert curl("overlay.example", "/.cdnf/challenge", method="POST", data=f"seed={seed}&counter=1")[0] == 403
    assert curl("overlay.example", "/.cdnf/challenge", method="POST", data="seed=7701.1.16.deadbeef&counter=1")[0] == 403
    assert curl("overlay.example", "/.cdnf/challenge", ("User-Agent: Another/1",), "POST", f"seed={seed}&counter={solve_challenge(seed)}")[0] == 403, "a seed is bound to the browser that got it"

    # A correct answer gives a token; the seed cannot be replayed; the token passes until its expiry.
    answer = f"seed={seed}&counter={solve_challenge(seed)}"
    status, head, _ = curl("overlay.example", "/.cdnf/challenge", method="POST", data=answer)
    assert status == 204 and "cdnf_chal=" in head and "HttpOnly" in head and "SameSite=Lax" in head, head
    cookie = re.search(r"cdnf_chal=([0-9a-f.]+)", head).group(1)
    assert curl("overlay.example", "/.cdnf/challenge", method="POST", data=answer)[0] == 403, "a solved seed is single use"
    assert curl("overlay.example", "/", html + (f"Cookie: cdnf_chal={cookie}",))[0] == 200
    assert curl("overlay.example", "/", ("Accept: */*", f"Cookie: cdnf_chal={cookie}"))[0] == 200
    assert curl("overlay.example", attack, ("Accept: */*", f"Cookie: cdnf_chal={cookie}"))[0] == 403, "the profile turns the firewall on in blocking mode"
    # A token is bound to the domain, the client, and the browser, and its signature is checked.
    forged = cookie[:-1] + ("0" if cookie[-1] != "0" else "1")
    assert curl("overlay.example", "/", html + (f"Cookie: cdnf_chal={forged}",))[0] == 403
    assert curl("overlay.example", "/", html + ("User-Agent: Another/1", f"Cookie: cdnf_chal={cookie}"))[0] == 403
    other = cookie.split(".")
    other[0] = "7702"
    assert curl("overlay.example", "/", html + (f"Cookie: cdnf_chal={'.'.join(other)}",))[0] == 403, "a token for another domain means nothing"
    assert curl("runtime.example")[0] == 200, "the profile is scoped to its own domain"

    # A scripted flood never reaches the origin: every request is refused, and issuing challenges is itself rate limited.
    flood = run("docker", "run", "--rm", "--network", f"container:{NAME}", "curlimages/curl:8.16.0", "-s", "-w", "%{http_code}\n", "-H", "Host: overlay.example", "-H", "Accept: text/html", "-H", "User-Agent: flood/1",
                *["-o", "/dev/null", "http://127.0.0.1:8080/"] * 60, check=False).stdout.split()
    assert len(flood) == 60 and "200" not in flood and flood.count("429") > 0 and set(flood) <= {"403", "429"}, flood

    # The cell ends the profile by its own clock, with no operator action.
    assert incident_overlay("incident-short", 8, 3).returncode == 0
    assert curl("overlay.example", "/", html)[0] in (403, 429), "still refused while the profile is on"
    time.sleep(5)
    assert curl("overlay.example", "/", html)[0] == 200, "the profile expired on its own"
    # Turning it on and then off with a normal overlay restores the snapshot.
    assert incident_overlay("incident-again", 9, 300).returncode == 0 and curl("overlay.example", "/", html)[0] in (403, 429)
    assert security_overlay("incident-off", 10, "normal", None).returncode == 0 and curl("overlay.example", "/", html)[0] == 200
    # An older sequence is ignored while a newer overlay is stored; once a normal overlay removes the entry the agent, not the cell, remembers the sequence.
    assert incident_overlay("incident-newer", 12, 300).returncode == 0 and incident_overlay("incident-older", 11, 5).returncode == 0
    assert curl("overlay.example", "/", html)[0] in (403, 429), "an older overlay never replaces a newer one"
    assert security_overlay("incident-cleanup", 13, "normal", None).returncode == 0


def worker_children(container: str, master: str) -> str:
    return run("docker", "exec", container, "cat", f"/proc/{master}/task/{master}/children").stdout.strip()


def main() -> None:
    run("docker", "compose", "-f", "compose.dev.yml", "up", "-d", "--force-recreate", "origin-http")
    # CDNF_SKIP_IMAGE_BUILD reuses cdnfoundry/edge-agent:test and cdnfoundry/edge-runtime:test (tagged from existing images); the cells
    # always mount the current runtime and configuration files, so only the image layers are reused.
    if not os.environ.get("CDNF_SKIP_IMAGE_BUILD"):
        run("docker", "build", "-t", "cdnfoundry/edge-agent:test", "edge-agent")
        run("docker", "build", "-f", "docker/openresty/Dockerfile", "-t", "cdnfoundry/edge-runtime:test", ".")
    with tempfile.TemporaryDirectory(prefix="cdnf-e2e-") as directory:
        os.chmod(directory, 0o755)
        temporary = pathlib.Path(directory)
        run("openssl", "req", "-x509", "-newkey", "rsa:2048", "-nodes", "-days", "1",
            "-subj", "/CN=CDNFoundry Test CA", "-keyout", str(temporary / "ca.key"),
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
        cache_directory = pathlib.Path(directory) / "cell-cache"
        cache_directory.mkdir(mode=0o777)
        cache_directory.chmod(0o777)
        for profile in ("small", "standard", "large", "streaming"):
            profile_directory = cache_directory / "content" / profile
            profile_directory.mkdir(parents=True, mode=0o777)
            profile_directory.chmod(0o777)
        initial = state({"runtime.example": "origin-one.example"}, 1)
        initial["hosts"]["development.example"] = state({"development.example": "development-origin.example"}, 1)["hosts"]["development.example"]
        initial["hosts"]["development.example"]["cache"]["development_mode_until"] = int(time.time()) + 3600
        for cache_host in (
            "admission.example", "admission-limit.example", "origin-policy.example",
            "small-object.example", "stale.example", "no-stale.example", "single-flight.example", "overlay.example",
            "rules.example",
        ):
            initial["hosts"][cache_host] = state({cache_host: "cache-origin.example"}, 1)["hosts"][cache_host]
        initial["hosts"]["overlay.example"]["domain_id"] = 7701
        initial["hosts"]["rules.example"]["cache"].update({
            "ignore_cookie_names": ["_ga", "_ga_*"],
            "path_rules": [
                {"match": "prefix", "value": "/live/", "action": "bypass"},
                {"match": "suffix", "value": ".php", "action": "bypass"},
                {"match": "prefix", "value": "/origin-ttl", "action": "ttl", "ttl_seconds": 600},
            ],
        })
        initial["hosts"]["admission-limit.example"]["security"] = {
            "limits": {"cache_admissions_per_second": 1},
        }
        initial["hosts"]["origin-policy.example"]["cache"].update({
            "edge_ttl_seconds": 3, "browser_ttl_seconds": 7, "respect_origin_headers": False,
        })
        initial["hosts"]["small-object.example"]["cache"].update({
            "maximum_object_bytes": 1048576,
            "profile_name": "small",
            "profile": {"maximum_object_bytes": 1048576, "admissions_per_second": 20},
        })
        initial["hosts"]["stale.example"]["cache"].update({
            "edge_ttl_seconds": 1, "browser_ttl_seconds": 5, "stale_if_error_seconds": 3,
        })
        initial["hosts"]["no-stale.example"]["cache"].update({
            "edge_ttl_seconds": 1, "browser_ttl_seconds": 5, "stale_if_error_seconds": 0,
        })
        initial["hosts"]["single-flight.example"]["cache"].update({
            "edge_ttl_seconds": 1, "stale_while_revalidate_seconds": 5,
        })
        initial["hosts"]["admission.example"]["cache"].update({
            "status_ttl_seconds": {"200": 3600, "302": 20, "404": 15},
            "stale_while_revalidate_seconds": 5,
        })
        initial["hosts"]["runtime.example"]["cache"].update({
            "query_policy": "include_selected", "query_parameters": ["page"],
            "maximum_variants_per_resource": 8,
        })
        initial["hosts"]["compression.example"] = state({"compression.example": "compression-origin.example"}, 1)["hosts"]["compression.example"]
        initial["hosts"]["compression.example"]["compression"].update({
            "profile_name": "maximum_savings", "brotli": True, "maximum_active_requests": 1,
        })
        add_https_origin(initial, "tls.example", "tls-origin")
        add_https_origin(initial, "bad-tls.example", "wrong-origin", "wrong-host")
        initial["hosts"]["blocked.example"] = initial["hosts"]["runtime.example"] | {
            "origin": initial["hosts"]["runtime.example"]["origin"] | {"host": "127.0.0.1", "private_allowlist": []},
        }
        for index, address in enumerate(("0.1.2.3", "192.0.2.1", "192.88.99.1", "198.51.100.1", "203.0.113.1", "239.1.2.3", "240.0.0.1", "2001:db8::1")):
            hostname = f"reserved-{index}.example"
            initial["hosts"][hostname] = initial["hosts"]["runtime.example"] | {
                "origin": initial["hosts"]["runtime.example"]["origin"] | {"host": address, "private_allowlist": []},
            }
        initial["hosts"]["policy-blocked.example"] = initial["hosts"]["runtime.example"] | {
            "origin": initial["hosts"]["runtime.example"]["origin"] | {"blocked_networks": ["172.16.0.0/12"]},
        }
        add_serving_certificate(initial, (temporary / "tls.crt").read_text(), (temporary / "tls.key").read_text())
        runtime.write_text(json.dumps(initial, separators=(",", ":")))
        runtime.chmod(0o644)
        quarantine_runtime = pathlib.Path(directory) / "quarantine-default.json"
        quarantine_runtime.write_text(json.dumps(state({"quarantine.example": "quarantine-origin.example"}, 1), separators=(",", ":")))
        quarantine_runtime.chmod(0o644)
        dedicated_runtime = pathlib.Path(directory) / "dedicated-test.json"
        dedicated_runtime.write_text(json.dumps(state({"dedicated.example": "dedicated-origin.example"}, 1), separators=(",", ":")))
        dedicated_runtime.chmod(0o644)
        # These cells exercise serving with telemetry unavailable. Resolve the
        # configured syslog hostname locally so an unrelated Vector container is
        # not a hidden prerequisite; failed UDP delivery must not stop serving.
        run("docker", "run", "-d", "--name", NAME, "--network", EDGE_NETWORK, "--add-host", "vector:127.0.0.1",
            "-e", "EDGE_RUNTIME_FILE=/var/lib/cdnfoundry/runtime/shared-default.json",
            "-e", "EDGE_STATUS_TOKEN=runtime-test-token",
            "-v", f"{ROOT / 'docker/nginx/openresty.conf'}:/usr/local/openresty/nginx/conf/nginx.conf:ro",
            "-v", f"{ROOT / 'docker/nginx/edge-runtime.conf'}:/etc/nginx/conf.d/default.conf:ro",
            "-v", f"{ROOT / 'docker/nginx/proxy-cache.conf'}:/etc/nginx/proxy-cache.conf:ro",
            "-v", f"{ROOT / 'docker/nginx/cache-upstream.conf'}:/etc/nginx/cache-upstream.conf:ro",
            "-v", f"{ROOT / 'docker/nginx/origin-proxy.conf'}:/etc/nginx/origin-proxy.conf:ro",
            "-v", f"{ROOT / 'docker/openresty/runtime.lua'}:/etc/cdnfoundry/runtime.lua:ro",
            "-v", f"{temporary / 'ca.crt'}:/etc/ssl/certs/ca-certificates.crt:ro",
            "-v", f"{temporary / 'tls.crt'}:/run/edge/tls.crt:ro",
            "-v", f"{temporary / 'tls.key'}:/run/edge/tls.key:ro",
            "-v", f"{cache_directory}:/var/cache/nginx",
            "-v", f"{directory}:/var/lib/cdnfoundry/runtime:ro", "cdnfoundry/edge-runtime:test")
        run("docker", "run", "-d", "--rm", "--name", QUARANTINE_NAME, "--network", EDGE_NETWORK, "--add-host", "vector:127.0.0.1", "--tmpfs", "/var/cache/nginx:rw,size=64m",
            "-e", "EDGE_RUNTIME_FILE=/var/lib/cdnfoundry/runtime/quarantine-default.json",
            "-e", "EDGE_STATUS_TOKEN=runtime-test-token",
            "-v", f"{ROOT / 'docker/nginx/openresty.conf'}:/usr/local/openresty/nginx/conf/nginx.conf:ro",
            "-v", f"{ROOT / 'docker/nginx/edge-runtime.conf'}:/etc/nginx/conf.d/default.conf:ro",
            "-v", f"{ROOT / 'docker/nginx/proxy-cache.conf'}:/etc/nginx/proxy-cache.conf:ro",
            "-v", f"{ROOT / 'docker/nginx/cache-upstream.conf'}:/etc/nginx/cache-upstream.conf:ro",
            "-v", f"{ROOT / 'docker/nginx/origin-proxy.conf'}:/etc/nginx/origin-proxy.conf:ro",
            "-v", f"{ROOT / 'docker/openresty/runtime.lua'}:/etc/cdnfoundry/runtime.lua:ro",
            "-v", f"{temporary / 'ca.crt'}:/etc/ssl/certs/ca-certificates.crt:ro",
            "-v", f"{temporary / 'tls.crt'}:/run/edge/tls.crt:ro",
            "-v", f"{temporary / 'tls.key'}:/run/edge/tls.key:ro",
            "-v", f"{directory}:/var/lib/cdnfoundry/runtime:ro", "cdnfoundry/edge-runtime:test")
        run("docker", "run", "-d", "--rm", "--name", AGENT_NAME, "--network", EDGE_NETWORK,
            "-e", "EDGE_CONTROL_URL=https://127.0.0.1:1", "-e", "EDGE_STATE_DIR=/state", "-e", "EDGE_RUNTIME_DIR=/state/runtime",
            "-v", f"{agent_state}:/state", "cdnfoundry/edge-agent:test")
        run("docker", "run", "-d", "--rm", "--name", DEDICATED_NAME, "--network", EDGE_NETWORK, "--add-host", "vector:127.0.0.1", "--tmpfs", "/var/cache/nginx:rw,size=64m",
            "-e", "EDGE_RUNTIME_FILE=/var/lib/cdnfoundry/runtime/dedicated-test.json", "-e", "EDGE_STATUS_TOKEN=runtime-test-token",
            "-v", f"{ROOT / 'docker/nginx/openresty.conf'}:/usr/local/openresty/nginx/conf/nginx.conf:ro",
            "-v", f"{ROOT / 'docker/nginx/edge-runtime.conf'}:/etc/nginx/conf.d/default.conf:ro",
            "-v", f"{ROOT / 'docker/nginx/proxy-cache.conf'}:/etc/nginx/proxy-cache.conf:ro",
            "-v", f"{ROOT / 'docker/nginx/cache-upstream.conf'}:/etc/nginx/cache-upstream.conf:ro",
            "-v", f"{ROOT / 'docker/nginx/origin-proxy.conf'}:/etc/nginx/origin-proxy.conf:ro",
            "-v", f"{ROOT / 'docker/openresty/runtime.lua'}:/etc/cdnfoundry/runtime.lua:ro",
            "-v", f"{temporary / 'ca.crt'}:/etc/ssl/certs/ca-certificates.crt:ro",
            "-v", f"{temporary / 'tls.crt'}:/run/edge/tls.crt:ro", "-v", f"{temporary / 'tls.key'}:/run/edge/tls.key:ro",
            "-v", f"{directory}:/var/lib/cdnfoundry/runtime:ro", "cdnfoundry/edge-runtime:test")
        try:
            run("docker", "exec", NAME, "openresty", "-t")
            known = wait_for("runtime.example")
            runtime_logs = run("docker", "logs", NAME, check=False)
            assert known.returncode == 0 and '"host":"origin-one.example"' in known.stdout, known.stderr + runtime_logs.stdout + runtime_logs.stderr
            if os.environ.get("CDNF_ONLY_INCIDENT"):
                # A focused run of the incident profile against the real runtime, for a change that touches only that.
                qualify_incident_profile()
                print("incident profile runtime qualification passed")
                return
            assert "X-CDNF-Cache: MISS" in known.stderr, known.stderr
            cached = request("runtime.example")
            assert cached.returncode == 0 and "X-CDNF-Cache: HIT" in cached.stderr, cached.stderr
            identity_headers = run(
                "docker", "run", "--rm", "--network", f"container:{NAME}", "curlimages/curl:8.16.0",
                "-sS", "-D", "-", "-o", "/dev/null", "-H", "Host: compression.example",
                "-H", "Accept-Encoding: identity", "http://127.0.0.1:8080/compressible",
            ).stdout
            assert "X-CDNF-Cache: MISS" in identity_headers and "Content-Encoding:" not in identity_headers, identity_headers
            identity_body = run(
                "docker", "run", "--rm", "--network", f"container:{NAME}", "curlimages/curl:8.16.0",
                "-sS", "-H", "Host: compression.example", "-H", "Accept-Encoding: identity",
                "http://127.0.0.1:8080/compressible",
            ).stdout
            for encoding in ("gzip", "br"):
                encoded_headers = run(
                    "docker", "run", "--rm", "--network", f"container:{NAME}", "curlimages/curl:8.16.0",
                    "-sS", "-D", "-", "-o", "/dev/null", "-H", "Host: compression.example",
                    "-H", f"Accept-Encoding: {encoding}", "http://127.0.0.1:8080/compressible",
                ).stdout
                assert f"Content-Encoding: {encoding}" in encoded_headers, encoded_headers
                assert "X-CDNF-Cache: HIT" in encoded_headers, encoded_headers
                encoded_body = run(
                    "docker", "run", "--rm", "--network", f"container:{NAME}", "curlimages/curl:8.16.0",
                    "-sS", "--compressed", "-H", "Host: compression.example",
                    "-H", f"Accept-Encoding: {encoding}", "http://127.0.0.1:8080/compressible",
                ).stdout
                assert encoded_body == identity_body, f"{encoding} decoded body differs from identity"
            ranged_compressed = run(
                "docker", "run", "--rm", "--network", f"container:{NAME}", "curlimages/curl:8.16.0",
                "-sS", "-D", "-", "-o", "/dev/null", "-H", "Host: compression.example",
                "-H", "Accept-Encoding: br, gzip", "-H", "Range: bytes=0-127",
                "http://127.0.0.1:8080/compressible",
            ).stdout
            assert "206 Partial Content" in ranged_compressed and "Content-Encoding:" not in ranged_compressed, ranged_compressed
            pressure_client = subprocess.Popen([
                "docker", "run", "--rm", "--name", COMPRESSION_CLIENT_NAME,
                "--network", f"container:{NAME}", "curlimages/curl:8.16.0",
                "-fsS", "-o", "/dev/null", "-H", "Host: compression.example",
                "-H", "Accept-Encoding: br", "http://127.0.0.1:8080/graceful",
            ], cwd=ROOT, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True)
            time.sleep(0.75)
            pressure_headers = run(
                "docker", "run", "--rm", "--network", f"container:{NAME}", "curlimages/curl:8.16.0",
                "-sS", "-D", "-", "-o", "/dev/null", "-H", "Host: compression.example",
                "-H", "Accept-Encoding: br", "http://127.0.0.1:8080/compressible",
            ).stdout
            assert "200 OK" in pressure_headers and "Content-Encoding:" not in pressure_headers, pressure_headers
            pressure_client.terminate()
            pressure_client.communicate(timeout=10)
            assert compression_emergency("compression-emergency-on", True)["accepted"] is True
            emergency_headers = run(
                "docker", "run", "--rm", "--network", f"container:{NAME}", "curlimages/curl:8.16.0",
                "-sS", "-D", "-", "-o", "/dev/null", "-H", "Host: compression.example",
                "-H", "Accept-Encoding: br", "http://127.0.0.1:8080/compressible",
            ).stdout
            assert "200 OK" in emergency_headers and "Content-Encoding:" not in emergency_headers, emergency_headers
            assert compression_emergency("compression-emergency-off", False)["accepted"] is True
            run("docker", "restart", NAME)
            persisted = wait_for("runtime.example")
            assert "X-CDNF-Cache: HIT" in persisted.stderr, persisted.stderr
            authorized = request_with("runtime.example", headers=("Authorization: Bearer cache-bypass",))
            assert "X-CDNF-Cache: BYPASS" in authorized.stderr, authorized.stderr
            # Any cookie not on the domain's ignore list makes the request personal: it bypasses the cache.
            cookie_bypass = request_with("runtime.example", headers=("Cookie: tracking=present",))
            assert "X-CDNF-Cache: BYPASS" in cookie_bypass.stderr, cookie_bypass.stderr
            mixed_cookie = request_with("runtime.example", headers=("Cookie: session_id=present; tracking=present",))
            assert "X-CDNF-Cache: BYPASS" in mixed_cookie.stderr, mixed_cookie.stderr
            ignored_cookie = request_with("runtime.example", headers=("Cookie: session_id=present",))
            assert "X-CDNF-Cache: BYPASS" not in ignored_cookie.stderr, ignored_cookie.stderr
            # Analytics cookies on the ignore list, including a prefix entry, never make a request personal.
            for _ in range(2):
                analytics = request_with("rules.example", "/analytics", headers=("Cookie: _ga=1; _ga_ABC123=2",))
            assert "X-CDNF-Cache: HIT" in analytics.stderr, analytics.stderr
            prefix_miss = request_with("rules.example", "/analytics", headers=("Cookie: _ga=1; _gax=2",))
            assert "X-CDNF-Cache: BYPASS" in prefix_miss.stderr, prefix_miss.stderr
            # Path rules: a bypass prefix and suffix are never stored; an unmatched path caches normally.
            for path in ("/live/feed", "/index.php"):
                for _ in range(2):
                    bypassed = request_with("rules.example", path)
                assert "X-CDNF-Cache: BYPASS" in bypassed.stderr, (path, bypassed.stderr)
            for _ in range(2):
                unmatched = request_with("rules.example", "/plain")
            assert "X-CDNF-Cache: HIT" in unmatched.stderr, unmatched.stderr
            # A TTL rule replaces the origin's one-second lifetime for its path.
            request_with("rules.example", "/origin-ttl")
            time.sleep(2.5)
            ttl_rule = request_with("rules.example", "/origin-ttl")
            assert "X-CDNF-Cache: HIT" in ttl_rule.stderr and "s-maxage=600" in ttl_rule.stderr, ttl_rule.stderr
            # A bypassed response keeps the origin's cache headers; the edge never adds "public".
            assert "s-maxage" not in cookie_bypass.stderr and "public" not in cookie_bypass.stderr, cookie_bypass.stderr
            # Two sessions alternate on one URL: no response may carry the other session's cookie value.
            session_loop = (
                "for i in $(seq 1 250); do "
                "curl -sS -H 'Host: runtime.example' -H 'Cookie: sid=alpha' http://127.0.0.1:8080/echo-cookie; "
                "curl -sS -H 'Host: runtime.example' -H 'Cookie: sid=bravo' http://127.0.0.1:8080/echo-cookie; done"
            )
            sessions = run("docker", "run", "--rm", "--network", f"container:{NAME}", "--entrypoint", "sh", "curlimages/curl:8.16.0", "-c", session_loop).stdout.split("\n")
            bodies = [line for line in sessions if line]
            assert len(bodies) == 500 and all(bodies[index] == ("cookie=sid=alpha" if index % 2 == 0 else "cookie=sid=bravo") for index in range(500)), bodies[:6]
            # Repeated parameters keep the order the client sent: two objects, never one shared entry.
            for _ in range(3):
                first_order = request_with("runtime.example", "/echo-query?page=first&page=second")
            assert "query=page=first&page=second" in first_order.stdout, first_order.stdout
            assert "X-CDNF-Cache: HIT" in first_order.stderr, first_order.stderr
            second_order = request_with("runtime.example", "/echo-query?page=second&page=first")
            assert "query=page=second&page=first" in second_order.stdout, (second_order.stdout, second_order.stderr)
            assert "X-CDNF-Cache: MISS" in second_order.stderr, second_order.stderr
            development = request("development.example")
            assert "X-CDNF-Cache: BYPASS" in development.stderr, development.stderr
            query_a = request_with("runtime.example", "/asset.css?ignored=1&page=2")
            assert "X-CDNF-Cache: MISS" in query_a.stderr, query_a.stderr
            assert "X-CDNF-Cache: HIT" in request_with("runtime.example", "/asset.css?page=2&ignored=2").stderr
            assert "X-CDNF-Cache: MISS" in request_with("runtime.example", "/asset.css?page=3").stderr
            assert "max-age=300" in cached.stderr, cached.stderr
            # The admission ceiling blocks creation of additional cache entries;
            # it must never bypass a resident entry and stampede the origin.
            time.sleep(1.05 - (time.time() % 1))
            admitted = request_with("admission-limit.example", "/resident")
            resident = request_with("admission-limit.example", "/resident")
            assert "X-CDNF-Cache: MISS" in admitted.stderr, admitted.stderr
            assert "X-CDNF-Cache: HIT" in resident.stderr, resident.stderr
            for _ in range(70):
                resident = request_with("admission-limit.example", "/resident")
                assert resident.returncode == 0 and "X-CDNF-Cache: HIT" in resident.stderr, resident.stderr
            origin_container = run("docker", "compose", "-f", "compose.dev.yml", "ps", "-q", "origin-http").stdout.strip()
            def origin_fetches() -> int:
                logs = run("docker", "logs", origin_container, check=False)
                return (logs.stdout + logs.stderr).count('GET /single-flight HTTP/')

            before = origin_fetches()
            with ThreadPoolExecutor(max_workers=6) as workers:
                first_wave = list(workers.map(lambda _: request_with("single-flight.example", "/single-flight"), range(6)))
            assert all(response.returncode == 0 for response in first_wave), first_wave
            assert origin_fetches() == before + 1, "cold cache sent concurrent origin fetches"
            time.sleep(2.2)
            before = origin_fetches()
            started = time.monotonic()
            stale_refresh = request_with("single-flight.example", "/single-flight")
            assert stale_refresh.returncode == 0 and "X-CDNF-Cache: STALE" in stale_refresh.stderr, stale_refresh.stderr
            assert time.monotonic() - started < 2, "stale response waited for origin refresh"
            with ThreadPoolExecutor(max_workers=6) as workers:
                refresh_wave = list(workers.map(lambda _: request_with("single-flight.example", "/single-flight"), range(6)))
            assert all(response.returncode == 0 for response in refresh_wave), refresh_wave
            time.sleep(2.5)
            assert origin_fetches() == before + 1, "expired object triggered more than one origin refresh"
            for path in ("/set-cookie", "/private", "/no-store", "/vary-star", "/vary-language"):
                first = request_with("admission.example", path)
                second = request_with("admission.example", path)
                assert "X-CDNF-Cache: BYPASS" in first.stderr, f"{path}: {first.stderr}"
                assert "X-CDNF-Cache: BYPASS" in second.stderr, f"{path}: {second.stderr}"
            for path, expected in (("/negative", "404"), ("/redirect", "302")):
                first_headers, second_headers = response_headers("admission.example", path), response_headers("admission.example", path)
                assert expected in first_headers and "X-CDNF-Cache: MISS" in first_headers, first_headers
                assert expected in second_headers and "X-CDNF-Cache: HIT" in second_headers, second_headers
            vary_encoding = request_with("admission.example", "/vary-encoding", headers=("Accept-Encoding: gzip, br",))
            assert "X-CDNF-Cache: MISS" in vary_encoding.stderr, vary_encoding.stderr
            assert "X-CDNF-Cache: HIT" in request_with("admission.example", "/vary-encoding", headers=("Accept-Encoding: gzip",)).stderr
            ranged = request_with("admission.example", headers=("Range: bytes=0-4",))
            assert "X-CDNF-Cache: BYPASS" in ranged.stderr, ranged.stderr
            posted = run("docker", "exec", NAME, "wget", "-S", "-O-", "--post-data=body", "--header=Host: admission.example", "http://127.0.0.1:8080/", check=False)
            assert "X-CDNF-Cache: BYPASS" in posted.stderr, posted.stderr
            large_first = request_with("small-object.example", "/large-object")
            large_second = request_with("small-object.example", "/large-object")
            assert "X-CDNF-Cache: BYPASS" in large_first.stderr and "X-CDNF-Cache: BYPASS" in large_second.stderr
            request_with("small-object.example", "/profile-object")
            request_with("small-object.example", "/profile-object")
            profile_files = run("docker", "exec", NAME, "sh", "-c", "find /var/cache/nginx/content/small -type f | wc -l")
            assert int(profile_files.stdout.strip()) > 0, profile_files
            origin_ttl = request_with("admission.example", "/origin-ttl")
            assert "X-CDNF-Cache: MISS" in origin_ttl.stderr
            assert "X-CDNF-Cache: HIT" in request_with("admission.example", "/origin-ttl").stderr
            time.sleep(2.1)
            revalidated = request_with("admission.example", "/origin-ttl")
            assert "s-maxage=1" in revalidated.stderr and "X-CDNF-Cache:" in revalidated.stderr, revalidated.stderr
            ignored_origin_ttl = request_with("origin-policy.example", "/origin-ttl")
            assert "max-age=7" in ignored_origin_ttl.stderr, ignored_origin_ttl.stderr
            time.sleep(1.2)
            ignored_origin_cached = request_with("origin-policy.example", "/origin-ttl")
            assert "X-CDNF-Cache: HIT" in ignored_origin_cached.stderr, ignored_origin_cached.stderr
            stale_seed = request_with("stale.example", "/stale")
            assert "X-CDNF-Cache: MISS" in stale_seed.stderr
            no_stale_seed = request_with("no-stale.example", "/stale")
            assert "X-CDNF-Cache: MISS" in no_stale_seed.stderr
            # Nginx cache freshness is evaluated at whole-second resolution. Give
            # the one-second TTL a full additional clock tick before taking the
            # origin offline so slower CI runners cannot still observe a HIT.
            # Use an abrupt outage because graceful Nginx shutdown may drain
            # keepalive connections longer than this deliberately short window.
            time.sleep(2.2)
            run("docker", "compose", "-f", "compose.dev.yml", "kill", "origin-http")
            try:
                stale = request_with("stale.example", "/stale")
                assert stale.returncode == 0 and "X-CDNF-Cache: STALE" in stale.stderr, stale.stderr
                no_stale = request_with("no-stale.example", "/stale")
                assert no_stale.returncode != 0 and "502 Bad Gateway" in no_stale.stderr, no_stale.stderr
                time.sleep(3.2)
                expired_stale = request_with("stale.example", "/stale")
                assert expired_stale.returncode != 0 and "502 Bad Gateway" in expired_stale.stderr, expired_stale.stderr
            finally:
                # Restore the origin even when an assertion fails; a stopped origin
                # otherwise breaks every later run until someone starts it by hand.
                run("docker", "compose", "-f", "compose.dev.yml", "up", "-d", "origin-http")
            assert wait_for("runtime.example").returncode == 0
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
            after_full_purge = request("runtime.example")
            assert "X-CDNF-Cache: MISS" in after_full_purge.stderr, after_full_purge.stderr
            assert "X-CDNF-Cache: HIT" in request("runtime.example").stderr
            url_purge = cache_purge("runtime-purge-url-1", "urls", ["http|runtime.example|/"])
            assert url_purge["accepted"] is True and url_purge["applied_keys"] == 1, url_purge
            after_url_purge = request("runtime.example")
            assert "X-CDNF-Cache: MISS" in after_url_purge.stderr, after_url_purge.stderr
            assert cache_purge("runtime-purge-url-1", "urls", ["http|runtime.example|/"])["replayed"] is True
            # The state automation sets travels as an overlay: the cell enforces the stricter limit, ignores an older
            # sequence, and returns to the snapshot's limits when the overlay is removed.
            before_overlay = run("docker", "exec", NAME, "wget", "-q", "-O-", "--header=X-Edge-Status-Token: runtime-test-token", "http://127.0.0.1:9080/passive-failures")
            assert json.loads(before_overlay.stdout)["cell"]["overlays_restored"] is False, before_overlay.stdout
            assert "200 OK" in request("overlay.example").stderr
            tightened = security_overlay("overlay-1", 5, "restricted", {"requests_per_second": 0, "request_burst": 0})
            assert tightened.returncode == 0, tightened.stderr
            assert "429" in request("overlay.example").stderr
            assert "200 OK" in request("runtime.example").stderr, "an overlay is scoped to its own domain"
            assert security_overlay("overlay-old", 4, "suspected", {"requests_per_second": 1000, "request_burst": 1000}).returncode == 0
            assert "429" in request("overlay.example").stderr, "an older sequence must not loosen the limit"
            assert security_overlay("overlay-bad", 6, "banished", None).returncode != 0
            assert security_overlay("overlay-negative", 6, "restricted", {"requests_per_second": -1}).returncode != 0
            assert security_overlay("overlay-normal", 6, "normal", None).returncode == 0
            assert "200 OK" in request("overlay.example").stderr, "a normal overlay restores the snapshot limits"
            qualify_incident_profile()
            control_response = run(
                "docker", "exec", NAME, "wget", "-q", "-O-", "--header=X-Edge-Status-Token: runtime-test-token",
                "--header=Content-Type: application/json",
                f"--post-data={json.dumps({'task_id': 'overlays-restored-1', 'action': 'security_overlays_restored'}, separators=(',', ':'))}",
                "http://127.0.0.1:9080/control",
            )
            assert json.loads(control_response.stdout)["data"]["accepted"] is True
            # Encoded path characters never share a cache entry with their decoded form,
            # and a purge of the canonical key reaches only that entry.
            for suffix in ("/durable%3Fv=1", "/durable?v=1"):
                assert "X-CDNF-Cache: MISS" in request_with("runtime.example", suffix).stderr, suffix
            for suffix in ("/durable%3Fv=1", "/durable?v=1"):
                assert "X-CDNF-Cache: HIT" in request_with("runtime.example", suffix).stderr, suffix
            assert cache_purge("runtime-purge-encoded-1", "urls", ["http|runtime.example|/durable%3Fv=1"], purge_id=5001)["applied_keys"] == 1
            assert "X-CDNF-Cache: MISS" in request_with("runtime.example", "/durable%3Fv=1").stderr
            assert "X-CDNF-Cache: HIT" in request_with("runtime.example", "/durable?v=1").stderr
            # Dot segments, empty segments, and malformed escapes have no cache key.
            for suffix in ("/durable/../durable", "//durable", "/durable%zz"):
                for _ in range(2):
                    assert "X-CDNF-Cache: HIT" not in request_with("runtime.example", suffix).stderr, suffix
            # A purge survives a cell restart once the agent replays it (the agent replay is
            # covered by the Go tests; the cell must report that it was not restored).
            assert "X-CDNF-Cache: MISS" in request_with("runtime.example", "/restart-durable").stderr
            assert "X-CDNF-Cache: HIT" in request_with("runtime.example", "/restart-durable").stderr
            assert cache_purge("runtime-purge-durable-1", "urls", ["http|runtime.example|/restart-durable"], purge_id=5002)["applied_keys"] == 1
            marker = run("docker", "exec", NAME, "wget", "-q", "-O-", "--header=X-Edge-Status-Token: runtime-test-token", "http://127.0.0.1:9080/passive-failures")
            assert '"purges_restored":false' in marker.stdout, marker.stdout
            run("docker", "restart", NAME)
            assert wait_for("runtime.example").returncode == 0
            lost = run("docker", "exec", NAME, "wget", "-q", "-O-", "--header=X-Edge-Status-Token: runtime-test-token", "http://127.0.0.1:9080/passive-failures")
            assert '"purges_restored":false' in lost.stdout, lost.stdout
            replay = cache_purge("runtime-purge-durable-restore", "urls", ["http|runtime.example|/restart-durable"], purge_id=5002)
            assert replay["applied_keys"] == 1, replay
            restored = run(
                "docker", "exec", NAME, "wget", "-q", "-O-", "--header=X-Edge-Status-Token: runtime-test-token",
                "--header=Content-Type: application/json",
                f"--post-data={json.dumps({'task_id': 'purges-restored-1', 'action': 'cache_purges_restored'})}",
                "http://127.0.0.1:9080/control",
            )
            assert json.loads(restored.stdout)["data"]["accepted"] is True, restored.stdout
            assert '"purges_restored":true' in run("docker", "exec", NAME, "wget", "-q", "-O-", "--header=X-Edge-Status-Token: runtime-test-token", "http://127.0.0.1:9080/passive-failures").stdout
            assert "X-CDNF-Cache: MISS" in request_with("runtime.example", "/restart-durable").stderr
            master = run("docker", "exec", NAME, "cat", "/usr/local/openresty/nginx/logs/nginx.pid").stdout.strip()
            children = worker_children(NAME, master)
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
            assert '"host":"origin-one.example"' in inbound_tls.stdout, f"{inbound_tls.stderr}\n{inbound_tls.stdout}"
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
            for index in range(8):
                reserved = request(f"reserved-{index}.example")
                assert "502 Bad Gateway" in reserved.stderr, reserved.stderr
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
            add_serving_certificate(updated, (temporary / "tls.crt").read_text(), (temporary / "tls.key").read_text())
            updated["hosts"]["disabled.example"]["settings"]["enabled"] = False
            runtime.write_text(json.dumps(updated, separators=(",", ":")))
            hot = wait_for("hot.example")
            assert hot.returncode == 0 and '"host":"origin-two.example"' in hot.stdout, hot.stderr
            scaled = wait_for("scale-1999.example")
            assert scaled.returncode == 0, scaled.stderr
            disabled = request("disabled.example")
            assert "503 Service Temporarily Unavailable" in disabled.stderr, disabled.stderr
            assert "421 Misdirected Request" in request("tls.example").stderr
            assert run("docker", "exec", NAME, "cat", "/usr/local/openresty/nginx/logs/nginx.pid").stdout.strip() == pid
            run("docker", "stop", QUARANTINE_NAME)
            assert request("runtime.example").returncode == 0
            assert run("docker", "inspect", "-f", "{{.State.Running}}", AGENT_NAME).stdout.strip() == "true"
            runtime.write_text('{"invalid"')
            time.sleep(1.5)
            assert request("runtime.example").returncode == 0
            graceful_client = subprocess.Popen([
                "docker", "run", "--rm", "--name", GRACEFUL_CLIENT_NAME,
                "--network", f"container:{NAME}", "curlimages/curl:8.16.0",
                "-fsS", "-o", "/dev/null", "-H", "Host: runtime.example",
                "http://127.0.0.1:8080/graceful",
            ], cwd=ROOT, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True)
            connection_deadline = time.monotonic() + 10
            while time.monotonic() < connection_deadline:
                connected = run(
                    "docker", "exec", NAME, "sh", "-c",
                    "awk '$2 ~ /:1F90$/ && $4 == \"01\" { found=1 } END { exit !found }' /proc/net/tcp /proc/net/tcp6",
                    check=False,
                )
                if connected.returncode == 0:
                    break
                if graceful_client.poll() is not None:
                    break
                time.sleep(0.1)
            else:
                connected = subprocess.CompletedProcess([], 1)
            assert connected.returncode == 0, "graceful request did not connect before shutdown"
            run("docker", "kill", "--signal=QUIT", NAME)
            _, graceful_error = graceful_client.communicate(timeout=15)
            assert graceful_client.returncode == 0, graceful_error
        finally:
            if os.environ.get("CDNF_KEEP_FAILED_RUNTIME") != "1":
                run("docker", "rm", "-f", GRACEFUL_CLIENT_NAME, check=False)
                run("docker", "rm", "-f", COMPRESSION_CLIENT_NAME, check=False)
                run("docker", "rm", "-f", NAME, check=False)
                run("docker", "stop", QUARANTINE_NAME, check=False)
                run("docker", "stop", AGENT_NAME, check=False)
                run("docker", "stop", DEDICATED_NAME, check=False)
                run("docker", "stop", TLS_NAME, check=False)
                # The runtime entrypoint assigns its persistent cache tree to
                # the unprivileged edge UID. Restore traversal and removal
                # permissions before TemporaryDirectory cleans the bind mount
                # on hosts where that UID is not the CI runner.
                run(
                    "docker", "run", "--rm",
                    "-v", f"{cache_directory}:/cache",
                    "alpine:3.22", "chmod", "-R", "a+rwx", "/cache",
                    check=False,
                )
    print("OpenResty runtime qualification passed.")


if __name__ == "__main__":
    main()
