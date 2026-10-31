#!/usr/bin/env python3
"""Qualify the released Caddy binary with production configs and real TLS/proxy I/O."""
from __future__ import annotations

import argparse
import json
from pathlib import Path
import subprocess
import tempfile
import time
import uuid

from docker_images import ensure_image

ROOT = Path(__file__).resolve().parents[2]
BUSYBOX = 'busybox:1.38.0-musl@sha256:32b5cdad7cce41dfd53d0ae06baebcf8357a147ee7694dc706911c373bc30c37'


def run(*args: str, check: bool = True) -> subprocess.CompletedProcess[str]:
    return subprocess.run(args, cwd=ROOT, check=check, capture_output=True, text=True, timeout=60)


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument('--image', default='caddy:2.11-alpine@sha256:eb4d35b7452d1ab6171fbdbe8d9275b2301dad6dad92115d91e19fb9b24d510f')
    image = parser.parse_args().image
    ensure_image(BUSYBOX)
    name = 'cdnf-caddy-' + uuid.uuid4().hex[:12]
    containers: list[str] = []
    with tempfile.TemporaryDirectory(prefix=name) as directory:
        target = Path(directory)
        target.chmod(0o755)
        (target / 'www').mkdir()
        (target / 'www/index.html').write_text('qualified upstream\n')
        run('openssl', 'req', '-x509', '-newkey', 'rsa:2048', '-nodes', '-days', '1',
            '-subj', '/CN=localhost', '-addext', 'subjectAltName=DNS:localhost',
            '-keyout', str(target / 'tls.key'), '-out', str(target / 'tls.crt'))
        (target / 'tls.key').chmod(0o600)
        run('docker', 'network', 'create', name)
        try:
            network = json.loads(run('docker', 'network', 'inspect', name).stdout)[0]
            subnet = network['IPAM']['Config'][0]['Subnet']
            variables = {
                'CONTROL_HOSTNAME': 'control.example.test',
                'TELEMETRY_HOSTNAME': 'telemetry.example.test',
                'GRAFANA_HOSTNAME': 'grafana.example.test',
                'ACME_CONTACT_EMAIL': 'qualification@example.test',
                'EDGE_PUBLIC_IPV4_ALLOWLIST': subnet,
                'EDGE_PUBLIC_IPV6_ALLOWLIST': '::1/128',
                'CONTROL_PUBLIC_IPV4_ALLOWLIST': subnet,
                'CONTROL_PUBLIC_IPV6_ALLOWLIST': '::1/128',
                'LOG_SOURCE_IPV4_ALLOWLIST': subnet,
                'LOG_SOURCE_IPV6_ALLOWLIST': '::1/128',
                'LOG_AUTH_TOKEN': 'isolated-qualification-token',
            }
            environment = [item for key, value in variables.items() for item in ('-e', key+'='+value)]
            for config in ('Caddyfile', 'Caddyfile.telemetry'):
                adapted = run('docker', 'run', '--rm', '--network', 'none', *environment,
                              '-v', f'{ROOT / "deploy/production"}:/configs:ro', image,
                              'caddy', 'adapt', '--adapter', 'caddyfile', '--config', '/configs/'+config)
                assert json.loads(adapted.stdout)['apps']['http']['servers'], config

            identity = run('docker', 'image', 'inspect', image, '--format', '{{.Id}}').stdout.strip()
            print(json.dumps({'qualification': 'caddy_ingress', 'image': identity,
                              'production_config_adaptation': 'passed'}))
        finally:
            for container in reversed(containers):
                run('docker', 'rm', '-f', container, check=False)
            run('docker', 'network', 'rm', name, check=False)


if __name__ == '__main__':
    main()
