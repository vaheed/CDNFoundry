"""Back up the encrypted vault to the off-host restic repository, and restore it on a new workstation.

The vault file is already encrypted, so restic stores ciphertext inside ciphertext. Restoring needs three things the
operator keeps outside the fleet: the repository URL, the restic password, and the vault passphrase.
"""
from __future__ import annotations

import os
import shutil
import sqlite3
import subprocess
import tempfile
from pathlib import Path

from .common import FleetError, ValidationError

TAG = "fleet-vault"


def _restic(args: list[str], env: dict[str, str]) -> subprocess.CompletedProcess[str]:
    if shutil.which("restic") is None:
        raise FleetError("restic is not installed on this workstation")
    return subprocess.run(["restic", *args], env={**os.environ, **env}, capture_output=True, text=True, check=False)


def restic_env(repository: str, password: str, access_key: str | None = None, secret_key: str | None = None, region: str | None = None) -> dict[str, str]:
    env = {"RESTIC_REPOSITORY": repository, "RESTIC_PASSWORD": password}
    if access_key and secret_key:
        env |= {"AWS_ACCESS_KEY_ID": access_key, "AWS_SECRET_ACCESS_KEY": secret_key, "AWS_DEFAULT_REGION": region or "us-east-1"}
    return env


def backup_vault(vault_path: Path, env: dict[str, str]) -> str:
    if _restic(["cat", "config"], env).returncode != 0:
        init = _restic(["init"], env)
        if init.returncode != 0:
            raise FleetError(f"restic init failed: {init.stderr.strip()[-300:]}")
    with tempfile.TemporaryDirectory(prefix="cdnf-vault-bk-") as tmp:
        copy = Path(tmp) / "fleet.db"
        source, target = sqlite3.connect(vault_path), sqlite3.connect(copy)
        with target:
            source.backup(target)
        source.close()
        target.close()
        copy.chmod(0o600)
        done = _restic(["backup", "--tag", TAG, "--host", "fleet-workstation", "--json", str(copy)], env)
    if done.returncode != 0:
        raise FleetError(f"restic backup failed: {done.stderr.strip()[-300:]}")
    check = _restic(["check"], env)
    if check.returncode != 0:
        raise FleetError(f"restic check failed: {check.stderr.strip()[-300:]}")
    import json

    summary = [json.loads(l) for l in done.stdout.splitlines() if l.startswith("{")]
    return next((x["snapshot_id"] for x in summary if x.get("message_type") == "summary"), "unknown")


def restore_vault(target: Path, env: dict[str, str], snapshot: str = "latest") -> None:
    if target.exists():
        raise ValidationError(f"{target} already exists; restore into a new location and compare first")
    with tempfile.TemporaryDirectory(prefix="cdnf-vault-rs-") as tmp:
        done = _restic(["restore", snapshot, "--tag", TAG, "--target", tmp], env)
        if done.returncode != 0:
            raise FleetError(f"restic restore failed: {done.stderr.strip()[-300:]}")
        found = [p for p in Path(tmp).rglob("fleet.db")]
        if not found:
            raise FleetError("The snapshot holds no fleet vault")
        target.parent.mkdir(parents=True, exist_ok=True, mode=0o700)
        shutil.copy2(found[0], target)
        target.chmod(0o600)
