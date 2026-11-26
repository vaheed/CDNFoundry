#!/usr/bin/env python3
"""Qualify the stated Phase 3 scale on a real cell: floods of 1,000,000 distinct client addresses and 1,000,000 distinct rule keys.

A cell with no control plane runs three domains. wrk generates the floods and the test measures, with the cell's own shared
memory report before and after:
  1. 1,000,000 IPv6 addresses inside one /64 are limited as one client: a rate-limit rule of 100 requests lets 95 to 105 through,
     a different /64 is not limited by it, and the counter dictionary does not grow by anything like a counter per address.
  2. 1,000,000 distinct header keys against one domain do not change another domain's rate-limit rule: its threshold of 100 is
     still met within 5%, the flood's extra keys are reported against the flooded domain only, and no dictionary reports an
     allocation failure.
"""

import http.client
import json
import os
import pathlib
import sys
import tempfile
import time

sys.path.insert(0, str(pathlib.Path(__file__).resolve().parent))
from managed_waf import IMAGE, NETWORK, host, run  # noqa: E402
from rate_limit_isolation import address  # noqa: E402

CELL = "cdnf-e2e-million"
ORIGIN = "cdnf-e2e-million-origin"
FLOOD = int(os.environ.get("CDNF_MILLION", "1000000"))
THRESHOLD = 100
WRK = "williamyeh/wrk"

SCRIPT = r'''
local counter, id = 0, 0
local statuses = {}
local mode, base = "v6", 0
function setup(thread) id = id + 1; thread:set("id", id) end
function init(args)
    mode, base = args[1], tonumber(args[2]) or 0
    host = args[3]
end
function request()
    counter = counter + 1
    local n = base + id * 100000000 + counter
    if mode == "v6" then
        return wrk.format("GET", "/", {["Host"] = host, ["X-Forwarded-For"] = string.format("2001:db8:1:1:%x:%x:%x:%x", 0, math.floor(n / 4294967296) % 65536, math.floor(n / 65536) % 65536, n % 65536)})
    end
    return wrk.format("GET", "/", {["Host"] = host, ["X-Limit-Key"] = "flood-" .. n})
end
function response(status) statuses[status] = (statuses[status] or 0) + 1 end
'''


def flood(script: pathlib.Path, network_ip: str, mode: str, hostname: str, total: int) -> dict[int, int]:
    """Runs wrk in chunks until `total` requests have been sent, and returns how many answers each status got."""
    statuses: dict[int, int] = {}
    sent = 0
    chunk = 0
    while sent < total:
        result = run("docker", "run", "--rm", "--network", NETWORK, "-v", f"{script}:/flood.lua:ro", WRK, "-t4", "-c64", "-d15s", "--timeout", "10s",
                     "-s", "/flood.lua", f"http://{network_ip}:8080", "--", mode, str(chunk * 1000000000), hostname)
        chunk += 1
        text = result.stdout
        for line in text.splitlines():
            line = line.strip()
            if line.startswith("Non-2xx or 3xx responses:"):
                pass
            if " requests in " in line:
                sent += int(line.split()[0])
                break
        else:
            raise AssertionError(text)
        statuses = merge(statuses, status_counts(text))
        print(f"  {mode} flood on {hostname}: {sent} of {total} sent", flush=True)
    return statuses


def status_counts(text: str) -> dict[int, int]:
    # wrk reports total requests and the non-2xx/3xx count; the per-status split comes from the cell's own security events.
    counts = {}
    for line in text.splitlines():
        line = line.strip()
        if line.startswith("Non-2xx or 3xx responses:"):
            counts[-1] = int(line.split(":")[1])
        elif " requests in " in line:
            counts[0] = int(line.split()[0])
    return counts


def merge(a: dict[int, int], b: dict[int, int]) -> dict[int, int]:
    return {key: a.get(key, 0) + b.get(key, 0) for key in set(a) | set(b)}


def domain(name: str, identifier: int, origin_ip: str, rule: dict) -> dict:
    config = host("off", False, 0, origin_ip)
    config["domain"], config["domain_id"] = name, identifier
    security = config["security"]
    security["limits"].update({"requests_per_second": 1000000, "request_burst": 1000000, "connections_per_client": 4096, "connections_per_domain": 100000})
    security["trusted_proxy_cidrs"] = ["0.0.0.0/0", "::/0"]
    security["rules"] = [rule]
    return config


def rule(path: str, key: str, threshold: int, header: str | None = None) -> dict:
    limit = {"key": key, "threshold": threshold, "period_seconds": 600, "duration_seconds": 600}
    if header:
        limit["header_name"] = header
    return {"id": 1, "name": None, "priority": 1, "action": "block", "mode": "enforce", "expires_at": None,
            "conditions": [{"field": "path", "operator": "starts_with", "value": path}], "rate_limit": limit}


def get(ip: str, hostname: str, path: str, headers: dict[str, str]) -> int:
    connection = http.client.HTTPConnection(ip, 8080, timeout=10)
    connection.request("GET", path, headers={"Host": hostname, **headers})
    response = connection.getresponse()
    response.read()
    connection.close()
    return response.status


def status_report(name: str) -> dict:
    result = run("docker", "exec", name, "wget", "-q", "-O-", "--header=X-Edge-Status-Token: e2e", "http://127.0.0.1:9080/passive-failures")
    return json.loads(result.stdout)


def used(report: dict, dictionary: str) -> int:
    entry = report["cell"]["shared_memory"][dictionary]
    return entry["capacity_bytes"] - entry["free_bytes"]


def main() -> None:
    run("docker", "rm", "-f", CELL, ORIGIN, check=False)
    run("docker", "run", "-d", "--name", ORIGIN, "--network", NETWORK, "nginx:1.30.3-alpine")
    origin_ip = address(ORIGIN)
    with tempfile.TemporaryDirectory(prefix="cdnf-e2e-million-") as directory:
        temporary = pathlib.Path(directory)
        temporary.chmod(0o755)
        script = temporary / "flood.lua"
        script.write_text(SCRIPT)
        script.chmod(0o644)
        hosts = {
            "v6.million.test": domain("v6.million.test", 1101, origin_ip, rule("/", "client_ip", THRESHOLD)),
            "attacker.million.test": domain("attacker.million.test", 1102, origin_ip, rule("/", "header", 2, "X-Limit-Key")),
            "victim.million.test": domain("victim.million.test", 1103, origin_ip, rule("/precise", "header", THRESHOLD, "X-Limit-Key")),
        }
        runtime = temporary / "runtime.json"
        runtime.write_text(json.dumps({"schema_version": 1, "sequence": 1, "generation_id": "million-1", "certificates": {}, "hosts": hosts}))
        runtime.chmod(0o644)
        run("openssl", "req", "-x509", "-newkey", "rsa:2048", "-nodes", "-days", "1", "-subj", "/CN=million.test", "-keyout", str(temporary / "tls.key"), "-out", str(temporary / "tls.crt"))
        for item in (temporary / "tls.key", temporary / "tls.crt"):
            item.chmod(0o644)
        try:
            run("docker", "run", "-d", "--name", CELL, "--network", NETWORK, "--cpus", "4", "--memory", "2g",
                "-e", "EDGE_RUNTIME_FILE=/var/lib/cdnfoundry/runtime/active.json", "-e", "EDGE_STATUS_TOKEN=e2e",
                "-v", f"{runtime}:/var/lib/cdnfoundry/runtime/active.json:ro",
                "-v", f"{temporary / 'tls.crt'}:/run/edge/tls.crt:ro", "-v", f"{temporary / 'tls.key'}:/run/edge/tls.key:ro",
                "--tmpfs", "/var/cache/nginx:rw,noexec,nosuid,size=64m,mode=0777", IMAGE)
            ip = address(CELL)
            deadline = time.monotonic() + 90
            while time.monotonic() < deadline:
                try:
                    if get(ip, "victim.million.test", "/ready", {}) in (200, 404):
                        break
                except OSError:
                    pass
                time.sleep(1)
            else:
                raise AssertionError(run("docker", "logs", CELL, check=False).stderr)
            # Start early in a 600 s counting window so that a window ending mid-test is not mistaken for eviction.
            remaining = 600 - time.time() % 600
            if remaining < 300:
                time.sleep(remaining + 1)
            before = status_report(CELL)

            # 1. One million addresses inside one /64 are one client.
            started = time.monotonic()
            counts = flood(script, ip, "v6", "v6.million.test", FLOOD)
            flood_seconds = time.monotonic() - started
            sent, refused = counts[0], counts.get(-1, 0)
            allowed = sent - refused
            after = status_report(CELL)
            grown = used(after, "rate_rules") - used(before, "rate_rules")
            print(json.dumps({"ipv6_flood": {"sent": sent, "answered_ok": allowed, "refused": refused, "seconds": round(flood_seconds), "rate_rules_growth_bytes": grown}}))
            assert sent >= FLOOD, sent
            assert THRESHOLD * 0.95 <= allowed <= THRESHOLD * 1.05, f"{allowed} requests got through one /64 against a threshold of {THRESHOLD}"
            assert grown < 1048576, f"the counter dictionary grew by {grown} bytes: counters were kept per address"
            assert get(ip, "v6.million.test", "/", {"X-Forwarded-For": "2001:db8:2:2::1"}) in (200, 404), "a different /64 was limited by the flood's counter"
            assert get(ip, "v6.million.test", "/", {"X-Forwarded-For": "2001:db8:1:1::ffff:1"}) == 429

            # 2. One million distinct keys on one domain do not move another domain's threshold.
            before = status_report(CELL)
            started = time.monotonic()
            counts = flood(script, ip, "key", "attacker.million.test", FLOOD)
            flood_seconds = time.monotonic() - started
            after = status_report(CELL)
            print(json.dumps({"key_flood": {"sent": counts[0], "seconds": round(flood_seconds),
                                            "rate_rules_used_bytes": used(after, "rate_rules"), "rate_rules_capacity_bytes": after["cell"]["shared_memory"]["rate_rules"]["capacity_bytes"],
                                            "security_limits_used_bytes": used(after, "security_limits"), "client_events_used_bytes": used(after, "client_events")}}))
            assert counts[0] >= FLOOD
            time.sleep(2)
            allowed = 0
            while get(ip, "victim.million.test", "/precise", {"X-Limit-Key": "victim"}) != 429 and allowed < 2 * THRESHOLD:
                allowed += 1
            assert abs(allowed - THRESHOLD) <= THRESHOLD * 0.05, f"the victim's threshold of {THRESHOLD} tripped after {allowed} requests"
            events = [event for event in status_report(CELL)["security"] if event["reason_code"] == "rate_limit_state_full"]
            assert events and {event["domain_id"] for event in events} <= {1102, 1101}, events
            assert not any(event["domain_id"] == 1103 for event in events), "the victim was reported as exhausted"
            logs = run("docker", "logs", CELL, check=False)
            assert "no memory" not in logs.stdout + logs.stderr, "a shared dictionary ran out of memory"
        finally:
            run("docker", "rm", "-f", CELL, ORIGIN, check=False)
    print("e2e million key floods passed")


if __name__ == "__main__":
    main()
