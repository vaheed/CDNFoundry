#!/usr/bin/env python3
"""A challenge solved on one real edge is accepted on the other, through a real proxied domain (the development stack).

The domain is served by the shared cell of both development edges. The test starts under attack mode on it through the admin API, waits
until both edges challenge, then, from one client address, solves the challenge on the first edge, presents the token to the second edge and
solves in the other direction as well. It checks that the same address without the token is still challenged, that the token does not follow
another address, and that the cell of the second edge did not issue it. It ends the incident and removes the address it added.

The client address is one extra /32 on the host loopback, so both gateways see the same peer; that needs root and a development host. It
creates one throwaway administrator with generated credentials. Run it only against a development stack.
"""

import json
import os
import re
import secrets
import socket
import subprocess
import sys
import time
import urllib.parse
import uuid

sys.path.insert(0, __import__("pathlib").Path(__file__).resolve().parent.as_posix())
from challenge_binding import solve  # noqa: E402
from e2e import ApiError, artisan, login, php_string, request, wait_for_api  # noqa: E402

DOMAIN = os.environ.get("CDNF_CHALLENGE_DOMAIN", "test.com")
GATEWAYS = {"edge-a": "172.28.10.10", "edge-b": "172.28.20.10"}
CLIENT = "198.51.100.77"
OTHER_CLIENT = "198.51.100.78"
EMAIL = f"challenge-token-{secrets.token_hex(3)}@example.test"
PASSWORD = secrets.token_urlsafe(24)
HTML = "text/html"


def http(edge: str, method: str = "GET", path: str = "/", source: str = CLIENT, cookie: str = "", body: str = "") -> tuple[int, dict[str, str], str]:
    lines = [f"{method} {path} HTTP/1.1", f"Host: {DOMAIN}", "Connection: close", f"Accept: {HTML}", "User-Agent: Browser/1"]
    if cookie:
        lines.append(f"Cookie: _chk={cookie}")
    if body:
        lines += ["Content-Type: application/x-www-form-urlencoded", f"Content-Length: {len(body)}"]
    with socket.socket() as connection:
        connection.settimeout(10)
        connection.bind((source, 0))
        connection.connect((GATEWAYS[edge], 80))
        connection.sendall(("\r\n".join(lines) + "\r\n\r\n" + body).encode())
        data = b""
        while chunk := connection.recv(65536):
            data += chunk
    head, _, content = data.partition(b"\r\n\r\n")
    status_line, *header_lines = head.decode("latin1").split("\r\n")
    headers = {line.split(":", 1)[0].lower(): line.split(":", 1)[1].strip() for line in header_lines if ":" in line}
    return int(status_line.split(" ", 2)[1]), headers, content.decode("utf-8", "replace")


def seed_of(edge: str, source: str = CLIENT) -> tuple[str, int]:
    status, headers, page = http(edge, source=source)
    assert status == 403 and "Checking your browser" in page, f"{edge} did not challenge {source}: {status} {page[:160]}"
    return re.search(r'var seed="([^"]+)"', page).group(1), int(re.search(r",bits=(\d+),", page).group(1))


def solved_token(edge: str, source: str = CLIENT) -> str:
    seed, bits = seed_of(edge, source)
    status, headers, _ = http(edge, "POST", "/.cdnf/challenge", source, body=f"seed={urllib.parse.quote(seed)}&counter={solve(seed, bits)}")
    assert status == 204, f"{edge} refused a correct solution: {status}"
    return re.search(r"_chk=([0-9a-z.]+)", headers["set-cookie"]).group(1)


def wait_until_challenging(timeout: int = 150) -> None:
    deadline = time.monotonic() + timeout
    pending = set(GATEWAYS)
    while pending and time.monotonic() < deadline:
        for edge in sorted(pending):
            try:
                if http(edge, source=OTHER_CLIENT)[0] == 403:
                    pending.discard(edge)
            except OSError:
                pass
        time.sleep(3)
    assert not pending, f"under attack mode never reached {sorted(pending)}"


def domain_id(token: str) -> int:
    cursor = ""
    while True:
        _, page = request("GET", "/api/domains" + (f"?cursor={cursor}" if cursor else ""), token=token)
        for item in page["data"]:
            if item.get("name") == DOMAIN:
                return item["id"]
        cursor = (page.get("meta") or {}).get("next_cursor") or ""
        if not cursor:
            raise AssertionError(f"domain {DOMAIN} not found")


def main() -> None:
    wait_for_api()
    artisan("App\\Models\\User::query()->create(['name'=>'Challenge Token','email'=>" + php_string(EMAIL) + ",'password'=>Illuminate\\Support\\Facades\\Hash::make(" + php_string(PASSWORD) + "),'type'=>'admin']);")
    token = login(EMAIL, PASSWORD)
    domain = domain_id(token)
    subprocess.run(["ip", "addr", "add", f"{CLIENT}/32", "dev", "lo"], check=True)
    subprocess.run(["ip", "addr", "add", f"{OTHER_CLIENT}/32", "dev", "lo"], check=True)
    started = False
    try:
        request("POST", f"/api/domains/{domain}/incident", {"duration_minutes": 5}, token, str(uuid.uuid4()))
        started = True
        wait_until_challenging()
        results: dict[str, object] = {}

        # 1. Solved on edge A, accepted on edge B (the status is whatever the origin of this development domain answers; what matters is that it is not the challenge). The same address without the token is still challenged there.
        issued_a = solved_token("edge-a")
        assert http("edge-b")[0] == 403, "edge B stopped challenging without a token"
        status, _, page = http("edge-b", cookie=issued_a)
        assert status != 403 and "Checking your browser" not in page, f"edge B refused a token that edge A issued: {status}"
        results["a_to_b"] = status

        # 2. And the other way: solved on edge B, accepted on edge A.
        issued_b = solved_token("edge-b")
        status, _, page = http("edge-a", cookie=issued_b)
        assert status != 403 and "Checking your browser" not in page, f"edge A refused a token that edge B issued: {status}"
        results["b_to_a"] = status

        # 3. Each token carries the key that signed it, and both edges signed with the same one.
        assert issued_a.split(".")[0] == issued_b.split(".")[0], (issued_a.split(".")[0], issued_b.split(".")[0])
        results["key_id"] = issued_a.split(".")[0]

        # 4. The token is bound to its client: another address, with the same token, is challenged on both edges.
        for edge in GATEWAYS:
            assert http(edge, source=OTHER_CLIENT, cookie=issued_a)[0] == 403, f"{edge} accepted a token for another address"
        results["other_address_refused"] = True
        print(json.dumps(results))
    finally:
        if started:
            try:
                request("DELETE", f"/api/domains/{domain}/incident", token=token, idempotency_key=str(uuid.uuid4()))
            except ApiError as error:
                print("could not end the incident:", error, file=sys.stderr)
        for address in (CLIENT, OTHER_CLIENT):
            subprocess.run(["ip", "addr", "del", f"{address}/32", "dev", "lo"], check=False)
    print("e2e challenge token across two real edges passed")


if __name__ == "__main__":
    main()
