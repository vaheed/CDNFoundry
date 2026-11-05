#!/usr/bin/env python3
"""Real OpenResty check that the origin sees the client address and that forgery has no effect.

No browser automation. The test starts one cell that trusts the Docker edge
network for PROXY protocol and one that trusts nothing, plus an echoing origin.
"""

import json
import os
import pathlib
import socket
import struct
import subprocess
import tempfile
import time

ROOT = pathlib.Path(__file__).resolve().parents[2]
PREFIX = "cdnf-client-address-e2e"
EDGE_NETWORK = os.environ.get("CDNF_EDGE_NETWORK", f"{os.environ.get('COMPOSE_PROJECT_NAME', 'cdnfoundry-dev')}_edge")
HOST = "client-address.example"
BEHIND_PROXY = "behind-proxy.example"
SIGNATURE = b"\r\n\r\n\x00\r\nQUIT\n"


def run(*args: str, check: bool = True) -> subprocess.CompletedProcess[str]:
    result = subprocess.run(args, cwd=ROOT, check=False, text=True, capture_output=True)
    if check and result.returncode != 0:
        raise RuntimeError(f"command failed ({result.returncode}): {' '.join(args)}\n{result.stdout}\n{result.stderr}")
    return result


def proxy_header(source: str) -> bytes:
    if ":" in source:
        packed = socket.inet_pton(socket.AF_INET6, source) + socket.inet_pton(socket.AF_INET6, "2001:db8::1") + struct.pack("!HH", 40000, 80)
        return SIGNATURE + bytes([0x21, 0x21]) + struct.pack("!H", len(packed)) + packed
    packed = socket.inet_aton(source) + socket.inet_aton("192.0.2.1") + struct.pack("!HH", 40000, 80)
    return SIGNATURE + bytes([0x21, 0x11]) + struct.pack("!H", len(packed)) + packed


def fetch(address: str, source: str | None, headers: dict[str, str] | None = None, host: str = HOST) -> dict:
    request = f"GET / HTTP/1.1\r\nHost: {host}\r\nConnection: close\r\n"
    for name, value in (headers or {}).items():
        request += f"{name}: {value}\r\n"
    request += "\r\n"
    payload = (proxy_header(source) if source else b"") + request.encode()
    with socket.create_connection((address, 8081), timeout=5) as connection:
        connection.sendall(payload)
        chunks = []
        while True:
            chunk = connection.recv(65536)
            if not chunk:
                break
            chunks.append(chunk)
    body = b"".join(chunks).split(b"\r\n\r\n", 1)[1].decode()
    return dict(line.split("=", 1) for line in body.splitlines() if "=" in line)


def container_address(name: str) -> str:
    return run("docker", "inspect", "-f", f"{{{{(index .NetworkSettings.Networks \"{EDGE_NETWORK}\").IPAddress}}}}", name).stdout.strip()


def wait_ready(name: str) -> None:
    for _ in range(60):
        if run("docker", "exec", name, "wget", "-qO-", "http://127.0.0.1:8080/healthz", check=False).returncode == 0:
            return
        time.sleep(0.5)
    raise RuntimeError(f"{name} did not become ready")


def main() -> None:
    run("docker", "build", "-f", "docker/openresty/Dockerfile", "-t", "cdnfoundry/edge-runtime:test", ".")
    subnet = run("docker", "network", "inspect", "-f", "{{(index .IPAM.Config 0).Subnet}}", EDGE_NETWORK).stdout.strip()
    names = [f"{PREFIX}-origin", f"{PREFIX}-trusting", f"{PREFIX}-untrusting"]
    run("docker", "rm", "-f", *names, check=False)
    with tempfile.TemporaryDirectory(prefix="cdnf-client-address-") as directory:
        os.chmod(directory, 0o755)
        temporary = pathlib.Path(directory)
        run("openssl", "req", "-x509", "-newkey", "rsa:2048", "-nodes", "-days", "1", "-subj", "/CN=client-address",
            "-keyout", str(temporary / "tls.key"), "-out", str(temporary / "tls.crt"))
        for path in (temporary / "tls.key", temporary / "tls.crt"):
            path.chmod(0o644)
        origin_config = temporary / "origin.conf"
        origin_config.write_text(
            "server { listen 80; location / { default_type text/plain; "
            "return 200 'xff=$http_x_forwarded_for\nforwarded=$http_forwarded\ninternal=$http_x_cdn_client_address\n'; } }\n")
        origin_config.chmod(0o644)
        runtime = {"schema_version": 1, "sequence": 1, "generation_id": "e2e-generation-1", "certificates": {}, "hosts": {HOST: {
            "domain": HOST, "revision": 1, "settings": {"enabled": True},
            "cache": {"enabled": False}, "origin": {
                "host": f"{PREFIX}-origin", "port": 80, "scheme": "http", "host_header": "origin.example", "sni": None,
                "verify_tls": False, "connect_timeout_ms": 1000, "response_timeout_ms": 5000, "retry_count": 0,
                "websocket": False, "health_check": None, "private_allowlist": ["172.16.0.0/12"],
                "blocked_networks": [], "blocked_addresses": [],
            },
        }}}
        # A domain behind its own trusted proxies reads X-Forwarded-For from the right.
        runtime["hosts"][BEHIND_PROXY] = json.loads(json.dumps(runtime["hosts"][HOST])) | {
            "domain": BEHIND_PROXY, "security": {"trusted_proxy_cidrs": ["198.51.100.0/24"]},
        }
        (temporary / "shared-default.json").write_text(json.dumps(runtime))
        (temporary / "shared-default.json").chmod(0o644)
        try:
            run("docker", "run", "-d", "--name", names[0], "--network", EDGE_NETWORK, "-v", f"{origin_config}:/etc/nginx/conf.d/default.conf:ro", "nginx:1.30.3-alpine")
            for name, trusted in ((names[1], subnet), (names[2], "192.0.2.0/24")):
                run("docker", "run", "-d", "--name", name, "--network", EDGE_NETWORK, "--add-host", "vector:127.0.0.1",
                    "--tmpfs", "/var/cache/nginx:rw,size=64m", "-e", "EDGE_RUNTIME_FILE=/var/lib/cdnfoundry/runtime/shared-default.json",
                    "-e", "EDGE_STATUS_TOKEN=client-address-test", "-e", f"EDGE_PROXY_PROTOCOL_TRUSTED_CIDRS={trusted}",
                    "-v", f"{temporary / 'tls.crt'}:/run/edge/tls.crt:ro", "-v", f"{temporary / 'tls.key'}:/run/edge/tls.key:ro",
                    "-v", f"{directory}:/var/lib/cdnfoundry/runtime:ro", "cdnfoundry/edge-runtime:test")
            for name in names[1:]:
                wait_ready(name)
            trusting, untrusting = container_address(names[1]), container_address(names[2])

            # A trusted gateway's PROXY header carries the real client over IPv4 and IPv6.
            for source, expected_forwarded in (("198.51.100.7", "for=198.51.100.7;"), ("2001:db8::77", 'for="[2001:db8::77]";')):
                result = fetch(trusting, source)
                assert result["xff"] == source, result
                assert result["forwarded"].startswith(expected_forwarded), result
                assert result["internal"] == "", f"the internal header must not reach the origin: {result}"

            # A forged X-Forwarded-For or internal header changes nothing.
            forged = fetch(trusting, "198.51.100.8", {"X-Forwarded-For": "203.0.113.9", "Forwarded": "for=203.0.113.9", "X-CDN-Client-Address": "203.0.113.99"})
            assert forged["xff"] == "198.51.100.8" and "203.0.113" not in forged["forwarded"], forged

            # Only a configured trusted proxy may supply X-Forwarded-For: the rightmost
            # address that is not itself a trusted proxy is the client.
            chained = fetch(trusting, "198.51.100.7", {"X-Forwarded-For": "203.0.113.200, 192.0.2.55, 198.51.100.9"}, BEHIND_PROXY)
            assert chained["xff"] == "192.0.2.55", chained
            garbage = fetch(trusting, "198.51.100.7", {"X-Forwarded-For": "192.0.2.55, not-an-address"}, BEHIND_PROXY)
            assert garbage["xff"] == "198.51.100.7", garbage

            # A PROXY header from a peer outside the trusted network is ignored.
            ignored = fetch(untrusting, "198.51.100.9")
            assert ignored["xff"] != "198.51.100.9" and ignored["xff"] != "", ignored
            assert ignored["xff"] not in ("127.0.0.1", ""), ignored
        finally:
            run("docker", "rm", "-f", *names, check=False)
    print("client address qualification passed")


if __name__ == "__main__":
    main()
