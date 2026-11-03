from __future__ import annotations

import shutil
import socket
import subprocess
import sys
import time
from pathlib import Path

import pytest

sys.path.insert(0, str(Path(__file__).resolve().parents[2] / "scripts"))

from cdnfoundry_fleet.ssh import HostKeyChanged, SshError, SshTransport, Target  # noqa: E402

pytestmark = pytest.mark.skipif(not (shutil.which("sshd") or Path("/usr/sbin/sshd").exists()), reason="sshd is required")


def free_port() -> int:
    with socket.socket() as s:
        s.bind(("127.0.0.1", 0))
        return s.getsockname()[1]


class Sshd:
    def __init__(self, root: Path) -> None:
        self.root = root
        self.port = free_port()
        self.client_key = root / "client"
        subprocess.run(["ssh-keygen", "-q", "-t", "ed25519", "-N", "", "-f", str(self.client_key)], check=True)
        self.start()

    def newhostkey(self) -> None:
        (self.root / "host").unlink(missing_ok=True)
        subprocess.run(["ssh-keygen", "-q", "-t", "ed25519", "-N", "", "-f", str(self.root / "host")], check=True)

    def start(self) -> None:
        if not (self.root / "host").exists():
            self.newhostkey()
        shutil.copy(self.client_key.with_suffix(".pub"), self.root / "authorized_keys")
        (self.root / "authorized_keys").chmod(0o600)
        (self.root / "sshd_config").write_text(
            f"Port {self.port}\nListenAddress 127.0.0.1\nHostKey {self.root / 'host'}\n"
            f"AuthorizedKeysFile {self.root / 'authorized_keys'}\nPasswordAuthentication no\nPubkeyAuthentication yes\n"
            f"PidFile {self.root / 'pid'}\nStrictModes no\nUsePAM no\n"
        )
        self.proc = subprocess.Popen([shutil.which("sshd") or "/usr/sbin/sshd", "-D", "-e", "-f", str(self.root / "sshd_config")],
                                     stderr=subprocess.DEVNULL)
        for _ in range(50):
            try:
                socket.create_connection(("127.0.0.1", self.port), timeout=0.2).close()
                return
            except OSError:
                time.sleep(0.1)
        raise RuntimeError("sshd did not start")

    def stop(self) -> None:
        self.proc.terminate()
        self.proc.wait(5)


@pytest.fixture()
def sshd(tmp_path: Path):
    Path("/run/sshd").mkdir(exist_ok=True)
    server = Sshd(tmp_path)
    yield server
    server.stop()


def install_key(t: SshTransport, private: Path) -> None:
    """Stands in for the Fleet key: the test server authorises this key pair."""
    t.dir.mkdir(parents=True, exist_ok=True, mode=0o700)
    shutil.copy(private, t.key_path())
    t.key_path().chmod(0o600)


def transport(tmp_path: Path, sshd: Sshd) -> tuple[SshTransport, Target]:
    t = SshTransport(tmp_path / "state")
    target = Target("pop-1", "127.0.0.1", sshd.port, "root")
    install_key(t, sshd.client_key)
    return t, target


def test_runs_a_command_after_pinning_the_host_key(tmp_path: Path, sshd: Sshd) -> None:
    t, target = transport(tmp_path, sshd)
    with pytest.raises(HostKeyChanged):
        t.run(target, "echo hi")  # not pinned yet: strict mode refuses
    t.pin_host_key(target)
    assert t.run(target, "echo hi").stdout.strip() == "hi"
    assert oct(t.key_path(target).stat().st_mode & 0o777) == "0o600"


def test_a_changed_host_key_is_refused(tmp_path: Path, sshd: Sshd) -> None:
    t, target = transport(tmp_path, sshd)
    t.pin_host_key(target)
    sshd.stop()
    sshd.newhostkey()
    sshd.start()
    with pytest.raises(HostKeyChanged):
        t.run(target, "true")


def test_password_and_unknown_keys_do_not_work(tmp_path: Path, sshd: Sshd) -> None:
    t, target = transport(tmp_path, sshd)
    t.pin_host_key(target)
    other = tmp_path / "other"
    subprocess.run(["ssh-keygen", "-q", "-t", "ed25519", "-N", "", "-f", str(other)], check=True)
    install_key(t, other)
    assert not t.run(target, "true").ok


def test_failed_command_raises_when_checked(tmp_path: Path, sshd: Sshd) -> None:
    t, target = transport(tmp_path, sshd)
    t.pin_host_key(target)
    assert t.run(target, "exit 3").code == 3
    with pytest.raises(SshError):
        t.run(target, "exit 3", check=True)


def test_upload_replaces_the_directory_and_keeps_the_previous_one(tmp_path: Path, sshd: Sshd) -> None:
    t, target = transport(tmp_path, sshd)
    t.pin_host_key(target)
    bundle = tmp_path / "bundle"
    bundle.mkdir()
    (bundle / "compose.yml").write_text("one")
    remote = str(tmp_path / "remote" / "bundle")
    (tmp_path / "remote").mkdir()
    t.upload_tree(target, bundle, remote)
    (bundle / "compose.yml").write_text("two")
    t.upload_tree(target, bundle, remote)
    assert (Path(remote) / "compose.yml").read_text() == "two"
    assert (Path(remote + ".previous") / "compose.yml").read_text() == "one"


def test_unsafe_targets_and_paths_are_rejected(tmp_path: Path) -> None:
    from cdnfoundry_fleet.common import ValidationError

    for bad in ({"host": "-oProxyCommand=x"}, {"host": "a;b"}, {"user": "-x"}, {"port": 0}):
        with pytest.raises(ValidationError):
            Target("n", **{"host": "h", **bad})
    t = SshTransport(tmp_path)
    with pytest.raises(ValidationError):
        t.upload_tree(Target("n", "h"), tmp_path, "/")


def test_one_fleet_key_is_created_once_and_never_changes(tmp_path: Path) -> None:
    t = SshTransport(tmp_path / "state")
    first = t.ensure_fleet_key()
    assert first.startswith("ssh-ed25519 ") and first.endswith("cdnfoundry-fleet")
    assert t.ensure_fleet_key() == first == t.public_key()
    assert oct(t.key_path().stat().st_mode & 0o777) == "0o600"
    assert not Path(str(t.key_path()) + ".pub").exists()
    assert t.key_path(Target("a", "h")) == t.key_path(Target("b", "h"))


def test_the_generated_fleet_key_logs_in_to_a_server_that_authorises_its_public_key(tmp_path: Path, sshd: Sshd) -> None:
    t = SshTransport(tmp_path / "state")
    target = Target("pop-1", "127.0.0.1", sshd.port, "root")
    (sshd.root / "authorized_keys").write_text(t.public_key() + "\n")
    t.pin_host_key(target)
    assert t.run(target, "echo ok").stdout.strip() == "ok"


def test_a_non_root_user_runs_every_command_through_passwordless_sudo(tmp_path: Path, monkeypatch: pytest.MonkeyPatch) -> None:
    seen: list[list[str]] = []

    class Done:
        returncode, stdout, stderr = 0, b"", b""

    monkeypatch.setattr(subprocess, "run", lambda args, **kw: seen.append(list(args)) or Done())
    t = SshTransport(tmp_path / "state")
    t.ensure_fleet_key = lambda: ""  # type: ignore[method-assign]
    t.dir.mkdir(parents=True)
    t.key_path().write_text("k")
    t.run(Target("n", "h", 22, "vk-user"), "echo 'a b'; id")
    t.run_raw(Target("n", "h", 22, "vk-user"), "id")
    t.run(Target("n", "h", 22, "root"), "id")
    assert seen[0][-1] == "sudo -n sh -c 'echo '\"'\"'a b'\"'\"'; id'"
    assert seen[1][-1] == "sudo -n sh -c id"
    assert seen[2][-1] == "id"
    assert seen[0][-2].startswith("vk-user@")


def test_a_dropped_connection_is_a_transport_error_not_a_command_failure(tmp_path: Path, monkeypatch: pytest.MonkeyPatch) -> None:
    class Dropped:
        returncode, stdout, stderr = 255, b"", b"Connection to host closed by remote host."

    monkeypatch.setattr(subprocess, "run", lambda *a, **k: Dropped())
    t = SshTransport(tmp_path / "state")
    t.ensure_fleet_key = lambda: ""  # type: ignore[method-assign]
    t.dir.mkdir(parents=True)
    t.key_path().write_text("k")
    with pytest.raises(SshError, match="failed or was interrupted"):
        t.run(Target("pop-1", "h"), "./start.sh")
