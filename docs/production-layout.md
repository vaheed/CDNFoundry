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

## Process lifecycle

`core`, `horizon`, and `scheduler` are independent services. Restart one at a time; committed PostgreSQL state is not tied to process lifetime. Replace workers gracefully with `php artisan horizon:terminate`. Readiness checks use bounded PostgreSQL and Valkey timeouts. `/api/health` is process-only liveness; `/api/ready` checks required dependencies; `/api/admin/system/status` adds queue depth and oldest queued-job age per lane.

## Durable recovery set

Back up the control PostgreSQL database, `.env.prod` secrets (especially `APP_KEY`), signing/transport identities introduced by later phases, and external custom TLS private keys. PowerDNS runtime PostgreSQL is rebuildable once DNS reconciliation exists, though a backup can shorten recovery.

Do not place control PostgreSQL, Valkey, PowerDNS, ClickHouse, or internal metrics on a public network. Use host firewalls in addition to Compose internal networks.
