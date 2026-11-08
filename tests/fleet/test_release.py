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


def test_first_install_adopts_a_signed_release_and_bootstrap_refuses_without_one(tmp_path: Path, monkeypatch: pytest.MonkeyPatch) -> None:
    from cdnfoundry_fleet.common import ValidationError
    from cdnfoundry_fleet.engine import FleetEngine
    from cdnfoundry_fleet.ssh import Target, save_target
    from cdnfoundry_fleet.state import FleetState

    store = FleetState(tmp_path / "state")
    state = store.init({"operator_domain": "ops.example.test", "platform_domain": "cdn.example.test", "release": "v0"})
    store.add_node(state, {"name": "control-1", "role": "control", "public_ipv4": "93.184.216.10"})
    save_target(tmp_path / "state", Target("control-1", "93.184.216.10"))
    engine = FleetEngine(tmp_path / "state", ROOT, tmp_path / "out", transport=object(), say=lambda _m: None)
    with pytest.raises(ValidationError, match="No signed release is set"):
        engine.bootstrap("control-1")

    private, public = release.generate_key(tmp_path / "keys")
    doc = release.sign_file(release.build(ROOT, release="v2.0.0", env_images=needed_env()), private.read_bytes())
    with pytest.raises(ValidationError, match="No release signing key is trusted"):
        engine.set_release(doc)
    engine.trust_release_key(public.read_text())
    assert engine.set_release(doc)["release"] == "v2.0.0"
    node = engine.state()["nodes"]["control-1"]
    assert node["release"] == "v2.0.0" and node["extra_env"]["CDNF_CORE_IMAGE"].endswith(D)
    assert any(s.kind == "prepull" for s in engine.bootstrap_plan("control-1").steps)
    with pytest.raises(ValidationError, match="already running"):
        engine.set_release(doc)
    forged = json.loads(json.dumps(doc))
    forged["manifest"]["release"] = "v9"
    engine2 = FleetEngine(tmp_path / "state", ROOT, tmp_path / "out", transport=object(), say=lambda _m: None)
    (engine2.work / "releases" / "current.json").unlink()
    with pytest.raises(mf.ManifestError, match="does not verify"):
        engine2.set_release(forged)


def test_automatic_release_builds_signs_and_verifies_without_any_pasted_key(tmp_path: Path) -> None:
    from cdnfoundry_fleet.engine import FleetEngine

    engine = FleetEngine(tmp_path / "state", ROOT, tmp_path / "out", transport=object(), say=lambda _m: None)
    looked_up: list[tuple[str, str]] = []

    def digest(name: str, tag: str) -> str:
        looked_up.append((name, tag))
        return "sha256:" + "a" * 64

    document = engine.automatic_release("v2.0.0", digest=digest)
    assert {name for name, _tag in looked_up} == set(release.IMAGE_NAMES.values())
    assert engine.verify_release(document)["release"] == "v2.0.0"
    assert engine.automatic_release("v2.0.0", digest=digest)["signature"] == document["signature"]


def test_automatic_release_refuses_when_the_operator_pinned_their_own_key(tmp_path: Path) -> None:
    from cdnfoundry_fleet.common import ValidationError
    from cdnfoundry_fleet.engine import FleetEngine

    engine = FleetEngine(tmp_path / "state", ROOT, tmp_path / "out", transport=object(), say=lambda _m: None)
    engine.trust_release_key(release.generate_key(tmp_path / "keys")[1].read_text())
    with pytest.raises(ValidationError, match="pinned your own release key"):
        engine.automatic_release("v2.0.0", digest=lambda _n, _t: "sha256:" + "a" * 64)


def test_registry_lookup_reports_an_unpublished_image_clearly(monkeypatch: pytest.MonkeyPatch) -> None:
    import io
    import urllib.error
    import urllib.request

    from cdnfoundry_fleet.common import ValidationError

    def refuse(*_a, **_k):
        raise urllib.error.HTTPError("https://ghcr.io", 404, "not found", {}, io.BytesIO())

    monkeypatch.setattr(urllib.request, "urlopen", refuse)
    with pytest.raises(ValidationError, match="is not published"):
        release.registry_digest("core", "v9.9.9")
