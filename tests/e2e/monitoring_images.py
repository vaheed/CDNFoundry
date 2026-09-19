#!/usr/bin/env python3
"""Qualify patched monitoring binaries through real scrape and alert HTTP APIs."""
from __future__ import annotations

import json
from pathlib import Path
import subprocess
import tempfile
import time
import urllib.parse
import urllib.request
import uuid

import yaml

ROOT = Path(__file__).resolve().parents[2]


def run(*args: str, check: bool = True) -> subprocess.CompletedProcess[str]:
    return subprocess.run(args, check=check, capture_output=True, text=True, timeout=120)


def main() -> None:
    name = 'cdnf-monitoring-' + uuid.uuid4().hex[:12]
    containers: list[str] = []
    with tempfile.TemporaryDirectory(prefix=name) as directory:
        root = Path(directory)
        root.chmod(0o755)
        config = {'global': {'scrape_interval': '1s'}, 'scrape_configs': [
            {'job_name': 'qualified-node', 'static_configs': [{'targets': ['node:9100']}]}]}
        (root / 'prometheus.yml').write_text(yaml.safe_dump(config))
        run('docker', 'network', 'create', name)
        try:
            for component in ('node-exporter', 'alertmanager', 'prometheus'):
                container = name+'-'+component
                containers.append(container)
                image = f'ghcr.io/vaheed/cdnfoundry-{component}:ci'
                port = {'node-exporter': '9100', 'alertmanager': '9093', 'prometheus': '9090'}[component]
                args = ['docker', 'run', '-d', '--name', container, '--network', name,
                        '--read-only', '--memory', '256m', '-p', '127.0.0.1::'+port]
                if component == 'node-exporter':
                    args += ['--network-alias', 'node']
                elif component == 'prometheus':
                    args += ['--tmpfs', '/prometheus:rw,uid=65534,gid=65534,size=64m',
                             '-v', f'{root}/prometheus.yml:/etc/prometheus/prometheus.yml:ro']
                else:
                    args += ['--tmpfs', '/alertmanager:rw,uid=65534,gid=65534,size=16m',
                             '-v', f'{ROOT}/docker/alertmanager/alertmanager.yml:/etc/alertmanager/alertmanager.yml:ro']
                run(*args, image)
            ports = {component: run('docker', 'port', name+'-'+component,
                                    {'node-exporter': '9100/tcp', 'alertmanager': '9093/tcp', 'prometheus': '9090/tcp'}[component]).stdout.strip().rsplit(':', 1)[1]
                     for component in ('node-exporter', 'alertmanager', 'prometheus')}
            opener = urllib.request.build_opener(urllib.request.ProxyHandler({}))

            def request(component: str, path: str, body: object | None = None) -> bytes:
                req = urllib.request.Request('http://127.0.0.1:'+ports[component]+path,
                                             data=None if body is None else json.dumps(body).encode(),
                                             headers={'Content-Type': 'application/json'})
                with opener.open(req, timeout=3) as response:
                    return response.read()

            query = '/api/v1/query?'+urllib.parse.urlencode({'query': 'up{job="qualified-node"}'})
            for _ in range(60):
                try:
                    result = json.loads(request('prometheus', query))['data']['result']
                    if result and result[0]['value'][1] == '1':
                        break
                except (OSError, ValueError):
                    pass
                time.sleep(0.5)
            else:
                raise RuntimeError('Patched Prometheus did not scrape the patched node exporter')
            assert b'node_uname_info' in request('node-exporter', '/metrics')
            request('alertmanager', '/-/ready')
            request('alertmanager', '/api/v2/alerts', [{'labels': {'alertname': 'ImageQualification', 'instance': name}}])
            alerts = json.loads(request('alertmanager', '/api/v2/alerts'))
            assert any(a['labels'].get('instance') == name for a in alerts)
            run('docker', 'exec', name+'-alertmanager', 'amtool', 'check-config', '/etc/alertmanager/alertmanager.yml')
            run('docker', 'exec', name+'-prometheus', 'promtool', 'check', 'config', '/etc/prometheus/prometheus.yml')
            identities = {c: run('docker', 'image', 'inspect', f'ghcr.io/vaheed/cdnfoundry-{c}:ci', '--format', '{{.Id}}').stdout.strip() for c in ports}
            print(json.dumps({'qualification': 'monitoring_images', 'images': identities, 'real_scrape_query': 'passed',
                              'node_metrics': 'passed', 'alert_ingestion': 'passed', 'configuration_tools': 'passed'}))
        finally:
            for container in reversed(containers):
                run('docker', 'rm', '-f', container, check=False)
            run('docker', 'network', 'rm', name, check=False)


if __name__ == '__main__':
    main()
