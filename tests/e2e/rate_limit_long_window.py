#!/usr/bin/env python3
"""Real-cell qualification of long-window rate limits across cells.

A rule of the shared scope with a 1 hour period divides its threshold by the cells that serve the domain. When that count is stale
(a cell was added and the snapshot has not caught up) every cell admits the whole threshold on its own. The control plane closes that
gap from what the cells report. This test plays the part of the edge agent against three real cells and checks the cell side of it:
  1. a cell lists the heaviest keys of its current window, as hashes, with their counts, at most 50, and never a client address,
  2. a key over the threshold across the three cells (and under it on each) is not blocked until a mitigation arrives, and is blocked
     on every cell after it, while another client is not,
  3. a cell that restarts reports no version, is given the mitigations again, and blocks the key again,
  4. a mitigation ends by the cell's own clock, and an older version is ignored,
  5. a malformed command is refused.
"""

import hashlib
import ipaddress
import json
import pathlib
import sys
import tempfile
import time

sys.path.insert(0, str(pathlib.Path(__file__).resolve().parent))
from managed_waf import IMAGE, NETWORK, run  # noqa: E402
from rate_limit_isolation import address  # noqa: E402
from rate_limit_semantics import Client, domain, rule  # noqa: E402

CELLS = ["cdnf-e2e-rate-long-a", "cdnf-e2e-rate-long-b", "cdnf-e2e-rate-long-c"]
ORIGIN = "cdnf-e2e-rate-long-origin"
DOMAIN_ID, RULE_ID = 1301, 1
THRESHOLD = 90


def key_hash(client: str) -> str:
    """A cell counts an IPv4 client by its 4 address bytes; the md5 of that is all the control plane ever sees."""
    return hashlib.md5(ipaddress.ip_address(client).packed).hexdigest()


def status(name: str) -> dict:
    out = run("docker", "exec", name, "wget", "-qO-", "--header=X-Edge-Status-Token: e2e", "http://127.0.0.1:9080/passive-failures", check=False).stdout
    return json.loads(out)["cell"]


def control(name: str, payload: dict) -> int:
    result = run("docker", "exec", name, "wget", "-q", "-O-", "--header=X-Edge-Status-Token: e2e", "--header=Content-Type: application/json",
                 "--post-data=" + json.dumps(payload), "http://127.0.0.1:9080/control", check=False)
    return 200 if result.returncode == 0 else 400


def mitigation(client: str, seconds: int, version: int, task: str) -> dict:
    return {"task_id": task, "action": "rate_mitigations", "version": version,
            "items": [{"domain_id": DOMAIN_ID, "rule_id": RULE_ID, "hash": key_hash(client), "seconds": seconds}]}


def wait_ready(ip: str) -> None:
    deadline = time.monotonic() + 90
    while time.monotonic() < deadline:
        try:
            if Client(ip).get("agg.rate.test", "/ready", client="203.0.113.250") in (200, 404):
                return
        except OSError:
            pass
        time.sleep(1)
    raise AssertionError("a cell did not start")


def main() -> None:
    run("docker", "rm", "-f", ORIGIN, *CELLS, check=False)
    with tempfile.TemporaryDirectory(prefix="cdnf-e2e-rate-long-") as directory:
        temporary = pathlib.Path(directory)
        temporary.chmod(0o755)
        conf = temporary / "origin.conf"
        conf.write_text("server { listen 80; location / { return 200 \"ok\"; } }\n")
        conf.chmod(0o644)
        run("docker", "run", "-d", "--name", ORIGIN, "--network", NETWORK, "-v", f"{conf}:/etc/nginx/conf.d/default.conf:ro", "nginx:1.30.3-alpine")
        shared = rule("/", "client_ip", THRESHOLD, 3600, 600, scope="shared")
        shared["id"] = RULE_ID
        # cells_serving is 1: the snapshot has not caught up with three cells, so each cell admits the whole threshold.
        hosts = {"agg.rate.test": domain("agg.rate.test", DOMAIN_ID, address(ORIGIN), shared, cells_serving=1)}
        runtime = temporary / "runtime.json"
        runtime.write_text(json.dumps({"schema_version": 1, "sequence": 1, "generation_id": "rate-long-1", "certificates": {}, "hosts": hosts}))
        runtime.chmod(0o644)
        run("openssl", "req", "-x509", "-newkey", "rsa:2048", "-nodes", "-days", "1", "-subj", "/CN=rate.test", "-keyout", str(temporary / "tls.key"), "-out", str(temporary / "tls.crt"))
        for item in (temporary / "tls.key", temporary / "tls.crt"):
            item.chmod(0o644)

        def start(name: str) -> None:
            run("docker", "run", "-d", "--name", name, "--network", NETWORK, "--cpus", "1",
                "-e", "EDGE_RUNTIME_FILE=/var/lib/cdnfoundry/runtime/active.json", "-e", "EDGE_STATUS_TOKEN=e2e",
                "-v", f"{runtime}:/var/lib/cdnfoundry/runtime/active.json:ro",
                "-v", f"{temporary / 'tls.crt'}:/run/edge/tls.crt:ro", "-v", f"{temporary / 'tls.key'}:/run/edge/tls.key:ro",
                "--tmpfs", "/var/cache/nginx:rw,noexec,nosuid,size=64m,mode=0777", IMAGE)

        try:
            for name in CELLS:
                start(name)
            ips = [address(name) for name in CELLS]
            for ip in ips:
                wait_ready(ip)
            # Keep the 1 hour window away from its boundary, so a window ending mid-test is not mistaken for a result.
            if time.time() % 3600 > 3300:
                time.sleep(3600 - time.time() % 3600 + 1)
            sessions = [Client(ip) for ip in ips]
            results: dict[str, object] = {}
            heavy = "203.0.113.50"

            # 1 and 2. 40 requests on each of three cells is 120 in all, over the threshold of 90 and under it on every cell.
            for session in sessions:
                assert all(session.get("agg.rate.test", client=heavy) == 200 for _ in range(40)), "a cell blocked a key under its own threshold"
            reports = [status(name) for name in CELLS]
            for report in reports:
                entries = [entry for entry in report["long_rate"] if entry["hash"] == key_hash(heavy)]
                assert len(entries) == 1 and entries[0]["count"] == 40 and entries[0]["domain_id"] == DOMAIN_ID and entries[0]["rule_id"] == RULE_ID, report["long_rate"]
                assert entries[0]["window"] == int(time.time() // 3600)
                assert heavy not in json.dumps(report), "a client address appeared in a cell's status"
                assert report.get("rate_mitigations_version") is None
            total = sum(entry["count"] for report in reports for entry in report["long_rate"] if entry["hash"] == key_hash(heavy))
            assert total >= THRESHOLD, total
            results["added_up_across_cells"] = total

            for name in CELLS:
                assert control(name, mitigation(heavy, 300, 1, "rate-mitigations-1-" + name)) == 200
            assert [status(name)["rate_mitigations_version"] for name in CELLS] == [1, 1, 1]
            for session in sessions:
                assert session.get("agg.rate.test", client=heavy) == 429, "a mitigated key was admitted by a cell"
                assert session.get("agg.rate.test", client="203.0.113.51") == 200, "another client was mitigated with it"

            # 3. A restarted cell has no version and no markers; the agent gives them again.
            run("docker", "rm", "-f", CELLS[2])
            start(CELLS[2])
            ips[2] = address(CELLS[2])
            wait_ready(ips[2])
            sessions[2] = Client(ips[2])
            assert status(CELLS[2]).get("rate_mitigations_version") is None
            assert sessions[2].get("agg.rate.test", client=heavy) == 200, "the restarted cell kept a marker it cannot have"
            assert control(CELLS[2], mitigation(heavy, 300, 1, "rate-mitigations-1-again")) == 200
            assert sessions[2].get("agg.rate.test", client=heavy) == 429

            # 4. A mitigation ends by the cell's clock, and an older version is ignored.
            other = "203.0.113.52"
            assert control(CELLS[0], mitigation(other, 3, 2, "rate-mitigations-2")) == 200
            assert sessions[0].get("agg.rate.test", client=other) == 429
            assert control(CELLS[0], mitigation("203.0.113.53", 300, 1, "rate-mitigations-old")) == 200
            assert sessions[0].get("agg.rate.test", client="203.0.113.53") == 200, "an older version was applied"
            assert status(CELLS[0])["rate_mitigations_version"] == 2
            time.sleep(5)
            assert sessions[0].get("agg.rate.test", client=other) == 200, "a mitigation outlived its time"

            # 5. Malformed commands are refused and change nothing.
            for bad in ({"items": [{"domain_id": 1, "rule_id": 1, "hash": "zz", "seconds": 5}]},
                        {"items": [{"domain_id": 1, "rule_id": 1, "hash": key_hash("192.0.2.1"), "seconds": 0}]},
                        {"items": [{"domain_id": 1, "rule_id": 1, "hash": key_hash("192.0.2.1"), "seconds": 999999}]},
                        {"items": [{"domain_id": 1, "rule_id": 1, "hash": key_hash("192.0.2.1"), "seconds": 5}] * 501}):
                assert control(CELLS[1], {"task_id": "bad", "action": "rate_mitigations", "version": 5, **bad}) == 400
            assert status(CELLS[1])["rate_mitigations_version"] == 1

            # 1 again. The report is bounded: 60 distinct heavy clients still report 50 keys, the heaviest first.
            for index in range(60):
                for _ in range(6 + index // 10):
                    sessions[1].get("agg.rate.test", client=f"198.18.{index // 250}.{index % 250 + 1}")
            long_rate = status(CELLS[1])["long_rate"]
            counts = [entry["count"] for entry in long_rate]
            assert len(long_rate) == 50 and counts == sorted(counts, reverse=True), (len(long_rate), counts[:5])
            results["reported_keys_of_60_heavy_clients"] = len(long_rate)
            print(json.dumps(results))
        finally:
            run("docker", "rm", "-f", ORIGIN, *CELLS, check=False)
    print("e2e long-window rate limit qualification passed")


if __name__ == "__main__":
    main()
