#!/usr/bin/env python3
"""Real MMDB provider-outage retention qualification; no browser automation."""

from __future__ import annotations

import json
import os
import pathlib
import secrets
import subprocess
import tempfile
import time

ROOT = pathlib.Path(__file__).resolve().parents[2]
PROJECT = os.environ.get("COMPOSE_PROJECT_NAME", "cdnfoundry-dev")
MMDB_VOLUME = os.environ.get("CDNF_MMDB_VOLUME", f"{PROJECT}_mmdb")
IMAGE = os.environ.get("CDNF_MMDB_IMAGE", "cdnfoundry/mmdb-updater:phase8")
NAME = f"cdnfoundry-phase8-mmdb-{secrets.token_hex(4)}"


def run(*args: str, timeout: int = 180, check: bool = True) -> subprocess.CompletedProcess[str]:
    result = subprocess.run(args, cwd=ROOT, text=True, capture_output=True, timeout=timeout, check=False)
    if check and result.returncode != 0:
        raise RuntimeError(f"command failed: {' '.join(args)}\n{result.stdout}\n{result.stderr}")
    return result


def checksum(directory: pathlib.Path) -> str:
    return run(
        "docker", "run", "--rm", "-v", f"{directory}:/mmdb:ro", "alpine:3.23",
        "sha256sum", "/mmdb/GeoLite2-City.mmdb",
    ).stdout.split()[0]


def main() -> None:
    run("docker", "build", "-t", IMAGE, "docker/mmdb-updater", timeout=600)
    with tempfile.TemporaryDirectory(prefix="cdnfoundry-phase8-mmdb-") as temporary:
        directory = pathlib.Path(temporary)
        run(
            "docker", "run", "--rm", "-v", f"{MMDB_VOLUME}:/source:ro", "-v", f"{directory}:/target",
            "alpine:3.23", "cp", "/source/GeoLite2-City.mmdb", "/target/GeoLite2-City.mmdb",
        )
        before = checksum(directory)
        run(
            "docker", "run", "-d", "--rm", "--name", NAME,
            "-e", "MMDB_PROVIDER=generic", "-e", "MMDB_DOWNLOAD_INTERVAL_SECONDS=300",
            "-e", "MMDB_DOWNLOAD_RETRIES=0", "-v", f"{directory}:/mmdb", IMAGE,
        )
        try:
            deadline = time.monotonic() + 20
            logs = ""
            while time.monotonic() < deadline:
                logs = run("docker", "logs", NAME, check=False).stdout
                if "initial update failed; preserving existing database" in logs:
                    break
                time.sleep(0.5)
            else:
                raise AssertionError(f"updater did not report bounded provider failure: {logs[-2000:]}")
            running = run("docker", "inspect", "--format={{.State.Running}}", NAME).stdout.strip()
            after = checksum(directory)
            if running != "true" or before != after:
                raise AssertionError({"running": running, "before": before, "after": after})
            if (directory / ".GeoLite2-City.mmdb.candidate").exists():
                raise AssertionError("failed provider left an activation candidate")
            print(json.dumps({
                "phase8_mmdb_outage": "passed", "database_checksum": after,
                "provider_failure_logged": True, "last_valid_retained": True,
            }, sort_keys=True))
        finally:
            run("docker", "stop", NAME, check=False)


if __name__ == "__main__":
    main()
