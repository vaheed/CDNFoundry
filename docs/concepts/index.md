---
title: Product concepts
description: Learn the vocabulary and invariants shared across CDNFoundry.
---

# Product concepts

::: info Read desired state first
Most changes finish later, in the background. Learn the difference between
desired state (what you asked for) and active state (what runs). Then you will not
mistake an accepted request for a finished change.
:::

Read these short pages before you change production state:

- [CDN fundamentals](cdn-fundamentals.md) explains DNS, TLS, edges, origins,
  caching, placement, and what a CDN cannot do.
- [How CDNFoundry works](how-cdnfoundry-works.md) follows one domain from
  a saved request to customer traffic.
- [Desired state](desired-state.md) explains why PostgreSQL is the source of truth and what is derived from it.
- [Domains and DNS](domains-and-dns.md) explains the domain lifecycle, delegation, record modes, and proxy publication.
- [Edges and cells](edges-and-cells.md) explains pools, cells, placement, and isolation.
- [Revisions and operations](revisions-and-operations.md) explains background work, acknowledgement, retry, and rollback.

One rule runs through all of them. A request is checked and saved as desired
state. Later, a worker with fixed limits brings the runtime in line with that
state. Customer traffic never waits for Laravel and never passes through it.

Read the pages in the order above. Then use
[Using CDNFoundry](../getting-started/using-cdnfoundry.md) as your task map.
