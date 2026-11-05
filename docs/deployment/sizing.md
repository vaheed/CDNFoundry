---
title: Sizing guide
description: Plan host counts and sizes for a small, medium, or large private CDN, and measure your own hardware before you commit capacity.
---

# Sizing guide

CDNFoundry does not publish throughput numbers. Capacity depends on your
hardware, network cards, storage, request mix, TLS rate, and origins, so any
number from another site would be a guess. This page gives you three things: a
starting layout for each size, the hard limits the software sets for itself,
and a method to measure your own capacity.

## Pick a starting layout

| Size | Layout | Use it when |
| --- | --- | --- |
| Small | One control and telemetry host, and two combined DNS and edge hosts in separate failure domains | You serve a few sites from one or two regions |
| Medium | Separate DNS hosts, three or more edge PoPs in two regions, a standby control node | Several regions, or DNS load and edge load grow at different rates |
| Large | Many PoPs per region, dedicated ClickHouse hosts, external replicated PostgreSQL and Valkey | Telemetry volume or control load outgrows one host |

The small layout is the minimum for production. One server is enough to
evaluate the product, but it has no failure isolation. For the diagrams of each
layout, see [Production reference architectures](../architecture/production-reference-architectures.md).

## Know the limits the software sets

These come from the shipped Compose files. They protect a host from one noisy
service. They are safety limits, not performance promises.

| Service | Memory | CPU | Notes |
| --- | --- | --- | --- |
| OpenResty cell | 512 MiB | 0.5 | Eight cells per edge host; the count is fixed |
| Edge agent | 256 MiB | 0.25 | Never carries customer traffic |

Other limits, such as request body size, connections per client, and cache
admission, are listed in [Limits](../reference/limits.md).

An edge host needs enough memory and CPU for all of its cells, plus its
gateway and agent. Add the cell limits, then leave room for the operating
system and the page cache.

## Measure before you commit

1. **Pick the unit that is short.** Do not size everything at once. Use the
   [scaling guide](../operations/scaling.md) to find the saturated resource.
2. **Measure each plane on its own.** DNS: queries per second and latency. Edge:
   requests per second, connections, TLS handshakes per second, bandwidth, and
   CPU. Cache: hit ratio and disk latency. Control: queue age and reconcile time.
   Telemetry: events per second and insert latency.
3. **Use your real request mix.** Object size, TLS share, and cache hit ratio
   change the answer more than CPU model does.
4. **Run the throughput test on your hardware.** `python3 tests/e2e/cell_throughput.py`
   drives one cell with HTTP and HTTPS and prints the result with the host
   details. Run it on the host you plan to buy more of.
5. **Keep headroom.** Plan to run at well under the measured limit, so that
   losing one PoP does not overload the others.

## Add capacity

- More edge traffic: add an edge host, or more cells on a host.
  See [Scaling](../operations/scaling.md#add-an-edge-only-host).
- More DNS load: add a DNS host.
- More telemetry: move ClickHouse to its own host.
- A bigger control plane: add worker capacity for the busy queue, or move
  PostgreSQL and Valkey to replicated hosts you operate.

Never add a process, container, or timer per domain.

## Check your plan

Before you buy hardware, confirm:

- [ ] Losing any one host leaves DNS and HTTP serving.
- [ ] Each plane has a measured limit from your own hardware.
- [ ] Peak traffic is under that limit with one PoP down.
- [ ] Backups and ClickHouse retention fit the disks you chose.
