# cdnf-dns-runtime

## Purpose
Implement authoritative DNS desired state and PowerDNS reconciliation.
## When to use
For zones, records, Geo-DNS, edge-selection DNS, DNSdist, or PowerDNS work.
## Required inputs
Zone ownership, supported types/modes, limits, serial rules, targets, and query qualification.
## Files normally touched
DNS models/API/jobs, PowerDNS/DNSdist config, tests and runbooks.
## Procedure
Canonicalize names/Punycode; validate boundaries/types/values/TTL/CNAME/duplicates and A/AAAA parity; transact bulk changes into one revision/serial; reconcile latest state transactionally; preserve active zone; verify through DNSdist with real `dig`; test rebuild/outage.
## Validation commands
Feature tests, Compose validation, IPv4/IPv6 `dig`, restart/outage/rebuild tests.
## Definition of done
DNSdist alone is public; valid state serves independently of Laravel; invalid state is isolated.
## Stop conditions
Stop if PowerDNS becomes source of truth/public or runtime DNS requires Laravel/network GeoIP.
## Forbidden shortcuts
No product edits through PowerAdmin, delete-before-replace, per-record jobs, serial reuse, or mocked-only qualification.
## Expected completion summary
Types, revision/serial behavior, targets, real query evidence, and limits.
