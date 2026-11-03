---
title: CDNFoundry CLI commands
description: Complete reference for CDNFoundry-owned Artisan commands and their schedules.
---

# CDNFoundry CLI commands

::: danger Confirm the target environment
Before a production command, verify the exact release, Compose files,
environment file, host role, and database endpoint. Never paste secrets into
command arguments or run maintenance commands against an assumed context.
:::

Run commands from the Laravel application directory, or through the repository's
`core` Docker Compose service. List only CDNFoundry commands with:

```sh
php artisan list cdnf
docker compose -f compose.dev.yml run --rm core php artisan list cdnf
```

View the authoritative arguments and options for any command with:

```sh
php artisan help cdnf:<area>:<action>
docker compose -f compose.dev.yml run --rm core php artisan help cdnf:<area>:<action>
```

Commands that enqueue work return before asynchronous runtime changes finish.
Run production examples with `docker compose --env-file .env.prod -f
compose.prod.yml exec core php artisan ...` against an already-running `core`
service.

## Administration

| Command and syntax | Purpose and effects | Scheduled |
| --- | --- | --- |
| `cdnf:admin:create {--name=} {--email=}` | Prompts for a password and creates an administrator plus an audit event. Fails on invalid or duplicate input; avoid supplying passwords in shell arguments or history. | No |

```sh
docker compose -f compose.dev.yml run --rm core php artisan cdnf:admin:create --name="Operations Admin" --email="admin@example.test"
```

## API

| Command and syntax | Purpose and effects | Scheduled |
| --- | --- | --- |
| `cdnf:api:openapi {--check}` | Generates the route-derived OpenAPI contract and endpoint catalog. `--check` makes no changes and fails when committed artifacts are stale; without it, the command rewrites generated documentation. | No |

```sh
docker compose -f compose.dev.yml run --rm core php artisan cdnf:api:openapi --check
```

## Audit

| Command and syntax | Purpose and effects | Scheduled |
| --- | --- | --- |
| `cdnf:audit:prune {--batch=1000}` | Permanently deletes one bounded batch of audit events beyond configured retention. Increase the batch cautiously because deletion load grows with it. | Daily at 03:10 |

## Backups

| Command and syntax | Purpose and effects | Scheduled |
| --- | --- | --- |
| `cdnf:backups:create {--wait}` | Creates backup and operation records, then queues an encrypted PostgreSQL Restic backup. `--wait` executes the job in the foreground and waits for repository acknowledgement. Fails if the repository is not configured. | Daily at 01:30 when configured |
| `cdnf:backups:maintain {task}` | `prune` forgets snapshots beyond the retention window (14 daily, 8 weekly, 6 monthly by default) and reclaims their space; `check` runs `restic check` on the repository. Each queues an operation. Fails if the repository is not configured. | `prune` daily at 04:10, `check` Sundays at 05:00, when configured |
| `cdnf:backups:restore {operation}` | Restores the backup approved by a successful restore-preflight operation. This is destructive, requires the explicit restore maintenance boundary, runs migrations, queues reconciliation, and leaves maintenance mode active if recovery fails. | No |

```sh
docker compose --env-file .env.prod -f compose.prod.yml exec core php artisan cdnf:backups:create --wait
docker compose --env-file .env.prod -f compose.prod.yml exec core php artisan cdnf:backups:restore 6f9619ff-8b86-d011-b42d-00c04fc964ff
```

## Control plane high availability

These commands serve the optional two-node control plane. The operator normally
runs them through `cdnfoundry-fleet control ...` (see
[Control-plane high availability](./control-plane-high-availability.md)), not by hand.

| Command and syntax | Purpose and effects | Scheduled |
| --- | --- | --- |
| `cdnf:control:guard` | Exits non-zero unless this node may run application services: PostgreSQL is the writable primary, this node is the recorded active node, and no reachable peer reports a higher epoch. The `horizon` and `scheduler` containers run it before starting. | At container start |
| `cdnf:control:enforce` | Asks Horizon to terminate when this node has been replaced, so the restart fails the start guard. | Every minute |
| `cdnf:control:observe` | On the active node, records whether the standby is streaming PostgreSQL, its replay lag, and whether the Valkey replica is linked. Skips on a single node. | Every minute |
| `cdnf:control:status` | Prints `ha` (`none`, `ready`, or `degraded`), the reasons, the epoch, the address list, and the fencing state as JSON. | No |
| `cdnf:edges:enroll {name} {--country=} {--continent=}` | Registers a PoP, assigns every cell slot to the default shared pool, and prints its edge ID and one-time bootstrap token as JSON. Fleet runs it when a PoP is added. | On PoP setup |
| `cdnf:control:fingerprints` | Prints a keyed fingerprint of each shared control secret as JSON. Never prints a value. | No |
| `cdnf:control:manage {action}` | Changes high availability state in one transaction. `addresses --addresses=A,B` sets the ordered control addresses every edge dials; `record --name --role --address [--fingerprints --lag]` records a node report; `promote --name` increments the epoch and records the node as active; `remove-standby` drops the standby and its address. | No |

## DNS and domains

| Command and syntax | Purpose and effects | Scheduled |
| --- | --- | --- |
| `cdnf:dns:deprovision-due` | Dispatches at most 1,000 due DNS-zone deprovision jobs in chunks of 100. It does not wait for PowerDNS changes. | Every minute |
| `cdnf:dns:repair-drift` | Checks up to `--limit` (default 100, at most 1,000) DNS deployments, oldest check first. It compares the zone each DNS target serves with the deployed record sets, and the deployed record sets with the current desired state, and queues one coalesced reconcile for every difference or failed deployment. An unreachable target is not drift. | Every 5 minutes |
| `cdnf:analytics:publish-zones` | Publishes the served zones (name and domain id of every active or pending-verification domain) to ClickHouse as one snapshot, so DNS telemetry rolls up by zone. It writes only when the list changed, and at least every 12 hours; `--force` writes anyway. It does nothing when `CLICKHOUSE_ZONES_PASSWORD` is unset. | Every minute |
| `cdnf:domains:expire-claims` | Retires at most 500 unverified claims older than seven days; runtime deletion remains asynchronous. | Every minute |
| `cdnf:domains:finalize-deprovisioning {--limit=100}` | Dispatches finalization for due retired domains after runtime tombstones are safe. The effective limit is clamped to 1–1,000. | Every minute |

## Edge

| Command and syntax | Purpose and effects | Scheduled |
| --- | --- | --- |
| `cdnf:edge:complete-placement-drains {--limit=100}` | Promotes ready target pools after DNS drain, advances desired revisions, records audit events, and queues edge reconciliation. Limit is clamped to 1–1,000. | Every minute |
| `cdnf:edge:dispatch-origin-checks {--limit=100}` | Dispatches a jittered, bounded batch of explicitly enabled origin checks. Limit is clamped to 1–500 and at most five checks are selected per domain. | Every minute |
| `cdnf:edge:prune-revisions {--limit=1000}` | Permanently removes expired derived edge revisions and artifacts while retaining active and rollback-protected state. Limit is clamped to 1–10,000. | Daily at 02:30 |
| `cdnf:scheduler:check {--max-age=150}` | Exits non-zero unless the scheduler recorded its heartbeat within the allowed seconds. Used as the scheduler container health check. | Every 30 seconds by Compose |
| `cdnf:edge:promote-placements {--chunk=100} {--chunks=50}` | Promotes deploying placements whose healthy PoPs have acknowledged (the quorum), paging through every deploying placement in chunks. A PoP that has not acknowledged within the promotion grace window stops counting and catches up on its own. | Every minute |
| `cdnf:edge:reconcile-stale-placements {--limit=100}` | Requeues interrupted stale placement deployments so they converge. Limit is clamped to 1–1,000. | Every minute |

```sh
docker compose -f compose.dev.yml run --rm core php artisan cdnf:edge:reconcile-stale-placements --limit=50
```

## Service health

| Command and syntax | Purpose and effects | Scheduled |
| --- | --- | --- |
| `cdnf:health:publish` | Samples existing system and queue checks once, then caches only public component names and states for the status page. It performs no runtime mutation. | Every minute |

## Platform settings

| Command and syntax | Purpose and effects | Scheduled |
| --- | --- | --- |
| `cdnf:platform:settings:show {group?} {--json}` | Reads PostgreSQL-backed settings, descriptions, defaults, and active values. Supply an optional group; `--json` emits machine-readable output. | No |
| `cdnf:platform:settings:set {group} {values}` | Validates a JSON object, updates one setting group and revision, and queues runtime reconciliation when required. Quote JSON to prevent shell expansion. | No |

```sh
docker compose -f compose.dev.yml run --rm core php artisan cdnf:platform:settings:show dns_lifecycle --json
docker compose -f compose.dev.yml run --rm core php artisan cdnf:platform:settings:set dns_lifecycle '{"deprovision_delay_days":14}'
```

## Security and WAF

| Command and syntax | Purpose and effects | Scheduled |
| --- | --- | --- |
| `cdnf:security:reconcile-readiness {--limit=100}` | Expires emergency controls and advances quiet domains through bounded security recovery, producing reconciliation work. Limit is clamped to 1–1,000. | Every minute |
| `cdnf:waf:expire-exclusions {--limit=100}` | Permanently removes due managed-WAF exclusions in bounded domain batches, increments revisions, records audit events, and queues signed edge artifacts. Limit is clamped to 1–1,000. | Every minute |

## TLS

| Command and syntax | Purpose and effects | Scheduled |
| --- | --- | --- |
| `cdnf:system:components` | Prints control plane component and queue health as JSON. `cdnfoundry-fleet doctor` reads it. | On demand |
| `cdnf:tls:dispatch-maintenance {--limit=500}` | Cleans expired ACME challenges, advances a shared bounded domain sweep for managed-certificate maintenance, and publishes administrator expiry/failure alerts. Limit is clamped to 1–2,000; scan progress advances only after dispatch and resets after each fixed domain-ID range. | Hourly |

## Usage

| Command and syntax | Purpose and effects | Scheduled |
| --- | --- | --- |
| `cdnf:usage:finalize` | Dispatches an idempotent rebuild for the most recently finalized UTC usage hour. It does not wait for ClickHouse aggregation. | Hourly at minute 20 |

## Scheduler

All command schedules use overlap prevention. Every scheduled task also runs only on the writable active control node (`onOneServer` plus the fence check), so a standby or a returning old node repeats nothing. The scheduler also runs framework
or internal non-command work, which is intentionally not renamed here.

| Scheduled command | Frequency | Purpose |
| --- | --- | --- |
| `cdnf:dns:deprovision-due` | Every minute | Queue due DNS removals |
| `cdnf:dns:repair-drift` | Every 5 minutes | Repair DNS zones that differ from desired state |
| `cdnf:analytics:publish-zones` | Every minute | Keep the served-zone list that DNS rollups use current |
| `cdnf:domains:expire-claims` | Every minute | Deprovision at most 500 expired unverified claims; preserve verified ownership. |
| `cdnf:domains:finalize-deprovisioning` | Every minute | Finalize safe domain retirement |
| `cdnf:edge:complete-placement-drains` | Every minute | Complete ready placement drains |
| `cdnf:edge:promote-placements` | Every minute | Promote placements by quorum |
| `cdnf:edge:reconcile-stale-placements` | Every minute | Retry stale placements |
| `cdnf:edge:dispatch-origin-checks` | Every minute | Queue due opt-in origin checks |
| `cdnf:edge:prune-revisions` | Daily at 02:30 | Remove expired derived revision history |
| `cdnf:health:publish` | Every minute | Refresh the sanitized public status page snapshot |
| `cdnf:tls:dispatch-maintenance` | Hourly | Maintain managed certificates and alerts |
| `cdnf:security:reconcile-readiness` | Every minute | Advance bounded security recovery |
| `cdnf:waf:expire-exclusions` | Every minute | Remove due WAF exclusions |
| `cdnf:usage:finalize` | Hourly at minute 20 | Queue the finalized usage hour |
| `cdnf:audit:prune` | Daily at 03:10 | Delete one expired audit batch |
| `cdnf:backups:create` | Daily at 01:30 when configured | Queue the control-plane backup |
| `cdnf:backups:maintain prune` | Daily at 04:10 when configured | Apply backup retention |
| `cdnf:backups:maintain check` | Sundays at 05:00 when configured | Verify the backup repository |
| `cdnf:control:enforce` | Every minute | Stop Horizon on a node that a promotion replaced |
| `cdnf:control:observe` | Every minute | Record standby replication lag and Valkey link |

Laravel's `horizon:snapshot` runs every five minutes and `model:prune` runs
hourly. They remain unchanged because they are not CDNFoundry-owned commands.
