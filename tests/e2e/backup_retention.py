#!/usr/bin/env python3
"""Qualify Restic retention and integrity checks on a disposable S3-compatible store.

Uses the commands the control plane runs (`forget --prune` with the retention flags, then
`restic check`), against the restic that ships in the core image.
"""
from __future__ import annotations

import json
import os
import pathlib
import secrets
import subprocess
import shutil

import object_store

ROOT = pathlib.Path(__file__).resolve().parents[2]
COMPOSE = os.environ.get("CDNF_COMPOSE_FILE", "compose.dev.yml")
NETWORK = os.environ.get("CDNF_CONTROL_NETWORK", "cdnfoundry-dev_control")
RUN = secrets.token_hex(5)
MINIO = f"cdnf-retention-{RUN}"
BUCKET = f"retention-{RUN}"
USER, PASSWORD, RESTIC_PASSWORD = f"u{secrets.token_hex(6)}", secrets.token_urlsafe(24), secrets.token_urlsafe(24)


def run(*args: str, check: bool = True, timeout: int = 300) -> subprocess.CompletedProcess[str]:
    result = subprocess.run(args, cwd=ROOT, text=True, capture_output=True, timeout=timeout)
    if check and result.returncode:
        raise RuntimeError(f"{' '.join(args[:6])} failed:\n{result.stdout[-1200:]}\n{result.stderr[-1200:]}")
    return result


def main() -> None:
    image = run("docker", "compose", "-f", COMPOSE, "images", "-q", "core").stdout.strip()
    repository = f"s3:http://{MINIO}:{object_store.PORT}/{BUCKET}"
    env = ["-e", f"RESTIC_REPOSITORY={repository}", "-e", f"RESTIC_PASSWORD={RESTIC_PASSWORD}",
           "-e", f"AWS_ACCESS_KEY_ID={USER}", "-e", f"AWS_SECRET_ACCESS_KEY={PASSWORD}", "-e", "AWS_DEFAULT_REGION=us-east-1"]

    def restic(*args: str, check: bool = True, stdin: str | None = None) -> subprocess.CompletedProcess[str]:
        prefix = ["docker", "run", "--rm", "--network", NETWORK, "--entrypoint", "restic", *(["-i"] if stdin else []), *env, image]
        return subprocess.run([*prefix, *args], cwd=ROOT, text=True, input=stdin, capture_output=True, timeout=300, check=False) if not check else _checked(prefix, args, stdin)

    def _checked(prefix: list[str], args: tuple[str, ...], stdin: str | None) -> subprocess.CompletedProcess[str]:
        result = subprocess.run([*prefix, *args], cwd=ROOT, text=True, input=stdin, capture_output=True, timeout=300, check=False)
        if result.returncode:
            raise RuntimeError(f"restic {args[0]} failed:\n{result.stdout[-800:]}\n{result.stderr[-800:]}")
        return result

    directory = object_store.start(MINIO, NETWORK, USER, PASSWORD)
    try:
        restic("init")
        for index in range(5):
            restic("backup", "--stdin", "--stdin-filename", "control.pgdump", "--tag", "cdnfoundry-control", stdin=f"dump {index} {secrets.token_hex(64)}")
        before = len(json.loads(restic("snapshots", "--json").stdout))
        assert before == 5, before

        # The same flags ResticBackupRepository::prune() passes (grouped by tag, because every backup runs in a container with its own hostname); all five are from today, so one daily survives.
        restic("forget", "--prune", "--tag", "cdnfoundry-control", "--group-by", "tags", "--keep-daily", "1", "--keep-weekly", "0", "--keep-monthly", "0")
        after = len(json.loads(restic("snapshots", "--json").stdout))
        assert after == 1, after
        restic("check")

        # A deleted pack must fail the check. The S3 gateway keeps objects inside volume files, so use a local
        # repository for this half: same restic, same command, a file we can remove.
        volume = f"cdnf-retention-local-{RUN}"
        local = ["docker", "run", "--rm", "-v", f"{volume}:/repo", "--entrypoint", "restic", "-e", "RESTIC_REPOSITORY=/repo", "-e", f"RESTIC_PASSWORD={RESTIC_PASSWORD}", image]
        try:
            run(*local, "init")
            subprocess.run([*local[:3], "-i", *local[3:], "backup", "--stdin", "--stdin-filename", "control.pgdump"], cwd=ROOT, text=True, input="payload " + secrets.token_hex(64), capture_output=True, check=True)
            run(*local, "check")
            run("docker", "run", "--rm", "-v", f"{volume}:/repo", "--entrypoint", "sh", image, "-c", "find /repo/data -type f -delete")
            broken = run(*local, "check", check=False)
            assert broken.returncode != 0, "restic check accepted a repository with a missing pack"
        finally:
            run("docker", "volume", "rm", "-f", volume, check=False)
        print(json.dumps({"qualification": "backup_retention", "snapshots_before": before, "snapshots_after_prune": after,
                          "check_passes_on_intact_repository": "passed", "check_fails_on_missing_pack": "passed"}))
    finally:
        run("docker", "rm", "-f", MINIO, check=False)
        shutil.rmtree(directory, ignore_errors=True)


if __name__ == "__main__":
    main()
