#!/usr/bin/env python3
"""Check the Go full-purge command against an isolated, pinned real OpenResty cell.

Uses disposable containers/bind mounts only. No application database, named
volume, staging credentials, browser, or image rebuild is involved.
"""

import argparse
import json
import os
import re
import secrets
import subprocess
import tempfile
import time
import urllib.error
import urllib.request
import uuid
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]


def run(*args, timeout=180, check=True):
    return subprocess.run(args, check=check, capture_output=True, text=True, timeout=timeout)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--runtime-image', required=True)
    parser.add_argument('--go-image', required=True)
    args = parser.parse_args()
    if any(not re.fullmatch(r'[^\s]+@sha256:[a-f0-9]{64}', value)
           for value in [args.runtime_image, args.go_image]):
        parser.error('Both images require immutable SHA-256 digests')
    name = 'cdnf-purge-protocol-' + uuid.uuid4().hex[:12]
    with tempfile.TemporaryDirectory(prefix=name) as directory:
        fixture = Path(directory)
        fixture.chmod(0o755)
        token = secrets.token_urlsafe(32)
        (fixture / 'status-token').write_text(token)
        (fixture / 'status-token').chmod(0o600)
        (fixture / 'cell.env').write_text('EDGE_STATUS_TOKEN=' + token + '\nEDGE_RUNTIME_FILE=/fixture/runtime.json\n')
        (fixture / 'cell.env').chmod(0o600)
        (fixture / 'runtime.json').write_text(json.dumps({'schema_version': 1, 'sequence': 0, 'hosts': {}}))
        (fixture / 'runtime.json').chmod(0o644)
        configuration = (ROOT / 'docker/nginx/openresty.conf').read_text()
        (fixture / 'nginx.conf').write_text(configuration.replace('worker_processes auto;', 'worker_processes 1;'))
        (fixture / 'nginx.conf').chmod(0o644)
        run('openssl', 'req', '-x509', '-newkey', 'rsa:2048', '-nodes', '-days', '1',
            '-subj', '/CN=purge-qualification.invalid', '-keyout', str(fixture / 'tls.key'),
            '-out', str(fixture / 'tls.crt'))
        try:
            run('docker', 'run', '-d', '--name', name, '--memory', '512m', '--cpus', '1',
                '--pids-limit', '128', '--env-file', str(fixture / 'cell.env'),
                '--add-host', 'vector:127.0.0.1',
                '-p', '127.0.0.1::9080', '-v', str(fixture) + ':/fixture:ro',
                '-v', str(fixture / 'nginx.conf') + ':/usr/local/openresty/nginx/conf/nginx.conf:ro',
                '-v', str(fixture / 'tls.crt') + ':/run/edge/tls.crt:ro',
                '-v', str(fixture / 'tls.key') + ':/run/edge/tls.key:ro',
                args.runtime_image, timeout=300)
            address = run('docker', 'port', name, '9080/tcp').stdout.strip()
            url = 'http://' + address
            for attempt in range(30):
                try:
                    request = urllib.request.Request(url + '/passive-failures', headers={'X-Edge-Status-Token': token})
                    with urllib.request.urlopen(request, timeout=2) as response:
                        if response.status == 200:
                            break
                except (OSError, urllib.error.HTTPError):
                    time.sleep(1)
            else:
                raise RuntimeError('Isolated cell did not become ready')
            result = run('docker', 'run', '--rm', '--network', 'host',
                         '-v', str(ROOT / 'edge-agent') + ':/src:ro',
                         '-v', str(fixture) + ':/fixture:ro', '-w', '/src',
                         '-e', 'CDNF_TEST_CELL_URL=' + url,
                         '-e', 'CDNF_TEST_CELL_TOKEN_FILE=/fixture/status-token',
                         args.go_image, 'go', 'test', '-count=1', '-run',
                         '^TestFullCachePurgeAgainstRunningCell$', '-v', '.', timeout=300)
            print(result.stdout, end='')
            print('full_purge_real_openresty=passed; nil and empty API keys accepted through Go agent')
        except Exception:
            logs = run('docker', 'logs', '--tail', '40', name, check=False, timeout=15)
            print((logs.stdout + logs.stderr).replace(token, '[redacted]')[-6000:], flush=True)
            raise
        finally:
            run('docker', 'rm', '-f', name, check=False, timeout=30)
    return 0


if __name__ == '__main__':
    raise SystemExit(main())
