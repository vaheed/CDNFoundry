---
title: Troubleshooting
description: A fixed order for diagnosing CDNFoundry problems, from the saved desired state outward to the running service, with links to each area.
---

# Troubleshooting

Use this page when something does not work and you do not yet know why. You need
administrator access to the panel. Some steps also need shell access to a host.

::: tip Diagnose from the saved intent outward
CDNFoundry saves what you asked for first, then pushes it to DNS and edges. Start
with the saved request, then look at each later stage. This tells you whether the
control plane or the running service failed.
:::

## Steps

<div class="steps">

1. Open `/api/ready` and the administrator component health.
2. Open the operation, deployment, task, or certificate order for your change.
3. Compare the desired revision with the acknowledged revision.
4. Check the Horizon queue that carries the work, and its failed jobs.
5. Check the private runtime service and its last valid state.
6. Reconcile only the smallest scope that is affected.

</div>

**Expected result:** you find the first stage where the desired and acknowledged
revisions differ. Fix that stage, not the ones after it.

If you start from what you see, use [Troubleshoot by symptom](by-symptom.md). Or
choose an area:

- [DNS](dns.md)
- [Edge and origin](edge-and-origin.md)
- [TLS and cache](tls-and-cache.md)
- [Telemetry](telemetry.md)

Do not repair desired state in PowerAdmin or by editing generated edge files.
CDNFoundry builds both from PostgreSQL and overwrites your edits.

## Docker reports `storage/logs: file exists`

You may see this message during `make dev-up`. It comes from Docker, which tries to
copy files into the shared Laravel storage volume from several containers at once.
Laravel logging is not the cause. `compose.dev.yml` mounts `core-storage` with
`volume.nocopy: true`, and the application entrypoint creates the directories.

<div class="steps">

1. Confirm your checkout has the current `compose.dev.yml`.
2. Run `docker compose -f compose.dev.yml config`. The `/app/storage` volume must
   show `nocopy: true`.
3. Run `make dev-up` again.

</div>

**Expected result:** the stack starts and `docker compose -f compose.dev.yml ps`
lists the services.

::: danger Preserve durable development state
Do not use `docker compose down -v` or remove `core-storage` as a workaround.
If it still fails, save the output of `docker compose ps`, the daemon
error, and `docker volume inspect cdnfoundry-dev_core-storage` before you change
anything.
:::
