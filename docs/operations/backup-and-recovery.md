---
title: Backup and recovery
description: Back up, preflight, restore, and reconstruct CDNFoundry control and derived state.
---

# Backup and recovery

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
- control, runtime, and DNS API listener identities;
- `.env.prod` or an equivalent secret inventory;
- Restic repository password stored separately from the repository;
- backup-only object-store credentials;
- metrics and cell-control tokens;
- externally held custom TLS material.

Managed TLS private keys are encrypted in PostgreSQL and require the same
application key. PowerDNS data, edge snapshots, artifacts, and analytics can be
rebuilt, but they affect recovery time.

## Backup creation

The daily scheduler queues `backups:create` at 01:30. Administrators can create
one through `POST /api/admin/backups` or:

```sh
docker compose --env-file .env.prod -f compose.prod.yml \
  exec core php artisan backups:create --wait
```

The job streams `pg_dump --format=custom --no-owner --no-privileges` directly
into Restic as `control.pgdump`. It records the immutable snapshot ID, byte
count, output-manifest SHA-256, verification time, operation, and audit event.

Deleting a backup is also asynchronous and calls `restic forget`; a running
backup is preserved.

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
4. run `php artisan backups:restore <operation UUID>`.

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
