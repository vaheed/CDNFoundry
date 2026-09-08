"""Adversarial fixtures for the policy itself, without modifying real builds."""
import importlib.util
from pathlib import Path
import unittest
import tempfile

spec = importlib.util.spec_from_file_location('supply_policy', Path(__file__).resolve().parents[2] / 'scripts/supply-chain-policy.py')
policy = importlib.util.module_from_spec(spec)
spec.loader.exec_module(policy)
PIN = 'alpine:3@sha256:' + 'a' * 64


class ImageReferenceTests(unittest.TestCase):
    def test_dependency_scan_includes_role_overrides_and_deduplicates(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            overrides = root / 'deploy/production'
            overrides.mkdir(parents=True)
            (root / 'compose.prod.yml').write_text('services:\n  core:\n    image: "${CDNF_CORE_IMAGE:?required}"\n  database:\n    image: '+PIN+'\n')
            second = 'vendor/service@sha256:'+'b'*64
            (overrides / 'logs.yml').write_text('services:\n  duplicate:\n    image: '+PIN+'\n  collector:\n    image: '+second+'\n')
            self.assertEqual(sorted([PIN, second]), policy.production_dependency_images(root))
            (overrides / 'logs.yml').write_text('services:\n  collector:\n    image: vendor/service:latest\n')
            with self.assertRaises(ValueError):
                policy.production_dependency_images(root)

    def test_compose_images_require_a_digest_or_the_checked_runtime_variable(self):
        for reference in ('alpine:latest', 'postgres:18', '${ARBITRARY_IMAGE}', '${CDNF_CORE_IMAGE:-mutable}'):
            with self.subTest(reference=reference), self.assertRaises(ValueError):
                policy.validate_compose_images({'services': {'probe': {'image': reference}}})
        for reference in (PIN, '${CDNF_CORE_IMAGE:?required}'):
            policy.validate_compose_images({'services': {'probe': {'image': reference}}})

    def test_git_checkout_requires_a_full_commit(self):
        clone = 'RUN git clone https://example.test/source.git /tmp/source && git -C /tmp/source checkout "${COMMIT}"'
        for value in ('main', 'v1.0', 'abcdef', '${UNKNOWN}', ''):
            with self.subTest(value=value), self.assertRaises(ValueError):
                policy.validate_git_dependencies('ARG COMMIT='+value+'\n'+clone)
        policy.validate_git_dependencies('ARG COMMIT='+'a'*40+'\n'+clone)
        policy.validate_git_dependencies(clone.replace('"${COMMIT}"', 'a'*40))
        with self.assertRaises(ValueError):
            policy.validate_git_dependencies('RUN git clone https://example.test/source.git /tmp/source')

    def test_rejects_mutable_and_unresolved_inputs(self):
        for source in (
            'FROM alpine:latest', 'FROM\talpine:latest', 'from alpine:3', '  FROM alpine:3',
            'FROM --platform=$BUILDPLATFORM alpine:3 AS base',
            'FROM --platform linux/amd64 alpine:3',
            'FROM \\\n alpine:3', 'ARG BASE=alpine:3\nFROM ${BASE}',
            'ARG BASE\nFROM $BASE', 'ARG BASE=alpine:3\nFROM $BASE',
            'FROM later\nFROM '+PIN+' AS later',
            'FROM scratch\nCOPY --from=alpine:3 /x /x',
            'FROM scratch\nCOPY --from alpine:3 /x /x',
            'FROM scratch\nRUN --mount=type=bind,from=alpine:3 echo hi',
            'ARG UNTRUSTED=alpine:3\nFROM ${UNTRUSTED}',
            'FROM scratch\nCOPY --from=3 /x /x',
        ):
            with self.subTest(source=source), self.assertRaises(ValueError):
                policy.validate_image_references(source)

    def test_accepts_pinned_inputs_and_previous_stages(self):
        for source in (
            'FROM '+PIN, 'FROM --platform=$BUILDPLATFORM '+PIN+' AS builder',
            'ARG BASE='+PIN+'\nFROM ${BASE}',
            'FROM '+PIN+' AS builder\nFROM builder AS final\nCOPY --from=0 /x /x',
            'FROM scratch\nCOPY --from='+PIN+' /x /x',
            '# FROM mutable:comment\nFROM scratch',
        ):
            with self.subTest(source=source):
                policy.validate_image_references(source)

    def test_only_explicit_ingress_core_boundary_allows_local_default(self):
        source = 'ARG CORE_IMAGE=cdnfoundry/core:ci\nFROM ${CORE_IMAGE} AS core'
        with self.assertRaises(ValueError):
            policy.validate_image_references(source)
        policy.validate_image_references(source, internal_core=True)
        with self.assertRaises(ValueError):
            policy.validate_image_references(source.replace('cdnfoundry/core:ci', 'attacker/core:ci'), internal_core=True)


if __name__ == '__main__':
    unittest.main()
