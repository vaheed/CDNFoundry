# Production Compose service sets

Copy `.env.prod.example` to `.env.prod`, replace every secret, and keep that
file outside source control. Production uses four independently deployable
profiles:

```sh
make prod-pull
make prod-control
make prod-dns
make prod-telemetry
make prod-edge
```

Set `CDNF_RELEASE` to the exact 40-character Git commit SHA from a successful `main` CI run. Production Compose references `ghcr.io/vaheed/cdnfoundry-{core,web,edge-control,edge-runtime,edge-agent,mmdb-updater}:$CDNF_RELEASE`; it contains no application build definitions, source-mounted web/runtime code, `latest` tag, branch tag, or local-image fallback. Authenticate the host with a least-privilege GHCR token if the packages are not public, run `make prod-pull`, and verify the pulled image digests before starting a profile. The GitHub Actions image job publishes these immutable SHA tags only after PHP, Go, Compose, image-build, backend E2E, and scale jobs pass.

The DNS profile starts the shared MMDB updater and does not start PowerDNS until a valid database is present. DNSdist then waits for PowerDNS's native readiness check before resolving its private backend. This ordering is required on a new host and after loss of the rebuildable MMDB volume.

Run `make prod-migrate` explicitly before starting application code that needs
a new schema. No service performs implicit migrations.

Operator-tunable product policy is not stored in `.env.prod`. After migration, manage it through the administrator **Platform settings** page, `/api/admin/system/settings`, or `php artisan platform:settings:*`; see [Platform settings](platform-settings.md). PostgreSQL remains the runtime source of truth.

The profiles may run on separate hosts with host-specific Compose overrides.
Only DNSdist and the edge listeners should be public. PowerDNS, its PostgreSQL
database, the control database, Valkey, ClickHouse, and internal metrics remain
on private networks. Production does not include PowerAdmin or test origins.
See [Architecture](architecture.md) for the complete operator-readable DNS,
HTTP, enrollment, deployment, failure, and recovery flows.

The default edge and quarantine cells use separate listeners, cache volumes, temporary filesystems, memory/CPU/PID limits, and public service addresses. `EDGE_HTTP_BIND`/`EDGE_HTTPS_BIND` belong to the default shared cell; `EDGE_QUARANTINE_HTTP_BIND`/`EDGE_QUARANTINE_HTTPS_BIND` must route the quarantine pool's distinct addresses. Configure the same unique IPv4/IPv6 values in each participating edge cell's durable control-plane state before enabling the pool. A cell added later without addresses is excluded from that pool until its complete address pair and ready heartbeat are present; it cannot block existing participants. Exceptional dedicated pools use a host-specific Compose override that repeats the same bounded OpenResty service shape with unique bindings and volumes. Do not generate an automatic service, volume, or server block per domain.

## Process lifecycle

`core`, `horizon`, and `scheduler` are independent services. Restart one at a time; committed PostgreSQL state is not tied to process lifetime. Replace workers gracefully with `php artisan horizon:terminate`. Readiness checks use bounded PostgreSQL and Valkey timeouts. `/api/health` is process-only liveness; `/api/ready` checks required dependencies; `/api/admin/system/status` adds queue depth and oldest queued-job age per lane.

## Durable recovery set

Back up the control PostgreSQL database, `.env.prod` secrets (especially `APP_KEY`), signing/transport identities introduced by later phases, and external custom TLS private keys. PowerDNS runtime PostgreSQL is rebuildable once DNS reconciliation exists, though a backup can shorten recovery.

Do not place control PostgreSQL, Valkey, PowerDNS, ClickHouse, or internal metrics on a public network. Use host firewalls in addition to Compose internal networks.
