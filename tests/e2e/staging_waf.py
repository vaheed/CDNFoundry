#!/usr/bin/env python3
"""Qualify managed WAF profiles on one owned staging domain, then restore state."""

import argparse
import json
from pathlib import Path
import time
import urllib.error
import urllib.request
import uuid

from staging_traffic import EdgeHTTPS


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--control", required=True)
    parser.add_argument("--credentials", type=Path, required=True)
    parser.add_argument("--domain-record", type=Path, required=True)
    parser.add_argument("--edge", action="append", required=True)
    parser.add_argument("--report", type=Path, required=True)
    args = parser.parse_args()
    if not args.control.startswith("https://") or len(args.edge) != 2 or len(set(args.edge)) != 2:
        parser.error("Use one HTTPS control endpoint and two distinct edge addresses")
    domain = json.loads(args.domain_record.read_text())
    credentials = json.loads(args.credentials.read_text())
    token = None
    original = None
    checks = []
    restoration = "not_attempted"

    def api(method, path, payload=None):
        headers = {"Accept": "application/json"}
        if token is not None:
            headers["Authorization"] = "Bearer " + token
        if payload is not None:
            headers["Content-Type"] = "application/json"
        if method == "PATCH":
            headers["Idempotency-Key"] = str(uuid.uuid4())
        request = urllib.request.Request(
            args.control.rstrip("/") + "/api" + path,
            headers=headers,
            data=json.dumps(payload).encode() if payload is not None else None,
            method=method,
        )
        with urllib.request.urlopen(request, timeout=20) as response:
            return json.load(response)["data"]

    def await_deployment(operation_id):
        deadline = time.monotonic() + 180
        while time.monotonic() < deadline:
            operation = api("GET", "/admin/operations/" + operation_id)
            deployment = api("GET", "/domains/" + str(domain["id"]) + "/deployment")
            if operation["status"] in ("failed", "cancelled", "obsolete"):
                raise RuntimeError("WAF reconciliation failed")
            if operation["status"] in ("succeeded", "completed") and deployment["active_revision"] == deployment["desired_revision"]:
                return
            time.sleep(3)
        raise TimeoutError("WAF deployment did not converge")

    def set_profile(profile):
        result = api("PATCH", "/domains/" + str(domain["id"]) + "/waf", {"profile": profile})
        await_deployment(result["operation_id"])

    def probe(edge, expected):
        connection = EdgeHTTPS(domain["name"], edge)
        try:
            connection.request("GET", "/?q=%3Cscript%3Ealert(1)%3C/script%3E", headers={"Accept-Encoding": "identity"})
            response = connection.getresponse()
            response.read(65536)
            checks.append({"profile": profile, "edge": edge, "status": response.status})
            if expected is not None and response.status != expected:
                raise RuntimeError("Unexpected WAF response status")
            return response.status
        finally:
            connection.close()

    outcome = "failed"
    try:
        token = api("POST", "/auth/login", {
            "email": credentials["email"], "password": credentials["password"],
            "device_name": "staging-waf-qualification",
        })["token"]
        original = api("GET", "/domains/" + str(domain["id"]) + "/waf")["name"]
        clean_status = {}
        for profile in ("off", "monitor", "balanced"):
            if profile != original or profile != "off":
                set_profile(profile)
            for edge in args.edge:
                expected = None if profile == "off" else (403 if profile == "balanced" else clean_status[edge])
                status = probe(edge, expected)
                if profile == "off":
                    if not 200 <= status < 400:
                        raise RuntimeError("Unprotected baseline did not serve")
                    clean_status[edge] = status
        outcome = "passed"
    finally:
        try:
            if token is not None and original is not None:
                if api("GET", "/domains/" + str(domain["id"]) + "/waf")["name"] != original:
                    set_profile(original)
                restoration = "passed"
        finally:
            try:
                if token is not None:
                    api("POST", "/auth/logout")
            finally:
                args.report.parent.mkdir(parents=True, exist_ok=True)
                args.report.write_text(json.dumps({"outcome": outcome, "restoration": restoration, "checks": checks}, indent=2) + "\n")
    print("staging WAF qualification", outcome)
    return 0 if outcome == "passed" else 1


if __name__ == "__main__":
    raise SystemExit(main())
