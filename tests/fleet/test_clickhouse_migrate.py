"""The ClickHouse migration script must finish even when its standard input is an open pipe that never closes."""
from __future__ import annotations

import shutil
import subprocess
from pathlib import Path

import pytest

ROOT = Path(__file__).resolve().parents[2]
pytestmark = pytest.mark.skipif(shutil.which("sh") is None, reason="a POSIX shell is required")

FAKE_CLIENT = """#!/bin/sh
# Behaves like clickhouse-client: an INSERT also reads rows from standard input, and waits for it to end.
for arg in "$@"; do
    case "$arg" in
        INSERT*) cat > /dev/null; echo "inserted" >> "$MIGRATION_LOG" ;;
        *"SELECT checksum"*) exit 0 ;;
    esac
done
case "$*" in *--multiquery*) cat > /dev/null; echo "applied" >> "$MIGRATION_LOG" ;; esac
exit 0
"""


def test_recording_a_migration_does_not_wait_on_standard_input(tmp_path: Path) -> None:
    (tmp_path / "bin").mkdir()
    client = tmp_path / "bin" / "clickhouse-client"
    client.write_text(FAKE_CLIENT)
    client.chmod(0o755)
    migrations = tmp_path / "migrations"
    migrations.mkdir()
    (migrations / "2026_01_01_example.sql").write_text("SELECT 1;\n")
    log = tmp_path / "log"
    process = subprocess.Popen(
        ["sh", str(ROOT / "docker/clickhouse/migrate.sh")], stdin=subprocess.PIPE, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True,  # stdin stays open, like a terminal
        env={"PATH": f"{tmp_path / 'bin'}:/usr/bin:/bin", "CLICKHOUSE_PASSWORD": "x", "MIGRATIONS_DIRECTORY": str(migrations), "MIGRATION_LOG": str(log)},
    )
    try:
        process.wait(timeout=20)
    except subprocess.TimeoutExpired:
        process.kill()
        pytest.fail("migrate.sh hung: clickhouse-client waited for standard input")
    out, err = process.stdout.read(), process.stderr.read()
    assert process.returncode == 0, err
    assert "applied 2026_01_01_example.sql" in out and "clickhouse_migrations_applied=1" in out
    assert log.read_text().split() == ["applied", "inserted"]
