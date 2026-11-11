#!/usr/bin/env python3
"""Qualify that no process of the core image runs as root and that the shipped PHP settings are in effect.

Starts the image's real entrypoint with the production capability set. The working-tree entrypoint and php.ini
are mounted over the image's copies so the check also covers a change that is not built into an image yet.
"""
import os
import pathlib
import subprocess
import time
import uuid

ROOT = pathlib.Path(__file__).resolve().parents[2]
IMAGE = os.environ.get('CDNF_CORE_IMAGE', 'cdnfoundry-dev-core:latest')
CAPS = ['--cap-drop', 'ALL', '--cap-add', 'CHOWN', '--cap-add', 'DAC_OVERRIDE', '--cap-add', 'SETGID', '--cap-add', 'SETUID', '--security-opt', 'no-new-privileges:true']
MOUNTS = ['-v', f'{ROOT}/core/docker/php/entrypoint.sh:/ep:ro', '-v', f'{ROOT}/core/docker/php/cdnfoundry.ini:/usr/local/etc/php/conf.d/zz-cdnfoundry.ini:ro']


def docker(*args: str, check: bool = True) -> subprocess.CompletedProcess[str]:
    result = subprocess.run(['docker', *args], text=True, capture_output=True, check=False)
    if check and result.returncode:
        raise RuntimeError(f'docker {args[0]} failed: {result.stdout[-800:]}{result.stderr[-800:]}')
    return result


def main() -> None:
    name = f'cdnf-core-user-{uuid.uuid4().hex[:10]}'
    try:
        docker('run', '-d', '--rm', '--name', name, *CAPS, *MOUNTS, '--entrypoint', 'sh', IMAGE, '/ep', 'php-fpm', '-F')
        for _ in range(60):
            if 'master process' in docker('exec', name, 'ps', '-o', 'args', check=False).stdout:
                break
            time.sleep(1)
        processes = docker('exec', name, 'ps', '-o', 'user,args').stdout.splitlines()[1:]
        owners = {line.split(None, 1)[0] for line in processes if 'ps -o' not in line}
        assert owners == {'www-data'}, f'a core-image process is not www-data: {processes}'
        assert any('php-fpm: master' in line for line in processes), processes
        assert any('NOTICE: ready to handle connections' in line for line in docker('logs', name).stderr.splitlines()), 'FPM logs do not reach the container log'
        print('fpm: every process is www-data and logs reach the container log')

        settings = docker('exec', name, 'php', '-r', 'foreach (["expose_php", "display_errors", "session.upload_progress.enabled"] as $k) echo $k, "=[", ini_get($k), "]\\n";').stdout
        for key in ('expose_php', 'display_errors', 'session.upload_progress.enabled'):
            assert f'{key}=[]' in settings, settings
        print('php settings: hardened')
    finally:
        docker('rm', '-f', name, check=False)

    # A one-off command through the entrypoint (the path Horizon, the scheduler, and migrations take) also drops root.
    uid = docker('run', '--rm', *CAPS, *MOUNTS, '--entrypoint', 'sh', IMAGE, '/ep', 'id', '-u').stdout.strip()
    assert uid != '0', 'one-off command still runs as root'
    print(f'one-off commands: uid {uid}')


if __name__ == '__main__':
    main()
