#!/usr/bin/env python3
"""Qualify Fleet encrypted boot ordering on a staging systemd/Docker host.

Uses synthetic keys, isolated systemd units and an already installed cell image.
Does not reboot the host, touch production material, or remove existing volumes.
Run: python3 tests/e2e/fleet_boot_material.py --host user@staging --known-hosts PATH
"""
from __future__ import annotations

import argparse
import subprocess
import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[2] / 'scripts'))
from cdnfoundry_fleet.provision import install_command

REMOTE = r'''
import io, json, os, pathlib, shlex, shutil, subprocess, tarfile, tempfile

def run(*args, input=None, check=True):
    result = subprocess.run(args, input=input, capture_output=True, timeout=90)
    if check and result.returncode:
        raise RuntimeError(' '.join(args[:3]) + ': ' + result.stderr.decode()[-1000:])
    return result

root = pathlib.Path(tempfile.mkdtemp(prefix='cdnf-boot-test-', dir='/run'))
runtime = pathlib.Path(tempfile.mkdtemp(prefix='cdnf-boot-test-', dir='/dev/shm'))
name = root.name
restore = name + '.service'
consumer = name + '-consumer.service'
unit_dir = pathlib.Path('/etc/systemd/system')
credential = root / 'material'
helper = pathlib.Path('/usr/local/libexec') / name
files = {'edge-runtime.crt': b'fixture certificate', 'edge-runtime.key': b'fixture private key',
         'edge-state-encryption.key': b'a' * 64, 'node.key': b'fixture node key'}
blob = io.BytesIO()
with tarfile.open(fileobj=blob, mode='w') as tar:
    for filename, value in files.items():
        info = tarfile.TarInfo(filename)
        info.size = len(value)
        info.mode = 0o400 if filename.endswith('.key') else 0o444
        tar.addfile(info, io.BytesIO(value))
command = INSTALL_COMMAND.replace('/dev/shm/cdnfoundry', str(runtime))
command = command.replace('/etc/credstore.encrypted/cdnfoundry-edge-material', str(credential))
command = command.replace('/usr/local/libexec/cdnfoundry-restore-edge-material', str(helper))
command = command.replace('/etc/systemd/system/docker.service.d', str(root / 'consumer.service.d'))
command = command.replace('cdnfoundry-edge-material.service', restore)
command = command.replace('docker.service', consumer)
# No swap/fstab changes in the isolated qualification.
start = command.index('swapoff -a;')
end = command.index('install -d -m 0700 /etc/credstore.encrypted;')
command = command[:start] + command[end:]
container = name + '-reader'
try:
    run('sh', '-c', command, input=blob.getvalue())
    image = json.loads(run('docker', 'inspect', 'cdnfoundry-cell-01-1').stdout)[0]['Image']
    marker = root / 'consumer-started'
    argv = ['docker', 'run', '--rm', '--name', container, '--user', '10101:10101', '--entrypoint', '/bin/sh',
            '--mount', f'type=bind,source={runtime}/edge-state-encryption.key,target=/run/fixture-key,readonly',
            image, '-c', 'test "$(wc -c < /run/fixture-key)" = 64']
    unit = ('[Unit]\nRequires=' + restore + '\nAfter=' + restore + '\n[Service]\nType=oneshot\n'
            'ExecStartPre=/usr/bin/touch ' + str(marker) + '\nExecStart=' + shlex.join(argv) + '\nTimeoutStartSec=60\n')
    (unit_dir / consumer).write_text(unit)
    run('systemctl', 'daemon-reload')
    for cycle in range(2):
        for filename in files:
            (runtime / filename).unlink()
            (runtime / filename).mkdir()
        run('systemctl', 'start', consumer)
        assert marker.is_file()
        assert {f: (runtime / f).read_bytes() for f in files} == files
        assert (runtime / 'edge-state-encryption.key').stat().st_mode & 0o777 == 0o400
        marker.unlink()
    print('PASS: two cold-memory restores preserve bytes and precede a real container reader', flush=True)
    credential.write_bytes(b'invalid credential')
    failed = run('systemctl', 'start', consumer, check=False)
    assert failed.returncode != 0 and not marker.exists()
    print('PASS: invalid credential blocks the dependent container', flush=True)
finally:
    run('systemctl', 'stop', consumer, restore, check=False)
    run('docker', 'rm', '-f', container, check=False)
    helper.unlink(missing_ok=True)
    (unit_dir / restore).unlink(missing_ok=True)
    (unit_dir / consumer).unlink(missing_ok=True)
    run('systemctl', 'daemon-reload', check=False)
    run('systemctl', 'reset-failed', consumer, restore, check=False)
    shutil.rmtree(root)
    shutil.rmtree(runtime)
'''


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--host', required=True)
    parser.add_argument('--known-hosts', required=True)
    args = parser.parse_args()
    if args.host.startswith('-'):
        parser.error('invalid host')
    source = 'INSTALL_COMMAND = ' + repr(install_command()) + '\n' + REMOTE
    done = subprocess.run(['ssh', '-o', 'BatchMode=yes', '-o', 'ConnectTimeout=10', '-o',
                           'UserKnownHostsFile=' + args.known_hosts, args.host, 'sudo -n python3 -'],
                          input=source, text=True, timeout=300)
    return done.returncode


if __name__ == '__main__':
    raise SystemExit(main())
