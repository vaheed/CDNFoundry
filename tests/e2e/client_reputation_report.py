#!/usr/bin/env python3
"""Real-cell qualification of the offending-client report that shared reputation reads (the shadow stage).

A cell runs the real image with tight per-client limits. Clients that send requests quickly cause escalating events, and a client that
sends clean requests after one event keeps its share low. The test checks what the cell reports in its status: the client keys as
text (IPv4 and an IPv6 prefix), the events and the requests seen since the first, the ranking, the bound of 50 clients, that exempt
reasons and a quiet client are never listed, and that a full dictionary or a flood of clients allocates nothing it should not.
"""

import json
import pathlib
import sys
import tempfile
import time

sys.path.insert(0, str(pathlib.Path(__file__).resolve().parent))
from client_containment import domain, fetch  # noqa: E402
from ipv6_rate_limit_keys import address  # noqa: E402
from managed_waf import IMAGE, NETWORK, run  # noqa: E402

CELL = "cdnf-e2e-reputation"
ORIGIN = "cdnf-e2e-reputation-origin"


def offenders(name: str) -> list[dict]:
    result = run("docker", "exec", name, "wget", "-q", "-O-", "--header=X-Edge-Status-Token: e2e", "http://127.0.0.1:9080/passive-failures")
    return json.loads(result.stdout)["cell"]["client_offenders"]


def memory(name: str) -> dict:
    result = run("docker", "exec", name, "wget", "-q", "-O-", "--header=X-Edge-Status-Token: e2e", "http://127.0.0.1:9080/passive-failures")
    return json.loads(result.stdout)["cell"]["shared_memory"]["client_reputation"]


def main() -> None:
    run("docker", "rm", "-f", ORIGIN, CELL, check=False)
    run("docker", "run", "-d", "--name", ORIGIN, "--network", NETWORK, "nginx:1.30.3-alpine")
    origin_ip = address(ORIGIN)
    subnet = json.loads(run("docker", "network", "inspect", NETWORK).stdout)[0]["IPAM"]["Config"][0]["Subnet"]
    with tempfile.TemporaryDirectory(prefix="cdnf-e2e-reputation-") as directory:
        temporary = pathlib.Path(directory)
        temporary.chmod(0o755)
        hosts = {"loud.rep.test": domain("loud.rep.test", 501, origin_ip)}
        runtime = temporary / "runtime.json"
        runtime.write_text(json.dumps({"schema_version": 1, "sequence": 1, "generation_id": "e2e-rep-1", "certificates": {}, "hosts": hosts}))
        runtime.chmod(0o644)
        run("openssl", "req", "-x509", "-newkey", "rsa:2048", "-nodes", "-days", "1", "-subj", "/CN=rep.test", "-keyout", str(temporary / "tls.key"), "-out", str(temporary / "tls.crt"))
        for item in (temporary / "tls.key", temporary / "tls.crt"):
            item.chmod(0o644)
        try:
            run("docker", "run", "-d", "--name", CELL, "--network", NETWORK, "-e", "EDGE_RUNTIME_FILE=/var/lib/cdnfoundry/runtime/active.json", "-e", "EDGE_STATUS_TOKEN=e2e",
                "-e", f"EDGE_PROXY_PROTOCOL_TRUSTED_CIDRS={subnet}", "-v", f"{runtime}:/var/lib/cdnfoundry/runtime/active.json:ro",
                "-v", f"{temporary / 'tls.crt'}:/run/edge/tls.crt:ro", "-v", f"{temporary / 'tls.key'}:/run/edge/tls.key:ro",
                "--tmpfs", "/var/cache/nginx:rw,noexec,nosuid,size=64m,mode=0777", IMAGE)
            ip = address(CELL)
            deadline = time.monotonic() + 60
            while time.monotonic() < deadline:
                try:
                    if fetch(ip, "loud.rep.test", "198.51.100.250")[0] == 200:
                        break
                except OSError:
                    pass
                time.sleep(1)
            else:
                raise AssertionError(run("docker", "logs", CELL, check=False).stderr)
            time.sleep(1.2)
            first = offenders(CELL)
            assert first == [], f"a client that sent clean requests was reported: {first}"

            # A loud IPv4 client and a loud IPv6 client: each is listed under its own key, as text, with events inside its requests.
            for source in ("203.0.113.10", "2001:db8:77:1::1"):
                for _ in range(60):
                    fetch(ip, "loud.rep.test", source)
            listed = {entry["client"]: entry for entry in offenders(CELL)}
            assert "203.0.113.10" in listed and "2001:db8:77:1:0:0:0:0" in listed, listed
            for entry in listed.values():
                assert entry["domain_id"] == 501 and entry["events"] >= 1 and entry["requests"] >= entry["events"], entry
            # The share the control plane reads is high for a client that only floods.
            assert listed["203.0.113.10"]["events"] * 2 >= listed["203.0.113.10"]["requests"] * 0.5, listed["203.0.113.10"]

            # A second address of the same /64 is the same client; it adds to the same entry instead of a new one.
            fetch(ip, "loud.rep.test", "2001:db8:77:1:aaaa::9")
            assert sum(1 for entry in offenders(CELL) if entry["client"].startswith("2001:db8:77:1:")) == 1

            # A flood of distinct clients is bounded in what the cell reports and in the memory it takes.
            for index in range(1500):
                for _ in range(2):
                    fetch(ip, "loud.rep.test", f"100.64.{index // 250}.{index % 250 + 1}")
            reported = offenders(CELL)
            assert len(reported) <= 50, len(reported)
            assert reported == sorted(reported, key=lambda entry: -entry["events"]), "the report is not ranked by events"
            used = memory(CELL)
            assert used["free_bytes"] > 0.2 * used["capacity_bytes"], used
            log = run("docker", "logs", CELL, check=False)
            assert "attempt to" not in log.stdout + log.stderr and "no memory" not in log.stdout + log.stderr, (log.stderr or log.stdout)[-800:]

            # The entries end with their window: after five minutes of quiet the cell reports nothing, so nothing stale is forwarded.
            if "--wait" in sys.argv:
                time.sleep(330)
                assert offenders(CELL) == []
        finally:
            run("docker", "rm", "-f", ORIGIN, CELL, check=False)
    print("e2e client reputation cell report passed")


if __name__ == "__main__":
    main()
