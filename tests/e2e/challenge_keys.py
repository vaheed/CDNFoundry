#!/usr/bin/env python3
"""Real-cell qualification of pool-wide challenge keys: portability, rotation, revocation, expiry, binding and the token budget.

Every cell derives its own fallback key from its own secret and name, so before it holds platform keys a token means nothing on
another cell. The test then gives cells keys over the control channel, the way the edge agent does, and checks:
  1. a token signed on one cell is accepted on another cell that holds the key, and refused by a cell that does not,
  2. a rotation keeps tokens of the previous key valid and new tokens sign with the new key, without re-challenging anyone,
  3. a key's end time is enforced by the cell's own clock, a revoked key is refused at once, and an older version is ignored,
  4. a token follows its client's address inside a prefix and its browser family, and not another family or a script,
  5. a token has a request budget per cell, and a refusal for a client that does not ask for HTML is machine-readable,
  6. malformed key sets are refused, and no secret appears in a response, the status report or a log.
"""

import json
import pathlib
import re
import sys
import tempfile
import time
import urllib.parse

sys.path.insert(0, str(pathlib.Path(__file__).resolve().parent))
from challenge_binding import ORIGIN, challenge, request, solve, start  # noqa: E402
from ipv6_rate_limit_keys import address  # noqa: E402
from managed_waf import NETWORK, host, run  # noqa: E402

CELLS = {name: f"cdnf-e2e-keys-{name}" for name in ("a", "b", "c", "budget")}
CHROME = "Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36"
CHROME_NEWER = CHROME.replace("130", "131")
FIREFOX = "Mozilla/5.0 (X11; Linux x86_64; rv:132.0) Gecko/20100101 Firefox/132.0"
K1, K2, K3 = ("1" * 16, "2" * 16, "3" * 16)
S1, S2, S3 = ("a1" * 32, "b2" * 32, "c3" * 32)


def body_json(body: str) -> dict:
    """The JSON object in a response body that may still carry chunked-transfer framing."""
    return json.loads(re.search(r"\{.*\}", body, re.S).group(0))


def control(name: str, payload: dict, expect: int = 200) -> None:
    result = run("docker", "exec", name, "wget", "-S", "-q", "-O-", "--header=X-Edge-Status-Token: " + TOKENS[name],
                 "--header=Content-Type: application/json", "--post-data=" + json.dumps(payload), "http://127.0.0.1:9080/control", check=False)
    status = int(re.search(r"HTTP/1\.\d (\d+)", result.stderr).group(1)) if "HTTP/" in result.stderr else 0
    assert status == expect, (name, payload.get("action"), status, result.stderr[-300:])


def keys(version: int, *entries: tuple[str, str, bool, int | None]) -> dict:
    return {"task_id": f"keys-{version}-{time.time_ns()}", "action": "challenge_keys", "version": version,
            "keys": [{"id": kid, "secret": secret, "signing": signing, "not_after": end} for kid, secret, signing, end in entries]}


def status(name: str) -> str:
    return run("docker", "exec", name, "wget", "-q", "-O-", "--header=X-Edge-Status-Token: " + TOKENS[name], "http://127.0.0.1:9080/passive-failures").stdout


def mint(ip: str, source: str, user_agent: str = "Browser/1") -> str:
    seed, bits = challenge(ip, source, user_agent)
    code, headers, _ = request(ip, source, "POST", "/.cdnf/challenge", {"User-Agent": user_agent}, f"seed={urllib.parse.quote(seed)}&counter={solve(seed, bits)}")
    assert code == 204, code
    return re.search(r"cdnf_chal=([0-9a-z.]+)", headers["set-cookie"]).group(1)


def with_token(ip: str, source: str, cookie: str, user_agent: str = "Browser/1", **kwargs) -> int:
    return request(ip, source, headers={"Cookie": f"cdnf_chal={cookie}", "User-Agent": user_agent}, **kwargs)[0]


TOKENS = {name: f"status-token-{name}" for name in CELLS.values()}


def main() -> None:
    cells = list(CELLS.values())
    run("docker", "rm", "-f", ORIGIN, *cells, check=False)
    run("docker", "run", "-d", "--name", ORIGIN, "--network", NETWORK, "nginx:1.30.3-alpine")
    origin_ip = address(ORIGIN)
    subnet = json.loads(run("docker", "network", "inspect", NETWORK).stdout)[0]["IPAM"]["Config"][0]["Subnet"]
    with tempfile.TemporaryDirectory(prefix="cdnf-e2e-keys-") as directory:
        temporary = pathlib.Path(directory)
        temporary.chmod(0o755)
        config = host("off", False, 0, origin_ip)
        config["domain"], config["domain_id"] = "chal.test", 601
        config["security"]["allowed_methods"] = ["GET", "HEAD"]
        config["security"]["limits"].update({"requests_per_second": 100000, "request_burst": 100000, "connections_per_client": 4096, "connections_per_domain": 100000})
        config["security"]["rules"] = [{"id": 1, "name": None, "priority": 1, "action": "challenge", "conditions": [{"field": "path", "operator": "equals", "value": "/"}]}]
        runtime = temporary / "runtime.json"
        runtime.write_text(json.dumps({"schema_version": 1, "sequence": 1, "generation_id": "e2e-keys-1", "certificates": {}, "hosts": {"chal.test": config}}))
        runtime.chmod(0o644)
        run("openssl", "req", "-x509", "-newkey", "rsa:2048", "-nodes", "-days", "1", "-subj", "/CN=chal.test", "-keyout", str(temporary / "tls.key"), "-out", str(temporary / "tls.crt"))
        for item in (temporary / "tls.key", temporary / "tls.crt"):
            item.chmod(0o644)
        try:
            ips = {}
            for name, container in CELLS.items():
                environment = {"EDGE_CHALLENGE_BITS": "12", "EDGE_STATUS_TOKEN": TOKENS[container], "EDGE_CELL_NAME": container}
                if name == "budget":
                    environment["EDGE_CHALLENGE_TOKEN_REQUESTS_PER_MINUTE"] = "30"
                ips[name] = start(container, runtime, temporary, subnet, environment)
            a, b, c, budget = (CELLS[n] for n in ("a", "b", "c", "budget"))
            now = int(time.time())

            # 1. Without platform keys a token is local to its cell.
            local = mint(ips["a"], "198.51.100.10")
            assert local.startswith("local."), local
            assert with_token(ips["a"], "198.51.100.10", local) == 200
            assert with_token(ips["b"], "198.51.100.10", local) == 403, "a cell-local token worked on another cell"

            # 2. Keys given to cells a and b: a token from either works on the other, and cell c, which has no keys, refuses it.
            control(a, keys(1, (K1, S1, True, None)))
            control(b, keys(1, (K1, S1, True, None)))
            assert '"challenge_keys_version":1' in status(a), "a cell must report the version it holds"
            from_a = mint(ips["a"], "198.51.100.11")
            from_b = mint(ips["b"], "198.51.100.12")
            assert from_a.startswith(K1 + ".") and from_b.startswith(K1 + "."), (from_a, from_b)
            assert with_token(ips["b"], "198.51.100.11", from_a) == 200, "a token from cell a was refused by cell b"
            assert with_token(ips["a"], "198.51.100.12", from_b) == 200, "a token from cell b was refused by cell a"
            assert with_token(ips["c"], "198.51.100.11", from_a) == 403, "a cell without the key accepted its token"
            # A seed is portable too: issued by one cell and solved on the other.
            seed, bits = challenge(ips["a"], "198.51.100.13")
            body = f"seed={urllib.parse.quote(seed)}&counter={solve(seed, bits)}"
            assert request(ips["b"], "198.51.100.13", "POST", "/.cdnf/challenge", None, body)[0] == 204, "a seed from cell a was refused by cell b"

            # 3. Rotation: K2 signs, K1 still verifies. Nobody holding a K1 token is challenged again, and new tokens are signed by K2.
            control(a, keys(2, (K2, S2, True, None), (K1, S1, False, now + 3600)))
            assert with_token(ips["a"], "198.51.100.11", from_a) == 200, "a rotation re-challenged a client with a valid token"
            rotated = mint(ips["a"], "198.51.100.14")
            assert rotated.startswith(K2 + "."), rotated
            assert with_token(ips["b"], "198.51.100.14", rotated) == 403, "a cell still on the old version accepted a key it does not hold"
            control(b, keys(2, (K2, S2, True, None), (K1, S1, False, now + 3600)))
            assert with_token(ips["b"], "198.51.100.14", rotated) == 200
            assert with_token(ips["b"], "198.51.100.11", from_a) == 200, "a rotation re-challenged a client with a valid token on cell b"

            # 4. A key's end is enforced by the cell's own clock, with no message from anyone.
            control(a, keys(3, (K2, S2, True, None), (K1, S1, False, int(time.time()) + 2)))
            assert with_token(ips["a"], "198.51.100.11", from_a) == 200
            time.sleep(3.2)
            assert with_token(ips["a"], "198.51.100.11", from_a) == 403, "a key past its end time still verified"
            assert with_token(ips["a"], "198.51.100.14", rotated) == 200, "the active key stopped with the overlap key"

            # 5. Revocation: the cell is given a set without K2 and refuses its tokens at once. An older version is ignored.
            control(a, keys(4, (K3, S3, True, None)))
            assert with_token(ips["a"], "198.51.100.14", rotated) == 403, "a revoked key still verified"
            control(a, keys(2, (K2, S2, True, None), (K1, S1, False, now + 3600)))
            assert with_token(ips["a"], "198.51.100.14", rotated) == 403, "an obsolete key set brought a revoked key back"
            assert '"challenge_keys_version":4' in status(a)
            assert mint(ips["a"], "198.51.100.15").startswith(K3 + ".")

            # 6. Binding: the same prefix and browser family keep the token; another family, a script, and another prefix do not.
            control(b, keys(5, (K3, S3, True, None)))
            chrome = mint(ips["b"], "2001:db8:a:b::1", CHROME)
            assert with_token(ips["b"], "2001:db8:a:b:9::9", chrome, CHROME_NEWER) == 200, "an address change and a browser update lost the token"
            assert with_token(ips["b"], "2001:db8:a:b::1", chrome, FIREFOX) == 403, "a token worked in another browser family"
            assert with_token(ips["b"], "2001:db8:a:b::1", chrome, "curl/8.5.0") == 403, "a token worked from a script"
            assert with_token(ips["b"], "2001:db8:a:c::1", chrome, CHROME) == 403, "a token worked for another prefix"

            # 7. A token has a request budget on a cell; another client's token is separate.
            control(budget, keys(1, (K3, S3, True, None)))
            heavy = mint(ips["budget"], "198.51.100.20")
            other = mint(ips["budget"], "198.51.100.21")
            codes = [with_token(ips["budget"], "198.51.100.20", heavy) for _ in range(45)]
            assert codes[:30] == [200] * 30 and set(codes[31:]) == {429}, codes
            code, headers, body = request(ips["budget"], "198.51.100.20", headers={"Cookie": f"cdnf_chal={heavy}"}, accept="application/json")
            assert code == 429 and 1 <= int(headers["retry-after"]) <= 60 and body_json(body)["error"] == "rate_limited", (code, headers, body)
            assert with_token(ips["budget"], "198.51.100.21", other) == 200, "one token's budget spent another's"

            # 8. A client that does not ask for HTML gets the reason as JSON, not a blank refusal.
            code, headers, body = request(ips["a"], "198.51.100.30", accept="application/json")
            assert code == 403 and headers["content-type"] == "application/json", (code, headers)
            assert body_json(body)["error"] == "challenge_required" and "browser" in body_json(body)["message"]
            code, _, body = request(ips["a"], "198.51.100.30", "POST", "/.cdnf/challenge", None, "seed=bad.1.2.3.ab&counter=1", accept="application/json")
            assert code == 403 and body_json(body)["error"] == "challenge_invalid", (code, body)

            # 9. Malformed key sets are refused and change nothing.
            for broken in (
                keys(9, (K1, S1, True, None), (K2, S2, True, None)),
                keys(9, (K1, S1, False, None)),
                keys(9, (K1, "abc", True, None)),
                keys(9, ("XYZ", S1, True, None)),
                keys(9, (K1, S1, True, None), (K1, S1, False, None)),
                keys(9, *[(f"{i:016x}", S1, i == 0, None) for i in range(5)]),
                {**keys(9, (K1, S1, True, None)), "version": 0},
            ):
                control(a, broken, expect=400)
            assert '"challenge_keys_version":4' in status(a)

            # 10. No secret appears in the status report or in any log.
            assert all(secret not in status(a) for secret in (S1, S2, S3))
            for container in cells:
                logs = run("docker", "logs", container, check=False)
                text = logs.stdout + logs.stderr
                assert not any(secret in text for secret in (S1, S2, S3)), f"a key secret was logged by {container}"
                assert "attempt to" not in text, text[-600:]
        finally:
            run("docker", "rm", "-f", ORIGIN, *cells, check=False)
    print("e2e challenge keys passed")


if __name__ == "__main__":
    main()
