#!/usr/bin/env python3
"""Exercise origin IP admission and IPv4/IPv6 HTTP/TLS in disposable OpenResty.

No browser, application database, shared container or named volume is touched.
The canary and all traffic stay on one unique, private Docker network. An image
may be supplied with --image; current runtime/config files are always mounted.
"""
from __future__ import annotations

import argparse
import http.client
import importlib.util
import ipaddress
import json
from pathlib import Path
import subprocess
import tempfile
import time
import uuid

ROOT = Path(__file__).resolve().parents[2]


def run(*args: str, check: bool = True) -> subprocess.CompletedProcess[str]:
    result = subprocess.run(args, cwd=ROOT, capture_output=True, text=True, timeout=1200 if args[:2] == ('docker', 'build') else 300)
    if check and result.returncode:
        raise RuntimeError(f'{args[0]} failed: {result.stderr[-2000:]}')
    return result


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--image', help='Existing compatible OpenResty image; resolved to an immutable local image ID')
    args = parser.parse_args()
    image = args.image or 'cdnfoundry/edge-runtime:origin-destination-qualification'
    if args.image is None:
        run('docker', 'build', '-f', 'docker/openresty/Dockerfile', '-t', image, '.')
    image = run('docker', 'image', 'inspect', image, '--format', '{{.Id}}').stdout.strip()
    instance = 'cdnf-origin-destinations-' + uuid.uuid4().hex[:12]
    prefix = uuid.uuid4().hex[:10]
    subnet = f'fd{prefix[:2]}:{prefix[2:6]}:{prefix[6:]}::/64'
    spec = importlib.util.spec_from_file_location('runtime_fixture', ROOT / 'tests/e2e/phase4_runtime.py')
    fixture = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(fixture)
    run('docker', 'network', 'create', '--ipv6', '--subnet', subnet,
        '--label', 'cdnfoundry.qualification=origin-destinations', instance)
    carrier_network = instance + '-carrier'
    carrier_created = False
    results = []
    try:
        with tempfile.TemporaryDirectory(prefix=instance) as directory:
            target = Path(directory)
            target.chmod(0o755)
            run('openssl', 'req', '-x509', '-newkey', 'rsa:2048', '-nodes', '-days', '1',
                '-subj', '/CN=origin-canary.test', '-addext', 'subjectAltName=DNS:origin-canary.test',
                '-keyout', str(target / 'tls.key'), '-out', str(target / 'tls.crt'))
            ca = run('docker', 'run', '--rm', '--entrypoint', 'cat', image, '/etc/ssl/certs/ca-certificates.crt').stdout
            (target / 'ca.crt').write_text(ca + (target / 'tls.crt').read_text())
            (target / 'runtime.json').write_text(json.dumps(fixture.state({}, 1)))
            (target / 'canary.conf').write_text('''server {
    listen 18080;
    listen [::]:18080 ipv6only=on;
    listen 18443 ssl;
    listen [::]:18443 ssl ipv6only=on;
    ssl_certificate /fixtures/tls.crt;
    ssl_certificate_key /fixtures/tls.key;
    access_log /tmp/origin-canary.log combined;
    location / { return 200 "synthetic-origin-canary"; }
}
''')
            mounts = {
                target: '/fixtures', target / 'tls.crt': '/run/edge/tls.crt', target / 'tls.key': '/run/edge/tls.key',
                target / 'ca.crt': '/etc/ssl/certs/ca-certificates.crt',
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
                       '-p', '127.0.0.1::8080', '-e', 'EDGE_RUNTIME_FILE=/fixtures/runtime.json',
                       '-e', 'EDGE_STATUS_TOKEN=synthetic-qualification-only']
            for source, destination in mounts.items():
                command.extend(['--mount', f'type=bind,source={source},target={destination},readonly'])
            run(*command, image)
            # Qualify the explicitly allowlisted shared-address range as well
            # as RFC1918/ULA. Only this disposable interface owns the subnet.
            random = uuid.uuid4().bytes
            carrier_subnet = f'100.{64 + random[0] % 64}.{random[1]}.0/24'
            run('docker', 'network', 'create', '--subnet', carrier_subnet,
                '--label', 'cdnfoundry.qualification=origin-destinations', carrier_network)
            carrier_created = True
            run('docker', 'network', 'connect', carrier_network, instance)
            info = json.loads(run('docker', 'inspect', instance).stdout)[0]
            carrier = info['NetworkSettings']['Networks'][carrier_network]['IPAddress']
            network = info['NetworkSettings']['Networks'][instance]
            ipv4, ipv6 = network['IPAddress'], network['GlobalIPv6Address']
            assert ipv4 and ipv6, 'Both origin address families are required, never skipped'
            port = int(info['NetworkSettings']['Ports']['8080/tcp'][0]['HostPort'])

            def request(host: str, path: str, headers: dict | None = None) -> tuple[int, str]:
                client = http.client.HTTPConnection('127.0.0.1', port, timeout=8)
                try:
                    client.request('GET', path, headers={'Host': host, **(headers or {})})
                    response = client.getresponse()
                    return response.status, response.read(65536).decode(errors='replace')
                finally:
                    client.close()

            def wait_ready() -> None:
                for _ in range(50):
                    try:
                        if request('health.test', '/healthz')[0] == 200:
                            return
                    except OSError:
                        pass
                    time.sleep(.2)
                raise RuntimeError('OpenResty did not become healthy')

            wait_ready()

            expanded = ipaddress.ip_address(ipv6).exploded
            # Each case gets a distinct hostname, so no cache, circuit or security
            # state from another case can establish or hide a passing result.
            cases = [
                ('ipv4', ipv4, 200, {}),
                ('carrier-allowed', carrier, 200, {'private_allowlist': [carrier + '/32']}),
                ('carrier-not-allowed', carrier, 502, {'private_allowlist': []}),
                ('carrier-explicit-deny', carrier, 502, {'private_allowlist': [carrier + '/32'], 'blocked_networks': [carrier_subnet]}),
                ('ipv6', ipv6, 200, {}),
                ('expanded-ipv6', expanded, 200, {}),
                ('ipv6-tls', ipv6, 200, {'scheme': 'https', 'port': 18443, 'verify_tls': True}),
                ('ipv6-tls-wrong-name', ipv6, 502, {'scheme': 'https', 'port': 18443, 'verify_tls': True, 'sni': 'wrong-name.test'}),
                ('ipv4-denied', ipv4, 502, {'blocked_addresses': [ipv4]}),
                ('ipv6-denied-expanded', ipv6, 502, {'blocked_addresses': [expanded]}),
                ('ipv6-denied-compressed', expanded, 502, {'blocked_addresses': [ipv6]}),
                ('ipv4-denied-network', ipv4, 502, {'blocked_networks': [ipv4 + '/32']}),
                ('ipv6-denied-network', ipv6, 502, {'blocked_networks': [subnet]}),
                ('ipv4-private-not-allowed', ipv4, 502, {'private_allowlist': []}),
                ('ipv6-private-not-allowed', ipv6, 502, {'private_allowlist': []}),
            ]
            for address in ['127.0.0.1', '169.254.169.254', '0.0.0.0', '224.0.0.1', '255.255.255.255',
                            '::', '::1', '0:0:0:0:0:0:0:1', 'fe80::1', 'fec0::1', 'ff02::1',
                            '::ffff:127.0.0.1', '0:0:0:0:0:ffff:7f00:1', '0:0:0:0:0:ffff:127.0.0.1',
                            '::ffff:' + ipv4, '0064:ff9b:0001::1', '2001:0db8::1',
                            '999.0.0.1', '1::2::3', '[::1]', 'fe80::1%eth0']:
                cases.append((f'unsafe-{len(cases)}', address, 502, {'private_allowlist': ['0.0.0.0/0', '::/0']}))
            for label, forwarded, cidr, expected in [
                ('cidr-v4-match', '198.18.1.2', '198.18.0.0/16', 403),
                ('cidr-v4-miss', '198.19.1.2', '198.18.0.0/16', 200),
                ('cidr-v6-match', '2606:4700:4700:0:0:0:0:1111', '2606:4700:4700::/48', 403),
                ('cidr-v6-miss', '2606:4700:4701::1111', '2606:4700:4700::/48', 200),
            ]:
                cases.append((label, ipv4, expected, {'security': {'trusted_proxy_cidrs': ['0.0.0.0/0', '::/0'],
                    'rules': [{'match_type': 'cidr', 'value': cidr, 'action': 'block'}]}, 'forwarded': forwarded}))
            current = fixture.state({label + '.example': 'origin-canary.test' for label, _, _, _ in cases}, 2)
            for label, address, _, overrides in cases:
                config = current['hosts'][label + '.example']
                config['cache']['enabled'] = False
                config['origin'].update(host=address, port=18080, host_header='origin-canary.test',
                                        sni='origin-canary.test', private_allowlist=[ipv4 + '/32', subnet])
                config['origin'].update({key: value for key, value in overrides.items() if key not in {'security', 'forwarded'}})
                if 'security' in overrides:
                    config['security'] = overrides['security']
            candidate = target / 'runtime.next.json'
            candidate.write_text(json.dumps(current))
            candidate.replace(target / 'runtime.json')
            time.sleep(1.2)
            for label, address, expected, overrides in cases:
                headers = {'X-Forwarded-For': overrides['forwarded']} if 'forwarded' in overrides else {}
                status, body = request(label + '.example', '/?case=' + label, headers)
                passed = status == expected and (body == 'synthetic-origin-canary' if expected == 200 else 'synthetic-origin-canary' not in body)
                results.append({'case': label, 'origin_address': address, 'expected': expected, 'status': status, 'passed': passed})
            def serving_checkpoint(label: str) -> None:
                for host, expected in [('ipv6-tls', 200), ('ipv6-denied-expanded', 502)]:
                    case = label + '-' + host
                    status, body = request(host + '.example', '/?case=' + case)
                    results.append({'case': case, 'expected': expected, 'status': status,
                                    'passed': status == expected and ('synthetic-origin-canary' in body) == (expected == 200)})

            # Runtime-file validation must retain the last usable state. This
            # exercises OpenResty only; signed agent activation is a separate gate.
            candidate.write_text('{invalid-json')
            candidate.replace(target / 'runtime.json')
            time.sleep(1.2)
            serving_checkpoint('last-valid')
            candidate.write_text(json.dumps(current))
            candidate.replace(target / 'runtime.json')
            run('docker', 'restart', instance)
            info = json.loads(run('docker', 'inspect', instance).stdout)[0]
            port = int(info['NetworkSettings']['Ports']['8080/tcp'][0]['HostPort'])
            wait_ready()
            serving_checkpoint('restart')
            canary_log = run('docker', 'exec', instance, 'cat', '/tmp/origin-canary.log').stdout
            for result in results:
                if result['expected'] != 200 and '?case=' + result['case'] + ' ' in canary_log:
                    result['passed'] = False
                    result['unexpected_canary_connection'] = True
            print(json.dumps({'qualification': 'origin_destinations', 'environment': instance, 'image': image,
                              'ipv4': ipv4, 'ipv6': ipv6, 'cases': results}), flush=True)
            if not all(result['passed'] for result in results):
                raise AssertionError('Origin destination qualification failed')
    except Exception:
        logs = run('docker', 'logs', instance, check=False)
        print(logs.stderr[-16000:], flush=True)
        raise
    finally:
        run('docker', 'rm', '-f', instance, check=False)
        if carrier_created:
            run('docker', 'network', 'rm', carrier_network)
        run('docker', 'network', 'rm', instance)


if __name__ == '__main__':
    main()
