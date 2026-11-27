#!/usr/bin/env python3
"""Real-runtime check that a solved challenge is logged with the client key of the request that was challenged.

A cell runs the real image with a challenge rule and writes its access log to stdout. A client is challenged, solves the proof of
work and posts it. The challenge line and the solve line must carry the same client key, the solve line must stay an allowed
request with the reason challenge_solved, and a client that never solved must show no such line. The false-positive candidates
view relies on exactly this pairing.
"""

import json
import pathlib
import re
import sys
import tempfile
import time
import urllib.parse

sys.path.insert(0, str(pathlib.Path(__file__).resolve().parent))
from challenge_binding import challenge, request, solve  # noqa: E402
from ipv6_rate_limit_keys import address  # noqa: E402
from managed_waf import IMAGE, NETWORK, host, run  # noqa: E402

CELL, ORIGIN = "cdnf-e2e-challenge-solved", "cdnf-e2e-challenge-solved-origin"


def page_of(ip: str) -> str:
    """The challenge page a new visitor gets: it must carry no product name, and must post to the neutral path."""
    status, _, page = request(ip, "198.51.100.12")
    assert status == 403 and "Checking your browser" in page and "/.chk/verify" in page, (status, page[:200])
    assert not re.search(r"cdnf|cdnfoundry|foundry", page, re.IGNORECASE), "the challenge page names the product"
    return ""


def main() -> None:
    run("docker", "rm", "-f", ORIGIN, CELL, check=False)
    run("docker", "run", "-d", "--name", ORIGIN, "--network", NETWORK, "nginx:1.30.3-alpine")
    origin_ip = address(ORIGIN)
    subnet = json.loads(run("docker", "network", "inspect", NETWORK).stdout)[0]["IPAM"]["Config"][0]["Subnet"]
    with tempfile.TemporaryDirectory(prefix="cdnf-e2e-chal-solved-") as directory:
        temporary = pathlib.Path(directory)
        temporary.chmod(0o755)
        config = host("off", False, 0, origin_ip)
        config["domain"], config["domain_id"] = "chal.test", 601
        config["security"]["allowed_methods"] = ["GET", "HEAD"]
        config["security"]["limits"].update({"requests_per_second": 100000, "request_burst": 100000, "connections_per_client": 4096, "connections_per_domain": 100000})
        config["security"]["rules"] = [{"id": 1, "name": None, "priority": 1, "action": "challenge", "conditions": [{"field": "path", "operator": "equals", "value": "/"}]}]
        runtime = temporary / "runtime.json"
        runtime.write_text(json.dumps({"schema_version": 1, "sequence": 7, "generation_id": "e2e-chal-solved", "certificates": {}, "hosts": {"chal.test": config}}))
        runtime.chmod(0o644)
        run("openssl", "req", "-x509", "-newkey", "rsa:2048", "-nodes", "-days", "1", "-subj", "/CN=chal.test", "-keyout", str(temporary / "tls.key"), "-out", str(temporary / "tls.crt"))
        for item in (temporary / "tls.key", temporary / "tls.crt"):
            item.chmod(0o644)
        main_conf = run("docker", "run", "--rm", "--entrypoint", "cat", IMAGE, "/usr/local/openresty/nginx/conf/nginx.conf").stdout
        logged = main_conf.replace("access_log syslog:server=vector:9000,facility=local7,tag=cdnfoundry_edge,nohostname edge_json;", "access_log /dev/stdout edge_json;")
        assert logged != main_conf
        (temporary / "nginx.conf").write_text(logged)
        (temporary / "nginx.conf").chmod(0o644)
        try:
            run("docker", "run", "-d", "--name", CELL, "--network", NETWORK, "-e", "EDGE_RUNTIME_FILE=/var/lib/cdnfoundry/runtime/active.json", "-e", "EDGE_STATUS_TOKEN=e2e",
                "-e", f"EDGE_PROXY_PROTOCOL_TRUSTED_CIDRS={subnet}", "-e", "EDGE_CHALLENGE_BITS=12",
                "-v", f"{temporary / 'nginx.conf'}:/usr/local/openresty/nginx/conf/nginx.conf:ro", "-v", f"{runtime}:/var/lib/cdnfoundry/runtime/active.json:ro",
                "-v", f"{pathlib.Path(__file__).resolve().parents[2] / 'docker/openresty/runtime.lua'}:/etc/cdnfoundry/runtime.lua:ro",
                "-v", f"{pathlib.Path(__file__).resolve().parents[2] / 'docker/openresty/challenge_solver.js'}:/etc/cdnfoundry/challenge_solver.js:ro",
                "-v", f"{temporary / 'tls.crt'}:/run/edge/tls.crt:ro", "-v", f"{temporary / 'tls.key'}:/run/edge/tls.key:ro",
                "--tmpfs", "/var/cache/nginx:rw,noexec,nosuid,size=64m,mode=0777", IMAGE)
            ip = address(CELL)
            deadline = time.monotonic() + 60
            while time.monotonic() < deadline:
                try:
                    request(ip, "198.51.100.250")
                    break
                except OSError:
                    time.sleep(1)
            else:
                raise AssertionError(run("docker", "logs", CELL, check=False).stderr)

            seed, bits = challenge(ip, "198.51.100.10")
            status, headers, _ = request(ip, "198.51.100.10", "POST", "/.cdnf/challenge", body=f"seed={urllib.parse.quote(seed)}&counter={solve(seed, bits)}")
            assert status == 204 and "_chk=" in headers["set-cookie"], status
            # The neutral cookie passes, and so does the name and path used before the challenge lost its product name.
            cookie = re.search(r"_chk=([0-9a-z.]+)", headers["set-cookie"]).group(1)
            assert request(ip, "198.51.100.10", headers={"Cookie": f"cdnf_chal={cookie}"})[0] == 200, "a token issued before the rename still passes"
            assert "Cookie" not in page_of(ip) and "_chk" in headers["set-cookie"] and "cdnf" not in headers["set-cookie"], headers["set-cookie"]
            challenge(ip, "198.51.100.11")  # challenged and never solves
            time.sleep(1)

            logs = run("docker", "logs", CELL, check=False)
            lines = [json.loads(line) for line in (logs.stdout + logs.stderr).splitlines() if line.startswith("{")]
            lines = [line for line in lines if line.get("hostname") == "chal.test"]
            solved = [line for line in lines if line["security_reason"] == "challenge_solved"]
            assert len(solved) == 1, solved
            assert solved[0]["security_action"] == "allow" and solved[0]["status"] == 204 and solved[0]["path"] == "/.cdnf/challenge", solved[0]
            assert re.fullmatch(r"[0-9a-f]{12}", solved[0]["client_key"]), solved[0]
            prompts = {line["client_ip"]: line for line in lines if line["security_reason"] == "challenge_required"}
            assert {"198.51.100.10", "198.51.100.11"} <= set(prompts), prompts.keys()
            assert prompts["198.51.100.10"]["client_key"] == solved[0]["client_key"] and solved[0]["client_ip"] == "198.51.100.10", (prompts, solved)
            assert prompts["198.51.100.11"]["client_key"] != solved[0]["client_key"]
            assert prompts["198.51.100.10"]["occurred_at"] <= solved[0]["occurred_at"]
            print("a solved challenge is logged as an allowed request carrying the client key of its challenge")
        finally:
            run("docker", "rm", "-f", CELL, ORIGIN, check=False)
    print("e2e challenge solved trace qualification passed")


if __name__ == "__main__":
    main()
