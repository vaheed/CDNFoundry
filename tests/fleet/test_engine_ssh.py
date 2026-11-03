"""Upgrade engine over a real SSH connection to a local sshd, with stub bundles in place of Docker."""
from __future__ import annotations

import getpass
import shutil
import sys
from pathlib import Path
from typing import Any

import pytest

HERE = Path(__file__).resolve().parent
sys.path.insert(0, str(HERE))
sys.path.insert(0, str(HERE.parents[1] / "scripts"))

from test_ssh import Sshd  # noqa: E402,F401
from test_ssh import sshd  # noqa: E402,F401

from cdnfoundry_fleet import actions as a  # noqa: E402
from cdnfoundry_fleet.ssh import SshError, SshTransport, Target  # noqa: E402

pytestmark = pytest.mark.skipif(not (shutil.which("sshd") or Path("/usr/sbin/sshd").exists()), reason="sshd is required")

D1, D2 = "sha256:" + "1" * 64, "sha256:" + "2" * 64
NODES = {"pop-1": {"role": "edge"}, "pop-2": {"role": "edge"}}


def manifest(version: str, digest: str) -> dict[str, Any]:
    return {"release": version, "profile": "full", "ci_passed": True, "irreversible_migration": False, "env_images": {},
            "images": {}, "configs": {"nginx.conf": digest[7:71].replace("1", "a").replace("2", "b")}, "roles": {"edge": {"images": [], "configs": ["nginx.conf"]}}}


def stub(directory: Path, release: str, *, start_ok: bool = True, validate_ok: bool = True) -> None:
    directory.mkdir(parents=True, exist_ok=True)
    (directory / "validate.sh").write_text("#!/bin/sh\n" + ("exit 0\n" if validate_ok else "echo bad config >&2; exit 1\n"))
    (directory / "start.sh").write_text("#!/bin/sh\n" + (f"cat RELEASE > running\n" if start_ok else "exit 1\n"))
    (directory / "RELEASE").write_text(release + "\n")
    for f in ("validate.sh", "start.sh"):
        (directory / f).chmod(0o755)


class Counting:
    """Wraps the real transport; can drop the connection once, and counts uploads."""

    def __init__(self, real: SshTransport) -> None:
        self.real, self.uploads, self.drop_once_on = real, [], None

    def pin_host_key(self, target):
        return self.real.pin_host_key(target)

    def run(self, target, command, **kw):
        if self.drop_once_on and self.drop_once_on[0] == target.name and self.drop_once_on[1] in command:
            self.drop_once_on = None
            raise SshError("connection dropped")
        return self.real.run(target, command, **kw)

    def upload_tree(self, target, local, remote, **kw):
        self.uploads.append((target.name, remote))
        return self.real.upload_tree(target, local, remote, **kw)


@pytest.fixture()
def fleet(tmp_path: Path, sshd):
    real = SshTransport(tmp_path / "state")
    targets = {n: Target(n, "127.0.0.1", sshd.port, getpass.getuser()) for n in NODES}
    real.dir.mkdir(parents=True, exist_ok=True, mode=0o700)
    shutil.copy(sshd.client_key, real.key_path())
    real.key_path().chmod(0o600)
    for t in targets.values():
        real.pin_host_key(t)
    roots = {n: str(tmp_path / "remote" / n) for n in NODES}
    for n, r in roots.items():
        stub(Path(r), "2.0.0")
        (Path(r) / "running").write_text("2.0.0\n")
        (Path(r) / ".cdnf-good").write_text("")  # the running release passed its gate
    bundles = tmp_path / "bundles"
    for n in NODES:
        stub(bundles / n, "2.0.1")
    transport = Counting(real)
    transport.port = sshd.port

    def gate(node: str) -> tuple[bool, str]:
        running = Path(roots[node]) / "running"
        return (running.exists() and running.read_text().strip() == "2.0.1", "release not running")

    def make(**kw) -> a.Engine:
        return a.Engine(transport, targets, NODES, bundles, a.Journal(tmp_path / "journal.json"), gate=gate, roots=roots, provision=lambda _n: (True, ""),
                        services={n: set() for n in NODES}, **kw)

    return make, transport, roots, bundles


def plan() -> a.Plan:
    return a.plan_upgrade(NODES, manifest("2.0.0", D1), manifest("2.0.1", D2))


def test_rolling_upgrade_over_ssh_activates_each_node_and_keeps_the_previous_release(fleet) -> None:
    make, transport, roots, _ = fleet
    out = make().apply(plan(), manifest("2.0.1", D2))
    assert out.ok, out.detail
    for n, root in roots.items():
        assert (Path(root) / "RELEASE").read_text().strip() == "2.0.1"
        assert (Path(root + ".previous") / "RELEASE").read_text().strip() == "2.0.0"


def test_upgrade_interrupted_by_a_dropped_connection_completes_when_run_again(fleet) -> None:
    make, transport, roots, _ = fleet
    transport.drop_once_on = ("pop-2", "start.sh")
    first = make().apply(plan(), manifest("2.0.1", D2))
    assert not first.ok and "run the action again" in first.detail
    assert (Path(roots["pop-1"]) / "RELEASE").read_text().strip() == "2.0.1"
    assert (Path(roots["pop-2"]) / "RELEASE").read_text().strip() == "2.0.0"
    second = make().apply(plan(), manifest("2.0.1", D2))
    assert second.ok
    assert [n for n, _r in transport.uploads].count("pop-1") == 1
    assert (Path(roots["pop-2"]) / "RELEASE").read_text().strip() == "2.0.1"


def test_broken_release_stops_at_the_canary_rolls_it_back_and_leaves_the_rest(fleet) -> None:
    make, transport, roots, bundles = fleet
    stub(bundles / "pop-1", "2.0.1", start_ok=False)
    out = make().apply(plan(), manifest("2.0.1", D2))
    assert not out.ok and out.failed == "pop-1:activate" and out.rolled_back == ["pop-1"]
    assert (Path(roots["pop-1"]) / "RELEASE").read_text().strip() == "2.0.0"
    assert (Path(roots["pop-2"]) / "RELEASE").read_text().strip() == "2.0.0"
    assert not any(n == "pop-2" for n, _ in transport.uploads)


def test_config_rejected_on_the_node_is_never_activated(fleet) -> None:
    make, transport, roots, bundles = fleet
    stub(bundles / "pop-1", "2.0.1", validate_ok=False)
    out = make().apply(plan(), manifest("2.0.1", D2))
    assert out.failed == "pop-1:validate" and "rejected the new config" in out.detail and out.rolled_back == []
    assert (Path(roots["pop-1"]) / "RELEASE").read_text().strip() == "2.0.0"
    assert (Path(roots["pop-1"] + ".next") / "RELEASE").exists()  # staged, never swapped in


def test_rollback_returns_the_previous_bundle(fleet) -> None:
    make, transport, roots, _ = fleet
    engine = make()
    assert engine.apply(plan(), manifest("2.0.1", D2)).ok
    assert engine._rollback(["pop-1", "pop-2"], [], lambda _m: None) == ["pop-1", "pop-2"]
    for root in roots.values():
        assert (Path(root) / "RELEASE").read_text().strip() == "2.0.0"


def test_validation_runs_the_start_script_up_to_validate_and_no_further(fleet) -> None:
    from cdnfoundry_fleet.validators import validate_node_config

    _, transport, roots, bundles = fleet
    staged = Path(roots["pop-1"] + ".next")
    stub(staged, "2.0.1")
    (staged / "start.sh").write_text("#!/bin/sh\ntouch prepared\n./validate.sh\ntouch started\n")
    target = Target("pop-1", "127.0.0.1", transport.port, getpass.getuser())
    ok, detail = validate_node_config(transport, target, "edge", str(staged), set())
    assert ok, detail
    assert (staged / "prepared").exists() and not (staged / "started").exists()


def test_activation_is_safe_to_run_twice_after_a_drop_that_happened_after_the_swap(fleet) -> None:
    make, _, roots, _ = fleet
    engine = make()
    assert engine.apply(plan(), manifest("2.0.1", D2)).ok
    again = engine._run(a.Step("pop-1:activate", "pop-1", "activate"), manifest("2.0.1", D2))
    assert again[0], again[1]
    assert (Path(roots["pop-1"]) / "RELEASE").read_text().strip() == "2.0.1"
    assert (Path(roots["pop-1"] + ".previous") / "RELEASE").read_text().strip() == "2.0.0"


def test_only_a_bundle_that_passed_its_gate_becomes_the_rollback_target(fleet) -> None:
    make, _, roots, bundles = fleet
    (Path(roots["pop-1"]) / ".cdnf-good").unlink()  # the running bundle never passed a gate (a first install that failed)
    stub(bundles / "pop-1", "2.0.1")
    out = make().apply(plan(), manifest("2.0.1", D2))
    assert out.ok
    assert not Path(roots["pop-1"] + ".previous").exists()  # nothing known-good to keep
    assert (Path(roots["pop-1"]) / ".cdnf-good").exists()  # the new bundle passed its gate and is now the one to keep
    assert (Path(roots["pop-2"] + ".previous") / "RELEASE").read_text().strip() == "2.0.0"
