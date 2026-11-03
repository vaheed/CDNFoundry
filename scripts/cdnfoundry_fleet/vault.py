"""Encrypted fleet state in one local SQLite file.

The vault is the only copy of the fleet state at rest. Each command unlocks it
into a private working directory, runs the engine on those files, seals the
changes back, and removes the directory. Every file is encrypted with
AES-256-GCM under a key derived from the operator passphrase with scrypt; the
file path is authenticated data, so rows cannot be swapped between paths.
"""
from __future__ import annotations

import hashlib
import os
import shutil
import sqlite3
import stat
import tempfile
from contextlib import contextmanager
from pathlib import Path
from typing import Iterator

from cryptography.exceptions import InvalidTag
from cryptography.hazmat.primitives.ciphers.aead import AESGCM

from .common import StateError, utc_now

VAULT_SCHEMA = 1
SCRYPT_N, SCRYPT_R, SCRYPT_P = 2**15, 8, 1
VERIFIER_PATH = "__verifier__"
VERIFIER_TEXT = b"cdnfoundry-fleet-vault"
# Directories sealed into the vault. Rendered bundles and history are derived and stay out.
SEALED_ROOTS = ("fleet.json", "secrets", "pki", "ha", "ssh")
MIN_PASSPHRASE = 12


class VaultError(StateError):
    pass


def _derive(passphrase: str, salt: bytes) -> bytes:
    return hashlib.scrypt(passphrase.encode(), salt=salt, n=SCRYPT_N, r=SCRYPT_R, p=SCRYPT_P, maxmem=128 * 1024 * 1024, dklen=32)


class Vault:
    def __init__(self, path: Path, passphrase: str) -> None:
        self.path = path.resolve()
        self._passphrase = passphrase
        self._key: bytes | None = None

    def exists(self) -> bool:
        return self.path.is_file()

    def _connect(self) -> sqlite3.Connection:
        conn = sqlite3.connect(self.path, timeout=10)
        conn.execute("PRAGMA journal_mode=DELETE")
        conn.execute("PRAGMA synchronous=FULL")
        return conn

    def create(self) -> None:
        if self.exists():
            raise VaultError(f"Fleet vault already exists at {self.path}")
        if len(self._passphrase) < MIN_PASSPHRASE:
            raise VaultError(f"The passphrase must be at least {MIN_PASSPHRASE} characters")
        self.path.parent.mkdir(parents=True, exist_ok=True, mode=0o700)
        salt = os.urandom(16)
        self._key = _derive(self._passphrase, salt)
        fd = os.open(self.path, os.O_CREAT | os.O_EXCL | os.O_WRONLY, 0o600)
        os.close(fd)
        with self._connect() as conn:
            conn.execute("CREATE TABLE meta (key TEXT PRIMARY KEY, value BLOB NOT NULL)")
            conn.execute("CREATE TABLE files (path TEXT PRIMARY KEY, nonce BLOB NOT NULL, data BLOB NOT NULL, mode INTEGER NOT NULL)")
            conn.execute("CREATE TABLE history (id INTEGER PRIMARY KEY AUTOINCREMENT, at TEXT NOT NULL, kind TEXT NOT NULL, detail TEXT NOT NULL)")
            conn.execute("INSERT INTO meta VALUES ('schema', ?)", (str(VAULT_SCHEMA).encode(),))
            conn.execute("INSERT INTO meta VALUES ('salt', ?)", (salt,))
            self._put(conn, VERIFIER_PATH, VERIFIER_TEXT, 0o600)

    def unlock(self) -> None:
        if not self.exists():
            raise VaultError(f"No fleet vault at {self.path}; run setup first")
        with self._connect() as conn:
            row = conn.execute("SELECT value FROM meta WHERE key='salt'").fetchone()
            if row is None:
                raise VaultError("The fleet vault is damaged: no salt")
            self._key = _derive(self._passphrase, bytes(row[0]))
            if self._get(conn, VERIFIER_PATH)[0] != VERIFIER_TEXT:
                raise VaultError("Wrong passphrase")

    def _put(self, conn: sqlite3.Connection, path: str, data: bytes, mode: int) -> None:
        assert self._key is not None
        nonce = os.urandom(12)
        blob = AESGCM(self._key).encrypt(nonce, data, path.encode())
        conn.execute("INSERT OR REPLACE INTO files VALUES (?,?,?,?)", (path, nonce, blob, mode))

    def _get(self, conn: sqlite3.Connection, path: str) -> tuple[bytes, int]:
        assert self._key is not None
        row = conn.execute("SELECT nonce, data, mode FROM files WHERE path=?", (path,)).fetchone()
        if row is None:
            raise VaultError(f"Missing vault entry: {path}")
        try:
            return AESGCM(self._key).decrypt(bytes(row[0]), bytes(row[1]), path.encode()), int(row[2])
        except InvalidTag as exc:
            raise VaultError("Wrong passphrase" if path == VERIFIER_PATH else f"Vault entry failed authentication: {path}") from exc

    def paths(self) -> list[str]:
        with self._connect() as conn:
            return sorted(r[0] for r in conn.execute("SELECT path FROM files") if r[0] != VERIFIER_PATH)

    def materialize(self, directory: Path) -> None:
        directory.mkdir(parents=True, exist_ok=True, mode=0o700)
        with self._connect() as conn:
            for (path,) in conn.execute("SELECT path FROM files").fetchall():
                if path == VERIFIER_PATH:
                    continue
                data, mode = self._get(conn, path)
                target = (directory / path).resolve()
                if directory.resolve() not in target.parents:
                    raise VaultError(f"Unsafe vault path: {path}")
                target.parent.mkdir(parents=True, exist_ok=True, mode=0o700)
                target.write_bytes(data)
                target.chmod(mode)

    def seal(self, directory: Path) -> None:
        entries: dict[str, tuple[bytes, int]] = {}
        for root in SEALED_ROOTS:
            base = directory / root
            files = [base] if base.is_file() else sorted(p for p in base.rglob("*") if p.is_file()) if base.is_dir() else []
            for file in files:
                if file.is_symlink():
                    raise VaultError(f"Refusing to seal a symlink: {file}")
                entries[file.relative_to(directory).as_posix()] = (file.read_bytes(), stat.S_IMODE(file.stat().st_mode))
        with self._connect() as conn:
            conn.execute("BEGIN IMMEDIATE")
            current = {r[0] for r in conn.execute("SELECT path FROM files")} - {VERIFIER_PATH}
            for gone in current - set(entries):
                conn.execute("DELETE FROM files WHERE path=?", (gone,))
            for path, (data, mode) in entries.items():
                self._put(conn, path, data, mode)
            conn.commit()

    def record(self, kind: str, detail: str) -> None:
        with self._connect() as conn:
            conn.execute("INSERT INTO history (at, kind, detail) VALUES (?,?,?)", (utc_now(), kind, detail))

    def history(self, limit: int = 100) -> list[dict[str, str]]:
        with self._connect() as conn:
            rows = conn.execute("SELECT id, at, kind, detail FROM history ORDER BY id DESC LIMIT ?", (limit,)).fetchall()
        return [{"id": str(r[0]), "at": r[1], "kind": r[2], "detail": r[3]} for r in rows]

    def change_passphrase(self, new_passphrase: str) -> None:
        if len(new_passphrase) < MIN_PASSPHRASE:
            raise VaultError(f"The passphrase must be at least {MIN_PASSPHRASE} characters")
        with tempfile.TemporaryDirectory(prefix="cdnf-vault-") as tmp:
            work = Path(tmp)
            self.materialize(work)
            salt = os.urandom(16)
            self._key = _derive(new_passphrase, salt)
            with self._connect() as conn:
                conn.execute("BEGIN IMMEDIATE")
                conn.execute("DELETE FROM files")
                conn.execute("UPDATE meta SET value=? WHERE key='salt'", (salt,))
                self._put(conn, VERIFIER_PATH, VERIFIER_TEXT, 0o600)
                conn.commit()
            self._passphrase = new_passphrase
            self.seal(work)

    @contextmanager
    def session(self) -> Iterator[Path]:
        """Unlock into a private work directory, seal on success, always wipe."""
        self.unlock()
        work = Path(tempfile.mkdtemp(prefix="cdnf-fleet-"))
        work.chmod(0o700)
        try:
            self.materialize(work)
            yield work
            self.seal(work)
        finally:
            shutil.rmtree(work, ignore_errors=True)
