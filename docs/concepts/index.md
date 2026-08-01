---
title: Product concepts
description: Learn the vocabulary and invariants shared across CDNFoundry.
---

# Product concepts

::: info Read desired state first
Most mutations are asynchronous. Understanding desired versus active state
prevents an accepted API request or queued operation from being mistaken for a
runtime deployment acknowledgement.
:::

Read these short concept guides before changing production state:

- [CDN fundamentals](cdn-fundamentals.md) explains DNS, TLS, edges, origins,
  caching, placement, and the limits of a CDN.
- [How CDNFoundry works](how-cdnfoundry-works.md) follows one domain from
  desired state through activation and customer traffic.
- [Desired state](desired-state.md) explains PostgreSQL ownership and derived runtime data.
- [Domains and DNS](domains-and-dns.md) explains lifecycle, delegation, records, and proxy publication.
- [Edges and cells](edges-and-cells.md) explains pools, cells, placement, and isolation.
- [Revisions and operations](revisions-and-operations.md) explains asynchronous work, acknowledgement, retry, and rollback.

The common rule is simple: a request validates and commits desired state; a
bounded worker later reconciles that state to an external runtime. Serving
traffic never waits for Laravel and never passes through it.

New operators should read the pages in the order shown above, then use
[Using CDNFoundry](../getting-started/using-cdnfoundry.md) as the task map.
