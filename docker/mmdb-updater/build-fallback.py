#!/usr/bin/env python3
"""Write a valid MMDB that answers "not found" for every address.

It lets DNS start on a host that cannot reach a GeoIP source: Geo-DNS records then return their
default answers until a real database is installed. The file is a one-node IPv6 search tree whose
both records point at "no data", followed by the metadata section the format requires.
"""
import struct
import sys
import time


def string(value: str) -> bytes:
    raw = value.encode()
    assert len(raw) < 29
    return bytes([0x40 | len(raw)]) + raw


def uint(kind: int, value: int) -> bytes:
    raw = value.to_bytes((value.bit_length() + 7) // 8, "big") if value else b""
    # Extended type bytes: uint64 is type 9 (extended 2), uint16 is 5, uint32 is 6.
    if kind in (5, 6):
        return bytes([(kind << 5) | len(raw)]) + raw
    return bytes([len(raw), kind - 7]) + raw


def main(path: str) -> None:
    node_count, record_size = 1, 24
    # Both records equal node_count, which the format defines as "no data".
    tree = (node_count).to_bytes(3, "big") * 2
    metadata = b"".join([
        bytes([0xE0 | 9]),  # map with nine entries
        string("binary_format_major_version"), uint(5, 2),
        string("binary_format_minor_version"), uint(5, 0),
        string("build_epoch"), uint(9, int(time.time())),
        string("database_type"), string("CDNFoundry-Fallback"),
        string("description"), bytes([0xE1]) + string("en") + string("Empty fallback database"),
        string("ip_version"), uint(5, 6),
        string("languages"), bytes([0x01, 0x04]) + string("en"),
        string("node_count"), uint(6, node_count),
        string("record_size"), uint(5, record_size),
    ])
    with open(path, "wb") as handle:
        handle.write(tree + b"\x00" * 16 + b"\xab\xcd\xefMaxMind.com" + metadata)


if __name__ == "__main__":
    main(sys.argv[1])
