# Phase 3 qualification

Phase 3 implements bounded DNS-only geographic A and AAAA answers. Desired state is stored in PostgreSQL, compiled deterministically to server-generated PowerDNS Lua, deployed asynchronously, and protected by the existing last-valid deployment snapshot.

## Automated qualification

`make dev-test` passed 76 tests with 508 assertions using the required isolated in-memory SQLite configuration. Coverage includes authorization, idempotency, ISO country and continent vocabulary, country/continent/default priority, A/AAAA family validation, malicious-input bounds, duplicate rejection, unknown fallback, ordinary DNS isolation, deterministic compilation, deployment failure retention, and the OpenAPI contract.

Laravel Pint completed successfully. Development Compose validation passed. The production Compose check requires the operator-owned `.env.prod`, which was not available.

## Real runtime qualification

The persistent development migration was applied without refreshing data. PowerDNS 5.1.3 loaded `gpgsql` and `geoip`, opened the shared DB-IP MMDB, and accepted the generated Lua RRsets. Queries went through DNSdist, not directly to PowerDNS.

Observed answers for the disposable `browser-test.example.test` zone:

| Query classification | A answer | AAAA answer |
|---|---:|---:|
| Unknown/resolver fallback | `203.0.113.10` | `2001:db8::10` |
| EU continent (`80.67.169.12`) | `203.0.113.20` | `2001:db8::20` |
| IR country (`5.160.0.1`) | `203.0.113.30` | `2001:db8::30` |
| GB country (`81.2.69.1`) | `203.0.113.40` | not configured |
| Unknown IPv6 ECS (`2001:db8::1`) | `203.0.113.10` | `2001:db8::10` |

The local preview classifier reported `FR`/`EU` for `80.67.169.12` and selected the same continent target returned by PowerDNS.

## Remaining release acceptance

The manual browser workflow remains user-owned and was not run. Actual queries from three external geographic vantage points remain unchecked; local trusted-ECS tests cover equivalent classification paths but are not represented as external vantage points. Geo-DNS analytics label verification remains pending until that telemetry surface is present.
