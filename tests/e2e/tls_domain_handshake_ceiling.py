#!/usr/bin/env python3
"""A domain's TLS handshakes are capped at a separate ceiling, 20 times the per-client budget, in a real cell."""

import json
import pathlib
import socket
import ssl
import subprocess
import tempfile
import time

ROOT = pathlib.Path(__file__).resolve().parents[2]
CELL_IMAGE = "cdnfoundry-dev-edge-a-cell-03"
NETWORK = "cdnfoundry-dev_telemetry"
NAME = "cdnfoundry-tls-handshake-test"
HOST = "handshake.example"
BUDGET = 1  # per-client budget; the cell's domain-wide ceiling is 20 times this


def docker(*args: str, timeout: int = 120) -> subprocess.CompletedProcess[str]:
    return subprocess.run(["docker", *args], text=True, capture_output=True, timeout=timeout, check=False)


def handshake(address: str, client: str) -> bool:
    """One TLS handshake whose PROXY protocol header names `client` as the source."""
    context = ssl.SSLContext(ssl.PROTOCOL_TLS_CLIENT)
    context.check_hostname = False
    context.verify_mode = ssl.CERT_NONE
    try:
        with socket.create_connection((address, 8444), timeout=5) as raw:
            family = "TCP6" if ":" in client else "TCP4"
            raw.sendall(f"PROXY {family} {client} {'::1' if ':' in client else '10.0.0.1'} 40000 443\r\n".encode())
            with context.wrap_socket(raw, server_hostname=HOST):
                return True
    except (ssl.SSLError, OSError):
        return False


def main() -> None:
    docker("rm", "-f", NAME)
    with tempfile.TemporaryDirectory() as scratch:
        directory = pathlib.Path(scratch)
        directory.chmod(0o755)
        subprocess.run(["openssl", "req", "-x509", "-newkey", "rsa:2048", "-nodes", "-days", "1", "-subj", f"/CN={HOST}",
                        "-addext", f"subjectAltName=DNS:{HOST}", "-keyout", str(directory / "k.pem"), "-out", str(directory / "c.pem")],
                       check=True, capture_output=True)
        entrypoint = directory / "entrypoint.sh"
        entrypoint.write_text((ROOT / "docker/openresty/entrypoint.sh").read_text())
        entrypoint.chmod(0o755)
        runtime = directory / "rt"
        runtime.mkdir(mode=0o755)
        state = {
            "schema_version": 1, "generation_id": "g1", "sequence": 1,
            "certificates": {"c1": {"id": "c1", "certificate_pem": (directory / "c.pem").read_text(), "chain_pem": "",
                                    "private_key_pem": (directory / "k.pem").read_text(), "expires_at": int(time.time()) + 3600, "names": [HOST]}},
            "hosts": {HOST: {"domain": HOST, "domain_id": 1, "revision": 1, "settings": {"enabled": True},
                             "security": {"limits": {"tls_handshakes_per_second": BUDGET}},
                             "tls": {"mode": "custom", "certificate_id": "c1"},
                             "origin": {"host": "127.0.0.1", "port": 80, "scheme": "http", "host_header": HOST, "sni": None, "verify_tls": False,
                                        "connect_timeout_ms": 1000, "response_timeout_ms": 5000, "retry_count": 0,
                                        "private_allowlist": [], "blocked_networks": [], "blocked_addresses": []}}},
        }
        (runtime / "cell.json").write_text(json.dumps(state))
        (runtime / "cell.json").chmod(0o644)
        started = docker(
            "run", "-d", "--name", NAME, "--network", NETWORK, "--cpus", "1",
            "--tmpfs", "/var/lib/nginx/tmp:rw,size=64m",
            "-e", "EDGE_RUNTIME_FILE=/rt/cell.json", "-e", "EDGE_STATUS_TOKEN=handshake-test",
            "-e", "EDGE_PROXY_PROTOCOL_TRUSTED_CIDRS=0.0.0.0/0,::/0",
            "-v", "cdnfoundry-dev_dev-pki:/run/edge:ro", "-v", "cdnfoundry-dev_mmdb:/mmdb:ro", "-v", f"{runtime}:/rt:ro",
            "-v", f"{ROOT / 'docker/openresty/runtime.lua'}:/etc/cdnfoundry/runtime.lua:ro",
            "-v", f"{ROOT / 'docker/nginx/openresty.conf'}:/usr/local/openresty/nginx/conf/nginx.conf:ro",
            "-v", f"{entrypoint}:/usr/local/bin/cdnfoundry-edge-entrypoint:ro",
            CELL_IMAGE,
        )
        assert started.returncode == 0, started.stderr
        try:
            address = docker("inspect", NAME, "--format", "{{(index .NetworkSettings.Networks \"" + NETWORK + "\").IPAddress}}").stdout.strip()
            deadline = time.time() + 60
            while time.time() < deadline and not handshake(address, "203.0.113.250"):
                time.sleep(1)
            else:
                if time.time() >= deadline:
                    raise AssertionError("the cell never completed a handshake:\n" + docker("logs", "--tail", "20", NAME).stderr)
            time.sleep(1.5)  # leave the per-second window the readiness probe used

            attempts = [handshake(address, f"198.51.100.{index % 200 + 1}") for index in range(120)]
            succeeded, refused = attempts.count(True), attempts.count(False)
            # 120 attempts take at most a few seconds; each second admits at most BUDGET * 20 handshakes.
            assert succeeded >= 15, f"the domain must admit handshakes up to its ceiling, only {succeeded} succeeded"
            assert refused >= 50, f"handshakes beyond the domain ceiling must be refused, only {refused} were"
        finally:
            docker("rm", "-f", NAME)
    print(f"tls domain handshake ceiling qualification passed: {succeeded} admitted, {refused} refused")


if __name__ == "__main__":
    main()
