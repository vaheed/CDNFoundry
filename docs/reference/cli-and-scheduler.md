---
title: CLI and scheduler reference
description: Entry point for CDNFoundry command-line and scheduler documentation.
---

# CLI and scheduler reference

::: info Scheduler entries dispatch bounded work
Scheduled commands do not make serving traffic depend on Laravel. When a
dependency or optional integration is unavailable, inspect the recorded health
and job state rather than adding per-domain timers.
:::

The complete command and scheduler reference is maintained on the dedicated
[CDNFoundry CLI commands](../operations/cli-commands.md) page.

Repository Make targets remain documented in [Scripts and CI](../development/scripts-and-ci.md).
