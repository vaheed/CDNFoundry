#!/usr/bin/env python3
"""Exercise official PostgreSQL entrypoint and minor upgrade on disposable data."""
from __future__ import annotations

import argparse
import json
import os
from pathlib import Path
import subprocess
import tempfile
import time
import uuid

PREVIOUS = 'postgres:18.4-alpine@sha256:9a8afca54e7861fd90fab5fdf4c42477a6b1cb7d293595148e674e0a3181de15'


def run(*args: str, check: bool = True) -> subprocess.CompletedProcess[str]:
    return subprocess.run(args, check=check, capture_output=True, text=True, timeout=120)


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument('--image', default='ghcr.io/vaheed/cdnfoundry-postgres:ci')
    image = parser.parse_args().image
    name = 'cdnf-postgres-' + uuid.uuid4().hex[:12]
    with tempfile.TemporaryDirectory(prefix=name) as directory:
        data = Path(directory) / 'data'
        data.mkdir(mode=0o777)
        data.chmod(0o777)

        def query(sql: str, *, password: str = 'isolated-qualification', check: bool = True) -> subprocess.CompletedProcess[str]:
            return run('docker', 'exec', '-e', 'PGPASSWORD='+password, name, 'psql',
                       '-h', '127.0.0.1', '-U', 'qualification', '-d', 'qualification',
                       '-v', 'ON_ERROR_STOP=1', '-Atc', sql, check=check)

        def start(reference: str) -> None:
            run('docker', 'run', '-d', '--name', name, '--network', 'none', '--memory', '256m',
                '--log-opt', 'max-size=10m', '--log-opt', 'max-file=2',
                '-e', 'POSTGRES_USER=qualification', '-e', 'POSTGRES_PASSWORD=isolated-qualification',
                '-e', 'POSTGRES_DB=qualification', '-e', 'POSTGRES_INITDB_ARGS=--auth-host=scram-sha-256', '-v', f'{data}:/var/lib/postgresql', reference)
            for _ in range(60):
                if query('SELECT 1', check=False).returncode == 0:
                    return
                time.sleep(0.5)
            raise RuntimeError('Isolated PostgreSQL did not initialize')

        try:
            start(PREVIOUS)
            query("CREATE TABLE retained (id bigint PRIMARY KEY, payload jsonb NOT NULL); INSERT INTO retained VALUES (1, '{\"kept\": true}');")
            run('docker', 'stop', '--time', '30', name)
            run('docker', 'rm', name)
            start(image)
            assert query("SELECT payload->>'kept' FROM retained WHERE id=1").stdout.strip() == 'true'
            assert query('SHOW server_version').stdout.startswith('18.6')
            assert query('SELECT 1', password='incorrect', check=False).returncode != 0
            query("BEGIN; INSERT INTO retained VALUES (2, '{}'); ROLLBACK;")
            assert query('SELECT count(*) FROM retained').stdout.strip() == '1'
            query("INSERT INTO retained VALUES (2, '{\"new\": true}')")
            run('docker', 'restart', '--time', '30', name)
            for _ in range(60):
                if query('SELECT 1', check=False).returncode == 0:
                    break
                time.sleep(0.5)
            assert query('SELECT count(*) FROM retained').stdout.strip() == '2'
            assert run('docker', 'exec', name, 'gosu', 'postgres', 'id', '-un').stdout.strip() == 'postgres'
            identity = run('docker', 'image', 'inspect', image, '--format', '{{.Id}}').stdout.strip()
            print(json.dumps({'qualification': 'postgres_upgrade', 'image': identity, 'retained_data': 'passed',
                              'authentication': 'passed', 'transaction_rollback': 'passed', 'restart': 'passed',
                              'entrypoint_privilege_drop': 'passed'}))
        finally:
            run('docker', 'rm', '-f', name, check=False)
            # Entrypoints chown these disposable bind mounts to their database UID.
            # Return ownership before TemporaryDirectory removes them on non-root CI.
            run('docker', 'run', '--rm', '--network', 'none', '--user', '0:0',
                '--memory', '64m', '--pids-limit', '32', '--entrypoint', 'chown',
                '-v', f'{data}:/qualification-data', PREVIOUS,
                '-R', f'{os.getuid()}:{os.getgid()}', '/qualification-data')


if __name__ == '__main__':
    main()
