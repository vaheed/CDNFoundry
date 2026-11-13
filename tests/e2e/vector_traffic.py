#!/usr/bin/env python3
"""Exercise production Vector HTTP decoders and redaction with synthetic events."""
from __future__ import annotations

import argparse
import base64
import hashlib
import socket
import json
from pathlib import Path
import subprocess
import tempfile
import time
import urllib.error
import urllib.request
import uuid

import yaml

ROOT = Path(__file__).resolve().parents[2]
NODE_TOKEN = 'qualification-node-token'
AUTHORIZATION = 'Basic ' + base64.b64encode(('cdnf:' + NODE_TOKEN).encode()).decode()


def run(*args: str, check: bool = True) -> subprocess.CompletedProcess[str]:
    return subprocess.run(args, check=check, capture_output=True, text=True, timeout=60)


def main() -> None:
    name = 'cdnf-vector-traffic-' + uuid.uuid4().hex[:12]
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--image', default='timberio/vector:0.58.0-debian@sha256:1c1ea358c617ea0b23003d5af87f7a678b30f8f7096437e680380c47fc13d2d9')
    parser.add_argument('--edge-id', default='')
    args = parser.parse_args()
    image = args.image
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
                '--memory', '256m', '--tmpfs', '/tmp:rw,size=32m', '-p', '127.0.0.1::8686', '-p', '127.0.0.1::8687', '-p', '127.0.0.1::9000/udp',
                '-e', 'CDNF_TELEMETRY_EDGE_ID='+args.edge_id, '-e', 'EDGE_STATUS_TOKEN='+NODE_TOKEN,
                '-v', f'{root}/vector.yaml:/etc/vector/vector.yaml:ro', '-v', f'{root}/result:/result', image)
            opener = urllib.request.build_opener(urllib.request.ProxyHandler({}))
            for kind, port, event in [('edge', '8686', {'domain_id': 42, 'hostname': 'qualification.test', 'status': 200,
                                                     'path': '/qualified?token=synthetic-secret', 'edge_id': 'cell-01'}),
                                      ('dns', '8687', {'domain_id': 42, 'zone': 'qualification.test', 'qname': 'www.qualification.test',
                                                     'qtype': 'A', 'rcode': 'NOERROR'})]:
                hostport = run('docker', 'port', name, port+'/tcp').stdout.strip().rsplit(':', 1)[1]
                for _ in range(40):
                    try:
                        request = urllib.request.Request('http://127.0.0.1:'+hostport, data=json.dumps(event).encode(),
                                                         headers={'Content-Type': 'application/json', 'Authorization': AUTHORIZATION})
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
                    assert rows[0]['edge_id'] == (args.edge_id or 'cell-01')
                    assert rows[0]['cell'] == 'cell-01', 'the serving cell survives the collector replacing edge_id'
                    assert 'synthetic-secret' not in target.read_text()
                else:
                    assert rows[0]['qname'] == 'www.qualification.test'
            hostport = run('docker', 'port', name, '8686/tcp').stdout.strip().rsplit(':', 1)[1]
            for forged in ({}, {'Authorization': 'Basic ' + base64.b64encode(b'cdnf:wrong').decode()}):
                request = urllib.request.Request('http://127.0.0.1:'+hostport, data=json.dumps({'domain_id': 99, 'hostname': 'forged.test'}).encode(),
                                                 headers={'Content-Type': 'application/json', **forged})
                try:
                    opener.open(request, timeout=3)
                except urllib.error.HTTPError as error:
                    assert error.code == 401, error.code
                else:
                    raise AssertionError('Vector accepted an unauthenticated telemetry event')
            time.sleep(1)
            assert '"forged.test"' not in (root/'result/edge.json').read_text()
            # Access-log lines reach Vector over UDP syslog: only the cell holding the
            # node secret can prove a line is genuine, and forged lines are dropped.
            proof = hashlib.sha256(('cdnf-telemetry-v1|' + NODE_TOKEN).encode()).hexdigest()
            udp_port = int(run('docker', 'port', name, '9000/udp').stdout.strip().rsplit(':', 1)[1])
            sender = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
            for label, value in (('syslog-forged', 'forged'), ('syslog-genuine', proof)):
                line = json.dumps({'domain_id': 42, 'hostname': label + '.test', 'path': '/' + label, 'telemetry_proof': value})
                sender.sendto(('<190>cdnfoundry_edge: ' + line).encode(), ('127.0.0.1', udp_port))
            for _ in range(40):
                if 'syslog-genuine.test' in (root/'result/edge.json').read_text():
                    break
                time.sleep(0.25)
            written = (root/'result/edge.json').read_text()
            assert 'syslog-genuine.test' in written and 'telemetry_proof' not in written
            assert 'syslog-forged.test' not in written, 'Vector accepted a forged syslog event'
            # A failed handshake at the gateway never reaches a cell. The edge agent posts it as a bounded
            # array of tls events; they must arrive as tls rows named by reason. A line the runtime emitted
            # with empty numeric fields is dropped as a known shape and never becomes a row.
            events = [{'occurred_at': '2026-11-12T00:00:00Z', 'event_type': 'tls', 'tls_error': 'gateway_invalid_hello',
                       'hostname': '', 'domain_id': 0, 'status': 0, 'security_action': 'allow'} for _ in range(2)]
            request = urllib.request.Request('http://127.0.0.1:'+hostport, data=json.dumps(events).encode(),
                                             headers={'Content-Type': 'application/json', 'Authorization': AUTHORIZATION})
            with opener.open(request, timeout=3) as response:
                assert response.status == 200
            sender.sendto(b'<190>cdnfoundry_edge: {"domain_id":,"hostname":"empty-fields.test","asn":,"waf_rule_id":,"telemetry_proof":""}', ('127.0.0.1', udp_port))
            for _ in range(40):
                rows = [json.loads(line) for line in (root/'result/edge.json').read_text().splitlines()]
                if len([row for row in rows if row.get('tls_error') == 'gateway_invalid_hello']) >= 2:
                    break
                time.sleep(0.25)
            tls_rows = [row for row in rows if row.get('event_type') == 'tls' and row.get('tls_error') == 'gateway_invalid_hello']
            assert len(tls_rows) == 2 and all(row['status'] == 0 for row in tls_rows), tls_rows
            assert not [row for row in rows if row.get('hostname') == 'empty-fields.test']
            cases = [
                ('success', '200', None, '0.125', '', 125),
                ('redirect', '302', None, '0.020', '', 20),
                ('client-error', '404', None, '0.005', '', 5),
                ('server-error', '502', None, '0.032', '502', 32),
                ('retry', '502, 200', None, '0.032, 0.010', '502, 200', 10),
                ('cached', '-', None, '-', '', None),
                ('unknown', '', None, None, '', None),
                ('explicit', 'timeout', 42, None, 'timeout', 42),
                ('invalid', '', None, 'invalid', '', None),
                ('negative', '', -1, None, '', None),
                ('bounded', '', None, '999999.000', '', None),
            ]
            for key, status, latency, response_time, expected_error, expected_latency in cases:
                event = {'domain_id': 42, 'hostname': 'qualification.test', 'path': '/'+key,
                         'origin_error': status, 'origin_latency_ms': latency}
                if response_time is not None:
                    event['origin_response_time'] = response_time
                request = urllib.request.Request('http://127.0.0.1:'+hostport, data=json.dumps(event).encode(),
                                                 headers={'Content-Type': 'application/json', 'Authorization': AUTHORIZATION})
                with opener.open(request, timeout=3) as response:
                    assert response.status == 200
            for _ in range(40):
                rows = [json.loads(line) for line in (root/'result/edge.json').read_text().splitlines()]
                by_path = {row['path']: row for row in rows}
                if all('/'+case[0] in by_path for case in cases):
                    break
                time.sleep(0.25)
            for key, _, _, _, expected_error, expected_latency in cases:
                row = by_path['/'+key]
                assert row['origin_error'] == expected_error, (key, 'origin_error', row['origin_error'])
                assert row.get('origin_latency_ms') == expected_latency, (key, 'origin_latency_ms', row.get('origin_latency_ms'))
                assert 'origin_response_time' not in row
            # Attack scores run from 1 to 99; a value outside the range, or one that is not a number, becomes 0 (not scored).
            score_cases = {'score-rule': {'security_rule': 'x' * 40}, 'score-low': {'attack_score': 4, 'attack_score_sqli': '7', 'attack_score_xss': 99, 'attack_score_rce': 1},
                           'score-bad': {'attack_score': 150, 'attack_score_sqli': -3, 'attack_score_xss': 'abc', 'attack_score_rce': 100},
                           'score-none': {}}
            for key, scores in score_cases.items():
                event = {'domain_id': 42, 'hostname': 'qualification.test', 'path': '/'+key, **scores}
                request = urllib.request.Request('http://127.0.0.1:'+hostport, data=json.dumps(event).encode(),
                                                 headers={'Content-Type': 'application/json', 'Authorization': AUTHORIZATION})
                with opener.open(request, timeout=3) as response:
                    assert response.status == 200
            for _ in range(40):
                rows = [json.loads(line) for line in (root/'result/edge.json').read_text().splitlines()]
                by_path = {row['path']: row for row in rows}
                if all('/'+key in by_path for key in score_cases):
                    break
                time.sleep(0.25)
            fields = ('attack_score', 'attack_score_sqli', 'attack_score_xss', 'attack_score_rce')
            assert by_path['/score-rule']['security_rule'] == 'x' * 24, by_path['/score-rule']
            assert [by_path['/score-low'][name] for name in fields] == [4, 7, 99, 1], by_path['/score-low']
            assert [by_path['/score-bad'][name] for name in fields] == [0, 0, 0, 0], by_path['/score-bad']
            assert [by_path['/score-none'][name] for name in fields] == [0, 0, 0, 0], by_path['/score-none']
            print(json.dumps({'qualification': 'vector_traffic', 'http_json_sources': 'passed',
                              'production_transforms': 'passed', 'query_redaction': 'passed', 'origin_metric_cases': len(cases)}))
        except Exception:
            logs = run('docker', 'logs', '--tail', '40', name, check=False)
            print((logs.stdout + logs.stderr)[-6000:], flush=True)
            raise
        finally:
            run('docker', 'rm', '-f', name, check=False)


if __name__ == '__main__':
    main()
