#!/usr/bin/env python3
"""Real WAF request-body inspection qualification.

The same malicious payload is sent to a real edge cell (OpenResty, ModSecurity, CRS) through a query string, a POST with
Content-Length, an HTTP/1.1 chunked POST without Content-Length and HTTP/2 POSTs without Content-Length. A controllable origin
records every request it receives, so each case asserts the WAF decision from the cell's own telemetry and what the origin did
or did not receive, never a status code alone. Failure policy cases run against cells whose scoring listener is made
unreachable or invalid. Everything runs in throwaway containers on a test network; nothing is sent to a live system.
"""

import json
import os
import re
import pathlib
import subprocess
import sys
import tempfile
import time
import uuid

sys.path.insert(0, str(pathlib.Path(__file__).resolve().parent))
from managed_waf import IMAGE, NETWORK, ROOT, host, run  # noqa: E402

CELL = "cdnf-e2e-body-waf"
BROKEN_CELLS = {"refused": "cdnf-e2e-body-waf-refused", "invalid": "cdnf-e2e-body-waf-invalid"}
ORIGIN = "cdnf-e2e-body-origin"
ORIGIN_SOURCE = '''
import http.server, json, sys

class Handler(http.server.BaseHTTPRequestHandler):
    protocol_version = "HTTP/1.1"
    def body(self):
        if "chunked" in (self.headers.get("Transfer-Encoding") or "").lower():
            data = b""
            while True:
                size = int(self.rfile.readline().strip() or b"0", 16)
                if size == 0:
                    self.rfile.readline()
                    return data
                data += self.rfile.read(size)
                self.rfile.readline()
        return self.rfile.read(int(self.headers.get("Content-Length") or 0))
    def handle_any(self):
        data = self.body()
        print(json.dumps({"origin_request": True, "method": self.command, "path": self.path, "length": len(data),
                          "body": data.decode("latin1"), "mode_header": self.headers.get("X-CDNF-Internal-WAF-Mode")}), flush=True)
        reply = b"origin-ok"
        self.send_response(200)
        self.send_header("Content-Length", str(len(reply)))
        self.end_headers()
        self.wfile.write(reply)
    do_GET = do_POST = do_PUT = handle_any
    def log_message(self, *args):
        pass

http.server.ThreadingHTTPServer(("0.0.0.0", 80), Handler).serve_forever()
'''
ATTACK_FORM = "q=%3Cscript%3Ealert(1)%3C%2Fscript%3E&note=hello"
ATTACK_QUERY = "q=%3Cscript%3Ealert(1)%3C%2Fscript%3E&via=query"  # the same payload in the address, which Nginx logs by design
BENIGN_FORM = "name=alice&city=Tehran&note=hello+world"
FORM = "application/x-www-form-urlencoded"


def address(container: str) -> str:
    return json.loads(run("docker", "inspect", container).stdout)[0]["NetworkSettings"]["Networks"][NETWORK]["IPAddress"]


class Result:
    def __init__(self, status: int, sent: str):
        self.status, self.sent = status, sent


def send(cell_ip: str, hostname: str, marker: str, *, method: str = "GET", body: bytes | None = None, protocol: str = "http1",
         content_type: str = FORM, headers: tuple[str, ...] = (), query: str = "") -> Result:
    """protocol: http1 (Content-Length), chunked (HTTP/1.1, no Content-Length), h2 (TLS, no Content-Length), h2c (cleartext)."""
    tls = protocol == "h2"
    port = 8443 if tls else 8080
    command = ["curl", "-s", "-o", "/dev/null", "-w", "%{http_code}", "-v", "--max-time", "20",
               "--resolve", f"{hostname}:{port}:{cell_ip}", "-H", "User-Agent: CDNFoundry-WAF-Test/1.0"]
    for header in headers:
        command += ["-H", header]
    scheme = "https" if tls else "http"
    url = f"{scheme}://{hostname}:{port}/m/{marker}{query}"
    stdin = None
    if protocol in ("h2", "h2c"):
        command += ["--http2"] if tls else ["--http2-prior-knowledge"]
        if tls:
            command += ["-k"]
    else:
        command += ["--http1.1"]
    if body is not None:
        command += ["-X", method, "-H", f"Content-Type: {content_type}"]
        if protocol == "http1":
            command += ["--data-binary", "@-"]
        elif protocol == "chunked":
            command += ["-H", "Transfer-Encoding: chunked", "--data-binary", "@-"]
        else:
            command += ["-T", "-"]  # streamed upload: the length is unknown, so no Content-Length is sent
        stdin = body
    elif method != "GET":
        command += ["-X", method]
    command.append(url)
    result = subprocess.run(command, input=stdin, capture_output=True, check=False, timeout=60)
    sent = result.stderr.decode("latin1").lower()
    if body is not None and protocol in ("chunked", "h2", "h2c"):
        assert "> content-length" not in sent, f"{protocol} request carried a Content-Length:\n{sent}"
    if protocol == "chunked":
        assert "> transfer-encoding: chunked" in sent, sent
    return Result(int(result.stdout.decode() or 0), sent)


def logs(container: str) -> list[dict]:
    result = run("docker", "logs", container, check=False)
    rows = []
    for line in (result.stdout + result.stderr).splitlines():
        if line.startswith("{"):
            try:
                rows.append(json.loads(line))
            except ValueError:
                pass
    return rows


def cell_row(cell: str, marker: str) -> dict:
    deadline = time.monotonic() + 15
    while time.monotonic() < deadline:
        rows = [row for row in logs(cell) if f"/m/{marker}" in str(row.get("path", "")) and "waf_profile" in row]
        if rows:
            return rows[-1]
        time.sleep(0.5)
    raise AssertionError(f"the cell logged nothing for {marker}")


def origin_rows(marker: str) -> list[dict]:
    return [row for row in logs(ORIGIN) if row.get("origin_request") and f"/m/{marker}" in row["path"]]


def expect_blocked_by_waf(cell: str, marker: str, status: Result, label: str) -> None:
    assert status.status == 403, (label, status.status)
    row = cell_row(cell, marker)
    # A bare 403 proves nothing: the decision must carry ModSecurity's anomaly score and the CRS XSS category.
    assert row["waf_action"] == "block" and row["security_reason"] == "waf_block", (label, row)
    assert row["waf_score"] > 0 and 0 < row["attack_score_xss"] < 50, (label, row)
    # The block names the CRS rules that matched, never the blocking evaluation, and carries ids only (no matched data).
    matched = [int(value) for value in row["waf_rule_ids"].split(",")]
    assert 1 <= len(matched) <= 8 and row["waf_rule_id"] in matched and row["waf_rule_id"] != 949110, (label, row)
    assert any(941000 <= value <= 941999 for value in matched), (label, row)
    assert re.fullmatch(r"[0-9]{6}(,[0-9]{6}){0,7}", row["waf_rule_ids"]), (label, row)
    assert row["waf_body_limit"] in ("inspected", "no_body"), (label, row)
    assert not origin_rows(marker), f"{label}: a blocked request reached the origin"


def expect_refused(cell: str, marker: str, result: Result, status: int, reason: str, telemetry: str, label: str) -> None:
    assert result.status == status, (label, result.status)
    row = cell_row(cell, marker)
    assert row["security_action"] == "block" and row["security_reason"] == reason, (label, row)
    assert row["waf_body_limit"] == telemetry and row["status"] == status, (label, row)
    assert not origin_rows(marker), f"{label}: a request that was not inspected reached the origin"


def expect_forwarded(cell: str, marker: str, result: Result, body: bytes, telemetry: str, label: str) -> dict:
    assert result.status == 200, (label, result.status, result.sent[-400:])
    received = origin_rows(marker)
    assert len(received) == 1, (label, received)
    assert received[0]["body"].encode("latin1") == body, f"{label}: the origin did not receive the complete body"
    row = cell_row(cell, marker)
    assert row["waf_body_limit"] == telemetry, (label, row)
    return row


def start_cell(name: str, runtime: pathlib.Path, temporary: pathlib.Path, config: pathlib.Path | None = None) -> str:
    command = ["docker", "run", "-d", "--name", name, "--network", NETWORK,
               "-e", "EDGE_RUNTIME_FILE=/var/lib/cdnfoundry/runtime/active.json", "-e", "EDGE_STATUS_TOKEN=e2e",
               # The assertions read access-log rows from the container log.
               "-e", "EDGE_ACCESS_LOG_STDOUT=true",
               "-v", f"{runtime}:/var/lib/cdnfoundry/runtime/active.json:ro",
               "-v", f"{temporary / 'tls.crt'}:/run/edge/tls.crt:ro", "-v", f"{temporary / 'tls.key'}:/run/edge/tls.key:ro"]
    if config is not None:
        command += ["-v", f"{config}:/etc/nginx/conf.d/default.conf:ro"]
    command += ["--tmpfs", "/var/cache/nginx:rw,noexec,nosuid,size=64m,mode=0777", IMAGE]
    run(*command)
    ip = address(name)
    deadline = time.monotonic() + 60
    while time.monotonic() < deadline:
        probe = subprocess.run(["curl", "-s", "-o", "/dev/null", "-w", "%{http_code}", "--max-time", "3", "--resolve", f"off.waf.test:8080:{ip}",
                                "http://off.waf.test:8080/ready"], capture_output=True, text=True, check=False)
        if probe.stdout == "200":
            return ip
        time.sleep(1)
    raise AssertionError(f"{name} did not start:\n{run('docker', 'logs', name, check=False).stderr}")


def main() -> None:
    cells = [CELL, *BROKEN_CELLS.values()]
    run("docker", "rm", "-f", ORIGIN, *cells, check=False)
    with tempfile.TemporaryDirectory(prefix="cdnf-e2e-waf-body-") as directory:
        temporary = pathlib.Path(directory)
        temporary.chmod(0o755)
        (temporary / "origin.py").write_text(ORIGIN_SOURCE)
        run("docker", "run", "-d", "--name", ORIGIN, "--network", NETWORK, "-v", f"{temporary / 'origin.py'}:/origin.py:ro",
            "python:3.13-alpine3.22", "python", "/origin.py")
        origin_ip = address(ORIGIN)
        hosts = {
            "off.waf.test": host("off", False, 0, origin_ip),
            "monitor.waf.test": host("monitor", False, 1048576, origin_ip),
            "balanced.waf.test": host("balanced", True, 1048576, origin_ip),
            "strict.waf.test": host("strict", True, 262144, origin_ip),
        }
        run("openssl", "req", "-x509", "-newkey", "rsa:2048", "-nodes", "-days", "1", "-subj", "/CN=waf.test",
            "-addext", "subjectAltName=DNS:balanced.waf.test,DNS:strict.waf.test,DNS:monitor.waf.test,DNS:off.waf.test",
            "-keyout", str(temporary / "tls.key"), "-out", str(temporary / "tls.crt"))
        # The same certificate serves every test host, so the HTTP/2 cases can use TLS with ALPN as a real client would.
        for entry in hosts.values():
            entry["tls"] = {"mode": "custom", "certificate_id": "cert-1"}
        certificates = {"cert-1": {"id": "cert-1", "certificate_pem": (temporary / "tls.crt").read_text(), "chain_pem": "",
                                   "private_key_pem": (temporary / "tls.key").read_text(), "expires_at": int(time.time()) + 3600,
                                   "names": sorted(hosts)}}
        runtime = temporary / "runtime.json"
        runtime.write_text(json.dumps({"schema_version": 1, "sequence": 1, "generation_id": "e2e-body-1", "certificates": certificates, "hosts": hosts}))
        runtime.chmod(0o644)
        for item in (temporary / "tls.key", temporary / "tls.crt"):
            item.chmod(0o644)

        # Two broken scoring listeners: one nothing listens on, and one that answers with something that is not a score.
        configuration = run("docker", "run", "--rm", "--entrypoint", "cat", IMAGE, "/etc/nginx/conf.d/default.conf").stdout
        scoring = configuration[configuration.index("server {\n    listen 127.0.0.1:9082;"):]
        scoring = scoring[:scoring.index("\n}\n") + 3]
        assert "modsecurity_rules_file /etc/cdnfoundry/waf-score.conf;" in scoring
        refused = temporary / "refused.conf"
        refused.write_text(configuration.replace(scoring, scoring.replace("127.0.0.1:9082", "127.0.0.1:9092")))
        invalid = temporary / "invalid.conf"
        invalid.write_text(configuration.replace(scoring, scoring.replace("modsecurity on;", "modsecurity off;").replace(
            'content_by_lua_block { ngx.say("scored") }', 'content_by_lua_block { ngx.status = 200; ngx.say("not a score") }')))
        assert refused.read_text() != configuration and invalid.read_text() != configuration

        try:
            ip = start_cell(CELL, runtime, temporary)
            attack = ATTACK_FORM.encode()
            benign = BENIGN_FORM.encode()

            # The same payload through every path must be blocked, and by ModSecurity's score.
            marker = "get-" + uuid.uuid4().hex[:8]
            expect_blocked_by_waf(CELL, marker, send(ip, "balanced.waf.test", marker, query="?" + ATTACK_QUERY), "GET query string")
            for protocol, label in (("http1", "POST with Content-Length"), ("chunked", "HTTP/1.1 chunked POST without Content-Length"),
                                    ("h2", "HTTP/2 POST (TLS) without Content-Length"), ("h2c", "HTTP/2 POST (cleartext) without Content-Length")):
                marker = f"{protocol}-{uuid.uuid4().hex[:8]}"
                expect_blocked_by_waf(CELL, marker, send(ip, "balanced.waf.test", marker, method="POST", body=attack, protocol=protocol), label)
                if protocol in ("h2", "h2c"):
                    assert cell_row(CELL, marker)["server_protocol"] == "HTTP/2.0", f"{label} did not use HTTP/2"

            # Benign requests of every framing are inspected, forwarded whole, and recorded as inspected.
            for protocol in ("http1", "chunked", "h2", "h2c"):
                marker = f"benign-{protocol}-{uuid.uuid4().hex[:8]}"
                row = expect_forwarded(CELL, marker, send(ip, "balanced.waf.test", marker, method="POST", body=benign, protocol=protocol),
                                       benign, "inspected", f"benign {protocol}")
                assert row["waf_action"] == "pass" and row["attack_score"] == 99, row  # 99 is the clean score; 1 is an attack
            marker = "json-" + uuid.uuid4().hex[:8]
            payload = json.dumps({"user": "alice", "tags": ["a", "b"]}).encode()
            expect_forwarded(CELL, marker, send(ip, "balanced.waf.test", marker, method="POST", body=payload, protocol="chunked",
                                                content_type="application/json"), payload, "inspected", "benign chunked JSON")
            marker = "json-attack-" + uuid.uuid4().hex[:8]
            expect_blocked_by_waf(CELL, marker, send(ip, "balanced.waf.test", marker, method="POST", protocol="chunked",
                                                     content_type="application/json", body=json.dumps({"q": "<script>alert(1)</script>"}).encode()),
                                  "chunked JSON XSS")
            marker = "multipart-" + uuid.uuid4().hex[:8]
            multipart = (b'--B\r\nContent-Disposition: form-data; name="q"\r\n\r\n<script>alert(1)</script>\r\n--B--\r\n')
            expect_blocked_by_waf(CELL, marker, send(ip, "balanced.waf.test", marker, method="POST", protocol="chunked",
                                                     content_type="multipart/form-data; boundary=B", body=multipart), "chunked multipart XSS")

            # No body and empty bodies are told apart from a body and never fail.
            marker = "nobody-" + uuid.uuid4().hex[:8]
            expect_forwarded(CELL, marker, send(ip, "balanced.waf.test", marker), b"", "no_body", "GET without a body")
            marker = "empty-" + uuid.uuid4().hex[:8]
            expect_forwarded(CELL, marker, send(ip, "balanced.waf.test", marker, method="POST", body=b"", protocol="http1"), b"", "no_body", "empty POST")

            # Bodies larger than Nginx's memory buffer are spooled to a temporary file and must still be inspected whole.
            filler = "pad=" + "a" * 120000 + "&"
            marker = "spool-benign-" + uuid.uuid4().hex[:8]
            big = (filler + BENIGN_FORM).encode()
            expect_forwarded(CELL, marker, send(ip, "balanced.waf.test", marker, method="POST", body=big, protocol="chunked"), big, "inspected", "spooled chunked body")
            for protocol in ("http1", "chunked", "h2"):
                marker = f"spool-{protocol}-{uuid.uuid4().hex[:8]}"
                expect_blocked_by_waf(CELL, marker, send(ip, "balanced.waf.test", marker, method="POST", protocol=protocol,
                                                         body=(filler + ATTACK_FORM).encode()), f"spooled {protocol} body, payload at the end")

            # The body limit is enforced with and without Content-Length, and an over-limit body is never reported as inspected.
            for protocol in ("http1", "chunked", "h2"):
                marker = f"large-{protocol}-{uuid.uuid4().hex[:8]}"
                expect_refused(CELL, marker, send(ip, "strict.waf.test", marker, method="POST", protocol=protocol, body=b"x=" + b"a" * 300000),
                               413, "waf_body_limit", "exceeded", f"over-limit {protocol} body")
            marker = "under-limit-" + uuid.uuid4().hex[:8]
            under = b"x=" + b"a" * 200000
            expect_forwarded(CELL, marker, send(ip, "strict.waf.test", marker, method="POST", protocol="chunked", body=under), under, "inspected", "chunked body under the limit")

            # A content-encoded body cannot be scored, so it is refused rather than passed as clean.
            marker = "gzip-" + uuid.uuid4().hex[:8]
            expect_refused(CELL, marker, send(ip, "balanced.waf.test", marker, method="POST", protocol="chunked", body=b"\x1f\x8b\x08\x00garbage",
                                              headers=("Content-Encoding: gzip",)), 415, "waf_body_encoding", "unsupported_enc", "gzip body")

            # Client-supplied internal controls change nothing.
            for protocol in ("chunked", "h2"):
                marker = f"spoof-{protocol}-{uuid.uuid4().hex[:8]}"
                expect_blocked_by_waf(CELL, marker, send(ip, "balanced.waf.test", marker, method="POST", body=attack, protocol=protocol,
                                                         headers=("X-CDNF-Internal-WAF-Mode: off", "X-CDNF-Internal-WAF-Off: ,941100,941110,949110,",
                                                                  "X-CDNF-Internal-WAF-Threshold: 100", "X-CDNF-Internal-WAF-Paranoia: 1")),
                                      f"spoofed internal headers over {protocol}")
            marker = "spoof-benign-" + uuid.uuid4().hex[:8]
            row = expect_forwarded(CELL, marker, send(ip, "balanced.waf.test", marker, method="POST", body=benign, protocol="chunked",
                                                      headers=("X-CDNF-Internal-WAF-Mode: off",)), benign, "inspected", "benign with spoofed header")
            assert origin_rows(marker)[0]["mode_header"] is None, "an internal control header reached the origin"

            # Monitor mode forwards a malicious request, records that the full body was inspected and scored, and records what it could not.
            for protocol in ("http1", "chunked", "h2"):
                marker = f"monitor-{protocol}-{uuid.uuid4().hex[:8]}"
                row = expect_forwarded(CELL, marker, send(ip, "monitor.waf.test", marker, method="POST", body=attack, protocol=protocol),
                                       attack, "inspected", f"monitor {protocol}")
                assert row["waf_action"] == "monitor" and 0 < row["attack_score_xss"] < 50 and row["security_action"] != "block", row
            marker = "monitor-large-" + uuid.uuid4().hex[:8]
            oversized = b"x=" + b"a" * 1100000
            row = expect_forwarded(CELL, marker, send(ip, "monitor.waf.test", marker, method="POST", body=oversized, protocol="chunked"),
                                   oversized, "exceeded", "monitor over-limit body")
            assert row["waf_action"] == "monitor" and row["attack_score"] == 0, "an over-limit body must not look scored"

            # A scoring listener that is down or answers garbage is recorded, and the request is then inspected by the inline engine
            # on its way to the origin. It never turns into a clean inspection or an open WAF.
            for kind, name in BROKEN_CELLS.items():
                broken_ip = start_cell(name, runtime, temporary, refused if kind == "refused" else invalid)
                for protocol in ("http1", "chunked", "h2"):
                    marker = f"{kind}-{protocol}-{uuid.uuid4().hex[:8]}"
                    result = send(broken_ip, "balanced.waf.test", marker, method="POST", body=attack, protocol=protocol)
                    assert result.status == 403, (kind, protocol, result.status)
                    assert cell_row(name, marker)["waf_body_limit"] == "scoring_failed", cell_row(name, marker)
                    assert not origin_rows(marker), f"{kind} listener, {protocol}: the inline engine let an attack through"
                    marker = f"{kind}-benign-{protocol}-{uuid.uuid4().hex[:8]}"
                    expect_forwarded(name, marker, send(broken_ip, "balanced.waf.test", marker, method="POST", body=benign, protocol=protocol),
                                     benign, "scoring_failed", f"{kind} listener, benign {protocol}")
                marker = f"{kind}-get-" + uuid.uuid4().hex[:8]
                assert send(broken_ip, "balanced.waf.test", marker, query="?" + ATTACK_QUERY).status == 403
                assert not origin_rows(marker)
                # A body that no engine can inspect is still refused while the listener is down.
                marker = f"{kind}-gzip-" + uuid.uuid4().hex[:8]
                expect_refused(name, marker, send(broken_ip, "balanced.waf.test", marker, method="POST", protocol="chunked", body=b"\x1f\x8bxx",
                                                  headers=("Content-Encoding: gzip",)), 415, "waf_body_encoding", "unsupported_enc", f"{kind} listener, gzip")
                marker = f"{kind}-monitor-" + uuid.uuid4().hex[:8]
                row = expect_forwarded(name, marker, send(broken_ip, "monitor.waf.test", marker, method="POST", body=attack, protocol="chunked"),
                                       attack, "scoring_failed", f"{kind} listener, monitor")
                assert row["waf_action"] == "monitor" and row["attack_score"] == 0, row
                marker = f"{kind}-off-" + uuid.uuid4().hex[:8]
                expect_forwarded(name, marker, send(broken_ip, "off.waf.test", marker, method="POST", body=attack, protocol="chunked"), attack, "none",
                                 f"{kind} listener, profile off")

            leaked = []
            for name in cells:
                log = run("docker", "logs", name, check=False)
                leaked += [f"{name}: {line[:300]}" for line in (log.stdout + log.stderr).splitlines() if not line.startswith("{") and ("note=hello" in line or "<script>" in line)]
            assert not leaked, "a request body was written to a log:\n" + "\n".join(leaked[:5])
        finally:
            run("docker", "rm", "-f", ORIGIN, *cells, check=False)
    print("e2e WAF request-body inspection qualification passed")


if __name__ == "__main__":
    main()
