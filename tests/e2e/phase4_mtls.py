#!/usr/bin/env python3
"""Qualify real edge-control TLS, certificate provenance and leaf constraints.

Uses disposable Nginx/PHP containers and ephemeral test PKI. The PHP upstream
reports only certificate metadata, never rendered UI or private material.
"""
import json
import os
import pathlib
import subprocess
import tempfile
import uuid

ROOT = pathlib.Path(__file__).resolve().parents[2]
NGINX = 'nginx:1.31.3-alpine@sha256:4a73073bd557c65b759505da037898b61f1be6cbcc3c2c3aeac22d2a470c1752'
PHP_IMAGE = 'php:8.5-fpm-alpine@sha256:9dc81f4086ea5402227a6bcc489b04b4baba12394624d9621faa92ed812fb8ee'


def run(*args: str, check: bool = True, **kwargs) -> subprocess.CompletedProcess[str]:
    return subprocess.run(args, cwd=ROOT, check=check, text=True, capture_output=True, **kwargs)


def main() -> None:
    name = 'cdnf-mtls-' + uuid.uuid4().hex[:12]
    with tempfile.TemporaryDirectory(prefix=name) as directory:
        target = pathlib.Path(directory)
        target.chmod(0o755)
        (target / 'public').mkdir(mode=0o755)
        (target / 'public/index.php').write_text('''<?php
header('Content-Type: application/json');
$certificate = rawurldecode($_SERVER['HTTP_X_EDGE_CERTIFICATE_PEM'] ?? '');
echo json_encode(['verify' => $_SERVER['HTTP_X_EDGE_CERTIFICATE_VERIFY'] ?? '',
    'serial' => $_SERVER['HTTP_X_EDGE_CERTIFICATE_SERIAL'] ?? '',
    'fingerprint' => $certificate === '' ? null : openssl_x509_fingerprint($certificate, 'sha256')]);
''')
        run('openssl', 'req', '-x509', '-newkey', 'rsa:2048', '-nodes', '-days', '2', '-subj', '/CN=Edge Identity CA',
            '-addext', 'basicConstraints=critical,CA:TRUE,pathlen:0', '-addext', 'keyUsage=critical,keyCertSign,cRLSign',
            '-keyout', str(target / 'ca.key'), '-out', str(target / 'ca.crt'))
        run('openssl', 'req', '-newkey', 'rsa:2048', '-nodes', '-subj', '/CN=edge-control',
            '-addext', 'subjectAltName=DNS:edge-control', '-keyout', str(target / 'server.key'), '-out', str(target / 'server.csr'))
        run('openssl', 'x509', '-req', '-in', str(target / 'server.csr'), '-CA', str(target / 'ca.crt'),
            '-CAkey', str(target / 'ca.key'), '-set_serial', '100', '-days', '1', '-copy_extensions', 'copy', '-out', str(target / 'server.crt'))
        edge_id = '00000000-0000-4000-8000-000000000001'
        run('openssl', 'req', '-newkey', 'rsa:2048', '-nodes', '-subj', '/CN='+edge_id,
            '-keyout', str(target / 'client.key'), '-out', str(target / 'client.csr'))
        # Call the actual first-party signer against ephemeral CA material.
        signer = target / 'sign.php'
        signer.write_text('''<?php
require getenv('CDNF_QUALIFICATION_ROOT').'/core/vendor/autoload.php';
$app = new Illuminate\\Foundation\\Application(getenv('CDNF_QUALIFICATION_ROOT').'/core');
$app->detectEnvironment(fn () => 'qualification');
$app->instance('config', new Illuminate\\Config\\Repository(['edge' => [
    'identity_ca_certificate' => __DIR__.'/ca.crt', 'identity_ca_private_key' => __DIR__.'/ca.key',
    'identity_ca_private_key_passphrase' => '',
]]));
$result = App\\Support\\EdgeCertificateAuthority::sign(file_get_contents(__DIR__.'/client.csr'), '00000000-0000-4000-8000-000000000001');
file_put_contents(__DIR__.'/client.crt', $result['certificate']);
echo json_encode(['serial' => $result['serial'], 'fingerprint' => openssl_x509_fingerprint($result['certificate'], 'sha256')]);
''')
        identity = json.loads(run('php', str(signer), env={**os.environ, 'CDNF_QUALIFICATION_ROOT': str(ROOT)}).stdout)
        run('openssl', 'verify', '-purpose', 'sslclient', '-CAfile', str(target / 'ca.crt'), str(target / 'client.crt'))
        run('openssl', 'req', '-newkey', 'rsa:2048', '-nodes', '-subj', '/CN=forged-edge',
            '-keyout', str(target / 'forged.key'), '-out', str(target / 'forged.csr'))
        run('openssl', 'x509', '-req', '-in', str(target / 'forged.csr'), '-CA', str(target / 'client.crt'),
            '-CAkey', str(target / 'client.key'), '-set_serial', '101', '-days', '1', '-out', str(target / 'forged.crt'))
        forged = run('openssl', 'verify', '-CAfile', str(target / 'ca.crt'), '-untrusted', str(target / 'client.crt'), str(target / 'forged.crt'), check=False)
        assert forged.returncode != 0 and 'invalid CA certificate' in forged.stderr, forged.stderr
        for key in target.glob('*.key'):
            key.chmod(0o600)
        run('docker', 'network', 'create', name)
        try:
            run('docker', 'run', '-d', '--name', name+'-php', '--network', name, '--network-alias', 'core',
                '--memory', '256m', '--mount', f'type=bind,source={target / "public"},target=/app/public,readonly', PHP_IMAGE)
            run('docker', 'run', '-d', '--name', name+'-nginx', '--network', name, '--memory', '128m',
                '-p', '127.0.0.1::8443', '-p', '127.0.0.1::8080',
                '-v', f'{target / "public"}:/app/public:ro',
                '-v', f'{ROOT / "docker/nginx/edge-control.conf"}:/etc/nginx/conf.d/edge-control.conf:ro',
                '-v', f'{ROOT / "docker/nginx/default.conf"}:/etc/nginx/conf.d/default.conf:ro',
                '-v', f'{target / "server.crt"}:/run/secrets/edge-control-server.crt:ro',
                '-v', f'{target / "server.key"}:/run/secrets/edge-control-server.key:ro',
                '-v', f'{target / "ca.crt"}:/run/secrets/edge-identity-ca.crt:ro', NGINX)
            run('docker', 'exec', name+'-nginx', 'nginx', '-t')
            ports = {p: run('docker', 'port', name+'-nginx', p+'/tcp').stdout.strip().rsplit(':', 1)[1] for p in ('8080', '8443')}
            port = ports['8443']
            common = ('curl', '--noproxy', '*', '-sS', '--retry', '10', '--retry-connrefused', '--retry-delay', '1', '--max-time', '10',
                      '--resolve', f'edge-control:{port}:127.0.0.1', '--cacert', str(target / 'ca.crt'))
            assert run(*common, '-o', '/dev/null', '-w', '%{http_code}', f'https://edge-control:{port}/mtls-health').stdout == '401'
            client = (*common, '--cert', str(target / 'client.crt'), '--key', str(target / 'client.key'))
            assert run(*client, f'https://edge-control:{port}/mtls-health').stdout == 'ok\n'
            spoof = ('-H', 'X-Edge-Certificate-Verify: SUCCESS', '-H', 'X-Edge-Certificate-Serial: spoofed', '-H', 'X-Edge-Certificate-Pem: spoofed')
            actual = json.loads(run(*client, *spoof, f'https://edge-control:{port}/edge/v1/probe').stdout)
            assert actual == {'verify': 'SUCCESS', **identity}, actual
            anonymous = json.loads(run(*common, *spoof, f'https://edge-control:{port}/edge/v1/probe').stdout)
            assert anonymous['verify'] != 'SUCCESS' and anonymous['fingerprint'] is None, anonymous
            ordinary = json.loads(run('curl', '--noproxy', '*', '-sS', *spoof, f'http://127.0.0.1:{ports["8080"]}/api/probe').stdout)
            assert ordinary == {'verify': '', 'serial': '', 'fingerprint': None}, ordinary
            denied = run('curl', '--noproxy', '*', '-sS', '-o', '/dev/null', '-w', '%{http_code}', *spoof, f'http://127.0.0.1:{ports["8080"]}/edge/v1/probe').stdout
            assert denied == '404', denied
            print(json.dumps({'qualification': 'edge_mtls', 'environment': name, 'nginx_image': NGINX, 'php_image': PHP_IMAGE,
                              'tls_client_identity': 'passed', 'header_overwrite_and_clear': 'passed', 'leaf_cannot_sign_identity': 'passed', 'ordinary_ingress_denied': 'passed'}))
        finally:
            for suffix in ('-nginx', '-php'):
                run('docker', 'rm', '-f', name+suffix, check=False)
            run('docker', 'network', 'rm', name, check=False)


if __name__ == '__main__':
    main()
