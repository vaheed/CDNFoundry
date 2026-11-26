#!/usr/bin/env python3
"""Real OpenResty qualification of JWT validation at the edge.

A cell holds one domain with RS256 and ES256 public keys, an issuer and an audience, and a rule that blocks a covered path when the token is
not valid. Tokens are signed here with throwaway keys. The test sends valid RS256 and ES256 tokens, expired, not-yet-valid, wrongly signed,
wrong-issuer, wrong-audience, `none`, HS256-confusion, malformed and absent tokens, a key retired by its own end time, a token that names another
key ID, and a path the domain does not cover. It then counts a rate limit by token subject and checks that no token or claim is in the logs.
"""

import base64
import json
import os
import pathlib
import subprocess
import tempfile
import time

from cryptography.hazmat.primitives import hashes, serialization
from cryptography.hazmat.primitives.asymmetric import ec, padding, rsa, utils

import custom_rules as rules
import detections as shared

NAME = "cdnf-e2e-jwt"
ORIGIN = "cdnf-e2e-jwt-origin"
rules.NAME = NAME
shared.NAME = NAME
shared.ORIGIN = ORIGIN
run = shared.run
get = rules.get
expect = rules.expect
host = shared.host
BLOCKED = "custom_rule_block"


def b64(data: bytes) -> str:
    return base64.urlsafe_b64encode(data).rstrip(b"=").decode()


def public_pem(key) -> str:
    return key.public_key().public_bytes(serialization.Encoding.PEM, serialization.PublicFormat.SubjectPublicKeyInfo).decode()


RSA_KEY = rsa.generate_private_key(public_exponent=65537, key_size=2048)
OTHER_RSA = rsa.generate_private_key(public_exponent=65537, key_size=2048)
EC_KEY = ec.generate_private_key(ec.SECP256R1())
RETIRED_KEY = rsa.generate_private_key(public_exponent=65537, key_size=2048)


def token(key, alg: str = "RS256", kid: str | None = None, claims: dict | None = None, header: dict | None = None, signature: bytes | None = None) -> str:
    now = int(time.time())
    payload = {"iss": "https://issuer.test", "aud": "orders", "sub": "user-1", "exp": now + 600, "nbf": now - 10, **(claims or {})}
    head = {"alg": alg, "typ": "JWT", **({"kid": kid} if kid else {}), **(header or {})}
    signing = f"{b64(json.dumps(head).encode())}.{b64(json.dumps(payload).encode())}"
    if signature is None:
        if alg == "RS256":
            signature = key.sign(signing.encode(), padding.PKCS1v15(), hashes.SHA256())
        elif alg == "ES256":
            r, s = utils.decode_dss_signature(key.sign(signing.encode(), ec.ECDSA(hashes.SHA256())))
            signature = r.to_bytes(32, "big") + s.to_bytes(32, "big")
        else:
            signature = b""
    return f"{signing}.{b64(signature)}"


def call(path: str, bearer: str | None, hostname: str = "jwt.example") -> tuple[int, str, str]:
    return get(hostname, path, *([f"Authorization: Bearer {bearer}"] if bearer is not None else []))


def qualify(directory: pathlib.Path) -> None:
    root = directory / "html"
    root.mkdir()
    (root / "index.html").write_text("ok")
    (directory / "origin.conf").write_text("server { listen 80; location / { default_type application/json; return 200 '{\"ok\":true}'; } }\n")
    run("docker", "rm", "-f", ORIGIN, check=False)
    run("docker", "run", "-d", "--name", ORIGIN, "--network", rules.EDGE_NETWORK, "-v", f"{directory / 'origin.conf'}:/etc/nginx/conf.d/default.conf:ro", "nginx:1.31.9-alpine")
    ended = int(time.time()) + 5
    config = {"paths": ["/api"], "issuer": "https://issuer.test", "audience": "orders", "leeway_seconds": 5, "keys": [
        {"kid": "rsa-1", "alg": "RS256", "pem": public_pem(RSA_KEY), "expires_at": None},
        {"kid": "ec-1", "alg": "ES256", "pem": public_pem(EC_KEY), "expires_at": None},
        {"kid": "old", "alg": "RS256", "pem": public_pem(RETIRED_KEY), "expires_at": ended}]}
    invalid_blocks = [rules.rule(1, [rules.condition("jwt_valid", "equals", False)], "block")]
    limited = [rules.rule(1, [rules.condition("jwt_valid", "equals", True)], "block")]
    limited[0]["rate_limit"] = {"key": "jwt_subject", "threshold": 3, "period_seconds": 60, "duration_seconds": 60, "scope": "per_cell", "window": "fixed"}
    limited[0]["action"] = "block"
    hosts = {
        "jwt.example": host(1, invalid_blocks, jwt_validation=config),
        "jwtlog.example": host(2, [], jwt_validation=config),
        "jwtoff.example": host(3, invalid_blocks),
        "jwtrate.example": host(4, limited, jwt_validation=config),
    }
    (directory / "active.json").write_text(json.dumps({"schema_version": 1, "sequence": 1, "generation_id": "e2e-jwt-1", "certificates": {}, "hosts": hosts}, separators=(",", ":")))
    run("openssl", "req", "-x509", "-newkey", "rsa:2048", "-nodes", "-days", "1", "-subj", "/CN=e2e-jwt", "-keyout", str(directory / "tls.key"), "-out", str(directory / "tls.crt"))
    for file in directory.iterdir():
        if file.is_file():
            file.chmod(0o644)
    run("docker", "run", "-d", "--name", NAME, "--network", rules.EDGE_NETWORK,
        "--tmpfs", "/var/cache/nginx:rw,size=64m", "--tmpfs", "/var/lib/nginx/tmp:rw,size=32m", "--memory", "512m", "--cpus", "0.5", "--pids-limit", "96",
        "-e", "EDGE_RUNTIME_FILE=/var/lib/cdnfoundry/runtime/active.json", "-e", "EDGE_STATUS_TOKEN=runtime-test-token", "-e", "EDGE_ACCESS_LOG_STDOUT=true",
        "-v", f"{directory / 'active.json'}:/var/lib/cdnfoundry/runtime/active.json:ro",
        "-v", f"{directory / 'tls.crt'}:/run/edge/tls.crt:ro", "-v", f"{directory / 'tls.key'}:/run/edge/tls.key:ro", "cdnfoundry/edge-runtime:e2e")
    for _ in range(60):
        probe = run("docker", "run", "--rm", "--network", f"container:{NAME}", "curlimages/curl:8.16.0", "-s", "-o", "/dev/null", "-w", "%{http_code}", "-H", "Host: jwtoff.example", "http://127.0.0.1:8080/", check=False).stdout
        if probe == "200":
            break
        time.sleep(1)

    # Valid tokens of both algorithms pass; the key ID may be named or left out.
    expect(call("/api/orders", token(RSA_KEY, kid="rsa-1")), 200)
    expect(call("/api/orders", token(EC_KEY, "ES256", kid="ec-1")), 200)
    expect(call("/api/orders", token(RSA_KEY)), 200)
    expect(call("/api/orders", token(EC_KEY, "ES256")), 200)
    expect(call("/api/orders", token(RSA_KEY, claims={"aud": ["x", "orders"]})), 200)  # audience as a list
    expect(call("/api/orders", token(RSA_KEY, claims={"exp": int(time.time()) - 2})), 200)  # inside the clock allowance
    # The path is covered by prefix, exactly or below it, never by a longer name.
    expect(call("/api", token(RSA_KEY)), 200)
    expect(call("/apiary", None), 200)
    expect(call("/public", None), 200)
    # Refusals: every one is blocked by the rule on an invalid token.
    now = int(time.time())
    refused = {
        "absent": None, "expired": token(RSA_KEY, claims={"exp": now - 60}), "not yet valid": token(RSA_KEY, claims={"nbf": now + 600}),
        "no expiry": token(RSA_KEY, claims={"exp": None}), "wrong issuer": token(RSA_KEY, claims={"iss": "https://evil.test"}),
        "wrong audience": token(RSA_KEY, claims={"aud": "billing"}), "signed by another key": token(OTHER_RSA), "signed by another key with a known id": token(OTHER_RSA, kid="rsa-1"),
        "algorithm none": token(RSA_KEY, "none", signature=b""), "HS256 over the public key": token(RSA_KEY, "HS256", signature=b"\x01" * 32),
        "RS256 header with an ES256 key id": token(RSA_KEY, kid="ec-1"), "unknown key id": token(RSA_KEY, kid="ghost"), "critical header": token(RSA_KEY, header={"crit": ["x"]}),
        "tampered payload": token(RSA_KEY).split(".")[0] + "." + b64(json.dumps({"iss": "https://issuer.test", "aud": "orders", "exp": now + 9999}).encode()) + "." + token(RSA_KEY).split(".")[2],
        "short signature for ES256": token(EC_KEY, "ES256", signature=b"\x01" * 10), "two parts": "a.b", "not base64": "!!.!!.!!", "oversized": "a" * 5000,
    }
    for label, value in refused.items():
        code, head, _ = call("/api/orders", value)
        assert code == 403 and BLOCKED.lower() in head.lower(), (label, code, head)
    expect(get("jwt.example", "/api/orders", "Authorization: Basic dXNlcjpwYXNz"), 403, BLOCKED)
    # A key retired by its own end time stops working on the cell's clock, with no help from the control plane.
    retiring = token(RETIRED_KEY, kid="old")
    wait = ended - time.time()
    if wait > 0:
        expect(call("/api/orders", retiring), 200)
        time.sleep(wait + 1)
    expect(call("/api/orders", retiring), 403, BLOCKED)
    # Without the rule nothing is blocked, the failure is a counted event, and a domain without the feature never validates.
    expect(call("/api/orders", None, "jwtlog.example"), 200)
    expect(call("/api/orders", token(RSA_KEY), "jwtlog.example"), 200)
    assert shared.events(2, "jwt_invalid")[0][0]["count"] == 1, shared.events(2, "jwt_invalid")
    expect(call("/api/orders", None, "jwtoff.example"), 200)
    # Requests of one subject are counted together whatever the token, and another subject is counted on its own.
    results = [call("/api/orders", token(RSA_KEY, claims={"sub": "alice", "jti": str(i)}), "jwtrate.example")[0] for i in range(6)]
    assert results[:3] == [200, 200, 200] and set(results[3:]) == {429}, results
    expect(call("/api/orders", token(RSA_KEY, claims={"sub": "bob"}), "jwtrate.example"), 200)
    # Nothing of a token or its claims reaches the logs.
    logs = run("docker", "logs", NAME, check=False)
    text = logs.stdout + logs.stderr
    assert "user-1" not in text and "alice" not in text and token(RSA_KEY).split(".")[2] not in text and "attempt to" not in text, text[-600:]


def main() -> None:
    run("docker", "build", "-f", "docker/openresty/Dockerfile", "-t", "cdnfoundry/edge-runtime:e2e", ".")
    with tempfile.TemporaryDirectory(prefix="cdnf-jwt-") as directory:
        os.chmod(directory, 0o755)
        try:
            qualify(pathlib.Path(directory))
            print("jwt validation runtime qualification passed")
        finally:
            if os.environ.get("CDNF_KEEP_FAILED_RUNTIME") != "1":
                run("docker", "rm", "-f", NAME, ORIGIN, check=False)


if __name__ == "__main__":
    main()
