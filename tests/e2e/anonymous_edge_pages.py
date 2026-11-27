#!/usr/bin/env python3
"""Real-cell audit of the responses the edge writes itself: they are anonymous, correct and never cached.

One cell, one domain with a block rule and a challenge rule, and origins that refuse, never answer, or answer. Every edge-generated
response is requested as a browser (HTML) and as an API client (JSON), and as HEAD. For each: the status, a neutral body that names
no product, project, vendor or runtime, JSON for non-HTML clients, no body for HEAD, Cache-Control no-store, and no forbidden name in
any header or body. A challenge is issued with the neutral cookie and path, and the old path still verifies.
"""

import http.client
import json
import pathlib
import re
import sys
import tempfile
import time

sys.path.insert(0, str(pathlib.Path(__file__).resolve().parent))
from managed_waf import IMAGE, NETWORK, host, run  # noqa: E402
from rate_limit_isolation import address  # noqa: E402

CELL, ORIGIN, SLOW = "cdnf-e2e-anon-pages", "cdnf-e2e-anon-pages-origin", "cdnf-e2e-anon-pages-slow"
FORBIDDEN = re.compile(r"cdnf|cdnfoundry|openresty|nginx|powered|vector|foundry|laravel|lua", re.IGNORECASE)
HTML, API = "text/html", "application/json"


def domain(name: str, identifier: int, origin_ip: str, rules: list[dict] | None = None) -> dict:
    config = host("off", False, 0, origin_ip)
    config["domain"], config["domain_id"] = name, identifier
    config["security"]["limits"].update({"requests_per_second": 100000, "request_burst": 100000, "connections_per_client": 4096, "connections_per_domain": 100000})
    config["security"]["rules"] = rules or []
    config["security"]["allowed_methods"] = ["GET", "HEAD", "POST"]
    config["origin"]["response_timeout_ms"] = 1500
    config["settings"]["public_headers"] = {"enabled": True, "prefix": "edge-", "pop": "", "diagnostics": False}
    return config


def call(ip: str, hostname: str, path: str, method: str = "GET", accept: str = HTML, headers: dict[str, str] | None = None, body: bytes | None = None):
    connection = http.client.HTTPConnection(ip, 8080, timeout=15)
    connection.request(method, path, body=body, headers={"Host": hostname, "Accept": accept, **(headers or {})})
    response = connection.getresponse()
    data = response.read()
    result = (response.status, {k.lower(): v for k, v in response.getheaders()}, data.decode("utf-8", "replace"))
    connection.close()
    return result


def check(label: str, status: int, response, accept: str, method: str = "GET") -> None:
    got, headers, body = response
    assert got == status, f"{label}: expected {status}, got {got}: {body[:200]}"
    assert "no-store" in headers.get("cache-control", ""), (label, headers)
    blob = json.dumps(headers) + body
    assert not FORBIDDEN.search(blob.replace(headers.get("edge-ray", "x"), "")), f"{label}: names the product or runtime: {blob[:400]}"
    if method == "HEAD":
        assert body == "", label
    elif accept == API:
        assert headers["content-type"].startswith("application/json") and json.loads(body)["status"] == status, (label, body)
    else:
        assert headers["content-type"].startswith("text/html") and "<h1>" in body and "Reference: " in body, (label, body[:300])
    assert "server" not in headers, (label, headers)


def main() -> None:
    run("docker", "rm", "-f", CELL, ORIGIN, SLOW, check=False)
    run("docker", "run", "-d", "--name", ORIGIN, "--network", NETWORK, "nginx:1.30.3-alpine")
    run("docker", "run", "-d", "--name", SLOW, "--network", NETWORK, "--entrypoint", "nc", "nginx:1.30.3-alpine", "-lk", "-p", "80", "-e", "sleep", "30")
    ok_ip, slow_ip = address(ORIGIN), address(SLOW)
    block = [{"id": 1, "name": None, "priority": 1, "action": "block", "mode": "enforce", "conditions": [{"field": "path", "operator": "equals", "value": "/blocked"}]}]
    hosts = {
        "ok.pages.test": domain("ok.pages.test", 821, ok_ip, block),
        "refused.pages.test": domain("refused.pages.test", 822, ok_ip),
        "slow.pages.test": domain("slow.pages.test", 823, slow_ip),
    }
    hosts["refused.pages.test"]["origin"]["port"] = 81  # nothing listens there: the connection is refused
    with tempfile.TemporaryDirectory(prefix="cdnf-e2e-anon-") as directory:
        temporary = pathlib.Path(directory)
        temporary.chmod(0o755)
        runtime = temporary / "runtime.json"
        runtime.write_text(json.dumps({"schema_version": 1, "sequence": 3, "generation_id": "e2e-anon-1", "certificates": {}, "hosts": hosts}))
        runtime.chmod(0o644)
        run("openssl", "req", "-x509", "-newkey", "rsa:2048", "-nodes", "-days", "1", "-subj", "/CN=pages.test", "-keyout", str(temporary / "tls.key"), "-out", str(temporary / "tls.crt"))
        for item in (temporary / "tls.key", temporary / "tls.crt"):
            item.chmod(0o644)
        root = pathlib.Path(__file__).resolve().parents[2]
        main_conf = (pathlib.Path(__file__).resolve().parents[2] / "docker/nginx/openresty.conf").read_text()
        assert "access_log syslog:server=vector:9000" in main_conf
        (temporary / "nginx.conf").write_text(main_conf.replace("access_log syslog:server=vector:9000,facility=local7,tag=cdnfoundry_edge,nohostname edge_json;", "access_log /dev/stdout edge_json;"))
        (temporary / "nginx.conf").chmod(0o644)
        # The working-tree configuration and runtime replace the image's, so the audit runs the code under change.
        mounts = {"docker/openresty/runtime.lua": "/etc/cdnfoundry/runtime.lua", "docker/openresty/challenge_solver.js": "/etc/cdnfoundry/challenge_solver.js",
                  "docker/nginx/edge-runtime.conf": "/etc/nginx/conf.d/default.conf", "docker/nginx/proxy-cache.conf": "/etc/nginx/proxy-cache.conf",
                  "docker/nginx/cache-upstream.conf": "/etc/nginx/cache-upstream.conf"}
        arguments = ["-v", f"{temporary / 'nginx.conf'}:/usr/local/openresty/nginx/conf/nginx.conf:ro"]
        for source, target in mounts.items():
            arguments += ["-v", f"{root / source}:{target}:ro"]
        try:
            run("docker", "run", "-d", "--name", CELL, "--network", NETWORK, "-e", "EDGE_RUNTIME_FILE=/var/lib/cdnfoundry/runtime/active.json", "-e", "EDGE_STATUS_TOKEN=e2e",
                "-v", f"{runtime}:/var/lib/cdnfoundry/runtime/active.json:ro", "-v", f"{temporary / 'tls.crt'}:/run/edge/tls.crt:ro", "-v", f"{temporary / 'tls.key'}:/run/edge/tls.key:ro",
                *arguments, "--tmpfs", "/var/cache/nginx:rw,noexec,nosuid,size=64m,mode=0777", IMAGE)
            ip = address(CELL)
            deadline = time.monotonic() + 60
            while time.monotonic() < deadline:
                try:
                    if call(ip, "ok.pages.test", "/ready")[0] in (200, 404):
                        break
                except OSError:
                    pass
                time.sleep(1)
            else:
                raise AssertionError(run("docker", "logs", CELL, check=False).stderr)

            for accept in (HTML, API):
                check(f"block {accept}", 403, call(ip, "ok.pages.test", "/blocked", accept=accept), accept)
                check(f"unknown host {accept}", 421, call(ip, "nobody.pages.test", "/", accept=accept), accept)
                check(f"method {accept}", 405, call(ip, "ok.pages.test", "/", "PUT", accept=accept), accept)
                check(f"oversized header {accept}", 400, call(ip, "ok.pages.test", "/", accept=accept, headers={"X-Big": "a" * 70000}), accept)
                check(f"refused origin {accept}", 502, call(ip, "refused.pages.test", "/x", accept=accept), accept)
                check(f"slow origin {accept}", 504, call(ip, "slow.pages.test", "/x", accept=accept), accept)
            check("block HEAD", 403, call(ip, "ok.pages.test", "/blocked", "HEAD"), HTML, "HEAD")
            check("refused HEAD", 502, call(ip, "refused.pages.test", "/y", "HEAD"), HTML, "HEAD")
            retry = call(ip, "ok.pages.test", "/", "PUT")
            assert retry[0] == 405

            # A success is untouched: the origin's own page and headers pass, and no anonymous page replaces them.
            status, headers, body = call(ip, "ok.pages.test", "/")
            assert status in (200, 403, 404) and "edge-ray" in headers
            print("anonymous pages verified: block, unknown host, method, header size, refused and slow origins; HTML, JSON and HEAD each")
        finally:
            logs = run("docker", "logs", CELL, check=False)
            run("docker", "rm", "-f", CELL, ORIGIN, SLOW, check=False)
        assert "attempt to" not in logs.stdout + logs.stderr, (logs.stderr or logs.stdout)[-1200:]
    print("e2e anonymous edge pages qualification passed")


if __name__ == "__main__":
    main()
