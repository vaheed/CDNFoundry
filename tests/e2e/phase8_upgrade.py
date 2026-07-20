#!/usr/bin/env python3
"""Prior/current control and edge-agent mixed-version rollback qualification."""

from __future__ import annotations

import json
import os
import pathlib
import re
import secrets
import subprocess
import tarfile
import tempfile
import time

ROOT = pathlib.Path(__file__).resolve().parents[2]
NETWORK = os.environ.get("CDNF_CONTROL_NETWORK", "cdnfoundry-dev_control")
POSTGRES_IMAGE = os.environ.get("CDNF_POSTGRES_IMAGE", "postgres:18.4-alpine")
RUN = f"{int(time.time())}-{secrets.token_hex(4)}"
DATABASE = f"cdnfoundry-phase8-upgrade-db-{RUN}"
PRIOR_CORE = f"cdnfoundry-phase8-prior-core:{RUN}"
CURRENT_CORE = f"cdnfoundry-phase8-current-core:{RUN}"
PRIOR_AGENT = f"cdnfoundry-phase8-prior-agent:{RUN}"
CURRENT_AGENT = f"cdnfoundry-phase8-current-agent:{RUN}"
APP_KEY = "base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA="


def run(*args: str, cwd: pathlib.Path = ROOT, timeout: int = 900,
        check: bool = True) -> subprocess.CompletedProcess[str]:
    result = subprocess.run(args, cwd=cwd, text=True, capture_output=True, timeout=timeout, check=False)
    if check and result.returncode != 0:
        raise RuntimeError(f"command failed: {' '.join(args)}\n{result.stdout}\n{result.stderr}")
    return result


def wait_postgres() -> None:
    deadline = time.monotonic() + 45
    while time.monotonic() < deadline:
        result = run("docker", "exec", DATABASE, "pg_isready", "-U", "cdnf_upgrade", "-d", "cdnf_upgrade", check=False)
        if result.returncode == 0:
            return
        time.sleep(1)
    raise RuntimeError("mixed-version PostgreSQL did not become ready")


def application(image: str, *command: str) -> subprocess.CompletedProcess[str]:
    return run(
        "docker", "run", "--rm", "--network", NETWORK, "--entrypoint", "php",
        "-e", "APP_ENV=production", "-e", f"APP_KEY={APP_KEY}",
        "-e", "DB_CONNECTION=pgsql", "-e", f"DB_HOST={DATABASE}", "-e", "DB_PORT=5432",
        "-e", "DB_DATABASE=cdnf_upgrade", "-e", "DB_USERNAME=cdnf_upgrade",
        "-e", "DB_PASSWORD=phase8-upgrade-only", "-e", "CACHE_STORE=array",
        "-e", "SESSION_DRIVER=array", "-e", "QUEUE_CONNECTION=sync",
        image, *command,
    )


def psql(sql: str) -> str:
    return run(
        "docker", "exec", DATABASE, "psql", "-U", "cdnf_upgrade", "-d", "cdnf_upgrade", "-Atc", sql,
    ).stdout.strip()


def source_version(source: pathlib.Path) -> str:
    text = (source / "edge-agent" / "main.go").read_text()
    match = re.search(r'const version = "([^"]+)"', text)
    if not match:
        raise RuntimeError("edge-agent release version is missing")
    return match.group(1)


def main() -> None:
    prior_sha = run("git", "rev-parse", "HEAD").stdout.strip()
    current_version = source_version(ROOT)
    with tempfile.TemporaryDirectory(prefix="cdnfoundry-phase8-upgrade-") as temp_name:
        temp = pathlib.Path(temp_name)
        archive = temp / "prior.tar"
        prior = temp / "prior"
        prior.mkdir()
        run("git", "archive", "--format=tar", f"--output={archive}", prior_sha)
        with tarfile.open(archive) as source:
            source.extractall(prior, filter="data")
        prior_version = source_version(prior)
        if prior_version == current_version:
            raise AssertionError("the canary requires distinct prior/current edge-agent versions")

        run("docker", "build", "--target", "production", "-t", PRIOR_CORE, str(prior / "core"))
        run("docker", "build", "--target", "production", "-t", CURRENT_CORE, str(ROOT / "core"))
        # Both Dockerfiles run their complete Go suite, including signed artifact compatibility.
        run("docker", "build", "-t", PRIOR_AGENT, str(prior / "edge-agent"))
        run("docker", "build", "-t", CURRENT_AGENT, str(ROOT / "edge-agent"))

        run(
            "docker", "run", "-d", "--rm", "--name", DATABASE, "--network", NETWORK,
            "--tmpfs", "/var/lib/postgresql:rw,nosuid,nodev,size=2g",
            "-e", "POSTGRES_DB=cdnf_upgrade", "-e", "POSTGRES_USER=cdnf_upgrade",
            "-e", "POSTGRES_PASSWORD=phase8-upgrade-only", POSTGRES_IMAGE,
        )
        try:
            wait_postgres()
            database_id = run("docker", "inspect", "--format={{.Id}}", DATABASE).stdout.strip()
            application(PRIOR_CORE, "artisan", "migrate", "--force")
            prior_migrations = int(psql("SELECT count(*) FROM migrations"))
            prior_marker = f"prior-{RUN}"
            application(
                PRIOR_CORE, "artisan", "tinker", "--execute="
                f"App\\Models\\Operation::query()->create(['type'=>'{prior_marker}','status'=>'succeeded','input'=>[]]);",
            )

            application(CURRENT_CORE, "artisan", "migrate", "--force")
            current_migrations = int(psql("SELECT count(*) FROM migrations"))
            if current_migrations <= prior_migrations:
                raise AssertionError((prior_migrations, current_migrations))
            current_marker = f"current-{RUN}"
            application(
                CURRENT_CORE, "artisan", "tinker", "--execute="
                f"if(App\\Models\\Operation::query()->where('type','{prior_marker}')->count()!==1)throw new RuntimeException('prior marker missing');"
                f"App\\Models\\Operation::query()->create(['type'=>'{current_marker}','status'=>'succeeded','input'=>[]]);",
            )

            # Roll the application back while retaining the additive current schema.
            application(
                PRIOR_CORE, "artisan", "tinker", "--execute="
                f"if(App\\Models\\Operation::query()->where('type','{current_marker}')->count()!==1)throw new RuntimeException('current marker missing');"
                "App\\Models\\Operation::query()->create(['type'=>'prior.rollback.write','status'=>'succeeded','input'=>[]]);",
            )
            if int(psql("SELECT count(*) FROM operations WHERE type='prior.rollback.write'")) != 1:
                raise AssertionError("the prior release could not write after rollback")
            if run("docker", "inspect", "--format={{.Id}}", DATABASE).stdout.strip() != database_id:
                raise AssertionError("the database container changed during application rollback")
            if int(psql("SELECT count(*) FROM migrations")) != current_migrations:
                raise AssertionError("application rollback modified or restored the database schema")

            observed_current = run("docker", "run", "--rm", CURRENT_AGENT, "--version").stdout.strip()
            if observed_current != current_version:
                raise AssertionError((observed_current, current_version))

            print(json.dumps({
                "phase8_upgrade": "passed",
                "prior_commit": prior_sha,
                "prior_agent_version": prior_version,
                "current_agent_version": current_version,
                "prior_migrations": prior_migrations,
                "current_migrations": current_migrations,
                "database_restored": False,
                "prior_write_after_rollback": True,
                "signed_artifact_tests": "passed_in_both_image_builds",
            }, sort_keys=True))
        finally:
            run("docker", "stop", DATABASE, check=False)
            for image in (PRIOR_CORE, CURRENT_CORE, PRIOR_AGENT, CURRENT_AGENT):
                run("docker", "image", "rm", image, check=False)


if __name__ == "__main__":
    main()
