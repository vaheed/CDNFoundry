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

Set `CDNF_RELEASE` to the exact 40-character Git commit SHA from a successful
`main` CI run (recommended) or an exact `vMAJOR.MINOR.PATCH` release tag.
Production Compose references
`ghcr.io/vaheed/cdnfoundry-{core,web,edge-control,edge-runtime,edge-agent,mmdb-updater}:$CDNF_RELEASE`;
it contains no application build definitions, source-mounted web/runtime code,
or local-image fallback. Authenticate the host with a least-privilege GHCR token
if the packages are not public, run `make prod-pull`, and verify the pulled image
digests before starting a profile. After all required checks pass, the GitHub
Actions image job publishes every successful `main` commit under its immutable
SHA and `latest`. A `vMAJOR.MINOR.PATCH` Git tag also publishes `vMAJOR.MINOR.PATCH`,
`MAJOR.MINOR.PATCH`, `MAJOR.MINOR`, `MAJOR`, and `latest`; prereleases publish only
their exact `v` and non-`v` aliases. `latest`, major, and minor are mutable
discovery channels and must not be used as production deployment pins.

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

`core`, `horizon`, and `scheduler` are independent services. Restart one at a time; committed PostgreSQL state is not tied to process lifetime. Replace workers gracefully with `php artisan horizon:terminate`. Readiness checks use bounded PostgreSQL and Valkey timeouts. `/api/health` is process-only liveness; `/api/ready` checks required dependencies; `/api/admin/system/components` adds dependency state plus queue depth and oldest queued-job age per lane.

Production Compose gives control workers bounded stop windows and sends
`SIGQUIT` to Nginx/OpenResty listeners, allowing existing requests to finish
before container replacement. Do not use forced removal for routine rollout.
The edge agent and runtime still enforce target activation and acknowledgement
before source drain; a graceful process stop is not a substitute for that
placement protocol.

## Durable recovery set

Back up the control PostgreSQL database through the encrypted off-host Restic workflow, plus `.env.prod` secrets (especially `APP_KEY`), the Restic password/decryption material stored separately, artifact-signing key, edge identity CA, listener identities, and externally held custom TLS keys. Managed certificate keys are encrypted in control PostgreSQL and are unrecoverable without the same `APP_KEY`. PowerDNS runtime PostgreSQL is derived and rebuildable from desired state, though a backup can shorten recovery. See [Operations and recovery](operations/operations-and-recovery.md). A clean-host RPO/RTO claim remains deferred until the production qualification is recorded.

Do not place control PostgreSQL, Valkey, PowerDNS, ClickHouse, or internal metrics on a public network. Use host firewalls in addition to Compose internal networks.
