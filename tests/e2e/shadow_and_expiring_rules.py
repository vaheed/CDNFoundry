#!/usr/bin/env python3
"""Real-runtime qualification of shadow and expiring rules on a cell that has no control plane at all.

A shadow rule must evaluate and record what it would have done without changing a response, and its would-have count must match
what the same rule counts as real blocks when it enforces. An expiring rule must stop acting within 2 seconds of its expiry by the
cell's own clock. A shadow rate-limit rule must count and record without ever limiting.
"""

import http.client
import json
import pathlib
import sys
import tempfile
import time

sys.path.insert(0, str(pathlib.Path(__file__).resolve().parent))
from managed_waf import IMAGE, NETWORK, host, run  # noqa: E402
from rate_limit_isolation import address  # noqa: E402

CELL = "cdnf-e2e-shadow-rules"
ORIGIN = "cdnf-e2e-shadow-origin"
# Kept below the per-client containment threshold: a client that is blocked often enough is contained, which is not what this compares.
REQUESTS = 90


def domain(name: str, identifier: int, origin_ip: str, rules: list[dict]) -> dict:
    config = host("off", False, 0, origin_ip)
    config["domain"], config["domain_id"] = name, identifier
    config["security"]["limits"].update({"requests_per_second": 100000, "request_burst": 100000, "connections_per_client": 4096, "connections_per_domain": 100000})
    config["security"]["rules"] = rules
    return config


def rule(identifier: int, action: str, mode: str, expires_at: int | None = None, rate: dict | None = None, prefix: str = "/blocked") -> dict:
    return {
        "id": identifier, "name": None, "priority": identifier, "action": action, "mode": mode, "expires_at": expires_at,
        "conditions": [{"field": "path", "operator": "starts_with", "value": prefix}], "rate_limit": rate,
    }


def get(ip: str, hostname: str, path: str) -> int:
    connection = http.client.HTTPConnection(ip, 8080, timeout=10)
    connection.request("GET", path, headers={"Host": hostname})
    response = connection.getresponse()
    response.read()
    connection.close()
    return response.status


def events(reason: str, domain_id: int) -> int:
    result = run("docker", "exec", CELL, "wget", "-q", "-O-", "--header=X-Edge-Status-Token: e2e", "http://127.0.0.1:9080/passive-failures")
    return sum(int(event["count"]) for event in json.loads(result.stdout)["security"] if event["reason_code"] == reason and event["domain_id"] == domain_id)


def main() -> None:
    run("docker", "rm", "-f", CELL, ORIGIN, check=False)
    run("docker", "run", "-d", "--name", ORIGIN, "--network", NETWORK, "nginx:1.30.3-alpine")
    origin_ip = address(ORIGIN)
    expires = int(time.time()) + 40
    hosts = {
        "shadow.rules.test": domain("shadow.rules.test", 701, origin_ip, [rule(1, "block", "shadow")]),
        "enforce.rules.test": domain("enforce.rules.test", 702, origin_ip, [rule(1, "block", "enforce")]),
        "expiring.rules.test": domain("expiring.rules.test", 703, origin_ip, [rule(1, "block", "enforce", expires_at=expires)]),
        "shadowrate.rules.test": domain("shadowrate.rules.test", 704, origin_ip, [rule(1, "block", "shadow", rate={"key": "client_ip", "threshold": 2, "period_seconds": 600, "duration_seconds": 600})]),
        "shadowchallenge.rules.test": domain("shadowchallenge.rules.test", 705, origin_ip, [rule(1, "challenge", "shadow")]),
    }
    with tempfile.TemporaryDirectory(prefix="cdnf-e2e-shadow-") as directory:
        temporary = pathlib.Path(directory)
        temporary.chmod(0o755)
        runtime = temporary / "runtime.json"
        runtime.write_text(json.dumps({"schema_version": 1, "sequence": 1, "generation_id": "e2e-shadow-1", "certificates": {}, "hosts": hosts}))
        runtime.chmod(0o644)
        run("openssl", "req", "-x509", "-newkey", "rsa:2048", "-nodes", "-days", "1", "-subj", "/CN=rules.test",
            "-keyout", str(temporary / "tls.key"), "-out", str(temporary / "tls.crt"))
        for item in (temporary / "tls.key", temporary / "tls.crt"):
            item.chmod(0o644)
        try:
            run("docker", "run", "-d", "--name", CELL, "--network", NETWORK,
                "-e", "EDGE_RUNTIME_FILE=/var/lib/cdnfoundry/runtime/active.json", "-e", "EDGE_STATUS_TOKEN=e2e",
                "-v", f"{runtime}:/var/lib/cdnfoundry/runtime/active.json:ro",
                "-v", f"{temporary / 'tls.crt'}:/run/edge/tls.crt:ro", "-v", f"{temporary / 'tls.key'}:/run/edge/tls.key:ro",
                "--tmpfs", "/var/cache/nginx:rw,noexec,nosuid,size=64m,mode=0777", IMAGE)
            ip = address(CELL)
            deadline = time.monotonic() + 60
            while time.monotonic() < deadline:
                try:
                    if get(ip, "shadow.rules.test", "/ready") in (200, 404):
                        break
                except OSError:
                    pass
                time.sleep(1)
            else:
                raise AssertionError(run("docker", "logs", CELL, check=False).stderr)

            # The same traffic goes to a shadow rule and to the same rule enforcing: one request in three matches.
            paths = ["/blocked/a", "/open/b", "/blocked/c"] * (REQUESTS // 3)
            shadow = [get(ip, "shadow.rules.test", path) for path in paths]
            enforced = [get(ip, "enforce.rules.test", path) for path in paths]
            assert 403 not in shadow, "a shadow rule changed a response"
            blocked = enforced.count(403)
            assert blocked == REQUESTS * 2 // 3, f"the enforcing rule blocked {blocked} of {REQUESTS * 2 // 3} matching requests"
            would = events("custom_rule_would_block", 701)
            assert abs(would - blocked) <= max(1, blocked * 0.01), f"shadow counted {would}, enforcement blocked {blocked}"
            assert events("custom_rule_block", 701) == 0 and events("custom_rule_would_block", 702) == 0

            # A shadow rate limit counts and records but never limits.
            statuses = [get(ip, "shadowrate.rules.test", "/blocked/x") for _ in range(6)]
            assert 429 not in statuses and 403 not in statuses, statuses
            assert events("custom_rule_would_block", 704) == 4, events("custom_rule_would_block", 704)
            assert events("custom_rule_rate_limited", 704) == 0

            # A shadow challenge serves the page and records would_challenge.
            assert get(ip, "shadowchallenge.rules.test", "/blocked/y") in (200, 404)
            assert events("custom_rule_would_challenge", 705) == 1

            # An expiring rule blocks until its expiry and stops within 2 seconds of it, by the cell's own clock.
            assert expires - time.time() > 5, "the test started too late to see the rule act"
            assert get(ip, "expiring.rules.test", "/blocked/z") == 403
            while time.time() < expires - 1:
                time.sleep(0.2)
            while time.time() < expires:
                assert get(ip, "expiring.rules.test", "/blocked/z") == 403, "the rule stopped before its expiry"
                time.sleep(0.1)
            ended = None
            deadline = time.time() + 5
            while time.time() < deadline:
                if get(ip, "expiring.rules.test", "/blocked/z") != 403:
                    ended = time.time() - expires
                    break
                time.sleep(0.05)
            assert ended is not None and ended <= 2, f"the rule acted {ended} seconds after its expiry"
            log = run("docker", "logs", CELL, check=False)
            assert "attempt to" not in log.stdout + log.stderr, log.stderr[-800:]
            print(f"shadow counted {would} of {blocked} enforced blocks; the rule stopped {ended:.2f} s after its expiry")
        finally:
            run("docker", "rm", "-f", CELL, ORIGIN, check=False)
    print("e2e shadow and expiring rules qualification passed")


if __name__ == "__main__":
    main()
