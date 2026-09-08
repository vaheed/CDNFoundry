#!/usr/bin/env python3
"""Real BIND parent referrals and DNSSEC, in disposable local containers.

The resolver's process seam redirects DNS packets to loopback and substitutes
an ephemeral COM trust anchor. Production parsing, validation, address safety,
and process bounds run unchanged. This is not public registrar qualification.
No application database, existing container, or named volume is touched.
"""
from __future__ import annotations

import json
import os
import socket
from pathlib import Path
import subprocess
import tempfile
import time
import uuid

ROOT = Path(__file__).resolve().parents[2]
IMAGE = 'cdnfoundry/dns-parent-qualification'
BASE = 'alpine:3.24@sha256:28bd5fe8b56d1bd048e5babf5b10710ebe0bae67db86916198a6eec434943f8b'
PHP = r'''<?php
require getenv('CDNF_QUALIFICATION_ROOT').'/core/vendor/autoload.php';
$app = new Illuminate\Foundation\Application(getenv('CDNF_QUALIFICATION_ROOT').'/core');
$resolver = new class extends App\Support\NameserverResolver {
    protected function execute(array $arguments): string {
        if ($arguments[0] === 'delv') {
            array_splice($arguments, 1, 0, ['@'.getenv('DNS_HOST'), '-p', getenv('DNS_PORT'), '-a', getenv('DNS_ANCHOR'), '+root=com']);
        } else {
            // Synthetic globally routable RDATA is checked by the production
            // address validator, but packets never leave the local fixture.
            $arguments[1] = '@'.getenv('DNS_HOST');
            array_splice($arguments, 2, 0, ['-p', getenv('DNS_PORT')]);
        }
        if (getenv('DNS_TCP') === '1') {
            $arguments[] = '+tcp';
        }
        return parent::execute($arguments);
    }
};
try {
    echo json_encode(['outcome' => 'resolved', 'nameservers' => $resolver->resolve('customer.com')], JSON_THROW_ON_ERROR)."\n";
} catch (RuntimeException $exception) {
    echo json_encode(['outcome' => 'rejected', 'error' => $exception->getMessage()], JSON_THROW_ON_ERROR)."\n";
}
'''


def run(*args: str, **kwargs) -> subprocess.CompletedProcess:
    result = subprocess.run(args, capture_output=True, text=True, **kwargs)
    if result.returncode:
        raise RuntimeError(f'{args[0]} failed ({result.returncode}): {result.stderr[-2500:]}')
    return result


def main() -> None:
    run('docker', 'build', '-t', IMAGE, '-', input=f'FROM {BASE}\nRUN apk add --no-cache bind=9.20.27-r0 bind-tools=9.20.27-r0 bind-dnssec-tools=9.20.27-r0\nENTRYPOINT ["named"]\n')
    image = json.loads(run('docker', 'image', 'inspect', IMAGE).stdout)[0]['Id']
    results = []
    with tempfile.TemporaryDirectory(prefix='cdnf-parent-qualification-') as temporary:
        root = Path(temporary)
        (root / 'resolver.php').write_text(PHP)
        (root / 'com.zone').write_text('''$ORIGIN com.
$TTL 60
@ IN SOA ns.com. hostmaster.com. (1 60 60 3600 60)
@ IN NS ns.com.
ns IN A 8.8.8.8
customer IN NS fresh.ns1.provider.net.
customer IN NS fresh.ns2.provider.net.
''')
        tools = ['docker', 'run', '--rm', '--network', 'none', '--mount', f'type=bind,source={root},target=/fixture',
                 '--workdir', '/fixture', '--entrypoint', 'sh', image, '-ec']
        run(*tools, 'dnssec-keygen -q -a ECDSAP256SHA256 -f KSK com; dnssec-keygen -q -a ECDSAP256SHA256 com; dnssec-signzone -n 1 -S -o com -f com.signed com.zone')
        key = next(p for p in root.glob('Kcom*.key') if 'DNSKEY 257' in p.read_text())
        fields = next(line.split() for line in key.read_text().splitlines() if 'DNSKEY' in line and not line.startswith(';'))
        position = fields.index('DNSKEY')
        anchor = 'trust-anchors { "com" static-key 257 3 13 "'+''.join(fields[position+4:])+'"; };\n'
        (root / 'anchor.conf').write_text(anchor)
        signed = (root / 'com.signed').read_text()
        for case in ('fresh', 'stale', 'bogus', 'existing_ds', 'child_apex'):
            (root / 'com.signed').write_text(signed)
            if case == 'bogus':
                # Invalidate signed authoritative A RDATA without changing RRSIG.
                (root / 'com.signed').write_text(signed.replace('8.8.8.8', '9.9.9.9'))
            elif case in ('stale', 'existing_ds'):
                source = (root / 'com.zone').read_text()
                if case == 'stale':
                    source = source.replace('fresh.', 'old.')
                else:
                    source += 'customer IN DS 12345 13 2 '+('AB'*32)+'\n'
                (root / 'candidate.zone').write_text(source)
                run(*tools, 'dnssec-signzone -n 1 -S -o com -f com.signed candidate.zone')
            child = ''
            if case == 'child_apex':
                (root / 'child.zone').write_text('''$ORIGIN customer.com.
$TTL 60
@ IN SOA fresh.ns1.provider.net. hostmaster.customer.com. (1 60 60 3600 60)
@ IN NS fresh.ns1.provider.net.
@ IN NS fresh.ns2.provider.net.
''')
                child = 'zone "customer.com" { type primary; file "/fixture/child.zone"; };'
            (root / 'named.conf').write_text('''options {
 directory "/tmp"; listen-on port 5353 { any; }; listen-on-v6 { none; };
 recursion no; allow-query { any; }; allow-transfer { none; };
 dnssec-validation no; pid-file "/tmp/named.pid"; session-keyfile "/tmp/session.key";
};
zone "com" { type primary; file "/fixture/com.signed"; };
'''+child)
            name = 'cdnf-parent-'+uuid.uuid4().hex[:12]
            with socket.socket() as listener:
                listener.bind(('127.0.0.1', 0))
                port = str(listener.getsockname()[1])
            run('docker', 'run', '-d', '--name', name, '--memory', '256m', '--cpus', '1',
                '--mount', f'type=bind,source={root},target=/fixture,readonly',
                '-p', f'127.0.0.1:{port}:5353/udp', '-p', f'127.0.0.1:{port}:5353/tcp',
                '-p', f'[::1]:{port}:5353/udp', '-p', f'[::1]:{port}:5353/tcp',
                image, '-g', '-c', '/fixture/named.conf', '-u', 'root', '-n', '1')
            try:
                port = run('docker', 'port', name, '5353/udp').stdout.strip().rsplit(':', 1)[1]
                for _ in range(30):
                    answer = subprocess.run(['dig', '@127.0.0.1', '-p', port, 'com.', 'SOA', '+time=1', '+tries=1'], capture_output=True, text=True).stdout
                    if 'status: NOERROR' in answer:
                        break
                    time.sleep(0.2)
                else:
                    raise RuntimeError('Isolated BIND did not become ready')
                env = {**os.environ, 'CDNF_QUALIFICATION_ROOT': str(ROOT), 'DNS_HOST': '127.0.0.1', 'DNS_TCP': '0', 'DNS_PORT': port, 'DNS_ANCHOR': str(root / 'anchor.conf')}
                start = time.monotonic()
                result = json.loads(run('php', str(root / 'resolver.php'), env=env).stdout)
                result.update(case=case, seconds=round(time.monotonic()-start, 3))
                print(json.dumps(result), flush=True)
                if case == 'fresh':
                    assert result == {**result, 'outcome': 'resolved', 'nameservers': ['fresh.ns1.provider.net', 'fresh.ns2.provider.net']}
                elif case == 'stale':
                    assert result.get('nameservers') == ['old.ns1.provider.net', 'old.ns2.provider.net']
                else:
                    assert result['outcome'] == 'rejected', result
                results.append(result)
                if case == 'fresh':
                    for host, tcp in (('127.0.0.1', '1'), ('::1', '0'), ('::1', '1')):
                        transport = json.loads(run('php', str(root / 'resolver.php'), env={**env, 'DNS_HOST': host, 'DNS_TCP': tcp}).stdout)
                        assert transport.get('nameservers') == result['nameservers'], transport
                        transport.update(case='fresh_transport', address_family=6 if host == '::1' else 4, protocol='tcp' if tcp == '1' else 'udp')
                        print(json.dumps(transport), flush=True)
                        results.append(transport)
            finally:
                run('docker', 'rm', '-f', name)
    print(json.dumps({'qualification': 'parent_delegation', 'image_id': image, 'cases': results}))


if __name__ == '__main__':
    main()
