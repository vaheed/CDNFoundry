#!/usr/bin/env python3
"""Stands in for `ssh root@ADDRESS COMMAND` in the two-node qualification.

The two "nodes" are directories on this host. The fleet tool addresses a node by IP and a fixed deploy
directory; this maps both onto that node's directory and its own Compose project, and runs the command
there with a shell, reading standard input as ssh would (the bundle archive arrives that way).
"""
from __future__ import annotations

import os
import subprocess
import sys

DEPLOY = "/opt/cdnfoundry"


def main() -> int:
    target, command = sys.argv[1], sys.argv[2]
    address = target.rsplit("@", 1)[-1]
    lab = os.environ["HA_LAB_ROOT"]
    node = {value: key for key, value in (item.split("=") for item in os.environ["HA_LAB_NODES"].split(","))}[address]
    directory = f"{lab}/{node}/cdnfoundry"
    os.makedirs(os.path.dirname(directory), exist_ok=True)
    environment = {**os.environ, "COMPOSE_PROJECT_NAME": f"{os.environ['HA_LAB_PROJECT']}-{node}"}
    code = subprocess.run(["sh", "-c", command.replace(DEPLOY, directory)], env=environment, check=False).returncode
    # The lab pins every network's subnet so the two projects never collide with other Docker networks on this host.
    override = f"{lab}/{node}/compose.override.yml"
    if code == 0 and os.path.exists(override) and os.path.isdir(directory):
        subprocess.run(["cp", override, f"{directory}/compose.override.yml"], check=True)
    return code


if __name__ == "__main__":
    raise SystemExit(main())
