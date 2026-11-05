#!/usr/bin/env python3
"""A cell decodes its runtime state only when the agent's stamp changes, and its workers follow the CPU limit."""

import pathlib
import subprocess
import tempfile
import time

ROOT = pathlib.Path(__file__).resolve().parents[2]
CELL_IMAGE = "cdnfoundry-dev-edge-a-cell-03"
NETWORK = "cdnfoundry-dev_telemetry"
NAME = "cdnfoundry-generation-reload-test"


def docker(*args: str, timeout: int = 120) -> subprocess.CompletedProcess[str]:
    return subprocess.run(["docker", *args], text=True, capture_output=True, timeout=timeout, check=False)


def loads() -> int:
    logs = docker("logs", NAME)
    return (logs.stdout + logs.stderr).count("runtime state loaded")


def publish(directory: pathlib.Path, generation: int) -> None:
    (directory / "cell.json").write_text(
        f'{{"schema_version":1,"generation_id":"g{generation}","sequence":{generation},"hosts":{{}},"certificates":{{}}}}')
    (directory / "cell.stamp").write_text(f"stamp-{generation}\n")


def main() -> None:
    docker("rm", "-f", NAME)
    with tempfile.TemporaryDirectory() as scratch:
        directory = pathlib.Path(scratch)
        directory.chmod(0o755)
        entrypoint = directory / "entrypoint.sh"
        entrypoint.write_text((ROOT / "docker/openresty/entrypoint.sh").read_text())
        entrypoint.chmod(0o755)
        runtime = directory / "rt"
        runtime.mkdir(mode=0o755)
        publish(runtime, 1)
        for path in runtime.iterdir():
            path.chmod(0o644)
        started = docker(
            "run", "-d", "--name", NAME, "--network", NETWORK, "--cpus", "0.5",
            "--tmpfs", "/var/lib/nginx/tmp:rw,size=64m",
            "-e", "EDGE_RUNTIME_FILE=/rt/cell.json", "-e", "EDGE_STATUS_TOKEN=generation-test",
            "-v", "cdnfoundry-dev_dev-pki:/run/edge:ro", "-v", "cdnfoundry-dev_mmdb:/mmdb:ro",
            "-v", f"{runtime}:/rt:ro",
            "-v", f"{ROOT / 'docker/openresty/runtime.lua'}:/etc/cdnfoundry/runtime.lua:ro",
            "-v", f"{ROOT / 'docker/nginx/openresty.conf'}:/usr/local/openresty/nginx/conf/nginx.conf:ro",
            "-v", f"{entrypoint}:/usr/local/bin/cdnfoundry-edge-entrypoint:ro",
            CELL_IMAGE,
        )
        assert started.returncode == 0, started.stderr
        try:
            deadline = time.time() + 60
            while loads() < 1 and time.time() < deadline:
                time.sleep(1)
            assert loads() == 1, f"the cell must load its state once at start, got {loads()}"
            workers = docker("exec", NAME, "ps").stdout.count("worker process")
            assert workers == 1, f"a 0.5 CPU cell must run one worker, got {workers}"
            time.sleep(8)
            assert loads() == 1, f"an unchanged stamp must not trigger a reload, got {loads()} loads"
            publish(runtime, 2)
            deadline = time.time() + 15
            while loads() < 2 and time.time() < deadline:
                time.sleep(1)
            assert loads() == 2, f"a changed stamp must trigger exactly one reload, got {loads()}"
        finally:
            docker("rm", "-f", NAME)
    print("cell generation reload qualification passed")


if __name__ == "__main__":
    main()
