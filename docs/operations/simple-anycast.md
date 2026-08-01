---
title: Simple Anycast pools
description: Operate pool-owned service pairs without giving CDNFoundry control of BGP.
---

# Simple Anycast pools

Simple Anycast lets an approved service pool use one public IPv4 address and
optional IPv6 address across several explicitly attached edges or POPs. The
pool owns the pair in PostgreSQL. Each edge endpoint is only a participation,
readiness, revision, and withdrawal record; it does not duplicate the pair.

::: danger CDNFoundry is not a BGP controller
CDNFoundry maps the advertised service identities to operator-provisioned local
gateway addresses and publishes the service identities in authoritative DNS.
It never installs FRR or BIRD, stores router credentials,
runs arbitrary network commands, announces a prefix, or withdraws a BGP route.
The network operator or provider must approve the prefix and independently
advertise, steer, withdraw, restore, monitor, and record routing changes.
:::

## Provision a pool

The model is deliberately one-to-one: **one distinct Anycast IPv4/optional
IPv6 pair equals one service pool**. Two different address pairs require two
pools. Choose `shared` for normal CDN traffic. `reserved`, `dedicated`, and
`quarantine` change workload isolation only; they do not change Anycast routing,
select edges, or reserve cells automatically.

Pool and edge creation consume no cells. Every cell and participating edge is
an explicit operator choice. With eight slots on an edge, one pool may use any
chosen number of those slots. More than eight pools may exist, but an eight-slot
edge can participate in at most eight pools when each uses one cell. Pools that
do not participate on that edge consume zero slots there.

1. In **Edge network → Service pools**, create a bounded pool and select
   **Simple Anycast**. Creation only saves the disabled pool and address pair.
2. Enter the approved public IPv4 address and, when routed, IPv6 address. At
   least one family is required. Private, special-purpose, management, existing
   Geo-Unicast, and other Anycast addresses are rejected.
3. On each intended edge, use **Cells → Assign service pool** to assign at
   least `minimum_ready_cells` slots to the pool. Assign more only when that
   edge should give the pool more local capacity. Assigning the first cell
   automatically creates that edge's participation and inherits the pool pair;
   there is no Anycast endpoint form.
4. Confirm the automatically created participation row shows the pool's IPv4
   and optional IPv6 pair. Additional cells on the same edge reuse that row.
5. Enable the pool after all intended endpoints and cell assignments exist. Confirm the
   same pair appears in each edge gateway candidate and becomes `ready` only
   after local gateway acknowledgement.
6. On each edge, assign distinct local addresses, configure the approved
   routing or layer-4 translation, and map every service address through
   `EDGE_GATEWAY_ADDRESS_MAP`. The public Anycast pair is never a host bind.
7. Have the network operator advertise the approved prefix. Verify routes and
   real HTTP/HTTPS traffic from independent external vantage points before
   assigning production domains.

Routing mode cannot change while a pool is enabled or has endpoints. Withdraw
and remove endpoint participation first. Pool address edits increment endpoint
candidates and return them to `pending` until gateways acknowledge the new
revision.

There is no fleet-wide reconcile or automatic assignment step. Creating a new
edge also leaves all of its slots unassigned. Automation is limited to the
selected edge: assigning its first Anycast cell creates participation, and
unassigning its final cell while the pool is disabled removes participation.

To delete a pool, disable it, move away every domain, and unassign every cell.
For Geo-Unicast, also withdraw and remove its manual endpoints. The guarded
**Delete** action then removes the empty pool.

## Signals and failure behavior

`ready` means every attached endpoint is locally ready. `degraded` means at
least one is ready and at least one attached POP is not. `withdrawn` means the
pool is disabled/withdrawn or no attached endpoint is ready. DNS publishes the
pair while at least one endpoint remains ready, so one POP failure does not
rewrite the pair or corrupt another POP's local state.

CDNFoundry withdrawal removes gateway candidates and DNS publication; it does
not withdraw the external route. Coordinate this with the operator/provider,
record timestamps and route evidence, then verify withdrawal and restoration
from multiple external networks. Never infer BGP health solely from the
control-plane `ready` signal.

## Capacity and DDoS boundary

Anycast can distribute reachable traffic only while routes, transit, uplinks,
gateway capacity, and healthy cells remain available. CDNFoundry provides no
upstream volumetric scrubbing and cannot protect an already saturated physical
uplink. Set provider alerts and accepted saturation thresholds, retain
Geo-Unicast comparison traffic, and rehearse provider-operated withdrawal.
