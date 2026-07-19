# Security rules

Security rules are desired domain state and deploy inside the normal signed,
cell-specific edge artifact. A request-time rule decision never calls Laravel,
Valkey, PostgreSQL, or ClickHouse.

Each rule has `match_type` (`ip`, `cidr`, `country`, or `continent`), `value`,
`action` (`allow` or `block`), integer `priority`, enabled state, and an optional
250-character note. IPv4 and IPv6 values are normalized before storage. Country
and continent codes use the same vocabulary as Geo-DNS.

Enabled rules are evaluated by ascending priority and then stable database ID.
The first match wins; no match allows the request. Unknown geography matches no
geographic rule, while IP and CIDR rules continue to work when the MMDB is
unavailable. An explicit allow therefore overrides only later rules.

The API accepts at most 500 rules per import and 1,000 rules per domain. It
normalizes and validates every row before one transaction increments one desired
revision. `replace_existing` replaces only after the complete candidate is
valid. Deployment uses the normal checksum/activate/acknowledge pipeline; an
invalid candidate cannot replace the last valid runtime.

In Filament open a domain, then **Security rules**. Create or edit individual
rules in the table. **Import rules** shows a bounded preview before committing.
Use low priority numbers for intentional exceptions, keep notes operational,
and avoid broad allow CIDRs unless they are required.
