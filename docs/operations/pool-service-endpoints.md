---
title: Pool service endpoints and Geo-Unicast
description: Operate revisioned public endpoint pairs backed by bounded cells.
---

# Pool service endpoints and Geo-Unicast

::: warning An endpoint is not ready when merely saved
A new endpoint is published only after its pool and edge are enabled, its cells
are ready, its gateway is ready, and the gateway acknowledged the endpoint
revision. Do not publish endpoint addresses manually to bypass these gates.
:::

For one pool-owned pair shared across explicitly attached POPs, use
[Simple Anycast pools](simple-anycast.md).

An edge/pool endpoint owns one public IPv4 address, one public IPv6 address, or
one of each. Several cells assigned to the same pool and edge are private gateway targets;
they do not own additional public addresses.

Endpoint addresses are advertised identities. A directly assigned address may
bind as-is without `EDGE_GATEWAY_ADDRESS_MAP`. For NAT or a load balancer,
allocate one distinct private local listener per advertised address, configure
one-to-one forwarding, and record the pair in the host map. Invalid translations
are rejected while the previous valid gateway map remains active.

Create endpoints under **Edge network → Edges → Pool endpoints** after assigning
the pool's cells. Address ownership is globally unique and unsafe,
duplicate, and empty pairs fail before desired state changes. Endpoint edits
increment the endpoint revision and return it to `pending` until the gateway
reports the active revision.

The first endpoint may be created while the edge has no customer domains. The
agent activates an empty runtime for each assigned cell, then publishes the
gateway listener generation.

An endpoint with no non-drained cell assigned to its pool remains saved but is
omitted from the gateway candidate. It cannot poison the edge's previous valid
gateway generation and remains ineligible for DNS publication. Assign at least
one cell on the same edge to the same pool before expecting a listener.

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
PoP's own endpoint addresses every 15 seconds and reports the result over mTLS.
A PoP is withdrawn only when at least `edge_runtime.probe_min_vantage_points`
(default 2) other PoPs reported within `edge_runtime.probe_result_max_age_seconds`
(default 180) and more than half of them failed. A fleet with fewer than two
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
