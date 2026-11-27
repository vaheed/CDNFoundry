#!/usr/bin/env python3
"""Real-runtime qualification of adaptive challenge difficulty and the per-domain bypass of under attack mode.

Cells run the real image. The difficulty a seed asks for is the base, two bits more for a client with three or more recent security
events on the domain, one more while the domain is restricted, and never above the cap. The test checks each step, that the cap holds,
that a seed whose difficulty was changed is refused (the difficulty is inside the signed seed), and that a seed keeps the difficulty it
was issued with. It then gives a cell an incident profile with its own bypass prefixes and methods and checks that exactly those skip
the challenge: the platform default list no longer applies, a method that is not listed is challenged, and an empty list lets nothing
through.
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

ORIGIN = "cdnf-e2e-adaptive-origin"
CELLS = {"default": "cdnf-e2e-adaptive-default", "capped": "cdnf-e2e-adaptive-capped", "restricted": "cdnf-e2e-adaptive-restricted",
         "bypass": "cdnf-e2e-adaptive-bypass", "none": "cdnf-e2e-adaptive-none"}


def control(name: str, payload: dict) -> None:
    run("docker", "exec", name, "wget", "-q", "-O-", "--header=X-Edge-Status-Token: e2e", "--header=Content-Type: application/json",
        "--post-data=" + json.dumps(payload), "http://127.0.0.1:9080/control")


def overlay(name: str, sequence: int, incident: dict | None) -> None:
    payload = {"task_id": f"adaptive-{name}-{sequence}", "action": "security_overlay", "domain_id": 701, "sequence": sequence, "state": "restricted"}
    if incident is not None:
        payload["incident"] = {"expires_at": int(time.time()) + 600, "waf": {"mode": "block"}, "methods": ["GET", "HEAD", "OPTIONS", "POST"],
                               "max_body_bytes": 1024, "challenge": True, **incident}
    control(name, payload)


def provoke(ip: str, source: str, times: int = 4) -> None:
    """Security events that count against a client: an answer to the challenge that is far too large."""
    for _ in range(times):
        assert request(ip, source, "POST", "/.cdnf/challenge", body="x" * 600)[0] == 413


def runtime_for(temporary: pathlib.Path, origin_ip: str, challenge_rule: bool) -> pathlib.Path:
    config = host("off", False, 0, origin_ip)
    config["domain"], config["domain_id"] = "chal.test", 701
    config["security"]["allowed_methods"] = ["GET", "HEAD", "OPTIONS", "POST"]
    config["security"]["limits"].update({"requests_per_second": 100000, "request_burst": 100000, "connections_per_client": 4096, "connections_per_domain": 100000})
    rules = [{"id": 2, "name": None, "priority": 1, "action": "block", "conditions": [{"field": "path", "operator": "starts_with", "value": "/blocked"}]}]
    if challenge_rule:
        rules.append({"id": 1, "name": None, "priority": 2, "action": "challenge", "conditions": [{"field": "path", "operator": "equals", "value": "/"}]})
    config["security"]["rules"] = rules
    path = temporary / ("challenge.json" if challenge_rule else "incident.json")
    path.write_text(json.dumps({"schema_version": 1, "sequence": 1, "generation_id": "e2e-adapt-1", "certificates": {}, "hosts": {"chal.test": config}}))
    path.chmod(0o644)
    return path


def main() -> None:
    cells = list(CELLS.values())
    run("docker", "rm", "-f", ORIGIN, *cells, check=False)
    run("docker", "run", "-d", "--name", ORIGIN, "--network", NETWORK, "nginx:1.30.3-alpine")
    origin_ip = address(ORIGIN)
    subnet = json.loads(run("docker", "network", "inspect", NETWORK).stdout)[0]["IPAM"]["Config"][0]["Subnet"]
    with tempfile.TemporaryDirectory(prefix="cdnf-e2e-adaptive-") as directory:
        temporary = pathlib.Path(directory)
        temporary.chmod(0o755)
        run("openssl", "req", "-x509", "-newkey", "rsa:2048", "-nodes", "-days", "1", "-subj", "/CN=chal.test", "-keyout", str(temporary / "tls.key"), "-out", str(temporary / "tls.crt"))
        for item in (temporary / "tls.key", temporary / "tls.crt"):
            item.chmod(0o644)
        challenge_runtime = runtime_for(temporary, origin_ip, True)
        incident_runtime = runtime_for(temporary, origin_ip, False)
        results: dict[str, object] = {}
        try:
            default = start(CELLS["default"], challenge_runtime, temporary, subnet, {})
            capped = start(CELLS["capped"], challenge_runtime, temporary, subnet, {"EDGE_CHALLENGE_BITS": "14", "EDGE_CHALLENGE_MAX_BITS": "14"})
            restricted = start(CELLS["restricted"], challenge_runtime, temporary, subnet, {"EDGE_CHALLENGE_BITS": "14"})

            # 1. A fresh client gets the base difficulty; a client with recent security events gets two bits more, up to the cap.
            _, base = challenge(default, "198.51.100.10")
            assert base == 16, base
            first_seed, first_bits = challenge(default, "198.51.100.11")
            provoke(default, "198.51.100.11")
            seed, penalized = challenge(default, "198.51.100.11")
            assert penalized == 17, penalized  # 16 + 2, held to the default cap of 17
            assert challenge(default, "198.51.100.12")[1] == 16, "another client was made to work harder"
            results["difficulty_base_and_penalized"] = [base, penalized]

            # Two page loads of one client in the same second (two tabs) each get a seed of their own, and both are accepted.
            seeds = [challenge(default, "198.51.100.13")[0] for _ in range(2)]
            assert seeds[0] != seeds[1], "two page loads in one second got the same seed"
            for twin in seeds:
                status, _, _ = request(default, "198.51.100.13", "POST", "/.cdnf/challenge", body=f"seed={urllib.parse.quote(twin)}&counter={solve(twin, 16)}")
                assert status == 204, status

            # 2. A seed keeps the difficulty it was issued with, and a changed difficulty is refused.
            counter = solve(first_seed, first_bits)
            status, headers, _ = request(default, "198.51.100.11", "POST", "/.cdnf/challenge", body=f"seed={urllib.parse.quote(first_seed)}&counter={counter}")
            assert status == 204 and "_chk=" in headers["set-cookie"], "a seed issued before the events was refused"
            kid, domain, issued, bits, nonce, mac = seed.split(".")
            lowered = ".".join([kid, domain, issued, "12", nonce, mac])
            status, _, _ = request(default, "198.51.100.11", "POST", "/.cdnf/challenge", body=f"seed={urllib.parse.quote(lowered)}&counter={solve(lowered, 12)}")
            assert status == 403, "a seed with its difficulty lowered was accepted"
            raised = ".".join([kid, domain, issued, "20", nonce, mac])
            status, _, _ = request(default, "198.51.100.11", "POST", "/.cdnf/challenge", body=f"seed={urllib.parse.quote(raised)}&counter=0")
            assert status == 403, "a seed above the cap was accepted"

            # 3. The cap holds: a cell whose cap equals its base never asks for more, even from a client with events.
            provoke(capped, "198.51.100.20")
            assert challenge(capped, "198.51.100.20")[1] == 14

            # 4. A restricted domain asks for one bit more, and the two together reach the cap.
            assert challenge(restricted, "198.51.100.30")[1] == 14
            overlay(CELLS["restricted"], 1, None)
            assert challenge(restricted, "198.51.100.30")[1] == 15
            provoke(restricted, "198.51.100.31")
            assert challenge(restricted, "198.51.100.31")[1] == 17, "events and a restricted domain add up to 14 + 3, which is the default cap"
            results["restricted_domain_bits"] = [15, 17]

            # 5. Under attack mode with the domain's own bypass: only those prefixes and methods skip the challenge.
            bypass = start(CELLS["bypass"], incident_runtime, temporary, subnet, {"EDGE_CHALLENGE_BITS": "12"})
            overlay(CELLS["bypass"], 1, {"challenge_bypass_prefixes": ["/v1/"], "challenge_bypass_methods": ["OPTIONS"]})
            json_accept = "application/json"
            assert request(bypass, "198.51.100.40", path="/v1/orders", accept=json_accept)[0] != 403, "a chosen prefix was challenged"
            assert request(bypass, "198.51.100.40", path="/api/orders", accept=json_accept)[0] == 403, "the default prefix still applied after the domain chose its own"
            assert request(bypass, "198.51.100.40", "OPTIONS", "/anything")[0] != 403, "a chosen method was challenged"
            assert request(bypass, "198.51.100.40", "POST", "/anything", body="a=1")[0] == 403, "a method that was not chosen skipped the challenge"
            none = start(CELLS["none"], incident_runtime, temporary, subnet, {"EDGE_CHALLENGE_BITS": "12"})
            overlay(CELLS["none"], 1, {"challenge_bypass_prefixes": [], "challenge_bypass_methods": []})
            for method, path in (("GET", "/api/orders"), ("GET", "/.well-known/x"), ("OPTIONS", "/anything")):
                assert request(none, "198.51.100.41", method, path, accept=json_accept)[0] == 403, (method, path)
            # A bypass the cell cannot verify is refused and changes nothing.
            try:
                overlay(CELLS["none"], 2, {"challenge_bypass_methods": ["TRACE"]})
            except RuntimeError:
                pass
            else:
                raise AssertionError("an unknown bypass method was accepted")
            assert request(none, "198.51.100.41", "OPTIONS", "/anything", accept=json_accept)[0] == 403, "a refused overlay changed the bypass"
            print(json.dumps(results))
        finally:
            run("docker", "rm", "-f", ORIGIN, *cells, check=False)
    print("e2e adaptive challenge difficulty and bypass passed")


if __name__ == "__main__":
    main()
