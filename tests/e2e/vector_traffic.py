#!/usr/bin/env python3
"""Exercise production Vector HTTP decoders and redaction with synthetic events."""
from __future__ import annotations

import json
from pathlib import Path
import subprocess
import tempfile
import time
import urllib.request
import uuid

import yaml

ROOT = Path(__file__).resolve().parents[2]


def run(*args: str, check: bool = True) -> subprocess.CompletedProcess[str]:
    return subprocess.run(args, check=check, capture_output=True, text=True, timeout=60)


def main() -> None:
    name = 'cdnf-vector-traffic-' + uuid.uuid4().hex[:12]
    image = 'ghcr.io/vaheed/cdnfoundry-vector:ci'
    with tempfile.TemporaryDirectory(prefix=name) as directory:
        root = Path(directory)
        root.chmod(0o755)
        document = yaml.safe_load((ROOT / 'docker/vector/vector.yaml').read_text())
        document['data_dir'] = '/tmp'
        # Keep the production decoders and transformations; retain synthetic
        # output locally to assert it without exposing a real telemetry service.
        document['sinks'] = {kind: {'type': 'file', 'inputs': ['safe_'+kind+'_events'],
                                    'path': '/result/'+kind+'.json', 'encoding': {'codec': 'json'}}
                             for kind in ('edge', 'dns')}
        (root / 'vector.yaml').write_text(yaml.safe_dump(document))
        (root / 'result').mkdir()
        try:
            run('docker', 'run', '-d', '--name', name, '--network', 'bridge', '--read-only',
                '--memory', '256m', '--tmpfs', '/tmp:rw,size=32m', '-p', '127.0.0.1::8686', '-p', '127.0.0.1::8687',
                '-v', f'{root}/vector.yaml:/etc/vector/vector.yaml:ro', '-v', f'{root}/result:/result', image)
            opener = urllib.request.build_opener(urllib.request.ProxyHandler({}))
            for kind, port, event in [('edge', '8686', {'domain_id': 42, 'hostname': 'qualification.test', 'status': 200,
                                                     'path': '/qualified?token=synthetic-secret'}),
                                      ('dns', '8687', {'domain_id': 42, 'zone': 'qualification.test', 'qname': 'www.qualification.test',
                                                     'qtype': 'A', 'rcode': 'NOERROR'})]:
                hostport = run('docker', 'port', name, port+'/tcp').stdout.strip().rsplit(':', 1)[1]
                for _ in range(40):
                    try:
                        request = urllib.request.Request('http://127.0.0.1:'+hostport, data=json.dumps(event).encode(),
                                                         headers={'Content-Type': 'application/json'})
                        with opener.open(request, timeout=2) as response:
                            assert response.status == 200
                        break
                    except OSError:
                        time.sleep(0.25)
                else:
                    raise RuntimeError('Vector HTTP source did not accept '+kind+' event')
                target = root / ('result/'+kind+'.json')
                for _ in range(40):
                    if target.exists() and target.stat().st_size:
                        break
                    time.sleep(0.25)
                rows = [json.loads(line) for line in target.read_text().splitlines()]
                assert rows[0]['domain_id'] == 42
                if kind == 'edge':
                    assert rows[0]['path'] == '/qualified'
                    assert 'synthetic-secret' not in target.read_text()
                else:
                    assert rows[0]['qname'] == 'www.qualification.test'
            print(json.dumps({'qualification': 'vector_traffic', 'http_json_sources': 'passed',
                              'production_transforms': 'passed', 'query_redaction': 'passed'}))
        finally:
            run('docker', 'rm', '-f', name, check=False)


if __name__ == '__main__':
    main()
