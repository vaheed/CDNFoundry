# cdnf-compose-operations

## Purpose
Maintain reproducible development and production Compose operations.
## When to use
For services, networks, health, storage, secrets, metrics, backup/restore, and startup behavior.
## Required inputs
Host role, service set, exposure, dependencies, volumes, limits, secrets, health, and recovery goals.
## Files normally touched
Compose, Dockerfiles/config, Makefile, env examples, smoke tests and operations docs.
## Procedure
Keep complete dev/minimal role profiles; make internals private; define volumes/health/restart/limits; keep migrations explicit; add graceful lifecycle; document secrets/backup/restore/clean recovery; qualify config/restarts.
## Validation commands
`make config-check`; production config; clean start/migrate/health/restart/smoke/restore commands.
## Definition of done
A fresh host starts the intended role and recovers state without implicit schema mutation.
## Stop conditions
Stop if secrets, ownership, exposure, health, or restore set is unclear.
## Forbidden shortcuts
No Kubernetes requirement, custom discovery/monitoring, implicit migrations, public internals, floating images, or prod devtools.
## Expected completion summary
Topology, exposure, volumes/limits, commands, and recovery limitations.
