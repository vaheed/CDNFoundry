import copy
import importlib.util
import json
import os
from pathlib import Path
import sys
import tempfile
import unittest
from unittest.mock import patch

SPEC = importlib.util.spec_from_file_location('qualification_runner', Path(__file__).resolve().parents[2] / 'tests/e2e/production_qualification.py')
runner = importlib.util.module_from_spec(SPEC)
sys.modules[SPEC.name] = runner
SPEC.loader.exec_module(runner)


class EvidenceTests(unittest.TestCase):
    def setUp(self):
        self.check = next(c for c in runner.CHECKS if c.identifier == 'browser')
        self.context = {'commit': 'a' * 40, 'source_sha256': 'b' * 64, 'environment_id': 'isolated-starter-01'}
        self.document = {
            'schema': 1, 'check_id': 'browser', **self.context,
            'kind': 'operator_attestation', 'operator': 'qualification-owner',
            'topology': 'control plus two DNS/edge hosts',
            'recorded_at': '2026-01-01T00:00:00Z', 'outcome': 'passed',
            'steps': [{'action': 'manual checklist step 1', 'expected': 'access denied', 'actual': 'access denied', 'outcome': 'passed'}],
            'images': ['example.invalid/core@sha256:' + 'c' * 64], 'measurements': {},
        }

    def result(self, contents):
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / 'evidence.json'
            path.write_text(contents)
            with patch.dict(os.environ, {'CDNF_QUALIFY_BROWSER_EVIDENCE': str(path)}):
                return runner.owner_result(self.check, self.context)

    def test_arbitrary_nonempty_file_never_passes(self):
        for text in ('passed', '{}', '[]', 'null', '{bad json', 'x' * (1024 * 1024 + 1)):
            with self.subTest(text=text[:30]):
                self.assertEqual(self.result(text).status, 'not_run')

    def test_attestation_is_explicitly_attributed(self):
        result = self.result(json.dumps(self.document))
        self.assertEqual(result.status, 'passed')
        self.assertEqual(result.evidence_kind, 'operator_attestation')
        self.assertIn('not executed by this runner', result.reason)

    def test_wrong_source_environment_or_outcome_cannot_pass(self):
        mutations = {'schema': 2, 'check_id': 'external-ip', 'commit': 'd' * 40,
                     'source_sha256': 'e' * 64, 'environment_id': 'other',
                     'kind': 'file', 'operator': '', 'topology': '',
                     'recorded_at': '2999-01-01T00:00:00Z', 'steps': [],
                     'images': ['core:latest'], 'measurements': None, 'outcome': 'success'}
        for key, value in mutations.items():
            document = {**self.document, key: value}
            with self.subTest(key=key):
                self.assertEqual(self.result(json.dumps(document)).status, 'not_run')

    def test_failed_or_blocked_evidence_remains_nonpassing(self):
        for status in ('failed', 'blocked', 'not_run'):
            document = {**self.document, 'outcome': status}
            self.assertEqual(self.result(json.dumps(document)).status, status)

    def test_false_execution_and_failed_step_are_rejected(self):
        document = copy.deepcopy(self.document)
        document['kind'] = 'executed_check'
        self.assertEqual(self.result(json.dumps(document)).status, 'not_run')
        document['steps'][0]['exit_code'] = 1
        self.assertEqual(self.result(json.dumps(document)).status, 'not_run')
        document['steps'][0]['exit_code'] = 0
        self.assertEqual(self.result(json.dumps(document)).status, 'passed')
        document['steps'][0]['outcome'] = 'failed'
        self.assertEqual(self.result(json.dumps(document)).status, 'not_run')

    def test_early_failure_records_all_remaining_checks(self):
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / 'report.json'
            first = runner.CHECKS[0]
            failure = runner.Result(first.identifier, first.description, first.owner, 'failed', None, None, 'test', None, 'fixture failure')
            with patch.object(sys, 'argv', ['qualification', '--output', str(path)]), patch.object(runner, 'source_identity', return_value={k: self.context[k] for k in ('commit', 'source_sha256')}), patch.object(runner, 'run_check', return_value=failure):
                self.assertEqual(runner.main(), 1)
            report = json.loads(path.read_text())
            self.assertEqual(len(report['results']), len(runner.CHECKS))
            self.assertEqual(report['release_decision'], 'failed')
            self.assertTrue(all(r['status'] == 'not_run' for r in report['results'][1:]))


if __name__ == '__main__':
    unittest.main()
