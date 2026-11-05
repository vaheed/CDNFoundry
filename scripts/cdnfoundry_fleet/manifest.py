"""Signed release manifests.

CI publishes one manifest per release: every image digest, every rendered config
checksum, the qualification profile that passed, and whether the release holds an
irreversible migration. Fleet installs only a manifest whose Ed25519 signature
verifies against the trusted release key and whose CI gate passed.
"""
from __future__ import annotations

import base64
import json
import re
from dataclasses import dataclass, field
from typing import Any

from cryptography.exceptions import InvalidSignature
from cryptography.hazmat.primitives.asymmetric.ed25519 import Ed25519PrivateKey, Ed25519PublicKey
from cryptography.hazmat.primitives import serialization

from .common import FleetError, ValidationError

DIGEST = re.compile(r"^sha256:[0-9a-f]{64}$")
PROFILES = {"full", "security-fast-lane"}


class ManifestError(FleetError):
    pass


def canonical(manifest: dict[str, Any]) -> bytes:
    return json.dumps(manifest, sort_keys=True, separators=(",", ":")).encode()


def sign(manifest: dict[str, Any], private_key: Ed25519PrivateKey) -> dict[str, Any]:
    check_shape(manifest)
    return {"manifest": manifest, "signature": base64.b64encode(private_key.sign(canonical(manifest))).decode()}


def public_key_from_pem(pem: str) -> Ed25519PublicKey:
    key = serialization.load_pem_public_key(pem.encode())
    if not isinstance(key, Ed25519PublicKey):
        raise ManifestError("The release key must be an Ed25519 public key")
    return key


def check_shape(manifest: dict[str, Any]) -> None:
    for key in ("release", "images", "configs", "profile", "ci_passed", "irreversible_migration", "roles", "env_images"):
        if key not in manifest:
            raise ManifestError(f"Manifest field missing: {key}")
    if manifest["profile"] not in PROFILES:
        raise ManifestError(f"Unknown qualification profile: {manifest['profile']}")
    for name, digest in manifest["images"].items():
        if not DIGEST.match(str(digest)):
            raise ManifestError(f"Image {name} is not pinned by sha256 digest")
    for path, checksum in manifest["configs"].items():
        if not re.fullmatch(r"[0-9a-f]{64}", str(checksum)):
            raise ManifestError(f"Config {path} has no sha256 checksum")


def verify(document: dict[str, Any], public_key: Ed25519PublicKey) -> dict[str, Any]:
    """Return the manifest, or raise. An unsigned or failed-CI manifest is never accepted."""
    manifest = document.get("manifest")
    signature = document.get("signature")
    if not isinstance(manifest, dict) or not signature:
        raise ManifestError("The release manifest is not signed")
    try:
        public_key.verify(base64.b64decode(signature, validate=True), canonical(manifest))
    except (InvalidSignature, ValueError) as exc:
        raise ManifestError("The release manifest signature does not verify") from exc
    check_shape(manifest)
    if manifest["ci_passed"] is not True:
        raise ManifestError("The release did not pass the CI qualification gate")
    return manifest


def verify_pulled(manifest: dict[str, Any], pulled: dict[str, str]) -> None:
    """`pulled` maps image name to the digest the node actually holds."""
    for name, wanted in manifest["images"].items():
        if name in pulled and pulled[name] != wanted:
            raise ManifestError(f"Image {name} pulled as {pulled[name]} but the manifest pins {wanted}")


@dataclass
class Diff:
    images: dict[str, tuple[str | None, str | None]] = field(default_factory=dict)
    configs: dict[str, tuple[str | None, str | None]] = field(default_factory=dict)
    migrations: bool = False
    roles: list[str] = field(default_factory=list)

    @property
    def empty(self) -> bool:
        return not (self.images or self.configs or self.migrations)


def diff(current: dict[str, Any] | None, target: dict[str, Any]) -> Diff:
    old_images = (current or {}).get("images", {})
    old_configs = (current or {}).get("configs", {})
    result = Diff(migrations=bool(target["irreversible_migration"]) and (current or {}).get("release") != target["release"])
    for name in sorted(set(old_images) | set(target["images"])):
        if old_images.get(name) != target["images"].get(name):
            result.images[name] = (old_images.get(name), target["images"].get(name))
    for path in sorted(set(old_configs) | set(target["configs"])):
        if old_configs.get(path) != target["configs"].get(path):
            result.configs[path] = (old_configs.get(path), target["configs"].get(path))
    roles = target["roles"]  # role -> {"images": [...], "configs": [...]}
    for role, parts in sorted(roles.items()):
        if any(i in result.images for i in parts.get("images", [])) or any(c in result.configs for c in parts.get("configs", [])):
            result.roles.append(role)
    return result


def node_needs_restart(role: str, d: Diff, manifest: dict[str, Any]) -> bool:
    return role in d.roles


def rollback_allowed(current: dict[str, Any], previous: dict[str, Any]) -> None:
    if current.get("irreversible_migration"):
        raise ValidationError(
            f"Release {current['release']} holds an irreversible migration; restore from backup instead of rolling back to {previous['release']}"
        )


def pull_ref(name: str, digest: str) -> str:
    """`repo:tag` and `repo` both pull as `repo@digest`; the digest alone selects the content."""
    repo = name.rsplit(":", 1)[0] if ":" in name.rsplit("/", 1)[-1] else name
    return f"{repo}@{digest}"


def verify_checkout(manifest: dict[str, Any], repo_root: Any) -> None:
    """The checkout Fleet renders from must be the release the manifest describes."""
    import hashlib
    from pathlib import Path

    import yaml

    root = Path(repo_root)
    for path, wanted in manifest["configs"].items():
        file = root / path
        if not file.is_file():
            raise ManifestError(f"The checkout lacks {path}, which the manifest lists")
        if hashlib.sha256(file.read_bytes()).hexdigest() != wanted:
            raise ManifestError(f"{path} in the checkout differs from the release; check out the release tag before upgrading")
    compose = yaml.safe_load((root / "compose.prod.yml").read_text(encoding="utf-8"))
    pinned: dict[str, str] = {}
    for service in compose.get("services", {}).values():
        image = str(service.get("image", ""))
        if "@sha256:" in image:
            repo, digest = image.split("@", 1)
            pinned[repo] = digest
    for repo, digest in pinned.items():
        if repo not in manifest["images"]:
            raise ManifestError(f"The manifest does not list the image {repo}")
        if manifest["images"][repo] != digest:
            raise ManifestError(f"The checkout pins {repo} to {digest}, the manifest to {manifest['images'][repo]}")
