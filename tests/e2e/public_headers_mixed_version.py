#!/usr/bin/env python3
"""Mixed-version rollout of the public response headers: a cell that predates the field receives the new snapshot.

The new snapshot carries both the new `settings.public_headers` and the old `settings.diagnostic_headers`, set to the same decision.
A cell built from the commit before the public headers existed ignores the new field, must keep serving, and follows the old one:
the diagnostic names it always had when the decision is on, none when it is off, and no public header it does not know. The
reverse direction (a new cell with an old snapshot) is covered by public_response_headers.py.

The old image is built from the commit before the public headers (the `PRE_PUBLIC_HEADERS` commit) in a temporary git worktree.
"""

import http.client
import json
import pathlib
import subprocess
import sys
import tempfile
import time

sys.path.insert(0, str(pathlib.Path(__file__).resolve().parent))
from managed_waf import NETWORK, host, run  # noqa: E402
from rate_limit_isolation import address  # noqa: E402

ROOT = pathlib.Path(__file__).resolve().parents[2]
OLD_IMAGE = "cdnfoundry/edge-runtime:before-public-headers"
PRE_PUBLIC_HEADERS = "82596ad0^"  # the parent of "send a per-request reference, the cache decision, the PoP and a mitigation marker"
CELL, ORIGIN = "cdnf-e2e-mixed-headers", "cdnf-e2e-mixed-headers-origin"


def build_old_image() -> None:
    if run("docker", "image", "inspect", OLD_IMAGE, check=False).returncode == 0:
        return
    with tempfile.TemporaryDirectory(prefix="cdnf-before-headers-") as directory:
        tree = pathlib.Path(directory) / "tree"
        run("git", "worktree", "add", "--detach", str(tree), PRE_PUBLIC_HEADERS)
        try:
            subprocess.run(["docker", "build", "-q", "-f", "docker/openresty/Dockerfile", "-t", OLD_IMAGE, "."], cwd=tree, check=True, capture_output=True, text=True)
        finally:
            run("git", "worktree", "remove", "--force", str(tree), check=False)


def get(ip: str, hostname: str) -> tuple[int, dict[str, str]]:
    connection = http.client.HTTPConnection(ip, 8080, timeout=10)
    connection.request("GET", "/", headers={"Host": hostname})
    response = connection.getresponse()
    response.read()
    headers = {name.lower(): value for name, value in response.getheaders()}
    connection.close()
    return response.status, headers


def snapshot(name: str, identifier: int, origin_ip: str, diagnostics: bool) -> dict:
    config = host("off", False, 0, origin_ip)
    config["domain"], config["domain_id"] = name, identifier
    config["security"]["limits"].update({"requests_per_second": 100000, "request_burst": 100000, "connections_per_client": 4096, "connections_per_domain": 100000})
    # What the control plane compiles today: the decision in both fields, so a cell of either version follows it.
    config["settings"]["diagnostics"] = "enabled" if diagnostics else "disabled"
    config["settings"]["diagnostic_headers"] = diagnostics
    config["settings"]["public_headers"] = {"enabled": True, "prefix": "edge-", "pop": "eu-west", "diagnostics": diagnostics}
    return config


def main() -> None:
    build_old_image()
    run("docker", "rm", "-f", CELL, ORIGIN, check=False)
    run("docker", "run", "-d", "--name", ORIGIN, "--network", NETWORK, "nginx:1.30.3-alpine")
    with tempfile.TemporaryDirectory(prefix="cdnf-e2e-mixed-headers-") as directory:
        temporary = pathlib.Path(directory)
        temporary.chmod(0o755)
        origin_ip = address(ORIGIN)
        hosts = {"on.mixed.test": snapshot("on.mixed.test", 831, origin_ip, True), "off.mixed.test": snapshot("off.mixed.test", 832, origin_ip, False)}
        (temporary / "runtime.json").write_text(json.dumps({"schema_version": 1, "sequence": 4, "generation_id": "e2e-mixed-headers-1", "certificates": {}, "hosts": hosts}))
        run("openssl", "req", "-x509", "-newkey", "rsa:2048", "-nodes", "-days", "1", "-subj", "/CN=mixed.test", "-keyout", str(temporary / "tls.key"), "-out", str(temporary / "tls.crt"))
        main_conf = run("docker", "run", "--rm", "--entrypoint", "cat", OLD_IMAGE, "/usr/local/openresty/nginx/conf/nginx.conf").stdout
        logged = main_conf.replace("access_log syslog:server=vector:9000,facility=local7,tag=cdnfoundry_edge,nohostname edge_json;", "access_log /dev/stdout edge_json;")
        assert logged != main_conf
        (temporary / "nginx.conf").write_text(logged)
        for item in temporary.iterdir():
            item.chmod(0o644)
        try:
            run("docker", "run", "-d", "--name", CELL, "--network", NETWORK, "-e", "EDGE_RUNTIME_FILE=/var/lib/cdnfoundry/runtime/active.json", "-e", "EDGE_STATUS_TOKEN=e2e",
                "-v", f"{temporary / 'nginx.conf'}:/usr/local/openresty/nginx/conf/nginx.conf:ro", "-v", f"{temporary / 'runtime.json'}:/var/lib/cdnfoundry/runtime/active.json:ro",
                "-v", f"{temporary / 'tls.crt'}:/run/edge/tls.crt:ro", "-v", f"{temporary / 'tls.key'}:/run/edge/tls.key:ro",
                "--tmpfs", "/var/cache/nginx:rw,noexec,nosuid,size=64m,mode=0777", OLD_IMAGE)
            ip = address(CELL)
            deadline = time.monotonic() + 60
            while time.monotonic() < deadline:
                try:
                    if get(ip, "on.mixed.test")[0] in (200, 404):
                        break
                except OSError:
                    pass
                time.sleep(1)
            else:
                raise AssertionError(run("docker", "logs", CELL, check=False).stderr)
            status, on = get(ip, "on.mixed.test")
            assert status == 200, "the old cell keeps serving a snapshot with a field it does not know"
            assert on.get("x-cdnf-cache") in ("MISS", "HIT", "BYPASS") and "server" in on, f"diagnostics on: the old names, as before: {on}"
            _, off = get(ip, "off.mixed.test")
            assert not [name for name in off if name.startswith("x-cdnf-")] and "server" not in off, f"diagnostics off: none: {off}"
            assert not [name for name in {**on, **off} if name.startswith("edge-")], "the old cell knows no public header and sends none"
            logs = run("docker", "logs", CELL, check=False)
            assert "attempt to" not in logs.stdout + logs.stderr and "[error]" not in logs.stderr, logs.stderr[-800:]
            print("mixed version verified: an old cell serves the new snapshot and follows the old diagnostics field")
        finally:
            run("docker", "rm", "-f", CELL, ORIGIN, check=False)
    print("e2e public headers mixed-version qualification passed")


if __name__ == "__main__":
    main()
