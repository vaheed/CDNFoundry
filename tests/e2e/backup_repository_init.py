#!/usr/bin/env python3
"""The control backup must create its restic repository on first use and must never touch an existing one it cannot open.

Runs core/docker/backup/create.sh in the control image against a throwaway local restic repository, with a stub `pg_dump`.
"""

import os
import pathlib
import secrets
import subprocess
import tempfile

ROOT = pathlib.Path(__file__).resolve().parents[2]
KEYS = {"repository": secrets.token_hex(16).encode(), "other": secrets.token_hex(16).encode()}
IMAGE = os.environ.get("CDNF_CORE_IMAGE", "cdnfoundry-dev-core:latest")


def run(script: str, directory: pathlib.Path, key: str = "repository", repository: str = "/work/repo/main") -> subprocess.CompletedProcess[str]:
    # Each throwaway repository key is generated for the run; nothing is a fixed credential.
    (directory / "password").write_bytes(KEYS[key] + b"\n")
    return subprocess.run(
        ["docker", "run", "--rm", "--entrypoint", "sh", "-v", f"{directory}:/work", "-v", f"{ROOT / 'core/docker/backup/create.sh'}:/create.sh:ro",
         "-e", "PATH=/work/bin:/usr/bin:/bin", "-e", f"RESTIC_REPOSITORY={repository}", "-e", "RESTIC_PASSWORD_FILE=/work/password",
         "-e", "HOME=/tmp", IMAGE, "-c", f"mkdir -p /work/repo; {script}"],
        capture_output=True, text=True, check=False)


def main() -> None:
    with tempfile.TemporaryDirectory(prefix="cdnf-e2e-backup-") as name:
        directory = pathlib.Path(name)
        directory.chmod(0o777)
        (directory / "bin").mkdir()
        (directory / "bin/pg_dump").write_text('#!/bin/sh\nprintf "stub-database-dump"\n')
        (directory / "bin/pg_dump").chmod(0o755)

        # A repository that does not exist is created, and the first backup succeeds and reports its snapshot.
        first = run("sh /create.sh", directory)
        assert first.returncode == 0, first.stdout + first.stderr
        assert '"snapshot_id"' in first.stdout and "initialising" in first.stderr, first.stdout + first.stderr
        assert "created restic repository" in first.stderr + first.stdout, first.stderr

        # The next backup reuses it: one repository, two snapshots, no second initialisation.
        second = run("sh /create.sh && restic snapshots --compact", directory)
        assert second.returncode == 0 and "initialising" not in second.stderr and "2 snapshots" in second.stdout, second.stdout + second.stderr

        # A repository that exists but cannot be opened is never initialised over: the cause is reported and the backup stops.
        wrong = run("sh /create.sh", directory, key="other")
        assert wrong.returncode != 0 and "initialising" not in wrong.stderr and '"snapshot_id"' not in wrong.stdout, wrong.stdout + wrong.stderr
        assert "wrong password" in wrong.stderr.lower() or "no key found" in wrong.stderr.lower(), wrong.stderr
        intact = run("sh /create.sh && restic snapshots --compact", directory)
        assert intact.returncode == 0 and "3 snapshots" in intact.stdout, "the existing repository was damaged\n" + intact.stdout + intact.stderr
    print("e2e backup repository initialisation passed")


if __name__ == "__main__":
    main()
