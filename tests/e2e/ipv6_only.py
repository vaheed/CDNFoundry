#!/usr/bin/env python3
"""Qualify the IPv6 data path with IPv6 literals only. This does not automate a browser.

DNS is queried through DNSdist's published IPv6 listener over UDP and TCP, and the
gateway is reached on its IPv6 address over HTTP Host and strictly verified HTTPS SNI.
No IPv4 address is used to reach either service.
"""

import json
import pathlib
import subprocess
import tempfile

import gateway_ingress as gateway

DNS_SERVER = "::1"
DNS_PORT = "1053"
GATEWAY_V6 = "fd00:cd0f:10::10"
PLATFORM_ZONE = "cdnf.test"


def dig(owner: str, record_type: str, tcp: bool) -> str:
    command = ["dig", f"@{DNS_SERVER}", "-p", DNS_PORT, owner, record_type, "+short", "+time=3", "+tries=1"]
    if tcp:
        command.append("+tcp")
    return subprocess.run(command, check=True, text=True, capture_output=True, timeout=15).stdout.strip()


def main() -> None:
    for tcp in (False, True):
        answer = dig(PLATFORM_ZONE, "SOA", tcp)
        if "hostmaster" not in answer:
            raise RuntimeError(f"no IPv6 {'TCP' if tcp else 'UDP'} SOA answer for {PLATFORM_ZONE}: {answer!r}")

    gateway_state, cell = gateway.runtime()
    hostnames = {
        route["hostname"] for route in gateway_state["routes"]
        if route["address"] == GATEWAY_V6
    }
    hostname = gateway.strictly_servable_tls_hostname(cell, hostnames)
    with tempfile.TemporaryDirectory(prefix="cdnfoundry-ipv6-ca-") as directory:
        ca_path = pathlib.Path(directory) / "root.pem"
        pathlib.Path(directory).chmod(0o777)
        ca_path.write_text(gateway.active_certificate_chain(cell, hostname))
        ca_path.chmod(0o644)
        gateway.curl(GATEWAY_V6, hostname)
        gateway.curl(GATEWAY_V6, hostname, tls=True, tls_ca=ca_path)
        gateway.curl(GATEWAY_V6, "unknown-ipv6.example.test", expect_success=False)
    print(json.dumps({
        "status": "passed", "dns": {"server": DNS_SERVER, "transports": ["UDP", "TCP"]},
        "gateway": {"address": GATEWAY_V6, "hostname": hostname, "protocols": ["HTTP Host", "HTTPS SNI"],
                    "tls_verification": "strict"},
    }, indent=2))


if __name__ == "__main__":
    main()
