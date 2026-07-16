# Production Compose service sets

Copy `.env.prod.example` to `.env.prod`, replace every secret, and keep that
file outside source control. Production uses four independently deployable
profiles:

```sh
make prod-control
make prod-dns
make prod-telemetry
make prod-edge
```

Run `make prod-migrate` explicitly before starting application code that needs
a new schema. No service performs implicit migrations.

The profiles may run on separate hosts with host-specific Compose overrides.
Only DNSdist and the edge listeners should be public. PowerDNS, its PostgreSQL
database, the control database, Valkey, ClickHouse, and internal metrics remain
on private networks. Production does not include PowerAdmin or test origins.

