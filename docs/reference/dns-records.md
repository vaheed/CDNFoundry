---
title: DNS record reference
description: Reference supported DNS record types, modes, normalization, and zone-wide constraints.
---

# DNS record reference

::: warning Record modes are mutually constrained
Geo-DNS picks the DNS answer by the client's location. Proxied mode routes
traffic through the CDN. You cannot use both on one record. When you change a
record's type or mode, CDNFoundry checks the whole owner name and the whole
zone before it saves the change.
:::

Every record needs `type`, `name`, and `ttl`. `mode` is optional and defaults to
`dns_only`. TTL (time to live, how long resolvers cache an answer) is 30 through
2,147,483,647 seconds. CDNFoundry lower-cases names and converts them to IDNA
ASCII (Punycode). A name has at most 253 characters, and each label at most 63.
The name must stay inside the managed zone. Use `@` for the apex, which is the
zone name itself.

| Type | Content | Additional rules |
| --- | --- | --- |
| `A` | IPv4 address | May be DNS-only, Geo-DNS, or proxied |
| `AAAA` | IPv6 address | Stored in lower case. May be DNS-only, Geo-DNS, or proxied |
| `CNAME` | DNS target | May be DNS-only, Geo-DNS, or proxied |
| `MX` | DNS target | Priority 0 to 65,535 is required |
| `TXT` | 1 to 4,096 characters | A zone import joins quoted segments into one value |
| `NS` | DNS target | Only administrators can create or change NS records |
| `CAA` | flags, tag, value | Write `flags tag "value"`. Flags are 0 to 255. The tag is `issue`, `issuewild`, or `iodef` |
| `SRV` | DNS target | The name starts with `_service._tcp`, `_udp`, or `_sctp`. Priority, weight, and port are required |
| `PTR` | DNS target | Only in managed `in-addr.arpa` or `ip6.arpa` zones |

Priority, weight, and port are integers from 0 through 65,535. A target
without a dot is relative to the zone. A target with a dot is a full name.
`@` as a target means the apex. A single `.` stays the DNS root.

## Modes

| Mode | Meaning |
| --- | --- |
| `dns_only` | Publish the record content after normalization |
| `geo_dns` | Publish answers by country, then continent, then a default set |
| `proxied` | Publish the CDN pool addresses. The record stores `origin.host` as its content |

See [Geo-DNS](../guides/geo-dns.md) for how answers are chosen, panel and API
examples, resolver limits, and the GeoIP database (MMDB) behavior.

Only A, AAAA, and CNAME records can be proxied. A proxied hostname has exactly
one record and one origin. No other A, AAAA, or CNAME can exist at the same
name. A proxied name that is not the apex is published as a CNAME, and it must
be the only record at that name. A proxied apex can share its name with other
types such as MX and TXT.

## Zone-wide validation

- A duplicate record is rejected after normalization.
- A published CNAME cannot share a name with any other record.
- A Geo-DNS record must be the only record of its type at its name.
- Only SRV names can contain underscores.
- A record name cannot leave the managed zone.
- You do not set zone serials. CDNFoundry keeps them increasing.
