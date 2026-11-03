from __future__ import annotations

import sqlite3
import sys
from pathlib import Path

import pytest

sys.path.insert(0, str(Path(__file__).resolve().parents[2] / "scripts"))

from cdnfoundry_fleet.vault import Vault, VaultError  # noqa: E402

PASS = "correct horse battery"


def make(tmp_path: Path, passphrase: str = PASS) -> Vault:
    vault = Vault(tmp_path / "fleet.db", passphrase)
    vault.create()
    return vault


def test_secrets_are_not_readable_in_the_sqlite_file(tmp_path: Path) -> None:
    vault = make(tmp_path)
    with vault.session() as work:
        (work / "secrets" / "global").mkdir(parents=True)
        (work / "secrets" / "global" / "app-key").write_text("SUPERSECRETVALUE\n")
    raw = (tmp_path / "fleet.db").read_bytes()
    assert b"SUPERSECRETVALUE" not in raw
    assert oct((tmp_path / "fleet.db").stat().st_mode & 0o777) == "0o600"


def test_session_round_trips_modes_and_wipes_the_work_directory(tmp_path: Path) -> None:
    vault = make(tmp_path)
    with vault.session() as work:
        key = work / "pki" / "ca.key"
        key.parent.mkdir()
        key.write_text("k")
        key.chmod(0o600)
        first = work
    assert not first.exists()
    with Vault(tmp_path / "fleet.db", PASS).session() as work:
        assert (work / "pki" / "ca.key").read_text() == "k"
        assert (work / "pki" / "ca.key").stat().st_mode & 0o777 == 0o600


def test_wrong_passphrase_is_refused(tmp_path: Path) -> None:
    make(tmp_path)
    with pytest.raises(VaultError, match="Wrong passphrase"):
        Vault(tmp_path / "fleet.db", "another passphrase!").unlock()


def test_short_passphrase_is_refused(tmp_path: Path) -> None:
    with pytest.raises(VaultError, match="at least"):
        Vault(tmp_path / "fleet.db", "short").create()


def test_failed_session_does_not_seal_partial_changes(tmp_path: Path) -> None:
    vault = make(tmp_path)
    with pytest.raises(RuntimeError):
        with vault.session() as work:
            (work / "fleet.json").write_text("{}")
            raise RuntimeError("boom")
    assert vault.paths() == []


def test_swapped_rows_fail_authentication(tmp_path: Path) -> None:
    vault = make(tmp_path)
    with vault.session() as work:
        (work / "secrets").mkdir()
        (work / "secrets" / "a").write_text("1")
        (work / "secrets" / "b").write_text("2")
    conn = sqlite3.connect(tmp_path / "fleet.db")
    a = conn.execute("SELECT nonce,data FROM files WHERE path='secrets/a'").fetchone()
    conn.execute("UPDATE files SET nonce=?, data=? WHERE path='secrets/b'", a)
    conn.commit()
    conn.close()
    with pytest.raises(VaultError, match="authentication"):
        with vault.session():
            pass


def test_change_passphrase_keeps_content(tmp_path: Path) -> None:
    vault = make(tmp_path)
    with vault.session() as work:
        (work / "fleet.json").write_text("{}")
    vault.change_passphrase("a brand new passphrase")
    with pytest.raises(VaultError):
        Vault(tmp_path / "fleet.db", PASS).unlock()
    with Vault(tmp_path / "fleet.db", "a brand new passphrase").session() as work:
        assert (work / "fleet.json").read_text() == "{}"


def test_history_is_recorded(tmp_path: Path) -> None:
    vault = make(tmp_path)
    vault.record("upgrade", "control-1 ok")
    assert vault.history()[0]["kind"] == "upgrade"


def test_cli_keeps_state_only_in_the_encrypted_vault(tmp_path: Path, monkeypatch: pytest.MonkeyPatch, capsys: pytest.CaptureFixture[str]) -> None:
    from cdnfoundry_fleet.cli import main

    vault = tmp_path / "fleet.db"
    monkeypatch.setenv("CDNF_FLEET_PASSPHRASE", PASS)
    common = ["--vault", str(vault), "--non-interactive"]
    assert main(["init", *common, "--operator-domain", "ops.example.test", "--platform-domain", "cdn.example.test", "--release", "v2.0.0"]) == 0
    assert vault.is_file()
    assert not (tmp_path / "fleet.json").exists()
    assert b"ops.example.test" not in vault.read_bytes()
    assert main(["status", "--json", *common]) == 0
    assert "ops.example.test" in capsys.readouterr().out
    monkeypatch.setenv("CDNF_FLEET_PASSPHRASE", "not the passphrase")
    assert main(["status", *common]) == 4
