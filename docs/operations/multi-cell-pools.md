---
title: Multi-cell pools and placement
description: Operate stable bounded placement across several cells on each edge.
---

# Multi-cell pools and placement

Pool kinds are `shared`, `reserved`, `dedicated`, and `quarantine`. Normal
shared and quarantine placement uses one stable cell per participating edge.
Reserved or dedicated pools may explicitly use two or three replicas. This is
an exceptional capacity/isolation choice, not an automatic per-domain runtime.

Configure `minimum_ready_cells` (1–32), `replicas_per_edge` (1–3), and
`maximum_domains_per_cell` (1–100,000). A target is not promoted until every
required target cell is ready and the edge has acknowledged its signed
artifact. Failed validation records `failed` while preserving the active cell.

## Safe operations

1. Pre-create bounded generic slots on each edge installation.
2. Assign the intended slots to the pool; cells retain private, stable ports.
3. Create one [pool service endpoint](pool-service-endpoints.md) per participating
   edge. The agent receives the matching named targets from desired state.
4. Wait for every required cell and the gateway map to report ready.
5. Move a domain. Observe `deploying`, then `draining`, then `active`.
6. Remove a source assignment only after no active or target placement refers
   to it. The API rejects premature removal.

Capacity exhaustion uses `pool_cell_domain_capacity_exhausted`; missing replica
capacity uses `pool_insufficient_participating_cells`. Neither condition
withdraws a source. If a target stays unready, inspect cell heartbeat status,
endpoint readiness, artifact rejection, and gateway candidate-rejection metrics.
Never clear the source runtime manually.

Placement uses durable rows in PostgreSQL. Edge snapshots, per-cell runtime
files, gateway maps, and cache contents remain derived and rebuildable. Adding
unrelated domains or cells does not reshuffle valid assignments.
