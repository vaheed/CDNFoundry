"""SSH transport for Fleet actions.

Key authentication only. A host key is pinned on first use into the vault and a
changed key is refused. Uploads stream a tar archive over the same connection,
so a node needs nothing but sshd, tar, and a POSIX shell.
"""
from __future__ import annotations

import os
import queue
import shlex
import subprocess
import tarfile
import tempfile
import threading
import time
from dataclasses import dataclass
from typing import Callable
from pathlib import Path

from .common import FleetError, ValidationError, atomic_write

SSH_TIMEOUT = 120
STREAM_HEARTBEAT_SECONDS = 30
_PIN_LOCK = threading.Lock()  # hosts are pinned side by side; the known_hosts file is read, extended, and rewritten as a whole
FLEET_KEY = "fleet_ed25519"


class SshError(FleetError):
    pass


class HostKeyChanged(SshError):
    pass


@dataclass(frozen=True)
class Target:
    name: str
    host: str
    port: int = 22
    user: str = "root"

    def __post_init__(self) -> None:
        if not (1 <= self.port <= 65535):
            raise ValidationError("SSH port must be between 1 and 65535")
        if not self.host or any(c in self.host for c in " \t\n;|&$`'\"\\<>") or self.host.startswith("-"):
            raise ValidationError("Invalid SSH host")
        if not self.user or not all(c.isalnum() or c in "_-." for c in self.user) or self.user.startswith("-"):
            raise ValidationError("Invalid SSH user")


@dataclass
class Result:
    code: int
    stdout: str
    stderr: str

    @property
    def ok(self) -> bool:
        return self.code == 0


class SshTransport:
    """`state_dir` is the unlocked vault work directory; keys and pins live under `ssh/`."""

    def __init__(self, state_dir: Path, *, timeout: int = SSH_TIMEOUT) -> None:
        self.dir = state_dir / "ssh"
        self.timeout = timeout

    def key_path(self, target: Target | None = None) -> Path:
        """One key for the whole fleet: every node authorises the same Fleet public key."""
        return self.dir / FLEET_KEY

    def ensure_fleet_key(self) -> str:
        """Create the Fleet key pair on first use and return the public half. The private half never leaves the vault."""
        private = self.key_path()
        if not private.exists():
            self.dir.mkdir(parents=True, exist_ok=True, mode=0o700)
            done = subprocess.run(["ssh-keygen", "-q", "-t", "ed25519", "-N", "", "-C", "cdnfoundry-fleet", "-f", str(private)], capture_output=True, text=True, check=False)
            if done.returncode != 0:
                raise SshError(f"Could not generate the Fleet SSH key: {done.stderr.strip()[-200:]}")
            Path(str(private) + ".pub").unlink(missing_ok=True)
        done = subprocess.run(["ssh-keygen", "-y", "-f", str(private)], capture_output=True, text=True, check=False)
        if done.returncode != 0:
            raise SshError("The Fleet SSH key is unreadable")
        return done.stdout.strip()

    def public_key(self) -> str:
        return self.ensure_fleet_key()

    def known_hosts(self) -> Path:
        return self.dir / "known_hosts"

    def _base(self, target: Target, *, strict: str = "yes") -> list[str]:
        self.ensure_fleet_key()
        key = self.key_path()
        self.dir.mkdir(parents=True, exist_ok=True, mode=0o700)
        self.known_hosts().touch(mode=0o600)
        return [
            "ssh", "-p", str(target.port), "-i", str(key),
            "-o", "BatchMode=yes", "-o", "PasswordAuthentication=no", "-o", "KbdInteractiveAuthentication=no",
            "-o", "PreferredAuthentications=publickey", "-o", "IdentitiesOnly=yes",
            "-o", f"StrictHostKeyChecking={strict}", "-o", f"UserKnownHostsFile={self.known_hosts()}",
            "-o", "GlobalKnownHostsFile=/dev/null", "-o", "ConnectTimeout=15",
            "-o", "ServerAliveInterval=15", "-o", "ServerAliveCountMax=3", "-o", "LogLevel=ERROR",
            f"{target.user}@{target.host}",
        ]

    def pin_host_key(self, target: Target) -> str:
        """Record the host key on first use. A host that is already pinned is left alone."""
        with _PIN_LOCK:
            return self._pin_host_key_locked(target)

    def _pin_host_key_locked(self, target: Target) -> str:
        pinned = self.known_hosts().read_text(encoding="utf-8") if self.known_hosts().exists() else ""
        marker = target.host if target.port == 22 else f"[{target.host}]:{target.port}"
        if any(line.split(" ", 1)[0] == marker for line in pinned.splitlines()):
            return marker
        scan = subprocess.run(
            ["ssh-keyscan", "-T", "10", "-p", str(target.port), "-t", "ed25519,ecdsa,rsa", target.host],
            capture_output=True, text=True, timeout=self.timeout, check=False,
        )
        lines = [l for l in scan.stdout.splitlines() if l and not l.startswith("#")]
        if not lines:
            raise SshError(f"Could not read a host key from {target.host}:{target.port}")
        self.dir.mkdir(parents=True, exist_ok=True, mode=0o700)
        atomic_write(self.known_hosts(), pinned + "\n".join(lines) + "\n", 0o600)
        return marker

    @staticmethod
    def _as_root(target: Target, command: str) -> str:
        """Nodes are managed as root. A non-root SSH user needs passwordless sudo; Fleet never asks for a password."""
        return command if target.user == "root" else f"sudo -n sh -c {shlex.quote(command)}"

    def run(self, target: Target, command: str, *, stdin: bytes | None = None, check: bool = False, timeout: int | None = None) -> Result:
        try:
            done = subprocess.run(
                # A command that is given no input gets an empty stdin, never the operator's terminal: a host command that reads stdin must see the end of it.
                self._base(target) + [self._as_root(target, command)], input=stdin, stdin=None if stdin is not None else subprocess.DEVNULL, capture_output=True, timeout=timeout or self.timeout, check=False,
                start_new_session=True,  # Ctrl+C in the terminal stops Fleet between steps, never a command half way on a host
            )
        except subprocess.TimeoutExpired as exc:
            raise SshError(f"SSH to {target.name} timed out") from exc
        stderr = done.stderr.decode(errors="replace")
        if done.returncode == 255 and ("REMOTE HOST IDENTIFICATION HAS CHANGED" in stderr or "Host key verification failed" in stderr):
            raise HostKeyChanged(f"The host key of {target.name} changed or is not pinned; refusing to connect")
        if done.returncode == 255:
            # ssh itself failed (dropped, refused, timed out): the command's outcome is unknown, so this is never a verdict on the node.
            raise SshError(f"SSH to {target.name} failed or was interrupted: {stderr.strip()[-200:] or 'connection lost'}")
        result = Result(done.returncode, done.stdout.decode(errors="replace"), stderr)
        if check and not result.ok:
            raise SshError(f"{target.name}: `{command}` failed ({result.code}): {(result.stderr or result.stdout).strip()[:500]}")
        return result

    def run_stream(self, target: Target, command: str, on_line: Callable[[str], None], *, timeout: int = 1800,
                   on_quiet: Callable[[int], None] | None = None, heartbeat: int = STREAM_HEARTBEAT_SECONDS) -> Result:
        """Run a long command and hand every output line to `on_line` as it appears, so an operator sees what the host is doing.

        Standard error is merged into the output. `on_quiet(seconds)` is called every `heartbeat` seconds without output.
        """
        process = subprocess.Popen(self._base(target) + [self._as_root(target, command)], stdout=subprocess.PIPE, stderr=subprocess.STDOUT, stdin=subprocess.DEVNULL,
                                   text=True, errors="replace", bufsize=1, start_new_session=True)
        lines: queue.Queue[str | None] = queue.Queue()

        def read() -> None:
            assert process.stdout is not None
            for raw in process.stdout:
                lines.put(raw.rstrip("\r\n"))
            lines.put(None)

        threading.Thread(target=read, daemon=True).start()
        started, collected = time.monotonic(), []
        while True:
            try:
                item = lines.get(timeout=heartbeat)
            except queue.Empty:
                if time.monotonic() - started > timeout:
                    process.kill()
                    raise SshError(f"{target.name}: the command did not finish within {timeout} seconds")
                if on_quiet:
                    on_quiet(round(time.monotonic() - started))
                continue
            if item is None:
                break
            collected.append(item)
            on_line(item)
        code = process.wait()
        output = "\n".join(collected)
        if code == 255 and ("REMOTE HOST IDENTIFICATION HAS CHANGED" in output or "Host key verification failed" in output):
            raise HostKeyChanged(f"The host key of {target.name} changed or is not pinned; refusing to connect")
        if code == 255:
            raise SshError(f"SSH to {target.name} failed or was interrupted: {output.strip()[-200:] or 'connection lost'}")
        return Result(code, output, "")

    def run_raw(self, target: Target, command: str, *, stdin: bytes | None = None, timeout: int = 1800) -> "subprocess.CompletedProcess[bytes]":
        """Run with the pinned key and return the raw process, for callers that stream bytes."""
        return subprocess.run(self._base(target) + [self._as_root(target, command)], input=stdin, stdin=None if stdin is not None else subprocess.DEVNULL, capture_output=True, timeout=timeout, check=False)

    def upload_tree(self, target: Target, local: Path, remote: str, *, keep_previous: bool = True) -> None:
        """Replace `remote` with a copy of `local`. Interrupting the stream leaves `remote` untouched."""
        if not remote.startswith("/") or remote.rstrip("/") in {"", "/"}:
            raise ValidationError("The remote directory must be an absolute path below /")
        quoted = shlex.quote(remote.rstrip("/"))
        staging = quoted + ".incoming"
        with tempfile.TemporaryFile() as archive:
            with tarfile.open(fileobj=archive, mode="w") as tar:
                tar.add(local, arcname=".")
            archive.seek(0)
            script = (
                f"set -e; rm -rf {staging}; mkdir -p {staging}; tar -C {staging} -xf -; "
                + (f"rm -rf {quoted}.previous; if [ -d {quoted} ]; then mv {quoted} {quoted}.previous; fi; " if keep_previous else f"rm -rf {quoted}; ")
                + f"mv {staging} {quoted}"
            )
            self.run(target, script, stdin=archive.read(), check=True)


TARGETS_FILE = "targets.json"


def load_targets(state_dir: Path, node_names: set[str] | None = None) -> dict[str, Target]:
    import json

    path = state_dir / "ssh" / TARGETS_FILE
    data = json.loads(path.read_text(encoding="utf-8")) if path.exists() else {}
    return {n: Target(n, v["host"], int(v["port"]), v["user"]) for n, v in data.items() if node_names is None or n in node_names}


def save_target(state_dir: Path, target: Target) -> None:
    import json

    path = state_dir / "ssh" / TARGETS_FILE
    data = json.loads(path.read_text(encoding="utf-8")) if path.exists() else {}
    data[target.name] = {"host": target.host, "port": target.port, "user": target.user}
    path.parent.mkdir(parents=True, exist_ok=True, mode=0o700)
    atomic_write(path, json.dumps(data, indent=1, sort_keys=True), 0o600)
