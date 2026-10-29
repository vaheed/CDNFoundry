---
name: cdnf-dns-runtime
description: Work on CDNFoundry authoritative DNS: records, zones, Geo-DNS, the platform zone, PowerDNS reconciliation, and DNSdist. Use for any DNS model, render, or runtime change.
---

# cdnf-dns-runtime

## Purpose
Keep authoritative DNS correct, monotonic, and independent of Laravel at query time.

## When to use
Changes to records, zones, Geo-DNS, nameserver identity, delegation, the platform zone, PowerDNS, or DNSdist.

## Required inputs
Zone ownership, record types and modes (`dns_only`, `proxied`, `geo_dns`), limits, the serial rule, and the targets (`DnsCluster`).

## Files normally touched
`core/app/Http/Controllers/DnsRecordController.php`, `DnsZoneController.php`, `core/app/Support/DnsRecordData.php`, `DnsZoneValidator.php`, `PowerDnsZone.php`, `PlatformDnsZone.php`, `GeoDnsCompiler.php`, `PowerDnsClient.php`, `BindZone.php`, `core/app/Jobs/ReconcileDnsZone.php`, `ReconcilePlatformDnsIdentity.php`, `docker/pdns/pdns.conf`, `docker/dnsdist/dnsdist.conf`, `docs/guides/dns.md`, `docs/reference/dns-records.md`.

## Procedure
1. Normalize names with `DomainName::normalize` (Punycode, trailing dot). Validate types, values, TTLs, CNAME exclusivity, duplicates, and A/AAAA parity in `DnsRecordData` and `DnsZoneValidator`.
2. Write all record changes for one request in one transaction with one revision.
3. Rendering must never throw for one record and block the whole zone. Today `PowerDnsZone::render()` throws for a proxied record without an active pool (roadmap H4).
4. The serial must change exactly when the content changes. The platform zone serial currently does not follow its content (roadmap M3).
5. DNSdist is the only public endpoint. PowerDNS and its API stay private. Geo-DNS uses the local MMDB only.
6. Verify with real queries through DNSdist over UDP and TCP, IPv4 and IPv6.

## Validation commands
- Backend: `make dev-test`, or one class with `docker compose -f compose.dev.yml -f compose.test.yml run --rm --no-deps core php artisan test --compact --filter <Class>` (the test override forces in-memory SQLite; never run tests against PostgreSQL).
- Style: `docker compose -f compose.dev.yml -f compose.test.yml run --rm --no-deps core vendor/bin/pint --test`.
- Feature: `DnsRecordApiTest`, `DnsReconciliationTest`, `GeoDnsTest`, `DnsZoneImportExportTest`.
- Runtime: `python3 tests/e2e/phase2_dns.py`, `python3 tests/e2e/phase3_geo_dns.py`, and `dig @127.0.0.1 -p <dnsdist port> <name> <type> +tcp`.

## Definition of done
Valid state serves without Laravel, invalid state is rejected before activation, and the serial is monotonic.

## Stop conditions
Stop if PowerDNS would become the source of truth, would be exposed publicly, or would need Laravel or a network GeoIP lookup at query time.

## Forbidden shortcuts
No product edits through PowerAdmin (they are drift), no serial reuse, no per-record jobs, and no mocked-only DNS qualification.

## Expected completion summary
Record types affected, revision and serial behavior, targets, real `dig` evidence, and limits.
