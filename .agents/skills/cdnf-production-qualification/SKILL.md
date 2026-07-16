# cdnf-production-qualification

## Purpose
Qualify runtime behavior before module or Part One completion.
## When to use
Before completing runtime-changing modules and final Part One acceptance.
## Required inputs
Hardware/topology, dataset, limits, browser flow, runtime paths, outage/restart/rollback matrix, compatible versions.
## Files normally touched
Qualification scripts, fixtures, results, and runbooks; production code only for defects.
## Procedure
Record environment; execute browser E2E, DNS, HTTP/S, IPv4/IPv6, restart, outage, last-valid, load/noisy-neighbor, queue-starvation, backup/restore, mixed-version canary/rollback; preserve measurements and failures.
## Validation commands
Repository qualification scripts and explicit documented Compose/runtime commands.
## Definition of done
Every criterion is recorded as Passed, Failed, Not executed, Blocked, or Observed limits with evidence.
## Stop conditions
Stop completion claims when tests are unexecuted, environment differs materially, or ceilings are absent.
## Forbidden shortcuts
No mocked-only runtime qualification, omitted environment/dataset, hidden failures, or converting blocked into pass.
## Expected completion summary
Passed; Failed; Not executed; Blocked; Observed limits.
