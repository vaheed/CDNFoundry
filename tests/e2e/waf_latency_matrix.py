#!/usr/bin/env python3
"""Latency and CPU matrix of the managed WAF and the request-path features, on one real OpenResty cell.

It drives ``edge_cell_benchmark.py`` over the Phase 1 matrix and writes one machine-readable record with the median of the repeats:

- WAF off, Observe (``monitor``), Recommended (``balanced``) and High (``strict``) with bodies of 0, 4 KiB, 64 KiB and 1 MiB;
- Recommended with 10 log-only rules, with 100 and 1,000 custom firewall rules, and with 20 rate-limit rules;
- each detection on its own (leaked-password check, sensitive-data scan, AI prompt signals) with the WAF off.

A body of 0 is a cached GET of a 10 KB object; every other body is a POST that bypasses the cache so the WAF reads the body. A 1 MiB
body is above the High inspection limit, so High refuses it by design; its status errors are recorded, not hidden.

    python3 tests/e2e/waf_latency_matrix.py --image cdnfoundry/edge-runtime:waf-qualification --output latency.json
    python3 tests/e2e/waf_latency_matrix.py --image ... --output quick.json --only balanced-body-0 --repeat 1 --duration 10
"""

from __future__ import annotations

import argparse
import datetime
import json
import pathlib
import statistics
import subprocess
import sys

ROOT = pathlib.Path(__file__).resolve().parents[2]
BENCHMARK = ROOT / "tests" / "e2e" / "edge_cell_benchmark.py"
BODY_SIZES = (0, 4096, 65536, 1048576)
FORM_PREFIX = len("field=")  # the benchmark sends "field=" + body, so the request body is body_bytes + 6
PROFILE_NAMES = {"off": "Off", "monitor": "Observe", "balanced": "Recommended", "strict": "High"}


def matrix() -> list[dict]:
    cases = []
    for waf in ("off", "monitor", "balanced", "strict"):
        for size in BODY_SIZES:
            cases.append({"name": f"{waf}-body-{size}", "waf": waf, "body": size, "group": "profile-by-body"})
    cases += [
        {"name": "balanced-log-only-10", "waf": "balanced", "body": 0, "group": "rules", "flags": ["--log-only-rules", "10"]},
        {"name": "balanced-custom-rules-100", "waf": "balanced", "body": 0, "group": "rules", "flags": ["--security-rules", "100"]},
        {"name": "balanced-custom-rules-1000", "waf": "balanced", "body": 0, "group": "rules", "flags": ["--security-rules", "1000"]},
        {"name": "balanced-rate-limit-rules-20", "waf": "balanced", "body": 0, "group": "rules", "flags": ["--rate-limit-rules", "20"]},
        {"name": "off-rate-limit-rules-20", "waf": "off", "body": 0, "group": "rules", "flags": ["--rate-limit-rules", "20"]},
        {"name": "off-credential-check", "waf": "off", "body": 64, "group": "detections", "detection": "credential_check", "path": "/login", "method": "POST"},
        {"name": "off-sensitive-data", "waf": "off", "body": 0, "group": "detections", "detection": "sensitive_data", "path": "/page.html", "method": "GET"},
        {"name": "off-ai-protection", "waf": "off", "body": 200, "group": "detections", "detection": "ai_protection", "path": "/v1/chat", "method": "POST"},
        {"name": "off-detections-control-post", "waf": "off", "body": 64, "group": "detections", "path": "/login", "method": "POST"},
    ]
    return cases


def command(case: dict, args: argparse.Namespace) -> list[str]:
    body = case["body"]
    posts = body > 0 and case.get("method", "POST") == "POST"
    base = [sys.executable, str(BENCHMARK), "--image", args.image, "--waf", case["waf"], "--cpus", str(args.cpus), "--connections", str(args.connections),
            "--duration", str(args.duration), "--repeat", str(args.repeat), "--warmup", str(args.warmup), "--label", case["name"], "--hostnames", "20"]
    if "detection" in case or "path" in case:
        base += ["--no-cache", "--path", case["path"], "--method", case["method"]]
        if "detection" in case:
            base += ["--detection", case["detection"]]
    elif posts:
        base += ["--no-cache", "--path", "/submit", "--method", "POST"]
    if body:
        base += ["--body-bytes", str(body if "detection" in case or "path" in case else max(1, body - FORM_PREFIX))]
    return base + case.get("flags", []) + (["--mmdb-volume", args.mmdb_volume] if args.mmdb_volume is not None else [])


def summarise(runs: list[dict]) -> dict:
    def median(key: str) -> float:
        return round(statistics.median(item[key] for item in runs), 2)

    return {
        "runs": len(runs), "requests_per_second": median("rps"), "p50_ms": median("p50_ms"), "p95_ms": median("p95_ms"), "p99_ms": median("p99_ms"),
        "cpu_us_per_request": median("cell_cpu_us_per_request"), "cell_cpu_cores": median("cell_cpu_cores"),
        "peak_rss_mib": max((item["cell_memory_peak_mib"] or 0) for item in runs), "errors": sum(item["errors"] for item in runs),
        "status_errors": sum(item["status_errors"] for item in runs), "throttled_periods": sum(item["cell_throttled_periods"] for item in runs),
        "request_count": sum(item["requests"] for item in runs),
        "cpu_us_per_request_runs": [item["cell_cpu_us_per_request"] for item in runs],
        "rps_runs": [item["rps"] for item in runs],
    }


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("--image", required=True)
    parser.add_argument("--output", required=True)
    parser.add_argument("--label", default="")
    parser.add_argument("--cpus", type=float, default=1.0)
    parser.add_argument("--connections", type=int, default=32)
    parser.add_argument("--duration", type=int, default=20)
    parser.add_argument("--repeat", type=int, default=3)
    parser.add_argument("--warmup", type=int, default=5)
    parser.add_argument("--only", nargs="*", help="case names to run")
    parser.add_argument("--mmdb-volume", default=None, help="Docker volume with GeoLite2-City.mmdb; empty for none (default: the benchmark's)")
    args = parser.parse_args()
    cases = [case for case in matrix() if not args.only or case["name"] in args.only]
    record: dict = {"record_version": 1, "kind": "waf-latency-benchmark", "label": args.label,
                    "created_at": datetime.datetime.now(datetime.timezone.utc).isoformat(timespec="seconds"),
                    "git": {"commit": subprocess.run(["git", "rev-parse", "HEAD"], cwd=ROOT, capture_output=True, text=True).stdout.strip(),
                            "dirty": bool(subprocess.run(["git", "status", "--porcelain"], cwd=ROOT, capture_output=True, text=True).stdout.strip())},
                    "method": {"cell_cpus": args.cpus, "connections": args.connections, "duration_s": args.duration, "repeats": args.repeat,
                               "warmup_s": args.warmup, "hostnames": 20, "object": "10 KB cached GET for body 0; POST with no cache otherwise",
                               "note": "development host; wrk in its own container on the same host. Medians of the repeats."},
                    "cases": {}}
    failures = 0
    for case in cases:
        result = subprocess.run(command(case, args), cwd=ROOT, text=True, capture_output=True, check=False)
        runs = [json.loads(line) for line in result.stdout.splitlines() if line.startswith("{")]
        if result.returncode or not runs:
            failures += 1
            record["cases"][case["name"]] = {"group": case["group"], "failed": True, "error": (result.stderr or result.stdout)[-1500:]}
            print(f"{case['name']}: FAILED", flush=True)
            continue
        record.setdefault("environment", runs[0]["environment"])
        record["cases"][case["name"]] = {"group": case["group"], "waf": PROFILE_NAMES[case["waf"]], "body_bytes": case["body"], **summarise(runs)}
        print(f"{case['name']}: {record['cases'][case['name']]['requests_per_second']} rps, {record['cases'][case['name']]['cpu_us_per_request']} us CPU/request, "
              f"p99 {record['cases'][case['name']]['p99_ms']} ms, errors {record['cases'][case['name']]['errors']}, status errors {record['cases'][case['name']]['status_errors']}", flush=True)
        pathlib.Path(args.output).write_text(json.dumps(record, indent=1, sort_keys=True) + "\n")
    pathlib.Path(args.output).write_text(json.dumps(record, indent=1, sort_keys=True) + "\n")
    return 1 if failures else 0


if __name__ == "__main__":
    sys.exit(main())
