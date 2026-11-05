---
title: Choose your installation
description: Pick the right CDNFoundry layout for your size, from one test machine to a multi-region fleet, and see which guide to follow for each.
---

# Choose your installation

CDNFoundry scales from one laptop to many regions with the same software. This
page helps you pick a layout. Each layout links to the guide that installs it.

**Read this first** if you are not sure how many servers you need.

## The layouts

| Layout | Servers | Use it for | Failure isolation | Follow |
| --- | --- | --- | --- | --- |
| **Lab (one machine)** | 1, any Linux or Mac with Docker | Trying it, learning, development | None | [Installation](../getting-started/installation.md) |
| **Starter production** | 3: one control and monitoring node, two combined DNS and edge nodes | A first real CDN, a few sites, two regions | Survives losing one PoP | [Production quick start](production-quick-start.md) |
| **Growing fleet** | 3 to about 10: more PoPs and DNS hosts added one at a time | More regions or more traffic | One PoP or DNS host at a time | [Scaling](../operations/scaling.md) |
| **Highly available control** | Starter or larger, plus a standby control node | When losing the control plane for hours is not acceptable | Also survives losing the control node | [Control-plane high availability](../operations/control-plane-high-availability.md) |
| **Multi-region** | 15 or more: separate control, DNS, edge, and monitoring hosts in several regions | A large private CDN or an ISP | Region by region | [Multi-region quick start](production-quick-start-multi-region.md) |
| **Anycast** | Any production layout, plus your own network routing | One address announced from many PoPs | PoP level, by routing | [Simple Anycast](../operations/simple-anycast.md) |
| **Without Fleet** | Any of the above, installed by hand | When you cannot use Fleet or want full control | Same as the layout | [Manual Docker Compose](manual-compose.md) |

## There is no single-server production install

One server is enough to try CDNFoundry, but it is not a CDN: if that server stops,
DNS and traffic stop with it. The smallest layout we call production has three
servers in separate failure domains, so that one can fail and the others keep
answering. See the [sizing guide](sizing.md).

## Choose by your need

1. **Just want to see it work?** Use the lab. It takes one machine and no
   domain.
2. **Serving real traffic for a small team?** Use starter production. It is the
   smallest layout with a control node and two PoPs.
3. **More than two regions, or more than one PoP per region?** Start with
   starter production and add hosts with [Scaling](../operations/scaling.md).
   You do not need to reinstall.
4. **Must the control plane survive a lost host?** Add a standby. It works with
   any production layout.
5. **Running a network of your own?** Use the multi-region layout, and add
   Anycast if you announce addresses.
6. **Do your policies forbid a deployment tool?** Use the manual Compose guide.
   It describes the same result by hand.

## Options that apply to every layout

| Option | What it does | Where |
| --- | --- | --- |
| **Embedded or external PostgreSQL** | Keep the control database on the control node, or point to a replicated database you run | [Fleet operator guide](production-fleet-operator-guide.md#embedded-or-remote-control-postgresql) |
| **Dedicated monitoring node** | Move Prometheus, Grafana, Loki, and ClickHouse to their own host | [Topology](topology.md) |
| **Encrypted backups** | Back up the control plane to an S3-compatible store | [Backup and recovery](../operations/backup-and-recovery.md) |
| **IPv6** | Publish DNS, control, and edges on IPv6 beside IPv4 | [Production quick start](production-quick-start.md) |
| **Centralized logs** | Send operational logs from every host to one place | [Operational logging](../operations/operational-logging.md) |

## What you need before any production layout

- Linux servers you control, with root SSH access
- a domain for your platform and one for management, with an independent DNS
  provider for the management names
- public IPv4 addresses, and IPv6 if you want it
- a workstation to run Fleet

The [production guide](production-guide.md) walks through all of it in order.
