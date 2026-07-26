# Registrar delegation and glue

Use the exact nameserver set returned by `GET /api/nameservers`. Do not add parking, forwarding, or registrar-default nameservers; automated verification requires the observed NS RRset to match the platform set exactly.

When a platform nameserver is inside the delegated parent zone, register both IPv4 and IPv6 glue at the registrar before changing delegation. Glue is registrar-side parent data, not a substitute for authoritative A and AAAA records.

Delegation updates depend on registrar and registry TTLs. A failed verification does not change the domain lifecycle or active DNS state. Wait for propagation, confirm the public NS response through an independent resolver, and retry the failed operation or request verification again.

Common failure causes include an extra registrar nameserver, a missing trailing delegation update, stale parent glue, Punycode mismatch, or querying a caching resolver before the previous NS TTL expires.
