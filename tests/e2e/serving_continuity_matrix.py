#!/usr/bin/env python3
"""Serving continues while each observability and control service is stopped in turn, on a real cell.

A disposable cell runs the real runtime and nginx configuration, logs to the development stack's Vector exactly as a production cell
does, and serves one domain with a block rule from its own snapshot. For every service in the matrix the container is stopped, the
cell is driven (a proxied page, a blocked request, the public reference header, a refused method), and the service is started again
and waited for. A request that fails, answers 5xx, or loses its public header fails the run. The development stack's own containers
are stopped for seconds and restored in `finally`; no volume is touched.
"""

import http.client
import json
import pathlib
import sys
import tempfile
import time

sys.path.insert(0, str(pathlib.Path(__file__).resolve().parent))
from managed_waf import IMAGE, host, run  # noqa: E402

NETWORK = "cdnfoundry-dev_dns"
CELL, ORIGIN = "cdnf-e2e-continuity", "cdnf-e2e-continuity-origin"
MATRIX = ["vector", "clickhouse", "loki", "grafana", "prometheus", "horizon", "core"]
REQUESTS = 40


def container(service: str) -> str:
    return f"cdnfoundry-dev-{service}-1"


def get(ip: str, path: str, method: str = "GET") -> tuple[int, dict[str, str], float]:
    started = time.monotonic()
    connection = http.client.HTTPConnection(ip, 8080, timeout=10)
    connection.request(method, path, headers={"Host": "continuity.test", "Accept": "text/html"})
    response = connection.getresponse()
    response.read()
    headers = {name.lower(): value for name, value in response.getheaders()}
    connection.close()
    return response.status, headers, time.monotonic() - started


def drive(ip: str) -> dict:
    latencies = []
    for index in range(REQUESTS):
        for path, method, expected in (("/", "GET", 200), ("/blocked", "GET", 403), ("/", "PUT", 405)):
            status, headers, took = get(ip, path, method)
            assert status == expected, f"{method} {path}: expected {expected}, got {status}"
            assert len(headers.get("edge-ray", "")) == 32, f"{method} {path}: lost its reference header"
            latencies.append(took)
    latencies.sort()
    return {"requests": len(latencies), "p50_ms": round(1000 * latencies[len(latencies) // 2], 1), "p99_ms": round(1000 * latencies[int(len(latencies) * 0.99) - 1], 1), "max_ms": round(1000 * latencies[-1], 1)}


def wait_running(service: str) -> None:
    deadline = time.monotonic() + 180
    while time.monotonic() < deadline:
        state = run("docker", "inspect", "--format", "{{.State.Running}} {{if .State.Health}}{{.State.Health.Status}}{{end}}", container(service), check=False).stdout.split()
        if state and state[0] == "true" and (len(state) == 1 or state[1] in ("healthy", "")):
            return
        time.sleep(2)
    raise AssertionError(f"{service} did not come back")


def main() -> None:
    present = {name for name in MATRIX if run("docker", "inspect", container(name), check=False).returncode == 0}
    assert {"vector"} <= present, "the development stack must run (Vector is the telemetry transport)"
    run("docker", "rm", "-f", CELL, ORIGIN, check=False)
    run("docker", "run", "-d", "--name", ORIGIN, "--network", NETWORK, "nginx:1.30.3-alpine")
    origin_ip = json.loads(run("docker", "inspect", ORIGIN).stdout)[0]["NetworkSettings"]["Networks"][NETWORK]["IPAddress"]
    stopped: list[str] = []
    report: dict = {}
    with tempfile.TemporaryDirectory(prefix="cdnf-e2e-continuity-") as directory:
        temporary = pathlib.Path(directory)
        temporary.chmod(0o755)
        config = host("off", False, 0, origin_ip)
        config["domain"], config["domain_id"] = "continuity.test", 831
        config["security"]["limits"].update({"requests_per_second": 100000, "request_burst": 100000, "connections_per_client": 4096, "connections_per_domain": 100000})
        config["security"]["allowed_methods"] = ["GET", "HEAD", "POST"]
        config["security"]["rules"] = [{"id": 1, "name": None, "priority": 1, "action": "block", "mode": "enforce", "conditions": [{"field": "path", "operator": "equals", "value": "/blocked"}]}]
        config["settings"]["public_headers"] = {"enabled": True, "prefix": "edge-", "pop": "", "diagnostics": False}
        runtime = temporary / "runtime.json"
        runtime.write_text(json.dumps({"schema_version": 1, "sequence": 4, "generation_id": "e2e-continuity-1", "certificates": {}, "hosts": {"continuity.test": config}}))
        runtime.chmod(0o644)
        run("openssl", "req", "-x509", "-newkey", "rsa:2048", "-nodes", "-days", "1", "-subj", "/CN=continuity.test", "-keyout", str(temporary / "tls.key"), "-out", str(temporary / "tls.crt"))
        for item in (temporary / "tls.key", temporary / "tls.crt"):
            item.chmod(0o644)
        root = pathlib.Path(__file__).resolve().parents[2]
        mounts = {"docker/openresty/runtime.lua": "/etc/cdnfoundry/runtime.lua", "docker/nginx/openresty.conf": "/usr/local/openresty/nginx/conf/nginx.conf",
                  "docker/nginx/edge-runtime.conf": "/etc/nginx/conf.d/default.conf", "docker/nginx/proxy-cache.conf": "/etc/nginx/proxy-cache.conf",
                  "docker/nginx/cache-upstream.conf": "/etc/nginx/cache-upstream.conf"}
        arguments = []
        for source, target in mounts.items():
            arguments += ["-v", f"{root / source}:{target}:ro"]
        try:
            run("docker", "run", "-d", "--name", CELL, "--network", NETWORK, "-e", "EDGE_RUNTIME_FILE=/var/lib/cdnfoundry/runtime/active.json", "-e", "EDGE_STATUS_TOKEN=e2e",
                "-v", f"{runtime}:/var/lib/cdnfoundry/runtime/active.json:ro", "-v", f"{temporary / 'tls.crt'}:/run/edge/tls.crt:ro", "-v", f"{temporary / 'tls.key'}:/run/edge/tls.key:ro",
                *arguments, "--tmpfs", "/var/cache/nginx:rw,noexec,nosuid,size=64m,mode=0777", IMAGE)
            ip = json.loads(run("docker", "inspect", CELL).stdout)[0]["NetworkSettings"]["Networks"][NETWORK]["IPAddress"]
            deadline = time.monotonic() + 60
            while time.monotonic() < deadline:
                try:
                    get(ip, "/ready")
                    break
                except OSError:
                    time.sleep(1)
            else:
                raise AssertionError(run("docker", "logs", CELL, check=False).stderr)
            report["baseline"] = drive(ip)
            for service in [name for name in MATRIX if name in present]:
                run("docker", "stop", "-t", "5", container(service))
                stopped.append(service)
                try:
                    report[f"{service} stopped"] = drive(ip)
                finally:
                    run("docker", "start", container(service))
                    stopped.remove(service)
                wait_running(service)
            logs = run("docker", "logs", CELL, check=False)
            assert "attempt to" not in logs.stdout + logs.stderr and "[emerg]" not in logs.stderr, (logs.stderr or logs.stdout)[-800:]
        finally:
            for service in stopped:
                run("docker", "start", container(service), check=False)
            run("docker", "rm", "-f", CELL, ORIGIN, check=False)
    for name, numbers in report.items():
        print(f"{name}: {numbers}")
    print("e2e serving continuity matrix passed for:", ", ".join(name.removesuffix(" stopped") for name in report if name != "baseline"))


if __name__ == "__main__":
    main()
