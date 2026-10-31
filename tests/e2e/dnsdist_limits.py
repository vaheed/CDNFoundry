#!/usr/bin/env python3
"""DNSdist answers over-limit and ANY UDP queries with TC instead of dropping them, and refuses ANY over TCP."""

import pathlib
import socket
import struct
import subprocess
import threading

ROOT = pathlib.Path(__file__).resolve().parents[2]
CONTAINER = "cdnfoundry-dev-dnsdist-1"
BURST = 3000
PER_CLIENT_QPS = 500


def dnsdist_address() -> str:
    result = subprocess.run(
        ["docker", "inspect", CONTAINER, "--format", "{{range .NetworkSettings.Networks}}{{.IPAddress}} {{end}}"],
        text=True, capture_output=True, check=True,
    )
    return result.stdout.split()[0]


def query(identifier: int, qtype: int) -> bytes:
    return struct.pack(">HHHHHH", identifier, 0x0100, 1, 0, 0, 0) + b"\x04test\x00" + struct.pack(">HH", qtype, 1)


def exchange(address: str, qtype: int, transport: int) -> bytes:
    with socket.socket(socket.AF_INET, transport) as sock:
        sock.settimeout(3)
        payload = query(1, qtype)
        if transport == socket.SOCK_STREAM:
            sock.connect((address, 53))
            sock.sendall(struct.pack(">H", len(payload)) + payload)
            length = struct.unpack(">H", sock.recv(2))[0]
            return sock.recv(length)
        sock.sendto(payload, (address, 53))
        return sock.recvfrom(512)[0]


def main() -> None:
    address = dnsdist_address()

    answer = exchange(address, 255, socket.SOCK_DGRAM)
    assert answer[2] & 0x02, "an ANY query over UDP must be answered with TC"
    answer = exchange(address, 255, socket.SOCK_STREAM)
    assert answer[3] & 0x0F == 4, f"an ANY query over TCP must be refused with NOTIMP, got rcode {answer[3] & 0x0F}"

    counts = {"truncated": 0, "answered": 0}
    sock = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
    sock.settimeout(1.5)

    def receive() -> None:
        try:
            while True:
                reply = sock.recvfrom(512)[0]
                counts["truncated" if reply[2] & 0x02 else "answered"] += 1
        except socket.timeout:
            pass

    receiver = threading.Thread(target=receive)
    receiver.start()
    for identifier in range(BURST):
        sock.sendto(query(identifier, 1), (address, 53))
    receiver.join()
    sock.close()

    received = counts["truncated"] + counts["answered"]
    assert counts["truncated"] > BURST // 2, f"over-limit UDP queries must be truncated, got {counts}"
    assert counts["answered"] <= PER_CLIENT_QPS * 3, f"one client must not exceed its rate by far, got {counts}"
    assert received > BURST * 0.9, f"over-limit queries must be answered, not dropped: {received} of {BURST} replies"
    print(f"dnsdist limits qualification passed: {counts}")


if __name__ == "__main__":
    main()
