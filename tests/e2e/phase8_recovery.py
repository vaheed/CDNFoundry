#!/usr/bin/env python3
"""Encrypted off-host S3 backup and clean replacement PostgreSQL qualification."""

from __future__ import annotations

import json
import os
import pathlib
import secrets
import subprocess
import time

ROOT = pathlib.Path(__file__).resolve().parents[2]
COMPOSE = os.environ.get("CDNF_COMPOSE_FILE", "compose.dev.yml")
NETWORK = os.environ.get("CDNF_CONTROL_NETWORK", "cdnfoundry-dev_control")
MINIO_IMAGE = os.environ.get("CDNF_MINIO_IMAGE", "quay.io/minio/minio:RELEASE.2025-07-23T15-54-02Z")
POSTGRES_IMAGE = os.environ.get("CDNF_POSTGRES_IMAGE", "postgres:18.4-alpine")
QUEUE_IMAGE = os.environ.get("CDNF_QUEUE_IMAGE", "valkey/valkey:9.1.0-alpine")
RUN = f"{int(time.time())}-{secrets.token_hex(4)}"
MINIO = f"cdnfoundry-phase8-object-{RUN}"
RESTORE = f"cdnfoundry-phase8-replacement-{RUN}"
QUEUE = f"cdnfoundry-phase8-queue-{RUN}"
BUCKET = f"phase8-{RUN}"
S3_USER = f"phase8{secrets.token_hex(8)}"
S3_PASSWORD = secrets.token_urlsafe(32)
RESTIC_PASSWORD = secrets.token_urlsafe(40)
MARKER = f"phase8-recovery-{RUN}@example.test"


def run(*args: str, timeout: int = 180, check: bool = True) -> subprocess.CompletedProcess[str]:
    result = subprocess.run(args, cwd=ROOT, text=True, capture_output=True, timeout=timeout, check=False)
    if check and result.returncode != 0:
        raise RuntimeError(f"command failed: {' '.join(args)}\n{result.stdout}\n{result.stderr}")
    return result


def compose(*args: str, timeout: int = 180) -> subprocess.CompletedProcess[str]:
    return run("docker", "compose", "-f", COMPOSE, *args, timeout=timeout)


def core_image() -> str:
    image = compose("images", "-q", "core").stdout.strip()
    if not image:
        raise RuntimeError("the development core image is unavailable; run make dev-up first")
    return image


def backup_environment(repository: str, password: str = RESTIC_PASSWORD) -> list[str]:
    return [
        "-e", f"RESTIC_REPOSITORY={repository}",
        "-e", f"RESTIC_PASSWORD={password}",
        "-e", f"AWS_ACCESS_KEY_ID={S3_USER}",
        "-e", f"AWS_SECRET_ACCESS_KEY={S3_PASSWORD}",
        "-e", "AWS_DEFAULT_REGION=us-east-1",
    ]


def postgres_environment(host: str, database: str, user: str, password: str) -> list[str]:
    return [
        "-e", f"PGHOST={host}", "-e", "PGPORT=5432", "-e", f"PGDATABASE={database}",
        "-e", f"PGUSER={user}", "-e", f"PGPASSWORD={password}",
    ]


def wait_ready(container: str, user: str, database: str, timeout: int = 45) -> None:
    deadline = time.monotonic() + timeout
    while time.monotonic() < deadline:
        result = run("docker", "exec", container, "pg_isready", "-U", user, "-d", database, check=False)
        if result.returncode == 0:
            return
        time.sleep(1)
    raise RuntimeError(f"PostgreSQL container {container} did not become ready")


def psql(container: str, user: str, database: str, sql: str) -> str:
    return run("docker", "exec", container, "psql", "-U", user, "-d", database, "-Atc", sql).stdout.strip()


def source_psql(sql: str) -> str:
    return compose("exec", "-T", "control-db", "psql", "-U", "cdnf", "-d", "cdnf", "-Atc", sql).stdout.strip()


def queue_length(pattern: str) -> int:
    keys = run("docker", "exec", QUEUE, "valkey-cli", "--scan", "--pattern", pattern).stdout.splitlines()
    return sum(int(run("docker", "exec", QUEUE, "valkey-cli", "llen", key).stdout.strip()) for key in keys)


def state_counts_source() -> dict[str, int]:
    tables = ("users", "domains", "dns_records", "dns_deployments", "edges", "edge_artifacts", "tls_certificates")
    return {table: int(source_psql(f'SELECT count(*) FROM "{table}"')) for table in tables}


def state_counts_restore() -> dict[str, int]:
    tables = ("users", "domains", "dns_records", "dns_deployments", "edges", "edge_artifacts", "tls_certificates")
    return {table: int(psql(RESTORE, "cdnf_restore", "cdnf_restore", f'SELECT count(*) FROM "{table}"')) for table in tables}


def main() -> None:
    image = core_image()
    repository = f"s3:http://{MINIO}:9000/{BUCKET}"
    started_at = time.time()
    snapshot = ""

    run(
        "docker", "run", "-d", "--rm", "--name", MINIO, "--network", NETWORK,
        "--tmpfs", "/data:rw,nosuid,nodev,size=2g",
        "-e", f"MINIO_ROOT_USER={S3_USER}", "-e", f"MINIO_ROOT_PASSWORD={S3_PASSWORD}",
        MINIO_IMAGE, "server", "/data",
    )
    try:
        deadline = time.monotonic() + 60
        while time.monotonic() < deadline:
            alias = run(
                "docker", "exec", MINIO, "mc", "alias", "set", "local",
                "http://127.0.0.1:9000", S3_USER, S3_PASSWORD, check=False,
            )
            if alias.returncode == 0 and run("docker", "exec", MINIO, "mc", "ready", "local", check=False).returncode == 0:
                break
            time.sleep(1)
        else:
            raise RuntimeError("ephemeral S3-compatible object host did not become ready")
        run("docker", "exec", MINIO, "mc", "mb", f"local/{BUCKET}")

        source_psql(
            "INSERT INTO users (name,email,password,type,disabled_at,created_at,updated_at) "
            f"VALUES ('Phase 8 recovery marker','{MARKER}','not-a-login-secret','admin',now(),now(),now())"
        )
        cutoff_at = time.time()
        source_counts = state_counts_source()

        common = ["docker", "run", "--rm", "--network", NETWORK, "--entrypoint", ""]
        run(*common, *backup_environment(repository), image, "restic", "init")
        backup = run(
            *common, *backup_environment(repository),
            *postgres_environment("control-db", "cdnf", "cdnf", "cdnf-dev-only"),
            image, "/usr/local/bin/cdnf-backup-create", timeout=600,
        )
        events = [json.loads(line) for line in backup.stdout.splitlines() if line.startswith("{")]
        summaries = [event for event in events if event.get("snapshot_id")]
        if not summaries:
            raise RuntimeError(f"Restic backup returned no snapshot identifier: {backup.stdout}")
        snapshot = summaries[-1]["snapshot_id"]
        backup_completed_at = time.time()
        run(*common, *backup_environment(repository), image, "restic", "check", "--read-data-subset=100%", timeout=600)

        wrong = run(
            *common, *backup_environment(repository, "wrong-decryption-material"),
            image, "restic", "snapshots", snapshot, check=False,
        )
        if wrong.returncode == 0:
            raise AssertionError("the encrypted repository accepted incorrect decryption material")

        restore_started_at = time.time()
        run(
            "docker", "run", "-d", "--rm", "--name", RESTORE, "--network", NETWORK,
            "--tmpfs", "/var/lib/postgresql:rw,nosuid,nodev,size=2g",
            "-e", "POSTGRES_DB=cdnf_restore", "-e", "POSTGRES_USER=cdnf_restore",
            "-e", "POSTGRES_PASSWORD=phase8-restore-only", POSTGRES_IMAGE,
        )
        wait_ready(RESTORE, "cdnf_restore", "cdnf_restore")
        run(
            *common, *backup_environment(repository),
            *postgres_environment(RESTORE, "cdnf_restore", "cdnf_restore", "phase8-restore-only"),
            image, "/usr/local/bin/cdnf-backup-restore", snapshot, timeout=600,
        )
        restored_counts = state_counts_restore()
        if restored_counts != source_counts:
            raise AssertionError((source_counts, restored_counts))
        marker_count = psql(RESTORE, "cdnf_restore", "cdnf_restore", f"SELECT count(*) FROM users WHERE email='{MARKER}'")
        if marker_count != "1":
            raise AssertionError("the just-before-backup recovery marker was not restored")
        compose(
            "run", "--rm", "--no-deps",
            "-e", "APP_ENV=production", "-e", f"DB_HOST={RESTORE}", "-e", "DB_PORT=5432",
            "-e", "DB_DATABASE=cdnf_restore", "-e", "DB_USERNAME=cdnf_restore",
            "-e", "DB_PASSWORD=phase8-restore-only", "core", "php", "artisan", "migrate", "--force",
            timeout=600,
        )
        migration_count = int(psql(RESTORE, "cdnf_restore", "cdnf_restore", "SELECT count(*) FROM migrations"))
        expected_migrations = len(list((ROOT / "core/database/migrations").glob("*.php")))
        if migration_count != expected_migrations:
            raise AssertionError(f"replacement schema has {migration_count} migrations, expected {expected_migrations}")
        restored_counts["migrations"] = migration_count

        # Queue contents are deliberately absent on a replacement host. Prove
        # that each bounded global reconciler can reconstruct work from desired
        # PostgreSQL state into a fresh queue without copying old Redis data.
        run("docker", "run", "-d", "--rm", "--name", QUEUE, "--network", NETWORK, "--tmpfs", "/data", QUEUE_IMAGE)
        deadline = time.monotonic() + 30
        while time.monotonic() < deadline:
            if run("docker", "exec", QUEUE, "valkey-cli", "ping", check=False).stdout.strip() == "PONG":
                break
            time.sleep(0.5)
        else:
            raise RuntimeError("replacement queue did not become ready")
        expression = (
            "$classes=['dns.global_reconcile'=>App\\Jobs\\ReconcileAllDnsZones::class,"
            "'edges.global_reconcile'=>App\\Jobs\\ReconcileAllEdgeDomains::class,"
            "'tls.global_reconcile'=>App\\Jobs\\ReconcileAllTls::class,"
            "'purges.global_reconcile'=>App\\Jobs\\ReconcileAllPurges::class];"
            "foreach($classes as $type=>$class){$operation=App\\Models\\Operation::query()->create("
            "['type'=>$type,'status'=>'pending','input'=>['reason'=>'replacement_queue_recovery']]);"
            "$class::dispatch($operation->id);};"
            "$from=now()->utc()->subHour()->startOfHour();$to=now()->utc()->startOfHour();"
            "$usage=App\\Models\\Operation::query()->create(['type'=>'usage.global_reconcile','status'=>'pending',"
            "'input'=>['from'=>$from->toIso8601String(),'to'=>$to->toIso8601String()]]);"
            "App\\Jobs\\BuildUsageRollups::dispatch($from->toIso8601String(),$to->toIso8601String(),null,$usage->id);"
        )
        compose(
            "run", "--rm", "--no-deps", "-e", f"DB_HOST={RESTORE}", "-e", "DB_PORT=5432",
            "-e", "DB_DATABASE=cdnf_restore", "-e", "DB_USERNAME=cdnf_restore",
            "-e", "DB_PASSWORD=phase8-restore-only", "-e", f"REDIS_HOST={QUEUE}",
            "-e", "CACHE_STORE=redis", "-e", "QUEUE_CONNECTION=redis",
            "core", "php", "artisan", "tinker", f"--execute={expression}", timeout=180,
        )
        reconstructed_jobs = queue_length("*queues:bulk_maintenance")
        if reconstructed_jobs != 5:
            raise AssertionError(f"replacement queue contains {reconstructed_jobs} global jobs, expected 5")
        compose(
            "run", "--rm", "--no-deps", "-e", f"DB_HOST={RESTORE}", "-e", "DB_PORT=5432",
            "-e", "DB_DATABASE=cdnf_restore", "-e", "DB_USERNAME=cdnf_restore",
            "-e", "DB_PASSWORD=phase8-restore-only", "-e", f"REDIS_HOST={QUEUE}",
            "-e", "CACHE_STORE=redis", "-e", "QUEUE_CONNECTION=redis",
            "core", "php", "artisan", "queue:work", "redis", "--queue=bulk_maintenance",
            "--stop-when-empty", "--tries=1", "--timeout=180", timeout=600,
        )
        if queue_length("*queues:bulk_maintenance") != 0:
            raise AssertionError("replacement global reconciliation queue did not drain")
        reconstructed_runtime_jobs = queue_length("*queues:runtime") + queue_length("*queues:certificate_purge")
        if reconstructed_runtime_jobs == 0:
            raise AssertionError("global reconciliation reconstructed no bounded runtime work")
        reconciliations_succeeded = int(psql(
            RESTORE, "cdnf_restore", "cdnf_restore",
            "SELECT count(*) FROM operations WHERE type IN ('dns.global_reconcile','edges.global_reconcile',"
            "'tls.global_reconcile','purges.global_reconcile','usage.global_reconcile') "
            "AND status='succeeded' AND created_at > now() - interval '10 minutes'",
        ))
        if reconciliations_succeeded < 5:
            raise AssertionError(f"only {reconciliations_succeeded} replacement reconciliations succeeded")
        active_domains = int(psql(
            RESTORE, "cdnf_restore", "cdnf_restore", "SELECT count(*) FROM domains WHERE deleted_at IS NULL",
        ))
        rebuilt_usage_intervals = int(psql(
            RESTORE, "cdnf_restore", "cdnf_restore",
            "SELECT count(*) FROM usage_rollups WHERE granularity='hour' AND status='finalized' "
            "AND source_finalized_at > now() - interval '10 minutes'",
        ))
        if rebuilt_usage_intervals != active_domains:
            raise AssertionError(
                f"rebuilt {rebuilt_usage_intervals} usage intervals, expected {active_domains} active domains"
            )
        restore_completed_at = time.time()

        print(json.dumps({
            "phase8_recovery": "passed",
            "object_host_image": MINIO_IMAGE,
            "snapshot": snapshot,
            "rpo_seconds": round(backup_completed_at - cutoff_at, 3),
            "rto_seconds": round(restore_completed_at - restore_started_at, 3),
            "total_seconds": round(restore_completed_at - started_at, 3),
            "counts": restored_counts,
            "reconstructed_queue_jobs": reconstructed_jobs,
            "reconstructed_runtime_jobs": reconstructed_runtime_jobs,
            "reconciliations_succeeded": reconciliations_succeeded,
            "rebuilt_usage_intervals": rebuilt_usage_intervals,
            "active_domains": active_domains,
            "wrong_password_rejected": True,
        }, sort_keys=True))
    finally:
        run("docker", "stop", RESTORE, check=False)
        run("docker", "stop", QUEUE, check=False)
        run("docker", "stop", MINIO, check=False)
        source_psql(f"DELETE FROM users WHERE email='{MARKER}'")


if __name__ == "__main__":
    main()
