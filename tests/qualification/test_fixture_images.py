"""Registry outages must be bounded without retrying runtime assertions."""
import importlib.util
from pathlib import Path
import subprocess
from types import SimpleNamespace
import unittest
from unittest.mock import patch

spec = importlib.util.spec_from_file_location('fixture_images', Path(__file__).resolve().parents[1] / 'e2e/docker_images.py')
images = importlib.util.module_from_spec(spec)
spec.loader.exec_module(images)
PIN = 'python:3.13-alpine@sha256:' + 'a' * 64
OK = SimpleNamespace(returncode=0, stderr='')
FAIL = SimpleNamespace(returncode=1, stderr='connection reset by peer')


class FixtureImageTests(unittest.TestCase):
    def test_cached_image_never_calls_registry(self):
        with patch.object(images.subprocess, 'run', return_value=OK) as run:
            images.ensure_image(PIN)
            self.assertEqual(run.call_count, 1)
            self.assertEqual(run.call_args.args[0], ['docker', 'image', 'inspect', PIN])

    def test_transient_failure_and_timeout_are_retried(self):
        for failure in (FAIL, subprocess.TimeoutExpired('docker', 120)):
            with self.subTest(failure=failure), \
                    patch.object(images.subprocess, 'run', side_effect=[FAIL, failure, OK]) as run, \
                    patch.object(images.time, 'sleep') as sleep:
                images.ensure_image(PIN)
                self.assertEqual(run.call_count, 3)
                self.assertEqual(run.call_args.args[0], ['docker', 'pull', PIN])
                self.assertEqual(run.call_args.kwargs['timeout'], 120)
                sleep.assert_called_once_with(2)

    def test_outage_fails_after_four_attempts(self):
        with patch.object(images.subprocess, 'run', return_value=FAIL) as run, \
                patch.object(images.time, 'sleep') as sleep:
            with self.assertRaisesRegex(RuntimeError, 'four attempts'):
                images.ensure_image(PIN)
            self.assertEqual(run.call_count, 5)
            self.assertEqual([call.args[0] for call in sleep.call_args_list], [2, 4, 8])

    def test_mutable_images_are_rejected_before_docker(self):
        with patch.object(images.subprocess, 'run') as run:
            with self.assertRaises(ValueError):
                images.ensure_image('python:latest')
            run.assert_not_called()


if __name__ == '__main__':
    unittest.main()
