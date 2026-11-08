"""Build and sign a release manifest from a repository checkout (CI side).

Images come from `compose.prod.yml` (pinned inline by digest) plus the `CDNF_*_IMAGE` references CI built. Configs are the
static files the production services mount, with their sha256. Roles map to Compose profiles, so Fleet restarts only the
roles whose images or configs changed.
"""
from __future__ import annotations

import hashlib
import json
from pathlib import Path
from typing import Any

import yaml
from cryptography.hazmat.primitives import serialization
from cryptography.hazmat.primitives.asymmetric.ed25519 import Ed25519PrivateKey

from . import manifest as mf
from .common import ValidationError

ROLE_PROFILES = {
    "control": {"control", "telemetry"},
    "dns": {"dns"},
    "edge": {"edge"},
    "dns-edge": {"dns", "edge"},
    "monitoring": {"telemetry"},
}


def _services(root: Path) -> dict[str, Any]:
    return yaml.safe_load((root / "compose.prod.yml").read_text(encoding="utf-8"))["services"]


def _repo(ref: str) -> tuple[str, str]:
    repo, digest = ref.split("@", 1)
    if not mf.DIGEST.match(digest):
        raise ValidationError(f"{ref} is not pinned by sha256 digest")
    return repo, digest


def _mounted_files(service: dict[str, Any], root: Path) -> set[str]:
    out: set[str] = set()
    for volume in service.get("volumes", []):
        source = str(volume.get("source", "")) if isinstance(volume, dict) else str(volume).split(":", 1)[0]
        if source.startswith("./") and (root / source).is_file():
            out.add(source[2:])
    return out


def build(root: Path, *, release: str, env_images: dict[str, str], profile: str = "full", ci_passed: bool = True,
          irreversible_migration: bool = False, vulnerabilities: list[dict[str, Any]] | None = None, mixed_versions_ok: bool = True) -> dict[str, Any]:
    services = _services(root)
    images: dict[str, str] = {}
    by_service_image: dict[str, str] = {}
    for name, svc in services.items():
        image = str(svc.get("image", ""))
        if "@sha256:" in image:
            repo, digest = _repo(image)
            images[repo] = digest
            by_service_image[name] = repo
        elif "${CDNF_" in image:
            var = image.split("${", 1)[1].split(":", 1)[0].rstrip("}")
            if var not in env_images:
                raise ValidationError(f"Give an image for {var}")
            repo, digest = _repo(env_images[var])
            images[repo] = digest
            by_service_image[name] = repo
    configs: dict[str, str] = {"compose.prod.yml": hashlib.sha256((root / "compose.prod.yml").read_bytes()).hexdigest()}
    roles: dict[str, Any] = {}
    for role, profiles in ROLE_PROFILES.items():
        role_images: set[str] = set()
        role_configs: set[str] = {"compose.prod.yml"}
        for name, svc in services.items():
            if not (set(svc.get("profiles", [])) & profiles):
                continue
            if name in by_service_image:
                role_images.add(by_service_image[name])
            for f in _mounted_files(svc, root):
                role_configs.add(f)
                configs[f] = hashlib.sha256((root / f).read_bytes()).hexdigest()
        roles[role] = {"images": sorted(role_images), "configs": sorted(role_configs)}
    manifest = {
        "release": release, "profile": profile, "ci_passed": bool(ci_passed), "irreversible_migration": irreversible_migration,
        "mixed_versions_ok": mixed_versions_ok, "images": dict(sorted(images.items())), "configs": dict(sorted(configs.items())),
        "env_images": dict(sorted(env_images.items())), "roles": roles, "vulnerabilities": vulnerabilities or [],
    }
    mf.check_shape(manifest)
    return manifest


def from_trivy(report: dict[str, Any]) -> list[dict[str, Any]]:
    out = []
    for result in report.get("Results", []):
        for v in result.get("Vulnerabilities") or []:
            out.append({"id": v["VulnerabilityID"], "image": report.get("ArtifactName", result.get("Target", "")).split("@")[0],
                        "severity": v.get("Severity", "UNKNOWN"), "fixed_version": v.get("FixedVersion") or None})
    return out


IMAGE_NAMES = {
    "CDNF_CORE_IMAGE": "core", "CDNF_WEB_IMAGE": "web", "CDNF_EDGE_CONTROL_IMAGE": "edge-control", "CDNF_EDGE_RUNTIME_IMAGE": "edge-runtime",
    "CDNF_EDGE_AGENT_IMAGE": "edge-agent", "CDNF_EDGE_GATEWAY_IMAGE": "edge-gateway", "CDNF_MMDB_UPDATER_IMAGE": "mmdb-updater",
}
REGISTRY = "ghcr.io/vaheed/cdnfoundry-"


def _tls_context() -> Any:
    """Verify the registry with the certifi bundle when present. Python from python.org on macOS ships without system roots."""
    import ssl

    try:
        import certifi

        return ssl.create_default_context(cafile=certifi.where())
    except ImportError:
        return ssl.create_default_context()


def registry_digest(name: str, tag: str) -> str:
    """The sha256 digest the public registry holds for one release image."""
    import urllib.error
    import urllib.request

    repository = f"vaheed/cdnfoundry-{name}"
    context = _tls_context()
    try:
        with urllib.request.urlopen(f"https://ghcr.io/token?scope=repository:{repository}:pull", timeout=20, context=context) as response:
            token = json.load(response)["token"]
        request = urllib.request.Request(
            f"https://ghcr.io/v2/{repository}/manifests/{tag}", method="HEAD",
            headers={"Authorization": f"Bearer {token}", "Accept": "application/vnd.oci.image.index.v1+json, application/vnd.oci.image.manifest.v1+json, "
                     "application/vnd.docker.distribution.manifest.list.v2+json, application/vnd.docker.distribution.manifest.v2+json"})
        with urllib.request.urlopen(request, timeout=20, context=context) as response:
            digest = response.headers.get("Docker-Content-Digest", "")
    except urllib.error.HTTPError as exc:
        if exc.code == 404:
            raise ValidationError(f"The image {REGISTRY}{name}:{tag} is not published. Check that release {tag} exists and its CI run finished") from exc
        raise ValidationError(f"The image registry answered {exc.code} for {name}:{tag}") from exc
    except (urllib.error.URLError, OSError, KeyError, ValueError) as exc:
        hint = " Run `pip install certifi` in the Fleet environment, or on macOS run the `Install Certificates.command` that comes with Python." if "CERTIFICATE_VERIFY_FAILED" in str(exc) else ""
        raise ValidationError(f"Could not reach the image registry to look up release {tag}: {exc}.{hint}") from exc
    if not mf.DIGEST.match(digest):
        raise ValidationError(f"The registry returned no digest for {name}:{tag}")
    return digest


def automatic_manifest(root: Path, release: str, *, digest: Any = registry_digest) -> dict[str, Any]:
    """Build the manifest for a published release from the checkout, looking every image digest up in the registry."""
    env_images = {var: f"{REGISTRY}{name}@{digest(name, release)}" for var, name in IMAGE_NAMES.items()}
    return build(root, release=release, env_images=env_images)


def generate_key(directory: Path) -> tuple[Path, Path]:
    key = Ed25519PrivateKey.generate()
    directory.mkdir(parents=True, exist_ok=True)
    private, public = directory / "release-signing.key", directory / "release-signing.pub"
    private.write_bytes(key.private_bytes(serialization.Encoding.PEM, serialization.PrivateFormat.PKCS8, serialization.NoEncryption()))
    private.chmod(0o600)
    public.write_bytes(key.public_key().public_bytes(serialization.Encoding.PEM, serialization.PublicFormat.SubjectPublicKeyInfo))
    return private, public


def sign_file(manifest: dict[str, Any], private_key_pem: bytes) -> dict[str, Any]:
    key = serialization.load_pem_private_key(private_key_pem, password=None)
    if not isinstance(key, Ed25519PrivateKey):
        raise ValidationError("The signing key must be Ed25519")
    return mf.sign(manifest, key)


def main(argv: list[str] | None = None) -> int:
    import argparse
    import sys

    p = argparse.ArgumentParser(prog="cdnfoundry-release-manifest")
    p.add_argument("--repo-root", default=".")
    p.add_argument("--release", required=True)
    p.add_argument("--signing-key", required=True)
    p.add_argument("--env-image", action="append", default=[], metavar="CDNF_CORE_IMAGE=ref@sha256:...")
    p.add_argument("--profile", choices=sorted(mf.PROFILES), default="full")
    p.add_argument("--irreversible-migration", action="store_true")
    p.add_argument("--no-mixed-versions", action="store_true")
    p.add_argument("--trivy-report", action="append", default=[])
    p.add_argument("--out", required=True)
    a = p.parse_args(argv)
    vulns: list[dict[str, Any]] = []
    for report in a.trivy_report:
        vulns += from_trivy(json.loads(Path(report).read_text(encoding="utf-8")))
    manifest = build(Path(a.repo_root), release=a.release, env_images=dict(i.split("=", 1) for i in a.env_image), profile=a.profile,
                     irreversible_migration=a.irreversible_migration, vulnerabilities=vulns, mixed_versions_ok=not a.no_mixed_versions)
    Path(a.out).write_text(json.dumps(sign_file(manifest, Path(a.signing_key).read_bytes()), indent=1, sort_keys=True) + "\n", encoding="utf-8")
    print(f"wrote {a.out}", file=sys.stderr)
    return 0
