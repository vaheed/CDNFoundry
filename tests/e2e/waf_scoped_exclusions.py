#!/usr/bin/env python3
"""Real-cell qualification of managed-WAF exclusions scoped to a path prefix and a method.

An exclusion removes named rules or categories from the scoring pass of a request only when the request's method and normalized
path are in scope. The test runs a throwaway cell and a stub origin on the development edge network and asserts decisions from the
cell's own access log and from what the origin received: an excluded payload reaches the origin only inside the scope, every
neighbouring path, method and spelling is still blocked, and a rule outside the excluded category still blocks inside the scope.
"""

import json
import pathlib
import subprocess
import tempfile
import time
import urllib.parse

import managed_waf as base

CELL = "cdnf-e2e-scoped-waf"
ORIGIN = "cdnf-e2e-scoped-origin"
# The stub origin has no such pages, so a request the WAF lets through is answered 404 by the origin itself.
ALLOWED = 404
XSS = urllib.parse.quote("<script>alert(1)</script>", safe="")
SQLI = urllib.parse.quote("1' UNION SELECT username, password FROM users-- -", safe="")
run = base.run


def curl(ip: str, hostname: str, target: str, method: str = "GET", data: str | None = None) -> int:
    command = ["curl", "-s", "-o", "/dev/null", "-w", "%{http_code}", "--max-time", "20", "--path-as-is", "--resolve", f"{hostname}:8080:{ip}",
               "-H", "User-Agent: CDNFoundry-WAF-Test/1.0", "-X", method]
    if data is not None:
        command += ["-H", "Content-Type: application/x-www-form-urlencoded", "--data-binary", data]
    command.append(f"http://{hostname}:8080{target}")
    return int(subprocess.run(command, capture_output=True, text=True, check=False).stdout or 0)


def main() -> None:
    run("docker", "rm", "-f", CELL, ORIGIN, check=False)
    run("docker", "run", "-d", "--name", ORIGIN, "--network", base.NETWORK, "nginx:1.30.3-alpine")
    origin_ip = json.loads(run("docker", "inspect", ORIGIN).stdout)[0]["NetworkSettings"]["Networks"][base.NETWORK]["IPAddress"]
    with tempfile.TemporaryDirectory(prefix="cdnf-e2e-scoped-") as directory:
        temporary = pathlib.Path(directory)
        temporary.chmod(0o755)
        scoped = base.host("balanced", True, 1048576, origin_ip)
        scoped["domain"], scoped["domain_id"] = "scoped.waf.test", 95
        scoped["waf"].update({"off_categories": [], "off_rules": [], "log_only_rules": [], "scoped_exclusions": [
            {"rules": [], "categories": ["941"], "path_prefix": "/editor", "methods": []},
            {"rules": [], "categories": ["942"], "path_prefix": "/import/", "methods": ["POST"]},
        ]})
        plain = base.host("balanced", True, 1048576, origin_ip)
        plain["domain"], plain["domain_id"] = "plain.waf.test", 96
        runtime = temporary / "runtime.json"
        runtime.write_text(json.dumps({"schema_version": 1, "sequence": 1, "generation_id": "e2e-scoped-1", "certificates": {},
                                       "hosts": {"scoped.waf.test": scoped, "plain.waf.test": plain}}))
        runtime.chmod(0o644)
        run("openssl", "req", "-x509", "-newkey", "rsa:2048", "-nodes", "-days", "1", "-subj", "/CN=waf.test",
            "-keyout", str(temporary / "tls.key"), "-out", str(temporary / "tls.crt"))
        for item in (temporary / "tls.key", temporary / "tls.crt"):
            item.chmod(0o644)
        run("docker", "run", "-d", "--name", CELL, "--network", base.NETWORK,
            "-e", "EDGE_RUNTIME_FILE=/var/lib/cdnfoundry/runtime/active.json", "-e", "EDGE_STATUS_TOKEN=e2e", "-e", "EDGE_ACCESS_LOG_STDOUT=true",
            "-v", f"{runtime}:/var/lib/cdnfoundry/runtime/active.json:ro",
            "-v", f"{temporary / 'tls.crt'}:/run/edge/tls.crt:ro", "-v", f"{temporary / 'tls.key'}:/run/edge/tls.key:ro",
            "--tmpfs", "/var/cache/nginx:rw,noexec,nosuid,size=64m,mode=0777", base.IMAGE)
        try:
            ip = json.loads(run("docker", "inspect", CELL).stdout)[0]["NetworkSettings"]["Networks"][base.NETWORK]["IPAddress"]
            deadline = time.monotonic() + 60
            while curl(ip, "plain.waf.test", "/ready") != 200 and time.monotonic() < deadline:
                time.sleep(1)
            assert curl(ip, "plain.waf.test", "/") == 200

            def expect(status: int, target: str, label: str, **kwargs: object) -> None:
                got = curl(ip, "scoped.waf.test", target, **kwargs)
                assert got == status, (label, target, got)

            # A category excluded for a path prefix: the payload passes there and nowhere beside it.
            expect(ALLOWED, f"/editor?q={XSS}", "the prefix itself")
            expect(ALLOWED, f"/editor/page?q={XSS}", "below the prefix")
            expect(403, f"/editorial?q={XSS}", "a longer name is not below the prefix")
            expect(403, f"/other?q={XSS}", "another path")
            expect(403, f"/?q={XSS}", "the root")
            expect(403, f"/editor/../other?q={XSS}", "a dot segment is resolved before the scope is compared")
            expect(403, f"/Editor?q={XSS}", "the comparison is case sensitive")
            expect(ALLOWED, f"/%65ditor?q={XSS}", "a percent-escaped spelling is the same normalized path")
            # The same payload on a domain without the exclusion, and another kind of attack inside the scope, still block.
            assert curl(ip, "plain.waf.test", f"/editor?q={XSS}") == 403
            expect(403, f"/editor?id={SQLI}", "SQL injection is not in the excluded category")
            # A prefix with a trailing slash and a method.
            expect(ALLOWED, "/import/data", "POST inside the scope", method="POST", data=f"id={SQLI}")
            expect(403, f"/import/data?id={SQLI}", "GET is outside the method scope")
            expect(403, "/import", "a prefix that ends in a slash needs the slash", method="POST", data=f"id={SQLI}")
            expect(403, f"/import/data", "XSS is not in the excluded category", method="POST", data=f"q={XSS}")

            # What was decided is in the cell's own log: the excluded request was inspected and passed, the others were blocked.
            time.sleep(3)
            logs = run("docker", "logs", CELL).stdout + run("docker", "logs", CELL).stderr
            rows = [json.loads(line) for line in logs.splitlines() if line.startswith('{"occurred_at"')]
            excluded = [row for row in rows if row["hostname"] == "scoped.waf.test" and row["path"].startswith("/editor?q=")]
            assert excluded and all(row["waf_action"] == "pass" and row["status"] == 404 for row in excluded), excluded[:2]
            blocked = [row for row in rows if row["hostname"] == "scoped.waf.test" and row["path"].startswith("/other?q=")]
            assert blocked and all(row["waf_action"] == "block" and row["waf_rule_id"] != 949110 for row in blocked), blocked[:2]
        finally:
            run("docker", "rm", "-f", CELL, ORIGIN, check=False)
    print("e2e managed WAF scoped exclusion qualification passed")


if __name__ == "__main__":
    main()
