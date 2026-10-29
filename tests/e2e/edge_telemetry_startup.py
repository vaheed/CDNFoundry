#!/usr/bin/env python3
"""Qualify that an edge cell still serves when Vector DNS is unavailable."""

from __future__ import annotations

import argparse
import subprocess
import tempfile
import time
import uuid
from pathlib import Path


def run(*command: str, check: bool = True) -> subprocess.CompletedProcess[str]:
    return subprocess.run(command, check=check, capture_output=True, text=True, timeout=60)


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("--image", required=True)
    image = run("docker", "image", "inspect", parser.parse_args().image, "--format", "{{.Id}}").stdout.strip()
    name = "cdnf-edge-telemetry-" + uuid.uuid4().hex[:12]
    with tempfile.TemporaryDirectory(prefix=name) as directory:
        certificate = Path(directory) / "tls.crt"
        private_key = Path(directory) / "tls.key"
        run(
            "openssl", "req", "-x509", "-newkey", "rsa:2048", "-nodes", "-days", "1",
            "-subj", "/CN=edge-telemetry.test", "-keyout", str(private_key), "-out", str(certificate),
        )
        try:
            run(
                "docker", "run", "-d", "--name", name, "--network", "none",
                "--tmpfs", "/var/lib/nginx/tmp:rw,noexec,nosuid,size=64m",
                "-v", f"{certificate}:/run/edge/tls.crt:ro",
                "-v", f"{private_key}:/run/edge/tls.key:ro", image,
            )
            for _ in range(60):
                probe = run("docker", "exec", name, "wget", "-qO-", "http://127.0.0.1:8080/healthz", check=False)
                if probe.returncode == 0 and probe.stdout.strip() == "ok":
                    break
                time.sleep(1)
            else:
                raise RuntimeError(f"edge did not serve without Vector: {run('docker', 'logs', name).stderr[-2000:]}")
            log = run("docker", "logs", name).stderr
            if "Vector DNS unavailable; serving with bounded stdout telemetry only" not in log:
                raise RuntimeError("missing explicit telemetry fallback log")
            config = run("docker", "exec", name, "cat", "/var/lib/nginx/tmp/nginx-without-syslog.conf").stdout
            if "access_log syslog:server=vector:9000" in config or "access_log /dev/stdout edge_json;" not in config:
                raise RuntimeError("fallback configuration did not preserve bounded stdout logging")
            run("docker", "exec", name, "openresty", "-t", "-c", "/var/lib/nginx/tmp/nginx-without-syslog.conf")
            run(
                "docker", "exec", name, "wget", "-qO-", "--header",
                "Referer: https://referrer.test/article?secret=cdnf-query-canary",
                "http://127.0.0.1:8080/telemetry-probe", check=False,
            )
            log = run("docker", "logs", name).stdout
            if "cdnf-query-canary" in log or "https://referrer.test/article" not in log:
                raise RuntimeError("query string leaked to bounded stdout telemetry")
            print("edge_telemetry_startup=passed")
        finally:
            run("docker", "rm", "-f", name, check=False)


if __name__ == "__main__":
    main()
