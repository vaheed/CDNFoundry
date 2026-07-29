#!/usr/bin/env python3
"""Real managed-WAF corpus, bounds, isolation, and telemetry qualification."""

import json
import os
import pathlib
import subprocess
import tempfile
from concurrent.futures import ThreadPoolExecutor

ROOT = pathlib.Path(__file__).resolve().parents[2]
IMAGE = os.environ.get("CDNF_WAF_IMAGE", "cdnfoundry/edge-runtime:phase9-qualification")
CELL = "cdnf-phase9-waf"
ORIGIN = "cdnf-phase9-origin"
NETWORK = os.environ.get("CDNF_EDGE_NETWORK", "cdnfoundry-dev_edge")


def run(*args: str, check: bool = True, input_text: str | None = None) -> subprocess.CompletedProcess[str]:
    result = subprocess.run(args, cwd=ROOT, check=False, text=True, capture_output=True, input=input_text)
    if check and result.returncode:
        raise RuntimeError(f"command failed: {' '.join(args)}\n{result.stdout}\n{result.stderr}")
    return result


def request(host: str, path: str = "/", body: str | None = None) -> str:
    command = ["docker", "exec"]
    if body is not None:
        command.append("-i")
    command += [CELL, "wget", "-T", "5", "-S", "-O-", f"--header=Host: {host}"]
    if body is not None:
        command += ["--header=Content-Type: application/json", "--post-file=/dev/stdin"]
    command.append(f"http://127.0.0.1:8080{path}")
    result = run(*command, check=False, input_text=body)
    return result.stderr + result.stdout


def host(profile: str, blocking: bool, body_limit: int, origin_ip: str, exclusions: list[dict] | None = None) -> dict:
    return {
        "domain": f"{profile}.waf.test", "domain_id": {"off": 90, "monitor": 91, "balanced": 92, "strict": 93}[profile],
        "revision": 1, "settings": {"enabled": True},
        "cache": {"enabled": False, "epoch": 1},
        "compression": {"profile_name": "off", "maximum_active_requests": 0},
        "security": {
            "state": "normal", "allowed_methods": ["GET", "HEAD", "POST"], "trusted_proxy_cidrs": [],
            "limits": {"maximum_header_size": 32768, "maximum_request_body_size": 16777216,
                       "requests_per_second": 1000, "request_burst": 1000,
                       "connections_per_client": 64, "connections_per_domain": 512},
            "rules": [],
        },
        "waf": {
            "name": profile, "paranoia_level": 0 if profile == "off" else (2 if profile == "strict" else 1),
            "inbound_threshold": 3 if profile == "strict" else 5, "outbound_threshold": 4,
            "body_limit_bytes": body_limit, "blocking": blocking,
            "ruleset": "owasp-crs/4.26.0-modsecurity/3.0.14", "exclusions": exclusions or [],
        },
        "origin": {
            "host": origin_ip, "port": 80, "scheme": "http", "host_header": "waf-origin",
            "sni": None, "verify_tls": False, "connect_timeout_ms": 500, "response_timeout_ms": 2000,
            "retry_count": 0, "websocket": False, "health_check": None,
            "private_allowlist": ["172.16.0.0/12"], "blocked_networks": [], "blocked_addresses": [],
        },
    }


def main() -> None:
    run("docker", "rm", "-f", CELL, ORIGIN, check=False)
    run("docker", "run", "-d", "--name", ORIGIN, "--network", NETWORK, "nginx:1.30.3-alpine")
    origin_ip = json.loads(run("docker", "inspect", ORIGIN).stdout)[0]["NetworkSettings"]["Networks"][NETWORK]["IPAddress"]
    with tempfile.TemporaryDirectory(prefix="cdnf-phase9-waf-") as directory:
        temporary = pathlib.Path(directory)
        temporary.chmod(0o755)
        exclusion = [{"id": 7, "dimension": "parameter", "value": "skip", "rule_id": 941100, "expires_at": 4102444800}]
        hosts = {
            "off.waf.test": host("off", False, 0, origin_ip),
            "monitor.waf.test": host("monitor", False, 1048576, origin_ip),
            "balanced.waf.test": host("balanced", True, 1048576, origin_ip, exclusion),
            "strict.waf.test": host("strict", True, 262144, origin_ip),
        }
        runtime = temporary / "runtime.json"
        runtime.write_text(json.dumps({"schema_version": 1, "sequence": 1, "certificates": {}, "hosts": hosts}))
        runtime.chmod(0o644)
        run("openssl", "req", "-x509", "-newkey", "rsa:2048", "-nodes", "-days", "1",
            "-subj", "/CN=waf.test", "-keyout", str(temporary / "tls.key"), "-out", str(temporary / "tls.crt"))
        for item in (temporary / "tls.key", temporary / "tls.crt"):
            item.chmod(0o644)
        run("docker", "run", "-d", "--name", CELL, "--network", NETWORK,
            "-e", "EDGE_RUNTIME_FILE=/var/lib/cdnfoundry/runtime/active.json", "-e", "EDGE_STATUS_TOKEN=phase9",
            "-v", f"{runtime}:/var/lib/cdnfoundry/runtime/active.json:ro",
            "-v", f"{temporary / 'tls.crt'}:/run/edge/tls.crt:ro", "-v", f"{temporary / 'tls.key'}:/run/edge/tls.key:ro",
            "--tmpfs", "/var/cache/nginx:rw,noexec,nosuid,size=64m,mode=0777", IMAGE)
        try:
            configuration = run("docker", "exec", CELL, "openresty", "-t", check=False)
            if configuration.returncode:
                logs = run("docker", "logs", CELL, check=False)
                raise RuntimeError(
                    f"edge runtime configuration failed\n{configuration.stdout}{configuration.stderr}"
                    f"{logs.stdout}{logs.stderr}"
                )
            attack = "/?q=%3Cscript%3Ealert(1)%3C/script%3E"
            assert "200 OK" in request("off.waf.test", attack)
            monitor = request("monitor.waf.test", attack)
            assert "200 OK" in monitor, monitor
            blocked = request("balanced.waf.test", attack)
            assert "403 Forbidden" in blocked, blocked
            assert "403 Forbidden" in request("strict.waf.test", "/?id=1%20union%20select%20password")
            excluded = request("balanced.waf.test", "/?skip=1&q=%3Cscript%3E")
            assert "200 OK" in excluded, excluded
            assert "403 Forbidden" in request("balanced.waf.test", "/", '{"broken":')
            assert "413 Request Entity Too Large" in request("strict.waf.test", "/", "x" * 262145)

            with ThreadPoolExecutor(max_workers=24) as executor:
                waf_results = list(executor.map(lambda _: request("balanced.waf.test", attack), range(48)))
                healthy_results = list(executor.map(lambda _: request("off.waf.test", "/"), range(48)))
            assert all("403 Forbidden" in result for result in waf_results)
            assert all("200 OK" in result for result in healthy_results)

            logs = run("docker", "logs", CELL).stdout + run("docker", "logs", CELL).stderr
            assert '"waf_profile":"monitor"' in logs and '"waf_action":"detect"' in logs
            assert '"waf_rule_id":941100' in logs and '"waf_action":"block"' in logs
            assert '"security_reason":"waf_request_blocked"' in logs
            assert '"waf_exclusion_id":7' in logs and '"waf_body_limit":"exceeded"' in logs
            leaked = [line for line in logs.splitlines() if "alert(1)" in line or '{"broken":' in line]
            assert not leaked, "\n".join(leaked[:5])
        finally:
            run("docker", "rm", "-f", CELL, ORIGIN, check=False)
    print("phase9 managed WAF runtime qualification passed")


if __name__ == "__main__":
    main()
