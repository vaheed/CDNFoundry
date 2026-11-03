"""Runtime secret material that an edge host keeps only in memory (tmpfs).

Fleet owns the PKI and the secrets, so Fleet issues this material and writes it to the host; nobody carries keys around.
Files live in /dev/shm/cdnfoundry, owned by UID 10101, mode 0400 for keys. They vanish at reboot, so every deploy writes
them again (the content is stable: it comes from the vault). Host swap is turned off, as the bundle requires.
"""
from __future__ import annotations

import io
import subprocess
import tarfile
from pathlib import Path
from typing import Any

from .certs import PKI
from .common import FleetError, atomic_write
from .ssh import Target

TMPFS_DIR = "/dev/shm/cdnfoundry"
EDGE_ROLES = {"edge", "dns-edge"}
RUNTIME_UID = 10101


def needs_material(role: str) -> bool:
    return role in EDGE_ROLES


def issue(work: Path, repo_root: Path, node: dict[str, Any]) -> dict[str, bytes]:
    """Create (or reuse) the node's tmpfs files inside the vault work directory and return name -> bytes."""
    pki = PKI(work / "pki")
    _ca, _cert, node_key = pki.ensure_node_certificate(node)
    out = work / "secrets" / "nodes" / node["name"] / "listener"
    script = repo_root / "scripts" / "issue-edge-listener-material.sh"
    done = subprocess.run(
        ["bash", str(script), "--state-dir", str(work), "--hostname", node["hostname"], "--public-ipv4", node["public_ipv4"], "--output", str(out)],
        capture_output=True, text=True, check=False,
    )
    if done.returncode != 0:
        raise FleetError(f"Could not issue listener material for {node['name']}: {(done.stderr or done.stdout).strip()[-300:]}")
    files = {name: (out / name).read_bytes() for name in ("edge-runtime.crt", "edge-runtime.key", "edge-state-encryption.key")}
    if node["role"] == "dns-edge":
        files["node.key"] = node_key.read_bytes()
    return files


def archive(files: dict[str, bytes]) -> bytes:
    buffer = io.BytesIO()
    with tarfile.open(fileobj=buffer, mode="w") as tar:
        for name, data in sorted(files.items()):
            info = tarfile.TarInfo(name)
            info.size = len(data)
            info.mode = 0o400 if name.endswith(".key") else 0o444
            tar.addfile(info, io.BytesIO(data))
    return buffer.getvalue()


def install_command() -> str:
    """Idempotent: disable swap for good, then replace the files in tmpfs with the same bytes."""
    return (
        "set -e; "
        "swapoff -a; if grep -qE '^[^#].*[[:space:]]swap[[:space:]]' /etc/fstab; then cp -n /etc/fstab /etc/fstab.cdnfoundry-original; "
        "sed -i -E '/^[^#].*[[:space:]]swap[[:space:]]/ s/^/#cdnfoundry-disabled /' /etc/fstab; fi; "
        f"install -d -m 0700 -o {RUNTIME_UID} -g {RUNTIME_UID} {TMPFS_DIR}; "
        f"rm -f {TMPFS_DIR}/edge-runtime.crt {TMPFS_DIR}/edge-runtime.key {TMPFS_DIR}/edge-state-encryption.key {TMPFS_DIR}/node.key; "
        f"tar -xf - -C {TMPFS_DIR} --no-same-owner; chown {RUNTIME_UID}:{RUNTIME_UID} {TMPFS_DIR}/*; "
        f"chmod 0400 {TMPFS_DIR}/*.key; chmod 0444 {TMPFS_DIR}/*.crt"
    )


def install(transport: Any, target: Target, files: dict[str, bytes]) -> tuple[bool, str]:
    result = transport.run(target, install_command(), stdin=archive(files))
    if not result.ok:
        return False, (result.stderr or result.stdout).strip()[-300:] or f"installing the tmpfs material exited with {result.code}"
    return True, ""


def present_command(role: str) -> str:
    names = ["edge-runtime.crt", "edge-runtime.key", "edge-state-encryption.key"] + (["node.key"] if role == "dns-edge" else [])
    tests = " && ".join(f"test -f {TMPFS_DIR}/{n}" for n in names)
    return f"{tests} && test \"$(wc -l < /proc/swaps)\" -le 1"
