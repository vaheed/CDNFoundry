#!/usr/bin/env python3
"""Real OpenResty qualification of the API profile and client-side reporting.

A cell with an API schema marks every violation kind and a rule on it blocks; an opted-in page gets a report-only policy, a
browser-style report is accepted on the reserved path and reaches the access log reduced to kind, page and origin, and reports
over the per-minute bound are dropped. The access log is read from the cell exactly as Vector reads it.
"""

import json
import os
import pathlib
import tempfile
import time

import custom_rules as rules
import detections as shared

NAME = "cdnf-e2e-application-e2e"
ORIGIN = "cdnf-e2e-application-origin"
rules.NAME = NAME
shared.NAME = NAME
shared.ORIGIN = ORIGIN
run = shared.run
get = rules.get
expect = rules.expect
host = shared.host

OPERATIONS = [
    {"path": "/orders", "method": "GET", "parameters": [{"name": "limit", "in": "query", "required": False, "type": "integer"}]},
    {"path": "/orders", "method": "POST", "parameters": [{"name": "x-request-id", "in": "header", "required": True, "type": "string"}]},
    {"path": "/orders/{id}", "method": "GET", "parameters": [{"name": "id", "in": "path", "required": True, "type": "integer"}]},
    {"path": "/orders/{id}", "method": "DELETE", "parameters": [{"name": "id", "in": "path", "required": True, "type": "integer"}]},
]
SCHEMA = {"base_path": "/api", "maximum_body_bytes": 64, "operations": OPERATIONS}
CLIENT = {"enabled": True, "report_path": "/.cdnf/csp-report", "policy": "script-src 'none'; connect-src 'none'; report-uri /.cdnf/csp-report",
          "maximum_reports_per_minute": 10, "report_bytes": 4096}
HTML = "<html><body><script src=\"https://cdn.example/app.js\"></script></body></html>"


def report(document: str, blocked: str, directive: str = "script-src-elem") -> str:
    return json.dumps({"csp-report": {"document-uri": document, "blocked-uri": blocked, "effective-directive": directive, "violated-directive": directive}})


def access_log() -> list[dict]:
    lines = run("docker", "logs", NAME, check=False)
    text = lines.stdout + lines.stderr
    entries = []
    for line in text.splitlines():
        if line.startswith("{") and '"client_report"' in line:
            entries.append(json.loads(line))
    return entries


def qualify(directory: pathlib.Path) -> None:
    root = directory / "html"
    root.mkdir()
    (root / "index.html").write_text(HTML)
    (root / "data.json").write_text('{"ok":true}')
    (directory / "origin.conf").write_text(
        "server { listen 80; root /usr/share/nginx/html; types { text/html html; application/json json; }\n"
        "  location /api/ { default_type application/json; return 200 '{\"ok\":true}'; } }\n")
    run("docker", "rm", "-f", ORIGIN, check=False)
    run("docker", "run", "-d", "--name", ORIGIN, "--network", rules.EDGE_NETWORK, "-v", f"{root}:/usr/share/nginx/html:ro",
        "-v", f"{directory / 'origin.conf'}:/etc/nginx/conf.d/default.conf:ro", "nginx:1.31.3-alpine")
    violation = lambda value, action="block": [rules.rule(1, [rules.condition("schema_violation", "equals", value)], action)]
    hosts = {
        "api.example": host(1, violation("any"), api_schema=SCHEMA),
        "apilog.example": host(2, violation("unknown_path", "log"), api_schema=SCHEMA),
        "apiunset.example": host(3, violation("any")),
        "page.example": host(4, [], client_security=CLIENT),
        "pageoff.example": host(5, []),
    }
    (directory / "active.json").write_text(json.dumps({"schema_version": 1, "sequence": 1, "generation_id": "e2e-application-1", "certificates": {}, "hosts": hosts}, separators=(",", ":")))
    run("openssl", "req", "-x509", "-newkey", "rsa:2048", "-nodes", "-days", "1", "-subj", "/CN=e2e-application", "-keyout", str(directory / "tls.key"), "-out", str(directory / "tls.crt"))
    for file in directory.iterdir():
        if file.is_file():
            file.chmod(0o644)
    run("docker", "run", "-d", "--name", NAME, "--network", rules.EDGE_NETWORK,
        "--tmpfs", "/var/cache/nginx:rw,size=64m", "--tmpfs", "/var/lib/nginx/tmp:rw,size=32m", "--memory", "512m", "--cpus", "0.5", "--pids-limit", "96",
        "-e", "EDGE_RUNTIME_FILE=/var/lib/cdnfoundry/runtime/active.json", "-e", "EDGE_STATUS_TOKEN=runtime-test-token",
        "-v", f"{directory / 'active.json'}:/var/lib/cdnfoundry/runtime/active.json:ro",
        "-v", f"{directory / 'tls.crt'}:/run/edge/tls.crt:ro", "-v", f"{directory / 'tls.key'}:/run/edge/tls.key:ro", "cdnfoundry/edge-runtime:e2e")
    for _ in range(60):
        probe = run("docker", "run", "--rm", "--network", f"container:{NAME}", "curlimages/curl:8.16.0", "-s", "-o", "/dev/null", "-w", "%{http_code}", "-H", "Host: pageoff.example", "http://127.0.0.1:8080/index.html", check=False).stdout
        if probe == "200":
            break
        time.sleep(1)
    blocked = "custom_rule_block"
    json_header = "Content-Type: application/json"

    # 8.1 each violation kind blocks through a rule on "any"; a request that fits the schema passes
    expect(get("api.example", "/api/orders?limit=5"), 200)
    expect(get("api.example", "/api/orders"), 200)
    expect(get("api.example", "/api/orders/42"), 200)
    expect(get("api.example", "/api/orders", "X-Request-Id: abc", method="POST", data="{}"), 200)
    expect(get("api.example", "/index.html"), 200)  # outside the base path: never checked
    expect(get("api.example", "/api/customers"), 403, blocked)  # unknown_path
    expect(get("api.example", "/api/orders/42/items"), 403, blocked)  # unknown_path: too many segments
    expect(get("api.example", "/api/orders", method="PUT"), 403, blocked)  # wrong_method
    expect(get("api.example", "/api/orders", method="POST", data="{}"), 403, blocked)  # missing_parameter
    expect(get("api.example", "/api/orders/abc"), 403, blocked)  # invalid_parameter (path)
    expect(get("api.example", "/api/orders?limit=ten"), 403, blocked)  # invalid_parameter (query)
    expect(get("api.example", "/api/orders", "X-Request-Id: a", json_header, method="POST", data=json.dumps({"note": "x" * 100})), 403, blocked)  # body_too_large
    expect(get("api.example", "/api/orders", "X-Request-Id: a", "Content-Type: text/plain", method="POST", data="x" * 100), 200)  # only JSON bodies are limited
    expect(get("apiunset.example", "/api/customers"), 200)  # no schema, no violation
    # a log rule on one kind matches only that kind, and each violation is a counted event
    expect(get("apilog.example", "/api/customers"), 200)
    expect(get("apilog.example", "/api/orders", method="PUT"), 200)
    logged, _ = shared.events(2, "custom_rule_log")
    assert len(logged) == 1 and logged[0]["count"] == 1, logged
    for reason, count in {"schema_unknown_path": 1, "schema_wrong_method": 1}.items():
        found, _ = shared.events(2, reason)
        assert len(found) == 1 and found[0]["count"] == count, (reason, found)
    for reason in ("schema_unknown_path", "schema_wrong_method", "schema_missing_parameter", "schema_invalid_parameter", "schema_body_too_large"):
        found, _ = shared.events(1, reason)
        assert found and found[0]["count"] >= 1, reason
    assert shared.events(3, "schema_unknown_path")[0] == []

    # 8.2 the policy is on HTML only, reports only, and never replaces a policy the origin sent
    code, head, _ = get("page.example", "/index.html")
    assert code == 200 and "content-security-policy-report-only: script-src 'none'; connect-src 'none'; report-uri /.cdnf/csp-report" in head.lower(), head
    assert "content-security-policy:" not in head.lower(), head
    code, head, _ = get("page.example", "/data.json")
    assert code == 200 and "content-security-policy-report-only" not in head.lower(), head
    code, head, _ = get("pageoff.example", "/index.html")
    assert code == 200 and "content-security-policy" not in head.lower(), head
    report_json = "Content-Type: application/csp-report"
    page = "https://page.example/checkout?token=secret#frag"
    expect(get("page.example", "/.cdnf/csp-report", report_json, method="POST", data=report(page, "https://cdn.example/app.js?v=3")), 204)
    expect(get("page.example", "/.cdnf/csp-report", report_json, method="POST", data=report(page, "https://api.example:8443/v1/x", "connect-src-elem")), 204)
    expect(get("page.example", "/.cdnf/csp-report", report_json, method="POST", data=report(page, "inline")), 204)
    reporting_api = json.dumps([{"type": "csp-violation", "body": {"documentURL": "https://page.example/cart", "blockedURL": "https://tags.example/t.js", "effectiveDirective": "script-src-elem"}}])
    expect(get("page.example", "/.cdnf/csp-report", "Content-Type: application/reports+json", method="POST", data=reporting_api), 204)
    # reports that do not describe a script or connection of this site are accepted and dropped
    expect(get("page.example", "/.cdnf/csp-report", report_json, method="POST", data=report("https://other.example/x", "https://cdn.example/app.js")), 204)
    expect(get("page.example", "/.cdnf/csp-report", report_json, method="POST", data=report(page, "https://cdn.example/s.css", "style-src-elem")), 204)
    expect(get("page.example", "/.cdnf/csp-report", report_json, method="POST", data="{not json"), 204)
    expect(get("page.example", "/.cdnf/csp-report"), 405, "invalid_method")
    expect(get("page.example", "/.cdnf/csp-report", report_json, method="POST", data="x" * 5000), 413, "body_too_large")
    # a domain that did not opt in sends the path to its origin
    code, _, _ = get("pageoff.example", "/.cdnf/csp-report", report_json, method="POST", data=report("https://pageoff.example/", "inline"))
    assert code != 204, code
    time.sleep(1)
    reports = [entry["client_report"] for entry in access_log() if entry["client_report"]]
    assert sorted(reports) == sorted([
        "script|/checkout|https://cdn.example", "connection|/checkout|https://api.example:8443", "script|/checkout|inline", "script|/cart|https://tags.example",
    ]), reports
    assert not any("token" in line or "secret" in line or "frag" in line for line in reports), reports
    # more than the per-minute bound is dropped without an error
    for index in range(15):
        expect(get("page.example", "/.cdnf/csp-report", report_json, method="POST", data=report(page, f"https://cdn{index}.example/a.js")), 204)
    time.sleep(1)
    total = [entry["client_report"] for entry in access_log() if entry["client_report"]]
    # Seven requests counted before the loop (valid or not), so three of the fifteen fit under the bound of ten; a minute change inside the run may allow ten more.
    assert len(total) == 7 or 7 < len(total) <= 14, len(total)
    assert len(total) < 4 + 15, len(total)


def main() -> None:
    run("docker", "build", "-f", "docker/openresty/Dockerfile", "-t", "cdnfoundry/edge-runtime:e2e", ".")
    with tempfile.TemporaryDirectory(prefix="cdnf-application-") as directory:
        os.chmod(directory, 0o755)
        try:
            qualify(pathlib.Path(directory))
            print("application protection runtime qualification passed")
        finally:
            if os.environ.get("CDNF_KEEP_FAILED_RUNTIME") != "1":
                run("docker", "rm", "-f", NAME, ORIGIN, check=False)


if __name__ == "__main__":
    main()
