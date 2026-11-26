#!/usr/bin/env python3
"""Real-runtime qualification that a rate-limit rule keeps limiting when the shared dictionary itself has no memory left.

A cell serves 700 domains, past the point where each domain's minimum share still fits, and its rate-limit dictionary is the
smallest nginx accepts, so keys of many domains exhaust the physical memory, not only a domain's share. Keys first seen after
that must still be limited: the overflow counter falls back to the worker's own memory. The cell must keep serving, never log an
allocation failure as a crash, and report `rate_limit_state_full`.
"""

import json
import pathlib
import sys
import tempfile
import time
from concurrent.futures import ThreadPoolExecutor

sys.path.insert(0, str(pathlib.Path(__file__).resolve().parent))
from managed_waf import IMAGE, NETWORK, run  # noqa: E402
from rate_limit_isolation import address, domain, get, security_events  # noqa: E402

CELL = "cdnf-e2e-rate-exhaustion"
ORIGIN = "cdnf-e2e-rate-exhaustion-origin"
DOMAINS = 700
KEYS_PER_DOMAIN = 6


def main() -> None:
    run("docker", "rm", "-f", CELL, ORIGIN, check=False)
    run("docker", "run", "-d", "--name", ORIGIN, "--network", NETWORK, "nginx:1.30.3-alpine")
    origin_ip = address(ORIGIN)
    with tempfile.TemporaryDirectory(prefix="cdnf-e2e-exhaust-") as directory:
        temporary = pathlib.Path(directory)
        temporary.chmod(0o755)
        hosts = {f"d{index}.exhaust.test": domain(f"d{index}.exhaust.test", 1000 + index, origin_ip) for index in range(1, DOMAINS + 1)}
        runtime = temporary / "runtime.json"
        runtime.write_text(json.dumps({"schema_version": 1, "sequence": 1, "generation_id": "e2e-exhaust-1", "certificates": {}, "hosts": hosts}))
        runtime.chmod(0o644)
        run("openssl", "req", "-x509", "-newkey", "rsa:2048", "-nodes", "-days", "1", "-subj", "/CN=exhaust.test",
            "-keyout", str(temporary / "tls.key"), "-out", str(temporary / "tls.crt"))
        for item in (temporary / "tls.key", temporary / "tls.crt"):
            item.chmod(0o644)
        configuration = run("docker", "run", "--rm", "--entrypoint", "cat", IMAGE, "/etc/nginx/conf.d/default.conf").stdout
        small = [line for line in configuration.splitlines() if line.startswith("lua_shared_dict rate_rules ")]
        assert len(small) == 1, small
        shrunk = temporary / "tiny-rate-rules.conf"
        shrunk.write_text(configuration.replace(small[0], "lua_shared_dict rate_rules 32k;"))
        try:
            run("docker", "run", "-d", "--name", CELL, "--network", NETWORK,
                "-e", "EDGE_RUNTIME_FILE=/var/lib/cdnfoundry/runtime/active.json", "-e", "EDGE_STATUS_TOKEN=e2e",
                "-v", f"{runtime}:/var/lib/cdnfoundry/runtime/active.json:ro", "-v", f"{shrunk}:/etc/nginx/conf.d/default.conf:ro",
                "-v", f"{temporary / 'tls.crt'}:/run/edge/tls.crt:ro", "-v", f"{temporary / 'tls.key'}:/run/edge/tls.key:ro",
                "--tmpfs", "/var/cache/nginx:rw,noexec,nosuid,size=64m,mode=0777", IMAGE)
            ip = address(CELL)
            deadline = time.monotonic() + 90
            while time.monotonic() < deadline:
                try:
                    if get(ip, "d1.exhaust.test", "ready") == 200:
                        break
                except OSError:
                    pass
                time.sleep(1)
            else:
                raise AssertionError(run("docker", "logs", CELL, check=False).stderr)
            workers = int(run("docker", "exec", CELL, "sh", "-c", "ps -o args | grep -c 'nginx: worker'").stdout.strip() or "1")

            # Distinct keys on every domain exhaust the dictionary: each key is a fresh entry, far beyond what 32 KiB holds.
            def spread(index: int) -> None:
                for key in range(KEYS_PER_DOMAIN):
                    get(ip, f"d{index}.exhaust.test", f"spread-{index}-{key}")
            with ThreadPoolExecutor(max_workers=8) as executor:
                list(executor.map(spread, range(1, DOMAINS + 1)))
            time.sleep(4)

            # Keys first seen now are still held to the rule: threshold 2 per window, per worker at worst, never unlimited.
            statuses = [get(ip, "d700.exhaust.test", f"late-{index}") for index in range(60)]
            allowed = statuses.count(200)
            assert 429 in statuses, f"keys first seen after the dictionary ran out of memory were never limited: {statuses}"
            assert allowed <= 2 * workers + 2, f"{allowed} requests got through a threshold of 2 with {workers} workers"
            events = [event for event in security_events(CELL) if event["reason_code"] == "rate_limit_state_full"]
            assert events, "the cell did not report that its rate-limit state was full"
            log = run("docker", "logs", CELL, check=False)
            output = log.stdout + log.stderr
            assert "attempt to" not in output and "[emerg]" not in output and "[alert]" not in output, output[-800:]
            assert get(ip, "d1.exhaust.test", "still-serving-1") in (200, 429)
            print(f"workers {workers}, requests allowed after exhaustion {allowed} of 60")
        finally:
            run("docker", "rm", "-f", CELL, ORIGIN, check=False)
    print("e2e rate-limit memory exhaustion qualification passed")


if __name__ == "__main__":
    main()
