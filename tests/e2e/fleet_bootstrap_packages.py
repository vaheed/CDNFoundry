#!/usr/bin/env python3
"""Install real prerequisite packages in disposable Linux containers.

This qualifies package availability and installed commands, not host boot or Docker
daemon activation: these containers have no init system or host Docker socket.
"""
from __future__ import annotations

import argparse
import pathlib
import secrets
import subprocess

ROOT = pathlib.Path(__file__).resolve().parents[2]
IMAGES = {
    'ubuntu': 'ubuntu@sha256:2198e4c65076e9cb0b3d695219df667c0e3b0072da64ca3c2648aca17dcecaf5',
    'alpine': 'alpine@sha256:49340b346798875b5592e5d420241f2f9cfba5f47e62ebb1446a1b34734e898f',
}
COMMAND = '''
set -eu
mkdir -p /run/systemd/system /tmp/qualification/bin
printf '#!/bin/sh\\nexit 0\\n' > /tmp/qualification/bin/systemctl
chmod +x /tmp/qualification/bin/systemctl
export PATH=/tmp/qualification/bin:$PATH
set +e
sh /bootstrap.sh > /tmp/install.log 2>&1
result=$?
set -e
for tool in docker python3 openssl tar ss flock bash curl iptables ip6tables; do
    command -v "$tool" >/dev/null || { tail -40 /tmp/install.log; echo "missing: $tool" >&2; exit 1; }
done
docker compose version
test "$result" -ne 0
grep -Ei 'cannot connect to (the )?docker daemon|failed to connect to.*docker' /tmp/install.log >/dev/null || { tail -40 /tmp/install.log; exit 1; }
echo 'package_install=PASS; daemon_validation=correctly_rejected; host_service_activation=NOT_TESTED'
'''


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--distro', choices=IMAGES, required=True)
    args = parser.parse_args()
    name = 'cdnf-packages-' + args.distro + '-' + secrets.token_hex(5)
    try:
        result = subprocess.run(['docker','run','--rm','--name',name,'--memory=1g','--cpus=2',
            '-v',f'{ROOT / "scripts/cdnfoundry_fleet/host-bootstrap.sh"}:/bootstrap.sh:ro',
            IMAGES[args.distro],'sh','-c',COMMAND],capture_output=True,text=True,timeout=1200)
        if result.returncode:
            raise RuntimeError(result.stdout[-5000:] + result.stderr[-2000:])
        print(f'distribution={args.distro}')
        print(result.stdout.strip())
    finally:
        subprocess.run(['docker','rm','-f',name],capture_output=True)


if __name__ == '__main__':
    main()
