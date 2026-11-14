#!/usr/bin/env python3
"""Real-runtime qualification that a client cannot get fresh rate-limit counters by rotating IPv6 addresses inside its prefix.

Cells run with the real image. Client addresses arrive over PROXY protocol, as they do from the gateway, so the test needs no
IPv6 routing. It covers the Lua rate-limit rules, the key of an address written two ways, IPv4-mapped IPv6, Nginx's own request
limit, the configurable prefix, and the exception list.
"""

import json
import os
import pathlib
import socket
import struct
import sys
import tempfile
import time
from concurrent.futures import ThreadPoolExecutor

sys.path.insert(0, str(pathlib.Path(__file__).resolve().parent))
from managed_waf import IMAGE, NETWORK, ROOT, host, run  # noqa: E402

ORIGIN = "cdnf-e2e-v6-origin"
CELLS = {"default": "cdnf-e2e-v6-default", "wide": "cdnf-e2e-v6-wide", "exceptions": "cdnf-e2e-v6-exceptions"}
SIGNATURE = b"\r\n\r\n\x00\r\nQUIT\n"


def address(container: str) -> str:
    return json.loads(run("docker", "inspect", container).stdout)[0]["NetworkSettings"]["Networks"][NETWORK]["IPAddress"]


def proxy_header(source: str) -> bytes:
    if ":" in source:
        packed = socket.inet_pton(socket.AF_INET6, source) + socket.inet_pton(socket.AF_INET6, "2001:db8::1") + struct.pack("!HH", 40000, 80)
        return SIGNATURE + bytes([0x21, 0x21]) + struct.pack("!H", len(packed)) + packed
    packed = socket.inet_aton(source) + socket.inet_aton("192.0.2.1") + struct.pack("!HH", 40000, 80)
    return SIGNATURE + bytes([0x21, 0x11]) + struct.pack("!H", len(packed)) + packed


def get(cell_ip: str, hostname: str, source: str) -> int:
    """One request whose client address, as the cell sees it, is `source`."""
    request = f"GET / HTTP/1.1\r\nHost: {hostname}\r\nConnection: close\r\n\r\n".encode()
    with socket.create_connection((cell_ip, 8081), timeout=10) as connection:
        connection.sendall(proxy_header(source) + request)
        data = b""
        while b"\r\n" not in data:
            chunk = connection.recv(4096)
            if not chunk:
                break
            data += chunk
    return int(data.split(b" ", 2)[1])


def get_forwarded(cell_ip: str, hostname: str, forwarded: str) -> int:
    request = f"GET / HTTP/1.1\r\nHost: {hostname}\r\nX-Forwarded-For: {forwarded}\r\nConnection: close\r\n\r\n".encode()
    with socket.create_connection((cell_ip, 8080), timeout=10) as connection:
        connection.sendall(request)
        data = connection.recv(4096)
    return int(data.split(b" ", 2)[1])


def domain(name: str, identifier: int, origin_ip: str, subnet: str, limited: bool) -> dict:
    config = host("off", False, 0, origin_ip)
    config["domain"], config["domain_id"] = name, identifier
    config["security"]["limits"].update({"requests_per_second": 100000, "request_burst": 100000, "connections_per_client": 256, "connections_per_domain": 100000})
    config["security"]["trusted_proxy_cidrs"] = [subnet]
    config["security"]["rules"] = [{
        "id": 1, "name": None, "priority": 1, "action": "block", "conditions": [{"field": "path", "operator": "starts_with", "value": "/"}],
        "rate_limit": {"key": "client_ip", "threshold": 3, "period_seconds": 600, "duration_seconds": 60},
    }] if limited else []
    return config


def statuses(cell_ip: str, hostname: str, sources: list[str]) -> list[int]:
    return [get(cell_ip, hostname, source) for source in sources]


def burst(cell_ip: str, sources: list[str]) -> int:
    """How many of the requests Nginx's own per-client request limit refused."""
    with ThreadPoolExecutor(max_workers=16) as executor:
        return sum(1 for status in executor.map(lambda source: get(cell_ip, "burst.v6.test", source), sources) if status == 503)


def start(name: str, runtime: pathlib.Path, temporary: pathlib.Path, subnet: str, config: pathlib.Path, environment: dict[str, str]) -> str:
    command = ["docker", "run", "-d", "--name", name, "--network", NETWORK,
               "-e", "EDGE_RUNTIME_FILE=/var/lib/cdnfoundry/runtime/active.json", "-e", "EDGE_STATUS_TOKEN=e2e",
               "-e", f"EDGE_PROXY_PROTOCOL_TRUSTED_CIDRS={subnet}"]
    for key, value in environment.items():
        command += ["-e", f"{key}={value}"]
    command += ["-v", f"{runtime}:/var/lib/cdnfoundry/runtime/active.json:ro", "-v", f"{config}:/etc/nginx/conf.d/default.conf:ro",
                "-v", f"{temporary / 'tls.crt'}:/run/edge/tls.crt:ro", "-v", f"{temporary / 'tls.key'}:/run/edge/tls.key:ro",
                "--tmpfs", "/var/cache/nginx:rw,noexec,nosuid,size=64m,mode=0777", IMAGE]
    run(*command)
    ip = address(name)
    deadline = time.monotonic() + 60
    while time.monotonic() < deadline:
        try:
            if get(ip, "ready.v6.test", "198.51.100.250") == 200:
                return ip
        except OSError:
            pass
        time.sleep(1)
    raise AssertionError(f"{name} did not start:\n{run('docker', 'logs', name, check=False).stderr}")


def main() -> None:
    cells = list(CELLS.values())
    run("docker", "rm", "-f", ORIGIN, *cells, check=False)
    run("docker", "run", "-d", "--name", ORIGIN, "--network", NETWORK, "nginx:1.30.3-alpine")
    origin_ip = address(ORIGIN)
    subnet = json.loads(run("docker", "network", "inspect", NETWORK).stdout)[0]["IPAM"]["Config"][0]["Subnet"]
    with tempfile.TemporaryDirectory(prefix="cdnf-e2e-v6-") as directory:
        temporary = pathlib.Path(directory)
        temporary.chmod(0o755)
        hosts = {
            "ready.v6.test": domain("ready.v6.test", 301, origin_ip, subnet, False),
            "rules.v6.test": domain("rules.v6.test", 302, origin_ip, subnet, True),
            "burst.v6.test": domain("burst.v6.test", 303, origin_ip, subnet, False),
        }
        runtime = temporary / "runtime.json"
        runtime.write_text(json.dumps({"schema_version": 1, "sequence": 1, "generation_id": "e2e-v6-1", "certificates": {}, "hosts": hosts}))
        runtime.chmod(0o644)
        run("openssl", "req", "-x509", "-newkey", "rsa:2048", "-nodes", "-days", "1", "-subj", "/CN=v6.test",
            "-keyout", str(temporary / "tls.key"), "-out", str(temporary / "tls.crt"))
        for item in (temporary / "tls.key", temporary / "tls.crt"):
            item.chmod(0o644)
        # Nginx's request limit is 5 requests per second here instead of 100, so the burst below is decisive on any machine.
        configuration = run("docker", "run", "--rm", "--entrypoint", "cat", IMAGE, "/etc/nginx/conf.d/default.conf").stdout
        assert "rate=100r/s" in configuration
        slow = temporary / "slow.conf"
        slow.write_text(configuration.replace("rate=100r/s", "rate=5r/s"))
        try:
            default = start(CELLS["default"], runtime, temporary, subnet, slow, {})
            wide = start(CELLS["wide"], runtime, temporary, subnet, slow, {"EDGE_IPV6_RATE_PREFIX": "56"})
            exceptions = start(CELLS["exceptions"], runtime, temporary, subnet, slow, {"EDGE_IPV6_EXACT_CIDRS": "2001:db8:ee::/48"})

            # Rotating addresses inside one /64 shares one allowance of 3; another /64 starts fresh.
            rotating = [f"2001:db8:1:2:{index:x}::{index:x}" for index in range(1, 11)]
            assert statuses(default, "rules.v6.test", rotating) == [200] * 3 + [429] * 7, "rotation inside a /64 got fresh counters"
            assert get(default, "rules.v6.test", "2001:db8:1:3::1") == 200
            # IPv4 and the same address as IPv4-mapped IPv6 are one client.
            assert statuses(default, "rules.v6.test", ["198.51.100.7", "198.51.100.7", "::ffff:198.51.100.7", "::ffff:198.51.100.7"]) == [200, 200, 200, 429]
            # Two IPv4 clients stay two clients.
            assert get(default, "rules.v6.test", "198.51.100.8") == 200
            # One address written two ways, through a trusted proxy's X-Forwarded-For, is one client.
            spellings = ["2001:db8:9:9::1", "2001:0db8:0009:0009:0000:0000:0000:0001", "2001:DB8:9:9:0:0:0:1", "2001:db8:9:9::0001"]
            assert [get_forwarded(default, "rules.v6.test", entry) for entry in spellings] == [200, 200, 200, 429]

            # Nginx's own limit counts the prefix too: 600 requests rotating inside a /64 are refused past the burst, and the same
            # 600 requests from 600 different /64s are not.
            inside = burst(default, [f"2001:db8:5:5:{index:x}:1::1" for index in range(600)])
            assert inside >= 200, f"Nginx's own request limit let a rotating client through ({inside} refused)"
            apart = burst(default, [f"2001:db8:6:{index:x}::1" for index in range(600)])
            assert apart <= 5, f"{apart} requests from distinct /64s were refused"

            # The prefix is configurable: with /56, two /64s of one /56 share an allowance, and the default cell keeps them apart.
            same_56 = ["2001:db8:1:21aa::1", "2001:db8:1:2155::1", "2001:db8:1:21ff::1", "2001:db8:1:2100::1"]
            assert statuses(wide, "rules.v6.test", same_56) == [200, 200, 200, 429]
            assert statuses(default, "rules.v6.test", ["2001:db8:2:21aa::1", "2001:db8:2:2155::1", "2001:db8:2:21ff::1", "2001:db8:2:2100::1"]) == [200] * 4
            assert get(wide, "rules.v6.test", "2001:db8:1:2200::1") == 200

            # A listed network keeps full addresses: rotation inside it is counted per address, outside it the /64 groups.
            assert statuses(exceptions, "rules.v6.test", [f"2001:db8:ee:1:{index:x}::{index:x}" for index in range(1, 11)]) == [200] * 10
            assert statuses(exceptions, "rules.v6.test", [f"2001:db8:1:2:{index:x}::{index:x}" for index in range(1, 11)]) == [200] * 3 + [429] * 7

            # No cell logged a Lua error while computing keys.
            for name in cells:
                log = run("docker", "logs", name, check=False)
                assert "attempt to" not in (log.stdout + log.stderr), f"{name} logged a Lua error:\n{(log.stderr)[-800:]}"
        finally:
            run("docker", "rm", "-f", ORIGIN, *cells, check=False)
    print("e2e IPv6 rate-limit key qualification passed")


if __name__ == "__main__":
    main()
