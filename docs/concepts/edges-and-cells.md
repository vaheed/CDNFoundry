---
title: Edges, pools, and cells
description: Understand CDNFoundry edge enrollment, runtime cells, placement, and isolation.
---

# Edges, pools, and cells

```mermaid
flowchart TB
    Domain["Domain desired state"] --> Placement["Stable placement"]
    Placement --> Pool["shared, quarantine, or dedicated pool"]
    Pool --> EdgeA["Edge A"]
    Pool --> EdgeB["Edge B"]
    EdgeA --> SharedA["Shared cell"]
    EdgeA --> QuarantineA["Quarantine cell"]
    EdgeB --> SharedB["Shared cell"]
    EdgeB --> QuarantineB["Quarantine cell"]
    SharedA -->|"assigned domains as data"| RuntimeA["One OpenResty runtime"]
    SharedB -->|"assigned domains as data"| RuntimeB["One OpenResty runtime"]
```

An edge is one enrolled agent identity and host. A pool is a stable service
class: `shared`, `quarantine`, or exceptional `dedicated`. A cell is the bounded
OpenResty runtime for one pool on one edge.

The shipped production profile creates two cells per edge host:

- `shared-default`, with 2 GiB memory and 2 CPU limits;
- `quarantine-default`, with 512 MiB memory and 0.5 CPU limits.

The edge agent is separate, read-only, non-root, and limited to 128 MiB and 0.25
CPU. It owns identity, artifact validation, atomic runtime files, acknowledgements,
and control tasks. It does not proxy customer traffic.

## Enrollment

An administrator creates an edge with name, country, continent, IPv4, and
optional IPv6. The returned UUID and bootstrap token are shown once. The agent
submits a CSR to the mutual-TLS edge-control endpoint. On success it persists
the issued identity and no longer needs the bootstrap token.

Exact registration retries can reuse a pending key and CSR. A different CSR for
the same consumed token fails closed. Identity rotation invalidates the old
certificate and creates a new one-time boundary.

## Artifact activation

The agent polls a manifest with its active sequence, fetches up to 500 artifact
entries per response, verifies Ed25519 signatures and SHA-256 checksums, compiles
per-pool runtime JSON, and switches the active directory atomically. A full
recovery snapshot is gzip compressed and bounded to 96 MiB on download.

Acknowledgements are persisted locally and retried. Runtime status drives
listener readiness, capacity, passive-origin failure summaries, and security
events.

## Placement

A domain has one active pool and may have one target pool during movement.
Target cells receive and acknowledge the current revision first. DNS then
publishes the target; the source remains active for the configured drain
interval. This avoids withdrawing the only valid route.

The transition is target-first: render and activate the destination, observe
listener readiness, switch derived routing, then drain the source. A failed
destination leaves the source active.

::: warning Protection boundary
Cell CPU, memory, PID, connection, and rate limits contain application-level
resource use. They cannot scrub volumetric traffic after a host or uplink is
saturated.
:::

See [Edges and placement](/guides/edges) and [Edge-agent API](/reference/api/edge-agent).
