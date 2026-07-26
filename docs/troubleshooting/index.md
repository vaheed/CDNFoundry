---
title: Troubleshooting
description: Diagnose CDNFoundry failures from durable state toward derived runtimes.
---

# Troubleshooting

::: tip Diagnose from durable intent outward
Start with the desired revision and operation receipt, then inspect target
deployment state, runtime acknowledgement, and finally a real network probe.
This distinguishes control failure from runtime failure.
:::

Start with the durable operation and desired revision, then move outward:

1. Check `/api/ready` and administrator component health.
2. Inspect the relevant operation, deployment, task, or certificate order.
3. Compare desired and acknowledged revisions.
4. Check the responsible Horizon lane and failed jobs.
5. Check the private runtime service and its last-valid state.
6. Reconcile only the smallest affected scope.

Choose a symptom:

- [DNS](/troubleshooting/dns)
- [Edge and origin](/troubleshooting/edge-and-origin)
- [TLS and cache](/troubleshooting/tls-and-cache)
- [Telemetry](/troubleshooting/telemetry)

Do not repair desired state in PowerAdmin or by editing generated edge files.
Those are derived and will be overwritten.

## Docker reports `storage/logs: file exists`

This message during `make dev-up` comes from Docker attempting concurrent
copy-up into the shared Laravel storage volume, not from Laravel logging. The
current Compose contract mounts `core-storage` with `volume.nocopy: true`, and
the application entrypoint creates the required directories.

1. Confirm the checkout contains the current `compose.dev.yml`.
2. Run `docker compose -f compose.dev.yml config` and verify the
   `/app/storage` volume reports `nocopy: true`.
3. Run `make dev-up` again.

::: danger Preserve durable development state
Do not use `docker compose down -v` or remove `core-storage` as a workaround.
If the current contract still fails, capture `docker compose ps`, the daemon
error, and `docker volume inspect cdnfoundry-dev_core-storage` before changing
anything.
:::
