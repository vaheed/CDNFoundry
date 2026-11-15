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
| Cached static objects, WAF off | 4 vCPU | 8 GB | about 900 requests per second (3,500 in a lighter test) | Measured |
| Cached static objects, WAF on (balanced) | 4 vCPU | 8 GB | about 450 requests per second | Estimate from a 2 CPU cell at 240 |
| Cache MISS to a 40 ms origin | 4 vCPU | 8 GB | about 190 requests per second at 8 connections | Measured, origin bound |
| DNS answers | shares the same 4 vCPU | none extra | about 5,000 queries per second clean, 16,000 peak | Measured |

Rules of thumb:

- **WAF off:** about 225 requests per second per vCPU, all services included. Plan with this.
- **WAF on:** about 110 requests per second per vCPU. The WAF is the dominant cost; plan CPU around it.
- **Cell size:** give each cell at least 2 CPUs and 1 GB. A 0.5 CPU cell served 130 requests per second with the WAF off and 25 with it on. Set `EDGE_CELL_CPUS` and `EDGE_CELL_MEMORY`.
- **Connections:** 128 concurrent connections were served cleanly. Higher counts were not measured.
- **Domains:** one domain uses one cell per edge. 601 domains deployed at about 1.5 per second. The edge agent needs about 19 KB of memory per domain and a cell about 35 KB per domain per worker. Raise the agent's 256 MiB limit above 10,000 domains per host, and give cells 2 GB with 4 workers at that scale (estimate).
- **Large objects:** 1 MB to 100 MB files cost about a tenth of a core at 34 MB/s. The staging network could not go faster, so the cell's own limit was not reached.
- **New TLS connections:** about 50 per second per client address, set by the gateway.
- **Two edge hosts:** requests per second added up almost exactly (850 plus 897).
- **Swap:** none on edge hosts.

## DNS host

DNS shares the edge host. At 16,000 queries per second DNSdist used about 1.1 cores and PowerDNS about 0.8 cores, so budget 2 vCPU per 15,000 queries per second and 1 GB of RAM. Measured.

## Control plane

| Deployment | Control host | Database host | Telemetry host | Monitoring host | Status |
| ---------- | ------------ | ------------- | -------------- | --------------- | ------ |
| Up to 1,000 domains | 8 vCPU, 16 GB, 100 GB SSD | same host | same host | same host | Estimate |
| 50,000 domains | 8 vCPU, 16 GB, 200 GB NVMe | 4 vCPU, 8 GB, 200 GB NVMe | 8 vCPU, 32 GB, 1 TB NVMe | 4 vCPU, 8 GB, 100 GB SSD | Estimate |

What the staging measurements support:

- **Domains barely cost anything in the database.** 50,005 domains and 150,000 records took 145 MB, and API speed was the same as with 1,000 domains.
- **The control host is limited by CPU, not memory.** The 4 vCPU host averaged 58 % CPU at idle. The scheduler alone uses half a core to a full core.
- **API capacity on that host was 7 to 10 requests per second.** Moving ClickHouse and the DNS database off the control host is what frees CPU for the API.
- **Adding PHP workers did not help.** Add CPU, not workers.
- **Telemetry storage:** about 49 bytes per request event and 24 bytes per DNS event, compressed. For 100 requests per second that is about 0.4 GB a day.

## DNS at 50,000 domains

- **Serving:** zone count did not change query speed. One edge host handled the same 16,000 queries per second with 1,003 zones as with 3.
- **Memory:** about 8 KB per zone, so plan 0.5 GB extra on each DNS host for 50,000 zones (estimate).
- **Onboarding is the slow part.** The primary's database wrote about 1.6 zones per second, so loading 50,000 zones takes about 9 hours. After that, normal changes are small.

## Edge at 50,000 domains

Domain count was not measured on the edge. Size the edge by requests per second from the tables above, not by domains. Spread domains over cells; one domain stays on one cell per edge.

## Monitoring host

Under 3,000 requests per second at the edge, Prometheus, Grafana, Loki and the exporters together used about 14 % of one core and 0.5 GB. ClickHouse used 57 % of a core on average and is the real consumer. 4 vCPU and 8 GB is a safe estimate for 50,000 domains.

## Not measured

Cell bandwidth above 34 MB/s, TLS handshake capacity of a cell (the gateway limits one client), edge memory above 601 domains, and more than two edge hosts.
