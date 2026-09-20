#!/usr/bin/env python3
"""Read-only staging Grafana API checks; no browser or workload mutation."""

import argparse
import base64
import json
import os
import stat
import tempfile
import urllib.request
from datetime import datetime, timezone
from pathlib import Path

from staging_health import NoRedirect, https_origin


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--grafana', required=True, type=https_origin)
    parser.add_argument('--credentials', required=True, type=Path,
                        help='Mode-0600 JSON containing username and password')
    parser.add_argument('--report', required=True, type=Path)
    args = parser.parse_args()
    if stat.S_IMODE(args.credentials.stat().st_mode) != 0o600:
        parser.error('Credential file must have mode 0600')
    credentials = json.loads(args.credentials.read_text())
    authorization = 'Basic ' + base64.b64encode(
        (credentials['username'] + ':' + credentials['password']).encode()
    ).decode()
    opener = urllib.request.build_opener(NoRedirect())
    checks = []

    def get(path):
        request = urllib.request.Request(args.grafana + path, headers={
            'Authorization': authorization, 'Accept': 'application/json'})
        with opener.open(request, timeout=40) as response:
            raw = response.read(2 * 1024 * 1024 + 1)
            if len(raw) > 2 * 1024 * 1024:
                raise ValueError('Response exceeds 2 MiB')
            return json.loads(raw)

    def check(name, predicate):
        try:
            if not predicate():
                raise ValueError('Unexpected API result')
            checks.append({'check': name, 'outcome': 'passed'})
        except Exception as error:
            checks.append({'check': name, 'outcome': 'failed',
                           'error_type': type(error).__name__})
        print(name, checks[-1]['outcome'], flush=True)

    for uid in ['prometheus', 'clickhouse', 'control-db', 'loki']:
        check('datasource-' + uid, lambda uid=uid: get(
            '/api/datasources/uid/' + uid + '/health').get('status') in ['OK', 'Success'])
    expected = {'cdnf-system-command-center', 'cdnf-domain-command-center'}
    check('exactly-two-dashboards', lambda: {
        row['uid'] for row in get('/api/search?type=dash-db')} == expected)
    for uid in sorted(expected):
        check(uid, lambda uid=uid: get('/api/dashboards/uid/' + uid).get(
            'dashboard', {}).get('uid') == uid)
    passed = all(row['outcome'] == 'passed' for row in checks)
    report = {'scope': 'Grafana datasource health and dashboard API availability only',
              'recorded_at': datetime.now(timezone.utc).isoformat(),
              'outcome': 'passed' if passed else 'failed', 'checks': checks}
    args.report.parent.mkdir(parents=True, exist_ok=True)
    descriptor, candidate = tempfile.mkstemp(prefix='.staging-observability-', dir=args.report.parent)
    try:
        with os.fdopen(descriptor, 'w') as stream:
            json.dump(report, stream, indent=2)
            stream.write('\n')
        os.replace(candidate, args.report)
    finally:
        Path(candidate).unlink(missing_ok=True)
    return 0 if passed else 1


if __name__ == '__main__':
    raise SystemExit(main())
