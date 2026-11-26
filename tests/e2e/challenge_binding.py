#!/usr/bin/env python3
"""Real-runtime qualification of the browser challenge's difficulty and token binding.

Cells run the real image with a custom challenge rule. Clients arrive over PROXY protocol, so the test needs no IPv6 routing. It
solves the proof of work the way a browser does, then checks which clients a token follows: another address of the same IPv6
prefix and another User-Agent are the same client, another prefix is not, and a cell set to bind the User-Agent refuses another
one. It also checks that the difficulty is configurable inside its bounds.
"""

import hashlib
import json
import pathlib
import re
import socket
import sys
import tempfile
import time
import urllib.parse

sys.path.insert(0, str(pathlib.Path(__file__).resolve().parent))
from ipv6_rate_limit_keys import address, proxy_header  # noqa: E402
from managed_waf import IMAGE, NETWORK, host, run  # noqa: E402

ORIGIN = "cdnf-e2e-challenge-origin"
CELLS = {"default": "cdnf-e2e-challenge-default", "tuned": "cdnf-e2e-challenge-tuned", "invalid": "cdnf-e2e-challenge-invalid",
         "incident": "cdnf-e2e-challenge-incident"}
HTML = "text/html"


def request(ip: str, source: str, method: str = "GET", path: str = "/", headers: dict[str, str] | None = None, body: str = "", accept: str = HTML) -> tuple[int, dict[str, str], str]:
    lines = [f"{method} {path} HTTP/1.1", "Host: chal.test", "Connection: close", "Accept: " + accept]
    merged = {"User-Agent": "Browser/1"} | (headers or {})
    lines += [f"{name}: {value}" for name, value in merged.items()]
    if body:
        lines += ["Content-Type: application/x-www-form-urlencoded", f"Content-Length: {len(body)}"]
    payload = proxy_header(source) + ("\r\n".join(lines) + "\r\n\r\n" + body).encode()
    with socket.create_connection((ip, 8081), timeout=10) as connection:
        connection.sendall(payload)
        data = b""
        while chunk := connection.recv(65536):
            data += chunk
    head, _, content = data.partition(b"\r\n\r\n")
    status_line, *header_lines = head.decode("latin1").split("\r\n")
    parsed = {line.split(":", 1)[0].lower(): line.split(":", 1)[1].strip() for line in header_lines if ":" in line}
    return int(status_line.split(" ", 2)[1]), parsed, content.decode("utf-8", "replace")


def leading_zero_bits(digest: bytes) -> int:
    bits = 0
    for byte in digest:
        if byte == 0:
            bits += 8
            continue
        return bits + 8 - byte.bit_length()
    return bits


def challenge(ip: str, source: str, user_agent: str = "Browser/1") -> tuple[str, int]:
    status, _, page = request(ip, source, headers={"User-Agent": user_agent})
    assert status == 403 and "Checking your browser" in page, (status, page[:200])
    seed = re.search(r'var seed="([^"]+)"', page).group(1)
    bits = int(re.search(r",bits=(\d+),", page).group(1))
    return seed, bits


def solve(seed: str, bits: int) -> int:
    counter = 0
    while leading_zero_bits(hashlib.sha256(f"{seed}:{counter}".encode()).digest()) < bits:
        counter += 1
    return counter


def token(ip: str, source: str, user_agent: str = "Browser/1") -> tuple[str, int]:
    seed, bits = challenge(ip, source, user_agent)
    counter = solve(seed, bits)
    status, headers, _ = request(ip, source, "POST", "/.cdnf/challenge", {"User-Agent": user_agent}, f"seed={urllib.parse.quote(seed)}&counter={counter}")
    assert status == 204, status
    return re.search(r"cdnf_chal=([0-9a-z.]+)", headers["set-cookie"]).group(1), bits


def start(name: str, runtime: pathlib.Path, temporary: pathlib.Path, subnet: str, environment: dict[str, str]) -> str:
    command = ["docker", "run", "-d", "--name", name, "--network", NETWORK, "-e", "EDGE_RUNTIME_FILE=/var/lib/cdnfoundry/runtime/active.json",
               "-e", "EDGE_STATUS_TOKEN=e2e", "-e", f"EDGE_PROXY_PROTOCOL_TRUSTED_CIDRS={subnet}"]
    for key, value in environment.items():
        command += ["-e", f"{key}={value}"]
    command += ["-v", f"{runtime}:/var/lib/cdnfoundry/runtime/active.json:ro", "-v", f"{temporary / 'tls.crt'}:/run/edge/tls.crt:ro",
                "-v", f"{pathlib.Path(__file__).resolve().parents[2] / 'docker/openresty/runtime.lua'}:/etc/cdnfoundry/runtime.lua:ro",
                "-v", f"{temporary / 'tls.key'}:/run/edge/tls.key:ro", "--tmpfs", "/var/cache/nginx:rw,noexec,nosuid,size=64m,mode=0777", IMAGE]
    run(*command)
    ip = address(name)
    deadline = time.monotonic() + 60
    while time.monotonic() < deadline:
        try:
            request(ip, "198.51.100.250")
            return ip
        except OSError:
            time.sleep(1)
    raise AssertionError(f"{name} did not start:\n{run('docker', 'logs', name, check=False).stderr}")


def main() -> None:
    cells = list(CELLS.values())
    run("docker", "rm", "-f", ORIGIN, *cells, check=False)
    run("docker", "run", "-d", "--name", ORIGIN, "--network", NETWORK, "nginx:1.30.3-alpine")
    origin_ip = address(ORIGIN)
    subnet = json.loads(run("docker", "network", "inspect", NETWORK).stdout)[0]["IPAM"]["Config"][0]["Subnet"]
    with tempfile.TemporaryDirectory(prefix="cdnf-e2e-challenge-") as directory:
        temporary = pathlib.Path(directory)
        temporary.chmod(0o755)
        config = host("off", False, 0, origin_ip)
        config["domain"], config["domain_id"] = "chal.test", 601
        # A site's method policy governs origin traffic, not the internal proof POST.
        config["security"]["allowed_methods"] = ["GET", "HEAD"]
        config["security"]["limits"].update({"requests_per_second": 100000, "request_burst": 100000, "connections_per_client": 4096, "connections_per_domain": 100000})
        config["security"]["rules"] = [{"id": 1, "name": None, "priority": 1, "action": "challenge",
                                        "conditions": [{"field": "path", "operator": "equals", "value": "/"}]}]
        runtime = temporary / "runtime.json"
        runtime.write_text(json.dumps({"schema_version": 1, "sequence": 1, "generation_id": "e2e-chal-1", "certificates": {}, "hosts": {"chal.test": config}}))
        runtime.chmod(0o644)
        run("openssl", "req", "-x509", "-newkey", "rsa:2048", "-nodes", "-days", "1", "-subj", "/CN=chal.test",
            "-keyout", str(temporary / "tls.key"), "-out", str(temporary / "tls.crt"))
        for item in (temporary / "tls.key", temporary / "tls.crt"):
            item.chmod(0o644)
        try:
            default = start(CELLS["default"], runtime, temporary, subnet, {})
            tuned = start(CELLS["tuned"], runtime, temporary, subnet, {"EDGE_CHALLENGE_BITS": "12", "EDGE_CHALLENGE_BIND_USER_AGENT": "true"})
            invalid = start(CELLS["invalid"], runtime, temporary, subnet, {"EDGE_CHALLENGE_BITS": "3"})
            incident_runtime = temporary / "incident.json"
            incident_state = json.loads(runtime.read_text())
            incident_state["hosts"]["chal.test"]["security"]["rules"] = []
            incident_runtime.write_text(json.dumps(incident_state))
            incident_runtime.chmod(0o644)
            incident_ip = start(CELLS["incident"], incident_runtime, temporary, subnet, {"EDGE_CHALLENGE_BITS": "12"})
            assert request(incident_ip, "198.51.100.80", "POST", "/.cdnf/challenge", body="x=1")[0] == 405, "an inactive endpoint must obey the method policy"
            overlay = {"task_id": "restricted-method-challenge", "action": "security_overlay", "domain_id": 601,
                       "sequence": 1, "state": "restricted", "incident": {"expires_at": int(time.time()) + 300,
                       "waf": {"mode": "block"}, "methods": ["GET", "HEAD"], "max_body_bytes": 1024, "challenge": True}}
            run("docker", "exec", CELLS["incident"], "wget", "-q", "-O-", "--header=X-Edge-Status-Token: e2e",
                "--header=Content-Type: application/json", "--post-data=" + json.dumps(overlay), "http://127.0.0.1:9080/control")
            incident_cookie, _ = token(incident_ip, "198.51.100.81")
            assert request(incident_ip, "198.51.100.81", headers={"Cookie": f"cdnf_chal={incident_cookie}"})[0] == 200
            assert request(incident_ip, "198.51.100.81", "POST", "/", body="x=1")[0] == 405
            assert request(incident_ip, "198.51.100.82", "POST", "/.cdnf/challenge", body="x" * 513)[0] == 413

            # The default difficulty is 16 bits, and a bad setting falls back to it instead of weakening the challenge.
            cookie, bits = token(default, "2001:db8:a:b::1")
            assert bits == 16, bits
            assert challenge(invalid, "2001:db8:a:b::1")[1] == 16, "a difficulty outside 12 to 24 must not be accepted"

            # The token follows its client: another address of the same /64 and another User-Agent are accepted.
            assert request(default, "2001:db8:a:b::1", headers={"Cookie": f"cdnf_chal={cookie}"})[0] == 200
            assert request(default, "2001:db8:a:b:7:7:7:7", headers={"Cookie": f"cdnf_chal={cookie}"})[0] == 200, "an address change inside the prefix lost the token"
            assert request(default, "2001:db8:a:b::1", headers={"Cookie": f"cdnf_chal={cookie}", "User-Agent": "Browser/2"})[0] == 200
            # Another prefix is another client, and a forged token fails.
            assert request(default, "2001:db8:a:c::1", headers={"Cookie": f"cdnf_chal={cookie}"})[0] == 403, "a token worked for another prefix"
            assert request(default, "2001:db8:a:b::1", headers={"Cookie": f"cdnf_chal={cookie[:-1]}{'0' if cookie[-1] != '0' else '1'}"})[0] == 403
            # IPv4 clients are one client per address.
            v4, _ = token(default, "198.51.100.30")
            assert request(default, "198.51.100.30", headers={"Cookie": f"cdnf_chal={v4}"})[0] == 200
            assert request(default, "198.51.100.31", headers={"Cookie": f"cdnf_chal={v4}"})[0] == 403
            # A seed issued to one prefix is useless to another, and a solved seed works once.
            seed, seed_bits = challenge(default, "2001:db8:d:1::1")
            counter = solve(seed, seed_bits)
            body = f"seed={urllib.parse.quote(seed)}&counter={counter}"
            assert request(default, "2001:db8:d:2::1", "POST", "/.cdnf/challenge", None, body)[0] == 403, "a seed for another prefix was accepted"
            assert request(default, "2001:db8:d:1:5::5", "POST", "/.cdnf/challenge", None, body)[0] == 204, "a seed for the same prefix was refused"
            assert request(default, "2001:db8:d:1::1", "POST", "/.cdnf/challenge", None, body)[0] == 403, "a seed worked twice"

            # A tuned cell: 12 bits, and the User-Agent is part of the binding.
            tuned_cookie, tuned_bits = token(tuned, "2001:db8:e:1::1")
            assert tuned_bits == 12, tuned_bits
            assert request(tuned, "2001:db8:e:1:9::9", headers={"Cookie": f"cdnf_chal={tuned_cookie}"})[0] == 200
            assert request(tuned, "2001:db8:e:1::1", headers={"Cookie": f"cdnf_chal={tuned_cookie}", "User-Agent": "Browser/2"})[0] == 403, "the User-Agent binding was ignored"

            # A client without JavaScript is told what is needed instead of being left with a blank page.
            assert request(default, "198.51.100.78", "POST", "/", body="x=1")[0] == 405
            assert request(default, "198.51.100.79", "PUT", "/.cdnf/challenge", body="x=1")[0] == 405
            assert "needs JavaScript" in request(default, "198.51.100.77")[2]
            for name in cells:
                log = run("docker", "logs", name, check=False)
                assert "attempt to" not in log.stdout + log.stderr, log.stderr[-600:]
        finally:
            run("docker", "rm", "-f", ORIGIN, *cells, check=False)
    print("e2e challenge binding and difficulty passed")


if __name__ == "__main__":
    main()
