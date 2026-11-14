#!/usr/bin/env python3
"""Real-runtime qualification that one domain's rate-limit key flood cannot disturb another domain.

A cell runs with a deliberately small rate-limit dictionary. A victim domain gets an active mitigation and a partly used
counter; an attacker domain then sends tens of thousands of requests with distinct rate-limit keys, far more than the dictionary
holds. Without per-domain budgets the dictionary evicts the victim's marker and counter. With them the victim keeps both, and the
attacker only loses counting for its own extra keys, which is reported as a `rate_limit_state_full` event.
"""

import http.client
import json
import os
import pathlib
import sys
import tempfile
import time
from concurrent.futures import ThreadPoolExecutor

sys.path.insert(0, str(pathlib.Path(__file__).resolve().parent))
from managed_waf import IMAGE, NETWORK, ROOT, host, run  # noqa: E402

CELL = "cdnf-e2e-rate-isolation"
ORIGIN = "cdnf-e2e-rate-origin"
FLOOD_REQUESTS = int(os.environ.get("CDNF_FLOOD_REQUESTS", "30000"))


def address(container: str) -> str:
    return json.loads(run("docker", "inspect", container).stdout)[0]["NetworkSettings"]["Networks"][NETWORK]["IPAddress"]


def domain(name: str, identifier: int, origin_ip: str) -> dict:
    config = host("off", False, 0, origin_ip)
    config["domain"], config["domain_id"] = name, identifier
    limits = config["security"]["limits"]
    limits.update({"requests_per_second": 100000, "request_burst": 100000, "connections_per_client": 256, "connections_per_domain": 4096})
    config["security"]["rules"] = [{
        "id": 1, "name": None, "priority": 1, "action": "block",
        "conditions": [{"field": "path", "operator": "starts_with", "value": "/"}],
        "rate_limit": {"key": "header", "header_name": "X-Limit-Key", "threshold": 2, "period_seconds": 600, "duration_seconds": 600},
    }]
    return config


def get(ip: str, hostname: str, key: str, connection: http.client.HTTPConnection | None = None) -> int:
    own = connection or http.client.HTTPConnection(ip, 8080, timeout=10)
    own.request("GET", "/", headers={"Host": hostname, "X-Limit-Key": key})
    response = own.getresponse()
    response.read()
    status = response.status
    if connection is None:
        own.close()
    return status


def flood(ip: str, hostname: str, count: int, workers: int = 16) -> int:
    def work(worker: int) -> int:
        connection = http.client.HTTPConnection(ip, 8080, timeout=10)
        served = 0
        for index in range(worker, count, workers):
            try:
                get(ip, hostname, f"flood-{index}", connection)
                served += 1
            except (OSError, http.client.HTTPException):
                connection.close()
                connection = http.client.HTTPConnection(ip, 8080, timeout=10)
        connection.close()
        return served
    with ThreadPoolExecutor(max_workers=workers) as executor:
        return sum(executor.map(work, range(workers)))


def security_events(name: str) -> list[dict]:
    result = run("docker", "exec", name, "wget", "-q", "-O-", "--header=X-Edge-Status-Token: e2e", "http://127.0.0.1:9080/passive-failures")
    return json.loads(result.stdout)["security"]


def main() -> None:
    run("docker", "rm", "-f", CELL, ORIGIN, check=False)
    run("docker", "run", "-d", "--name", ORIGIN, "--network", NETWORK, "nginx:1.30.3-alpine")
    origin_ip = address(ORIGIN)
    with tempfile.TemporaryDirectory(prefix="cdnf-e2e-rate-") as directory:
        temporary = pathlib.Path(directory)
        temporary.chmod(0o755)
        hosts = {"victim.rate.test": domain("victim.rate.test", 201, origin_ip), "attacker.rate.test": domain("attacker.rate.test", 202, origin_ip)}
        runtime = temporary / "runtime.json"
        runtime.write_text(json.dumps({"schema_version": 1, "sequence": 1, "generation_id": "e2e-rate-1", "certificates": {}, "hosts": hosts}))
        runtime.chmod(0o644)
        run("openssl", "req", "-x509", "-newkey", "rsa:2048", "-nodes", "-days", "1", "-subj", "/CN=rate.test",
            "-keyout", str(temporary / "tls.key"), "-out", str(temporary / "tls.crt"))
        for item in (temporary / "tls.key", temporary / "tls.crt"):
            item.chmod(0o644)
        # The same configuration as the image's, with a 1 MiB rate-limit dictionary so that a flood fills it in seconds.
        configuration = run("docker", "run", "--rm", "--entrypoint", "cat", IMAGE, "/etc/nginx/conf.d/default.conf").stdout
        small = [line for line in configuration.splitlines() if line.startswith("lua_shared_dict rate_rules ")]
        assert len(small) == 1, small
        shrunk = temporary / "small-rate-rules.conf"
        shrunk.write_text(configuration.replace(small[0], "lua_shared_dict rate_rules 1m;"))
        try:
            run("docker", "run", "-d", "--name", CELL, "--network", NETWORK,
                "-e", "EDGE_RUNTIME_FILE=/var/lib/cdnfoundry/runtime/active.json", "-e", "EDGE_STATUS_TOKEN=e2e",
                "-v", f"{runtime}:/var/lib/cdnfoundry/runtime/active.json:ro", "-v", f"{shrunk}:/etc/nginx/conf.d/default.conf:ro",
                "-v", f"{temporary / 'tls.crt'}:/run/edge/tls.crt:ro", "-v", f"{temporary / 'tls.key'}:/run/edge/tls.key:ro",
                "--tmpfs", "/var/cache/nginx:rw,noexec,nosuid,size=64m,mode=0777", IMAGE)
            ip = address(CELL)
            deadline = time.monotonic() + 60
            while time.monotonic() < deadline:
                try:
                    if get(ip, "victim.rate.test", "ready") == 200:
                        break
                except OSError:
                    pass
                time.sleep(1)
            else:
                raise AssertionError(run("docker", "logs", CELL, check=False).stderr)
            # "ready" used one count of its own key; the victim's keys below are different ones.

            # The victim's client is mitigated (a marker with a 600 s duration), and another of its clients is mid-allowance.
            assert [get(ip, "victim.rate.test", "mitigated") for _ in range(3)] == [200, 200, 429]
            assert get(ip, "victim.rate.test", "mid-allowance") == 200
            assert get(ip, "attacker.rate.test", "early") == 200

            served = flood(ip, "attacker.rate.test", FLOOD_REQUESTS)
            assert served > FLOOD_REQUESTS * 0.9, f"the flood only got {served} of {FLOOD_REQUESTS} requests through"

            # Nothing of the victim was evicted: the marker still blocks, and the counter still holds its one request.
            assert get(ip, "victim.rate.test", "mitigated") == 429, "the victim's mitigation was evicted by another domain"
            assert get(ip, "victim.rate.test", "mid-allowance") == 200
            assert get(ip, "victim.rate.test", "mid-allowance") == 429, "the victim's counter was evicted by another domain"

            # The attacker alone pays: its extra keys are uncounted and reported, and the report names its own domain only.
            events = [event for event in security_events(CELL) if event["reason_code"] == "rate_limit_state_full"]
            assert events and {event["domain_id"] for event in events} == {202}, events
            assert not any(event["domain_id"] == 201 and event["reason_code"] == "rate_limit_state_full" for event in events)
            log = run("docker", "logs", CELL, check=False)
            assert "no memory" not in (log.stdout + log.stderr), "a shared dictionary ran out of memory"
        finally:
            run("docker", "rm", "-f", CELL, ORIGIN, check=False)
    print("e2e rate-limit isolation qualification passed")


if __name__ == "__main__":
    main()
