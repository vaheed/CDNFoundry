---
title: CLI and scheduler reference
description: Reference CDNFoundry Artisan commands, Make targets, and scheduled jobs.
---

# CLI and scheduler reference

Run Artisan commands inside the `core` container or the matching production
service. Commands that mutate runtime state still use jobs and do not perform
synchronous deployment.

## CDNFoundry Artisan commands

| Command | Purpose |
| --- | --- |
| `cdnf:admin:create --name= --email=` | Interactively create an administrator |
| `api:openapi [--check]` | Generate or verify the route-derived API contract and endpoint catalog |
| `platform:settings:show [group] [--json]` | Show settings, descriptions, defaults, and active values |
| `platform:settings:set group values` | Validate a JSON object and update one group |
| `dns:deprovision-due` | Dispatch DNS removals whose delay elapsed |
| `domains:finalize-deprovisioning --limit=100` | Finalize bounded retired domains |
| `edge:complete-placement-drains --limit=100` | Complete ready target/source transitions |
| `edge:dispatch-origin-checks --limit=100` | Dispatch due opt-in origin checks |
| `edge:prune-revisions --limit=1000` | Prune derived history while keeping protected revisions |
| `tls:dispatch-maintenance --limit=500` | Renew, retry, clean challenge state, and alert |
| `security:reconcile-readiness --limit=100` | Expire controls and advance recovery |
| `waf:expire-exclusions --limit=100` | Expire bounded managed-WAF exclusions and queue signed revisions |
| `usage:finalize` | Dispatch the most recently finalizable usage hour |
| `audit:prune --batch=1000` | Delete one bounded expired audit batch |
| `backups:create [--wait]` | Queue or synchronously execute one Restic backup |
| `backups:restore operation` | Execute a preflighted restore in maintenance mode |

`backups:restore` requires a successful preflight operation and the explicit
`BACKUP_RESTORE_ALLOWED` maintenance boundary. It intentionally leaves
maintenance mode enabled for operator verification.

## Scheduler

| Schedule | Work |
| --- | --- |
| Every minute | DNS deprovision, domain finalization, placement drains, origin checks, platform DNS reconcile, security readiness, WAF exclusion expiry, scheduler heartbeat |
| Every five minutes | Horizon snapshot |
| Hourly | Idempotency pruning, managed TLS maintenance |
| Hourly at minute 20 | Usage finalization |
| Daily 01:30 | Control backup |
| Daily 02:30 | Edge revision pruning |
| Daily 03:10 | Audit pruning |

Every schedule uses overlap prevention.

## Make targets

| Target | Purpose |
| --- | --- |
| `dev-assets` | Build/export production frontend assets |
| `dev-up` | Build and start full development plus `devtools` |
| `dev-edge-up`, `dev-edge-status` | Start or inspect UI-enrolled local agents |
| `dev-scale-up` | Start only bounded scale-test dependencies |
| `dev-down` | Stop containers without removing volumes |
| `dev-migrate`, `dev-pdns-migrate` | Explicit schema deployment |
| `dev-test` | Isolated SQLite Laravel suite |
| `dev-e2e` | Cumulative non-browser real-runtime suite |
| `dev-scale-e2e` | 500,000-zone/1,000,000-record qualification |
| `dev-phase7-e2e` | Analytics runtime qualification |
| `dev-phase8-e2e` | Operations runtime qualification |
| `dev-phase8-recovery-e2e` | Recovery qualification |
| `dev-phase8-upgrade-e2e` | Mixed-version upgrade qualification |
| `dev-phase8-throughput-e2e` | HTTP/DNS throughput sample |
| `dev-phase8-mmdb-e2e` | MMDB updater qualification |
| `prod-pull` | Pull pinned production images |
| `prod-migrate`, `prod-pdns-migrate` | Explicit production migrations |
| `prod-control`, `prod-dns`, `prod-telemetry`, `prod-edge` | Start one profile |
| `config-check` | Validate base and overlay Compose contracts |
| `openapi-check` | Verify committed route contract |
| `docs-check` | Run documentation lint, links, coverage, and build |
