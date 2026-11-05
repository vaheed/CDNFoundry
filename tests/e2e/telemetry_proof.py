#!/usr/bin/env python3
"""A real OpenResty cell must prove its access-log lines with the node secret."""
from __future__ import annotations

import hashlib
import json
from pathlib import Path
import subprocess
import tempfile
import time
import uuid

from openresty_runtime import state

ROOT = Path(__file__).resolve().parents[2]
TOKEN = 'telemetry-proof-qualification-only'


def run(*args: str, check: bool = True) -> subprocess.CompletedProcess[str]:
    result = subprocess.run(args, cwd=ROOT, text=True, capture_output=True, timeout=300)
    if check and result.returncode:
        raise RuntimeError(f'{args[0]} failed ({result.returncode}): {result.stderr[-2000:]}')
    return result


def main() -> None:
    image = 'cdnfoundry/edge-runtime:telemetry-proof'
    run('docker', 'build', '-f', 'docker/openresty/Dockerfile', '-t', image, '.')
    name = 'cdnf-telemetry-proof-' + uuid.uuid4().hex[:12]
    with tempfile.TemporaryDirectory(prefix=name) as directory:
        target = Path(directory)
        target.chmod(0o755)
        (target / 'runtime.json').write_text(json.dumps(state({}, 1)))
        key, certificate = target / 'tls.key', target / 'tls.crt'
        run('openssl', 'req', '-x509', '-newkey', 'rsa:2048', '-nodes', '-days', '1', '-subj', '/CN=proof.test',
            '-keyout', str(key), '-out', str(certificate))
        key.chmod(0o644)
        mounts = {
            target: '/fixtures', certificate: '/run/edge/tls.crt', key: '/run/edge/tls.key',
            ROOT / 'docker/openresty/runtime.lua': '/etc/cdnfoundry/runtime.lua',
            ROOT / 'docker/nginx/openresty.conf': '/usr/local/openresty/nginx/conf/nginx.conf',
            ROOT / 'docker/nginx/edge-runtime.conf': '/etc/nginx/conf.d/default.conf',
            ROOT / 'docker/nginx/origin-proxy.conf': '/etc/nginx/origin-proxy.conf',
            ROOT / 'docker/nginx/proxy-cache.conf': '/etc/nginx/proxy-cache.conf',
            ROOT / 'docker/nginx/cache-upstream.conf': '/etc/nginx/cache-upstream.conf',
        }
        command = ['docker', 'run', '-d', '--name', name, '--add-host', 'vector:127.0.0.1', '--memory', '512m', '--pids-limit', '128',
                   '--tmpfs', '/tmp:rw,nosuid,size=32m,mode=1777', '--tmpfs', '/var/cache/nginx:rw,nosuid,size=32m',
                   '-e', 'EDGE_RUNTIME_FILE=/fixtures/runtime.json', '-e', 'EDGE_STATUS_TOKEN=' + TOKEN]
        for source, destination in mounts.items():
            command.extend(['--mount', f'type=bind,source={source},target={destination},readonly'])
        try:
            run(*command, image)
            expected = hashlib.sha256(('cdnf-telemetry-v1|' + TOKEN).encode()).hexdigest()
            line = None
            for _ in range(60):
                run('docker', 'exec', name, 'sh', '-c', "wget -q -O /dev/null --header 'Host: unknown.proof.test' http://127.0.0.1:8080/ || true", check=False)
                for raw in run('docker', 'logs', name, check=False).stdout.splitlines():
                    if raw.startswith('{') and 'telemetry_proof' in raw:
                        line = json.loads(raw)
                if line:
                    break
                time.sleep(0.5)
            assert line is not None, run('docker', 'logs', name, check=False).stderr[-1500:]
            assert line['telemetry_proof'] == expected, line['telemetry_proof']
            print(json.dumps({'qualification': 'telemetry_proof', 'cell_access_log_carries_node_proof': 'passed'}))
        finally:
            run('docker', 'rm', '-f', name, check=False)


if __name__ == '__main__':
    main()
