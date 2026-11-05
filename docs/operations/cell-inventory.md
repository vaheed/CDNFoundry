---
title: Bounded cell inventory
description: Install, monitor, control, and recover stable OpenResty cell slots.
---

# Bounded cell inventory

A cell is one OpenResty process that serves traffic for the domains assigned to it. A slot is a fixed place for a cell on an edge host. This page lists the slots every edge has, shows how to assign a service pool to a slot, and explains how to drain, undrain, and restart a slot.

**You need:** an enrolled edge and administrator access.

::: warning Slots are installed capacity
A slot exists because the host runs a matching container with its own ports and volumes. Creating a pool or a domain never creates a process, container, cache directory, or timer.
:::

## The eight slots

Every production edge runs exactly eight generic OpenResty slots, `cell-01` to `cell-08`. PostgreSQL records each slot's identity, assignment, ports, paths, state, capacity, and resource limits. A slot is never created for a domain, and its identity does not change when work moves.

The API and the admin panel accept only `cell_slot_count: 8` or no value. A different count would need a different Compose topology, which CDNFoundry does not support. A mismatch is a failed installation, not elastic scaling.

| Slot | HTTP | HTTPS | Status |
| --- | ---: | ---: | ---: |
| `cell-01` | 18081 | 18444 | 19081 |
| `cell-02` | 18082 | 18445 | 19082 |
| `cell-03` to `cell-08` | 18083 to 18088 | 18446 to 18451 | 19083 to 19088 |

All cell ports are published on loopback for diagnostics only. Customer traffic reaches a cell through the gateway, on distinct private addresses mapped from the advertised service addresses.

Each cell container has its own tmpfs cache, request-temporary storage, and logs. It runs read-only with limits of 512 MiB memory, 0.5 CPU, 128 processes, and 32,768 open files. The agent has smaller limits and no Docker socket.

## Assign a service pool to a slot

A slot serves nothing until it belongs to a service pool. Creating an edge or a pool in the panel or API assigns no slot. A PoP enrolled with the Fleet tool is the exception: `cdnf:edges:enroll` puts all its slots in the default shared pool.

<DocTabs :labels="['Admin panel', 'API']">
<template #tab-1>

1. Open **Infrastructure → Edges** and open the edge.
2. In the **Cells** list, open the row actions for the slot and choose **Assign service pool**.
3. Pick the pool and confirm.

To remove a slot from a pool, choose **Unassign service pool** in the same menu.

</template>
<template #tab-2>

Call `PUT /api/admin/edge-pools/{pool}/cells/{cell}` to assign. Call `DELETE` on the same path to unassign.

</template>
</DocTabs>

Assignment saves desired state and queues bounded reconciliation. It does not change a runtime inside the request. To check it worked, the **Pool** column of the slot shows the pool name and its status changes from unassigned to assigned.

A pool can use one or several slots of an edge. It uses no capacity on edges you did not select. Removal fails while the slot owns an active or target domain placement, or if it would break the pool's minimum-ready policy.

## Drain, undrain, restart

Draining stops new work from reaching a slot. Use it before maintenance.

<DocTabs :labels="['Admin panel', 'API']">
<template #tab-1>

In the **Cells** list, open **Cell actions** for the slot and choose **Drain**, **Undrain**, or **Restart**. Drain and Restart ask for confirmation.

</template>
<template #tab-2>

Call `POST /api/admin/edge-cells/{cell}/drain`, `/undrain`, or `/restart`. List slots with `GET /api/admin/edge-cells`.

</template>
</DocTabs>

The control plane saves the desired state and queues one coalesced task. The agent then calls only that slot's private, authenticated control endpoint. A restart drains the runtime briefly and advances the slot's restart generation. It does not restart the agent, the gateway, or other cells.

## Slot state

The agent heartbeat is authenticated and accepts at most 32 distinct slot names. Ready, degraded, drained, and stopped are runtime observations. Assigned and unassigned are durable inventory.

- An assigned slot missing from a heartbeat becomes degraded.
- An unassigned slot missing from a heartbeat is stopped.
- When a gateway is configured, its readiness is authoritative for the edge.

Watch the edge detail page and its **Cells** list. They show assignment, state, revision, domains, connections, CPU, memory, cache, temporary storage, and last restart. Treat any of these as degraded, and fix the host before you return traffic:

- a slot count, name, or path mismatch;
- a stale heartbeat;
- an assigned slot that is stopped;
- resource limits that differ from the shipped ones.

## Failure behavior

- A stopped or saturated slot leaves the gateway, the agent, and the other slots running. Only routes that target that slot fail.
- The control plane requeues placements that have been deploying for more than five minutes, in bounded batches. This repairs interrupted upgrades. It does not replace the active route before the target acknowledges. Draining has its own bounded completion command.
- An invalid signed state or slot mapping never replaces the agent's previous active state.
- After an agent restart, it rebuilds every assigned slot and every empty unassigned slot file from its stored signed snapshot.
- If the control plane is down, the gateway and cells keep serving locally.
- The cache, temporary, and log tmpfs size limits prevent disk growth. Losing them is safe because they are derived data.
- Enrollment identity, mutual TLS, acknowledgements, and the full snapshot stay in the agent's persistent volume.

## Qualify your host

The repository test `tests/e2e/cell_inventory.py` starts eight real read-only OpenResty containers. It checks unique identities, cgroup and tmpfs limits, and isolation when one slot stops. See [Testing and qualification](../development/testing.md).

On your own host, record CPU, memory, Docker version, idle and active usage of eight slots, request rate and latency, and the first resource that saturates. Load-test gateway-to-cell traffic before you admit workload up to the limits.

One recorded run of that test is below, on an x86_64 VMware host with 32 Intel Xeon E5-2697 v4 vCPUs, 15.6 GiB RAM, and Docker 29.1.3. It is an example of the measurements to take, not a promise for your hardware.

| Measurement | Result |
| --- | --- |
| Idle CPU per slot | 1.21% to 1.57% sample |
| Idle memory per slot | 97.25 to 98.26 MiB |
| Active CPU per slot | 1.11% to 1.28% sample |
| Active memory per slot | 98.20 to 98.75 MiB |
| Active workload | 160 health requests in 40.056 s through serialized Docker exec probes |
| Isolation | Stopping `cell-04` did not stop `cell-05` or the support process |
| Saturation | Not reached |

The probe rate measures per-process overhead, not customer throughput.
