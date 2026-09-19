#!/usr/bin/env python3
"""Qualify compiled DNS services with PostgreSQL, real UDP/TCP and Lua answers."""
from __future__ import annotations

import json
from pathlib import Path
import subprocess
import tempfile
import time
import uuid

ROOT = Path(__file__).resolve().parents[2]


def run(*args: str, check: bool = True) -> subprocess.CompletedProcess[str]:
    return subprocess.run(args, check=check, capture_output=True, text=True, timeout=120)


def main() -> None:
    name = 'cdnf-dns-images-' + uuid.uuid4().hex[:12]
    containers: list[str] = []
    with tempfile.TemporaryDirectory(prefix=name) as directory:
        root = Path(directory)
        root.chmod(0o755)
        config = (ROOT / 'docker/pdns/pdns.conf').read_text()
        config = '\n'.join(line for line in config.splitlines() if not line.startswith('geoip-database-files='))+'\ngeoip-database-files=\n'
        (root / 'pdns.conf').write_text(config)
        (root / 'pdns.conf').chmod(0o640)
        import os
        os.chown(root / 'pdns.conf', 0, 82)
        run('docker', 'network', 'create', name)
        try:
            database = name+'-postgres'
            containers.append(database)
            run('docker', 'run', '-d', '--name', database, '--network', name, '--network-alias', 'pdns-db',
                '--memory', '256m', '--tmpfs', '/var/lib/postgresql:rw,size=128m',
                '-e', 'POSTGRES_USER=pdns', '-e', 'POSTGRES_PASSWORD=pdns-dev-only', '-e', 'POSTGRES_DB=pdns',
                '-v', f'{ROOT}/docker/postgres/pdns-schema.sql:/docker-entrypoint-initdb.d/schema.sql:ro',
                'ghcr.io/vaheed/cdnfoundry-postgres:ci')
            for _ in range(60):
                result = run('docker', 'exec', database, 'psql', '-U', 'pdns', '-Atc',
                             "SELECT count(*) FROM records", check=False)
                if result.returncode == 0:
                    break
                time.sleep(0.5)
            else:
                raise RuntimeError('Isolated PowerDNS schema did not initialize')
            sql = """INSERT INTO domains (name,type) VALUES ('qualification.test','NATIVE');
INSERT INTO records (domain_id,name,type,content,ttl,auth) VALUES
(1,'qualification.test','SOA','ns1.qualification.test hostmaster.qualification.test 1 3600 600 86400 60',60,true),
(1,'qualification.test','NS','ns1.qualification.test',60,true),
(1,'ns1.qualification.test','A','192.0.2.53',60,true),
(1,'www.qualification.test','A','192.0.2.42',60,true),
(1,'www.qualification.test','AAAA','2001:db8::42',60,true),
(1,'lua.qualification.test','LUA',$$A "pickrandom({'192.0.2.43'})"$$,60,true);
"""
            run('docker', 'exec', database, 'psql', '-U', 'pdns', '-v', 'ON_ERROR_STOP=1', '-c', sql)
            auth = name+'-pdns'
            containers.append(auth)
            run('docker', 'run', '-d', '--name', auth, '--network', name, '--network-alias', 'pdns-auth',
                '--memory', '256m', '--group-add', '82', '-v', f'{root}/pdns.conf:/etc/powerdns/pdns.conf:ro',
                '-v', f'{ROOT}/docker/pdns/geoip-zones.yml:/etc/powerdns/geoip-zones.yml:ro',
                'ghcr.io/vaheed/cdnfoundry-pdns:ci')
            for _ in range(60):
                if run('docker', 'exec', auth, 'pdns_control', 'rping', check=False).returncode == 0:
                    break
                time.sleep(0.5)
            else:
                raise RuntimeError('Compiled PowerDNS did not become ready: '+run('docker', 'logs', auth, check=False).stderr)
            dnsdist = name+'-dnsdist'
            containers.append(dnsdist)
            # The absent dnstap listener exercises serving with telemetry unavailable.
            run('docker', 'run', '-d', '--name', dnsdist, '--network', name, '--network-alias', 'vector',
                '--memory', '256m', '-p', '127.0.0.1::53/udp', '-p', '127.0.0.1::53/tcp',
                '-v', f'{ROOT}/docker/dnsdist/dnsdist.conf:/etc/dnsdist/dnsdist.conf:ro',
                'ghcr.io/vaheed/cdnfoundry-dnsdist:ci')
            for transport in ('udp', 'tcp'):
                port = run('docker', 'port', dnsdist, '53/'+transport).stdout.strip().rsplit(':', 1)[1]
                for hostname, kind, expected in [('www.qualification.test', 'A', '192.0.2.42'),
                                                  ('www.qualification.test', 'AAAA', '2001:db8::42'),
                                                  ('lua.qualification.test', 'A', '192.0.2.43')]:
                    for _ in range(30):
                        answer = run('dig', '@127.0.0.1', '-p', port, hostname, kind,
                                     '+short', '+time=1', '+tries=1', '+tcp' if transport == 'tcp' else '+notcp', check=False)
                        if answer.returncode == 0 and expected in answer.stdout.splitlines():
                            break
                        time.sleep(0.25)
                    else:
                        raise RuntimeError('Compiled DNS query failed: '+transport+' '+hostname+' '+kind)
            run('docker', 'exec', dnsdist, 'python3', '-c',
                "import urllib.request; assert b'dnsdist_' in urllib.request.urlopen('http://127.0.0.1:8083/metrics',timeout=2).read()")
            run('docker', 'exec', dnsdist, 'python3', '-c',
                "import json,urllib.request,urllib.error; "
                "url='http://pdns-auth:8081/api/v1/servers/localhost/zones'; "
                "request=urllib.request.Request(url,headers={'X-API-Key':'pdns-dev-api-key'}); "
                "assert any(zone['name']=='qualification.test.' for zone in json.load(urllib.request.urlopen(request,timeout=2)))")
            run('docker', 'restart', '--time', '20', auth)
            for _ in range(60):
                if run('docker', 'exec', auth, 'pdns_control', 'rping', check=False).returncode == 0:
                    break
                time.sleep(0.5)
            else:
                raise RuntimeError('Compiled PowerDNS did not recover after restart')
            identities = {c: run('docker', 'image', 'inspect', f'ghcr.io/vaheed/cdnfoundry-{c}:ci', '--format', '{{.Id}}').stdout.strip()
                          for c in ('pdns', 'dnsdist', 'postgres')}
            print(json.dumps({'qualification': 'dns_images', 'images': identities, 'udp_tcp_answers': 'passed',
                              'a_aaaa_lua': 'passed', 'geoip_module_load': 'passed', 'metrics': 'passed',
                              'authenticated_zone_api': 'passed', 'telemetry_outage_serving': 'passed', 'auth_restart': 'passed'}))
        finally:
            for container in reversed(containers):
                run('docker', 'rm', '-f', container, check=False)
            run('docker', 'network', 'rm', name, check=False)


if __name__ == '__main__':
    main()
