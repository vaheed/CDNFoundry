#!/usr/bin/env python3
"""Qualify actual origin-probe DNS/HTTP/TLS and required IPv6 on a disposable network."""
from __future__ import annotations

import json
from pathlib import Path
import subprocess
import uuid

ROOT = Path(__file__).resolve().parents[2]
IMAGE = 'golang:1.26.8-alpine@sha256:ce864e7223ac17b1775e6fd0b4c0db580c2eb50e7953a427916379e4b92a1628'


def main() -> None:
    instance = 'cdnf-origin-qualification-' + uuid.uuid4().hex[:12]
    prefix = uuid.uuid4().hex[:10]
    subnet = f'fd{prefix[:2]}:{prefix[2:6]}:{prefix[6:]}::/64'
    subprocess.run(['docker', 'network', 'create', '--ipv6', '--subnet', subnet,
                    '--label', 'cdnfoundry.qualification=origin-probes', instance], check=True, capture_output=True)
    try:
        print(json.dumps({'environment': instance, 'image': IMAGE, 'ipv6_subnet': subnet}), flush=True)
        subprocess.run(['docker', 'run', '--rm', '--name', instance, '--network', instance,
                        '--memory', '768m', '--cpus', '2', '--pids-limit', '256',
                        '--mount', f'type=bind,source={ROOT},target=/src,readonly',
                        '--workdir', '/src/edge-agent', '-e', 'CDNF_QUALIFY_IPV6=1', IMAGE,
                        'go', 'test', '-v', '-run', 'TestOrigin', '-count=1', './...'], check=True, timeout=300)
    finally:
        # The name is unique to this invocation. Never remove shared resources.
        subprocess.run(['docker', 'rm', '-f', instance], capture_output=True)
        subprocess.run(['docker', 'network', 'rm', instance], check=True, capture_output=True)


if __name__ == '__main__':
    main()
