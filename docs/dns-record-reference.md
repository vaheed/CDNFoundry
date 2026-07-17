# DNS record reference

CDNFoundry manages authoritative desired state for `A`, `AAAA`, `CNAME`, `MX`, `TXT`, `NS`, `CAA`, `SRV`, and reverse-zone `PTR` records. Every record is DNS-only in Phase 2.

Owners may be `@`, relative to the managed zone, or fully qualified inside it. Names are normalized to lowercase ASCII/Punycode. TTL values must be between 30 and 2,147,483,647 seconds.

| Type | Content | Additional fields |
| --- | --- | --- |
| `A` | IPv4 address | None |
| `AAAA` | IPv6 address | None |
| `CNAME` | DNS target | Cannot coexist with another type at the same owner |
| `MX` | DNS target | `priority`, 0–65535 |
| `TXT` | Non-empty text, up to 4,096 bytes | None |
| `NS` | DNS target | None |
| `CAA` | Flags, `issue`, `issuewild`, or `iodef`, and a quoted value | None |
| `SRV` | DNS target | `priority`, `weight`, and `port`, each 0–65535; owner starts `_service._protocol` |
| `PTR` | DNS target | Managed reverse zones only |

Logical duplicates are rejected. Bulk mutations and imports validate the complete resulting zone before committing, so an invalid change does not partially alter desired state. A successful bulk mutation or import increments the zone revision once.

A domain is bounded to 10,000 desired records. One bulk request may contain at most 5,000 create, update, or delete actions.

Runtime reconciliation is asynchronous. Mutating an active zone queues deployment of the latest desired revision; invalid runtime output never replaces the previous valid zone.
