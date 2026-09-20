#!/usr/bin/env python3
"""Qualify bounded staging control outage or a single PoP Docker restart.

This deliberately stops services on an explicitly supplied staging host. It never
removes volumes, changes application data or automates a browser. Control services
are restored in finally; edge restart waits for Docker and verified HTTPS recovery.
"""

import argparse
import hashlib
import ipaddress
import json
import os
from pathlib import Path
import re
import shlex
import subprocess
import tempfile
import time
from datetime import datetime, timezone

from staging_traffic import probe


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--mode', choices=['control-outage', 'edge-restart'], required=True)
    parser.add_argument('--host', type=ipaddress.ip_address, required=True)
    parser.add_argument('--ssh-user', required=True)
    parser.add_argument('--ssh-key', type=Path, required=True)
    parser.add_argument('--known-hosts', type=Path, required=True)
    parser.add_argument('--hostname', required=True)
    parser.add_argument('--edge', action='append', type=ipaddress.ip_address, required=True)
    parser.add_argument('--path', required=True)
    parser.add_argument('--expected-sha256', required=True)
    parser.add_argument('--report', type=Path, required=True)
    args = parser.parse_args()
    if not re.fullmatch(r'[a-z_][a-z0-9_-]*', args.ssh_user):
        parser.error('Use a plain SSH username')
    if len(args.edge) != 2 or len(set(args.edge)) != 2:
        parser.error('Exactly two distinct staging edges are required')
    if args.mode == 'edge-restart' and args.host not in args.edge:
        parser.error('The restart host must be one of the edges')
    if args.mode == 'control-outage' and args.host in args.edge:
        parser.error('Control outage requires a separate control host')
    if not re.fullmatch(r'[a-zA-Z0-9.-]+', args.hostname) or not args.path.startswith('/') or any(c in args.path for c in '\r\n?#'):
        parser.error('Use a hostname and a static-resource path without query or fragment')
    if not re.fullmatch(r'[a-f0-9]{64}', args.expected_sha256):
        parser.error('Expected content requires a SHA-256 digest')
    ssh = ['ssh', '-i', str(args.ssh_key), '-o', 'IdentitiesOnly=yes', '-o', 'BatchMode=yes',
           '-o', 'ConnectTimeout=10', '-o', 'StrictHostKeyChecking=yes',
           '-o', 'UserKnownHostsFile='+str(args.known_hosts), args.ssh_user+'@'+str(args.host)]
    compose = 'cd /opt/cdnfoundry && docker compose --env-file .env.prod '
    rows = []
    fingerprints = {}

    def remote(command, timeout=90):
        result = subprocess.run(ssh+['sudo -n sh -c '+shlex.quote(command)],
                                capture_output=True, text=True, timeout=timeout)
        if result.returncode:
            raise RuntimeError('Remote staging command failed; exit '+str(result.returncode))
        return result.stdout

    def sample(edge, stage):
        result = probe(args.hostname, str(edge), args.path, 'https')
        assert result['status'] == 200 and result['body_sha256'] == args.expected_sha256
        if str(edge) in fingerprints:
            assert result['certificate_sha256'] == fingerprints[str(edge)]
        fingerprints[str(edge)] = result['certificate_sha256']
        rows.append({'edge': str(edge), 'stage': stage, **result})

    result = {'mode': args.mode, 'outcome': 'failed', 'samples': rows}
    try:
        for edge in args.edge:
            sample(edge, 'before')
        volumes_before = remote("docker volume ls --format '{{.Name}}'")
        if args.mode == 'control-outage':
            services = 'core horizon scheduler'
            running = set(remote(compose+'ps --services --status running').splitlines())
            assert set(services.split()) <= running, 'Control services must initially be running'
            try:
                remote(compose+'stop '+services, timeout=180)
                assert not set(services.split()) & set(remote(compose+'ps --services --status running').splitlines())
                for _ in range(3):
                    for edge in args.edge:
                        sample(edge, 'control-stopped')
                    time.sleep(2)
            finally:
                remote(compose+'up -d --no-deps '+services)
            for _ in range(18):
                running = set(remote(compose+'ps --services --status running').splitlines())
                if set(services.split()) <= running:
                    break
                time.sleep(3)
            else:
                raise RuntimeError('Control services did not return to running')
        else:
            process = subprocess.Popen(ssh+['sudo -n systemctl restart docker'],
                                       stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
            try:
                other = next(edge for edge in args.edge if edge != args.host)
                for _ in range(3):
                    sample(other, 'peer-restarting')
                    time.sleep(2)
            finally:
                if process.wait(timeout=90) != 0:
                    raise RuntimeError('Docker restart failed')
            for attempt in range(30):
                try:
                    sample(args.host, 'recovered')
                    break
                except (OSError, AssertionError):
                    if attempt == 29:
                        raise
                    time.sleep(3)
        volumes_after = remote("docker volume ls --format '{{.Name}}'")
        assert sorted(volumes_before.splitlines()) == sorted(volumes_after.splitlines())
        result['named_volumes_preserved'] = True
        result['volume_inventory_sha256'] = hashlib.sha256('\n'.join(sorted(volumes_after.splitlines())).encode()).hexdigest()
        for edge in args.edge:
            sample(edge, 'after')
        result['outcome'] = 'passed'
    except Exception as error:
        result['error_type'] = type(error).__name__
        raise
    finally:
        result['recorded_at'] = datetime.now(timezone.utc).isoformat()
        args.report.parent.mkdir(parents=True, exist_ok=True)
        descriptor, candidate = tempfile.mkstemp(prefix='.continuity-', dir=args.report.parent)
        with os.fdopen(descriptor, 'w') as stream:
            json.dump(result, stream, indent=2)
            stream.write('\n')
        os.replace(candidate, args.report)
        print(args.mode, result['outcome'], flush=True)


if __name__ == '__main__':
    main()
