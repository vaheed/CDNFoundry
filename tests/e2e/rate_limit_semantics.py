#!/usr/bin/env python3
"""Real-cell qualification of rate-limit semantics: windows, keys, responses, outcome counting and the shared scope.

Three cells share one runtime file, as three cells of one domain would. The test checks, with the cell's own counters:
  1. a rule trips within 5% of its threshold for steady traffic, and with a sliding window within 10% for a burst on each side of a
     window boundary, where a fixed window lets nearly twice the threshold through (the stated error bound of a fixed window),
  2. cookie, query-argument, ASN and two-part keys count what they say, and a one-hour window counts,
  3. a limited request answers 429 with Retry-After, and a JSON body for a client that does not ask for HTML,
  4. a rule that counts only 401 responses stops 200 addresses that fail 25 logins each, and never counts successful logins from one
     shared (NAT) address,
  5. in shared scope over three cells balanced traffic is admitted at no more than 120% of the threshold, skewed traffic to one cell is
     admitted at a third of it (the stated underuse), and per-cell scope admits the threshold on every cell.
"""

import http.client
import json
import math
import os
import pathlib
import sys
import tempfile
import time
from concurrent.futures import ThreadPoolExecutor

sys.path.insert(0, str(pathlib.Path(__file__).resolve().parent))
from managed_waf import IMAGE, NETWORK, host, run  # noqa: E402
from rate_limit_isolation import address  # noqa: E402

CELLS = ["cdnf-e2e-rate-a", "cdnf-e2e-rate-b", "cdnf-e2e-rate-c"]
ORIGIN = "cdnf-e2e-rate-semantics-origin"


def rule(path: str, key: str, threshold: int, period: int = 600, duration: int = 60, action: str = "block", **extra: object) -> dict:
    limit = {"key": key, "threshold": threshold, "period_seconds": period, "duration_seconds": duration, "window": "fixed", "scope": "per_cell", **extra}
    return {"id": 1, "name": None, "priority": 1, "action": action, "mode": "enforce", "expires_at": None,
            "conditions": [{"field": "path", "operator": "starts_with", "value": path}], "rate_limit": limit}


def domain(name: str, identifier: int, origin_ip: str, rate_rule: dict, cells_serving: int = 1) -> dict:
    config = host("off", False, 0, origin_ip)
    config["domain"], config["domain_id"] = name, identifier
    security = config["security"]
    security["limits"].update({"requests_per_second": 1000000, "request_burst": 1000000, "connections_per_client": 4096, "connections_per_domain": 100000})
    security["trusted_proxy_cidrs"] = ["0.0.0.0/0", "::/0"]
    security["rules"] = [rate_rule]
    security["cells_serving"] = cells_serving
    return config


class Client:
    def __init__(self, ip: str):
        self.ip = ip
        self.connection = http.client.HTTPConnection(ip, 8080, timeout=10)

    def get(self, hostname: str, path: str = "/", client: str = "198.51.100.1", headers: dict | None = None, body: bool = False):
        request_headers = {"Host": hostname, "X-Forwarded-For": client, **(headers or {})}
        try:
            self.connection.request("GET", path, headers=request_headers)
            response = self.connection.getresponse()
            data = response.read()
        except (OSError, http.client.HTTPException):
            self.connection = http.client.HTTPConnection(self.ip, 8080, timeout=10)
            self.connection.request("GET", path, headers=request_headers)
            response = self.connection.getresponse()
            data = response.read()
        return (response.status, dict(response.getheaders()), data) if body else response.status


def allowed_until_limited(session: Client, hostname: str, limit: int = 2000, **kwargs) -> int:
    allowed = 0
    for _ in range(limit):
        if session.get(hostname, **kwargs) == 429:
            return allowed
        allowed += 1
    return allowed


def main() -> None:
    run("docker", "rm", "-f", ORIGIN, *CELLS, check=False)
    with tempfile.TemporaryDirectory(prefix="cdnf-e2e-rate-sem-") as directory:
        temporary = pathlib.Path(directory)
        temporary.chmod(0o755)
        conf = temporary / "origin.conf"
        conf.write_text('server { listen 80; location /login { if ($arg_bad) { return 401 "no"; } return 200 "ok"; } location / { return 200 "ok"; } }\n')
        conf.chmod(0o644)
        run("docker", "run", "-d", "--name", ORIGIN, "--network", NETWORK, "-v", f"{conf}:/etc/nginx/conf.d/default.conf:ro", "nginx:1.30.3-alpine")
        origin_ip = address(ORIGIN)
        hosts = {
            "steady.rate.test": domain("steady.rate.test", 1201, origin_ip, rule("/", "client_ip", 200)),
            "fixed.rate.test": domain("fixed.rate.test", 1202, origin_ip, rule("/", "client_ip", 100, 10, 10)),
            "sliding.rate.test": domain("sliding.rate.test", 1203, origin_ip, rule("/", "client_ip", 100, 10, 10, window="sliding")),
            "cookie.rate.test": domain("cookie.rate.test", 1204, origin_ip, rule("/", "cookie", 3, key_name="sid")),
            "query.rate.test": domain("query.rate.test", 1205, origin_ip, rule("/", "query_arg", 3, key_name="token")),
            "combo.rate.test": domain("combo.rate.test", 1206, origin_ip, rule("/", "header", 2, key_name="x-tenant", second_key="query_arg", second_name="user")),
            "asn.rate.test": domain("asn.rate.test", 1207, origin_ip, rule("/", "asn", 5)),
            "json.rate.test": domain("json.rate.test", 1208, origin_ip, rule("/", "client_ip", 1, 600, 90)),
            "login.rate.test": domain("login.rate.test", 1209, origin_ip, rule("/login", "client_ip", 5, 600, 300, count_statuses=[401])),
            "shared.rate.test": domain("shared.rate.test", 1210, origin_ip, rule("/", "client_ip", 300, scope="shared"), cells_serving=3),
            "percell.rate.test": domain("percell.rate.test", 1211, origin_ip, rule("/", "client_ip", 300)),
            "long.rate.test": domain("long.rate.test", 1212, origin_ip, rule("/", "client_ip", 10, 3600, 100)),
        }
        runtime = temporary / "runtime.json"
        runtime.write_text(json.dumps({"schema_version": 1, "sequence": 1, "generation_id": "rate-1", "certificates": {}, "hosts": hosts}))
        runtime.chmod(0o644)
        run("openssl", "req", "-x509", "-newkey", "rsa:2048", "-nodes", "-days", "1", "-subj", "/CN=rate.test", "-keyout", str(temporary / "tls.key"), "-out", str(temporary / "tls.crt"))
        for item in (temporary / "tls.key", temporary / "tls.crt"):
            item.chmod(0o644)
        try:
            ips = []
            for name in CELLS:
                run("docker", "run", "-d", "--name", name, "--network", NETWORK, "--cpus", "1",
                    "-e", "EDGE_RUNTIME_FILE=/var/lib/cdnfoundry/runtime/active.json", "-e", "EDGE_STATUS_TOKEN=e2e",
                    "-v", f"{runtime}:/var/lib/cdnfoundry/runtime/active.json:ro",
                    "-v", f"{temporary / 'tls.crt'}:/run/edge/tls.crt:ro", "-v", f"{temporary / 'tls.key'}:/run/edge/tls.key:ro",
                    "--tmpfs", "/var/cache/nginx:rw,noexec,nosuid,size=64m,mode=0777", IMAGE)
                ips.append(address(name))
            for ip in ips:
                deadline = time.monotonic() + 90
                while time.monotonic() < deadline:
                    try:
                        if Client(ip).get("steady.rate.test", "/ready", client="203.0.113.250") in (200, 404):
                            break
                    except OSError:
                        pass
                    time.sleep(1)
                else:
                    raise AssertionError(run("docker", "logs", name, check=False).stderr)
            cell = Client(ips[0])
            # Keep the 600 s windows away from a boundary, so a window ending mid-test is not mistaken for a result.
            remaining = 600 - time.time() % 600
            if remaining < 120:
                time.sleep(remaining + 1)
            results: dict[str, object] = {}

            # 1. Steady traffic trips within 5% of the threshold.
            steady = allowed_until_limited(cell, "steady.rate.test", client="203.0.113.10")
            results["steady_allowed_of_200"] = steady
            assert 190 <= steady <= 210, steady

            # Bursts on each side of a 10 s window boundary.
            def burst_pair(hostname: str, client: str) -> int:
                boundary = math.ceil(time.time() / 10) * 10
                if boundary - time.time() < 3:
                    boundary += 10
                time.sleep(boundary - 0.9 - time.time())
                total = sum(1 for _ in range(100) if cell.get(hostname, client=client) != 429)
                time.sleep(max(0, boundary + 0.05 - time.time()))
                return total + sum(1 for _ in range(100) if cell.get(hostname, client=client) != 429)
            fixed = burst_pair("fixed.rate.test", "203.0.113.20")
            sliding = burst_pair("sliding.rate.test", "203.0.113.21")
            results["boundary_bursts_allowed_of_200"] = {"fixed": fixed, "sliding": sliding}
            assert fixed >= 190, f"a fixed window should let nearly twice the threshold through across a boundary, got {fixed}"
            assert 90 <= sliding <= 110, f"a sliding window must hold a boundary burst to the threshold within 10%, got {sliding}"

            # 2. Other keys.
            assert [cell.get("cookie.rate.test", headers={"Cookie": "sid=one"}) for _ in range(4)] == [200, 200, 200, 429]
            assert cell.get("cookie.rate.test", headers={"Cookie": "sid=two"}) == 200, "another cookie value has its own counter"
            assert cell.get("cookie.rate.test", client="203.0.113.99", headers={"Cookie": "sid=one"}) == 429, "the same cookie from another address shares the counter"
            assert cell.get("cookie.rate.test") == 200, "a request with no cookie is not counted"
            assert [cell.get("query.rate.test", "/?token=a") for _ in range(4)] == [200, 200, 200, 429]
            assert cell.get("query.rate.test", "/?token=b") == 200
            assert [cell.get("combo.rate.test", "/?user=u1", headers={"X-Tenant": "t1"}) for _ in range(3)] == [200, 200, 429]
            assert cell.get("combo.rate.test", "/?user=u2", headers={"X-Tenant": "t1"}) == 200, "another second key is another counter"
            assert cell.get("combo.rate.test", "/?user=u1", headers={"X-Tenant": "t2"}) == 200, "another first key is another counter"
            assert cell.get("combo.rate.test", "/", headers={"X-Tenant": "t1"}) == 200, "a request missing one half of the key is not counted"
            asn = [cell.get("asn.rate.test", client=f"198.51.100.{index}") for index in range(1, 9)]
            assert asn.count(429) >= 3, f"clients of one network share the counter: {asn}"
            assert allowed_until_limited(cell, "long.rate.test", client="203.0.113.30") == 10

            # 3. The response of a limited request.
            assert cell.get("json.rate.test", client="203.0.113.40") == 200
            status, headers, body = cell.get("json.rate.test", client="203.0.113.40", headers={"Accept": "application/json"}, body=True)
            retry = int(headers["Retry-After"])
            assert status == 429 and 1 <= retry <= 90, (status, headers)
            document = json.loads(body)
            assert document["error"] == "rate_limited" and document["retry_after"] == retry, document
            assert headers["Content-Type"] == "application/json"
            status, headers, body = cell.get("json.rate.test", client="203.0.113.40", headers={"Accept": "text/html,application/xhtml+xml"}, body=True)
            assert status == 429 and "Retry-After" in headers and not body.startswith(b"{"), (status, headers, body[:60])

            # 4. Credential stuffing: 5,000 failed logins from 200 addresses, and one shared address that mostly succeeds.
            failures_through = []

            def attacker(number: int) -> int:
                own = Client(ips[0])
                through = 0
                for _ in range(25):
                    status = own.get("login.rate.test", "/login?bad=1", client=f"192.0.2.{number}")
                    through += status == 401
                return through
            with ThreadPoolExecutor(max_workers=8) as pool:
                failures_through = list(pool.map(attacker, range(1, 201)))
            results["credential_stuffing"] = {"attempts": 5000, "failures_reaching_origin": sum(failures_through), "most_for_one_address": max(failures_through)}
            assert max(failures_through) <= 6 and sum(failures_through) <= 200 * 6, failures_through
            assert all(cell.get("login.rate.test", "/login?bad=1", client=f"192.0.2.{number}") == 429 for number in (1, 100, 200))
            nat = "198.18.0.1"
            assert all(cell.get("login.rate.test", "/login?ok=1", client=nat) == 200 for _ in range(500)), "successful logins were counted"
            assert [cell.get("login.rate.test", "/login?bad=1", client=nat) for _ in range(3)] == [401, 401, 401]
            assert cell.get("login.rate.test", "/login?ok=1", client=nat) == 200, "three failures after 500 successes are below the threshold of 5"
            assert [cell.get("login.rate.test", "/login?bad=1", client=nat) for _ in range(5)][-1] == 429

            # 5. Three cells: shared scope, skew, and per-cell scope.
            clients = [Client(ip) for ip in ips]

            def spread(hostname: str, address_: str, requests: int, targets: list[Client]) -> int:
                admitted = 0
                for index in range(requests):
                    if targets[index % len(targets)].get(hostname, client=address_) != 429:
                        admitted += 1
                return admitted
            balanced = spread("shared.rate.test", "203.0.113.50", 1500, clients)
            skewed = spread("shared.rate.test", "203.0.113.51", 1500, clients[:1])
            percell = spread("percell.rate.test", "203.0.113.52", 3000, clients)
            results["three_cells_threshold_300"] = {"shared_balanced": balanced, "shared_all_on_one_cell": skewed, "per_cell_balanced": percell}
            assert balanced <= 360, f"shared scope admitted {balanced} of a threshold of 300"
            assert balanced >= 270, f"shared scope admitted only {balanced}"
            assert 90 <= skewed <= 110, f"all traffic on one cell is held to its third of the threshold, got {skewed}"
            assert 810 <= percell <= 990, f"per-cell scope admits the threshold on every cell, got {percell}"
            for name in CELLS:
                logs = run("docker", "logs", name, check=False)
                assert "no memory" not in logs.stdout + logs.stderr
            print(json.dumps(results))
        finally:
            run("docker", "rm", "-f", ORIGIN, *CELLS, check=False)
    print("e2e rate limit semantics passed")


if __name__ == "__main__":
    main()
