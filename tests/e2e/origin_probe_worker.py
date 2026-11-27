#!/usr/bin/env python3
"""The origin probe worker as a real container with the limits Compose gives it, driven over its unix socket.

The agent image is built from the working tree and started in worker mode exactly as `compose.prod.yml` defines the service: read-only
root, no capabilities, no new privileges, 128 MB, 0.25 CPU, 128 PIDs, a small tmpfs, and a volume for the socket. A disposable nginx
origin shares its network. The script checks a healthy probe, a refused port (blocked_destination), an expired task, a task of another
type, a burst of concurrent probes bounded by the worker's slots, and records the worker's memory and PIDs after the burst.
"""

import json
import pathlib
import subprocess
import sys
import threading
import time

ROOT = pathlib.Path(__file__).resolve().parents[2]
IMAGE, NETWORK, VOLUME, WORKER, ORIGIN = "cdnfoundry/edge-agent:probe-worker", "probe-e2e-net", "probe-e2e", "probe-e2e-worker", "probe-e2e-origin"


def run(*command: str, check: bool = True) -> subprocess.CompletedProcess:
    done = subprocess.run(command, capture_output=True, text=True)
    if check and done.returncode:
        raise SystemExit(f"{' '.join(command)}\n{done.stderr}")
    return done


def call(socket: str, task: dict) -> tuple[str, dict | str]:
    out = run("curl", "-s", "--unix-socket", socket, "-X", "POST", "http://worker/probe", "-d", json.dumps(task), "-w", "\n%{http_code}", check=False).stdout
    body, _, code = out.rpartition("\n")
    try:
        return code, json.loads(body)
    except ValueError:
        return code, body


def main() -> None:
    run("docker", "build", "-q", "-t", IMAGE, str(ROOT / "edge-agent"))
    for name in (WORKER, ORIGIN):
        run("docker", "rm", "-f", name, check=False)
    run("docker", "network", "rm", NETWORK, check=False)
    run("docker", "volume", "rm", "-f", VOLUME, check=False)
    run("docker", "network", "create", NETWORK)
    try:
        run("docker", "run", "-d", "--name", WORKER, "--network", NETWORK, "--read-only", "--cap-drop", "ALL", "--security-opt", "no-new-privileges",
            "--memory", "128m", "--cpus", "0.25", "--pids-limit", "128", "--tmpfs", "/tmp:size=8m", "-e", "EDGE_PROBE_SOCKET=/run/probe/probe.sock",
            "-v", f"{VOLUME}:/run/probe", IMAGE, "--origin-probe-worker")
        run("docker", "run", "-d", "--name", ORIGIN, "--network", NETWORK, "nginx:1.30.3-alpine")
        origin_ip = run("docker", "inspect", "-f", f'{{{{(index .NetworkSettings.Networks "{NETWORK}").IPAddress}}}}', ORIGIN).stdout.strip()
        socket = f"/var/lib/docker/volumes/{VOLUME}/_data/probe.sock"
        deadline = time.monotonic() + 30
        while run("docker", "exec", WORKER, "/usr/local/bin/edge-agent", "--origin-probe-worker-healthcheck", check=False).returncode and time.monotonic() < deadline:
            time.sleep(1)
        task = {"id": "t1", "type": "origin_test", "payload": {"addresses": [origin_ip], "private_allowlist": ["172.16.0.0/12"], "blocked_networks": [], "probe_expires_at": int(time.time()) + 120,
                "origin": {"host": origin_ip, "scheme": "http", "host_header": "origin.example", "sni": "", "port": 80, "verify_tls": False, "connect_timeout_ms": 1000, "response_timeout_ms": 2000, "health_check": {"path": "/"}}}}

        code, result = call(socket, task)
        assert code == "200" and result["status"] == "healthy" and result["http_status"] == 200, (code, result)
        refused = json.loads(json.dumps(task))
        refused["payload"]["origin"]["port"] = 81
        assert call(socket, refused)[1]["failure_reason"] == "blocked_destination"
        expired = json.loads(json.dumps(task))
        expired["payload"]["probe_expires_at"] = int(time.time()) - 5
        assert call(socket, expired)[1]["failure_reason"] == "task_expired"
        assert call(socket, {"type": "cache_purge"})[0] == "400", "only origin tests run"

        outcomes: list[str] = []
        started = time.monotonic()
        threads = [threading.Thread(target=lambda: outcomes.append(call(socket, task)[1]["status"])) for _ in range(120)]
        [thread.start() for thread in threads]
        [thread.join() for thread in threads]
        elapsed = time.monotonic() - started
        assert outcomes.count("healthy") == 120, outcomes.count("healthy")
        stats = run("docker", "stats", "--no-stream", "--format", "{{.MemUsage}} pids={{.PIDs}}", WORKER).stdout.strip()
        print(f"120 concurrent probes healthy in {elapsed:.2f} s; worker {stats}")
        inspect = json.loads(run("docker", "inspect", WORKER).stdout)[0]["HostConfig"]
        assert inspect["ReadonlyRootfs"] and inspect["Memory"] == 128 * 1024 * 1024 and inspect["PidsLimit"] == 128 and inspect["CapDrop"] == ["ALL"], inspect
        print("e2e origin probe worker passed")
    finally:
        for name in (WORKER, ORIGIN):
            run("docker", "rm", "-f", name, check=False)
        run("docker", "volume", "rm", "-f", VOLUME, check=False)
        run("docker", "network", "rm", NETWORK, check=False)


if __name__ == "__main__":
    sys.exit(main())
