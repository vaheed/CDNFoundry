---
title: Hardware requirements
description: How much CPU and RAM a CDNFoundry edge, DNS and control host needs for a given request rate, based on staging measurements.
---

# Hardware requirements

Use this page to size hosts. The numbers come from the staging measurements in [Staging qualification, 2026-11-15](staging-qualification-2026-11-15.md), taken on 4 vCPU Intel Xeon E5-2697 v4 virtual machines with a 10 Gbit/s NIC. Rows marked **estimate** were not measured at that size.

## Edge host

One edge host runs the cells, the gateway, the edge agent, DNS and the log shipper.

| Traffic | CPU | RAM | Handles | Status |
| --- | --- | --- | --- | --- |
| Cached static objects, WAF off | 4 vCPU | 8 GB | about 3,500 requests per second | Measured |
| Cached static objects, WAF on (balanced) | 4 vCPU | 8 GB | about 450 requests per second | Estimate from a 2 CPU cell at 240 |
| Cache MISS to a 40 ms origin | 4 vCPU | 8 GB | about 190 requests per second at 8 connections | Measured, origin bound |
| DNS answers | shares the same 4 vCPU | none extra | about 5,000 queries per second clean, 16,000 peak | Measured |

Rules of thumb:

- **WAF off:** about 900 requests per second per vCPU, all services included.
- **WAF on:** about 110 requests per second per vCPU. The WAF is the dominant cost; plan CPU around it.
- **Cell size:** give each cell at least 2 CPUs and 1 GB. A 0.5 CPU cell served 130 requests per second with the WAF off and 25 with it on. Set `EDGE_CELL_CPUS` and `EDGE_CELL_MEMORY`.
- **Connections:** 128 concurrent connections were served cleanly. Higher counts were not measured.
- **Domains:** one domain used one cell per edge. Domains per host were not measured beyond one.
- **Swap:** none on edge hosts.

## DNS host

DNS shares the edge host. At 16,000 queries per second DNSdist used about 1.1 cores and PowerDNS about 0.8 cores, so budget 2 vCPU per 15,000 queries per second and 1 GB of RAM. Measured.

## Control host

| Size    | CPU    | RAM   | Disk       | Status   |
| ------- | ------ | ----- | ---------- | -------- |
| Minimum | 8 vCPU | 16 GB | 100 GB SSD | Estimate |

The staging control host (4 vCPU, 4 GB) averaged 58 % CPU and used swap at idle, so it is too small. Put ClickHouse, Vector and Prometheus on a separate host for production. Control-plane and database load were not measured.

## Not measured

Large objects and bandwidth, TLS handshake rate, domains per host, database and ClickHouse sizing, and anything above one edge host.
