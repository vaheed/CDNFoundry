#!/usr/bin/env python3
"""Synthetic UDP/TCP DNS for origin qualification, isolated by its caller."""
from __future__ import annotations

import ipaddress
import json
from pathlib import Path
import socketserver
import struct
import threading
import time

CONFIG = Path('/fixtures/records.json')


def encode_name(name: str) -> bytes:
    return b''.join(bytes([len(label)]) + label.encode('ascii') for label in name.rstrip('.').split('.')) + b'\0'


def answer(packet: bytes, protocol: str) -> bytes:
    if len(packet) < 12 or struct.unpack('!H', packet[4:6])[0] != 1:
        raise ValueError('One DNS question is required')
    offset, labels = 12, []
    while offset < len(packet) and packet[offset]:
        size = packet[offset]
        if size > 63 or offset + size + 1 > len(packet):
            raise ValueError('Malformed question')
        labels.append(packet[offset + 1:offset + size + 1].decode('ascii'))
        offset += size + 1
    offset += 1
    qtype, qclass = struct.unpack('!HH', packet[offset:offset + 4])
    question = packet[12:offset + 4]
    name = '.'.join(labels).lower()
    document = CONFIG.read_bytes()
    if len(document) > 65536:
        raise ValueError('Fixture configuration exceeds its bound')
    settings = json.loads(document).get(name, {'error': 3})
    family = 'A' if qtype == 1 else 'AAAA' if qtype == 28 else 'unsupported'
    settings = {**settings, **settings.get(family + '_options', {})}
    delay = min(2.0, max(0, float(settings.get('delay', 0))))
    time.sleep(delay)
    code = int(settings.get('error', 0))
    records = []
    flags = 0x8180 | code
    if settings.get('tcp') and protocol == 'udp':
        flags |= 0x0200
    elif not code and qclass == 1:
        owner = b'\xc0\x0c'
        if settings.get('cname'):
            target = encode_name(settings['cname'])
            records.append(owner + struct.pack('!HHIH', 5, 1, 0, len(target)) + target)
            owner = target
        for address in settings.get(family, [])[:128]:
            packed = ipaddress.ip_address(address).packed
            if len(packed) != (4 if qtype == 1 else 16):
                raise ValueError('Fixture address family mismatch')
            records.append(owner + struct.pack('!HHIH', qtype, 1, 0, len(packed)) + packed)
    print(json.dumps({'name': name, 'type': family, 'protocol': protocol, 'records': len(records), 'error': code}), flush=True)
    return packet[:2] + struct.pack('!HHHHH', flags, 1, len(records), 0, 0) + question + b''.join(records)


class UDP(socketserver.BaseRequestHandler):
    def handle(self) -> None:
        packet, connection = self.request
        connection.sendto(answer(packet, 'udp'), self.client_address)


class TCP(socketserver.BaseRequestHandler):
    def handle(self) -> None:
        self.request.settimeout(3)

        def receive(length: int) -> bytes:
            data = bytearray()
            while len(data) < length:
                part = self.request.recv(length - len(data))
                if not part:
                    raise ValueError('Incomplete TCP message')
                data.extend(part)
            return bytes(data)

        packet = receive(struct.unpack('!H', receive(2))[0])
        response = answer(packet, 'tcp')
        self.request.sendall(struct.pack('!H', len(response)) + response)


if __name__ == '__main__':
    with socketserver.ThreadingUDPServer(('0.0.0.0', 53), UDP) as udp, \
            socketserver.ThreadingTCPServer(('0.0.0.0', 53), TCP) as tcp:
        threading.Thread(target=udp.serve_forever, daemon=True).start()
        print('synthetic_dns_ready', flush=True)
        tcp.serve_forever()
