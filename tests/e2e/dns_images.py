#!/usr/bin/env python3
"""Qualify compiled DNS services with the shipped SQLite node, real UDP/TCP and Lua answers."""
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
            # The DNS node is a PowerDNS secondary over a local SQLite store, exactly as shipped. The fixture
            # seeds one zone into that store before the server starts, as the primary's transfer would.
            seed = """INSERT INTO domains (name,type) VALUES ('qualification.test','NATIVE');
INSERT INTO records (domain_id,name,type,content,ttl,auth) VALUES
(1,'qualification.test','SOA','ns1.qualification.test hostmaster.qualification.test 1 3600 600 86400 60',60,1),
(1,'qualification.test','NS','ns1.qualification.test',60,1),
(1,'ns1.qualification.test','A','192.0.2.53',60,1),
(1,'www.qualification.test','A','192.0.2.42',60,1),
(1,'www.qualification.test','AAAA','2001:db8::42',60,1),
(1,'lua.qualification.test','LUA','A "pickrandom({''192.0.2.43''})"',60,1);
"""
            (root / 'seed.sql').write_text(seed)
            (root / 'seed.sql').chmod(0o644)
            auth = name+'-pdns'
            containers.append(auth)
            database = '/var/lib/powerdns/pdns.sqlite3'
            start = (f'[ -e {database} ] || sqlite3 {database} < /usr/local/share/doc/pdns/schema.sqlite3.sql; '
                     f'[ "$(sqlite3 {database} "SELECT count(*) FROM domains")" != 0 ] || sqlite3 {database} < /seed.sql; '
                     'exec pdns_server --daemon=no --guardian=no --disable-syslog')
            run('docker', 'run', '-d', '--name', auth, '--network', name, '--network-alias', 'pdns-auth',
                '--memory', '256m', '--group-add', '82', '-v', f'{root}/pdns.conf:/etc/powerdns/pdns.conf:ro',
                '-v', f'{root}/seed.sql:/seed.sql:ro', '-v', '/var/lib/powerdns',
                '-v', f'{ROOT}/docker/pdns/geoip-zones.yml:/etc/powerdns/geoip-zones.yml:ro',
                '--entrypoint', 'sh',
                'powerdns/pdns-auth-51:5.1.4@sha256:bb5b1c133bcca1dd455075321de7d55db4945a8d7f2ba23339e3c7bbe416b205', '-c', start)
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
                'powerdns/dnsdist-21:2.1.2@sha256:efabf173811e27438bebee5f1f40e047f263ea39093a8f7d6097df494cf1ed67')
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
            identities = {c: run('docker', 'image', 'inspect', ref, '--format', '{{.Id}}').stdout.strip()
                          for c, ref in (('pdns', 'powerdns/pdns-auth-51:5.1.4@sha256:bb5b1c133bcca1dd455075321de7d55db4945a8d7f2ba23339e3c7bbe416b205'),
                                         ('dnsdist', 'powerdns/dnsdist-21:2.1.2@sha256:efabf173811e27438bebee5f1f40e047f263ea39093a8f7d6097df494cf1ed67'))}
            print(json.dumps({'qualification': 'dns_images', 'images': identities, 'udp_tcp_answers': 'passed',
                              'a_aaaa_lua': 'passed', 'geoip_module_load': 'passed', 'metrics': 'passed',
                              'authenticated_zone_api': 'passed', 'telemetry_outage_serving': 'passed', 'auth_restart': 'passed'}))
        except Exception as exc:
            print(f'DNS fixture failure: {exc}', flush=True)
            for container in containers:
                state = run('docker', 'inspect', container, '--format', '{{.State.Status}} {{.State.ExitCode}}', check=False)
                logs = run('docker', 'logs', '--tail', '12', container, check=False)
                print(f'{container}: {state.stdout.strip()}\n{(logs.stdout + logs.stderr)[-1600:]}', flush=True)
            raise
        finally:
            for container in reversed(containers):
                run('docker', 'rm', '-f', '-v', container, check=False)
            run('docker', 'network', 'rm', name, check=False)


if __name__ == '__main__':
    main()
