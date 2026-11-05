---
title: Multi-cell pools and placement
description: Assign cells to a service pool, move a domain between pools safely, and fix a placement that does not finish.
---

# Multi-cell pools and placement

<Screenshot name="admin-edge-pools" alt="The Service pools list in the admin panel." />

A **cell** is one bounded OpenResty runtime on an edge host. A **service pool** groups cells across edges. A domain is placed in one pool, and the pool decides which cell serves it on each edge. This page shows how to prepare a pool, move a domain into it, and fix a move that stalls.

**You need:** administrator access, edges that have unassigned cell slots (see [cell inventory](cell-inventory.md)), and a proxied domain to move.

::: warning Activate before draining
Make the target cell ready and acknowledged before you drain the source. If you remove the source first, the pool can fall below its minimum ready cells and DNS stops publishing it.
:::

## Pool kinds and replicas

| Kind | Use |
| --- | --- |
| `shared` | Normal tenants |
| `quarantine` | Containment of risky traffic |
| `reserved` | Controlled capacity |
| `dedicated` | Exceptional isolation. One domain per pool. |

Shared and quarantine pools use one stable cell per participating edge. Only reserved and dedicated pools may use two or three replicas per edge. The API rejects replicas above 1 on other kinds with a 422 error. Treat extra replicas as an exceptional choice for capacity or isolation. CDNFoundry never creates a runtime per domain.

A pool has three limits:

| Setting | Allowed values | Meaning |
| --- | --- | --- |
| `minimum_ready_cells` | 1 to 32 | Ready cells each edge needs before the pool publishes |
| `replicas_per_edge` | 1 to 3 | Cells that serve one domain on each edge |
| `maximum_domains_per_cell` | 1 to 100,000 | Domains one cell accepts |

The control plane does not promote a target until every required target cell is ready and the edge has acknowledged its signed artifact. If validation fails, the placement records `failed` and the active cell keeps serving.

## Prepare and move

<div class="steps">

1. Make sure each edge has generic cell slots installed. Fleet installs a fixed set. The control plane never creates slots on demand.
2. Assign cells to the pool. Cells keep private, stable ports.
3. Create one [pool service endpoint](pool-service-endpoints.md) per participating edge. The agent receives the matching targets from desired state.
4. Wait until every required cell and the gateway map report ready.
5. Move the domain to the pool.
6. Watch the placement go from `deploying` to `draining` to `active`.
7. Remove a source cell assignment only after no active or target placement refers to it.

</div>

<DocTabs :labels="['Admin panel', 'API']">
<template #tab-1>

Open **Infrastructure → Edges**, open an edge, then its **Cells** tab. Choose **Assign service pool** on an unassigned cell and pick the pool.

To move a domain, open the domain, choose **Move service pool**, and pick the **Target pool**. The list offers enabled pools that are not withdrawn.

</template>
<template #tab-2>

```sh
curl -X PUT "$CDNF_URL/api/admin/edge-pools/$POOL_ID/cells/$CELL_ID" \
  -H "Authorization: Bearer $CDNF_TOKEN" \
  -H "Idempotency-Key: $(uuidgen)"

curl -X POST "$CDNF_URL/api/admin/domains/$DOMAIN_ID/move" \
  -H "Authorization: Bearer $CDNF_TOKEN" \
  -H "Idempotency-Key: $(uuidgen)" \
  -H "Content-Type: application/json" \
  -d '{"pool_id": 7}'
```

Both calls return `202 Accepted` with an operation ID. Only a proxied domain can be moved. A dedicated pool accepts one domain. Remove a source cell with `DELETE /api/admin/edge-pools/{pool}/cells/{cell}`. It returns 409 while a domain placement still refers to the cell, or while the pool is enabled and the edge would drop below `minimum_ready_cells`.

</template>
</DocTabs>

**Expected result:** the move returns an operation ID, the placement shows `deploying`, then `draining`, then `active`, and the domain keeps answering the whole time.

## If a move does not finish

- `pool_cell_domain_capacity_exhausted`: the target cells are full. Raise `maximum_domains_per_cell` or assign more cells.
- `pool_insufficient_participating_cells`: the pool has fewer cells on an edge than its replica policy needs. Assign more cells.

Neither error withdraws the source. If a target stays unready, check the cell heartbeat status, endpoint readiness, artifact rejection, and the gateway candidate-rejection metrics. Never clear the source runtime by hand.

## Where the state lives

Placement uses durable rows in PostgreSQL. Edge snapshots, per-cell runtime files, gateway maps, and cache contents are derived and can be rebuilt. Adding unrelated domains or cells does not reshuffle valid assignments.
