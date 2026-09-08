"""Host-local PowerDNS password reconciliation, copied into pending Fleet bundles."""
from __future__ import annotations

import fcntl
import os
from pathlib import Path
import re
import subprocess
import tempfile


class RotationError(Exception):
    pass


def run(*arguments: str, password: str = "", pending: str = "", sql: str | None = None) -> bool:
    environment = {**os.environ, "PGPASSWORD": password, "NEXT_PDNS_DB_PASSWORD": pending}
    # Errors can contain SQL or configuration secrets. Emit bounded, actionable
    # phase errors below instead of forwarding subprocess diagnostics.
    result = subprocess.run(["docker", "compose", "--env-file", ".env.prod", *arguments],
                            env=environment, input=sql, text=True, stdout=subprocess.DEVNULL,
                            stderr=subprocess.DEVNULL, timeout=240)
    return result.returncode == 0


def setting(path: Path, prefix: str, value: str) -> tuple[str, str]:
    lines = path.read_text(encoding="utf-8").splitlines()
    matches = [index for index, line in enumerate(lines) if line.startswith(prefix)]
    if len(matches) != 1:
        raise RotationError(f"{path} must contain exactly one {prefix[:-1]} setting; correct it before retrying.")
    old = lines[matches[0]][len(prefix):]
    lines[matches[0]] = prefix + value
    return old, "\n".join(lines) + "\n"


def sync_directory(path: Path) -> None:
    fd = os.open(path, os.O_RDONLY | os.O_DIRECTORY)
    try:
        os.fsync(fd)
    finally:
        os.close(fd)


def reconcile() -> None:
    pending = Path("secrets/pdns-db-password.next").read_text().strip()
    if not re.fullmatch(r"[0-9a-f]{64}", pending):
        raise RotationError("Pending password is not a Fleet-generated rotation; restore it from protected Fleet state.")
    env_path = Path(".env.prod")
    config = Path("docker/pdns/pdns.conf")
    current, config_payload = setting(config, "gpgsql-password=", pending)
    _, env_payload = setting(env_path, "PDNS_DB_PASSWORD=", pending)
    if not run("config", "--quiet"):
        raise RotationError("Compose configuration failed; correct .env.prod before retrying rotation.")

    staged: list[tuple[Path, Path]] = []
    try:
        # Validate and durably stage both consumers before altering PostgreSQL.
        for path, payload, mode, group in ((env_path, env_payload, 0o600, 0), (config, config_payload, 0o640, 82)):
            fd, name = tempfile.mkstemp(prefix="."+path.name+".", dir=path.parent)
            candidate = Path(name)
            staged.append((candidate, path))
            with os.fdopen(fd, "w", encoding="utf-8") as handle:
                os.fchown(handle.fileno(), 0, group)
                os.fchmod(handle.fileno(), mode)
                handle.write(payload)
                handle.flush()
                os.fsync(handle.fileno())
        backup = Path(".env.prod.before-pdns-rotation")
        if not backup.exists():
            with backup.open("xb") as handle:
                os.fchmod(handle.fileno(), 0o600)
                handle.write(env_path.read_bytes())
                handle.flush()
                os.fsync(handle.fileno())
            sync_directory(backup.parent)

        def authenticate(password: str) -> bool:
            return run("exec", "-T", "-e", "PGPASSWORD", "pdns-db", "psql", "-h", "pdns-db", "-U", "pdns", "-d", "pdns", "-Atc", "SELECT 1", password=password)

        # Recovery after interruption between ALTER ROLE and file activation
        # must authenticate with the already-applied pending credential.
        if authenticate(pending):
            active = pending
        elif authenticate(current):
            active = current
        else:
            raise RotationError("Neither protected credential authenticates to pdns-db; restore database credentials before retrying.")
        if not run("exec", "-T", "-e", "PGPASSWORD", "-e", "NEXT_PDNS_DB_PASSWORD", "pdns-db", "sh", "-eu", "-c",
                   'exec psql -h pdns-db -U pdns -d pdns -v ON_ERROR_STOP=1 -v next_password="$NEXT_PDNS_DB_PASSWORD"',
                   password=active, pending=pending, sql="ALTER ROLE pdns PASSWORD :'next_password';\n"):
            raise RotationError("PostgreSQL password update failed; preserve the pending file and retry this command.")
        for candidate, path in staged:
            os.replace(candidate, path)
            sync_directory(path.parent)
        if not run("up", "-d", "--no-deps", "--force-recreate", "--wait", "--wait-timeout", "180", "pdns-auth"):
            raise RotationError("Password applied but PowerDNS activation failed; fix service health and rerun this command with the same pending file.")
        print("Local PostgreSQL, PowerDNS configuration, and .env.prod now use the pending password.")
        print("Commit the rotation in protected Fleet state, then rerender. Retain the private before-rotation backup until recovery is verified.")
    finally:
        for candidate, _ in staged:
            candidate.unlink(missing_ok=True)


def main() -> None:
    if os.geteuid() != 0:
        raise SystemExit("PowerDNS password reconciliation must run as root to preserve private file ownership.")
    os.umask(0o077)
    try:
        with Path(".pdns-rotation.lock").open("a") as lock:
            try:
                fcntl.flock(lock.fileno(), fcntl.LOCK_EX | fcntl.LOCK_NB)
            except BlockingIOError as exc:
                raise RotationError("Another PowerDNS rotation is running; wait for it to finish before retrying.") from exc
            reconcile()
    except (RotationError, OSError, subprocess.TimeoutExpired) as exc:
        if isinstance(exc, RotationError):
            raise SystemExit(str(exc)) from None
        raise SystemExit("PowerDNS reconciliation was interrupted or local file access failed; preserve the pending file, check service/file access and retry.") from None


if __name__ == "__main__":
    main()
