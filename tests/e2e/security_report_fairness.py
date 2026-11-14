#!/usr/bin/env python3
"""A cell's security report must give every active domain a slot before any domain a second one.

One domain causes hundreds of events while more than a hundred others cause one each. The report is bounded to 100 entries, so the
noisy domain must take one of them, not all, every other entry must be a different domain, and the cell must say how many
active domains did not fit.
"""

import http.client
import json
import pathlib
import sys
import tempfile
import time

sys.path.insert(0, str(pathlib.Path(__file__).resolve().parent))
from managed_waf import IMAGE, NETWORK, host, run  # noqa: E402

CELL = "cdnf-e2e-report-fairness"
ORIGIN = "cdnf-e2e-report-origin"
DOMAINS = 130
NOISY = 1


def address(container: str) -> str:
    return json.loads(run("docker", "inspect", container).stdout)[0]["NetworkSettings"]["Networks"][NETWORK]["IPAddress"]


def refused_method(ip: str, hostname: str) -> int:
    connection = http.client.HTTPConnection(ip, 8080, timeout=10)
    connection.request("DELETE", "/", headers={"Host": hostname})
    status = connection.getresponse().status
    connection.close()
    return status


def report() -> dict:
    result = run("docker", "exec", CELL, "wget", "-q", "-O-", "--header=X-Edge-Status-Token: e2e", "http://127.0.0.1:9080/passive-failures")
    return json.loads(result.stdout)


def main() -> None:
    run("docker", "rm", "-f", ORIGIN, CELL, check=False)
    run("docker", "run", "-d", "--name", ORIGIN, "--network", NETWORK, "nginx:1.30.3-alpine")
    origin_ip = address(ORIGIN)
    with tempfile.TemporaryDirectory(prefix="cdnf-e2e-fair-") as directory:
        temporary = pathlib.Path(directory)
        temporary.chmod(0o755)
        hosts = {}
        for index in range(1, DOMAINS + 1):
            config = host("off", False, 0, origin_ip)
            config["domain"], config["domain_id"] = f"d{index}.fair.test", 500 + index
            config["security"]["limits"].update({"requests_per_second": 100000, "request_burst": 100000, "connections_per_client": 4096, "connections_per_domain": 100000})
            hosts[f"d{index}.fair.test"] = config
        runtime = temporary / "runtime.json"
        runtime.write_text(json.dumps({"schema_version": 1, "sequence": 1, "generation_id": "e2e-fair-1", "certificates": {}, "hosts": hosts}))
        runtime.chmod(0o644)
        run("openssl", "req", "-x509", "-newkey", "rsa:2048", "-nodes", "-days", "1", "-subj", "/CN=fair.test",
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
                    if refused_method(ip, "d1.fair.test") == 405:
                        break
                except OSError:
                    pass
                time.sleep(1)
            else:
                raise AssertionError(run("docker", "logs", CELL, check=False).stderr)

            # Every domain has one refused request, then the first domain goes on to cause hundreds more, last of all.
            for index in range(2, DOMAINS + 1):
                assert refused_method(ip, f"d{index}.fair.test") == 405
            for _ in range(300):
                refused_method(ip, "d1.fair.test")

            payload = report()
            entries = payload["security"]
            domains = [entry["domain_id"] for entry in entries]
            assert len(entries) == 100, f"the report is bounded to 100 entries, got {len(entries)}"
            assert len(set(domains)) == 100, "a domain took more than one slot while others were left out"
            assert 501 in domains, "the domain with the most events is in the report once"
            assert next(entry for entry in entries if entry["domain_id"] == 501)["count"] >= 50
            dropped = payload["cell"]["capacity"]["security_domains_dropped"]
            assert dropped == DOMAINS - 100, f"the cell must say how many active domains did not fit, got {dropped}"
        finally:
            run("docker", "rm", "-f", ORIGIN, CELL, check=False)
    print("e2e security report fairness passed")


if __name__ == "__main__":
    main()
