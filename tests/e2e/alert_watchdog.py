#!/usr/bin/env python3
"""A webhook sink receives the always-firing Watchdog, and Alertmanager refuses to run without a notifier."""

import pathlib
import subprocess
import time

ROOT = pathlib.Path(__file__).resolve().parents[2]
PROJECT = "cdnfoundry-dev"


def run(*args: str) -> subprocess.CompletedProcess[str]:
    return subprocess.run(args, cwd=ROOT, text=True, capture_output=True, check=False)


def main() -> None:
    script = ROOT / "docker/alertmanager/start.sh"
    for environment, expected in (({}, "ALERT_WEBHOOK_URL is required"), ({"ALERT_WEBHOOK_URL": "ftp://x"}, "http:// or https://"),
                                  ({"ALERT_WEBHOOK_URL": "http://sink/"}, "must use https://")):
        command = ["docker", "run", "--rm", "--entrypoint", "/bin/sh", "-v", f"{script}:/start.sh:ro"]
        for key, value in environment.items():
            command += ["-e", f"{key}={value}"]
        result = run(*command, "prom/alertmanager:v0.32.1", "/start.sh")
        assert result.returncode != 0 and expected in result.stderr, (environment, result.stderr)
    deadline = time.time() + 240
    while time.time() < deadline:
        logs = run("docker", "logs", f"{PROJECT}-alert-sink-1")
        # nginx escapes quotes in logged bodies as \x22.
        if '"alertname":"Watchdog"' in (logs.stdout + logs.stderr).replace("\\x22", '"'):
            print("alert watchdog qualification passed")
            return
        time.sleep(5)
    raise AssertionError("the webhook sink never received Watchdog")


if __name__ == "__main__":
    main()
