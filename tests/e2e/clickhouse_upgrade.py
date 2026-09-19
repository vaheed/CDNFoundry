#!/usr/bin/env python3
"""Qualify ClickHouse schema, aggregation and data continuity on disposable files."""
from __future__ import annotations

import json
from pathlib import Path
import subprocess
import tempfile
import time
import uuid

import yaml

ROOT = Path(__file__).resolve().parents[2]
PREVIOUS = 'clickhouse/clickhouse-server:26.3.12.3-alpine@sha256:c246f5c07d647c5b4f50e99609d1fe3a79e58880ef28c2043edc4bb55762a65d'


def run(*args: str, check: bool = True) -> subprocess.CompletedProcess[str]:
    return subprocess.run(args, check=check, text=True, capture_output=True, timeout=180)


def main() -> None:
    current = yaml.safe_load((ROOT / 'compose.prod.yml').read_text())['services']['clickhouse']['image']
    name = 'cdnf-clickhouse-' + uuid.uuid4().hex[:12]
    with tempfile.TemporaryDirectory(prefix=name) as directory:
        data = Path(directory) / 'data'
        data.mkdir(mode=0o777)
        data.chmod(0o777)

        def query(sql: str, *, readonly: bool = False, check: bool = True) -> subprocess.CompletedProcess[str]:
            return run('docker', 'exec', name, 'clickhouse-client', '--user',
                       'cdnf_grafana' if readonly else 'cdnf', '--password',
                       'isolated-grafana' if readonly else 'isolated-clickhouse',
                       '--query', sql, check=check)

        def start(image: str) -> None:
            run('docker', 'run', '-d', '--name', name, '--network', 'none', '--memory', '2g',
                '--log-opt', 'max-size=10m', '--log-opt', 'max-file=2',
                '-e', 'CLICKHOUSE_DB=cdnf', '-e', 'CLICKHOUSE_USER=cdnf',
                '-e', 'CLICKHOUSE_PASSWORD=isolated-clickhouse',
                '-e', 'CLICKHOUSE_DEFAULT_ACCESS_MANAGEMENT=1',
                '-e', 'GRAFANA_CLICKHOUSE_PASSWORD=isolated-grafana',
                '-v', f'{data}:/var/lib/clickhouse',
                '-v', f'{ROOT}/docker/clickhouse/init.sql:/docker-entrypoint-initdb.d/10-init.sql:ro',
                '-v', f'{ROOT}/docker/clickhouse/users.d/grafana.xml:/etc/clickhouse-server/users.d/grafana.xml:ro', image)
            for _ in range(90):
                if query('SELECT count() FROM cdnf.edge_events', check=False).returncode == 0:
                    return
                time.sleep(1)
            raise RuntimeError('Isolated ClickHouse did not initialize')

        def insert() -> None:
            query("INSERT INTO cdnf.edge_events (occurred_at, domain_id, hostname, method, path, status, bytes_out) VALUES (now(), 42, 'qualification.test', 'GET', '/', 200, 123)")

        def verify(count: int) -> None:
            assert query('SELECT count() FROM cdnf.edge_events').stdout.strip() == str(count)
            assert query('SELECT sum(requests) FROM cdnf.edge_hourly', readonly=True).stdout.strip() == str(count)
            assert query('TRUNCATE TABLE cdnf.edge_events', readonly=True, check=False).returncode != 0

        try:
            start(PREVIOUS)
            insert()
            verify(1)
            run('docker', 'stop', '--time', '30', name)
            run('docker', 'rm', name)
            start(current)
            verify(1)
            for migration in sorted((ROOT / 'docker/clickhouse/migrations').glob('*.sql')):
                result = subprocess.run(['docker', 'exec', '-i', name, 'clickhouse-client', '--user', 'cdnf',
                                         '--password', 'isolated-clickhouse', '--multiquery'],
                                        input=migration.read_text(), text=True, capture_output=True, timeout=60)
                result.check_returncode()
            insert()
            verify(2)
            run('docker', 'restart', '--time', '30', name)
            for _ in range(60):
                if query('SELECT 1', check=False).returncode == 0:
                    break
                time.sleep(1)
            verify(2)
            print(json.dumps({'qualification': 'clickhouse_upgrade', 'previous': PREVIOUS, 'current': current,
                              'schema_migrations': 'passed', 'data_continuity': 'passed',
                              'aggregate_queries': 'passed', 'readonly_permissions': 'passed', 'restart': 'passed'}))
        finally:
            run('docker', 'rm', '-f', name, check=False)


if __name__ == '__main__':
    main()
