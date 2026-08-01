---
title: Bounded cell inventory
description: Install, monitor, control, and recover stable OpenResty cell slots.
---

# Bounded cell inventory

::: caution Slots are installed capacity
A configured slot must have a matching fixed service, port set, volume, and
agent entry before the host starts. Creating a pool or domain never creates a
new process, container, cache directory, or timer.
:::

Each production edge installation starts exactly eight generic OpenResty slots,
`cell-01` through `cell-08`. PostgreSQL records the configured slot count and
each slot's identity, assignment, ports, paths, state, capacity, and resource
ceilings. A slot is never created for a domain and its identity never changes
when work moves.

## Installation contract

Create the edge with the intended `cell_slot_count` (1–32) before enrollment.
The shipped Compose topology implements the default count of eight. An operator
using a non-default count must render the same consecutive services and update
the agent assignment/status lists before starting the host; a mismatch is a
failed installation, not elastic scaling.

| Slot | HTTP | HTTPS | Status | Initial assignment |
| --- | ---: | ---: | ---: | --- |
| `cell-01` | 18081 | 18444 | 19081 | Unassigned |
| `cell-02` | 18082 | 18445 | 19082 | Unassigned |
| `cell-03`–`cell-08` | 18083–18088 | 18446–18451 | 19083–19088 | Unassigned |

All cell publications are loopback diagnostics. Customer traffic enters only
through gateway listeners mapped from advertised service addresses to distinct
private local addresses. Each container has separate
tmpfs-backed cache, request-temporary, and log storage, plus CPU, memory, PID,
and file-descriptor limits. The agent has its own smaller limits and no Docker
or other container-engine socket.

## State and controls

The authenticated heartbeat accepts at most 32 distinct canonical slot names.
Ready, degraded, drained, and stopped are runtime observations; assigned and
unassigned describe durable inventory. An assigned slot omitted from a
heartbeat becomes degraded. An unassigned omitted slot is stopped. Gateway
readiness is authoritative when the gateway is configured and is never tied to
a hardcoded shared-cell name.

Administrators may drain, undrain, or restart an existing slot. The
control plane commits desired state and queues one coalesced task; the agent
calls only that slot's private authenticated control endpoint. Restart drains
the runtime briefly and advances its restart generation without restarting the
agent, gateway, or unrelated cells.

Creating a service pool or an edge never assigns a slot. Assign every intended
cell explicitly from **Edge network → Edges → Cells → Assign service pool**.
Automation can use `PUT /api/admin/edge-pools/{pool}/cells/{cell}`. Assignment
persists desired state and queues bounded reconciliation rather than changing a
runtime synchronously. A pool may use one or several of an edge's slots, and it
does not consume capacity on edges that the operator did not select.
Removal fails while the cell owns an active or target domain placement or would
violate the pool's minimum-ready policy.

## Failure and recovery

- A stopped or saturated slot leaves the gateway, agent, and other slot cgroups
  running. Routes targeting it fail in isolation.
- Deploying placements older than five minutes are requeued in bounded batches.
  This repairs interrupted upgrades and missing per-cell state without
  replacing the active route before the target is acknowledged. Draining has
  its own bounded completion command.
- Invalid signed state or an invalid slot mapping never replaces the agent's
  previous active state.
- Agent restart reconstructs every assigned and empty unassigned slot file from
  its durable signed snapshot.
- Control-plane outage leaves active gateway and cell state serving locally.
- Cache, temporary, and log tmpfs ceilings prevent disk growth. Loss is safe
  because these artifacts are derived.
- Enrollment identity, mutual TLS, acknowledgements, and full snapshot recovery
  remain in the independent persistent agent volume.

Monitor the edge detail and Cells relation for assignment, state, revision,
workload, connections, CPU, memory, cache, temporary storage, and last restart.
Treat any slot-count/name/path mismatch, stale heartbeat, assigned stopped slot,
or resource limit drift as degraded and reconcile the host definition before
returning traffic.

## Qualification

Run the non-browser inventory runtime test documented in
[Testing and qualification](../development/testing.md). Record the host CPU and
memory, Docker version, eight-slot idle and active cgroup usage, concurrency,
request rate and latency, the first saturated resource, and the accepted host
limit. Then run the owner-only browser checklist. Coding agents must not run or
claim the browser result.

On 2026-07-27, `python3 tests/e2e/cell_inventory.py` passed on an x86_64 VMware
host with 32 Intel Xeon E5-2697 v4 vCPUs, 15.6 GiB RAM, and Docker 29.1.3. The
test created exactly eight real read-only OpenResty containers, verified unique
identities and cgroup/tmpfs ceilings, made 160 active health requests, stopped
and restored `cell-04`, and confirmed `cell-05` plus the separate support
process remained ready. The first run found and corrected unsafe default Nginx
temp paths outside the writable quota; the passing run used the production
paths.

| Measurement | Result |
| --- | --- |
| Idle CPU per slot | 1.21%–1.57% sample |
| Idle memory per slot | 97.25–98.26 MiB |
| Active CPU per slot | 1.11%–1.28% sample |
| Active memory per slot | 98.20–98.75 MiB |
| Active workload | 160 health requests in 40.056 s through serialized Docker exec probes |
| Isolation | Stopped slot did not stop another slot or support process |
| Saturation | Not reached; installation is intentionally capped at eight shipped slots (32 schema maximum) |
| Accepted limit | Eight 512 MiB / 0.5 CPU / 128 PID slots on this topology |

The serialized probe rate measures active per-process overhead, not customer
throughput. Operators must load-test gateway-to-cell traffic on their host
before admitting workload up to the cgroup ceilings.
