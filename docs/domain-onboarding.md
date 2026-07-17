# Domain onboarding

Domains are added without an origin. Creating one stores DNS desired state only and begins in `pending_verification`.

1. Read `GET /api/nameservers` and show every required hostname with its IPv4 and IPv6 glue.
2. Create the domain with `POST /api/domains` using its registrable name. Unicode input is normalized to canonical Punycode.
3. Configure the registrar delegation exactly as shown.
4. Request `POST /api/domains/{domain}/verify-nameservers`. This returns `202` and an operation identifier; polling the operation shows success or the observed-delegation failure.
5. After verification, request `POST /api/domains/{domain}/activate`. Activation is asynchronous and queues authoritative reconciliation.
6. Inspect `GET /api/domains/{domain}/status` or `/dns/deployment` until every target reports the desired revision.

Administrators may force verification only when registrar behavior has been independently confirmed. The action is audited and cannot revive a deprovisioning domain.

Disabling a domain preserves its desired records. Deleting starts a seven-day delayed deprovisioning period and creates a tombstone for every DNS cluster. The canonical name cannot be reclaimed while its runtime deletion or cooldown is incomplete.
