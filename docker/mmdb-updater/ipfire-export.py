#!/usr/bin/env python3
"""Verify IPFire's signature with a bundled public key, then stream all usable fields to the MMDB compiler."""
import json
import pathlib
import sys
import time

import location


def export(database: pathlib.Path, previous: pathlib.Path, verify_only: bool = False) -> None:
    db = location.Database(str(database))
    with open('/usr/local/share/ipfire-signing-key.pem') as public_key:
        if not db.verify(public_key):
            raise ValueError('IPFire signature verification failed')
    if db.created_at > time.time() + 86400:
        raise ValueError('IPFire database has a future timestamp')
    if previous.exists():
        old = location.Database(str(previous))
        if db.created_at < old.created_at:
            raise ValueError('IPFire database would roll back the cached version')
    if verify_only:
        return
    print(json.dumps({"created_at": db.created_at}))
    names = {entry.number: entry.name for entry in db.ases}
    countries = {}
    # MMDB already resolves overlapping prefixes; flattening here needlessly expands the routing tree.
    for index, network in enumerate(db.networks):
        if index >= 4000000:
            raise ValueError('IPFire network count exceeds 4000000')
        code = network.country_code or ''
        if code not in countries:
            country = db.get_country(code) if code else None
            countries[code] = (country.name or '', country.continent_code or '') if country else ('', '')
        name, continent = countries[code]
        row = {
            'network': str(network), 'country': code, 'country_name': name, 'continent': continent,
            'asn': network.asn or 0, 'organization': names.get(network.asn, '') or '',
            'anycast': network.has_flag(location.NETWORK_FLAG_ANYCAST),
            'satellite': network.has_flag(location.NETWORK_FLAG_SATELLITE_PROVIDER),
            'anonymous_proxy': network.has_flag(location.NETWORK_FLAG_ANONYMOUS_PROXY),
            'hostile': network.has_flag(location.NETWORK_FLAG_DROP),
        }
        print(json.dumps(row, separators=(',', ':')))


if __name__ == '__main__':
    try:
        export(pathlib.Path(sys.argv[1]), pathlib.Path(sys.argv[2]), "--verify-only" in sys.argv[3:])
    except (OSError, ValueError, RuntimeError) as error:
        print(str(error), file=sys.stderr)
        sys.exit(1)
