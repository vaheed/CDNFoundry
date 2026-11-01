---
title: Backup and recovery
description: Back up, preflight, restore, and reconstruct CDNFoundry control and derived state.
---

# Backup and recovery

The built-in Restic integration is optional at deployment time. Leave
`RESTIC_REPOSITORY`, `RESTIC_PASSWORD_FILE`, and the S3 credential values empty
to disable it; containers and migrations still start, the daily backup schedule
is skipped, API/CLI backup creation fails explicitly as unconfigured, and the
backup health component remains degraded until an operator records a working
recovery method.

::: danger Practice before an incident
A snapshot listing is not recovery proof. Restore to an isolated environment,
retain the encryption/signing and PKI keys, rebuild derived runtimes, and
record measured RPO and RTO.
:::

## Recovery set

A database snapshot alone is insufficient. Retain:

- encrypted off-host control PostgreSQL snapshots;
- `APP_KEY` and any `APP_PREVIOUS_KEYS`;
- `EDGE_ARTIFACT_SIGNING_KEY`;
- edge identity and server CA private keys;
- the external `EDGE_STATE_ENCRYPTION_KEY_FILE` contents and a tested way to
  provision that key and the bootstrap listener key into edge host tmpfs;
- control, runtime, and DNS API listener identities;
- `.env.prod` or an equivalent secret inventory;
- Restic repository password stored separately from the repository;
- backup-only object-store credentials;
- metrics and cell-control tokens;
- externally held custom TLS material.

Managed TLS private keys are encrypted in PostgreSQL and require the same
application key. PowerDNS data, edge snapshots, artifacts, and analytics can be
rebuilt, but they affect recovery time.

The edge agent keeps its enrollment identity and a last-valid serving snapshot
encrypted in `edge-agent-state`. With its external recovery key, it restores
the bounded `edge-runtime-memory` tmpfs generation after an edge restart even
when control is unavailable. If both the recovery snapshot and control plane
are unavailable, the edge cannot reconstruct certificate keys after reboot.

The control Restic job does not include `loki-data`. Loki operational logs are
derived, retention-bounded incident data and are not required to recover
serving. When policy requires preservation, use a separately tested crash-safe
volume snapshot or supported object storage; never imply the control backup
contains Loki.

## Backup creation

With a readable password file and non-empty repository configured, the daily
scheduler queues `cdnf:backups:create` at 01:30. Administrators can create one
through `POST /api/admin/backups` or:

```sh
docker compose --env-file .env.prod -f compose.prod.yml \
  exec core php artisan cdnf:backups:create --wait
```

The job streams `pg_dump --format=custom --no-owner --no-privileges` directly
into Restic as `control.pgdump`. It records the immutable snapshot ID, byte
count, output-manifest SHA-256, verification time, operation, and audit event.

Before the control dump, the same job exports the hourly and daily usage rollups
(`edge_hourly`, `dns_hourly`, `edge_daily`, `dns_daily`) from ClickHouse as
`rollups.jsonl` under the tag `cdnfoundry-rollups`, so usage survives the loss of
the telemetry host. Raw events are not backed up: they expire after seven days and
the platform serves without them. A rollup export failure is logged and never stops
the control backup. Restore preflight reports whether a rollup snapshot exists
(`usage_rollups: present` or `absent`).

Restore the rollups into empty tables with an account that may insert (the ingest
account):

```sh
docker compose --env-file .env.prod -f compose.prod.yml exec \
  -e CLICKHOUSE_RESTORE_USER=cdnf_ingest -e CLICKHOUSE_RESTORE_PASSWORD \
  core cdnf-backup-restore-rollups latest
```

The export ends with a completion line, and the restore uses only snapshots that
have it: `latest` skips an incomplete snapshot (a stream cut short) and takes the
newest complete one, and an explicit snapshot ID that is incomplete is refused. When
ClickHouse does not answer, no rollup snapshot is made at all and the control backup
still succeeds. The restore refuses to load over a table that already holds rows,
because the tables sum duplicate keys and loading again would double the totals. The
`dns_top_names` sketch is not backed up. Pruning and the weekly integrity check cover
both tags. `tests/e2e/rollup_backup.py` proves the round trip, the outage, the
incomplete snapshot, and the refusal against real ClickHouse and Restic.

Deleting a backup is also asynchronous and calls `restic forget`; a running
backup is preserved.

With a [standby control node](./control-plane-high-availability.md), the backup runs
on the active node and moves with the scheduler on promotion. Keep the same
off-host repository: a standby is a faster way back, not a replacement for a backup.

## Retention, integrity, and age

The repository does not grow without limit. Every day at 04:10 the scheduler runs
`cdnf:backups:maintain prune`, which keeps 14 daily, 8 weekly, and 6 monthly
snapshots (`BACKUP_KEEP_DAILY`, `BACKUP_KEEP_WEEKLY`, `BACKUP_KEEP_MONTHLY`) with
`restic forget --prune`, grouped by tag because each backup runs in a container with
its own hostname. Every Sunday at 05:00 `cdnf:backups:maintain check` runs
`restic check`. Both are operations; a failed check marks the **Backups** health
component degraded until a later check succeeds. The `ControlBackupStale` alert
fires when the newest verified backup is older than `operations.backup_stale_hours`
or the last integrity check failed.

The weekly GitHub workflow `restore-drill.yml` backs up to a disposable
S3-compatible store and restores into a clean PostgreSQL, so a broken backup path
fails there before an incident exposes it. It does not replace a restore rehearsal on
your own infrastructure with your own repository.

### S3-compatible repository setup

The production generator directly supports the S3 backend. A repository value
identifies storage; it is not itself encrypted or secret:

```dotenv
RESTIC_REPOSITORY=s3:https://object-storage.example/bucket/cdnfoundry-control
RESTIC_PASSWORD_FILE=/etc/cdnfoundry/secrets/restic-password
BACKUP_ACCESS_KEY_ID=prefix-scoped-access-key
BACKUP_SECRET_ACCESS_KEY=prefix-scoped-secret
BACKUP_DEFAULT_REGION=us-east-1
```

Use a dedicated bucket or prefix, deny access to unrelated objects, and retain
the Restic password separately from both the repository and S3 credentials.
Initialize a new repository once after the control dependencies, migration, and
core service are healthy; use `restic snapshots` instead when attaching an
existing repository. The exact secret-safe container commands are in the
[Production quick start](../deployment/production-quick-start.md).

Restic also supports SFTP, REST, Azure, Google Cloud Storage, and other
backends, but their provider variables, identity files, and mounts are not
present in the default production Compose contract. Add and qualify that wiring
explicitly instead of entering placeholder S3 credentials.

## Restore preflight

The administrator restore request requires:

- exact confirmation `RESTORE <backup UUID>`;
- the current administrator password;
- a succeeded backup with a snapshot ID.

It returns a `backup.restore` operation. The worker confirms that the snapshot
exists and leaves the operation in `running` with `preflight=passed`.

## Restore execution

Perform this only on an isolated replacement or explicit maintenance host:

1. install the exact compatible release and recovery secrets;
2. configure the target empty PostgreSQL instance and Restic access;
3. supply `BACKUP_RESTORE_ALLOWED=true` only to the one-off executor;
4. run `php artisan cdnf:backups:restore <operation UUID>`.

The command enters Laravel maintenance mode, streams the selected dump through
`pg_restore --clean --if-exists --no-owner --no-privileges --exit-on-error`,
runs migrations, writes a restore receipt, queues global DNS, edge, TLS, purge,
and usage reconciliation, then leaves maintenance mode on success. If restore
fails, it keeps maintenance mode active for inspection.

Do not run the restore command in a normal long-lived core container with
permanent restore permission.

## Post-restore qualification

Verify:

- restored user/domain counts and audit continuity;
- `/api/ready` and administrator components;
- Horizon and scheduler;
- global operation completion;
- system DNS identity and every authoritative cluster over UDP/TCP;
- edge identity, manifest sync, listener readiness, HTTP/HTTPS, and certificates;
- cache epoch and outstanding purges;
- ClickHouse independence and usage rebuild;
- a new verified backup.

Only then return traffic and record measured RPO/RTO. The repository's recovery
E2E uses a disposable fixture; it does not prove the operator's external
repository or clean-host timing.
