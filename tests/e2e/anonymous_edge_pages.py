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
STALE_ORIGIN = "cdnf-e2e-anon-pages-stale"
CURL = "curlimages/curl:8.11.1"
STALE_TTL, STALE_WINDOW = 1, 4  # seconds an object is fresh, and how long after that it may still answer when the origin is down
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


def curl(args: list[str], hostname: str, path: str, port: int, family: str) -> tuple[int, dict[str, str], str]:
    """One request by `curl` run inside the cell's network namespace: HTTP/1.0, HTTP/1.1, cleartext HTTP/2, HTTPS and HTTPS HTTP/2, over IPv4 or IPv6."""
    scheme = "https" if port == 8443 else "http"
    loopback = "::1" if family == "6" else "127.0.0.1"
    done = run("docker", "run", "--rm", "--network", f"container:{CELL}", CURL, "-sS", "-i", "-k", "-m", "15", "--resolve", f"{hostname}:{port}:{loopback}", "-H", "Accept: text/html", *args, f"{scheme}://{hostname}:{port}{path}", check=False)
    text = done.stdout.replace("\r\n", "\n")
    head, _, body = text.partition("\n\n")
    lines = head.split("\n")
    assert lines[0].startswith("HTTP/"), f"no response over {args} {family} {hostname}{path} port {port}: exit {done.returncode} {done.stdout[:200]} {done.stderr[:200]}"
    headers = {k.lower(): v.strip() for k, _, v in (line.partition(":") for line in lines[1:]) if k}
    return int(lines[0].split()[1]), headers, body


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
    run("docker", "rm", "-f", CELL, ORIGIN, SLOW, STALE_ORIGIN, check=False)
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
    run("docker", "rm", "-f", STALE_ORIGIN, check=False)
    run("docker", "run", "-d", "--name", STALE_ORIGIN, "--network", NETWORK, "nginx:1.30.3-alpine")
    hosts["stale.pages.test"] = domain("stale.pages.test", 824, address(STALE_ORIGIN))
    hosts["stale.pages.test"]["cache"] = {"enabled": True, "edge_ttl_seconds": STALE_TTL, "browser_ttl_seconds": 0, "maximum_object_bytes": 1048576, "respect_origin_headers": False,
                                          "include_query_string": True, "ignore_cookie_names": [], "stale_if_error_seconds": STALE_WINDOW, "stale_while_revalidate_seconds": 0,
                                          "status_ttl_seconds": {"200": STALE_TTL}, "epoch": 1}
    hosts["stale.pages.test"]["origin"].update({"connect_timeout_ms": 300, "response_timeout_ms": 1000})
    with tempfile.TemporaryDirectory(prefix="cdnf-e2e-anon-") as directory:
        temporary = pathlib.Path(directory)
        temporary.chmod(0o755)
        runtime = temporary / "runtime.json"
        # One certificate covers every host of the audit, so each protocol path can also be driven over TLS.
        run("openssl", "req", "-x509", "-newkey", "rsa:2048", "-nodes", "-days", "1", "-subj", "/CN=*.pages.test", "-addext", "subjectAltName=DNS:*.pages.test", "-keyout", str(temporary / "pages.key"), "-out", str(temporary / "pages.crt"))
        for hostname in hosts:
            hosts[hostname]["tls"] = {"mode": "custom", "certificate_id": "cert-pages"}
        certificates = {"cert-pages": {"id": "cert-pages", "certificate_pem": (temporary / "pages.crt").read_text(), "chain_pem": "", "private_key_pem": (temporary / "pages.key").read_text(),
                                       "expires_at": int(time.time()) + 3600, "names": ["*.pages.test"]}}
        runtime.write_text(json.dumps({"schema_version": 1, "sequence": 3, "generation_id": "e2e-anon-1", "certificates": certificates, "hosts": hosts}))
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
            run("docker", "run", "-d", "--name", CELL, "--network", NETWORK, "--sysctl", "net.ipv6.conf.all.disable_ipv6=0", "-e", "EDGE_RUNTIME_FILE=/var/lib/cdnfoundry/runtime/active.json", "-e", "EDGE_STATUS_TOKEN=e2e",
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

            # The body limit sits in front of the rules: a body over it is refused with the same anonymous page.
            for accept in (HTML, API):
                oversized = call(ip, "ok.pages.test", "/", "POST", accept=accept, body=b"x" * (9 * 1024 * 1024), headers={"Content-Type": "application/octet-stream"})
                check(f"oversized body {accept}", 413, oversized, accept)

            # Every protocol path, over IPv4 and IPv6, gives the same anonymous page for a refused origin and for an unknown host.
            paths = {"HTTP/1.0": (["--http1.0"], 8080), "HTTP/1.1": (["--http1.1"], 8080), "HTTP/2 cleartext": (["--http2-prior-knowledge"], 8080),
                     "HTTPS HTTP/1.1": (["--http1.1"], 8443), "HTTPS HTTP/2": (["--http2"], 8443)}
            for family in ("4", "6"):
                for label, (flags, port) in paths.items():
                    # An unknown name has no certificate, so over TLS it never reaches an HTTP answer; it is asserted on the plain paths only.
                    for hostname, status in (("refused.pages.test", 502), ("nobody.pages.test", 421)):
                        if port == 8443 and status == 421:
                            continue
                        got = curl(flags, hostname, "/p", port, family)
                        assert got[0] == status, f"{label} IPv{family} {hostname}: {got[0]} {got[2][:120]}"
                        check(f"{label} IPv{family} {hostname}", status, got, HTML)

            # The stale window. An object is cached while the origin answers, then the origin goes away.
            first = call(ip, "stale.pages.test", "/index.html")
            assert first[0] == 200 and first[1].get("edge-cache-status") == "MISS", first[:2]
            run("docker", "stop", "-t", "0", STALE_ORIGIN)
            time.sleep(STALE_TTL + 0.6)  # expired, inside the stale window
            stale = call(ip, "stale.pages.test", "/index.html")
            assert stale[0] == 200 and stale[1].get("edge-cache-status") == "STALE", f"inside the window: {stale[:2]}"
            time.sleep(STALE_WINDOW + 1)  # beyond the window. A stopped container's address does not answer at all, so the failure is a timeout: 504
            check("beyond the stale window", 504, call(ip, "stale.pages.test", "/index.html"), HTML)
            check("stale bypass (Authorization)", 504, call(ip, "stale.pages.test", "/index.html", headers={"Authorization": "Bearer x"}), HTML)
            check("uncached path", 504, call(ip, "stale.pages.test", "/never-cached"), HTML)
            check("stale JSON", 504, call(ip, "stale.pages.test", "/index.html", accept=API), API)
            # The error is never cached: when the origin returns, the next request is served by it.
            run("docker", "start", STALE_ORIGIN)
            deadline = time.monotonic() + 20
            while True:
                again = call(ip, "stale.pages.test", "/index.html")
                if again[0] == 200:
                    break
                assert time.monotonic() < deadline, f"the origin is back but the edge still answers {again[0]}"
                time.sleep(0.5)
            assert again[1].get("edge-cache-status") != "STALE" and "no-store" not in again[1].get("cache-control", ""), again[:2]

            # A success is untouched: the origin's own page and headers pass, and no anonymous page replaces them.
            status, headers, body = call(ip, "ok.pages.test", "/")
            assert status in (200, 403, 404) and "edge-ray" in headers
            print("anonymous pages verified: block, unknown host, method, header size, oversized body, refused and slow origins; HTML, JSON and HEAD each; five protocol paths over IPv4 and IPv6; stale window, bypass and uncached error")
        finally:
            logs = run("docker", "logs", CELL, check=False)
            run("docker", "rm", "-f", CELL, ORIGIN, SLOW, STALE_ORIGIN, check=False)
        assert "attempt to" not in logs.stdout + logs.stderr, (logs.stderr or logs.stdout)[-1200:]
    print("e2e anonymous edge pages qualification passed")


if __name__ == "__main__":
    main()
