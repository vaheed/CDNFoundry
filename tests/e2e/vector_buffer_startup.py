#!/usr/bin/env python3
"""Qualify that the production Vector config starts with its bounded disk buffers."""

from __future__ import annotations

import argparse
import subprocess
import tempfile
import time
import uuid
from pathlib import Path


ROOT = Path(__file__).resolve().parents[2]


def run(*command: str, check: bool = True) -> subprocess.CompletedProcess[str]:
    return subprocess.run(command, check=check, capture_output=True, text=True, timeout=45)


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("--image", required=True)
    image = run("docker", "image", "inspect", parser.parse_args().image, "--format", "{{.Id}}").stdout.strip()
    name = "cdnf-vector-buffers-" + uuid.uuid4().hex[:12]
    with tempfile.TemporaryDirectory(prefix=name) as directory:
        try:
            run(
                "docker", "run", "-d", "--name", name, "--network", "none",
                "-e", "VECTOR_DANGEROUSLY_ALLOW_ENV_VAR_INTERPOLATION=true",
                "-e", "CDNF_TELEMETRY_EDGE_ID=",
                "-e", "CLICKHOUSE_ENDPOINT=http://127.0.0.1:8123",
                "-e", "CLICKHOUSE_USER=cdnf", "-e", "CLICKHOUSE_PASSWORD=test-only",
                "-v", f"{ROOT / 'docker/vector/vector.yaml'}:/etc/vector/vector.yaml:ro",
                "-v", f"{directory}:/vector-data-dir", image,
            )
            time.sleep(3)
            running = run("docker", "inspect", name, "--format", "{{.State.Running}}").stdout.strip()
            log = run("docker", "logs", name).stderr
            if running != "true" or "Vector has started" not in log:
                raise RuntimeError(f"bounded Vector buffers prevented startup: {log[-2000:]}")
            run("docker", "exec", name, "vector", "validate", "--no-environment", "/etc/vector/vector.yaml")
            print("vector_buffer_startup=passed")
        finally:
            run("docker", "rm", "-f", name, check=False)


if __name__ == "__main__":
    main()
