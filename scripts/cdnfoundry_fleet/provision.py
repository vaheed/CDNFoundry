"""Runtime secret material that an edge host keeps only in memory (tmpfs).

Fleet owns the PKI and the secrets, so Fleet issues this material and writes it to the host; nobody carries keys around.
Files live in /dev/shm/cdnfoundry, owned by UID 10101, mode 0400 for keys. A host-encrypted systemd credential
restores them before Docker starts after reboot. The vault remains the recovery source. Host swap is disabled.
"""
from __future__ import annotations

import io
import shlex
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


def restore_script() -> str:
    """Restore only Fleet's known paths; never recursively remove an unexpected directory."""
    return f'''#!/bin/sh
set -eu
umask 077
runtime={TMPFS_DIR}
install -d -m 0700 "$runtime"
test "$(stat -f -c %T "$runtime")" = tmpfs
for name in edge-runtime.crt edge-runtime.key edge-state-encryption.key node.key; do
    path="$runtime/$name"
    if [ -d "$path" ]; then rmdir -- "$path"; fi
    test ! -L "$path"
done
tar -xf "$CREDENTIALS_DIRECTORY/edge-material" -C "$runtime" --no-same-owner
chown -R {RUNTIME_UID}:{RUNTIME_UID} "$runtime"
chmod 0400 "$runtime"/*.key
chmod 0444 "$runtime"/*.crt
'''


def install_command() -> str:
    """Stream plaintext directly into encryption; systemd decrypts only into memory."""
    credential = "/etc/credstore.encrypted/cdnfoundry-edge-material"
    helper = "/usr/local/libexec/cdnfoundry-restore-edge-material"
    unit = f'''[Unit]
Description=Restore CDNFoundry edge material into memory
Before=docker.service
RequiresMountsFor=/dev/shm

[Service]
Type=oneshot
UMask=0077
LoadCredentialEncrypted=edge-material:{credential}
ExecStart={helper}
TimeoutStartSec=30
LimitCORE=0
'''
    dependency = "[Unit]\nRequires=cdnfoundry-edge-material.service\nAfter=cdnfoundry-edge-material.service\n"
    return (
        "set -eu; umask 077; command -v systemd-creds >/dev/null; test -d /run/systemd/system; "
        "swapoff -a; if grep -qE '^[^#].*[[:space:]]swap[[:space:]]' /etc/fstab; then cp -n /etc/fstab /etc/fstab.cdnfoundry-original; "
        "sed -i -E '/^[^#].*[[:space:]]swap[[:space:]]/ s/^/#cdnfoundry-disabled /' /etc/fstab; fi; "
        "install -d -m 0700 /etc/credstore.encrypted; install -d -m 0755 /usr/local/libexec /etc/systemd/system/docker.service.d; "
        f"systemd-creds encrypt --with-key=host --name=edge-material - {credential}.pending; "
        f"chmod 0600 {credential}.pending; mv -f {credential}.pending {credential}; "
        f"printf %s {shlex.quote(restore_script())} > {helper}; chmod 0700 {helper}; "
        f"printf %s {shlex.quote(unit)} > /etc/systemd/system/cdnfoundry-edge-material.service; "
        f"printf %s {shlex.quote(dependency)} > /etc/systemd/system/docker.service.d/cdnfoundry-edge-material.conf; "
        "systemctl daemon-reload; systemctl start cdnfoundry-edge-material.service"
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


def recovery_command() -> str:
    """Start only existing long-running containers in the installed Compose release."""
    script = '''import json, subprocess
def run(*args):
    return subprocess.run(args, check=True, capture_output=True, text=True, timeout=120).stdout
ids = run('docker', 'compose', '--env-file', '.env.prod', '--profile', '*',
          'ps', '--all', '--quiet', '--orphans=false').split()
if not ids or len(ids) > 256:
    raise SystemExit('Expected 1..256 installed release containers')
for item in json.loads(run('docker', 'inspect', *ids)):
    if item['HostConfig']['RestartPolicy']['Name'] not in ('', 'no') and not item['State']['Running']:
        run('docker', 'start', item['Id'])
'''
    return ("set -e; if command -v systemctl >/dev/null && test -d /run/systemd/system; "
            "then systemctl enable --now docker; else rc-update add docker default; rc-service docker start; fi; "
            "cd /opt/cdnfoundry; python3 -c " + shlex.quote(script))
