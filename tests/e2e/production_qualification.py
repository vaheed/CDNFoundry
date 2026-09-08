#!/usr/bin/env python3
"""Run and record the bounded final non-browser production qualification."""

from __future__ import annotations

import argparse
import datetime
import hashlib
import re
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
    evidence_kind: str | None = None
    exit_code: int | None = None


CHECKS = (
    Check("origin-probes", "Isolated real DNS, HTTP/TLS, destination safety and required IPv6 origin probes",
          ("python3", "tests/e2e/origin_probes.py"), "agent"),
    Check("postgres-edge-tasks", "Isolated PostgreSQL task receipt and aggregate concurrency",
          ("python3", "tests/e2e/postgres_edge_tasks.py"), "agent"),
    Check("fleet-pdns", "Generated PowerDNS credential permissions, rotation and interrupted-rotation recovery",
          ("python3", "tests/e2e/fleet_pdns.py"), "agent"),
    Check("postgres-claims", "Isolated PostgreSQL domain claim concurrency, constraints and reclaim",
          ("python3", "tests/e2e/postgres_domain_claims.py"), "agent"),
    Check("parent-delegation", "Real BIND signed parent referrals, stale delegation, bogus DNSSEC and child-zone rejection",
          ("python3", "tests/e2e/parent_delegation.py"), "agent"),
    Check("production-dependencies", "Immutable production Compose dependency scans with High/Critical/EOL enforcement",
          ("python3", "scripts/supply-chain-policy.py", "--scan-production-dependencies"), "agent"),
    Check("fleet", "Fleet configuration, PKI, bundle generation and failure regressions",
          ("python3", "-m", "pytest", "-q", "tests/fleet/test_fleet.py"), "agent"),
    Check("qualification-tools", "Negative supply-chain and qualification-evidence fixtures",
          ("make", "qualification-tools-check"), "agent"),
    Check("production-observability", "Generated production bundle, authenticated metrics, discovery, and Grafana datasources",
          ("python3", "tests/e2e/production_observability.py", "--build-images"), "agent"),
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
    value.add_argument("--environment-id", default=os.environ.get("CDNF_QUALIFY_ENVIRONMENT_ID"),
                       help="stable identity of the tested topology, required for owner evidence")
    value.add_argument("--output", type=pathlib.Path, default=DEFAULT_REPORT)
    value.add_argument("--only", action="append", default=[],
                       help="run only this agent check identifier; repeatable")
    value.add_argument("--source-identity", action="store_true", help="print the source identity for attributable evidence")
    value.add_argument("--list", action="store_true", help="list checks without running them")
    value.add_argument("--continue-on-failure", action="store_true")
    return value


def selected_checks(only: list[str]) -> tuple[Check, ...]:
    known = {check.identifier for check in CHECKS}
    unknown = set(only) - known
    if unknown:
        raise SystemExit(f"unknown qualification check(s): {', '.join(sorted(unknown))}")
    return tuple(check for check in CHECKS if not only or check.identifier in only)


def source_identity() -> dict[str, str]:
    """Bind evidence to actual source bytes, including untracked implementation."""
    digest = hashlib.sha256()
    paths = subprocess.check_output(
        ("git", "ls-files", "-z", "--cached", "--others", "--exclude-standard"), cwd=ROOT,
    ).split(b"\0")
    for raw in sorted(set(paths) - {b""}):
        path = ROOT / os.fsdecode(raw)
        digest.update(raw + b"\0")
        if path.is_symlink():
            digest.update(b"symlink\0" + os.fsencode(os.readlink(path)))
        elif path.is_file():
            digest.update(b"file\0" + hashlib.sha256(path.read_bytes()).digest())
        else:
            digest.update(b"deleted\0")
    return {"commit": git("rev-parse", "HEAD"), "source_sha256": digest.hexdigest()}


def validate_owner_evidence(document: object, check: Check, context: dict) -> dict:
    if not isinstance(document, dict) or document.get("schema") != 1:
        raise ValueError("expected an evidence object with schema 1")
    for key in ("check_id", "commit", "source_sha256", "environment_id"):
        expected = check.identifier if key == "check_id" else context.get(key)
        if not expected or document.get(key) != expected:
            raise ValueError(f"evidence {key} does not match this qualification")
    if document.get("kind") not in {"operator_attestation", "executed_check"}:
        raise ValueError("evidence kind must explicitly identify execution or operator attestation")
    for key in ("operator", "topology", "recorded_at"):
        if not isinstance(document.get(key), str) or not document[key].strip():
            raise ValueError(f"evidence {key} is required")
    recorded = datetime.datetime.fromisoformat(document["recorded_at"].replace("Z", "+00:00"))
    if recorded.tzinfo is None or recorded > datetime.datetime.now(datetime.UTC):
        raise ValueError("recorded_at must be a past timestamp with timezone")
    if document.get("outcome") not in {"passed", "failed", "blocked", "not_run"}:
        raise ValueError("invalid evidence outcome")
    steps = document.get("steps")
    if not isinstance(steps, list) or not 1 <= len(steps) <= 1000:
        raise ValueError("evidence requires 1..1000 recorded steps")
    for step in steps:
        if not isinstance(step, dict):
            raise ValueError("each step must be an object")
        for key in ("action", "expected", "actual"):
            if not isinstance(step.get(key), str) or not step[key].strip():
                raise ValueError(f"each step requires {key}")
        if step.get("outcome") not in {"passed", "failed", "blocked", "not_run"}:
            raise ValueError("invalid step outcome")
        if document["outcome"] == "passed" and step["outcome"] != "passed":
            raise ValueError("a nonpassing step cannot establish a pass")
        if document["kind"] == "executed_check":
            if type(step.get("exit_code")) is not int:
                raise ValueError("executed steps require an integer exit_code")
            if step["outcome"] == "passed" and step["exit_code"] != 0:
                raise ValueError("nonzero exit cannot establish a pass")
    images = document.get("images")
    if not isinstance(images, list) or not images or any(
        not isinstance(image, str) or not re.fullmatch(r"[^\s@]+@sha256:[0-9a-f]{64}", image)
        for image in images
    ):
        raise ValueError("evidence requires tested immutable image references")
    if not isinstance(document.get("measurements"), dict):
        raise ValueError("evidence requires measurements (empty only where inapplicable)")
    return document


def owner_result(check: Check, context: dict) -> Result:
    variable = check.requirement.split("=", 1)[0] if check.requirement else ""
    evidence = pathlib.Path(os.environ.get(variable, ""))
    try:
        if not evidence.is_absolute() or not evidence.is_file():
            raise ValueError(f"owner evidence absent; set {check.requirement} after recording it")
        if not 0 < evidence.stat().st_size <= 1024 * 1024:
            raise ValueError("evidence must be nonempty and at most 1 MiB")
        document = validate_owner_evidence(json.loads(evidence.read_text()), check, context)
    except (ValueError, OSError, TypeError) as error:
        return Result(check.identifier, check.description, check.owner, "not_run",
                      None, None, None, None, f"invalid or absent owner evidence: {error}")
    return Result(
        check.identifier, check.description, check.owner, document["outcome"],
        document["recorded_at"], None, None, str(evidence),
        f"{document['kind']} supplied by {document['operator']}; not executed by this runner",
        document["kind"],
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
        "executed_check", process.returncode,
    )


def main() -> int:
    arguments = parser().parse_args()
    if arguments.source_identity:
        print(json.dumps(source_identity(), sort_keys=True))
        return 0
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
    context = {**source_identity(), "environment_id": arguments.environment_id}

    for check in checks:
        if check.owner == "owner":
            result = owner_result(check, context)
        else:
            print(f"[{check.identifier}] {check.description}", flush=True)
            result = run_check(check, log_directory)
        results.append(result)
        print(f"[{check.identifier}] {result.status}", flush=True)
        if result.status == "failed" and not arguments.continue_on_failure:
            break

    selected_ids = {result.identifier for result in results}
    for check in CHECKS:
        if check.identifier not in selected_ids:
            results.append(Result(
                check.identifier, check.description, check.owner, "not_run",
                None, None, None, None, "not selected or stopped after an earlier failure",
            ))

    statuses = {result.status for result in results}
    decision = "passed" if statuses == {"passed"} else (
        "failed" if "failed" in statuses else "blocked"
    )
    report = {
        "schema": 2,
        "kind": "cdnfoundry-production-qualification",
        "started_at": started_at,
        "completed_at": timestamp(),
        **context,
        "source_unchanged_during_run": source_identity() == {k: context[k] for k in ("commit", "source_sha256")},
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
    if not report["source_unchanged_during_run"]:
        decision = "blocked" if decision != "failed" else decision
        report["release_decision"] = decision
    report_path.write_text(json.dumps(report, indent=2, sort_keys=True) + "\n", encoding="utf-8")
    print(f"qualification report: {report_path}")
    print(f"release decision: {decision}")
    return 0 if decision == "passed" else 1


if __name__ == "__main__":
    sys.exit(main())
