#!/usr/bin/env python3
"""Real-runtime qualification that one noisy client is contained on its own and that the cell reports how many clients took part.

A cell runs the real image with deliberately tight per-client limits, so a client that sends requests quickly causes
`client_rate_exceeded` events, which are escalating events. One client is contained after it crosses the per-client threshold,
other clients and other domains are untouched, and the cell's report to the agent says how many distinct clients caused the
events. The control plane's side of the rule (a domain tightens only when enough distinct clients take part) is covered by the
Laravel tests; this proves the cell produces the evidence that rule reads.
"""

import json
import pathlib
import socket
import sys
import tempfile
import time

sys.path.insert(0, str(pathlib.Path(__file__).resolve().parent))
from ipv6_rate_limit_keys import address, proxy_header  # noqa: E402
from managed_waf import IMAGE, NETWORK, host, run  # noqa: E402

CELL = "cdnf-e2e-containment"
ORIGIN = "cdnf-e2e-containment-origin"


def fetch(cell_ip: str, hostname: str, source: str) -> tuple[int, str]:
    """One request from `source`: its status and the security reason the cell gave."""
    request = f"GET / HTTP/1.1\r\nHost: {hostname}\r\nConnection: close\r\n\r\n".encode()
    with socket.create_connection((cell_ip, 8081), timeout=10) as connection:
        connection.sendall(proxy_header(source) + request)
        data = b""
        while b"\r\n\r\n" not in data:
            chunk = connection.recv(4096)
            if not chunk:
                break
            data += chunk
    head = data.split(b"\r\n\r\n", 1)[0].decode("latin1")
    reason = next((line.split(":", 1)[1].strip() for line in head.split("\r\n") if line.lower().startswith("x-cdnf-security-reason:")), "")
    return int(head.split(" ", 2)[1]), reason


def domain(name: str, identifier: int, origin_ip: str) -> dict:
    config = host("off", False, 0, origin_ip)
    config["domain"], config["domain_id"] = name, identifier
    # One request per second and a burst of one: a client that sends more is refused with client_rate_exceeded.
    config["security"]["limits"].update({"requests_per_second": 1, "request_burst": 1, "connections_per_client": 256, "connections_per_domain": 100000})
    return config


def evidence(name: str, domain_id: int) -> list[dict]:
    result = run("docker", "exec", name, "wget", "-q", "-O-", "--header=X-Edge-Status-Token: e2e", "http://127.0.0.1:9080/passive-failures")
    return [event for event in json.loads(result.stdout)["security"] if event["domain_id"] == domain_id]


def flood(cell_ip: str, hostname: str, source: str, requests: int) -> list[tuple[int, str]]:
    return [fetch(cell_ip, hostname, source) for _ in range(requests)]


def main() -> None:
    run("docker", "rm", "-f", ORIGIN, CELL, check=False)
    run("docker", "run", "-d", "--name", ORIGIN, "--network", NETWORK, "nginx:1.30.3-alpine")
    origin_ip = address(ORIGIN)
    subnet = json.loads(run("docker", "network", "inspect", NETWORK).stdout)[0]["IPAM"]["Config"][0]["Subnet"]
    with tempfile.TemporaryDirectory(prefix="cdnf-e2e-contain-") as directory:
        temporary = pathlib.Path(directory)
        temporary.chmod(0o755)
        hosts = {"noisy.contain.test": domain("noisy.contain.test", 401, origin_ip), "quiet.contain.test": domain("quiet.contain.test", 402, origin_ip)}
        runtime = temporary / "runtime.json"
        runtime.write_text(json.dumps({"schema_version": 1, "sequence": 1, "generation_id": "e2e-contain-1", "certificates": {}, "hosts": hosts}))
        runtime.chmod(0o644)
        run("openssl", "req", "-x509", "-newkey", "rsa:2048", "-nodes", "-days", "1", "-subj", "/CN=contain.test",
            "-keyout", str(temporary / "tls.key"), "-out", str(temporary / "tls.crt"))
        for item in (temporary / "tls.key", temporary / "tls.crt"):
            item.chmod(0o644)
        try:
            run("docker", "run", "-d", "--name", CELL, "--network", NETWORK,
                "-e", "EDGE_RUNTIME_FILE=/var/lib/cdnfoundry/runtime/active.json", "-e", "EDGE_STATUS_TOKEN=e2e",
                "-e", f"EDGE_PROXY_PROTOCOL_TRUSTED_CIDRS={subnet}",
                "-v", f"{runtime}:/var/lib/cdnfoundry/runtime/active.json:ro",
                "-v", f"{temporary / 'tls.crt'}:/run/edge/tls.crt:ro", "-v", f"{temporary / 'tls.key'}:/run/edge/tls.key:ro",
                "--tmpfs", "/var/cache/nginx:rw,noexec,nosuid,size=64m,mode=0777", IMAGE)
            ip = address(CELL)
            deadline = time.monotonic() + 60
            while time.monotonic() < deadline:
                try:
                    if fetch(ip, "quiet.contain.test", "198.51.100.250")[0] == 200:
                        break
                except OSError:
                    pass
                time.sleep(1)
            else:
                raise AssertionError(run("docker", "logs", CELL, check=False).stderr)
            time.sleep(1.2)

            # One client sends far more than it may. It first meets the per-client limit, then is contained outright.
            results = flood(ip, "noisy.contain.test", "203.0.113.10", 120)
            reasons = [reason for _, reason in results]
            assert "client_rate_exceeded" in reasons, reasons
            assert reasons[-1] == "client_temporarily_blocked", f"the client was never contained: {set(reasons)}"
            contained_at = reasons.index("client_temporarily_blocked")
            assert all(reason == "client_temporarily_blocked" for reason in reasons[contained_at:]), "a contained client got through again"
            assert all(status == 429 for status, reason in results if reason)

            # The containment is the client's alone: another client of the same domain is served, and so is this client elsewhere.
            time.sleep(1.2)
            assert fetch(ip, "noisy.contain.test", "203.0.113.11") == (200, ""), "another client of the domain was affected"
            time.sleep(1.2)
            assert fetch(ip, "quiet.contain.test", "203.0.113.10") == (200, ""), "the ban followed the client to another domain"

            # One client, however loud, is one client in the evidence the cell reports.
            events = evidence(CELL, 401)
            assert events and all(event["clients"] == 1 for event in events), events
            assert any(event["reason_code"] == "client_rate_exceeded" for event in events)

            # A client counts under its IPv6 prefix: its other addresses are contained with it, and another /64 is not.
            flood(ip, "quiet.contain.test", "2001:db8:77:1::1", 120)
            assert fetch(ip, "quiet.contain.test", "2001:db8:77:1:aaaa::2")[1] == "client_temporarily_blocked"
            time.sleep(1.2)
            assert fetch(ip, "quiet.contain.test", "2001:db8:77:2::1") == (200, "")

            # Many clients each causing a few events is what the report must show as many distinct clients.
            for index in range(12):
                time.sleep(0.25)
                source = f"198.51.100.{index + 20}"
                for _ in range(6):
                    fetch(ip, "noisy.contain.test", source)
            events = evidence(CELL, 401)
            assert max(event["clients"] for event in events) >= 10, events
            log = run("docker", "logs", CELL, check=False)
            assert "attempt to" not in log.stdout + log.stderr, log.stderr[-800:]
        finally:
            run("docker", "rm", "-f", ORIGIN, CELL, check=False)
    print("e2e client containment qualification passed")


if __name__ == "__main__":
    main()
