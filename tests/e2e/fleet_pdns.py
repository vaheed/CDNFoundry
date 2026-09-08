#!/usr/bin/env python3
"""Qualify generated PowerDNS secret activation and rotation on disposable services.

No public DNS, GeoIP or full multi-host installation is claimed. PostgreSQL uses
fresh tmpfs; cleanup is restricted to this random Compose project, without -v.
"""
from __future__ import annotations

import json
import fcntl
import os
import re
from pathlib import Path
import stat
import secrets
import subprocess
import sys
import tempfile
import time
import uuid

import yaml

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT / "scripts"))
from cdnfoundry_fleet.render import Renderer
from cdnfoundry_fleet.state import FleetState


def require(condition: bool, message: str) -> None:
    if not condition:
        raise RuntimeError(message)


def main() -> None:
    require(os.geteuid() == 0, "Run this isolated file-ownership qualification as root.")
    project = "cdnf-pdns-qualification-" + uuid.uuid4().hex[:12]
    source = yaml.safe_load((ROOT / "compose.prod.yml").read_text())
    images = {name: source["services"][name]["image"] for name in ("pdns-db", "pdns-auth")}
    require(all(re.search(r"@sha256:[0-9a-f]{64}$", value) for value in images.values()),
            "PowerDNS qualification requires immutable production image digests.")
    with tempfile.TemporaryDirectory(prefix=project) as temporary:
        root = Path(temporary)
        store = FleetState(root / "state")
        with store.locked():
            state = store.init({"operator_domain": "ops.example.com", "platform_domain": "example.net", "release": "qualification"})
            store.add_node(state, {"name": "dns-qualification", "role": "dns", "region": "test", "location": "local", "public_ipv4": "192.0.2.100"})
        store.prepare_secret_rotation("pdns-db-password", node="dns-qualification")
        Renderer(ROOT, store, root / "bundles").render(store.load())
        bundle = root / "bundles/dns-qualification"
        config = bundle / "docker/pdns/pdns.conf"
        require(stat.S_IMODE(config.stat().st_mode) == 0o600, "Generated credentials are not transfer-private.")
        # Run the generated host preflight, stopping at validation: this fixture
        # deliberately starts only the two services needed for secret rotation.
        (bundle / "validate.sh").write_text("#!/bin/sh\nexit 73\n")
        preflight = subprocess.run(["sh", "start.sh"], cwd=bundle, capture_output=True)
        require(preflight.returncode == 73, "Generated permission preflight failed.")
        require(stat.S_IMODE(config.stat().st_mode) == 0o640 and config.stat().st_uid == 0 and config.stat().st_gid == 82,
                "Activated PowerDNS credentials are not restricted to root:82 mode 640.")
        generated = yaml.safe_load((bundle / "compose.yml").read_text())
        # GeoIP/public DNS are qualified separately. Keep the actual generated
        # database and API credentials, image, service user, group and mount.
        config.write_text("\n".join(line.replace("launch=gpgsql,geoip", "launch=gpgsql")
                                   for line in config.read_text().splitlines() if not line.startswith("geoip-"))+"\n")
        compose = {"services": {
            "pdns-db": {"image": images["pdns-db"], "environment": {"POSTGRES_DB": "pdns", "POSTGRES_USER": "pdns", "POSTGRES_PASSWORD": "${PDNS_DB_PASSWORD}"},
                        "tmpfs": ["/var/lib/postgresql"],
                        "volumes": [str(ROOT / "docker/postgres/pdns-schema.sql")+":/docker-entrypoint-initdb.d/schema.sql:ro"],
                        "healthcheck": {"test": ["CMD", "pg_isready", "-U", "pdns"], "interval": "1s", "timeout": "2s", "retries": 30}},
            "pdns-auth": {"image": images["pdns-auth"], "group_add": generated["services"]["pdns-auth"].get("group_add", []),
                          "volumes": ["./docker/pdns/pdns.conf:/etc/powerdns/pdns.conf:ro"],
                          "depends_on": {"pdns-db": {"condition": "service_healthy"}},
                          "healthcheck": source["services"]["pdns-auth"]["healthcheck"]},
        }}
        (bundle / "compose.yml").write_text(yaml.safe_dump(compose))
        environment = {**os.environ, "COMPOSE_PROJECT_NAME": project, "COMPOSE_FILE": "compose.yml"}

        def command(*arguments: str, check: bool = True, **kwargs) -> subprocess.CompletedProcess:
            result = subprocess.run(arguments, cwd=bundle, env=environment, capture_output=True, text=True, **kwargs)
            if check and result.returncode:
                # Docker/psql diagnostics can include credentials. Report the
                # failed boundary only; never dump generated config or logs.
                raise RuntimeError("Isolated PowerDNS command failed: "+arguments[0]+" (exit "+str(result.returncode)+")")
            return result

        def dc(*arguments: str, **kwargs) -> subprocess.CompletedProcess:
            return command("docker", "compose", "--env-file", ".env.prod", *arguments, **kwargs)

        try:
            dc("up", "-d", "--wait", "--wait-timeout", "90")
            database_container = dc("ps", "-q", "pdns-db").stdout.strip()
            dc("exec", "-T", "pdns-auth", "sh", "-c", "test -r /etc/powerdns/pdns.conf")
            command("docker", "run", "--rm", "--user", "65534:65534", "--entrypoint", "sh", "-v", str(config)+":/credential:ro",
                                images["pdns-auth"], "-c", "test ! -r /credential")
            current = store.read_secret("pdns-db-password", node="dns-qualification")
            pending = store.pending_secret_path("pdns-db-password", node="dns-qualification").read_text().strip()

            def authenticate(password: str) -> bool:
                return dc("exec", "-T", "-e", "PGPASSWORD="+password, "pdns-db", "psql", "-h", "pdns-db", "-U", "pdns", "-d", "pdns", "-Atc", "SELECT 1", check=False).returncode == 0

            require(authenticate(current) and not authenticate(pending), "Initial database authentication differs from protected Fleet state.")
            # The previous generated -c command did not expand psql variables.
            old_sql = dc("exec", "-T", "pdns-db", "psql", "-U", "pdns", "-v", "next_password="+pending,
                         "-c", "ALTER ROLE pdns PASSWORD :'next_password';", check=False)
            require(old_sql.returncode != 0 and 'syntax error at or near ":"' in old_sql.stderr,
                    "Legacy SQL failure was not reproduced.")
            with (bundle / ".pdns-rotation.lock").open("a") as lock:
                fcntl.flock(lock.fileno(), fcntl.LOCK_EX | fcntl.LOCK_NB)
                locked = command("sh", "reconcile-pdns-password.sh", check=False)
                require(locked.returncode != 0 and "Another PowerDNS rotation" in locked.stderr,
                        "Concurrent rotation did not fail before effects.")
            original_config = config.read_text()
            config.write_text(original_config+"gpgsql-password=duplicate\n")
            invalid = command("sh", "reconcile-pdns-password.sh", check=False)
            require(invalid.returncode != 0 and authenticate(current) and not authenticate(pending),
                    "Invalid local configuration changed the database password.")
            config.write_text(original_config)
            started = time.monotonic()
            command("sh", "reconcile-pdns-password.sh")
            require(dc("ps", "-q", "pdns-db").stdout.strip() == database_container,
                    "Rotation unexpectedly recreated the database dependency.")
            require(authenticate(pending) and not authenticate(current), "Rotation did not replace the database password.")
            require(stat.S_IMODE(config.stat().st_mode) == 0o640 and config.stat().st_gid == 82,
                    "Rotation lost restricted container read permissions.")
            dc("exec", "-T", "pdns-auth", "pdns_control", "rping")
            backup = (bundle / ".env.prod.before-pdns-rotation").read_bytes()
            command("sh", "reconcile-pdns-password.sh")
            require(authenticate(pending) and not authenticate(current), "Repeated rotation changed the intended password.")
            require((bundle / ".env.prod.before-pdns-rotation").read_bytes() == backup, "Retry overwrote recovery material.")
            # Reproduce interruption after ALTER ROLE and before file activation.
            next_value = secrets.token_hex(32)
            (bundle / "secrets/pdns-db-password.next").write_text(next_value+"\n")
            dc("exec", "-T", "pdns-db", "psql", "-U", "pdns", "-v", "next_password="+next_value,
               input="ALTER ROLE pdns PASSWORD :'next_password';\n")
            require(authenticate(next_value) and not authenticate(pending), "Interrupted rotation fixture did not apply.")
            command("sh", "reconcile-pdns-password.sh")
            require(authenticate(next_value) and "gpgsql-password="+next_value in config.read_text(),
                    "Retry after database-only activation did not repair consumers.")
            require(dc("ps", "-q", "pdns-db").stdout.strip() == database_container,
                    "Interrupted-rotation recovery recreated the database dependency.")
            dc("exec", "-T", "pdns-auth", "pdns_control", "rping")
            print(json.dumps({"environment": project, "images": images, "secret_permissions": "passed", "legacy_sql_failure": "reproduced", "lock_and_validation": "passed", "rotation_and_retry": "passed", "interrupted_rotation_recovery": "passed", "rotation_seconds": round(time.monotonic()-started, 3)}))
        finally:
            dc("down", "--remove-orphans", check=False)


if __name__ == "__main__":
    main()
