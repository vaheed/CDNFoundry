#!/usr/bin/env python3
"""Qualify the tracked ClickHouse migration service on a disposable ClickHouse.

Applies every migration to a fresh database, proves a second run changes nothing, that a new
migration is applied once, and that editing an applied migration is refused.
"""
from __future__ import annotations

import json
from pathlib import Path
import shutil
import subprocess
import tempfile
import time
import uuid

import yaml

from docker_images import ensure_image

ROOT = Path(__file__).resolve().parents[2]


def run(*args: str, check: bool = True) -> subprocess.CompletedProcess[str]:
    return subprocess.run(args, check=check, text=True, capture_output=True, timeout=300)


def main() -> None:
    image = yaml.safe_load((ROOT / 'compose.prod.yml').read_text())['services']['clickhouse']['image']
    ensure_image(image)
    name = 'cdnf-chmigrate-' + uuid.uuid4().hex[:10]
    password = 'isolated-clickhouse'
    migrations = sorted((ROOT / 'docker/clickhouse/migrations').glob('*.sql'))
    with tempfile.TemporaryDirectory(prefix=name) as directory:
        work = Path(directory)
        run('docker', 'network', 'create', '--internal', name)
        try:
            run('docker', 'run', '-d', '--name', name, '--network', name, '--network-alias', 'clickhouse', '--memory', '2g',
                '-e', 'CLICKHOUSE_DB=cdnf', '-e', 'CLICKHOUSE_USER=cdnf', '-e', f'CLICKHOUSE_PASSWORD={password}',
                '-e', 'CLICKHOUSE_DEFAULT_ACCESS_MANAGEMENT=1',
                '-e', 'GRAFANA_CLICKHOUSE_PASSWORD=isolated-grafana', '-e', 'CLICKHOUSE_INGEST_PASSWORD=isolated-ingest',
                '-e', 'CLICKHOUSE_READER_PASSWORD=isolated-reader', '-e', 'CLICKHOUSE_ZONES_PASSWORD=isolated-zones',
                '-v', f'{ROOT}/docker/clickhouse/init.sql:/docker-entrypoint-initdb.d/10-init.sql:ro',
                '-v', f'{ROOT}/docker/clickhouse/users.d/grafana.xml:/etc/clickhouse-server/users.d/grafana.xml:ro',
                '-v', f'{ROOT}/docker/clickhouse/users.d/telemetry.xml:/etc/clickhouse-server/users.d/telemetry.xml:ro', image)
            for _ in range(90):
                if run('docker', 'exec', name, 'clickhouse-client', '--user', 'cdnf', '--password', password,
                       '--query', 'SELECT count() FROM cdnf.edge_events', check=False).returncode == 0:
                    break
                time.sleep(1)
            else:
                raise RuntimeError('Isolated ClickHouse did not initialize')

            def migrate(directory: Path, *, check: bool = True) -> subprocess.CompletedProcess[str]:
                return run('docker', 'run', '--rm', '--network', name, '--memory', '256m', '--entrypoint', 'sh',
                           '-e', 'CLICKHOUSE_HOST=clickhouse', '-e', 'CLICKHOUSE_USER=cdnf', '-e', f'CLICKHOUSE_PASSWORD={password}',
                           '-v', f'{ROOT}/docker/clickhouse/migrate.sh:/migrate.sh:ro', '-v', f'{directory}:/migrations:ro',
                           image, '/migrate.sh', check=check)

            def recorded() -> list[str]:
                out = run('docker', 'exec', name, 'clickhouse-client', '--user', 'cdnf', '--password', password,
                          '--query', 'SELECT name FROM cdnf.schema_migrations ORDER BY name').stdout
                return out.split()

            first = migrate(ROOT / 'docker/clickhouse/migrations')
            assert f'clickhouse_migrations_applied={len(migrations)}' in first.stdout, first.stdout
            assert recorded() == [path.name for path in migrations], recorded()

            second = migrate(ROOT / 'docker/clickhouse/migrations')
            assert 'clickhouse_migrations_applied=0' in second.stdout and 'applied 20' not in second.stdout, second.stdout

            changed = work / 'changed'
            shutil.copytree(ROOT / 'docker/clickhouse/migrations', changed)
            (changed / '2099_01_01_probe.sql').write_text('CREATE TABLE IF NOT EXISTS cdnf.migration_probe (x UInt8) ENGINE = Memory;\n')
            added = migrate(changed)
            assert 'clickhouse_migrations_applied=1' in added.stdout and 'applied 2099_01_01_probe.sql' in added.stdout, added.stdout
            exists = run('docker', 'exec', name, 'clickhouse-client', '--user', 'cdnf', '--password', password,
                         '--query', 'EXISTS TABLE cdnf.migration_probe').stdout.strip()
            assert exists == '1', exists

            edited = work / 'edited'
            shutil.copytree(changed, edited)
            first_file = edited / migrations[0].name
            first_file.write_text(first_file.read_text() + '\n-- edited after it was applied\n')
            refused = migrate(edited, check=False)
            assert refused.returncode != 0 and 'changed after it was applied' in refused.stderr, (refused.returncode, refused.stderr)

            print(json.dumps({'qualification': 'clickhouse_migrations', 'image': image, 'migrations': len(migrations),
                              'fresh_apply': 'passed', 'rerun_is_noop': 'passed', 'new_migration_applied_once': 'passed',
                              'edited_migration_refused': 'passed'}))
        finally:
            run('docker', 'rm', '-f', name, check=False)
            run('docker', 'network', 'rm', name, check=False)


if __name__ == '__main__':
    main()
