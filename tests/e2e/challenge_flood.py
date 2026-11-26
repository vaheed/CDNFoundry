#!/usr/bin/env python3
"""Measures what a flood gets through a challenge, against a real cell, so the documentation can state it.

Three kinds of client send the same number of requests to a path that matches a challenge rule:
  1. scripted HTTP clients that never solve the check (plain GETs, with and without an HTML Accept header),
  2. scripted clients that implement the solver, one token each,
  3. the same solver clients passing one token among many addresses, which the token's own budget and its binding refuse.
A headless browser solves the check like any browser; the browser suite shows that, so it is not repeated here.
"""

import json
import pathlib
import sys
import tempfile
import time
import urllib.parse

sys.path.insert(0, str(pathlib.Path(__file__).resolve().parent))
from challenge_binding import challenge, request, solve, start  # noqa: E402
from ipv6_rate_limit_keys import address  # noqa: E402
from managed_waf import NETWORK, host, run  # noqa: E402

ORIGIN = "cdnf-e2e-flood-origin"
CELL = "cdnf-e2e-flood-cell"
BUDGET = 600


def paced(ip: str, source: str, **kwargs) -> int:
    """A cell holds each connecting address to 100 requests a second; the test stays under that so a 503 is never mistaken for a result."""
    time.sleep(0.012)
    status = request(ip, source, **kwargs)[0]
    assert status != 503, "the cell's own per-address limit answered; the test sent too fast"
    return status


def solved_token(ip: str, source: str) -> str:
    seed, bits = challenge(ip, source)
    status, headers, _ = request(ip, source, "POST", "/.cdnf/challenge", body=f"seed={urllib.parse.quote(seed)}&counter={solve(seed, bits)}")
    assert status == 204, status
    return headers["set-cookie"].split("cdnf_chal=")[1].split(";")[0]


def main() -> None:
    run("docker", "rm", "-f", ORIGIN, CELL, check=False)
    run("docker", "run", "-d", "--name", ORIGIN, "--network", NETWORK, "nginx:1.30.3-alpine")
    subnet = json.loads(run("docker", "network", "inspect", NETWORK).stdout)[0]["IPAM"]["Config"][0]["Subnet"]
    with tempfile.TemporaryDirectory(prefix="cdnf-e2e-flood-") as directory:
        temporary = pathlib.Path(directory)
        temporary.chmod(0o755)
        config = host("off", False, 0, address(ORIGIN))
        config["domain"], config["domain_id"] = "chal.test", 601
        config["security"]["allowed_methods"] = ["GET", "HEAD"]
        config["security"]["limits"].update({"requests_per_second": 100000, "request_burst": 100000, "connections_per_client": 4096, "connections_per_domain": 100000})
        config["security"]["rules"] = [{"id": 1, "name": None, "priority": 1, "action": "challenge", "conditions": [{"field": "path", "operator": "equals", "value": "/"}]}]
        runtime = temporary / "runtime.json"
        runtime.write_text(json.dumps({"schema_version": 1, "sequence": 1, "generation_id": "e2e-flood-1", "certificates": {}, "hosts": {"chal.test": config}}))
        runtime.chmod(0o644)
        run("openssl", "req", "-x509", "-newkey", "rsa:2048", "-nodes", "-days", "1", "-subj", "/CN=chal.test", "-keyout", str(temporary / "tls.key"), "-out", str(temporary / "tls.crt"))
        for item in (temporary / "tls.key", temporary / "tls.crt"):
            item.chmod(0o644)
        results: dict[str, object] = {}
        try:
            ip = start(CELL, runtime, temporary, subnet, {})

            # 1. Scripted clients that never solve: 20 addresses, 100 requests each, half asking for HTML.
            through = sent = 0
            for client in range(20):
                for index in range(100):
                    status = paced(ip, f"198.51.100.{client + 1}", accept="text/html" if index % 2 else "*/*", headers={"User-Agent": "python-requests/2.32"})
                    sent += 1
                    through += status not in (403, 429)
            results["unsolved_scripts"] = {"sent": sent, "through": through}
            assert through == 0, f"{through} of {sent} unsolved requests reached the origin"

            # 2. Scripted clients that implement the solver: each earns a token and sends 700 requests inside a minute.
            allowed = refused = 0
            for client in range(5):
                source = f"198.51.100.{100 + client}"
                token = solved_token(ip, source)
                for _ in range(BUDGET + 100):
                    status = paced(ip, source, headers={"Cookie": f"cdnf_chal={token}", "User-Agent": "Browser/1"})
                    allowed += status == 200
                    refused += status == 429
            results["solving_scripts"] = {"sent": 5 * (BUDGET + 100), "through": allowed, "refused_by_token_budget": refused}
            assert allowed <= 5 * BUDGET and refused >= 5 * 95, results["solving_scripts"]

            # 3. One token passed to 30 other addresses: it is bound to the client it was issued to.
            token = solved_token(ip, "198.51.100.200")
            shared = sum(request(ip, f"198.51.101.{index}", headers={"Cookie": f"cdnf_chal={token}", "User-Agent": "Browser/1"})[0] == 200 for index in range(30))
            results["one_token_on_30_addresses"] = {"sent": 30, "through": shared}
            assert shared == 0, shared
            print(json.dumps(results))
        finally:
            run("docker", "rm", "-f", ORIGIN, CELL, check=False)
    print("e2e challenge flood measurement passed")


if __name__ == "__main__":
    main()
