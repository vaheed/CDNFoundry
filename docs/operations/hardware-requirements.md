---
title: Hardware requirements
description: How much CPU and RAM a CDNFoundry edge, DNS and control host needs for a given request rate, based on staging measurements.
---

# Hardware requirements

Use this page to size hosts. The numbers come from the staging measurements in [Staging qualification, 2026-11-15](staging-qualification-2026-11-15.md), taken on 4 vCPU Intel Xeon E5-2697 v4 virtual machines with a 10 Gbit/s NIC. Rows marked **estimate** were not measured at that size.

## Edge host

One edge host runs the cells, the gateway, the edge agent, DNS and the log shipper.

A request is served by one cell, and one domain lives on one cell per edge host. So the speed a domain gets is the speed of its cell, which is set by the cell's CPU limit, not by the host's CPU. The default cell limit is 0.5 CPU.

| Cell CPU limit | WAF | Requests per second for one domain | Status |
| --- | --- | --- | --- |
| 0.5 CPU (default) | off | about 130 | Measured |
| 0.5 CPU (default) | balanced | about 25 | Measured |
| 2 CPU | off | 900 to 3,300 | Measured, the two runs disagree and the cause is unknown |
| 2 CPU | balanced | about 240 | Measured |
| 4 CPU | off | 900 to 3,500, and the host was 98 % busy | Measured, same disagreement |
| 4 CPU | balanced | not measured | The client was banned at high rates |

What the host adds on top of the cells:

- The gateway, Vector, the log collector, the agent and Docker used about 1.5 to 2 CPUs at 900 to 3,500 requests per second. A 4 vCPU host therefore has about 2 CPUs left for cells.
- Eight default cells reserve 4 CPUs on a 4 vCPU host. That oversells the host, which is fine at low traffic and not at high traffic.
- Cache MISS to a 40 ms origin: about 190 requests per second at 8 connections, limited by the origin. Measured.
- DNS shares the host: about 5,000 queries per second clean, 16,000 peak. Measured.

Rules of thumb:

- **WAF off:** plan with 900 requests per second per 4 CPU cell, the lower of the two measurements.
- **WAF on:** about 120 requests per second per CPU of cell limit (240 on a 2 CPU cell). The WAF is the dominant cost; plan cell CPU around it.
- **Many domains:** each domain gets its own cell's speed, so a host with eight 2 CPU cells and enough spare CPU can carry several times one cell's rate, as long as the domains land on different cells. This was not measured with several busy domains at once.
- **Cell size:** give each cell at least 2 CPUs and 1 GB, and size the host so the cells' CPU limits plus about 2 CPUs of overhead fit in it. Set `EDGE_CELL_CPUS` and `EDGE_CELL_MEMORY`.
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
