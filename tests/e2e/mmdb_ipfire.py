#!/usr/bin/env python3
"""Qualify the signed default source, MMDB output, tamper retention and first-start recovery on disposable containers."""
from __future__ import annotations

import argparse
import hashlib
import json
import lzma
import pathlib
import re
import shutil
import sys
import tempfile
import time
import urllib.request
import uuid

from mmdb_fallback import IMAGE, run

ROOT = pathlib.Path(__file__).resolve().parents[2]


def wait_for(test, description: str) -> None:
    deadline = time.monotonic() + 600
    while time.monotonic() < deadline:
        if test():
            return
        time.sleep(1)
    raise AssertionError(description)


def main() -> None:
    global IMAGE
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--database', type=pathlib.Path, help='reuse the official location.db.xz download')
    parser.add_argument('--output', type=pathlib.Path, help='save the verified generated MMDB for related qualification')
    parser.add_argument('--image', help='qualify an already-built updater image')
    args = parser.parse_args()
    if args.image:
        IMAGE = args.image
    else:
        run('docker', 'build', '-q', '-t', IMAGE, 'docker/mmdb-updater')
    name = 'cdnf-ipfire-' + uuid.uuid4().hex[:8]
    with tempfile.TemporaryDirectory(prefix=name) as temporary:
        directory = pathlib.Path(temporary)
        directory.chmod(0o755)
        served = directory / 'served'
        served.mkdir(mode=0o755)
        source = served / 'location.db.xz'
        if args.database:
            shutil.copyfile(args.database, source)
        else:
            with urllib.request.urlopen('https://location.ipfire.org/databases/1/location.db.xz', timeout=180) as response:
                source.write_bytes(response.read(268435457))
        original = source.read_bytes()
        sleep = directory / 'sleep'
        sleep.write_text('#!/bin/sh\nexec /bin/sleep 2\n')
        sleep.chmod(0o755)
        caches = {}
        run('docker', 'network', 'create', name)
        try:
            server = '''from http.server import HTTPServer,SimpleHTTPRequestHandler
import os
os.chdir('/srv')
class Handler(SimpleHTTPRequestHandler):
 def do_GET(self):
  if self.headers.get('Authorization'): print('UNEXPECTED_AUTH_HEADER',flush=True)
  super().do_GET()
HTTPServer(('0.0.0.0',8000),Handler).serve_forever()
'''
            run('docker', 'run', '-d', '--name', name + '-origin', '--network', name,
                '-v', f'{served}:/srv:ro',
                'python:3.13-alpine@sha256:399babc8b49529dabfd9c922f2b5eea81d611e4512e3ed250d75bd2e7683f4b0',
                'python3', '-u', '-c', server)

            def updater(suffix: str) -> tuple[str, pathlib.Path]:
                container = name + suffix
                cache = directory / ('cache' + suffix)
                cache.mkdir(mode=0o777)
                caches[container] = cache
                run('docker', 'run', '-d', '--name', container, '--network', name, '--memory', '1g', '--cpus', '1',
                    '-v', f'{cache}:/mmdb', '-v', f'{sleep}:/usr/local/bin/sleep:ro',
                    '-e', 'MMDB_REQUIRE_VERIFICATION=true', '-e', 'MMDB_DOWNLOAD_RETRIES=0',
                    '-e', 'MMDB_DOWNLOAD_INTERVAL_SECONDS=300',
                    '-e', 'MMDB_DOWNLOAD_HEADER=Authorization: Bearer e2e-placeholder',
                    '-e', f'MMDB_IPFIRE_DOWNLOAD_URL=http://{name}-origin:8000/location.db.xz', IMAGE)
                return container, cache

            container, cache = updater('')
            native = cache / 'IPFire.db'
            wait_for(native.exists, 'signed IPFire default did not activate')
            target = cache / 'GeoLite2-City.mmdb'
            asn = cache / 'GeoLite2-ASN.mmdb'
            digest = hashlib.sha256(target.read_bytes()).hexdigest()
            if args.output:
                shutil.copyfile(target, args.output)
            assert hashlib.sha256(asn.read_bytes()).hexdigest() == digest
            assert native.read_bytes() == lzma.decompress(original)
            native_lookup = '''import json,location,sys
d=location.Database('/mmdb/IPFire.db');n=d.lookup(sys.argv[1])
if n is None: print('null');sys.exit(0)
c=d.get_country(n.country_code) if n.country_code else None;a=d.get_as(n.asn) if n.asn else None
print(json.dumps({'country':n.country_code,'continent':c.continent_code if c else '', 'asn':n.asn,'organization':a.name if a else ''}))'''
            for address in ('1.1.1.1', '8.8.8.8', '9.9.9.9', '2606:4700:4700::1111', '2001:4860:4860::8888'):
                expected = json.loads(run('docker', 'exec', container, 'python3', '-c', native_lookup, address).stdout)
                if expected is None:
                    missing = run('docker', 'exec', container, 'mmdblookup', '-f', '/mmdb/GeoLite2-City.mmdb', '-i', address, check=False)
                    assert missing.returncode == 6, (address, missing.stdout, missing.stderr)
                    continue
                for field, path in [('country', ['country', 'iso_code']), ('continent', ['continent', 'code']),
                                    ('asn', ['autonomous_system_number']), ('organization', ['autonomous_system_organization'])]:
                    output = run('docker', 'exec', container, 'mmdblookup', '-f', '/mmdb/GeoLite2-City.mmdb', '-i', address, *path).stdout
                    if field == 'asn':
                        assert re.search(r'\b' + str(expected[field]) + r' <uint32>', output), (field, expected, output)
                    else:
                        assert json.dumps(expected[field], ensure_ascii=False) in output, (field, expected, output)
            stamp = (cache / 'LAST_UPDATE').read_text()
            wait_for(lambda: (cache / 'LAST_UPDATE').read_text() != stamp, 'daily update cycle not exercised')
            assert hashlib.sha256(target.read_bytes()).hexdigest() == digest, 'conversion is not deterministic'
            # Refuse an otherwise valid signed download that predates the locally cached version.
            run('docker', 'exec', container, 'python3', '-c',
                "import location;location.Writer().write('/mmdb/newer.db')")
            newer = json.loads(run('docker', 'exec', container, 'python3', '-c',
                "import location,json;print(json.dumps(location.Database('/mmdb/newer.db').created_at > location.Database('/mmdb/IPFire.db').created_at))").stdout)
            assert newer, 'download fixture must predate the generated local cache for rollback qualification'
            rollback = run('docker', 'exec', container, 'python3', '/usr/local/bin/ipfire-export',
                           '/mmdb/IPFire.db', '/mmdb/newer.db', '--verify-only', check=False)
            assert rollback.returncode != 0 and 'would roll back the cached version' in rollback.stderr, rollback.stderr
            assert hashlib.sha256(target.read_bytes()).hexdigest() == digest
            # Corrupt a signed native record while preserving a valid compressed container.
            tampered = bytearray(lzma.decompress(original))
            organization_offset = tampered.find(b'Cloudflare')
            assert organization_offset >= 0
            tampered[organization_offset] ^= 1
            replacement = source.with_suffix('.next')
            replacement.write_bytes(lzma.compress(tampered))
            replacement.replace(source)
            wait_for(lambda: 'signature verification failed' in run('docker', 'logs', container).stderr,
                     'tampered IPFire signature was not refused')
            assert hashlib.sha256(target.read_bytes()).hexdigest() == digest
            assert hashlib.sha256(asn.read_bytes()).hexdigest() == digest
            assert native.read_bytes() == lzma.decompress(original)
            down, down_cache = updater('-first-start')
            wait_for(lambda: (down_cache / 'GeoLite2-ASN.mmdb.fallback').exists(), 'first-start fallback missing')
            assert (down_cache / 'FALLBACK_IN_USE').exists()
            assert not (down_cache / 'IPFire.db').exists()
            replacement.write_bytes(original)
            replacement.replace(source)
            wait_for(lambda: (down_cache / 'IPFire.db').exists(), 'periodic recovery did not activate verified data')
            assert not (down_cache / 'FALLBACK_IN_USE').exists()
            assert not (down_cache / 'GeoLite2-ASN.mmdb.fallback').exists()
            assert 'UNEXPECTED_AUTH_HEADER' not in run('docker', 'logs', name + '-origin').stdout
            print(json.dumps({'qualification': 'mmdb_ipfire', 'official_signature_and_native_cache': 'passed',
                              'ipv4_ipv6_country_continent_asn_organization': 'passed',
                              'scheduled_deterministic_update': 'passed', 'rollback_rejected': 'passed', 'tamper_retention': 'passed',
                              'first_start_fallback_and_recovery': 'passed', 'auth_header_isolation': 'passed'}))
        except BaseException:
            diagnostic = run('docker', 'logs', container, check=False)
            print(diagnostic.stdout[-3000:] + diagnostic.stderr[-3000:], file=sys.stderr)
            raise
        finally:
            run('docker', 'rm', '-f', *caches, name + '-origin', check=False)
            run('docker', 'network', 'rm', name, check=False)


if __name__ == '__main__':
    main()
