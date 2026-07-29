---
title: Upgrade and rollback
description: Upgrade CDNFoundry with explicit migrations, canaries, compatibility checks, and safe rollback.
---

# Upgrade and rollback

::: danger Preserve durable state
Never remove named volumes, regenerate encryption or CA keys, or run a
destructive database refresh during an upgrade. Roll back images only within
the proven schema compatibility envelope.
:::

## Before the change

1. Pin the current and target immutable image references.
2. Create and verify an encrypted off-host control backup.
3. Retain `.env.prod`, `APP_KEY`, artifact signing key, both CA keys,
   listener identities, Restic password, and externally held custom TLS material.
4. Validate target Compose and production overlays.
5. Review migrations for expand/contract compatibility.
6. Run the target's automated and real-runtime qualification.

## Rollout order

For additive migrations and compatible agents:

1. run `make prod-pull`;
2. run `make prod-migrate`;
3. apply the additive ClickHouse telemetry migrations that are newer than the
   deployed release, including
   `docker/clickhouse/migrations/2026_07_29_add_compression_telemetry.sql`
   and
   `docker/clickhouse/migrations/2026_07_29_add_origin_failover_telemetry.sql`,
   and
   `docker/clickhouse/migrations/2026_07_29_add_managed_waf_telemetry.sql`,
   with `clickhouse-client --multiquery` before replacing Vector;
4. canary one control web process;
5. replace Horizon workers gracefully with `php artisan horizon:terminate`;
6. replace the scheduler;
7. run `make prod-pdns-migrate` before PowerDNS code that needs it;
8. canary one DNS target and verify UDP/TCP answers;
9. canary one edge agent and cell;
10. verify artifact compatibility, acknowledgement, HTTP/HTTPS, cache,
    compression, managed WAF, TLS, and telemetry;
11. continue one failure domain at a time.

Nginx/OpenResty services receive `SIGQUIT` and bounded stop grace. Do not force
remove healthy listeners during routine rollout.

## Compatibility

Artifacts carry schema, minimum, and maximum agent versions. An incompatible
agent rejects the candidate and keeps its active state. The upgrade qualification
builds prior and current core/agent images, migrates a temporary PostgreSQL
database forward, verifies old state, and exercises signed compatibility.

## Application rollback

If the target application is compatible with the already-applied schema, repin
`CDNF_RELEASE` to the prior immutable image and replace services in reverse
order. Do not roll the database backward for a normal application rollback.
Contract migrations must remove old columns only in a later release after every
old binary is gone.

## Runtime rollback

Domain edge rollback creates a new revision from a retained validated snapshot.
DNS keeps its prior active RRsets when replacement fails. TLS preserves its
valid active certificate. These runtime guarantees are independent of container
image rollback.

## Stop conditions

Stop the rollout if component health is unavailable, queue age grows without
bound, a candidate is rejected, a DNS cluster loses correct answers, edge
listener readiness falls, or new migrations break the prior binary. Preserve
the last healthy canary and collect operation/task evidence before retrying.
