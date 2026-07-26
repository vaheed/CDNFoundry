---
title: Product concepts
description: Learn the vocabulary and invariants shared across CDNFoundry.
---

# Product concepts

Read these short concept guides before changing production state:

- [Desired state](/concepts/desired-state) explains PostgreSQL ownership and derived runtime data.
- [Domains and DNS](/concepts/domains-and-dns) explains lifecycle, delegation, records, and proxy publication.
- [Edges and cells](/concepts/edges-and-cells) explains pools, cells, placement, and isolation.
- [Revisions and operations](/concepts/revisions-and-operations) explains asynchronous work, acknowledgement, retry, and rollback.

The common rule is simple: a request validates and commits desired state; a
bounded worker later reconciles that state to an external runtime. Serving
traffic never waits for Laravel and never passes through it.
