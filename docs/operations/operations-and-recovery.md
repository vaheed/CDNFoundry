# Operations, recovery, and upgrade runbook

## Operational state

Administrators use `GET /api/admin/system/health` for the overall state and
`GET /api/admin/system/components` for component detail and bounded queue-lane
depth/age. States are `healthy`, `degraded`, and `unavailable`. ClickHouse or
Vector failure degrades observability but does not make DNS or HTTP serving
unavailable. The administrator dashboard summarizes the same checks.

Prometheus scrapes `GET /metrics` across the private `control` network with the
bearer token stored in the mode-0600 file named by `METRICS_TOKEN_FILE`. Do not
publish this endpoint or token. Alertmanager receives control-plane scrape,
component, queue, failed-operation, certificate-expiry, clock, and telemetry
alerts. The private Node Exporter provides NTP synchronization and clock offset;
the control-plane health summary compares the maximum absolute offset with the
PostgreSQL-backed **Clock drift warning** setting. Production qualification must
still rehearse a real offset on a disposable host and confirm alert resolution.

Inspect failed durable operations before raw queue failures. The failed-jobs API
returns only job name, lane, first exception line, and timestamps; it never
returns serialized payloads. Retry only after correcting the cause. Deleting a
failed row is audited and does not repair desired state. Run the appropriate
coalesced reconciliation afterward:

```text
POST /api/admin/reconcile/dns
POST /api/admin/reconcile/edges
POST /api/admin/reconcile/tls
POST /api/admin/reconcile/purges
POST /api/admin/reconcile/usage
```

Every call needs administrator authentication and an `Idempotency-Key`. Global
jobs page through bounded batches on `bulk_maintenance`; per-domain runtime work
uses its existing lane and unique job. Audit pruning runs daily in batches and
uses the PostgreSQL-backed retention setting.

## Monitoring and alert reference

| Signal | Default alert condition | First response |
|---|---|---|
| Control scrape | unavailable for 2 minutes | Check private routing, token file permissions, then control readiness. |
| Component health | any component degraded for 5 minutes | Inspect the named component and preserve unrelated serving paths. |
| Queue lane | depth over 1,000 or oldest item over 15 minutes for 5 minutes | Stop bulk producers, inspect Horizon and failed operations, then reconcile. |
| Failed operation | any durable failed operation for 10 minutes | Correct its stable error, retry or reconcile, and retain audit evidence. |
| Certificate expiry | an active certificate enters the configured alert window for 5 minutes | Preserve the active certificate, repair DNS-01/CA access, and reconcile TLS. |
| DNSdist | private metrics scrape unavailable, or any authoritative backend down, for 2 minutes | Preserve healthy targets, inspect the named backend and DNS database, then verify UDP and TCP answers through DNSdist. |
| PowerDNS | private metrics scrape unavailable for 2 minutes | Keep DNSdist on healthy backends, repair the private PowerDNS/API/database path, then reconcile desired zones. |
| Edge runtime | stale heartbeat, listener/cell/pool failure, placement/configuration drift, emergency mode, or resource use at 80% of a reported limit | Isolate the affected edge/cell/pool, retain last-valid artifacts, add capacity or reconcile only the affected scope. |
| MMDB | file missing/unreadable, empty, or older than 48 hours | Retain the last valid database, repair the updater, and verify IPv4 and IPv6 lookups before activation. |
| Host clock | unsynchronized or absolute offset over 5 seconds for 2 minutes | Drain the affected host if signatures/certificates may be unsafe, repair NTP, and confirm resolution. |
| Vector | scrape loss, dropped events, delivery errors, or buffer above 80% | Keep serving, preserve the partial-data interval, and repair the sink/buffer. |

Alert labels contain only bounded infrastructure identifiers; customer domains,
request paths, credentials, and serialized jobs are not alert labels. Tune the
database health threshold and the matching Prometheus rule together, then run
`promtool test rules /etc/prometheus/alerts.test.yml` before deployment.
DNSdist exposes only read-only statistics on port 8083 of the private DNS
network; its configuration-changing API remains authenticated and disabled for
Prometheus. PowerDNS metrics and both database/API paths remain private.

## Failure routing

- Control PostgreSQL: stop mutations, keep DNS/edges serving their last valid
  state, restore the complete encrypted backup on a replacement host, run only
  forward migrations, then reconcile DNS, edges, TLS, purges, and usage.
- Valkey/Horizon: serving continues. Restore Valkey, restart Horizon, inspect
  failed operations, and run all reconciliation endpoints; queue contents are
  not part of the minimum recovery set.
- DNS cluster: disable the unhealthy target, keep healthy clusters active,
  repair its private database/API, test it, enable it, and reconcile DNS.
- Edge/cell: drain when reachable; otherwise withdraw only the affected pool or
  edge addresses. Add a replacement edge, wait for full snapshot acknowledgement,
  then restore routing. Never remove the last active source before target ack.
- Certificate: retain the last valid certificate, correct DNS-01 or CA failure,
  reconcile TLS, and confirm edge acknowledgement before expiry.
- ClickHouse/Vector: follow [ClickHouse outage](../clickhouse-outage-runbook.md).
  Serving must continue and loss intervals must be recorded rather than guessed.
- MMDB: retain the last checksum-validated file. Repair provider access and
  confirm both IPv4 and IPv6 lookup before activation.

## Backup and clean-host restore

The recovery set is the encrypted control PostgreSQL backup, `APP_KEY`, artifact
signing key, edge identity CA, listener identities, typed environment files,
metrics token, and externally held custom TLS material. Store backup encryption
material separately. PowerDNS, Valkey queue state, edge snapshots, and
ClickHouse are not substitutes for control PostgreSQL.

Production backups stream `pg_dump` custom-format output directly into the
configured S3-compatible Restic repository; no unbounded local dump is staged.
Initialize the repository once with its separately stored password, then use
the backup API or `php artisan backups:create`. The API records snapshot ID,
size, verification time, bounded failure, operation, and audit evidence. S3
credentials should be restricted to the dedicated repository prefix.

Restore requires the exact `RESTORE <backup UUID>` value and current
administrator password. It queues a repository preflight and returns an
operation. After the preflight succeeds, stop normal control-plane workers and
run the returned `php artisan backups:restore <operation UUID>` command in a
one-off maintenance container with `BACKUP_RESTORE_ALLOWED=true`. A failed
restore deliberately leaves maintenance mode active. A successful restore runs
forward migrations, records a receipt, queues all reconciliations, and leaves
maintenance mode.

Do not claim recovery merely because a snapshot exists. Verify Restic packs and
restoreability, and record the immutable snapshot identifier. Restore on an empty replacement host,
start private dependencies, run `make prod-migrate` and `make prod-pdns-migrate`,
then start control, DNS, telemetry, and a fresh edge. Run all reconciliations and
verify DNSdist UDP/TCP plus edge IPv4/IPv6 HTTP/HTTPS. Record backup cutoff,
first successful DNS/HTTP response, measured RPO, and measured RTO.

Backup files are never downloadable through Laravel. The local Restic repository
in development exists only for automated qualification and is not an off-host
production backup.

Repeat the agent-owned portions with `make dev-phase8-e2e`,
`make dev-phase8-recovery-e2e`, `make dev-phase8-upgrade-e2e`, and
`make dev-phase8-throughput-e2e`. Use `make dev-phase8-mmdb-e2e` for the
last-valid MMDB provider-outage rehearsal. The recovery job creates only disposable
tmpfs PostgreSQL/object-host containers and must never remove named volumes.
Its measured times are local evidence; record separate end-to-end times on the
approved fresh replacement host.

## Canary upgrade and rollback

Use an immutable commit-SHA release. Back up first, apply only expand-compatible
migrations, upgrade one control worker, one DNS target, and one edge agent/cell.
Artifacts already carry schema version and minimum/maximum agent versions.
Stop rollout on an unhealthy component, increased error rate, configuration
rejection, stale revision, or queue-age alert. Roll application containers back
to the prior immutable SHA without restoring the database. Contract migrations
are admitted only after the rollback window closes and a separate release proves
the previous application is no longer required.

Use `make prod-pull` and the host-role commands in
[Production Compose service sets](../production-layout.md). Record both image
digests, schema migration set, artifact schema/agent bounds, canary operation
IDs, stop thresholds, and rollback result. Replace Horizon workers gracefully
with `php artisan horizon:terminate`; do not stop an edge during candidate
activation. Roll back the immutable application images only, never PostgreSQL.

## Secret and identity rotation

- API tokens: create a replacement, verify its narrowly scoped client, revoke
  the old token, then confirm the old token is rejected. Never log either value.
- Edge mTLS identity: use the administrator rotate-identity workflow, enroll the
  replacement once, verify heartbeat/artifact acknowledgement, and confirm the
  revoked serial cannot authenticate.
- Listener/server certificates and CAs: follow
  [Production certificate rotation](production-certificates.md), retaining both
  trust anchors through the overlap window.
- Restic/S3 credentials: verify a backup with the new repository credential,
  revoke the old S3 key, and retain the Restic repository password in a separate
  recovery system. Changing an S3 key does not re-encrypt existing Restic packs.
- `APP_KEY` and artifact-signing/identity-CA private keys are recovery roots, not
  routine in-place rotations. A change requires an explicit data/key migration
  and fleet trust transition; never silently replace one during deployment.
- PostgreSQL, Valkey, ClickHouse, metrics, and ACME credentials: install the new
  value on the private dependency and consumers, restart one bounded component
  at a time, verify health, then revoke the old value.

## Capacity planning

Treat the published 500,000-domain/1,000,000-record run as correctness evidence,
not a universal capacity promise. Before production, repeat `make dev-scale-e2e`
on the intended control/DNS hardware and record CPU model/count, memory, storage,
network, image digests, dataset, latency, throughput, errors, and saturation.
Measure per-edge HTTP/HTTPS throughput separately with cache HIT/MISS, TLS,
IPv4/IPv6, request-size, connection, origin, and telemetry mixes.

Add capacity when a sustained queue lane approaches its alert threshold, DNS
latency/error budget is consumed, or an edge reports CPU, memory, file-descriptor,
connection, cache, or temporary-storage pressure. Scale by adding bounded
workers, DNSdist/PowerDNS capacity, ClickHouse capacity, or edge nodes/cells.
Adding an edge must not create a per-domain runtime or force unrelated domain
revisions; verify this with placement/reconciliation metrics after enrollment.
