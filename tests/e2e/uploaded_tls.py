#!/usr/bin/env python3
"""Verify custom-chain admission against real OpenResty TLS clients, without a DB.

The PHP fixture runs the current chain validator with synthetic certificates.
Forced invalid runtime snapshots demonstrate client failure independently of
admission. API transaction/last-valid-state coverage remains in TlsApiTest.
"""
from __future__ import annotations

import argparse
import hashlib
import http.client
import json
from pathlib import Path
import re
import socket
import ssl
import subprocess
import tempfile
import time
import uuid

from phase4_runtime import state

ROOT = Path(__file__).resolve().parents[2]
HOST = 'www.example.test'


def run(*args: str, check: bool = True) -> subprocess.CompletedProcess[str]:
    result = subprocess.run(args, cwd=ROOT, text=True, capture_output=True,
                            timeout=1200 if args[:2] == ('docker', 'build') else 180)
    if check and result.returncode:
        # stdout can contain synthetic private keys; never include it in errors.
        raise RuntimeError(f'{args[0]} failed ({result.returncode}): {result.stderr[-2000:]}')
    return result


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--core-image', help='Existing compatible PHP image, resolved to its immutable local ID')
    parser.add_argument('--edge-image', help='Existing compatible OpenResty image, resolved to its immutable local ID')
    args = parser.parse_args()
    core = args.core_image or 'cdnfoundry/core:uploaded-tls-qualification'
    edge = args.edge_image or 'cdnfoundry/edge-runtime:uploaded-tls-qualification'
    if args.core_image is None:
        run('docker', 'build', '--target', 'production', '-t', core, 'core')
    if args.edge_image is None:
        run('docker', 'build', '-f', 'docker/openresty/Dockerfile', '-t', edge, '.')
    core, edge = (run('docker', 'image', 'inspect', ref, '--format', '{{.Id}}').stdout.strip()
                  for ref in (core, edge))
    producer = ['docker', 'run', '--rm', '--network', 'none', '--read-only',
                '--memory', '256m', '--cpus', '1', '--pids-limit', '64',
                '--tmpfs', '/tmp:rw,nosuid,size=16m,mode=1777',
                '--tmpfs', '/app/storage:rw,nosuid,size=16m,mode=1777',
                '-e', 'APP_ENV=testing', '-e', 'DB_CONNECTION=sqlite', '-e', 'DB_DATABASE=:memory:',
                '-e', 'DB_URL=', '-e', 'APP_CONFIG_CACHE=/tmp/test-config.php',
                '-e', 'CACHE_STORE=array', '-e', 'SESSION_DRIVER=array', '-e', 'QUEUE_CONNECTION=sync']
    for source, name in [
        ('core/app/Support/UploadedCertificate.php', 'UploadedCertificate.php'),
        ('core/tests/Support/CertificateChain.php', 'CertificateChain.php'),
        ('tests/e2e/uploaded_tls_fixture.php', 'producer.php'),
    ]:
        producer.extend(['--mount', f'type=bind,source={ROOT / source},target=/fixtures/{name},readonly'])
    data = json.loads(run(*producer, '--entrypoint', 'php', core, '/fixtures/producer.php').stdout)
    cases = data['cases']
    assert set(cases) == {'valid', 'issuer_not_ca', 'issuer_key_usage', 'expired_issuer', 'expired_root',
                          'path_length', 'server_purpose', 'name_constraint', 'critical_extension'}
    for name, case in cases.items():
        assert case['accepted'] is (name == 'valid'), f'Incorrect chain admission for {name}'

    instance = 'cdnf-uploaded-tls-' + uuid.uuid4().hex[:12]
    run('docker', 'network', 'create', '--label', 'cdnfoundry.qualification=uploaded-tls', instance)
    results = []
    try:
        with tempfile.TemporaryDirectory(prefix=instance) as directory:
            target = Path(directory)
            target.chmod(0o755)
            valid = cases['valid']['bundle']
            (target / 'tls.crt').write_text(valid['certificate'] + valid['chain'])
            (target / 'tls.key').write_text(valid['private_key'])
            (target / 'tls.key').chmod(0o600)
            (target / 'runtime.json').write_text(json.dumps(state({}, 1)))
            (target / 'canary.conf').write_text('server { listen 18080; location / { return 200 "synthetic-tls-origin"; } }\n')
            mounts = {
                target: '/fixtures', target / 'tls.crt': '/run/edge/tls.crt', target / 'tls.key': '/run/edge/tls.key',
                target / 'canary.conf': '/etc/nginx/conf.d/canary.conf',
                ROOT / 'docker/openresty/runtime.lua': '/etc/cdnfoundry/runtime.lua',
                ROOT / 'docker/nginx/openresty.conf': '/usr/local/openresty/nginx/conf/nginx.conf',
                ROOT / 'docker/nginx/edge-runtime.conf': '/etc/nginx/conf.d/default.conf',
                ROOT / 'docker/nginx/origin-proxy.conf': '/etc/nginx/origin-proxy.conf',
                ROOT / 'docker/nginx/proxy-cache.conf': '/etc/nginx/proxy-cache.conf',
                ROOT / 'docker/nginx/cache-upstream.conf': '/etc/nginx/cache-upstream.conf',
            }
            command = ['docker', 'run', '-d', '--name', instance, '--network', instance,
                       '--memory', '512m', '--cpus', '1', '--pids-limit', '128', '--add-host', 'vector:127.0.0.1',
                       '--tmpfs', '/tmp:rw,nosuid,size=32m,mode=1777',
                       '--tmpfs', '/var/cache/nginx:rw,nosuid,size=32m',
                       '-p', '127.0.0.1::8443', '-e', 'EDGE_RUNTIME_FILE=/fixtures/runtime.json',
                       '-e', 'EDGE_STATUS_TOKEN=synthetic-qualification-only']
            for source, destination in mounts.items():
                command.extend(['--mount', f'type=bind,source={source},target={destination},readonly'])
            run(*command, edge)
            info = json.loads(run('docker', 'inspect', instance).stdout)[0]
            address = info['NetworkSettings']['Networks'][instance]['IPAddress']
            port = int(info['NetworkSettings']['Ports']['8443/tcp'][0]['HostPort'])
            sequence = 1

            def publish(name: str) -> None:
                nonlocal sequence
                sequence += 1
                bundle = cases[name]['bundle']
                candidate = state({HOST: HOST}, sequence)
                host = candidate['hosts'][HOST]
                host['cache']['enabled'] = False
                host['origin'].update(host=address, port=18080, private_allowlist=[address + '/32'])
                host['tls'] = {'mode': 'custom', 'certificate_id': f'fixture-{sequence}'}
                candidate['certificates'][f'fixture-{sequence}'] = {
                    'id': f'fixture-{sequence}', 'certificate_pem': bundle['certificate'],
                    'chain_pem': bundle['chain'], 'private_key_pem': bundle['private_key'],
                    'expires_at': int(time.time()) + 3600, 'names': [HOST],
                }
                staging = target / 'candidate.json'
                staging.write_text(json.dumps(candidate))
                staging.replace(target / 'runtime.json')
                # Each worker reloads on its one-second timer. One matching
                # handshake alone does not establish that every worker refreshed.
                time.sleep(1.2)
                # Observe the actual peer certificate before judging verification.
                expected = hashlib.sha256(ssl.PEM_cert_to_DER_cert(bundle['certificate'])).hexdigest()
                for _ in range(50):
                    try:
                        if request(name, verify=False)[0] == expected:
                            return
                    except (OSError, http.client.HTTPException):
                        pass
                    time.sleep(.2)
                raise AssertionError(f'Runtime failed to activate synthetic {name} certificate')

            def request(name: str, verify: bool = True) -> tuple[str, int, bytes]:
                context = ssl.SSLContext(ssl.PROTOCOL_TLS_CLIENT)
                if verify:
                    roots = re.findall(r'-----BEGIN CERTIFICATE-----.*?-----END CERTIFICATE-----',
                                       cases[name]['bundle']['chain'], re.S)
                    context.load_verify_locations(cadata=roots[-1])
                else:
                    # Deliberate negative-fixture observation, never an admission check.
                    context.check_hostname = False
                    context.verify_mode = ssl.CERT_NONE
                with socket.create_connection(('127.0.0.1', port), timeout=5) as connection:
                    with context.wrap_socket(connection, server_hostname=HOST) as tls:
                        fingerprint = hashlib.sha256(tls.getpeercert(binary_form=True)).hexdigest()
                        tls.sendall(f'GET / HTTP/1.1\r\nHost: {HOST}\r\nConnection: close\r\n\r\n'.encode())
                        response = http.client.HTTPResponse(tls)
                        response.begin()
                        return fingerprint, response.status, response.read(4096)

            publish('valid')
            original, status, body = request('valid')
            assert status == 200 and body == b'synthetic-tls-origin', 'Valid private-CA TLS must serve HTTP 200'
            results.append({'case': 'valid', 'admission': 'accepted', 'http_status': status})
            for name in cases:
                if name == 'valid':
                    continue
                # Bypass admission only to prove why rejecting this candidate matters.
                publish(name)
                try:
                    request(name)
                except ssl.SSLCertVerificationError as error:
                    reason = error.verify_message
                else:
                    raise AssertionError(f'TLS client incorrectly trusted {name}')
                publish('valid')
                fingerprint, status, body = request('valid')
                assert fingerprint == original and status == 200 and body == b'synthetic-tls-origin'
                results.append({'case': name, 'admission': 'rejected', 'client_failure': reason,
                                'restored_fingerprint_matches': True, 'restored_http_status': status})
            print(json.dumps({'instance': instance, 'core_image': core, 'edge_image': edge,
                              'php_openssl': data['openssl'], 'client_openssl': ssl.OPENSSL_VERSION,
                              'results': results}, sort_keys=True))
    finally:
        run('docker', 'rm', '-f', instance, check=False)
        run('docker', 'network', 'rm', instance, check=False)


if __name__ == '__main__':
    main()
