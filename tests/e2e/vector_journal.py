#!/usr/bin/env python3
"""Read a synthetic binary journal with the exact released Vector/journalctl pair."""
from __future__ import annotations

import argparse
import json
from pathlib import Path
import subprocess
import tempfile
import time
import uuid

import yaml


def run(*args: str, check: bool = True) -> subprocess.CompletedProcess[str]:
    return subprocess.run(args, check=check, capture_output=True, text=True, timeout=180)


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument('--image', default='ghcr.io/vaheed/cdnfoundry-vector:ci')
    image = parser.parse_args().image
    name = 'cdnf-vector-journal-' + uuid.uuid4().hex[:12]
    marker = name+'-event'
    with tempfile.TemporaryDirectory(prefix=name) as directory:
        root = Path(directory)
        root.chmod(0o755)
        (root / 'journal').mkdir()
        now = int(time.time() * 1_000_000)
        (root / 'event.export').write_text(f'__REALTIME_TIMESTAMP={now}\n__MONOTONIC_TIMESTAMP=1\n_BOOT_ID=0123456789abcdef0123456789abcdef\n_MACHINE_ID=0123456789abcdef0123456789abcdef\n_SYSTEMD_UNIT=cdnf-qualification.service\nPRIORITY=6\nMESSAGE={marker}\n\n')
        # Install the journal writer only in this disposable helper. The released
        # collector needs journalctl, and does not contain a remote journal server.
        run('docker', 'run', '--rm', '--memory', '512m', '--entrypoint', 'sh',
            '-v', f'{root}:/fixture', image, '-ec',
            'apt-get update -qq && apt-get install -y --no-install-recommends systemd-journal-remote >/dev/null && '
            '/usr/lib/systemd/systemd-journal-remote --output=/fixture/journal/fixture.journal /fixture/event.export')
        journal = run('docker', 'run', '--rm', '--network', 'none', '--entrypoint', 'journalctl',
                      '-v', f'{root}:/fixture:ro', image, '--directory=/fixture/journal', '--no-pager', '-o', 'json').stdout
        assert marker in journal, 'Synthetic binary journal did not contain the fixture event'
        document = {'data_dir': '/tmp', 'sources': {'journal': {'type': 'journald',
                    'current_boot_only': False, 'since_now': False,
                    'extra_args': ['--directory=/fixture/journal']}},
                    'sinks': {'result': {'type': 'file', 'inputs': ['journal'], 'path': '/result/events.json',
                                         'encoding': {'codec': 'json'}}}}
        (root / 'vector.yaml').write_text(yaml.safe_dump(document))
        (root / 'result').mkdir()
        try:
            run('docker', 'run', '-d', '--name', name, '--network', 'none', '--read-only',
                '--memory', '256m', '--tmpfs', '/tmp:rw,size=32m',
                '-v', f'{root}:/fixture:ro', '-v', f'{root}/result:/result', image,
                '--config', '/fixture/vector.yaml')
            for _ in range(60):
                output = root / 'result/events.json'
                rows = [json.loads(line) for line in output.read_text().splitlines()] if output.exists() else []
                if any(row.get('message') == marker for row in rows):
                    break
                time.sleep(0.5)
            else:
                raise RuntimeError('Released Vector did not consume the synthetic journal event: '+run('docker', 'logs', name, check=False).stderr+' rows='+repr(rows))
            identity = run('docker', 'image', 'inspect', image, '--format', '{{.Id}}').stdout.strip()
            print(json.dumps({'qualification': 'vector_journal', 'image': identity, 'real_binary_journal': 'passed',
                              'journalctl_reader': 'passed', 'vector_event_delivery': 'passed'}))
        finally:
            run('docker', 'rm', '-f', name, check=False)


if __name__ == '__main__':
    main()
