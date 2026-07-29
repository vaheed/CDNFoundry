#!/usr/bin/env python3
"""Run and record the bounded final non-browser production qualification."""

from __future__ import annotations

import argparse
import datetime
import json
import os
import pathlib
import platform
import shlex
import subprocess
import sys
import time
from dataclasses import asdict, dataclass

ROOT = pathlib.Path(__file__).resolve().parents[2]
DEFAULT_REPORT = ROOT / "storage" / "qualification" / "production-qualification.json"


@dataclass(frozen=True)
class Check:
    identifier: str
    description: str
    command: tuple[str, ...] | None
    owner: str
    requirement: str | None = None


@dataclass
class Result:
    identifier: str
    description: str
    owner: str
    status: str
    started_at: str | None
    duration_seconds: float | None
    command: str | None
    log: str | None
    reason: str | None


CHECKS = (
    Check("contracts", "Compose, production overrides, OpenAPI, and documentation contracts",
          ("make", "config-check", "openapi-check", "docs-check"), "agent"),
    Check("application", "Isolated Laravel unit and feature suite",
          ("make", "dev-test"), "agent"),
    Check("go-runtime", "Gateway and agent formatting, vet, tests, and builds",
          ("bash", "scripts/qualification/test-go.sh"), "agent"),
    Check("gateway", "Real Host/SNI gateway, invalid candidate, restart, and last-valid behavior",
          ("make", "dev-gateway-e2e"), "agent"),
    Check("cells", "Eight-slot inventory, isolation, restart, bounds, and overhead",
          ("python3", "tests/e2e/cell_inventory.py"), "agent"),
    Check("runtime", "Cumulative DNS, TLS, cache, compression, origin, WAF, and outage runtime suite",
          ("make", "dev-e2e"), "agent"),
    Check("scale", "Bounded 20,000-domain and 10,000-change qualification",
          ("make", "dev-scale-e2e"), "agent"),
    Check("recovery", "Encrypted backup, clean replacement database, and derived-state reconciliation",
          ("make", "dev-phase8-recovery-e2e"), "agent"),
    Check("upgrade", "Mixed-version application and agent rollback compatibility",
          ("make", "dev-phase8-upgrade-e2e"), "agent"),
    Check("throughput", "Measured single-cell HTTP and HTTPS throughput",
          ("make", "dev-phase8-throughput-e2e"), "agent"),
    Check("geo-provider", "MMDB provider outage and last-valid database retention",
          ("make", "dev-phase8-mmdb-e2e"), "agent"),
    Check("external-ip", "Real public IPv4 and IPv6 traffic", None, "owner",
          "CDNF_QUALIFY_EXTERNAL_IP_EVIDENCE=/absolute/path/to/sanitized-evidence"),
    Check("external-anycast", "Approved routing-environment Anycast convergence and withdrawal", None, "owner",
          "CDNF_QUALIFY_ANYCAST_EVIDENCE=/absolute/path/to/sanitized-evidence"),
    Check("external-load", "Two-POP saturation and unrelated-pool isolation measurements", None, "owner",
          "CDNF_QUALIFY_EXTERNAL_LOAD_EVIDENCE=/absolute/path/to/sanitized-evidence"),
    Check("fleet-installer", "Fixed-purpose multi-POP canary installer, pause, and rollback", None, "owner",
          "CDNF_QUALIFY_FLEET_INSTALLER_EVIDENCE=/absolute/path/to/sanitized-evidence"),
    Check("browser", "Owner-run browser qualification checklist", None, "owner",
          "CDNF_QUALIFY_BROWSER_EVIDENCE=/absolute/path/to/sanitized-evidence"),
)


def timestamp() -> str:
    return datetime.datetime.now(datetime.UTC).replace(microsecond=0).isoformat()


def git(*args: str) -> str:
    return subprocess.run(
        ("git", *args), cwd=ROOT, text=True, capture_output=True, check=True,
    ).stdout.strip()


def parser() -> argparse.ArgumentParser:
    value = argparse.ArgumentParser(description=__doc__)
    value.add_argument("--output", type=pathlib.Path, default=DEFAULT_REPORT)
    value.add_argument("--only", action="append", default=[],
                       help="run only this agent check identifier; repeatable")
    value.add_argument("--list", action="store_true", help="list checks without running them")
    value.add_argument("--continue-on-failure", action="store_true")
    return value


def selected_checks(only: list[str]) -> tuple[Check, ...]:
    known = {check.identifier for check in CHECKS}
    unknown = set(only) - known
    if unknown:
        raise SystemExit(f"unknown qualification check(s): {', '.join(sorted(unknown))}")
    return tuple(check for check in CHECKS if not only or check.identifier in only)


def owner_result(check: Check) -> Result:
    variable = check.requirement.split("=", 1)[0] if check.requirement else ""
    evidence = pathlib.Path(os.environ.get(variable, ""))
    passed = evidence.is_absolute() and evidence.is_file() and evidence.stat().st_size > 0
    return Result(
        check.identifier, check.description, check.owner,
        "passed" if passed else "not_run", None, None, None,
        str(evidence) if passed else None,
        None if passed else f"owner evidence absent; set {check.requirement} only after recording it",
    )


def run_check(check: Check, log_directory: pathlib.Path) -> Result:
    assert check.command is not None
    started_at = timestamp()
    started = time.monotonic()
    log = log_directory / f"{check.identifier}.log"
    with log.open("w", encoding="utf-8") as output:
        process = subprocess.run(
            check.command, cwd=ROOT, text=True, stdout=output,
            stderr=subprocess.STDOUT, check=False,
        )
    return Result(
        check.identifier, check.description, check.owner,
        "passed" if process.returncode == 0 else "failed",
        started_at, round(time.monotonic() - started, 3),
        shlex.join(check.command), str(log),
        None if process.returncode == 0 else f"command exited {process.returncode}",
    )


def main() -> int:
    arguments = parser().parse_args()
    checks = selected_checks(arguments.only)
    if arguments.list:
        for check in checks:
            print(f"{check.identifier:20} {check.owner:5} {check.description}")
        return 0

    report_path = arguments.output.resolve()
    report_path.parent.mkdir(parents=True, exist_ok=True)
    log_directory = report_path.with_suffix("")
    log_directory.mkdir(parents=True, exist_ok=True)
    started_at = timestamp()
    results: list[Result] = []

    for check in checks:
        if check.owner == "owner":
            result = owner_result(check)
        else:
            print(f"[{check.identifier}] {check.description}", flush=True)
            result = run_check(check, log_directory)
        results.append(result)
        print(f"[{check.identifier}] {result.status}", flush=True)
        if result.status == "failed" and not arguments.continue_on_failure:
            break

    selected_ids = {check.identifier for check in checks}
    for check in CHECKS:
        if check.identifier not in selected_ids:
            results.append(Result(
                check.identifier, check.description, check.owner, "not_run",
                None, None, None, None, "not selected for this run",
            ))

    statuses = {result.status for result in results}
    decision = "passed" if statuses == {"passed"} else (
        "failed" if "failed" in statuses else "blocked"
    )
    report = {
        "schema": 1,
        "kind": "cdnfoundry-production-qualification",
        "started_at": started_at,
        "completed_at": timestamp(),
        "commit": git("rev-parse", "HEAD"),
        "working_tree_clean": not bool(git("status", "--short")),
        "host": {
            "system": platform.system(),
            "release": platform.release(),
            "machine": platform.machine(),
            "cpu_count": os.cpu_count(),
        },
        "release_decision": decision,
        "results": [asdict(result) for result in results],
    }
    report_path.write_text(json.dumps(report, indent=2, sort_keys=True) + "\n", encoding="utf-8")
    print(f"qualification report: {report_path}")
    print(f"release decision: {decision}")
    return 0 if decision == "passed" else 1


if __name__ == "__main__":
    sys.exit(main())
