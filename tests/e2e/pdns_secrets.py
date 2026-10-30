#!/usr/bin/env python3
"""The production PowerDNS start wrapper must never run with development secrets."""
import subprocess
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
IMAGE = "powerdns/pdns-auth-51:5.1.4@sha256:bb5b1c133bcca1dd455075321de7d55db4945a8d7f2ba23339e3c7bbe416b205"


def run_wrapper(environment: dict[str, str], script: str, config: Path | None = None) -> subprocess.CompletedProcess:
    command = ["docker", "run", "--rm", "--network", "none", "--entrypoint", "sh",
               "-v", f"{config or ROOT / 'docker/pdns/pdns.conf'}:/etc/powerdns/pdns.conf:ro",
               "-v", f"{ROOT}/docker/pdns/production-start.sh:/usr/local/bin/pdns-production-start:ro",
               "-v", f"{ROOT}/docker/pdns/geoip-zones.yml:/etc/powerdns/geoip-zones.yml:ro"]
    for key, value in environment.items():
        command += ["-e", f"{key}={value}"]
    return subprocess.run(command + [IMAGE, "-c", script], text=True, capture_output=True, timeout=120)


def main() -> None:
    start = "/usr/local/bin/pdns-production-start --version >/dev/null 2>&1; echo exit=$?; cat /tmp/pdns/pdns.conf 2>/dev/null"
    refused = run_wrapper({}, "/usr/local/bin/pdns-production-start --version; echo exit=$?")
    assert "exit=78" in refused.stdout and "development value" in refused.stderr, refused
    development = run_wrapper({"PDNS_DB_PASSWORD": "pdns-dev-only", "PDNS_API_KEY": "pdns-dev-api-key"}, "/usr/local/bin/pdns-production-start --version; echo exit=$?")
    assert "exit=78" in development.stdout, development
    half = run_wrapper({"PDNS_DB_PASSWORD": "unique-db-secret"}, "/usr/local/bin/pdns-production-start --version; echo exit=$?")
    assert "exit=78" in half.stdout and "api-key" in half.stderr, half
    rendered = run_wrapper({"PDNS_DB_PASSWORD": "unique-db-secret", "PDNS_API_KEY": "unique=api&key/secret"}, start)
    assert "exit=0" in rendered.stdout, rendered
    assert "gpgsql-password=unique-db-secret" in rendered.stdout and "api-key=unique=api&key/secret" in rendered.stdout
    assert "pdns-dev" not in rendered.stdout, "a development secret survived rendering"
    print("pdns_secrets: PASS")


if __name__ == "__main__":
    main()
