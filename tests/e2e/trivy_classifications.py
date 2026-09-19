#!/usr/bin/env python3
"""Exercise the pinned scanner's classification scope, expiry and release gate."""
import copy
import json
from pathlib import Path
import subprocess
import tempfile

ROOT = Path(__file__).resolve().parents[2]
SCANNER = 'aquasec/trivy:0.66.0@sha256:086971aaf400beebd94e8300fd8ea623774419597169156cec56eec5b00dfb1e'
VERSION = 'v1.5.1-0.20260427112133-525d1bab07e0'
PACKAGE = 'github.com/grafana/tempo'
TARGET = 'usr/share/grafana/bin/grafana'


def finding(identifier: str, *, version: str = VERSION, package: str = PACKAGE) -> dict:
    return {
        'VulnerabilityID': identifier, 'PkgName': package, 'InstalledVersion': version,
        'PkgIdentifier': {'PURL': f'pkg:golang/{package}@{version}'},
        'Severity': 'HIGH', 'Status': 'affected', 'Title': 'Classification boundary fixture',
    }


def main() -> None:
    approved = [finding('CVE-2026-21728'), finding('CVE-2026-28377')]
    report = {
        'SchemaVersion': 2, 'ArtifactName': 'classification-fixture', 'ArtifactType': 'container_image',
        'Metadata': {'OS': {'Family': 'alpine', 'Name': '3.24.1', 'EOSL': False}},
        'Results': [{'Target': TARGET, 'Class': 'lang-pkgs', 'Type': 'gobinary', 'Vulnerabilities': approved}],
    }
    cases = [
        ('approved', approved, TARGET, False, False, 0, 2),
        ('different-version', [finding('CVE-2026-21728', version='v1.5.0')], TARGET, False, False, 1, 0),
        ('different-binary', approved, 'usr/bin/tempo', False, False, 2, 0),
        ('different-package', [finding('CVE-2026-21728', package='example.test/other')], TARGET, False, False, 1, 0),
        ('unapproved-finding', [finding('CVE-2026-43871', package='github.com/apache/thrift', version='v0.23.0')], TARGET, False, False, 1, 0),
        ('expired', approved, TARGET, True, False, 2, 0),
        ('end-of-life', [], TARGET, False, True, 0, 0),
    ]
    policy = (ROOT / 'supply-chain/trivy-classifications.yaml').read_text()
    with tempfile.TemporaryDirectory(prefix='cdnf-trivy-classifications-') as directory:
        work = Path(directory)
        (work / 'approved.yaml').write_text(policy)
        (work / 'expired.yaml').write_text(policy.replace('2026-10-19', '2000-01-01'))
        for name, vulnerabilities, target, expired, eol, remaining, suppressed in cases:
            fixture = copy.deepcopy(report)
            fixture['Results'][0].update(Target=target, Vulnerabilities=vulnerabilities)
            fixture['Metadata']['OS']['EOSL'] = eol
            source = json.dumps(fixture).encode()
            (work / 'input.json').write_bytes(source)
            output = work / 'result.json'
            output.unlink(missing_ok=True)
            command = [
                'docker', 'run', '--rm', '--network', 'none', '--memory', '256m',
                '-v', f'{work}:/evidence', SCANNER, 'convert',
                '--ignorefile', f'/evidence/{"expired" if expired else "approved"}.yaml',
                '--show-suppressed', '--exit-code', '1', '--exit-on-eol', '1',
                '--severity', 'HIGH,CRITICAL', '--format', 'json',
                '--output', '/evidence/result.json', '/evidence/input.json',
            ]
            result = subprocess.run(command, capture_output=True, text=True, timeout=180)
            expected = int(bool(remaining or eol))
            if result.returncode != expected or not output.exists():
                raise AssertionError(f'{name}: expected exit {expected}, got {result.returncode}: {result.stderr}')
            converted = json.loads(output.read_text())
            rows = converted.get('Results', [])
            assert sum(len(row.get('Vulnerabilities', [])) for row in rows) == remaining, name
            assert sum(len(row.get('ExperimentalModifiedFindings', [])) for row in rows) == suppressed, name
            assert (work / 'input.json').read_bytes() == source, 'Conversion changed original evidence'
            print(f'trivy_classification={name} passed', flush=True)


if __name__ == '__main__':
    main()
