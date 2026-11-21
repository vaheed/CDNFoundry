#!/usr/bin/env python3
"""Real OpenResty qualification of request rules: redirects, URL rewrites, header rules, and cache rules decided by a running cell
from the snapshot, over IPv4 and IPv6."""

import json
import argparse
import os
import pathlib
import re
import tempfile
import time

import edge_security as base
import openresty_runtime as runtime

NAME = "cdnf-e2e-request-rules-e2e"
IMAGE = "cdnfoundry/edge-runtime:e2e"
CURL = "curlimages/curl:8.16.0"
run = base.run


def host(domain_id: int, redirects: list[dict], security_rules: list[dict] | None = None, rewrites: list[dict] | None = None,
         headers: list[dict] | None = None, caches: list[dict] | None = None, cached: bool = False) -> dict:
    config = base.host(domain_id, security_rules or [])
    config["rules"] = {"redirects": redirects, "rewrites": rewrites or [], "headers": headers or [], "caches": caches or []}
    if cached:
        config["cache"] = {"enabled": True, "epoch": 1, "edge_ttl_seconds": 60, "browser_ttl_seconds": 5, "status_ttl_seconds": {"200": 60},
                           "admission_requests": 1, "maximum_object_bytes": 104857600, "respect_origin_headers": True}
    return config


def rule(identifier: int, conditions: list[dict], **action: object) -> dict:
    return {"id": identifier, "conditions": conditions, "action": action}


def redirect(identifier: int, conditions: list[dict], **action: object) -> dict:
    return {"id": identifier, "conditions": conditions, "action": {"status_code": 301, "preserve_query": False, **action}}


def condition(field: str, operator: str, value: object) -> dict:
    return {"field": field, "operator": operator, "value": value}


def get(hostname: str, path: str = "/", *headers: str, address: str = "127.0.0.1") -> tuple[int, str]:
    if address == "127.0.0.1":
        code, head, _ = runtime.curl(hostname, path, headers, container=NAME)
        return code, head
    return fetch(hostname, path, *headers, address=address)[:2]


def fetch(hostname: str, path: str = "/", *headers: str, address: str = "127.0.0.1") -> tuple[int, str, str]:
    if address == "127.0.0.1":
        return runtime.curl(hostname, path, headers, container=NAME)
    command = ["docker", "run", "--rm", "--network", f"container:{NAME}", CURL, "-sS", "-i", "--max-redirs", "0", "-H", f"Host: {hostname}"]
    for header in headers:
        command += ["-H", header]
    output = run(*command, f"http://[{address}]:8080{path}", check=False).stdout
    head, _, body = re.split(r"(\r?\n\r?\n)", output, maxsplit=1)
    return int(output.split()[1]), head, body


def header(head: str, name: str) -> str | None:
    match = re.search(rf"^{re.escape(name)}:\s*(.*?)\s*$", head, re.I | re.M)
    return match.group(1) if match else None


def location(head: str) -> str:
    match = re.search(r"^location:\s*(\S+)", head, re.I | re.M)
    assert match, head
    return match.group(1)


def expect_redirect(result: tuple[int, str], status: int, target: str) -> None:
    code, head = result
    assert code == status, (code, head)
    # A relative target may be sent as given or made absolute by the server; both reach the same address.
    sent = location(head)
    assert sent == target or sent.endswith("://redirect.example" + target) or sent.endswith("://127.0.0.1" + target), (sent, target)


def access_entries(hostname: str) -> list[dict]:
    logs = run("docker", "logs", NAME, check=False)
    return [json.loads(line) for line in (logs.stdout + logs.stderr).splitlines() if line.startswith("{") and f'"hostname":"{hostname}"' in line]


def qualify(directory: pathlib.Path) -> None:
    hosts = {
        "plain.example": host(1, []),
        "redirect.example": host(2, [
            redirect(11, [], target="prefix", from_prefix="/old", to_prefix="/new", preserve_query=True),
            redirect(12, [condition("path", "equals", "/promo")], target="url", url="https://shop.example/sale", status_code=302),
            redirect(13, [condition("path", "starts_with", "/same")], target="url", url="/same"),
            redirect(14, [condition("path", "equals", "/v6"), condition("client_ip", "equals", "2001:db8:7::/48")],
                     target="url", url="/v6-landing", status_code=307),
            # A malformed rule never acts: no status, an unknown target, too many conditions.
            {"id": 15, "conditions": [condition("path", "equals", "/broken")], "action": {"target": "url", "url": "/x"}},
            {"id": 16, "conditions": [condition("path", "equals", "/broken")], "action": {"target": "elsewhere", "status_code": 301}},
            {"id": 17, "conditions": [condition("path", "equals", "/broken")] * 6, "action": {"target": "url", "url": "/x", "status_code": 301}},
            redirect(18, [condition("path", "equals", "/admin")], target="url", url="/admin-moved"),
        ], security_rules=[{"id": 1, "name": None, "conditions": [condition("path", "equals", "/admin")], "action": "block", "priority": 1}]),
        "www.redirect.example": host(3, [
            redirect(22, [condition("tls_version", "equals", "none"), condition("path", "starts_with", "/secure")], target="https", preserve_query=True),
            redirect(21, [condition("hostname", "equals", "www.redirect.example")], target="host", host="redirect.example", scheme="same",
                     status_code=308, preserve_query=True),
        ]),
        "rewrite.example": host(4, [], cached=True, rewrites=[
            rule(31, [], path="prefix", from_prefix="/api", to_prefix="/echo-request", query="keep"),
            rule(32, [], path="prefix", from_prefix="/alias", to_prefix="/echo-request", query="keep"),
            rule(33, [condition("path", "equals", "/legacy")], path="set", path_value="/echo-request/legacy-new", query="drop"),
            rule(34, [condition("path", "equals", "/lang")], path="set", path_value="/echo-request/lang", query="set", query_value="lang=en"),
            # A rewrite that would leave the root, or a query with a space, is never applied.
            rule(35, [condition("path", "equals", "/escape")], path="set", path_value="/echo-request/../secret", query="keep"),
            rule(36, [condition("path", "equals", "/spaced")], path="set", path_value="/echo-request/spaced", query="set", query_value="a b"),
        ]),
        "headers.example": host(5, [], cached=True, rewrites=[rule(51, [], path="prefix", from_prefix="/", to_prefix="/echo-request/", query="keep")], headers=[
            rule(41, [], request=[{"op": "set", "name": "x-rule-set", "value": "yes"}, {"op": "remove", "name": "x-rule-removed"}],
                 response=[{"op": "set", "name": "x-frame-options", "value": "DENY"}, {"op": "remove", "name": "x-powered-by"}]),
            rule(42, [condition("path", "starts_with", "/fonts/")], request=[], response=[{"op": "set", "name": "access-control-allow-origin", "value": "*"}]),
            # Protected headers are never changed, whatever the snapshot says.
            rule(43, [], request=[{"op": "set", "name": "host", "value": "evil.example"}, {"op": "set", "name": "x-cdn-client-address", "value": "192.0.2.1"}],
                 response=[{"op": "set", "name": "content-length", "value": "1"}, {"op": "set", "name": "x-cdnf-cache", "value": "FAKE"}]),
        ]),
        "cache.example": host(6, [], cached=True, caches=[
            rule(61, [condition("path", "starts_with", "/nocache")], mode="bypass"),
            rule(62, [condition("path", "starts_with", "/long")], mode="override", edge_ttl_seconds=600, browser_ttl_seconds=30),
            rule(63, [condition("path", "starts_with", "/long")], mode="bypass"),
        ]),
        "challenge.example": host(7, [redirect(71, [condition("path", "equals", "/protected")], target="url", url="/moved")],
            security_rules=[{"id": 7, "conditions": [condition("path", "equals", "/protected")], "action": "challenge", "priority": 1}],
            rewrites=[rule(72, [condition("path", "equals", "/protected")], path="set", path_value="/echo-request/bypassed", query="keep")]),
        "budget.example": host(8, [], headers=[
            rule(80 + index, [], request=[{"op": "set", "name": f"x-budget-{index}-{n}", "value": "1"} for n in range(5)],
                 response=[{"op": "set", "name": f"x-budget-{index}-{n}", "value": "1"} for n in range(5)]) for index in range(6)
        ]),
        "vary.example": host(9, [], headers=[
            rule(91, [{"field": "header", "name": "x-selector", "operator": "equals", "value": "nil"}], request=[],
                 response=[{"op": "set", "name": "x-header-match", "value": "yes"}]),
            rule(92, [{"field": "cookie_present", "name": "session", "operator": "equals", "value": True}], request=[],
                 response=[{"op": "set", "name": "x-cookie-match", "value": "yes"}]),
            rule(93, [condition("referer_present", "equals", True)], request=[], response=[{"op": "set", "name": "x-referer-match", "value": "yes"}]),
        ]),
    }
    (directory / "active.json").write_text(json.dumps({"schema_version": 1, "sequence": 1, "generation_id": "e2e-request-rules-1", "certificates": {}, "hosts": hosts}, separators=(",", ":")))
    run("openssl", "req", "-x509", "-newkey", "rsa:2048", "-nodes", "-days", "1", "-subj", "/CN=e2e-request-rules", "-keyout", str(directory / "tls.key"), "-out", str(directory / "tls.crt"))
    for file in directory.iterdir():
        file.chmod(0o644)
    run("docker", "run", "-d", "--name", NAME, "--network", base.EDGE_NETWORK,
        # The standard cache zone keeps 64 MiB free; a smaller disk would evict every entry at once.
        "--tmpfs", "/var/cache/nginx:rw,size=256m", "--tmpfs", "/var/lib/nginx/tmp:rw,size=32m",
        "--memory", "512m", "--cpus", "0.5", "--pids-limit", "96", "--ulimit", "nofile=65536:65536",
        "-e", "EDGE_RUNTIME_FILE=/var/lib/cdnfoundry/runtime/active.json", "-e", "EDGE_STATUS_TOKEN=runtime-test-token",
        # The assertions read access-log rows from the container log.
        "-e", "EDGE_ACCESS_LOG_STDOUT=true",
        "-v", f"{directory}:/var/lib/cdnfoundry/runtime:ro",
        "-v", f"{directory / 'tls.crt'}:/run/edge/tls.crt:ro", "-v", f"{directory / 'tls.key'}:/run/edge/tls.key:ro",
        IMAGE)
    for _ in range(60):  # the cell loads its state a few seconds after it starts
        if run("docker", "run", "--rm", "--network", f"container:{NAME}", CURL, "-s", "-o", "/dev/null", "-w", "%{http_code}",
               "-H", "Host: plain.example", "http://127.0.0.1:8080/", check=False).stdout == "200":
            break
        time.sleep(1)

    assert get("plain.example")[0] == 200
    # A prefix move keeps the rest of the path and, when asked, the query string.
    expect_redirect(get("redirect.example", "/old/page?x=1&y=2"), 301, "/new/page?x=1&y=2")
    expect_redirect(get("redirect.example", "/old"), 301, "/new")
    # A fixed URL drops the query string unless asked to keep it.
    expect_redirect(get("redirect.example", "/promo?ref=mail"), 302, "https://shop.example/sale")
    # The same path on another host, with the request's scheme, path, and query.
    expect_redirect(get("www.redirect.example", "/a/b?q=1"), 308, "http://redirect.example/a/b?q=1")
    # A rule that would send a request to the address it already has never redirects.
    assert get("redirect.example", "/same")[0] == 200
    expect_redirect(get("redirect.example", "/same-other"), 301, "/same")
    # A request that matches no rule, or only malformed rules, reaches the origin.
    assert get("redirect.example", "/elsewhere")[0] == 200
    assert get("redirect.example", "/broken")[0] == 200
    # Security runs first: a blocked request is never redirected.
    code, head = get("redirect.example", "/admin")
    assert code == 403 and not re.search(r"^location:", head, re.I | re.M), head
    code, head, body = fetch("challenge.example", "/protected", "Accept: text/html")
    assert code == 403 and header(head, "Location") is None and "uri=/echo-request/bypassed" not in body, (code, head, body)
    # IPv6: the client address condition matches an IPv6 network, and the cell answers on its IPv6 listener.
    expect_redirect(get("redirect.example", "/v6", "X-Forwarded-For: 2001:db8:7::9"), 307, "/v6-landing")
    assert get("redirect.example", "/v6", "X-Forwarded-For: 2001:db8:8::9")[0] == 200
    expect_redirect(get("redirect.example", "/old/six", address="::1"), 301, "/new/six")
    assert get("plain.example", "/", address="::1")[0] == 200

    # HTTP to HTTPS keeps the host, path, and query; an HTTPS request is never redirected to itself.
    expect_redirect(get("www.redirect.example", "/secure/page?x=1"), 301, "https://www.redirect.example/secure/page?x=1")

    # URL rewrites change only what the origin receives.
    code, _, body = fetch("rewrite.example", "/api/users?x=1")
    assert code == 200 and "uri=/echo-request/users?x=1" in body, body
    assert "uri=/echo-request/legacy-new\n" in fetch("rewrite.example", "/legacy?drop=me")[2]
    assert "uri=/echo-request/lang?lang=en\n" in fetch("rewrite.example", "/lang?other=1")[2]
    assert '"origin":"http"' in fetch("rewrite.example", "/escape")[2]
    assert '"origin":"http"' in fetch("rewrite.example", "/spaced")[2]
    # The cache key stays on the visitor's URL: two URLs that rewrite to one origin path are two entries.
    for _ in range(2):
        code, head, body = fetch("rewrite.example", "/api/item")
    assert header(head, "X-CDNF-Cache") == "HIT" and "uri=/echo-request/item" in body, head
    assert header(fetch("rewrite.example", "/alias/item")[1], "X-CDNF-Cache") != "HIT"
    payload = {"task_id": "rewrite-url-purge", "action": "cache_purge", "type": "urls", "domain": "rewrite.example", "cache_epoch": 1,
               "purge_id": 1, "cache_keys": ["http|rewrite.example|/api/item"]}
    result = run("docker", "exec", NAME, "wget", "-q", "-O-", "--header=X-Edge-Status-Token: runtime-test-token",
                 "--header=Content-Type: application/json", f"--post-data={json.dumps(payload)}", "http://127.0.0.1:9080/control")
    assert json.loads(result.stdout)["data"]["applied_keys"] == 1, result.stdout
    assert header(fetch("rewrite.example", "/api/item")[1], "X-CDNF-Cache") == "MISS"
    assert header(fetch("rewrite.example", "/alias/item")[1], "X-CDNF-Cache") == "HIT"
    assert "uri=/echo-request/six?x=6" in fetch("rewrite.example", "/api/six?x=6", address="::1")[2]

    # Header rules: request headers reach the origin changed, response headers reach the visitor changed, on a hit too.
    for attempt in range(2):
        code, head, body = fetch("headers.example", "/fonts/a.woff2", "X-Rule-Removed: client", "X-Rule-Kept: client")
        assert "set=yes\n" in body and "removed=\n" in body and "kept=client\n" in body, body
        assert header(head, "X-Frame-Options") == "DENY" and header(head, "X-Powered-By") is None, head
        assert header(head, "Access-Control-Allow-Origin") == "*", head
        assert header(head, "X-CDNF-Cache") in ("MISS", "HIT") and header(head, "X-CDNF-Cache") != "FAKE", head
    assert header(head, "X-CDNF-Cache") == "HIT", head
    assert header(fetch("headers.example", "/page")[1], "Access-Control-Allow-Origin") is None
    # The protected Host header was not changed: the origin answered for the configured origin host.
    assert "set=yes" in fetch("headers.example", "/plain", address="::1")[2]
    # Thirty request operations leave room for only twenty response operations in the shared budget of fifty.
    _, budget_head, _ = fetch("budget.example")
    assert len(re.findall(r"^x-budget-", budget_head, re.I | re.M)) == 20, budget_head
    for _ in range(2):
        _, plain_head, _ = fetch("vary.example")
        assert header(plain_head, "X-Header-Match") is None and header(plain_head, "X-Cookie-Match") is None and header(plain_head, "X-Referer-Match") is None
        _, selected_head, _ = fetch("vary.example", "/", "X-Selector: nil", "Cookie: session=present", "Referer: https://site.example/")
        assert header(selected_head, "X-Header-Match") == "yes" and header(selected_head, "X-Cookie-Match") == "yes" and header(selected_head, "X-Referer-Match") == "yes"

    # Cache rules: a bypass is never stored; an override sets the edge and browser lifetimes; the first match wins.
    for _ in range(2):
        _, head, _ = fetch("cache.example", "/nocache/a")
    assert header(head, "X-CDNF-Cache") == "BYPASS", head
    for _ in range(2):
        _, head, _ = fetch("cache.example", "/long/a")
    assert header(head, "X-CDNF-Cache") == "HIT" and "s-maxage=600" in (header(head, "Cache-Control") or "") and "max-age=30" in (header(head, "Cache-Control") or ""), head
    for _ in range(2):
        _, head, _ = fetch("cache.example", "/plain")
    assert header(head, "X-CDNF-Cache") == "HIT" and "s-maxage=60," in (header(head, "Cache-Control") or ""), head
    assert header(fetch("cache.example", "/nocache/v6", address="::1")[1], "X-CDNF-Cache") == "BYPASS"

    # The access log names the rule that acted, and nothing for requests no rule changed.
    time.sleep(1.5)  # the stdout copy of the access log is buffered for up to a second
    rows = {(row["path"], row["status"]): row for row in access_entries("redirect.example")}
    assert rows[("/old/page?x=1&y=2", 301)]["request_rule"] == "redirect:11", rows
    assert rows[("/promo?ref=mail", 302)]["request_rule"] == "redirect:12", rows
    assert rows[("/elsewhere", 200)]["request_rule"] == "", rows
    assert rows[("/admin", 403)]["request_rule"] == "" and rows[("/admin", 403)]["security_rule"] == "r1", rows
    assert any(row["request_rule"] == "redirect:21" for row in access_entries("www.redirect.example"))
    rewrites = {row["path"]: row for row in access_entries("rewrite.example")}
    assert rewrites["/api/users?x=1"]["request_rule"] == "rewrite:31", rewrites
    header_rows = [row for row in access_entries("headers.example") if row["path"] == "/fonts/a.woff2"]
    assert header_rows and header_rows[0]["request_rule"].startswith("rewrite:51,header:41,header:42,header:43"), header_rows
    cache_rows = {row["path"]: row for row in access_entries("cache.example")}
    assert cache_rows["/long/a"]["request_rule"] == "cache:62" and cache_rows["/plain"]["request_rule"] == "", cache_rows
    # Corrupt state cannot replace the loaded generation; the previous redirect keeps serving after a refresh tick.
    (directory / "active.json").write_text('{"schema_version":99,"hosts":{}}')
    time.sleep(2)
    expect_redirect(get("redirect.example", "/old/still-valid"), 301, "/new/still-valid")


def main() -> None:
    global IMAGE
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--image", help="qualify this already-built runtime image")
    args = parser.parse_args()
    run("docker", "compose", "-f", "compose.dev.yml", "up", "-d", "--force-recreate", "origin-http")
    if args.image:
        IMAGE = args.image
    else:
        run("docker", "build", "-f", "docker/openresty/Dockerfile", "-t", IMAGE, ".")
    with tempfile.TemporaryDirectory(prefix="cdnf-request-rules-") as directory:
        os.chmod(directory, 0o755)
        try:
            qualify(pathlib.Path(directory))
            print("request rule runtime qualification passed")
        finally:
            if os.environ.get("CDNF_KEEP_FAILED_RUNTIME") != "1":
                run("docker", "rm", "-f", NAME, check=False)


if __name__ == "__main__":
    main()
