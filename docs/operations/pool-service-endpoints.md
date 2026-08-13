---
title: Pool service endpoints and Geo-Unicast
description: Operate revisioned public endpoint pairs backed by bounded cells.
---

# Pool service endpoints and Geo-Unicast

::: warning An endpoint is not ready when merely saved
DNS publication waits for an enabled pool and edge, fresh heartbeat, sufficient
ready cells, a ready gateway, and acknowledgement of the endpoint revision.
Do not publish endpoint addresses manually to bypass these gates.
:::

For one pool-owned pair shared across explicitly attached POPs, use
[Simple Anycast pools](simple-anycast.md).

An edge/pool endpoint owns one public IPv4 address, one public IPv6 address, or
one of each. An edge management address is optional, may be private, and is
used for operator inventory/management rather than public traffic. Management
addresses cannot be reused as service endpoint addresses.
Several cells assigned to the same pool and edge are private gateway targets;
they do not own additional public addresses.

Endpoint addresses are advertised identities, never production host bind
addresses. Before enabling an endpoint, allocate one distinct local address
per advertised address, configure one-to-one DNAT or layer-4 forwarding, and
record the pair in that host's `EDGE_GATEWAY_ADDRESS_MAP`. The agent rejects an
unmapped endpoint and keeps the previous valid gateway map.

Create endpoints under **Edge network → Edges → Pool endpoints** after assigning
the pool's cells. Address ownership is globally unique and unsafe, management,
duplicate, and empty pairs fail before desired state changes. Endpoint edits
increment the endpoint revision and return it to `pending` until the gateway
reports the active revision.

The first endpoint may be created while the edge has no customer domains. The
agent activates an empty runtime for each assigned cell, then publishes the
gateway listener generation. If the service address is the host's only public
address, leave the optional edge management address blank rather than reusing
the service address as management inventory.

An endpoint with no non-drained cell assigned to its pool remains saved but is
omitted from the gateway candidate. It cannot poison the edge's previous valid
gateway generation and remains ineligible for DNS publication. Assign at least
one cell on the same edge to the same pool before expecting a listener.

## Publication and fallback

System DNS publishes an endpoint only when the pool and edge are enabled and
not withdrawn, the edge heartbeat and gateway listener are fresh, the pool has
its configured minimum ready cells on that edge, and the gateway has
acknowledged the endpoint revision. Country answers fall back to continent and
then global answers. IPv4 and IPv6 are evaluated independently, so IPv4-only,
IPv6-only, and dual-stack endpoints are valid.

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
`insufficient_ready_cells`, `heartbeat_stale`, `edge_unavailable`, and
`withdrawn` before forcing reconciliation. Never publish endpoint addresses
manually in customer zones.
