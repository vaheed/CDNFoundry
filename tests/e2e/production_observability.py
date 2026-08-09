#!/usr/bin/env python3
"""Qualify a generated production control/telemetry bundle with real containers.

This test intentionally uses fresh, isolated Compose state. It never removes
volumes and never touches the persistent development project.
"""

from __future__ import annotations

import argparse
import base64
import json
import os
import subprocess
import tempfile
import time
import urllib.error
import urllib.request
from pathlib import Path


ROOT = Path(__file__).resolve().parents[2]
PROJECT = f"cdnf-production-observability-{os.getpid()}"
LOOPBACK = "127.0.0.3"
MONITOR_LOOPBACK = "127.0.0.4"


def run(*command: str, cwd: Path, capture: bool = False) -> subprocess.CompletedProcess[str]:
    return subprocess.run(command, cwd=cwd, check=True, text=True, capture_output=capture)


def request(url: str, *, headers: dict[str, str] | None = None, attempts: int = 60) -> bytes:
    last_error: Exception | None = None
    for _ in range(attempts):
        try:
            with urllib.request.urlopen(urllib.request.Request(url, headers=headers or {}), timeout=3) as response:
                if response.status != 200:
                    raise RuntimeError(f"{url} returned HTTP {response.status}")
                return response.read()
        except (OSError, urllib.error.URLError) as exc:
            last_error = exc
            time.sleep(1)
    raise RuntimeError(f"{url} did not become ready: {last_error}")


def compose(bundle: Path, *arguments: str, capture: bool = False) -> subprocess.CompletedProcess[str]:
    return run(
        "docker", "compose", "-p", PROJECT, "--env-file", ".env.prod", "-f", "compose.yml",
        *arguments, cwd=bundle, capture=capture,
    )


def build_images(release: str) -> None:
    core = f"ghcr.io/vaheed/cdnfoundry-core:{release}"
    run("docker", "build", "--target", "production", "-t", core, "core", cwd=ROOT)
    run("docker", "build", "--build-arg", f"CORE_IMAGE={core}", "--target", "web", "-t",
        f"ghcr.io/vaheed/cdnfoundry-web:{release}", "-f", "docker/nginx/Dockerfile.production", ".", cwd=ROOT)
    run("docker", "build", "--build-arg", f"CORE_IMAGE={core}", "--target", "edge-control", "-t",
        f"ghcr.io/vaheed/cdnfoundry-edge-control:{release}", "-f", "docker/nginx/Dockerfile.production", ".", cwd=ROOT)
    for image, dockerfile, context in (
        ("edge-runtime", "docker/openresty/Dockerfile", "."),
        ("edge-agent", "edge-agent/Dockerfile", "edge-agent"),
        ("edge-gateway", "edge-gateway/Dockerfile", "edge-gateway"),
        ("mmdb-updater", "docker/mmdb-updater/Dockerfile", "docker/mmdb-updater"),
        ("loki", "docker/loki/Dockerfile", "docker/loki"),
        ("grafana", "docker/grafana/Dockerfile", "docker/grafana"),
    ):
        run("docker", "build", "-t", f"ghcr.io/vaheed/cdnfoundry-{image}:{release}",
            "-f", dockerfile, context, cwd=ROOT)


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--release", default=os.environ.get("CDNF_QUALIFY_RELEASE", "ci"))
    parser.add_argument("--build-images", action="store_true")
    args = parser.parse_args()
    if os.geteuid() != 0:
        raise SystemExit("production observability qualification must run as root")
    os.environ["COMPOSE_PROJECT_NAME"] = PROJECT
    if args.build_images:
        build_images(args.release)

    with tempfile.TemporaryDirectory(prefix="cdnf-production-observability.") as temporary:
        root = Path(temporary)
        config = {
            "preset": "control-monitoring",
            "global": {
                "operator_domain": "ops.example.com",
                "platform_domain": "example.net",
                "release": args.release,
                "acme_email": "qualification@example.com",
                "ipv6": False,
            },
            "nodes": [{
                "name": "control-qualification",
                "role": "control",
                "region": "qualification",
                "location": "local",
                "hostname": "control.ops.example.com",
                "public_ipv4": LOOPBACK,
                "bind_ipv4": LOOPBACK,
                "monitor_ipv4": MONITOR_LOOPBACK,
                "extra_env": {
                    "CONTROL_BIND": f"{LOOPBACK}:18080",
                    "GRAFANA_BIND": f"{LOOPBACK}:13000",
                },
            }],
            "features": {
                "monitoring": {"mode": "colocated", "host": "control-qualification"},
                "logs": {"mode": "centralized", "host": "control-qualification", "endpoint": None},
                "backups": {"mode": "disabled", "repository": None, "region": "us-east-1"},
            },
        }
        config_path = root / "fleet.json"
        config_path.write_text(json.dumps(config), encoding="utf-8")
        state = root / "state"
        bundles = root / "bundles"
        run(
            str(ROOT / "scripts/cdnfoundry-fleet"), "--config", str(config_path),
            "--state-dir", str(state), "--output-dir", str(bundles),
            "--repo-root", str(ROOT), "--non-interactive", "setup", cwd=ROOT,
        )
        bundle = bundles / "control-qualification"
        override = bundle / "qualification.override.yml"
        override.write_text(
            f'''services:\n  caddy:\n    ports: !override\n      - "{LOOPBACK}:18000:80/tcp"\n      - "{LOOPBACK}:14443:443/tcp"\n      - "{LOOPBACK}:14443:443/udp"\n      - "{LOOPBACK}:18444:8444/tcp"\n''',
            encoding="utf-8",
        )
        os.environ["COMPOSE_FILE"] = "compose.yml:qualification.override.yml"

        try:
            run("./start.sh", cwd=bundle)
            ready = json.loads(request(f"http://{LOOPBACK}:18080/api/ready"))
            if ready.get("status") != "ready":
                raise RuntimeError(f"control readiness failed: {ready}")

            token = (bundle / "secrets/metrics-token").read_text(encoding="utf-8").strip()
            metrics = request(
                f"http://{LOOPBACK}:18080/metrics",
                headers={"Authorization": f"Bearer {token}"},
            ).decode("utf-8")
            if "cdnfoundry_component_health" not in metrics:
                raise RuntimeError("authenticated control metrics are missing")

            query = compose(
                bundle, "exec", "-T", "prometheus", "wget", "-qO-",
                "http://127.0.0.1:9090/api/v1/query?query=up%7Bjob%3D%22cdnfoundry-control%22%7D",
                capture=True,
            )
            result = json.loads(query.stdout)["data"]["result"]
            if not result or result[0]["value"][1] != "1":
                raise RuntimeError(f"Prometheus control scrape is not up: {result}")

            env = dict(
                line.split("=", 1)
                for line in (bundle / ".env.prod").read_text(encoding="utf-8").splitlines()
                if line and "=" in line
            )
            basic = base64.b64encode(
                f"{env['GRAFANA_ADMIN_USER']}:{env['GRAFANA_ADMIN_PASSWORD']}".encode()
            ).decode()
            for datasource in ("prometheus", "clickhouse", "control-db", "loki"):
                health = json.loads(request(
                    f"http://{LOOPBACK}:13000/api/datasources/uid/{datasource}/health",
                    headers={"Authorization": f"Basic {basic}"},
                ))
                if health.get("status") not in {"OK", "success"}:
                    raise RuntimeError(f"Grafana datasource {datasource} is unhealthy: {health}")

            print("production_observability=passed")
            return 0
        finally:
            subprocess.run(
                ["docker", "compose", "-p", PROJECT, "--env-file", ".env.prod", "-f", "compose.yml",
                 "--profile", "*", "down", "--timeout", "20"],
                cwd=bundle, check=False,
            )


if __name__ == "__main__":
    raise SystemExit(main())
