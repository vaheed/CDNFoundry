from __future__ import annotations

import shutil
import sys
from pathlib import Path

import pytest

sys.path.insert(0, str(Path(__file__).resolve().parents[2] / "scripts"))

from cdnfoundry_fleet.cli import main  # noqa: E402
from cdnfoundry_fleet.common import ValidationError  # noqa: E402
from cdnfoundry_fleet.vault import Vault  # noqa: E402
from cdnfoundry_fleet.vault_backup import backup_vault, restic_env, restore_vault  # noqa: E402

pytestmark = pytest.mark.skipif(shutil.which("restic") is None, reason="restic is required")
PASS = "correct horse battery"


def test_a_new_workstation_restores_the_vault_in_one_step_and_can_read_it(tmp_path: Path, monkeypatch: pytest.MonkeyPatch, capsys) -> None:
    monkeypatch.setenv("CDNF_FLEET_PASSPHRASE", PASS)
    vault = tmp_path / "old" / "fleet.db"
    assert main(["init", "--vault", str(vault), "--non-interactive", "--operator-domain", "ops.example.test",
                 "--platform-domain", "cdn.example.test", "--release", "v2.0.0"]) == 0
    repo = tmp_path / "repo"
    env = restic_env(str(repo), "restic-password-1")
    snapshot = backup_vault(vault, env)
    assert snapshot != "unknown"
    assert b"ops.example.test" not in vault.read_bytes()

    password_file = tmp_path / "restic.pw"
    password_file.write_text("restic-password-1\n")
    new = tmp_path / "new" / "fleet.db"
    assert main(["vault-restore", "--vault", str(new), "--repository", str(repo), "--password-file", str(password_file)]) == 0
    capsys.readouterr()
    assert main(["status", "--json", "--vault", str(new), "--non-interactive"]) == 0
    assert "ops.example.test" in capsys.readouterr().out


def test_restore_never_overwrites_an_existing_vault(tmp_path: Path) -> None:
    existing = tmp_path / "fleet.db"
    existing.write_text("x")
    with pytest.raises(ValidationError, match="already exists"):
        restore_vault(existing, restic_env(str(tmp_path / "repo"), "pw"))


def test_wrong_restic_password_fails_loudly(tmp_path: Path) -> None:
    from cdnfoundry_fleet.common import FleetError

    vault = tmp_path / "fleet.db"
    Vault(vault, PASS).create()
    repo = tmp_path / "repo"
    backup_vault(vault, restic_env(str(repo), "right"))
    with pytest.raises(FleetError):
        restore_vault(tmp_path / "other.db", restic_env(str(repo), "wrong"))
