---
title: Pool service endpoints and Geo-Unicast
description: Operate revisioned public endpoint pairs backed by bounded cells.
---

# Pool service endpoints and Geo-Unicast

An edge/pool endpoint owns one public IPv4 address, one public IPv6 address, or
one of each. The addresses are distinct from the edge management addresses.
Several cells assigned to the same pool and edge are private gateway targets;
they do not own additional public addresses.

Create endpoints under **Edge network → Edges → Pool endpoints** after assigning
the pool's cells. Address ownership is globally unique and unsafe, management,
duplicate, and empty pairs fail before desired state changes. Endpoint edits
increment the endpoint revision and return it to `pending` until the gateway
reports the active revision.

## Publication and fallback

System DNS publishes an endpoint only when the pool and edge are enabled and
not withdrawn, the edge heartbeat and gateway listener are fresh, the pool has
its configured minimum ready cells on that edge, and the gateway has
acknowledged the endpoint revision. Country answers fall back to continent and
then global answers. IPv4 and IPv6 are evaluated independently, so IPv4-only,
IPv6-only, and dual-stack endpoints are valid.

Withdrawing one endpoint removes only that edge/pool pair. Pool withdrawal
removes every endpoint for that pool but does not alter other pools on the same
edge. DNS reconciliation is coalesced and retains the last valid PowerDNS zone
if rendering or activation fails.

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
