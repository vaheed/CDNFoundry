#!/usr/bin/env python3
"""Qualify the GeoIP updater's fallback and download verification on disposable containers."""
from __future__ import annotations

import hashlib
import json
import pathlib
import subprocess
import tempfile
import time
import uuid

ROOT = pathlib.Path(__file__).resolve().parents[2]
IMAGE = 'cdnfoundry/mmdb-updater:fallback-test'


def run(*args: str, check: bool = True) -> subprocess.CompletedProcess[str]:
    result = subprocess.run(args, cwd=ROOT, text=True, capture_output=True, timeout=600)
    if check and result.returncode:
        raise RuntimeError(f'{args[:4]} failed: {result.stdout[-800:]}{result.stderr[-800:]}')
    return result


def main() -> None:
    suffix = uuid.uuid4().hex[:8]
    network = f'cdnf-mmdb-{suffix}'
    run('docker', 'build', '-q', '-t', IMAGE, 'docker/mmdb-updater')
    with tempfile.TemporaryDirectory(prefix=network) as directory:
        served = pathlib.Path(directory) / 'served'
        served.mkdir(mode=0o755)
        with open(served / 'db.mmdb', 'wb') as handle:
            handle.write(subprocess.run(['docker', 'run', '--rm', '--entrypoint', 'cat', IMAGE, '/opt/fallback/GeoLite2-City.mmdb'], check=True, capture_output=True).stdout)
        digest = hashlib.sha256((served / 'db.mmdb').read_bytes()).hexdigest()
        run('docker', 'network', 'create', network)
        run('docker', 'run', '-d', '--name', f'{network}-origin', '--network', network, '-v', f'{served}:/srv:ro',
            'python:3.13-alpine@sha256:399babc8b49529dabfd9c922f2b5eea81d611e4512e3ed250d75bd2e7683f4b0', 'python3', '-m', 'http.server', '8000', '-d', '/srv')
        time.sleep(2)

        def updater(volume: str, *env: str) -> tuple[str, str]:
            name = f'{network}-{volume}'
            run('docker', 'volume', 'create', name)
            args = ['docker', 'run', '-d', '--name', name, '--network', network, '-v', f'{name}:/mmdb',
                    '-e', f'MMDB_DOWNLOAD_URL=http://{network}-origin:8000/db.mmdb', '-e', 'MMDB_DOWNLOAD_RETRIES=0']
            for item in env:
                args += ['-e', item]
            run(*args, IMAGE)
            time.sleep(8)
            listing = run('docker', 'run', '--rm', '-v', f'{name}:/mmdb:ro', '--entrypoint', 'ls', IMAGE, '-A', '/mmdb').stdout.split()
            logs = run('docker', 'logs', name).stdout + run('docker', 'logs', name, check=False).stderr
            return ' '.join(listing), logs

        try:
            unreachable, _ = updater('down', 'MMDB_DOWNLOAD_URL=http://127.0.0.1:9/none')
            assert 'GeoLite2-City.mmdb' in unreachable and 'FALLBACK_IN_USE' in unreachable, unreachable

            refused, logs = updater('unverified', 'MMDB_REQUIRE_VERIFICATION=true')
            assert 'FALLBACK_IN_USE' in refused and 'refusing an unverified download' in logs, (refused, logs)

            wrong, logs = updater('wrong', 'MMDB_REQUIRE_VERIFICATION=true', f'MMDB_EXPECTED_SHA256={"0" * 64}')
            assert 'FALLBACK_IN_USE' in wrong and 'checksum mismatch' in logs, (wrong, logs)

            good, logs = updater('good', 'MMDB_REQUIRE_VERIFICATION=true', f'MMDB_EXPECTED_SHA256={digest}')
            assert 'FALLBACK_IN_USE' not in good and 'LAST_UPDATE' in good, (good, logs)
            print(json.dumps({'qualification': 'mmdb_fallback', 'image': IMAGE, 'starts_without_a_source': 'passed',
                              'unverified_refused': 'passed', 'checksum_mismatch_refused': 'passed', 'verified_activated': 'passed'}))
        finally:
            for volume in ('down', 'unverified', 'wrong', 'good'):
                run('docker', 'rm', '-f', f'{network}-{volume}', check=False)
                run('docker', 'volume', 'rm', f'{network}-{volume}', check=False)
            run('docker', 'rm', '-f', f'{network}-origin', check=False)
            run('docker', 'network', 'rm', network, check=False)


if __name__ == '__main__':
    main()
