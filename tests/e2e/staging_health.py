#!/usr/bin/env python3
"""Read-only public staging health and authoritative DNS probes; no browser access.

This is a focused installation check, not the full staging or production gate.
It never creates application data, runs migrations, or changes host services.
"""

from __future__ import annotations

import argparse
import ipaddress
import json
import os
import re
import subprocess
import tempfile
import urllib.error
import urllib.parse
import urllib.request
from datetime import datetime, timezone
from pathlib import Path


def https_origin(value: str) -> str:
    parsed = urllib.parse.urlsplit(value)
    if (
        parsed.scheme != "https"
        or not parsed.hostname
        or parsed.username is not None
        or parsed.password is not None
        or parsed.path not in {"", "/"}
        or parsed.query
        or parsed.fragment
    ):
        raise argparse.ArgumentTypeError("Use an HTTPS origin without credentials, path or query")
    return value.rstrip("/")


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        return None


def check_http(url: str, expected: int) -> dict:
    opener = urllib.request.build_opener(NoRedirect())
    request = urllib.request.Request(url, headers={"Accept": "application/json"})
    try:
        response = opener.open(request, timeout=20)
    except urllib.error.HTTPError as error:
        response = error
    with response:
        status = response.code
        raw = response.read(65537)
        if len(raw) > 65536:
            raise ValueError("Response exceeded 64 KiB")
        if status != expected:
            raise ValueError(f"Expected HTTP {expected}, observed {status}")
        if not isinstance(json.loads(raw), dict):
            raise ValueError("Expected a JSON object")
    return {"status": status, "tls_verified": True}


def check_dns(server: str, zone: str, tcp: bool) -> dict:
    result = subprocess.run(
        ["dig", "@" + server, zone, "SOA", "+time=3", "+tries=1", "+noall",
         "+comments", "+answer", "+ignore", "+tcp" if tcp else "+notcp"],
        capture_output=True, text=True, timeout=10, check=True,
    )
    header = next((line for line in result.stdout.splitlines() if "flags:" in line), "")
    flags = header.split("flags:", 1)[-1].split(";", 1)[0].split()
    if "status: NOERROR" not in result.stdout or "aa" not in flags or "tc" in flags:
        raise ValueError("Expected an authoritative, untruncated NOERROR response")
    answers = [line.split() for line in result.stdout.splitlines() if line and not line.startswith(";")]
    soa = [row for row in answers if len(row) == 11 and row[3] == "SOA"]
    if len(soa) != 1 or soa[0][0].lower().rstrip(".") != zone:
        raise ValueError("Expected one SOA for the requested zone")
    return {"server": server, "transport": "tcp" if tcp else "udp", "serial": int(soa[0][6])}


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--control", type=https_origin, required=True)
    parser.add_argument("--grafana", type=https_origin, required=True)
    parser.add_argument("--zone")
    parser.add_argument("--dns-server", action="append", default=[], type=ipaddress.ip_address)
    parser.add_argument("--report", type=Path, required=True)
    args = parser.parse_args()
    if bool(args.zone) != bool(args.dns_server) or len(args.dns_server) > 8:
        parser.error("Supply a zone and 1–8 DNS servers together")
    zone = (args.zone or "").lower().rstrip(".")
    if zone and not re.fullmatch(r"(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}", zone):
        parser.error("Use a canonical public DNS zone")
    checks = []

    def record(name, operation):
        try:
            checks.append({"check": name, "outcome": "passed", "actual": operation()})
        except Exception as error:
            # Exception text and response bodies may contain deployment details.
            checks.append({"check": name, "outcome": "failed", "error_type": type(error).__name__})
        print(name, checks[-1]["outcome"], flush=True)

    for path in ["/api/health", "/api/ready"]:
        record(path, lambda path=path: check_http(args.control + path, 200))
    record("grafana-health", lambda: check_http(args.grafana + "/api/health", 200))
    record("admin-requires-auth", lambda: check_http(args.control + "/api/admin/system/health", 401))
    for server in args.dns_server:
        for tcp in [False, True]:
            record(f"dns-{server}-{'tcp' if tcp else 'udp'}", lambda server=server, tcp=tcp: check_dns(str(server), zone, tcp))
    dns = [row for row in checks if row["check"].startswith("dns-")]
    if dns and all(row["outcome"] == "passed" for row in dns):
        checks.append({"check": "dns-serial-parity", "outcome": "passed" if len({row["actual"]["serial"] for row in dns}) == 1 else "failed"})
    passed = all(row["outcome"] == "passed" for row in checks)
    report = {"scope": "public health/authentication and optional authoritative SOA only",
              "recorded_at": datetime.now(timezone.utc).isoformat(), "outcome": "passed" if passed else "failed", "checks": checks}
    args.report.parent.mkdir(parents=True, exist_ok=True)
    descriptor, candidate = tempfile.mkstemp(prefix=".staging-health-", dir=args.report.parent)
    try:
        with os.fdopen(descriptor, "w") as stream:
            json.dump(report, stream, indent=2)
            stream.write("\n")
        os.replace(candidate, args.report)
    finally:
        Path(candidate).unlink(missing_ok=True)
    return 0 if passed else 1


if __name__ == "__main__":
    raise SystemExit(main())
