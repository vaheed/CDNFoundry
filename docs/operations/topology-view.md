---
title: Topology view
description: See the control plane, DNS, PoPs, cells, and telemetry as one health-colored graph.
---

# Topology view

**Observe → Topology** in the administrator panel draws the fleet as one graph:
control (active and standby), DNS clusters, PoPs, their cells, and the
telemetry path (Vector and ClickHouse). It refreshes every ten seconds and shows
**Polling active** while it does.

## Reading it

| Colour and word | Meaning |
| --- | --- |
| Healthy | Fresh heartbeat or a passing check. |
| Degraded | Reachable, but a cell or component is not ready. |
| Failed | A stale PoP heartbeat, an unhealthy DNS cluster, or a failed telemetry check. |
| Pending | A drained PoP, or a cell that is assigned but not ready yet. |
| Disabled | Switched off on purpose. |
| Unknown | No report yet. |

A link takes the worse status of its two ends, so a stopped PoP turns its link
failed too. Search dims every node that does not match. The zoom buttons scale
the drawing from 50% to 200%. A screen reader gets the same nodes as a list.

## Limits

The graph draws at most 200 PoPs and 8 cells per PoP; the PoP's detail line
shows the real cell count when more exist, and a note appears when PoPs were
left out. The data comes from PostgreSQL and the same health probes as
`GET /api/admin/system/components`, so it never waits on a runtime change.

## API

`GET /api/admin/system/topology` returns the same nodes and links
(`id`, `kind`, `label`, `status`, `detail`) for administrators only. The local
fleet web reuses this payload.
