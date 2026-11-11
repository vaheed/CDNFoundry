#!/usr/bin/env python3
"""Qualify the management-network allowlist in the real production Caddyfile.

A disposable Caddy runs the control site of deploy/production/Caddyfile (HTTP only, for the test) in front of
a stub web service. A client container on the same network is first outside the allowlist, then inside it.
"""
import os
import pathlib
import re
import subprocess
import tempfile
import uuid

ROOT = pathlib.Path(__file__).resolve().parents[2]
CADDY = re.search(r'caddy:2\.11-alpine@sha256:[0-9a-f]+', (ROOT / 'compose.prod.yml').read_text()).group(0)
STUB = 'python:3.14-alpine'


def docker(*args: str, check: bool = True) -> subprocess.CompletedProcess[str]:
    result = subprocess.run(['docker', *args], text=True, capture_output=True, check=False)
    if check and result.returncode:
        raise RuntimeError(f'docker {args[0]} failed: {result.stdout[-800:]}{result.stderr[-800:]}')
    return result


def main() -> None:
    suffix = uuid.uuid4().hex[:10]
    network, web, proxy = f'cdnf-mgmt-{suffix}', f'cdnf-mgmt-web-{suffix}', f'cdnf-mgmt-caddy-{suffix}'
    source = (ROOT / 'deploy/production/Caddyfile').read_text()
    snippet = source[:source.index('{$CONTROL_HOSTNAME} {')]
    snippet = snippet.replace('{\n\tadmin off\n\temail {$ACME_CONTACT_EMAIL}\n}\n', '{\n\tadmin off\n\tauto_https off\n}\n')
    site = '''http://:8080 {
	import management_only
	reverse_proxy web:8080
}
http://:8081 {
	import management_only_strict
	reverse_proxy web:8080
}
'''
    with tempfile.TemporaryDirectory(prefix='cdnf-mgmt') as directory:
        caddyfile = pathlib.Path(directory) / 'Caddyfile'
        caddyfile.write_text(snippet + site)
        caddyfile.chmod(0o644)
        docker('network', 'create', network)
        try:
            docker('run', '-d', '--rm', '--name', web, '--network', network, '--network-alias', 'web', STUB, 'python', '-m', 'http.server', '8080')
            for label, networks, expected in (
                ('outside', '203.0.113.0/24 2001:db8::/32', {'/admin': '403', '/api/domains': '403', '/horizon': '403', '/api/health': '404', '/up': '404', '/': '403'}),
                ('inside', '0.0.0.0/0 ::/0', {'/admin': '404', '/api/health': '404'}),
            ):
                docker('rm', '-f', proxy, check=False)
                docker('run', '-d', '--rm', '--name', proxy, '--network', network, '-e', f'MANAGEMENT_NETWORKS={networks}',
                       '-v', f'{caddyfile}:/etc/caddy/Caddyfile:ro', CADDY, 'caddy', 'run', '--config', '/etc/caddy/Caddyfile', '--adapter', 'caddyfile')
                probe = lambda path, port='8080': docker('run', '--rm', '--network', network, 'curlimages/curl:8.11.1', '-s', '-i', '--retry', '10', '--retry-connrefused', '--retry-delay', '1', f'http://{proxy}:{port}{path}').stdout
                for path, status in expected.items():
                    response = probe(path)
                    assert response.startswith('HTTP/1.1 ' + status), f'{label} {path}: {response[:80]!r}'
                    if status == '403':
                        assert 'set-cookie' not in response.lower() and 'location:' not in response.lower(), f'{label} {path} leaked state'
                        assert response.rstrip().endswith('Forbidden')
                if label == 'outside':
                    assert probe('/api/health', '8081').startswith('HTTP/1.1 403'), 'strict site must refuse even health'
                print(f'{label} network: ok')
        finally:
            docker('rm', '-f', proxy, web, check=False)
            docker('network', 'rm', network, check=False)


if __name__ == '__main__':
    main()
