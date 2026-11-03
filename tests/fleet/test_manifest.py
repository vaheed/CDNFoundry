from __future__ import annotations

import copy
import sys
from pathlib import Path

import pytest
from cryptography.hazmat.primitives.asymmetric.ed25519 import Ed25519PrivateKey

sys.path.insert(0, str(Path(__file__).resolve().parents[2] / "scripts"))

from cdnfoundry_fleet import manifest as m  # noqa: E402
from cdnfoundry_fleet.common import ValidationError  # noqa: E402

D1 = "sha256:" + "1" * 64
D2 = "sha256:" + "2" * 64
C = "a" * 64


def release(version: str = "2.0.1", **over):
    base = {
        "release": version, "profile": "full", "ci_passed": True, "irreversible_migration": False,
        "images": {"openresty": D1, "pdns": D1}, "configs": {"nginx.conf": C}, "env_images": {},
        "roles": {"edge": {"images": ["openresty"], "configs": ["nginx.conf"]}, "dns": {"images": ["pdns"], "configs": []}},
    }
    base.update(over)
    return base


def test_valid_signature_is_accepted() -> None:
    key = Ed25519PrivateKey.generate()
    doc = m.sign(release(), key)
    assert m.verify(doc, key.public_key())["release"] == "2.0.1"


def test_unsigned_tampered_or_foreign_manifests_are_refused() -> None:
    key = Ed25519PrivateKey.generate()
    doc = m.sign(release(), key)
    with pytest.raises(m.ManifestError, match="not signed"):
        m.verify({"manifest": release()}, key.public_key())
    tampered = copy.deepcopy(doc)
    tampered["manifest"]["images"]["pdns"] = D2
    with pytest.raises(m.ManifestError, match="does not verify"):
        m.verify(tampered, key.public_key())
    with pytest.raises(m.ManifestError, match="does not verify"):
        m.verify(doc, Ed25519PrivateKey.generate().public_key())


def test_a_release_that_failed_ci_is_refused_even_when_signed() -> None:
    key = Ed25519PrivateKey.generate()
    with pytest.raises(m.ManifestError, match="CI"):
        m.verify(m.sign(release(ci_passed=False), key), key.public_key())


def test_images_must_be_pinned_by_digest() -> None:
    with pytest.raises(m.ManifestError, match="digest"):
        m.sign(release(images={"openresty": "openresty:latest"}), Ed25519PrivateKey.generate())


def test_pulled_digest_must_match_the_manifest() -> None:
    m.verify_pulled(release(), {"openresty": D1})
    with pytest.raises(m.ManifestError, match="pins"):
        m.verify_pulled(release(), {"openresty": D2})


def test_diff_names_changed_images_configs_and_affected_roles() -> None:
    old = release("2.0.0")
    new = release("2.0.1", images={"openresty": D2, "pdns": D1})
    d = m.diff(old, new)
    assert d.images == {"openresty": (D1, D2)}
    assert d.roles == ["edge"]
    assert not d.empty
    assert m.diff(old, copy.deepcopy(old)).empty


def test_migration_flag_shows_in_the_diff() -> None:
    assert m.diff(release("2.0.0"), release("2.0.1", irreversible_migration=True)).migrations


def test_rollback_refuses_to_cross_an_irreversible_migration() -> None:
    with pytest.raises(ValidationError, match="irreversible"):
        m.rollback_allowed(release("2.0.1", irreversible_migration=True), release("2.0.0"))
    m.rollback_allowed(release("2.0.1"), release("2.0.0"))


def test_checkout_must_match_the_release(tmp_path: Path) -> None:
    import hashlib

    (tmp_path / "compose.prod.yml").write_text(f"services:\n  db:\n    image: postgres:18@{D1}\n")
    (tmp_path / "nginx.conf").write_text("x")
    good = release(images={"postgres:18": D1}, configs={"nginx.conf": hashlib.sha256(b"x").hexdigest()})
    m.verify_checkout(good, tmp_path)
    with pytest.raises(m.ManifestError, match="differs from the release"):
        m.verify_checkout({**good, "configs": {"nginx.conf": C}}, tmp_path)
    with pytest.raises(m.ManifestError, match="pins postgres:18"):
        m.verify_checkout({**good, "images": {"postgres:18": D2}}, tmp_path)
    with pytest.raises(m.ManifestError, match="does not list"):
        m.verify_checkout({**good, "images": {}}, tmp_path)
