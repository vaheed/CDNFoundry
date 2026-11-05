---
title: Pool service endpoints and Geo-Unicast
description: Create, withdraw, and troubleshoot the public IPv4 and IPv6 address of a service pool on an edge, and see when DNS publishes it.
---

# Pool service endpoints and Geo-Unicast

A **pool service endpoint** is the public address of one service pool on one edge. In Geo-Unicast routing, each point of presence (PoP) has its own address, and DNS answers each client with a nearby one. This page shows how to create an endpoint, when DNS publishes it, how to take it out of traffic, and what to check when it stays unready.

**You need:** administrator access, an edge with at least one cell assigned to the pool (see [multi-cell pools](multi-cell-pools.md)), and a public IPv4 address, IPv6 address, or both, that this edge owns.

::: warning An endpoint is not ready when merely saved
DNS publishes a new endpoint only after the pool and edge are enabled, the pool's cells are ready, the gateway is ready, and the gateway has acknowledged the endpoint revision. Do not put endpoint addresses into DNS by hand to skip these checks.
:::

For one address pair that several PoPs share, use [Simple Anycast pools](simple-anycast.md) instead.

## What an endpoint is

An endpoint holds one public IPv4 address, one public IPv6 address, or one of each. Several cells in the same pool and edge are private gateway targets. They do not get their own public addresses.

Endpoint addresses are advertised identities. If the host owns the address directly, the gateway can bind it as it is, with no `EDGE_GATEWAY_ADDRESS_MAP`. Behind NAT or a load balancer, do three things:

1. Pick one distinct private local listener address for each advertised address.
2. Forward each advertised address to its listener, one to one.
3. Record each pair in the host's `EDGE_GATEWAY_ADDRESS_MAP`.

The agent rejects an invalid translation. The previous valid gateway map stays active.

## Create an endpoint

Open **Infrastructure → Edges**, open the edge, and choose the **Pool endpoints** tab. Create the endpoint after you assign the pool's cells.

**Expected result:** the endpoint appears with a pending gateway state and the reason `gateway_not_acknowledged`. After the agent activates the gateway map, the **Active revision** matches the **Desired revision** and the readiness reads `Ready`.

Address ownership is globally unique. An unsafe address, a duplicate address, or an empty pair fails before any desired state changes. Each edit raises the endpoint revision and returns it to `pending` until the gateway reports that revision as active.

You may create the first endpoint while the edge has no customer domains. The agent activates an empty runtime for each assigned cell, then publishes the gateway listener generation.

An endpoint whose pool has no non-drained cell on that edge stays saved. The gateway candidate leaves it out, so it cannot damage the previous valid gateway generation, and DNS does not publish it. Assign at least one cell on the same edge to the same pool before you expect a listener.

## Publication and fallback

System DNS publishes an endpoint when the pool and edge are enabled and not
withdrawn, the PoP reports a ready gateway listener and its configured minimum
ready cells, and the gateway has acknowledged the endpoint revision. Country
answers fall back to continent and then global answers. IPv4 and IPv6 are
evaluated independently, so IPv4-only, IPv6-only, and dual-stack endpoints are
valid.

### Fail static

A PoP leaves DNS only on a positive signal: an operator drains, disables, or
withdraws it; a quorum of external probes fails; or the PoP itself reports that
its listener or cells are not ready. **Missing heartbeats are never a reason.**
If edge-control, its CA, a certificate, or the network between control and the
PoPs fails, every PoP keeps its published address and the edges keep serving.
Silence raises the `EdgeHeartbeatStale` alert instead.

A pool also never loses its whole answer set to an automatic signal. When every
endpoint of a pool looks unhealthy, DNS keeps the last published set, the pool
is flagged `dns_fail_static`, and the `PoolServingLastPublishedSet` alert fires.
Operator actions still win: draining the last PoP removes it.

### External probes

Every PoP's agent probes the HTTP (80) and TLS (443) listeners of every other
PoP's own endpoint addresses about every 15 seconds and reports the result over mTLS.
A PoP is withdrawn only when at least `edge_runtime.probe_min_vantage_points`
(default 2) other PoPs reported within `edge_runtime.probe_result_max_age_seconds`
(default 180 seconds) and more than half of them failed. A fleet with fewer than two
other PoPs therefore never withdraws a PoP on probes. A probe checks that both
listeners accept connections; it does not fetch customer content. Probes target
the endpoint's own addresses, so for anycast pools set the endpoint address of
each PoP to its unicast listener.

**Temporarily remove from traffic** keeps the endpoint row but removes only that
edge/pool pair from gateway candidates and DNS. Turning it off restores the
pair after a fresh gateway acknowledgement. Pool emergency withdrawal
removes every endpoint for that pool but does not alter other pools on the same
edge. DNS reconciliation is coalesced and retains the last valid PowerDNS zone
if rendering or activation fails.

To remove one address family, edit the endpoint and clear IPv4 or IPv6; at
least one family must remain. To delete the endpoint completely, first enable
**Temporarily remove from traffic**, wait for withdrawal if traffic is live,
then use **Delete**. Deleting an endpoint does not unassign its cells.

## Recovery

The agent fetches its bounded endpoint candidate over the authenticated edge
control channel. It renders listeners and Host/SNI routes deterministically,
validates conflicts, activates atomically, and reports the active revision in
its heartbeat. Invalid candidates preserve the prior gateway map. After an
agent or gateway restart, the same desired endpoint and domain artifacts
rebuild the candidate; DNS publication resumes only after fresh acknowledgement.

Investigate `gateway_not_acknowledged`, `gateway_not_ready`,
`insufficient_ready_cells`, `probe_failed`, `edge_unavailable`, and
`withdrawn` before forcing reconciliation. `heartbeat_stale` is shown in
monitoring but does not withdraw the endpoint. Never publish endpoint addresses
manually in customer zones.
