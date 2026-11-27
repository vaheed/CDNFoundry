#!/usr/bin/env python3
"""Real-cell qualification of the decision trace fields on a cell that has no control plane.

Every mitigated or shadow-matched request must name its stage and rule and carry a keyed client hash and the snapshot sequence;
an unmitigated request carries neither; the hash is stable for one client and domain, differs between domains, and never holds
the client address; and the fields add no more than the stated bytes to the log line.
"""

import http.client
import json
import pathlib
import re
import sys
import tempfile
import time

sys.path.insert(0, str(pathlib.Path(__file__).resolve().parent))
from managed_waf import IMAGE, NETWORK, host, run  # noqa: E402
from rate_limit_isolation import address  # noqa: E402

CELL = "cdnf-e2e-decision-trace"
ORIGIN = "cdnf-e2e-decision-trace-origin"
SEQUENCE = 4711
# The two fields on a line that names a client: "client_key":"<12 hex>","snapshot_sequence":<digits>, minus the empty form every line already carries.
BYTE_BUDGET = 64


def domain(name: str, identifier: int, origin_ip: str, rules: list[dict]) -> dict:
    config = host("off", False, 0, origin_ip)
    config["domain"], config["domain_id"] = name, identifier
    config["security"]["limits"].update({"requests_per_second": 100000, "request_burst": 100000, "connections_per_client": 4096, "connections_per_domain": 100000})
    config["security"]["rules"] = rules
    return config


def rule(identifier: int, action: str, mode: str) -> dict:
    return {"id": identifier, "name": None, "priority": identifier, "action": action, "mode": mode, "expires_at": None,
            "conditions": [{"field": "path", "operator": "starts_with", "value": "/blocked"}], "rate_limit": None}


def get(ip: str, hostname: str, path: str) -> int:
    connection = http.client.HTTPConnection(ip, 8080, timeout=10)
    connection.request("GET", path, headers={"Host": hostname})
    response = connection.getresponse()
    response.read()
    connection.close()
    return response.status


def main() -> None:
    run("docker", "rm", "-f", CELL, ORIGIN, check=False)
    run("docker", "run", "-d", "--name", ORIGIN, "--network", NETWORK, "nginx:1.30.3-alpine")
    origin_ip = address(ORIGIN)
    hosts = {
        "enforce.trace.test": domain("enforce.trace.test", 801, origin_ip, [rule(1, "block", "enforce")]),
        "second.trace.test": domain("second.trace.test", 802, origin_ip, [rule(1, "block", "enforce")]),
        "shadow.trace.test": domain("shadow.trace.test", 803, origin_ip, [rule(1, "block", "shadow")]),
    }
    with tempfile.TemporaryDirectory(prefix="cdnf-e2e-trace-") as directory:
        temporary = pathlib.Path(directory)
        temporary.chmod(0o755)
        runtime = temporary / "runtime.json"
        runtime.write_text(json.dumps({"schema_version": 1, "sequence": SEQUENCE, "generation_id": "e2e-trace-1", "certificates": {}, "hosts": hosts}))
        runtime.chmod(0o644)
        run("openssl", "req", "-x509", "-newkey", "rsa:2048", "-nodes", "-days", "1", "-subj", "/CN=trace.test",
            "-keyout", str(temporary / "tls.key"), "-out", str(temporary / "tls.crt"))
        for item in (temporary / "tls.key", temporary / "tls.crt"):
            item.chmod(0o644)
        main_conf = run("docker", "run", "--rm", "--entrypoint", "cat", IMAGE, "/usr/local/openresty/nginx/conf/nginx.conf").stdout
        logged = main_conf.replace("access_log syslog:server=vector:9000,facility=local7,tag=cdnfoundry_edge,nohostname edge_json;", "access_log /dev/stdout edge_json;")
        assert logged != main_conf
        (temporary / "nginx.conf").write_text(logged)
        (temporary / "nginx.conf").chmod(0o644)
        try:
            run("docker", "run", "-d", "--name", CELL, "-v", f"{temporary / 'nginx.conf'}:/usr/local/openresty/nginx/conf/nginx.conf:ro", "--network", NETWORK,
                "-e", "EDGE_RUNTIME_FILE=/var/lib/cdnfoundry/runtime/active.json", "-e", "EDGE_STATUS_TOKEN=e2e",
                "-v", f"{runtime}:/var/lib/cdnfoundry/runtime/active.json:ro",
                "-v", f"{temporary / 'tls.crt'}:/run/edge/tls.crt:ro", "-v", f"{temporary / 'tls.key'}:/run/edge/tls.key:ro",
                "--tmpfs", "/var/cache/nginx:rw,noexec,nosuid,size=64m,mode=0777", IMAGE)
            ip = address(CELL)
            deadline = time.monotonic() + 60
            while time.monotonic() < deadline:
                try:
                    if get(ip, "enforce.trace.test", "/ready") in (200, 404):
                        break
                except OSError:
                    pass
                time.sleep(1)
            else:
                raise AssertionError(run("docker", "logs", CELL, check=False).stderr)

            for hostname in hosts:
                for path in ("/blocked/a", "/blocked/b", "/open/c"):
                    get(ip, hostname, path)
            logs = run("docker", "logs", CELL, check=False)
            raw = [line for line in (logs.stdout + logs.stderr).splitlines() if line.startswith("{")]
            entries = {(json.loads(line)["hostname"], json.loads(line)["path"]): json.loads(line) for line in raw if json.loads(line).get("hostname", "").endswith("trace.test")}

            keys = {}
            for hostname in hosts:
                for path in ("/blocked/a", "/blocked/b"):
                    entry = entries[(hostname, path)]
                    assert re.fullmatch(r"[0-9a-f]{12}", entry["client_key"]), entry
                    assert entry["snapshot_sequence"] == SEQUENCE, entry
                    acted = entry["security_rule"] if hostname != "shadow.trace.test" else entry["shadow_rule"]
                    assert acted == "r1", entry
                    assert hostname == "shadow.trace.test" or (entry["status"] == 403 and entry["security_reason"] == "custom_rule_block"), entry
                    keys.setdefault(hostname, set()).add(entry["client_key"])
                quiet = entries[(hostname, "/open/c")]
                assert quiet["client_key"] == "" and quiet["snapshot_sequence"] == 0, quiet
            assert all(len(value) == 1 for value in keys.values()), "the key of one client and domain must be stable"
            assert len({next(iter(value)) for value in keys.values()}) == len(keys), "the same client must have a different key in each domain"
            for entry in entries.values():
                assert entry["client_key"] == "" or entry["client_key"] not in entry["client_ip"]

            blocked_line = next(line for line in raw if '"path":"/blocked/a"' in line and "enforce.trace.test" in line)
            quiet_line = blocked_line.replace(re.search(r'"client_key":"[0-9a-f]{12}","snapshot_sequence":\d+', blocked_line).group(0), '"client_key":"","snapshot_sequence":0')
            added = len(blocked_line) - len(quiet_line)
            assert 0 < added <= BYTE_BUDGET, added
            log = run("docker", "logs", CELL, check=False)
            assert "attempt to" not in log.stdout + log.stderr, log.stderr[-800:]
            print(f"trace fields verified on {len(entries)} requests across {len(hosts)} domains; a mitigated line grows by {added} bytes (budget {BYTE_BUDGET})")
        finally:
            run("docker", "rm", "-f", CELL, ORIGIN, check=False)
    print("e2e decision trace qualification passed")


if __name__ == "__main__":
    main()
