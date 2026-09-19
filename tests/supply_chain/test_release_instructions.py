"""Execute the actual documented Fleet projection; never contact a registry."""
import copy
import json
import os
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[2]


class ReleaseInstructionTests(unittest.TestCase):
    def test_verified_manifest_projection_and_rejection_boundaries(self):
        text = (ROOT / 'docs/operations/software-supply-chain.md').read_text()
        script = text.split("python3 - <<'PYTHON'\n", 1)[1].split('\nPYTHON', 1)[0]
        commit = subprocess.check_output(['git', 'rev-parse', 'HEAD'], cwd=ROOT, text=True).strip()
        git_dir = subprocess.check_output(['git', 'rev-parse', '--absolute-git-dir'], cwd=ROOT, text=True).strip()
        components = ('core', 'web', 'edge-control', 'edge-runtime', 'edge-agent',
                      'edge-gateway', 'mmdb-updater', 'grafana', 'loki', 'postgres', 'caddy')
        manifest = {'source_commit': commit, 'images': [
            {'component': c, 'image': f'ghcr.io/vaheed/cdnfoundry-{c}@sha256:'+'a'*64}
            for c in components]}
        fleet = json.loads((ROOT / 'deploy/production/examples/starter-fleet.json').read_text())
        cases = []
        bad = copy.deepcopy(manifest)
        bad['source_commit'] = 'b'*40
        cases.append(('wrong source', bad, fleet))
        bad = copy.deepcopy(manifest)
        bad['images'][-1] = bad['images'][0]
        cases.append(('duplicate component', bad, fleet))
        bad = copy.deepcopy(manifest)
        bad['images'][0]['image'] = 'untrusted/core@sha256:'+'a'*64
        cases.append(('wrong publisher', bad, fleet))
        bad = copy.deepcopy(fleet)
        bad['nodes'][0]['extra_env'] = {'CDNF_CORE_IMAGE': 'conflicting:tag'}
        cases.append(('conflicting image', manifest, bad))
        cases.append(('valid', manifest, fleet))
        for name, selected, topology in cases:
            with self.subTest(name=name), tempfile.TemporaryDirectory() as directory:
                root = Path(directory)
                (root / 'release-manifest.json').write_text(json.dumps(selected))
                original = json.dumps(topology)
                (root / 'fleet.json').write_text(original)
                result = subprocess.run([sys.executable, '-c', script], cwd=root,
                                        env={**os.environ, 'GIT_DIR': git_dir, 'PYTHONOPTIMIZE': '1'},
                                        capture_output=True, text=True)
                self.assertEqual(original, (root / 'fleet.json').read_text())
                output = root / 'fleet.verified.json'
                if name != 'valid':
                    self.assertNotEqual(0, result.returncode)
                    self.assertFalse(output.exists())
                    continue
                self.assertEqual(0, result.returncode, result.stderr)
                self.assertEqual(0o600, output.stat().st_mode & 0o777)
                projected = json.loads(output.read_text())
                self.assertEqual(commit, projected['global']['release'])
                self.assertTrue(all(len(node['extra_env']) == len(components) for node in projected['nodes']))
                dry_run = subprocess.run([str(ROOT / 'scripts/cdnfoundry-fleet'), '--config', str(output),
                                          '--state-dir', str(root / 'state'), '--output-dir', str(root / 'bundles'),
                                          '--non-interactive', '--dry-run', 'setup'],
                                         cwd=ROOT, capture_output=True, text=True)
                self.assertEqual(0, dry_run.returncode, dry_run.stderr)
                self.assertFalse((root / 'state').exists())
                self.assertFalse((root / 'bundles').exists())


if __name__ == '__main__':
    unittest.main()
