#!/usr/bin/env python3
"""Exercise origin IP admission and IPv4/IPv6 HTTP/TLS in disposable OpenResty.

No browser, application database, shared container or named volume is touched.
The canary and runtime DNS traffic stay on disposable private Docker networks.
An image may be supplied with --image; current runtime/config files are always
mounted. The synthetic DNS container is a test fixture, not a product service.
"""
from __future__ import annotations

import argparse
import copy
from concurrent.futures import ThreadPoolExecutor
import http.client
import importlib.util
import ipaddress
import json
from pathlib import Path
import subprocess
import tempfile
import time
import uuid

from docker_images import ensure_image

ROOT = Path(__file__).resolve().parents[2]
DNS_IMAGE = 'python:3.13-alpine@sha256:399babc8b49529dabfd9c922f2b5eea81d611e4512e3ed250d75bd2e7683f4b0'


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
    ensure_image(DNS_IMAGE)
    instance = 'cdnf-origin-destinations-' + uuid.uuid4().hex[:12]
    prefix = uuid.uuid4().hex[:10]
    subnet = f'fd{prefix[:2]}:{prefix[2:6]}:{prefix[6:]}::/64'
    spec = importlib.util.spec_from_file_location('runtime_fixture', ROOT / 'tests/e2e/phase4_runtime.py')
    fixture = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(fixture)
    run('docker', 'network', 'create', '--ipv6', '--subnet', subnet,
        '--label', 'cdnfoundry.qualification=origin-destinations', instance)
    dns_name = instance + '-dns'
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
            (target / 'canary.conf').write_text('''lua_shared_dict origin_canary 1m;
server {
    listen 18080;
    listen [::]:18080 ipv6only=on;
    listen 18443 ssl;
    listen [::]:18443 ssl ipv6only=on;
    ssl_certificate /fixtures/tls.crt;
    ssl_certificate_key /fixtures/tls.key;
    access_log /tmp/origin-canary.log combined;
    location = /hold {
        content_by_lua_block {
            ngx.sleep(3)
            if ngx.var.arg_failure == "1" then return ngx.exit(503) end
            ngx.print("synthetic-origin-canary")
        }
    }
    location = /retry {
        content_by_lua_block {
            local attempt = ngx.shared.origin_canary:incr(ngx.var.arg_case, 1, 0)
            ngx.sleep(0.01)
            -- A fixture-only ceiling prevents a defective runtime from looping
            -- indefinitely. Reaching it fails the exact attempt-count assertion.
            if attempt <= tonumber(ngx.var.arg_failures) and attempt < 12 then
                return ngx.exit(503)
            end
            if ngx.var.arg_terminal == "404" then return ngx.exit(404) end
            ngx.print("synthetic-origin-canary")
        }
    }
    location / { return 200 "synthetic-origin-canary"; }
}
''')
            dns_directory = target / 'dns'
            dns_directory.mkdir(mode=0o755)
            (dns_directory / 'records.json').write_text('{}')
            run('docker', 'run', '-d', '--name', dns_name, '--network', instance,
                '--memory', '64m', '--cpus', '0.5', '--pids-limit', '64',
                '--mount', f'type=bind,source={dns_directory},target=/fixtures,readonly',
                '--mount', f'type=bind,source={ROOT / "tests/e2e/origin_dns_fixture.py"},target=/dns.py,readonly',
                DNS_IMAGE, 'python', '/dns.py')
            dns_info = json.loads(run('docker', 'inspect', dns_name).stdout)[0]
            dns_address = dns_info['NetworkSettings']['Networks'][instance]['IPAddress']
            for _ in range(50):
                if 'synthetic_dns_ready' in run('docker', 'logs', dns_name).stdout:
                    break
                time.sleep(.1)
            else:
                raise RuntimeError('Synthetic DNS server did not start')
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
                       '--memory', '512m', '--cpus', '1', '--pids-limit', '128', '--add-host', 'vector:127.0.0.1', '--dns', dns_address,
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

            def request(host: str, path: str, headers: dict | None = None, response_headers: dict | None = None,
                        method: str = 'GET') -> tuple[int, str]:
                client = http.client.HTTPConnection('127.0.0.1', port, timeout=8)
                try:
                    client.request(method, path, headers={'Host': host, **(headers or {})})
                    response = client.getresponse()
                    if response_headers is not None:
                        response_headers.update({key.lower(): value for key, value in response.getheaders()})
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
            dns_records = {
                'dns-v4.origin.test': {'A': [ipv4], 'AAAA': []},
                'dns-v6.origin.test': {'A': [], 'AAAA': [ipv6]},
                'dns-dual.origin.test': {'A': [ipv4], 'AAAA': [ipv6]},
                'dns-mixed-a.origin.test': {'A': [ipv4, '127.0.0.1']},
                'dns-unsafe-first.origin.test': {'A': ['127.0.0.1', ipv4]},
                'dns-mixed-family.origin.test': {'A': [ipv4], 'AAAA': ['::1']},
                'dns-mapped-aaaa.origin.test': {'A': [ipv4], 'AAAA': ['::ffff:127.0.0.1']},
                'dns-error-aaaa.origin.test': {'A': [ipv4], 'AAAA_options': {'error': 2}},
                'dns-error-a.origin.test': {'A_options': {'error': 2}, 'AAAA': [ipv6]},
                'dns-nxdomain.origin.test': {'error': 3},
                'dns-empty.origin.test': {'A': [], 'AAAA': []},
                'dns-limit.origin.test': {'A': [ipv4] * 65},
                'dns-limit-both.origin.test': {'A': [ipv4] * 33, 'AAAA': [ipv6] * 32},
                'dns-at-limit.origin.test': {'A': [ipv4] * 32, 'AAAA': [ipv6] * 32},
                'dns-cname.origin.test': {'cname': 'target.origin.test', 'A': [ipv4], 'AAAA': [ipv6]},
                'dns-cname-unsafe.origin.test': {'cname': 'target.origin.test', 'A': [ipv4], 'AAAA': ['::1']},
                'dns-tcp.origin.test': {'tcp': True, 'A': [ipv4], 'AAAA': [ipv6]},
                'dns-tcp-unsafe.origin.test': {'tcp': True, 'A': [ipv4, '127.0.0.1']},
                'dns-deadline.origin.test': {'delay': .35, 'A': [], 'AAAA': [ipv6]},
                'dns-deadline-tcp.origin.test': {'tcp': True, 'delay': .35, 'A': [ipv4], 'AAAA': [ipv6]},
                'dns-deadline-cap.origin.test': {'delay': 1.75, 'A': [], 'AAAA': [ipv6]},
                'dns-rebind.origin.test': {'A': [ipv4], 'AAAA': [ipv6]},
            }
            (dns_directory / 'records.json').write_text(json.dumps(dns_records))
            passing = {'dns-v4', 'dns-v6', 'dns-dual', 'dns-at-limit', 'dns-cname', 'dns-tcp', 'dns-rebind'}
            for host in dns_records:
                label = host.split('.')[0]
                options = {'response_timeout_ms': 500} if label in {'dns-deadline', 'dns-deadline-tcp'} else {}
                cases.append((label, host, 200 if label in passing else 502, options))
            current = fixture.state({label + '.example': 'origin-canary.test' for label, _, _, _ in cases}, 2)
            for label, address, _, overrides in cases:
                config = current['hosts'][label + '.example']
                config['cache']['enabled'] = False
                config['origin'].update(host=address, port=18080, host_header='origin-canary.test',
                                        sni='origin-canary.test', private_allowlist=[ipv4 + '/32', subnet])
                config['origin'].update({key: value for key, value in overrides.items() if key not in {'security', 'forwarded'}})
                if 'security' in overrides:
                    config['security'] = overrides['security']
            backup_config = copy.deepcopy(current['hosts']['dns-v4.example'])
            backup_config['domain'] = 'dns-backup.example'
            backup_config['origin']['host'] = 'dns-nxdomain.origin.test'
            backup_config['origin']['backup'] = copy.deepcopy(current['hosts']['dns-v6.example']['origin'])
            backup_config['origin']['failover'] = {'failure_threshold': 2, 'recovery_threshold': 2,
                                                 'hold_down_seconds': 5, 'failback_delay_seconds': 5}
            current['hosts']['dns-backup.example'] = backup_config
            capacity_config = copy.deepcopy(current['hosts']['ipv4.example'])
            capacity_config['domain'] = 'capacity.example'
            capacity_config['security'] = {'limits': {'origin_max_connections': 1}}
            current['hosts']['capacity.example'] = capacity_config
            capacity_failover = copy.deepcopy(capacity_config)
            capacity_failover['domain'] = 'capacity-failover.example'
            capacity_failover['origin']['backup'] = copy.deepcopy(current['hosts']['ipv6.example']['origin'])
            capacity_failover['origin']['failover'] = {'failure_threshold': 1, 'recovery_threshold': 2,
                                                     'hold_down_seconds': 5, 'failback_delay_seconds': 5}
            current['hosts']['capacity-failover.example'] = capacity_failover
            retry_cases = [
                ('retry-disabled', 0, 2, 1, 502, 1),
                ('retry-success', 1, 2, 1, 200, 2),
                ('retry-second-success', 2, 2, 2, 200, 3),
                ('retry-exhausted', 1, 2, 99, 502, 2),
                ('retry-limit', 2, 1, 2, 502, 2),
                ('retry-security-disabled', 2, 0, 1, 502, 1),
                ('retry-client-status', 1, 2, 1, 404, 2),
                ('retry-tls-success', 1, 2, 1, 200, 2),
                ('retry-tls-exhausted', 2, 2, 99, 502, 3),
                ('retry-post', 2, 2, 1, 502, 1),
            ]
            for label, retries, limit, _, _, _ in retry_cases:
                config = copy.deepcopy(capacity_failover)
                config['domain'] = label + '.example'
                config['origin']['retry_count'] = retries
                config['security']['limits']['origin_retry_limit'] = limit
                if label.startswith('retry-tls-'):
                    config['origin'].update(host=ipv6, scheme='https', port=18443, verify_tls=True)
                current['hosts'][label + '.example'] = config
            for label in ['lifecycle-success', 'lifecycle-failure']:
                config = copy.deepcopy(capacity_config)
                config['domain'] = label + '.example'
                current['hosts'][label + '.example'] = config
            candidate = target / 'runtime.next.json'
            candidate.write_text(json.dumps(current))
            candidate.replace(target / 'runtime.json')
            time.sleep(1.2)
            # Inspect passive receipts before the larger destination corpus
            # fills the status endpoint's deliberately bounded key scan.
            def origin_diagnostics() -> dict:
                return json.loads(run('docker', 'exec', instance, 'wget', '-q', '-O-',
                    '--header=X-Edge-Status-Token: synthetic-qualification-only',
                    'http://127.0.0.1:9080/passive-failures').stdout)

            def origin_connections() -> int:
                return origin_diagnostics()['cell']['capacity']['origin_connections']

            for label, _, _, failures, expected, attempts in retry_cases:
                method = 'POST' if label == 'retry-post' else 'GET'
                status, body = request(label + '.example', f'/retry?case={label}&failures={failures}&terminal={expected}', method=method)
                diagnostics = origin_diagnostics()
                health = next(item for item in diagnostics['origins'] if item['hostname'] == label + '.example')
                passive = [item for item in diagnostics['data'] if item['hostname'] == label + '.example']
                active = diagnostics['cell']['capacity']['origin_connections']
                results.append({'case': label, 'expected': expected, 'status': status,
                    'expected_origin_attempts': attempts, 'canary_request': method + ' /retry?case=' + label + '&',
                    'method': method, 'failover': health, 'passive_failures': passive,
                    'origin_connections': active,
                    'passed': status == expected and ('synthetic-origin-canary' in body) == (expected == 200)
                    and active == 0 and health['active'] == ('primary' if expected < 500 else 'backup')
                    and (not passive if expected < 500 else len(passive) == 1
                         and passive[0]['failure_count'] == 1 and passive[0]['last_status'] == 503)})

            # A rejection must not release the slot held by an admitted request.
            # The canary sleeps in real OpenResty while the other requests finish.
            for label in ['capacity', 'capacity-failover']:
                hostname = label + '.example'
                with ThreadPoolExecutor(max_workers=1) as executor:
                    held = executor.submit(request, hostname, '/hold?case=' + label + '-held')
                    for _ in range(50):
                        if origin_connections() == 1:
                            break
                        time.sleep(.02)
                    else:
                        raise AssertionError('Held request never acquired its origin slot')
                    for index in range(3):
                        case = f'{label}-rejected-{index}'
                        status, body = request(hostname, '/?case=' + case)
                        diagnostics = origin_diagnostics()
                        active = diagnostics['cell']['capacity']['origin_connections']
                        health = next((item for item in diagnostics['origins'] if item['hostname'] == hostname), None)
                        passive = [item for item in diagnostics['data'] if item['hostname'] == hostname]
                        results.append({'case': case, 'expected': 503, 'status': status, 'origin_connections': active,
                            'failover': health, 'passive_failures': passive,
                            'passed': status == 503 and active == 1 and not held.done() and 'synthetic-origin-canary' not in body
                            and not passive and (health is None or (health['active'] == 'primary' and health['reason'] == 'none'))})
                    status, body = held.result()
                    active = origin_connections()
                    results.append({'case': label + '-held', 'expected': 200, 'status': status, 'origin_connections': active,
                        'passed': status == 200 and body == 'synthetic-origin-canary' and active == 0})
                headers = {}
                status, body = request(hostname, '/?case=' + label + '-recovered', response_headers=headers)
                active = origin_connections()
                results.append({'case': label + '-recovered', 'expected': 200, 'status': status, 'origin_connections': active,
                    'origin_role': headers.get('x-cdnfoundry-origin'),
                    'passed': status == 200 and body == 'synthetic-origin-canary' and active == 0
                    and headers.get('x-cdnfoundry-origin') == 'primary'})

            for label, address, expected, overrides in cases:
                headers = {'X-Forwarded-For': overrides['forwarded']} if 'forwarded' in overrides else {}
                started = time.monotonic()
                status, body = request(label + '.example', '/?case=' + label, headers)
                elapsed = time.monotonic() - started
                passed = status == expected and (body == 'synthetic-origin-canary' if expected == 200 else 'synthetic-origin-canary' not in body)
                results.append({'case': label, 'origin_address': address, 'expected': expected, 'status': status, 'seconds': round(elapsed, 3), 'passed': passed and elapsed < (1.5 if label in {'dns-deadline', 'dns-deadline-tcp'} else 4.5 if label == 'dns-deadline-cap' else 8)})
            for index, expected in enumerate([502, 502, 200]):
                case = f'dns-backup-attempt-{index}'
                headers = {}
                status, body = request('dns-backup.example', '/?case=' + case, response_headers=headers)
                role = headers.get('x-cdnfoundry-origin')
                results.append({'case': case, 'expected': expected, 'status': status, 'origin_role': role,
                    'passed': status == expected and ('synthetic-origin-canary' in body) == (expected == 200)
                    and (expected != 200 or role == 'backup')})

            for suffix, addresses, expected in [('failed', ['::1'], 502), ('restored', [ipv6], 200)]:
                dns_records['dns-v6.origin.test']['AAAA'] = addresses
                changed_dns = dns_directory / 'records.next.json'
                changed_dns.write_text(json.dumps(dns_records))
                changed_dns.replace(dns_directory / 'records.json')
                case = 'dns-backup-' + suffix
                headers = {}
                status, body = request('dns-backup.example', '/?case=' + case, response_headers=headers)
                role = headers.get('x-cdnfoundry-origin')
                results.append({'case': case, 'expected': expected, 'status': status, 'origin_role': role,
                    'passed': status == expected and ('synthetic-origin-canary' in body) == (expected == 200)
                    and (expected != 200 or role == 'backup')})
                if suffix == 'failed':
                    diagnostics = json.loads(run('docker', 'exec', instance, 'wget', '-q', '-O-',
                        '--header=X-Edge-Status-Token: synthetic-qualification-only',
                        'http://127.0.0.1:9080/passive-failures').stdout)
                    health = next(item for item in diagnostics['origins'] if item['hostname'] == 'dns-backup.example')
                    results[-1]['failover'] = health
                    results[-1]['passed'] = results[-1]['passed'] and health['active'] == 'backup' and health['reason'] == 'backup_failure'

            def deadline_request(index: int) -> dict:
                case = f'dns-deadline-concurrent-{index}'
                started = time.monotonic()
                status, body = request('dns-deadline.example', '/?case=' + case)
                elapsed = time.monotonic() - started
                return {'case': case, 'expected': 502, 'status': status, 'seconds': round(elapsed, 3),
                        'passed': status == 502 and elapsed < 1.5 and 'synthetic-origin-canary' not in body}

            with ThreadPoolExecutor(max_workers=4) as executor:
                results.extend(executor.map(deadline_request, range(4)))
            capacity = json.loads(run('docker', 'exec', instance, 'wget', '-q', '-O-',
                '--header=X-Edge-Status-Token: synthetic-qualification-only',
                'http://127.0.0.1:9080/passive-failures').stdout)['cell']['capacity']['origin_connections']
            assert capacity == 0, f'Origin slots leaked after DNS deadlines: {capacity}'

            # A later request must resolve again, even with an existing origin
            # keepalive connection. No cached configuration change is involved.
            dns_records['dns-rebind.origin.test'] = {'A': [ipv4], 'AAAA': ['::1']}
            dns_candidate = dns_directory / 'records.next.json'
            dns_candidate.write_text(json.dumps(dns_records))
            dns_candidate.replace(dns_directory / 'records.json')
            status, body = request('dns-rebind.example', '/?case=dns-rebind-after')
            results.append({'case': 'dns-rebind-after', 'expected': 502, 'status': status,
                            'passed': status == 502 and 'synthetic-origin-canary' not in body})
            dns_logs = run('docker', 'logs', dns_name).stdout
            assert '"protocol": "tcp"' in dns_logs, 'Real TCP fallback was not exercised'

            # Removal must not leak reservations held by in-flight requests,
            # whether their actual origin response succeeds or fails.
            for label, expected in [('lifecycle-success', 200), ('lifecycle-failure', 502)]:
                hostname = label + '.example'
                with ThreadPoolExecutor(max_workers=1) as executor:
                    held = executor.submit(request, hostname, f'/hold?case={label}&failure={int(expected == 502)}')
                    for _ in range(50):
                        if origin_connections() == 1:
                            break
                        time.sleep(.02)
                    else:
                        raise AssertionError('Lifecycle request never acquired its origin slot')
                    config = current['hosts'].pop(hostname)
                    current['sequence'] += 1
                    candidate.write_text(json.dumps(current))
                    candidate.replace(target / 'runtime.json')
                    time.sleep(1.2)
                    status, _ = request(hostname, '/?case=' + label + '-removed')
                    results.append({'case': label + '-removed', 'expected': 421, 'status': status,
                        'passed': status == 421 and not held.done()})
                    status, body = held.result()
                    active = origin_connections()
                    results.append({'case': label, 'expected': expected, 'status': status, 'origin_connections': active,
                        'expected_origin_attempts': 1, 'canary_request': 'GET /hold?case=' + label + '&',
                        'passed': status == expected and active == 0
                        and ('synthetic-origin-canary' in body) == (expected == 200)})
                current['hosts'][hostname] = config
                current['sequence'] += 1
                candidate.write_text(json.dumps(current))
                candidate.replace(target / 'runtime.json')
                time.sleep(1.2)
                status, body = request(hostname, '/?case=' + label + '-restored')
                active = origin_connections()
                results.append({'case': label + '-restored', 'expected': 200, 'status': status, 'origin_connections': active,
                    'passed': status == 200 and body == 'synthetic-origin-canary' and active == 0})

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
                if 'expected_origin_attempts' in result:
                    attempts = canary_log.count(result['canary_request'])
                    result['origin_attempts'] = attempts
                    result['passed'] = result['passed'] and attempts == result['expected_origin_attempts']
                if result['expected'] != 200 and '?case=' + result['case'] + ' ' in canary_log:
                    result['passed'] = False
                    result['unexpected_canary_connection'] = True
            print(json.dumps({'qualification': 'origin_destinations', 'environment': instance, 'image': image, 'dns_image': DNS_IMAGE,
                              'ipv4': ipv4, 'ipv6': ipv6, 'origin_connections_after_deadlines': capacity, 'cases': results}), flush=True)
            if not all(result['passed'] for result in results):
                raise AssertionError('Origin destination qualification failed')
    except Exception:
        logs = run('docker', 'logs', instance, check=False)
        print(logs.stderr[-16000:], flush=True)
        print(run('docker', 'logs', dns_name, check=False).stdout[-12000:], flush=True)
        raise
    finally:
        run('docker', 'rm', '-f', instance, check=False)
        run('docker', 'rm', '-f', dns_name, check=False)
        if carrier_created:
            run('docker', 'network', 'rm', carrier_network)
        run('docker', 'network', 'rm', instance)


if __name__ == '__main__':
    main()
