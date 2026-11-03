"""A failed engine action must still keep what it already did (an enrollment, a journal) in the vault."""
from __future__ import annotations

import json
import sys
from dataclasses import dataclass
from pathlib import Path

import pytest

sys.path.insert(0, str(Path(__file__).resolve().parents[2] / "scripts"))

from cdnfoundry_fleet import cli, engine as engine_module, release  # noqa: E402
from cdnfoundry_fleet.cli import main  # noqa: E402
from cdnfoundry_fleet.ssh import Target, save_target  # noqa: E402
from cdnfoundry_fleet.vault import Vault  # noqa: E402

ROOT = Path(__file__).resolve().parents[2]
PASS = "correct horse battery"
EDGE_ID = "11111111-2222-4333-8444-555555555555"


@dataclass
class R:
    code: int = 0
    stdout: str = ""
    stderr: str = ""

    @property
    def ok(self) -> bool:
        return self.code == 0


class FakeSsh:
    """Answers like a node whose bundle validation fails after the PoP has been enrolled."""

    def __init__(self, work: Path) -> None:
        self.work = work

    def ensure_fleet_key(self):
        return "ssh-ed25519 AAAA"

    def pin_host_key(self, target):
        return target.host

    def run(self, target, command, **_):
        if "docker image inspect" in command:
            ref = command.rsplit(" ", 1)[-1]
            return R(0, ref + "\n")
        if "edges:enroll" in command:
            return R(0, json.dumps({"edge_id": EDGE_ID, "bootstrap_token": "one-time-token"}) + "\n")
        if "validate.sh" in command and "sed" in command:
            return R(1, "", "rejected")
        return R()

    def upload_tree(self, *_a, **_k):
        pass


def test_enrollment_survives_a_bootstrap_that_fails_later(tmp_path: Path, monkeypatch: pytest.MonkeyPatch) -> None:
    monkeypatch.setenv("CDNF_FLEET_PASSPHRASE", PASS)
    monkeypatch.setattr(engine_module, "SshTransport", FakeSsh)
    vault = tmp_path / "fleet.db"
    common = ["--vault", str(vault), "--output-dir", str(tmp_path / "bundles"), "--repo-root", str(ROOT), "--non-interactive", "--yes"]
    topology = tmp_path / "topology.json"
    topology.write_text(json.dumps({"preset": "control-only", "global": {"operator_domain": "ops.example.test", "platform_domain": "cdn.example.test", "release": "v0",
                                    "acme_email": "acme@example.test", "alert_webhook_url": "https://alerts.example.test/h"},
                                    "nodes": [{"name": "control-1", "role": "control", "public_ipv4": "93.184.216.10"},
                                              {"name": "pop-1", "role": "edge", "public_ipv4": "93.184.216.11"}]}))
    assert main(["setup", "--config", str(topology), "--no-render", *common]) == 0
    private, public = release.generate_key(tmp_path / "keys")
    needed = {n: f"registry.example/{n.lower()}@sha256:{'a' * 64}" for n in __import__("re").findall(r"\$\{(CDNF_[A-Z_]+_IMAGE)", (ROOT / "compose.prod.yml").read_text())}
    manifest = tmp_path / "release.json"
    manifest.write_text(json.dumps(release.sign_file(release.build(ROOT, release="v2.0.0", env_images=needed), private.read_bytes())))
    assert main(["trust-release-key", "--key-file", str(public), *common]) == 0
    assert main(["set-release", "--manifest", str(manifest), *common]) == 0
    with Vault(vault, PASS).session() as work:
        for n, ip in (("control-1", "93.184.216.10"), ("pop-1", "93.184.216.11")):
            save_target(work, Target(n, ip))

    code = main(["bootstrap", "--node", "pop-1", "--country", "DE", "--continent", "EU", *common])
    assert code == cli.EXIT_EXTERNAL  # validation failed, as arranged

    with Vault(vault, PASS).session() as work:
        state = json.loads((work / "fleet.json").read_text())
        assert state["nodes"]["pop-1"]["extra_env"]["EDGE_ID"] == EDGE_ID
        assert (work / "secrets" / "nodes" / "pop-1" / "edge-bootstrap-token").read_text().strip() == "one-time-token"
