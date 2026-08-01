---
title: DNS record reference
description: Reference supported DNS record types, modes, normalization, and zone-wide constraints.
---

# DNS record reference

::: warning Record modes are mutually constrained
Geo-DNS is authoritative answer selection; proxied mode is CDN traffic routing.
They cannot be combined on one record. Changing type or mode revalidates the
entire owner and zone before desired state changes.
:::

Every record requires `type`, `name`, `ttl`, and a mode. TTL is 30 through
2,147,483,647 seconds. Names are lower-cased, converted to IDNA ASCII, limited
to 253 characters and 63 per label, and must remain inside the managed zone.
Use `@` for the apex.

| Type | Content | Additional rules |
| --- | --- | --- |
| `A` | IPv4 address | May be DNS-only, Geo-DNS, or proxied |
| `AAAA` | IPv6 address | Lower-cased; may be DNS-only, Geo-DNS, or proxied |
| `CNAME` | DNS target | May be DNS-only, Geo-DNS, or proxied |
| `MX` | DNS target | Priority 0–65,535 required |
| `TXT` | 1–4,096 characters | Import joins quoted segments |
| `NS` | DNS target | Apex delegation changes require administrator permission |
| `CAA` | flags, `issue`/`issuewild`/`iodef`, value | Flags 0–255 |
| `SRV` | DNS target | Owner begins `_service._tcp`, `_udp`, or `_sctp`; priority, weight, port required |
| `PTR` | DNS target | Only in managed `in-addr.arpa` or `ip6.arpa` zones |

Priority, weight, and port are integers from 0 through 65,535. DNS names without
a dot are relative to the zone; `@` targets the apex; `.` is preserved as the
DNS root.

## Modes

| Mode | Meaning |
| --- | --- |
| `dns_only` | Publish normalized content |
| `geo_dns` | Publish country, continent, then default answer sets |
| `proxied` | Publish pool routing and use `origin.host` as stored content |

See [Geo-DNS](../guides/geo-dns.md) for the selection flow, panel and API
examples, ECS/resolver limitations, MMDB behavior, and production qualification.

Only A, AAAA, and CNAME may be proxied. One proxied hostname has exactly one
record and origin. It cannot coexist with another A, AAAA, or CNAME at the same
owner. A non-apex proxied hostname becomes a CNAME and must be the only record
at that owner. A proxied apex may coexist with non-routing types such as MX and
TXT.

## Zone-wide validation

- Logical duplicates are rejected after normalization.
- A published CNAME cannot coexist with another record at the same owner.
- One Geo-DNS record must be the only record of its type at an owner.
- Underscores are limited to valid SRV owners.
- Record owners cannot escape the managed zone.
- PowerDNS serials remain monotonic and are not supplied by customer records.
