---
title: Upgrade and rollback
description: Upgrade a running CDNFoundry fleet to a new signed release with Fleet or by hand, in a safe order with a backup first, and know when to roll back or restore.
---

# Upgrade and rollback

This page shows how to move a running fleet to a new release, check it, and go back if needed.

**You need:** a working fleet, the signed release manifest (or the exact image references) for the target release, and a verified off-host backup.

::: danger v1.2.1 is a zero-state release
There is no upgrade from 0.x, including 0.9.8 and 0.9.9. Deploy v1.2.1 from scratch with the [production quick start](production-quick-start.md). Do not point v1.2.1 images at a 0.x database or volumes. This page applies to updates within 1.2.x.
:::

::: danger Keep durable state
Never remove named volumes. Never regenerate encryption keys or certificate authority keys. Never run a destructive database refresh. Roll images back only when no incompatible migration ran.
:::

## Before the change

1. Pin the current and the target immutable image references. Verify the target [release evidence](../operations/software-supply-chain.md#verify-a-release).
2. Create an encrypted off-host control backup and verify that it restores.
3. Keep copies of `.env.prod`, `APP_KEY`, the artifact signing key, both private certificate authority keys, the listener identities, the Restic password, and any custom TLS material you hold outside CDNFoundry. Recovery needs all of them, not only PostgreSQL.
4. Validate the target Compose file and every generated node bundle.
5. Read the release's migrations. Changes to fields used by both versions must be expand-then-contract. Laravel and PowerDNS migrations are separate and explicit.

**Check:** you can name the backup snapshot and the previous release you would return to.

## Order

The order protects serving. Edge and DNS hosts keep serving the last valid runtime generation during the whole upgrade, so a failed step never stops traffic.

1. **Canary first.** Update one DNS or edge host. Check that DNS answers, the edge heartbeats, and HTTPS works.
2. **Control plane.** Apply the Laravel migration, then the PowerDNS migration. Then replace `core`, Horizon, and the Scheduler together.
3. **Remaining hosts**, one at a time. See [fleet rollouts](../operations/fleet-rollouts.md) for edge runtime images.
4. **Verify** health, readiness, the operations list, and the acceptance checks.

<DocTabs :labels="['Fleet CLI', 'Fleet web', 'Manual Compose']">
<template #tab-1>

Show the plan first. It changes nothing.

```sh
./scripts/cdnfoundry-fleet upgrade --manifest release-manifest.json --plan-only
```

The output lists the profile, whether the release holds an irreversible migration, each image and config that changes, and the steps for each node. Then apply it. Fleet asks you to confirm (or pass `--yes`):

```sh
./scripts/cdnfoundry-fleet upgrade --manifest release-manifest.json
```

Fleet verifies the manifest signature, then pre-pulls, uploads, validates, activates, and health-checks one node at a time. It upgrades DNS and edge nodes first, then control nodes (a standby first, then a planned switchover, then the old active node), then monitoring. Nodes whose images and configs do not change are not restarted. If the connection drops, run the same command again and it continues after the last finished step. Add `--key-file` to verify with a key other than the trusted one.

**Expected result:** every step ends `ok` and Fleet records the new release. If activation or the health gate fails, that node returns to its previous bundle, the roll stops, and the previous release stays on record.

</template>
<template #tab-2>

Open **Upgrade** in the local Fleet web page (`cdnfoundry-fleet web`, bound to `127.0.0.1`). Paste the release public key under **Trust this key** once, paste the signed manifest, and choose **Show plan**. Follow the page to apply the plan.

**Expected result:** the page shows the new running release.

</template>
<template #tab-3>

Without Fleet, follow the upgrade steps in [manual Docker Compose deployment](manual-compose.md#upgrade). In short: set the same `CDNF_RELEASE` on every host, run `config --quiet` and `pull`, run the `migrate` service on control, run `pdns-migrate` where `pdns-db` runs, then replace and verify one host at a time.

</template>
</DocTabs>

## Check it worked

- `/api/health` and `/api/ready` on the control host answer.
- The administrator health page and Horizon show no failed components or queues.
- Every edge reports a fresh heartbeat and an acknowledged runtime generation.
- `dig` against each DNS host returns your zone's SOA record.
- A proxied test domain answers over HTTPS.

## Rollback

Roll back only if the new release ran no incompatible (irreversible) migration.

<DocTabs :labels="['Fleet CLI', 'Fleet web', 'Manual Compose']">
<template #tab-1>

```sh
./scripts/cdnfoundry-fleet rollback
```

Fleet returns the fleet to the previous release and config. Repeat `--node NAME` to roll back only some nodes. If the running release holds an irreversible migration, Fleet refuses and tells you to restore from backup.

</template>
<template #tab-2>

Open **Rollback** and choose **Roll the whole fleet back**. Type `ROLLBACK` to confirm. If the release holds an irreversible migration, the page says to restore from backup instead.

</template>
<template #tab-3>

Set `CDNF_RELEASE` back to the previous value on each host and recreate the services, one host at a time.

</template>
</DocTabs>

If an incompatible migration ran, restore from the verified backup (see [backup and recovery](../operations/backup-and-recovery.md)) or ship a forward fix. Edge hosts keep serving the last valid runtime generation throughout.
