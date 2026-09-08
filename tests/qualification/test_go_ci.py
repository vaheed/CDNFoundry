"""Execute the actual CI shell with controlled Go-tool exit statuses."""
from __future__ import annotations

import os
from pathlib import Path
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[2]


class GoCiFailurePropagationTest(unittest.TestCase):
    def script(self) -> str:
        workflow = (ROOT / '.github/workflows/ci.yml').read_text()
        marker = '      - name: Test and build every Go module\n'
        self.assertEqual(workflow.count(marker), 1)
        body = workflow.split(marker, 1)[1].split('        run: |\n', 1)[1]
        lines = []
        for line in body.splitlines():
            if line and not line.startswith('          '):
                break
            lines.append(line[10:] if line else '')
        self.assertTrue(lines)
        return '\n'.join(lines)

    def run_job(self, module: str = '', command: str = '') -> subprocess.CompletedProcess:
        with tempfile.TemporaryDirectory(prefix='cdnf-go-ci-fixture-') as directory:
            root = Path(directory)
            commands = root / 'bin'
            commands.mkdir()
            for name in ('edge-agent', 'edge-gateway'):
                (root / name).mkdir()
                (root / name / 'go.mod').write_text('module fixture\n\ngo 1.24\n')
                (root / name / 'main.go').write_text('package main\n')
            scripts = {
                'go': '''#!/bin/sh
case "$PWD:$1" in
  *"/$FIXTURE_MODULE:$FIXTURE_COMMAND") echo "injected $FIXTURE_MODULE $FIXTURE_COMMAND failure" >&2; exit 9;;
esac
exit 0
''',
                'gofmt': '''#!/bin/sh
case "$*:$FIXTURE_COMMAND" in
  *"$FIXTURE_MODULE"*:gofmt) echo "injected $FIXTURE_MODULE gofmt failure" >&2; exit 9;;
esac
exit 0
''',
            }
            for name, source in scripts.items():
                (commands / name).write_text(source)
                (commands / name).chmod(0o755)
            # Isolate only the job's evidence paths; execute its actual control flow.
            script = self.script().replace('/tmp/cdnfoundry-go', str(root / 'ci-go'))
            return subprocess.run(['bash', '-c', script], cwd=root,
                                  env={**os.environ, 'PATH': str(commands) + ':' + os.environ['PATH'],
                                       'GITHUB_STEP_SUMMARY': str(root / 'summary.md'),
                                       'FIXTURE_MODULE': module, 'FIXTURE_COMMAND': command},
                                  capture_output=True, text=True, timeout=10)

    def test_success_requires_both_modules_to_pass(self) -> None:
        result = self.run_job()
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
        self.assertIn('Testing ./edge-agent', result.stdout)
        self.assertIn('Testing ./edge-gateway', result.stdout)

    def test_tool_failure_in_either_module_cannot_be_masked(self) -> None:
        for module in ('edge-agent', 'edge-gateway'):
            for command in ('gofmt', 'vet', 'test', 'build'):
                with self.subTest(module=module, command=command):
                    result = self.run_job(module, command)
                    self.assertNotEqual(result.returncode, 0, result.stdout + result.stderr)
                    self.assertIn(f'injected {module} {command} failure', result.stdout)
                    self.assertIn('::error title=Go test/build failed::', result.stdout)
                    if module == 'edge-agent':
                        self.assertNotIn('Testing ./edge-gateway', result.stdout)


if __name__ == '__main__':
    unittest.main()
