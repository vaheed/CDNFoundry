"""Fail closed before publishing missing, stale or altered build artifacts."""
import importlib.util
import json
from pathlib import Path
import tempfile
import unittest
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[2]
spec = importlib.util.spec_from_file_location('ci_images', ROOT / 'scripts/ci/images.py')
images = importlib.util.module_from_spec(spec)
spec.loader.exec_module(images)
IMAGE = 'ghcr.io/vaheed/cdnfoundry-core:ci'


class ImageTransferTests(unittest.TestCase):
    def test_bake_inventory_matches_production_and_matrix(self):
        import yaml
        policy_spec = importlib.util.spec_from_file_location('supply_policy_ci', ROOT / 'scripts/supply-chain-policy.py')
        policy = importlib.util.module_from_spec(policy_spec)
        policy_spec.loader.exec_module(policy)
        expected = set(policy.production_release_images(ROOT, 'ci'))
        selected = {spec['tags'][0] for spec in images.targets().values()}
        self.assertEqual(expected, selected)
        workflow = yaml.safe_load((ROOT / '.github/workflows/ci.yml').read_text())
        groups = workflow['jobs']['images']['strategy']['matrix']['group']
        inventory = []
        for group in groups:
            inventory.extend(spec['tags'][0] for spec in images.targets(group).values())
        self.assertEqual(len(inventory), len(set(inventory)))
        self.assertEqual(expected, set(inventory))
        self.assertEqual(workflow['jobs']['compose']['needs'], 'images')
        self.assertIn('compose', workflow['jobs']['publish-images']['needs'])
        self.assertNotIn('docker build ', json.dumps(workflow['jobs']['publish-images']))

    def fixture(self, directory):
        archive = Path(directory) / 'application.tar.gz'
        archive.write_bytes(b'fixture archive')
        manifest = {'archive': archive.name, 'sha256': images.checksum(archive),
                    'revision': 'expected', 'images': {IMAGE: 'sha256:expected'}}
        path = Path(directory) / 'application.manifest.json'
        path.write_text(json.dumps(manifest))
        return path, manifest

    def test_invalid_artifacts_fail_before_docker_load(self):
        for case in ('missing', 'wrong revision', 'checksum', 'unexpected image', 'duplicate', 'path traversal'):
            with self.subTest(case=case), tempfile.TemporaryDirectory() as directory:
                path, manifest = self.fixture(directory)
                if case == 'missing':
                    manifest['images'] = {}
                elif case == 'wrong revision':
                    manifest['revision'] = 'other-commit'
                elif case == 'checksum':
                    manifest['sha256'] = 'corrupted'
                elif case == 'unexpected image':
                    manifest['images'] = {'untrusted/image:ci': 'sha256:expected'}
                elif case == 'path traversal':
                    manifest['archive'] = '../application.tar.gz'
                else:
                    (Path(directory) / 'duplicate.manifest.json').write_text(json.dumps(manifest))
                path.write_text(json.dumps(manifest))
                with patch.object(images, 'OUTPUT', Path(directory)), \
                        patch.object(images, 'targets', return_value={'core': {'tags': [IMAGE]}}), \
                        patch.object(images.subprocess, 'run') as docker:
                    with self.assertRaises(ValueError):
                        images.load('expected')
                    docker.assert_not_called()

    def test_changed_image_identity_is_rejected_and_archive_retained(self):
        with tempfile.TemporaryDirectory() as directory:
            self.fixture(directory)
            with patch.object(images, 'OUTPUT', Path(directory)), \
                    patch.object(images, 'targets', return_value={'core': {'tags': [IMAGE]}}), \
                    patch.object(images.subprocess, 'run'), \
                    patch.object(images, 'identity', return_value='sha256:changed'):
                with self.assertRaisesRegex(ValueError, 'identity changed'):
                    images.load('expected')
                self.assertTrue((Path(directory) / 'application.tar.gz').exists())

    def test_image_from_another_commit_is_rejected(self):
        with patch.object(images, 'run', return_value=json.dumps([{
                'Config': {'Labels': {'org.opencontainers.image.revision': 'other'}},
                'Id': 'sha256:expected'}])):
            with self.assertRaisesRegex(ValueError, 'revision'):
                images.identity(IMAGE, 'expected')


if __name__ == '__main__':
    unittest.main()
