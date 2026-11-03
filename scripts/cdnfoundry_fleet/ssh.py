"""SSH transport for Fleet actions.

Key authentication only. A host key is pinned on first use into the vault and a
changed key is refused. Uploads stream a tar archive over the same connection,
so a node needs nothing but sshd, tar, and a POSIX shell.
"""
from __future__ import annotations

import os
import shlex
import subprocess
import tarfile
import tempfile
from dataclasses import dataclass
from pathlib import Path

from .common import FleetError, ValidationError, atomic_write

SSH_TIMEOUT = 120


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

    def key_path(self, target: Target) -> Path:
        return self.dir / "keys" / target.name

    def known_hosts(self) -> Path:
        return self.dir / "known_hosts"

    def store_key(self, target: Target, private_key: str) -> None:
        if "PRIVATE KEY" not in private_key:
            raise ValidationError("The SSH key is not a private key")
        (self.dir / "keys").mkdir(parents=True, exist_ok=True, mode=0o700)
        atomic_write(self.key_path(target), private_key.rstrip("\n") + "\n", 0o600)

    def _base(self, target: Target, *, strict: str = "yes") -> list[str]:
        key = self.key_path(target)
        if not key.is_file():
            raise SshError(f"No SSH key stored for {target.name}")
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

    def run(self, target: Target, command: str, *, stdin: bytes | None = None, check: bool = False) -> Result:
        try:
            done = subprocess.run(
                self._base(target) + [command], input=stdin, capture_output=True, timeout=self.timeout, check=False,
            )
        except subprocess.TimeoutExpired as exc:
            raise SshError(f"SSH to {target.name} timed out") from exc
        stderr = done.stderr.decode(errors="replace")
        if done.returncode == 255 and ("REMOTE HOST IDENTIFICATION HAS CHANGED" in stderr or "Host key verification failed" in stderr):
            raise HostKeyChanged(f"The host key of {target.name} changed or is not pinned; refusing to connect")
        result = Result(done.returncode, done.stdout.decode(errors="replace"), stderr)
        if check and not result.ok:
            raise SshError(f"{target.name}: `{command}` failed ({result.code}): {(result.stderr or result.stdout).strip()[:500]}")
        return result

    def run_raw(self, target: Target, command: str, *, stdin: bytes | None = None, timeout: int = 1800) -> "subprocess.CompletedProcess[bytes]":
        """Run with the pinned key and return the raw process, for callers that stream bytes."""
        return subprocess.run(self._base(target) + [command], input=stdin, capture_output=True, timeout=timeout, check=False)

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
