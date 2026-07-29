---
title: CDNFoundry CLI commands
description: Complete reference for CDNFoundry-owned Artisan commands and their schedules.
---

# CDNFoundry CLI commands

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
| `cdnf:backups:create {--wait}` | Creates backup and operation records, then queues an encrypted PostgreSQL Restic backup. `--wait` executes the job in the foreground and waits for repository acknowledgement. Fails if the repository is not configured. | Daily at 01:30 |
| `cdnf:backups:restore {operation}` | Restores the backup approved by a successful restore-preflight operation. This is destructive, requires the explicit restore maintenance boundary, runs migrations, queues reconciliation, and leaves maintenance mode active if recovery fails. | No |

```sh
docker compose --env-file .env.prod -f compose.prod.yml exec core php artisan cdnf:backups:create --wait
docker compose --env-file .env.prod -f compose.prod.yml exec core php artisan cdnf:backups:restore 6f9619ff-8b86-d011-b42d-00c04fc964ff
```

## DNS and domains

| Command and syntax | Purpose and effects | Scheduled |
| --- | --- | --- |
| `cdnf:dns:deprovision-due` | Dispatches at most 1,000 due DNS-zone deprovision jobs in chunks of 100. It does not wait for PowerDNS changes. | Every minute |
| `cdnf:domains:finalize-deprovisioning {--limit=100}` | Dispatches finalization for due retired domains after runtime tombstones are safe. The effective limit is clamped to 1–1,000. | Every minute |

## Edge

| Command and syntax | Purpose and effects | Scheduled |
| --- | --- | --- |
| `cdnf:edge:complete-placement-drains {--limit=100}` | Promotes ready target pools after DNS drain, advances desired revisions, records audit events, and queues edge reconciliation. Limit is clamped to 1–1,000. | Every minute |
| `cdnf:edge:dispatch-origin-checks {--limit=100}` | Dispatches a jittered, bounded batch of explicitly enabled origin checks. Limit is clamped to 1–500 and at most five checks are selected per domain. | Every minute |
| `cdnf:edge:prune-revisions {--limit=1000}` | Permanently removes expired derived edge revisions and artifacts while retaining active and rollback-protected state. Limit is clamped to 1–10,000. | Daily at 02:30 |
| `cdnf:edge:reconcile-stale-placements {--limit=100}` | Requeues interrupted stale placement deployments so they converge. Limit is clamped to 1–1,000. | Every minute |

```sh
docker compose -f compose.dev.yml run --rm core php artisan cdnf:edge:reconcile-stale-placements --limit=50
```

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
| `cdnf:tls:dispatch-maintenance {--limit=500}` | Cleans expired ACME challenges, queues bounded managed-certificate maintenance, and publishes administrator expiry/failure alerts. Limit is clamped to 1–2,000. | Hourly |

## Usage

| Command and syntax | Purpose and effects | Scheduled |
| --- | --- | --- |
| `cdnf:usage:finalize` | Dispatches an idempotent rebuild for the most recently finalized UTC usage hour. It does not wait for ClickHouse aggregation. | Hourly at minute 20 |

## Scheduler

All command schedules use overlap prevention. The scheduler also runs framework
or internal non-command work, which is intentionally not renamed here.

| Scheduled command | Frequency | Purpose |
| --- | --- | --- |
| `cdnf:dns:deprovision-due` | Every minute | Queue due DNS removals |
| `cdnf:domains:finalize-deprovisioning` | Every minute | Finalize safe domain retirement |
| `cdnf:edge:complete-placement-drains` | Every minute | Complete ready placement drains |
| `cdnf:edge:reconcile-stale-placements` | Every minute | Retry stale placements |
| `cdnf:edge:dispatch-origin-checks` | Every minute | Queue due opt-in origin checks |
| `cdnf:edge:prune-revisions` | Daily at 02:30 | Remove expired derived revision history |
| `cdnf:tls:dispatch-maintenance` | Hourly | Maintain managed certificates and alerts |
| `cdnf:security:reconcile-readiness` | Every minute | Advance bounded security recovery |
| `cdnf:waf:expire-exclusions` | Every minute | Remove due WAF exclusions |
| `cdnf:usage:finalize` | Hourly at minute 20 | Queue the finalized usage hour |
| `cdnf:audit:prune` | Daily at 03:10 | Delete one expired audit batch |
| `cdnf:backups:create` | Daily at 01:30 | Queue the control-plane backup |

Laravel's `horizon:snapshot` runs every five minutes and `model:prune` runs
hourly. They remain unchanged because they are not CDNFoundry-owned commands.
