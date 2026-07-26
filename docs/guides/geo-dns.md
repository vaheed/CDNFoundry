---
title: Geo-DNS
description: Configure deterministic country, continent, and default DNS answers.
---

# Geo-DNS

Geo-DNS is a record mode implemented by PowerDNS Lua records. Supported answer
types are `A`, `AAAA`, `CNAME`, `MX`, `TXT`, `NS`, `SRV`, and `PTR`.

Each configuration contains:

- up to 64 country codes;
- up to seven continent codes;
- up to eight answer targets per country, continent, or default set;
- record-specific priority, weight, and port values where applicable.

Selection order is country, then continent, then default. Unknown or invalid
geography skips geographic matches and uses the default set. It never fails a
query merely because classification is unavailable.

## Configure

Choose `geo_dns` in the DNS record form and add default targets before country
or continent overrides. The API uses:

- `GET /api/domains/{domain}/dns/records/{record}/geo`
- `PUT /api/domains/{domain}/dns/records/{record}/geo`
- `POST /api/domains/{domain}/dns/records/{record}/geo/preview`

Preview accepts an IPv4 or IPv6 address and returns the classified country,
continent, selected rule, and answers. It is a control-plane preview, not proof
of an external resolver path.

## Runtime data

PowerDNS reads `GeoLite2-City.mmdb` from the shared MMDB volume in memory-mapped
mode. The updater downloads a candidate, validates it with `mmdblookup`, checks
an optional SHA-256 value, and renames it atomically. On failure it preserves an
existing valid file.

PowerDNS also processes EDNS Client Subnet. Without trusted ECS, the resolver's
address may determine geography; document this limitation for users.
