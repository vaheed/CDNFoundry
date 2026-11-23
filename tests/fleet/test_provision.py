from __future__ import annotations

import io
import subprocess
import sys
import tarfile
from dataclasses import dataclass
from pathlib import Path

import pytest

sys.path.insert(0, str(Path(__file__).resolve().parents[2] / "scripts"))

from cdnfoundry_fleet import provision  # noqa: E402
from cdnfoundry_fleet.ssh import Target  # noqa: E402
from cdnfoundry_fleet.state import FleetState  # noqa: E402

ROOT = Path(__file__).resolve().parents[2]


@dataclass
class R:
    code: int = 0
    stdout: str = ""
    stderr: str = ""

    @property
    def ok(self) -> bool:
        return self.code == 0


def fleet(tmp_path: Path):
    store = FleetState(tmp_path / "state")
    state = store.init({"operator_domain": "ops.example.test", "platform_domain": "cdn.example.test", "release": "v2.0.0"})
    state = store.add_node(state, {"name": "pop-1", "role": "dns-edge", "public_ipv4": "93.184.216.12"})
    state = store.add_node(state, {"name": "pop-2", "role": "edge", "public_ipv4": "93.184.216.13"})
    return store, store.load()["nodes"]


def test_dns_edge_gets_four_files_and_an_edge_gets_three_signed_by_the_fleet_ca(tmp_path: Path) -> None:
    store, nodes = fleet(tmp_path)
    pop1 = provision.issue(store.state_dir, ROOT, nodes["pop-1"])
    pop2 = provision.issue(store.state_dir, ROOT, nodes["pop-2"])
    assert set(pop1) == {"edge-runtime.crt", "edge-runtime.key", "edge-state-encryption.key", "node.key"}
    assert set(pop2) == {"edge-runtime.crt", "edge-runtime.key", "edge-state-encryption.key"}
    assert len(pop1["edge-state-encryption.key"].strip()) == 64
    crt = tmp_path / "c.crt"
    crt.write_bytes(pop1["edge-runtime.crt"])
    verify = subprocess.run(["openssl", "verify", "-CAfile", str(store.state_dir / "pki" / "edge-server-ca.crt"), str(crt)], capture_output=True, text=True)
    assert verify.returncode == 0, verify.stderr
    assert b"93.184.216.12" in subprocess.run(["openssl", "x509", "-noout", "-text", "-in", str(crt)], capture_output=True).stdout


def test_the_material_is_stable_across_runs_because_it_lives_in_the_vault(tmp_path: Path) -> None:
    store, nodes = fleet(tmp_path)
    first = provision.issue(store.state_dir, ROOT, nodes["pop-1"])
    again = provision.issue(store.state_dir, ROOT, nodes["pop-1"])
    assert first == again
    assert first["edge-runtime.key"] != provision.issue(store.state_dir, ROOT, nodes["pop-2"])["edge-runtime.key"]


def test_archive_carries_key_modes_and_the_install_command_sets_owner_swap_and_tmpfs(tmp_path: Path) -> None:
    store, nodes = fleet(tmp_path)
    blob = provision.archive(provision.issue(store.state_dir, ROOT, nodes["pop-1"]))
    with tarfile.open(fileobj=io.BytesIO(blob)) as tar:
        modes = {m.name: m.mode for m in tar.getmembers()}
    assert modes["edge-runtime.key"] == 0o400 and modes["edge-runtime.crt"] == 0o444 and modes["node.key"] == 0o400
    cmd = provision.install_command()
    assert "swapoff -a" in cmd and "/dev/shm/cdnfoundry" in cmd and "10101" in cmd and "fstab.cdnfoundry-original" in cmd


def test_install_streams_the_files_over_stdin_and_reports_a_failure_with_its_reason() -> None:
    seen: dict = {}

    class T:
        def run(self, target, command, **kw):
            seen.update(command=command, stdin=kw.get("stdin"))
            return R(1, "", "tar: boom") if "FAIL" in str(kw.get("stdin", b"")) else R()

    ok, _ = provision.install(T(), Target("n", "h"), {"edge-runtime.key": b"k"})
    assert ok and seen["stdin"] and b"edge-runtime.key" in seen["stdin"]
    ok, detail = provision.install(T(), Target("n", "h"), {"edge-runtime.key": b"FAIL"})
    assert not ok and "boom" in detail


def test_only_edge_roles_need_the_material_and_the_plans_provision_before_validating() -> None:
    from cdnfoundry_fleet import actions

    assert provision.needs_material("edge") and provision.needs_material("dns-edge")
    assert not provision.needs_material("control") and not provision.needs_material("dns")
    kinds = [s.kind for s in actions.plan_bootstrap("pop-1", "v2", provision=True, enroll=True, prepull=True).steps]
    assert kinds.index("provision") < kinds.index("upload") < kinds.index("validate")
    nodes = {"pop-1": {"role": "dns-edge"}, "control-1": {"role": "control"}}
    plan = actions.plan_config_change(nodes, {"pop-1", "control-1"}, "v2")
    assert [s.kind for s in plan.steps if s.node == "pop-1"][0] == "provision"
    assert "provision" not in [s.kind for s in plan.steps if s.node == "control-1"]


def test_restore_recovers_empty_mount_directories_and_preserves_key_bytes(tmp_path: Path) -> None:
    import os

    runtime = tmp_path / 'runtime'
    runtime.mkdir()
    for name in ('edge-runtime.crt', 'edge-runtime.key', 'edge-state-encryption.key'):
        (runtime / name).mkdir()
    credentials = tmp_path / 'credentials'
    credentials.mkdir()
    files = {'edge-runtime.crt': b'certificate', 'edge-runtime.key': b'private', 'edge-state-encryption.key': b'a' * 64}
    (credentials / 'edge-material').write_bytes(provision.archive(files))
    script = provision.restore_script().replace(provision.TMPFS_DIR, str(runtime))
    # The test directory is on disk; production must require tmpfs.
    script = script.replace('test "$(stat -f -c %T "$runtime")" = tmpfs', ':')
    script = script.replace('chown -R 10101:10101 "$runtime"', ':')
    for _ in range(2):
        done = subprocess.run(['sh', '-c', script], env={**os.environ, 'CREDENTIALS_DIRECTORY': str(credentials)}, capture_output=True, text=True)
        assert done.returncode == 0, done.stderr
        assert {n: (runtime / n).read_bytes() for n in files} == files
        assert (runtime / 'edge-runtime.key').stat().st_mode & 0o777 == 0o400
    (runtime / 'edge-runtime.key').unlink()
    (runtime / 'edge-runtime.key').mkdir()
    (runtime / 'edge-runtime.key' / 'unexpected').write_text('preserve')
    done = subprocess.run(['sh', '-c', script], env={**os.environ, 'CREDENTIALS_DIRECTORY': str(credentials)}, capture_output=True)
    assert done.returncode != 0
    assert (runtime / 'edge-runtime.key' / 'unexpected').read_text() == 'preserve'


def test_boot_recovery_gates_docker_and_encrypts_material_before_persistence() -> None:
    cmd = provision.install_command()
    assert 'systemd-creds encrypt --with-key=host --name=edge-material - ' in cmd
    assert 'LoadCredentialEncrypted=edge-material:' in cmd
    assert 'Before=docker.service' in cmd
    assert 'Requires=cdnfoundry-edge-material.service' in cmd
    assert 'After=cdnfoundry-edge-material.service' in cmd
    assert 'systemctl start cdnfoundry-edge-material.service' in cmd
    assert 'RemainAfterExit' not in cmd
    assert 'rm -rf' not in cmd
