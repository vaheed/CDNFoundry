from __future__ import annotations

import json
import sys
from pathlib import Path

import pytest

sys.path.insert(0, str(Path(__file__).resolve().parents[2] / "scripts"))

from cdnfoundry_fleet import manifest as mf  # noqa: E402
from cdnfoundry_fleet import release  # noqa: E402

ROOT = Path(__file__).resolve().parents[2]
D = "sha256:" + "a" * 64
ENV = {
    "CDNF_CORE_IMAGE": f"ghcr.io/o/core@{D}", "CDNF_WEB_IMAGE": f"ghcr.io/o/web@{D}",
    "CDNF_EDGE_CONTROL_IMAGE": f"ghcr.io/o/edge-control@{D}", "CDNF_EDGE_RUNTIME_IMAGE": f"ghcr.io/o/edge-runtime@{D}",
}


def needed_env() -> dict[str, str]:
    import re

    names = set(re.findall(r"\$\{(CDNF_[A-Z_]+_IMAGE)", (ROOT / "compose.prod.yml").read_text()))
    return {n: ENV.get(n, f"ghcr.io/o/{n.lower()}@{D}") for n in names}


def test_manifest_of_the_real_repository_is_complete_and_verifies(tmp_path: Path) -> None:
    manifest = release.build(ROOT, release="2.0.1", env_images=needed_env())
    private, public = release.generate_key(tmp_path)
    doc = release.sign_file(manifest, private.read_bytes())
    verified = mf.verify(doc, mf.public_key_from_pem(public.read_text()))
    mf.verify_checkout(verified, ROOT)
    assert verified["roles"]["dns"]["images"] and verified["roles"]["edge"]["images"]
    assert "docker/dnsdist/dnsdist.conf" in verified["roles"]["dns"]["configs"]
    assert "docker/dnsdist/dnsdist.conf" not in verified["roles"]["monitoring"]["configs"]


def test_changing_only_a_dns_image_restarts_only_dns_roles() -> None:
    old = release.build(ROOT, release="2.0.0", env_images=needed_env())
    new = json.loads(json.dumps(old))
    dns_image = next(i for i in new["roles"]["dns"]["images"] if "dnsdist" in i)
    new["images"][dns_image] = "sha256:" + "b" * 64
    d = mf.diff(old, new)
    assert "dns" in d.roles and "dns-edge" in d.roles
    assert "edge" not in d.roles and "monitoring" not in d.roles


def test_missing_env_image_is_refused() -> None:
    from cdnfoundry_fleet.common import ValidationError

    with pytest.raises(ValidationError, match="CDNF_"):
        release.build(ROOT, release="2.0.1", env_images={})


def test_trivy_report_becomes_vulnerabilities() -> None:
    rows = release.from_trivy({"ArtifactName": "grafana/grafana@sha256:x", "Results": [{"Vulnerabilities": [
        {"VulnerabilityID": "CVE-1", "Severity": "HIGH", "FixedVersion": "1.2"}, {"VulnerabilityID": "CVE-2", "Severity": "CRITICAL"}]}]})
    assert rows[0]["fixed_version"] == "1.2" and rows[1]["fixed_version"] is None and rows[0]["image"] == "grafana/grafana"


def test_cli_writes_a_signed_file(tmp_path: Path) -> None:
    private, public = release.generate_key(tmp_path)
    args = ["--repo-root", str(ROOT), "--release", "2.0.1", "--signing-key", str(private), "--out", str(tmp_path / "m.json")]
    for k, v in needed_env().items():
        args += ["--env-image", f"{k}={v}"]
    assert release.main(args) == 0
    mf.verify(json.loads((tmp_path / "m.json").read_text()), mf.public_key_from_pem(public.read_text()))
