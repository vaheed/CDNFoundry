---
title: Upgrade and rollback
description: Upgrade CDNFoundry with explicit migrations, canaries, compatibility checks, and safe rollback.
---

# Upgrade and rollback

::: danger v1.2.1 is a zero-state release
There is no upgrade from 0.x (including 0.9.8 and 0.9.9). Deploy v1.2.1 from scratch
with the [production quick start](production-quick-start.md). Do not point v1.2.1
images at a 0.x database or volumes. This page applies to updates **within 1.2.x**.
:::

::: danger Preserve durable state
Never remove named volumes, regenerate encryption or CA keys, or run a destructive
database refresh. Roll back images only within the proven schema compatibility envelope.
:::

## Before the change

1. Pin the current and target immutable image references and verify the target
   [release evidence](../operations/software-supply-chain.md#verify-a-release).
2. Create and verify an encrypted off-host control backup.
3. Retain `.env.prod`, `APP_KEY`, the artifact signing key, both CA keys, listener
   identities, the Restic password and externally held custom TLS material.
4. Validate the target production Compose file and every generated node bundle.
5. Review migrations for expand/contract compatibility. Laravel and PowerDNS
   migrations are separate and explicit.

## Order

1. **Canary first:** update one DNS/edge host; verify DNS answers, edge heartbeat and HTTPS.
2. Update the control plane: apply Laravel migrations, then PowerDNS migrations, then
   replace core, Horizon and Scheduler together.
3. Roll the remaining hosts one at a time ([fleet rollouts](../operations/fleet-rollouts.md)).
4. Verify health, readiness, operations list and the acceptance checks.

## Rollback

Restart the previous pinned images only if no incompatible migration ran. Otherwise
restore from the verified backup, or apply a forward fix. Edge hosts keep serving the
last valid runtime generation throughout.
