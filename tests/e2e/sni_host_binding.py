#!/usr/bin/env python3
"""A TLS request may only address a Host that its SNI's certificate covers."""
from __future__ import annotations

import json
from pathlib import Path
import subprocess
import tempfile
import time
import uuid

from openresty_runtime import state

ROOT = Path(__file__).resolve().parents[2]


def run(*args: str, check: bool = True) -> subprocess.CompletedProcess[str]:
    result = subprocess.run(args, cwd=ROOT, text=True, capture_output=True, timeout=300)
    if check and result.returncode:
        raise RuntimeError(f'{args[0]} failed ({result.returncode}): {result.stderr[-1500:]}')
    return result


def certificate(directory: Path, label: str, names: list[str]) -> tuple[str, str]:
    key, crt = directory / f'{label}.key', directory / f'{label}.crt'
    run('openssl', 'req', '-x509', '-newkey', 'rsa:2048', '-nodes', '-days', '1', '-subj', f'/CN={names[0]}',
        '-addext', 'subjectAltName=' + ','.join('DNS:' + name for name in names), '-keyout', str(key), '-out', str(crt))
    return crt.read_text(), key.read_text()


def main() -> None:
    image = 'cdnfoundry/edge-runtime:telemetry-proof'
    run('docker', 'build', '-f', 'docker/openresty/Dockerfile', '-t', image, '.')
    name = 'cdnf-sni-host-' + uuid.uuid4().hex[:12]
    with tempfile.TemporaryDirectory(prefix=name) as directory:
        target = Path(directory)
        target.chmod(0o755)
        hosts = ['a.sni.test', 'b.sni.test', 'x.wild.test', 'y.wild.test']
        runtime = state({host: host for host in hosts}, 1)
        a_pem, a_key = certificate(target, 'a', ['a.sni.test'])
        b_pem, b_key = certificate(target, 'b', ['b.sni.test'])
        w_pem, w_key = certificate(target, 'w', ['*.wild.test'])
        expiry = int(time.time()) + 3600
        for identifier, pem, private, names, covered in (
            ('cert-a', a_pem, a_key, ['a.sni.test'], ['a.sni.test']),
            ('cert-b', b_pem, b_key, ['b.sni.test'], ['b.sni.test']),
            ('cert-w', w_pem, w_key, ['*.wild.test'], ['x.wild.test', 'y.wild.test']),
        ):
            runtime['certificates'][identifier] = {'id': identifier, 'certificate_pem': pem, 'chain_pem': '', 'private_key_pem': private,
                                                   'expires_at': expiry, 'names': names}
            for host in covered:
                runtime['hosts'][host]['tls'] = {'mode': 'custom', 'certificate_id': identifier}
        (target / 'runtime.json').write_text(json.dumps(runtime))
        for item in target.iterdir():
            item.chmod(0o644)
        bootstrap, bootstrap_key = target / 'bootstrap.crt', target / 'bootstrap.key'
        run('openssl', 'req', '-x509', '-newkey', 'rsa:2048', '-nodes', '-days', '1', '-subj', '/CN=bootstrap.test',
            '-keyout', str(bootstrap_key), '-out', str(bootstrap))
        bootstrap.chmod(0o644)
        bootstrap_key.chmod(0o644)
        mounts = {
            target: '/fixtures', bootstrap: '/run/edge/tls.crt', bootstrap_key: '/run/edge/tls.key',
            ROOT / 'docker/openresty/runtime.lua': '/etc/cdnfoundry/runtime.lua',
            ROOT / 'docker/nginx/openresty.conf': '/usr/local/openresty/nginx/conf/nginx.conf',
            ROOT / 'docker/nginx/edge-runtime.conf': '/etc/nginx/conf.d/default.conf',
            ROOT / 'docker/nginx/origin-proxy.conf': '/etc/nginx/origin-proxy.conf',
            ROOT / 'docker/nginx/proxy-cache.conf': '/etc/nginx/proxy-cache.conf',
            ROOT / 'docker/nginx/cache-upstream.conf': '/etc/nginx/cache-upstream.conf',
        }
        command = ['docker', 'run', '-d', '--name', name, '--add-host', 'vector:127.0.0.1', '--memory', '512m', '--pids-limit', '128',
                   '--tmpfs', '/tmp:rw,nosuid,size=32m,mode=1777', '--tmpfs', '/var/cache/nginx:rw,nosuid,size=32m',
                   '-e', 'EDGE_RUNTIME_FILE=/fixtures/runtime.json', '-e', 'EDGE_STATUS_TOKEN=sni-host-qualification-only']
        for source, destination in mounts.items():
            command.extend(['--mount', f'type=bind,source={source},target={destination},readonly'])

        def status(sni: str, host: str) -> str:
            result = run('docker', 'run', '--rm', '--network', f'container:{name}', 'curlimages/curl:8.16.0', '-sk', '-o', '/dev/null',
                         '-w', '%{http_code}', '--connect-timeout', '3', '--resolve', f'{sni}:8443:127.0.0.1',
                         '-H', f'Host: {host}', f'https://{sni}:8443/', check=False)
            return result.stdout.strip()

        try:
            run(*command, image)
            for _ in range(40):
                if status('a.sni.test', 'a.sni.test') not in ('', '000'):
                    break
                time.sleep(0.5)
            assert status('a.sni.test', 'b.sni.test') == '421', 'SNI a fronted tenant b'
            assert status('b.sni.test', 'a.sni.test') == '421', 'SNI b fronted tenant a'
            assert status('a.sni.test', 'a.sni.test') != '421', 'matching SNI and Host were rejected'
            assert status('x.wild.test', 'y.wild.test') != '421', 'a wildcard certificate that covers Host was rejected'
            assert status('x.wild.test', 'b.sni.test') == '421', 'a wildcard SNI fronted an uncovered tenant'
            print(json.dumps({'qualification': 'sni_host_binding', 'mismatch_rejected': 'passed', 'covered_host_allowed': 'passed'}))
        finally:
            run('docker', 'rm', '-f', name, check=False)


if __name__ == '__main__':
    main()
