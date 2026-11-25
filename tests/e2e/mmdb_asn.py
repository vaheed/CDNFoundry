#!/usr/bin/env python3
"""Real dual-stack ASN downloads, bounded update cycles, retention and live Lua handle replacement.

Uses disposable containers and files only. Pass --database with a verified generated or custom ASN MMDB.
"""
from __future__ import annotations

import argparse
import hashlib
import json
import pathlib
import shutil
import subprocess
import sys
import tempfile
import time
import uuid

ROOT = pathlib.Path(__file__).resolve().parents[2]
IMAGE = 'cdnfoundry/mmdb-updater:fallback-test'


def run(*args: str, check: bool = True) -> subprocess.CompletedProcess[str]:
    result = subprocess.run(args, cwd=ROOT, text=True, capture_output=True, timeout=600)
    if check and result.returncode:
        raise RuntimeError(f'{args[:4]} failed: {result.stdout[-2000:]}{result.stderr[-2000:]}')
    return result


def wait_for(test, description: str) -> None:
    deadline = time.monotonic() + 45
    while time.monotonic() < deadline:
        if test():
            return
        time.sleep(0.5)
    raise AssertionError(description)


def main() -> None:
    global IMAGE
    parser = argparse.ArgumentParser()
    parser.add_argument('--database', type=pathlib.Path, required=True)
    parser.add_argument('--image', help='qualify an already-built updater image')
    args = parser.parse_args()
    if args.image:
        IMAGE = args.image
    else:
        run('docker', 'build', '-q', '-t', IMAGE, 'docker/mmdb-updater')
    name = 'cdnf-asn-' + uuid.uuid4().hex[:8]
    with tempfile.TemporaryDirectory(prefix=name) as temp:
        directory = pathlib.Path(temp)
        directory.chmod(0o755)
        served = directory / 'served'
        served.mkdir(mode=0o755)
        cache = directory / 'cache'
        cache.mkdir(mode=0o777)
        database = served / 'origin-asn.mmdb'
        shutil.copyfile(args.database, database)
        digest = hashlib.sha256(database.read_bytes()).hexdigest()
        (served / 'asn.sha256').write_text(digest + '  origin-asn.mmdb\n')
        fallback = subprocess.run(['docker', 'run', '--rm', '--entrypoint', 'cat', IMAGE,
                                   '/opt/fallback/GeoLite2-City.mmdb'], check=True, capture_output=True).stdout
        (served / 'city.mmdb').write_bytes(fallback)
        # Exercise repeated production update cycles without a five-minute wait in this isolated container.
        sleep = directory / 'sleep'
        sleep.write_text('#!/bin/sh\nexec /bin/sleep 1\n')
        sleep.chmod(0o755)
        run('docker', 'network', 'create', name)
        try:
            run('docker', 'run', '-d', '--name', name + '-origin', '--network', name,
                '-v', f'{served}:/srv:ro',
                'python:3.13-alpine@sha256:399babc8b49529dabfd9c922f2b5eea81d611e4512e3ed250d75bd2e7683f4b0',
                'python3', '-m', 'http.server', '8000', '-d', '/srv')
            source = f'http://{name}-origin:8000/'
            run('docker', 'run', '-d', '--name', name, '--network', name,
                '-v', f'{cache}:/mmdb', '-v', f'{sleep}:/usr/local/bin/sleep:ro',
                '-e', 'MMDB_PROVIDER=generic', '-e', 'MMDB_REQUIRE_VERIFICATION=true',
                '-e', 'MMDB_DOWNLOAD_RETRIES=0', '-e', 'MMDB_DOWNLOAD_INTERVAL_SECONDS=300',
                '-e', f'MMDB_DOWNLOAD_URL={source}city.mmdb',
                '-e', f'MMDB_EXPECTED_SHA256={hashlib.sha256(fallback).hexdigest()}',
                '-e', f'MMDB_ASN_DOWNLOAD_URL={source}origin-asn.mmdb',
                '-e', f'MMDB_ASN_CHECKSUM_URL={source}asn.sha256', IMAGE)
            target = cache / 'GeoLite2-ASN.mmdb'
            wait_for(lambda: (cache / 'GeoLite2-ASN.mmdb.revision').exists(), 'ASN did not activate')
            assert hashlib.sha256(target.read_bytes()).hexdigest() == digest
            for address in ('1.1.1.1', '2606:4700:4700::1111'):
                output = run('docker', 'exec', name, 'mmdblookup', '-f', '/mmdb/GeoLite2-ASN.mmdb', '-i', address).stdout
                assert '13335 <uint32>' in output and 'Cloudflare' in output, output
            stamp = (cache / 'GeoLite2-ASN.mmdb.last-update').read_text()
            wait_for(lambda: (cache / 'GeoLite2-ASN.mmdb.last-update').read_text() != stamp,
                     'scheduled update did not run')
            # A correct checksum must not make a wrong-schema or empty MMDB usable as ASN data.
            database.write_bytes(fallback)
            (served / 'asn.sha256').write_text(hashlib.sha256(fallback).hexdigest())
            wait_for(lambda: 'no usable IPv4/IPv6 ASN records' in run('docker', 'logs', name).stdout,
                     'wrong-schema ASN not refused')
            assert hashlib.sha256(target.read_bytes()).hexdigest() == digest
            (served / 'asn.sha256').write_text('0' * 64)
            wait_for(lambda: 'checksum mismatch' in run('docker', 'logs', name).stdout,
                     'bad checksum not refused')
            assert hashlib.sha256(target.read_bytes()).hexdigest() == digest
            (served / 'asn.sha256').unlink()
            wait_for(lambda: 'published checksum is not a SHA-256' in run('docker', 'logs', name).stdout,
                     'provider outage not detected')
            assert hashlib.sha256(target.read_bytes()).hexdigest() == digest
            assert not (cache / 'GeoLite2-ASN.mmdb.fallback').exists()
            # A successful ASN update must not clear City's fallback or advance its freshness.
            (served / 'city.mmdb').unlink()
            (cache / 'FALLBACK_IN_USE').write_text('primary degraded')
            primary_stamp = (cache / 'LAST_UPDATE').read_text()
            asn_stamp = (cache / 'GeoLite2-ASN.mmdb.last-update').read_text()
            shutil.copyfile(target, database)
            (served / 'asn.sha256').write_text(digest)
            wait_for(lambda: (cache / 'GeoLite2-ASN.mmdb.last-update').read_text() != asn_stamp,
                     'ASN did not recover independently of City')
            assert (cache / 'FALLBACK_IN_USE').read_text() == 'primary degraded'
            assert (cache / 'LAST_UPDATE').read_text() == primary_stamp
            (cache / 'fallback.mmdb').write_bytes(fallback)
            run('docker', 'run', '--rm', '-v', f'{cache}:/data:ro',
                '-v', f'{ROOT / "docker/openresty"}:/module:ro',
                '-v', f'{ROOT / "tests/e2e/mmdb_reload.lua"}:/test.lua:ro',
                '-e', 'GEOIP_ASN_DATABASE=/tmp/asn.mmdb', '--entrypoint', 'sh',
                'alpine:3.24@sha256:28bd5fe8b56d1bd048e5babf5b10710ebe0bae67db86916198a6eec434943f8b', '-c',
                'apk add --no-cache lua5.1 lua5.1-maxminddb >/dev/null && lua5.1 /test.lua')
            print(json.dumps({'qualification': 'mmdb_asn', 'ipv4_ipv6_asn_and_organization': 'passed',
                              'scheduled_update': 'passed', 'wrong_schema_checksum_outage_retention': 'passed',
                              'live_reader_reload_and_retention': 'passed'}))
        except BaseException:
            diagnostic = run('docker', 'logs', name, check=False)
            print(diagnostic.stdout[-3000:] + diagnostic.stderr[-3000:], file=sys.stderr)
            raise
        finally:
            run('docker', 'rm', '-f', name, name + '-origin', check=False)
            run('docker', 'network', 'rm', name, check=False)


if __name__ == '__main__':
    main()
