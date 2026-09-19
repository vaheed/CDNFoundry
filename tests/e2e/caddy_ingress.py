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
    parser.add_argument('--image', default='ghcr.io/vaheed/cdnfoundry-caddy:ci')
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
                'DNS_API_HOSTNAME': 'localhost',
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
            for config in ('Caddyfile', 'Caddyfile.dns-api', 'Caddyfile.telemetry'):
                adapted = run('docker', 'run', '--rm', '--network', 'none', *environment,
                              '-v', f'{ROOT / "deploy/production"}:/configs:ro', image,
                              'caddy', 'adapt', '--adapter', 'caddyfile', '--config', '/configs/'+config)
                assert json.loads(adapted.stdout)['apps']['http']['servers'], config

            backend = name+'-backend'
            containers.append(backend)
            run('docker', 'run', '-d', '--name', backend, '--network', name,
                '--network-alias', 'pdns-auth', '--read-only', '--memory', '32m',
                '-v', f'{target / "www"}:/www:ro', BUSYBOX, 'httpd', '-f', '-p', '8081', '-h', '/www')
            for allowed in (True, False):
                server = name+('-allow' if allowed else '-deny')
                containers.append(server)
                run('docker', 'run', '-d', '--name', server, '--network', name,
                    '--read-only', '--memory', '128m', '--tmpfs', '/data:rw,size=16m',
                    '--tmpfs', '/config:rw,size=16m', '--tmpfs', '/tmp:rw,size=16m',
                    '-p', '127.0.0.1::8444', *environment,
                    '-e', 'CONTROL_PUBLIC_IPV4_ALLOWLIST='+(subnet if allowed else '203.0.113.1/32'),
                    '-v', f'{ROOT / "deploy/production/Caddyfile.dns-api"}:/etc/caddy/Caddyfile:ro',
                    '-v', f'{target / "tls.crt"}:/run/secrets/dns-api.crt:ro',
                    '-v', f'{target / "tls.key"}:/run/secrets/dns-api.key:ro', image)
                port = run('docker', 'port', server, '8444/tcp').stdout.strip().rsplit(':', 1)[1]
                for _ in range(30):
                    result = run('curl', '--noproxy', '*', '-sS', '--max-time', '2',
                                 '--resolve', f'localhost:{port}:127.0.0.1',
                                 '--cacert', str(target / 'tls.crt'), '-w', '\n%{http_code}',
                                 f'https://localhost:{port}/', check=False)
                    if result.returncode == 0:
                        break
                    time.sleep(0.2)
                else:
                    raise RuntimeError('Caddy TLS listener did not become ready')
                body, status = result.stdout.rsplit('\n', 1)
                assert status == ('200' if allowed else '403'), (allowed, status)
                if allowed:
                    assert body == 'qualified upstream\n', body
                assert run('docker', 'exec', server, 'wget', '-qO-',
                           'http://127.0.0.1:2019/healthz').returncode == 0
            identity = run('docker', 'image', 'inspect', image, '--format', '{{.Id}}').stdout.strip()
            print(json.dumps({'qualification': 'caddy_ingress', 'image': identity,
                              'production_config_adaptation': 'passed', 'real_tls_proxy': 'passed',
                              'source_allowlist_denial': 'passed', 'health': 'passed'}))
        finally:
            for container in reversed(containers):
                run('docker', 'rm', '-f', container, check=False)
            run('docker', 'network', 'rm', name, check=False)


if __name__ == '__main__':
    main()
