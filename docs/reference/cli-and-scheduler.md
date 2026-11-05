---
title: CLI and scheduler reference
description: Find the CDNFoundry Artisan commands, the scheduled jobs, the Fleet CLI, and the Make targets.
---

# CLI and scheduler reference

::: info Scheduled commands start bounded work
Scheduled commands run on the control node. Serving traffic never depends on
Laravel. If a scheduled job fails, check the recorded health and job state
first. Do not add per-domain timers.
:::

Use these pages to find a command.

| You want | Read |
| --- | --- |
| Application (Artisan) commands, such as creating an administrator, backups, or TLS maintenance | [CDNFoundry CLI commands](../operations/cli-commands.md) |
| The schedule: which command runs when | The [Scheduler section](../operations/cli-commands.md#scheduler) of the same page |
| Host and fleet commands: nodes, roles, releases, certificates | [Fleet CLI reference](fleet-cli.md) |
| Repository Make targets | [Scripts and CI](../development/scripts-and-ci.md) |

Only the active control node runs scheduled work. A standby node runs none, so
a returning old active node does not repeat backups, renders, or TLS
maintenance. Backup jobs also run only when a backup repository is configured.
