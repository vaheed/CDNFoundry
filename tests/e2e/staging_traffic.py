#!/usr/bin/env python3
"""Bounded GET probes of an owned static resource through explicit staging edges.

Verifies public TLS with the requested hostname. No browser, cookies, credentials,
redirect following, rendered-UI inspection, or control-plane mutations.
"""

import argparse
import hashlib
import http.client
import ipaddress
import json
import os
import socket
import ssl
import tempfile
import time
from datetime import datetime, timezone
from pathlib import Path


class EdgeHTTPS(http.client.HTTPSConnection):
    def __init__(self, hostname, address):
        super().__init__(hostname, timeout=15, context=ssl.create_default_context())
        self.address = address

    def connect(self):
        connection = socket.create_connection((self.address, self.port), timeout=self.timeout)
        try:
            self.sock = self._context.wrap_socket(connection, server_hostname=self.host)
        except Exception:
            connection.close()
            raise


def probe(hostname, address, path):
    connection = EdgeHTTPS(hostname, address)
    try:
        connection.connect()
        fingerprint = hashlib.sha256(connection.sock.getpeercert(binary_form=True)).hexdigest()
        connection.request('GET', path, headers={'Accept-Encoding': 'identity'})
        response = connection.getresponse()
        body = response.read(1024 * 1024 + 1)
        if len(body) > 1024 * 1024:
            raise ValueError('Static resource exceeds 1 MiB')
        return {'status': response.status,
                'cache': response.getheader('X-CDNFoundry-Cache'),
                'body_sha256': hashlib.sha256(body).hexdigest(), 'bytes': len(body),
                'certificate_sha256': fingerprint, 'tls_verified': True}
    finally:
        connection.close()


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--hostname', required=True)
    parser.add_argument('--edge', action='append', type=ipaddress.ip_address, required=True)
    parser.add_argument('--path', required=True)
    parser.add_argument('--expected-status', type=int, choices=[200, 403], default=200)
    parser.add_argument('--expected-sha256')
    parser.add_argument('--samples', type=int, choices=range(1, 6), default=3)
    parser.add_argument('--expect-hit', action='store_true')
    parser.add_argument('--expect-first-miss', action='store_true')
    parser.add_argument('--report', type=Path, required=True)
    args = parser.parse_args()
    if len(args.edge) > 8 or not args.path.startswith('/') or any(c in args.path for c in '\r\n?#'):
        parser.error('Use 1–8 edges and a resource path without query, fragment or newlines')
    if any(c in args.hostname for c in '/:@\r\n'):
        parser.error('Use a DNS hostname only')
    checks = []
    for address in args.edge:
        row = {'edge': str(address), 'outcome': 'failed', 'samples': []}
        try:
            for index in range(args.samples):
                if index:
                    time.sleep(1)
                sample = probe(args.hostname, str(address), args.path)
                row['samples'].append(sample)
                if sample['status'] != args.expected_status:
                    raise ValueError('Unexpected HTTP status')
                if args.expected_sha256 and sample['body_sha256'] != args.expected_sha256:
                    raise ValueError('Resource differs from expected origin content')
            if args.expect_hit and not any(r['cache'] == 'HIT' for r in row['samples']):
                raise ValueError('No cache HIT within sample bound')
            if args.expect_first_miss and row['samples'][0]['cache'] != 'MISS':
                raise ValueError('First request was not a cache MISS')
            row['outcome'] = 'passed'
        except Exception as error:
            row['error_type'] = type(error).__name__
        checks.append(row)
        print(str(address), row['outcome'], flush=True)
    passed = all(row['outcome'] == 'passed' for row in checks)
    report = {'scope': 'bounded verified HTTPS static-resource requests only',
              'recorded_at': datetime.now(timezone.utc).isoformat(),
              'outcome': 'passed' if passed else 'failed', 'checks': checks}
    args.report.parent.mkdir(parents=True, exist_ok=True)
    descriptor, candidate = tempfile.mkstemp(prefix='.staging-traffic-', dir=args.report.parent)
    try:
        with os.fdopen(descriptor, 'w') as stream:
            json.dump(report, stream, indent=2)
            stream.write('\n')
        os.replace(candidate, args.report)
    finally:
        Path(candidate).unlink(missing_ok=True)
    return 0 if passed else 1


if __name__ == '__main__':
    raise SystemExit(main())
