#!/usr/bin/env python3
"""Fail when a production Compose service lacks a health check, hardening, or a memory limit.

Reads the output of `docker compose ... config --format json` on stdin, so it checks the
configuration Compose would actually run, after anchors, merges, and variable substitution.
"""
from __future__ import annotations

import json
import sys

# Capabilities a service may add back. Anything else needs a reason in this list.
# KILL is for the ClickHouse image entrypoint, which stops its temporary init server after the first start.
ALLOWED_CAP_ADD = {"CHOWN", "DAC_OVERRIDE", "FOWNER", "KILL", "SETGID", "SETUID", "NET_BIND_SERVICE"}
SOCKET = "docker.sock"


def memory_limit(service: dict) -> object:
    return service.get("mem_limit") or ((service.get("deploy") or {}).get("resources") or {}).get("limits", {}).get("memory")


def check(config: dict) -> list[str]:
    problems: list[str] = []
    for name, service in sorted(config.get("services", {}).items()):
        # A one-shot tool never runs long enough to need a probe; everything else must be probed.
        oneshot = service.get("restart") == "no" or "tools" in (service.get("profiles") or []) or name.endswith("-init") or name.endswith("-provision")
        health = service.get("healthcheck") or {}
        if not oneshot and (not health or health.get("disable")):
            problems.append(f"{name}: no health check")
        if "no-new-privileges:true" not in (service.get("security_opt") or []):
            problems.append(f"{name}: missing security_opt no-new-privileges:true")
        if "ALL" not in [str(cap).upper() for cap in service.get("cap_drop") or []]:
            problems.append(f"{name}: cap_drop must include ALL")
        extra = {str(cap).upper() for cap in service.get("cap_add") or []} - ALLOWED_CAP_ADD
        if extra:
            problems.append(f"{name}: cap_add outside the allowed set: {', '.join(sorted(extra))}")
        if not memory_limit(service):
            problems.append(f"{name}: no memory limit")
        if service.get("privileged"):
            problems.append(f"{name}: privileged")
        for volume in service.get("volumes") or []:
            source = volume.get("source", "") if isinstance(volume, dict) else str(volume)
            if SOCKET in source and name != "docker-socket-proxy":
                problems.append(f"{name}: mounts the Docker socket")
    return problems


if __name__ == "__main__":
    problems = check(json.load(sys.stdin))
    for problem in problems:
        print(problem, file=sys.stderr)
    print(f"compose_policy={'failed' if problems else 'passed'}")
    sys.exit(1 if problems else 0)
