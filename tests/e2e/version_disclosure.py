#!/usr/bin/env python3
"""Qualify that public listeners name no product version.

Runs the real web and edge-control nginx configuration in disposable containers and asks a real PowerDNS
for version.bind with the version-string setting taken from the shipped configuration.
"""
import pathlib
import re
import subprocess
import tempfile
import uuid

ROOT = pathlib.Path(__file__).resolve().parents[2]
NGINX = re.search(r'nginx:[0-9.]+-alpine@sha256:[0-9a-f]+', (ROOT / 'docker/nginx/Dockerfile.production').read_text()).group(0)
PDNS = re.search(r'powerdns/pdns-auth-51:[0-9.]+@sha256:[0-9a-f]+', (ROOT / 'compose.prod.yml').read_text()).group(0)
CURL = 'curlimages/curl:8.11.1'
VERSION = re.compile(r'nginx/\d|PowerDNS Authoritative Server \d|\b5\.1\.\d')


def docker(*args: str, check: bool = True) -> subprocess.CompletedProcess[str]:
    result = subprocess.run(['docker', *args], text=True, capture_output=True, check=False)
    if check and result.returncode:
        raise RuntimeError(f'docker {args[0]} failed: {result.stdout[-800:]}{result.stderr[-800:]}')
    return result


def main() -> None:
    suffix = uuid.uuid4().hex[:10]
    network, web, pdns = f'cdnf-ver-{suffix}', f'cdnf-ver-web-{suffix}', f'cdnf-ver-pdns-{suffix}'
    docker('network', 'create', network)
    try:
        docker('run', '-d', '--rm', '--name', web, '--network', network, '--add-host', 'core-fpm:127.0.0.1',
               '-v', f'{ROOT}/docker/nginx/default.conf:/etc/nginx/conf.d/default.conf:ro', NGINX)
        # A request nginx refuses itself, so the page comes from nginx and not from the application.
        refused = docker('run', '--rm', '--network', network, CURL, '-s', '-i', '--retry', '10', '--retry-connrefused', '--retry-delay', '1',
                         '-H', 'Transfer-Encoding: bogus', f'http://{web}:8080/').stdout
        assert refused.strip(), 'no response from nginx'
        assert not VERSION.search(refused), f'web nginx names its version: {refused[:300]!r}'
        assert re.search(r'^Server: nginx\s*$', refused, re.M), refused[:200]
        print('web nginx: no version')

        with tempfile.TemporaryDirectory(prefix='cdnf-ver') as directory:
            target = pathlib.Path(directory)
            target.chmod(0o755)
            docker('run', '--rm', '-v', f'{target}:/out', NGINX, 'sh', '-ec',
                   'apk add --no-cache openssl >/dev/null && openssl req -x509 -newkey rsa:2048 -nodes -subj /CN=x -days 1 -keyout /out/k -out /out/c 2>/dev/null; '
                   'echo "set \\$edge_proxy_secret x;" > /out/s; chmod -R a+r /out')
            check = docker('run', '--rm', '--add-host', 'core-fpm:127.0.0.1',
                           '-v', f'{ROOT}/docker/nginx/edge-control.conf:/etc/nginx/conf.d/default.conf:ro',
                           '-v', f'{target}/c:/run/secrets/edge-control-server.crt:ro', '-v', f'{target}/k:/run/secrets/edge-control-server.key:ro',
                           '-v', f'{target}/c:/run/secrets/edge-identity-ca.crt:ro', '-v', f'{target}/s:/run/secrets/edge-proxy-secret.conf:ro',
                           NGINX, 'nginx', '-t', check=False)
            assert check.returncode == 0, check.stderr[-500:]
        assert re.search(r'^\s*server_tokens off;', (ROOT / 'docker/nginx/edge-control.conf').read_text(), re.M)
        print('edge-control nginx: configuration valid, tokens off')

        config = ['launch=gsqlite3', 'gsqlite3-database=/tmp/pdns.sqlite3', 'gsqlite3-dnssec=no', 'local-address=0.0.0.0', 'local-port=5300', 'primary=yes']
        shipped = {line.strip() for path in ('pdns.conf', 'primary.conf') for line in (ROOT / 'docker/pdns' / path).read_text().splitlines()}
        assert 'version-string=anonymous' in shipped
        config.append('version-string=anonymous')
        with tempfile.TemporaryDirectory(prefix='cdnf-ver') as directory:
            data = pathlib.Path(directory)
            data.chmod(0o777)
            schema = docker('run', '--rm', '--entrypoint', 'cat', PDNS, '/usr/local/share/doc/pdns/schema.sqlite3.sql').stdout
            (data / 'schema.sql').write_text(schema)
            docker('run', '--rm', '-v', f'{data}:/data', 'alpine:3.20', 'sh', '-ec',
                   'apk add --no-cache sqlite >/dev/null && sqlite3 /data/pdns.sqlite3 < /data/schema.sql && chmod 666 /data/pdns.sqlite3')
            config[1] = 'gsqlite3-database=/data/pdns.sqlite3'
            conf = data / 'pdns.conf'
            conf.write_text('\n'.join(config) + '\n')
            conf.chmod(0o644)
            docker('run', '-d', '--rm', '--name', pdns, '--network', network, '-v', f'{conf}:/etc/powerdns/pdns.conf:ro', '-v', f'{data}:/data', PDNS,
                   '--daemon=no', '--guardian=no', '--disable-syslog')
            answer = docker('run', '--rm', '--network', network, 'alpine:3.20', 'sh', '-ec',
                            f'apk add --no-cache bind-tools >/dev/null; for i in $(seq 1 20); do dig +short +tcp +time=2 +tries=1 -p 5300 @{pdns} version.bind chaos txt && break; sleep 1; done; '
                            f'dig +tcp +time=2 -p 5300 @{pdns} version.bind chaos txt | grep -E "status|version"', check=False).stdout
            assert 'status: REFUSED' in answer or 'status: SERVFAIL' in answer, answer
            assert not VERSION.search(answer), f'PowerDNS names its version: {answer!r}'
        print('powerdns version.bind: no version')
    finally:
        docker('rm', '-f', web, pdns, check=False)
        docker('network', 'rm', network, check=False)


if __name__ == '__main__':
    main()
