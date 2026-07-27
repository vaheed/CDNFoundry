#!/usr/bin/env python3
"""Real-runtime qualification for the bounded eight-slot edge inventory."""

from __future__ import annotations

import json
import pathlib
import shutil
import subprocess
import tempfile
import time
import uuid


ROOT = pathlib.Path(__file__).resolve().parents[2]
IMAGE = "cdnfoundry/edge-runtime:cell-inventory"
TOKEN = "cell-inventory-qualification-only"


def run(*args: str, check: bool = True) -> subprocess.CompletedProcess[str]:
    completed = subprocess.run(args, cwd=ROOT, check=False, text=True, capture_output=True)
    if check and completed.returncode != 0:
        raise RuntimeError(f"command failed ({completed.returncode}): {' '.join(args)}\n{completed.stdout}\n{completed.stderr}")
    return completed


def inspect(name: str) -> dict:
    return json.loads(run("docker", "inspect", name).stdout)[0]


def main() -> None:
    if shutil.which("docker") is None or shutil.which("openssl") is None:
        raise SystemExit("docker and openssl are required")

    suffix = uuid.uuid4().hex[:10]
    network = f"cdnf-cell-inventory-{suffix}"
    names = [f"cdnf-{suffix}-cell-{slot:02d}" for slot in range(1, 9)]
    vector = f"cdnf-{suffix}-vector"
    started: list[str] = []
    report: dict[str, object] = {"slots": 8, "network": network}

    with tempfile.TemporaryDirectory(prefix="cdnf-cell-inventory-") as directory:
        work = pathlib.Path(directory)
        try:
            run("docker", "build", "--progress=plain", "--load", "-t", IMAGE, "-f", "docker/openresty/Dockerfile", ".")
            run("openssl", "req", "-x509", "-newkey", "rsa:2048", "-nodes", "-days", "1", "-subj", "/CN=cell-inventory.test", "-keyout", str(work / "tls.key"), "-out", str(work / "tls.crt"))
            run("docker", "network", "create", network)
            run("docker", "run", "-d", "--name", vector, "--network", network, "--network-alias", "vector", "alpine:3.22", "sleep", "600")
            started.append(vector)

            for slot, name in enumerate(names, 1):
                runtime = {"schema_version": 1, "sequence": 1, "hosts": {}, "certificates": {}}
                runtime_path = work / f"cell-{slot:02d}.json"
                runtime_path.write_text(json.dumps(runtime), encoding="utf-8")
                run(
                    "docker", "run", "-d", "--name", name, "--network", network,
                    "--memory", "512m", "--cpus", "0.5", "--pids-limit", "128", "--read-only",
                    "--tmpfs", "/var/cache/nginx:rw,noexec,nosuid,size=256m",
                    "--tmpfs", "/var/lib/nginx/tmp:rw,noexec,nosuid,size=64m",
                    "--tmpfs", "/usr/local/openresty/nginx/logs:rw,noexec,nosuid,size=16m",
                    "-e", f"EDGE_CELL_NAME=cell-{slot:02d}",
                    "-e", f"EDGE_RUNTIME_FILE=/var/lib/cdnfoundry/runtime/cell-{slot:02d}.json",
                    "-e", f"EDGE_STATUS_TOKEN={TOKEN}",
                    "-v", f"{runtime_path}:/var/lib/cdnfoundry/runtime/cell-{slot:02d}.json:ro",
                    "-v", f"{work / 'tls.crt'}:/run/edge/tls.crt:ro",
                    "-v", f"{work / 'tls.key'}:/run/edge/tls.key:ro",
                    IMAGE,
                )
                started.append(name)

            deadline = time.monotonic() + 30
            while time.monotonic() < deadline:
                healthy = sum(run("docker", "exec", name, "wget", "-qO-", "http://127.0.0.1:8080/healthz", check=False).returncode == 0 for name in names)
                if healthy == 8:
                    break
                time.sleep(1)
            if healthy != 8:
                diagnostics = run("docker", "logs", names[0], check=False).stdout + run("docker", "logs", names[0], check=False).stderr
                raise RuntimeError(f"only {healthy}/8 slots became healthy; first-slot logs:\n{diagnostics}")

            identities = []
            for slot, name in enumerate(names, 1):
                metadata = inspect(name)
                host = metadata["HostConfig"]
                if host["Memory"] != 536870912 or host["NanoCpus"] != 500000000 or host["PidsLimit"] != 128 or not host["ReadonlyRootfs"]:
                    raise RuntimeError(f"resource limit drift for {name}")
                status = json.loads(run("docker", "exec", name, "wget", "-qO-", "--header", f"X-Edge-Status-Token: {TOKEN}", "http://127.0.0.1:9080/passive-failures").stdout)
                identities.append(status["cell"]["name"])
            expected = [f"cell-{slot:02d}" for slot in range(1, 9)]
            if identities != expected:
                raise RuntimeError(f"slot identities differ: {identities}")

            idle_stats = run("docker", "stats", "--no-stream", "--format", "{{.Name}}|{{.CPUPerc}}|{{.MemUsage}}", *names).stdout.strip().splitlines()
            started_at = time.monotonic()
            for _ in range(20):
                for name in names:
                    run("docker", "exec", name, "wget", "-qO-", "http://127.0.0.1:8080/healthz")
            active_seconds = time.monotonic() - started_at
            active_stats = run("docker", "stats", "--no-stream", "--format", "{{.Name}}|{{.CPUPerc}}|{{.MemUsage}}", *names).stdout.strip().splitlines()

            run("docker", "stop", "-t", "1", names[3])
            if run("docker", "exec", names[4], "wget", "-qO-", "http://127.0.0.1:8080/healthz", check=False).returncode != 0:
                raise RuntimeError("stopping cell-04 affected cell-05")
            if inspect(vector)["State"]["Running"] is not True:
                raise RuntimeError("cell failure affected the separate support process")
            run("docker", "start", names[3])

            report.update({
                "identities": identities,
                "idle_stats": idle_stats,
                "active_stats": active_stats,
                "health_requests": 160,
                "active_seconds": round(active_seconds, 3),
                "isolation": "cell-04 stop left cell-05 and support process ready",
                "resource_limits": {"memory_bytes": 536870912, "cpu": 0.5, "pids": 128, "cache_bytes": 268435456, "temporary_bytes": 67108864, "log_bytes": 16777216},
            })
            print(json.dumps(report, indent=2))
            print("cell_inventory=passed")
        finally:
            for name in reversed(started):
                run("docker", "rm", "-f", name, check=False)
            run("docker", "network", "rm", network, check=False)


if __name__ == "__main__":
    main()
