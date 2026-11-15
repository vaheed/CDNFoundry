---
title: Hardware requirements
description: How much CPU and RAM a CDNFoundry edge, DNS and control host needs for a given request rate, based on staging measurements.
---

# Hardware requirements

Use this page to size hosts. The numbers come from the staging measurements in [Staging qualification, 2026-11-15](staging-qualification-2026-11-15.md), taken on 4 vCPU Intel Xeon E5-2697 v4 virtual machines with a 10 Gbit/s NIC. Rows marked **estimate** were not measured at that size.

## Edge host

One edge host runs the cells, the gateway, the edge agent, DNS and the log shipper. These numbers are for one host with 4 vCPU (Intel Xeon E5-2697 v4 class), 8 cells, 20 busy proxied domains spread over the cells, a 10 KB cached object, and traffic from many client addresses.

| WAF | Requests per second | Busy connections held cleanly | At 500 busy connections | At 2,000 busy connections |
| --- | --- | --- | --- | --- |
| Off | about 1,100 | 100 (median 90 ms) | same rate, median 0.4 s, 1 % errors | same rate, median 0.6 s, 2 % errors |
| Balanced | about 320 to 350 | 100 (median 300 ms) | about 320, median 1.4 s, 15 % time out | 430, most time out |

All of this is measured. Rules that follow from it:

- **The host is the limit, not the cells.** With eight cells at the default 0.5 CPU or at 2 CPU each, the host served the same rate, and its CPU was 96 % busy either way. Size the host, then let the cells share it.
- **WAF off: about 275 requests per second per vCPU. WAF on: about 80.** The WAF costs roughly 3.5 times the CPU of serving the same cached response.
- **Past the limit, requests queue and time out.** The edge does not return errors; latency rises until clients give up. Plan to run at 60 % of these figures.
- **Busy connections** here means connections that send requests back to back. Idle keep-alive connections cost much less, and their memory cost was not measured.
- **A client address is limited by design.** The gateway allows 256 connections per address and the cell 100 requests per second and 64 connections per address. Real traffic comes from many addresses and is not affected; a test from one machine is. The test lifted those limits.
- **Two edge hosts add up.** 850 and 897 requests per second at the same time, so capacity grows by adding hosts.

### One busy domain

A domain lives on one cell per edge host, so a single busy domain gets only its cell's CPU. On a default 0.5 CPU cell that is about 130 requests per second with the WAF off and about 25 with it on (measured). With a 2 CPU cell it is about 240 with the WAF on. If one domain carries most of your traffic, raise `EDGE_CELL_CPUS` for the cells that serve it.

### Other edge numbers

- **Cache MISS to a 40 ms origin:** about 190 requests per second at 8 connections, limited by the origin.
- **Large objects:** 1 MB to 100 MB files cost about a tenth of a core at 34 MB/s. The staging network could not go faster, so the cell's own limit was not reached.
- **New TLS connections:** about 50 per second per client address, set by the gateway.
- **Domains:** 601 domains deployed at about 1.5 per second. The edge agent needs about 19 KB of memory per domain and a cell about 35 KB per domain per worker. Raise the agent's 256 MiB limit above about 10,000 domains per host, and give cells 2 GB with 4 workers at that scale (estimate).
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

## Calculator

Run the calculator with your peak numbers. It uses the figures on this page and keeps them at the top of the file so you can change them.

```bash
python3 scripts/sizing-calculator.py --peak-rps 600 --waf-share 0.5 --domains 100 --dns-qps 2000 --average-kb 50
```

| Input | Meaning |
| --- | --- |
| `--peak-rps` | Requests per second at your busiest moment, across all domains |
| `--waf-share` | Share of that traffic on domains with the WAF on, from 0 to 1 |
| `--domains` | Number of proxied domains |
| `--connections` | Connections actively sending requests, if you know it |
| `--average-kb` | Average response size, for bandwidth |
| `--dns-qps` | Peak DNS queries per second |
| `--host-vcpu` | vCPU of each edge host, default 8 |
| `--no-ha` | Leave out the spare host |

It adds the CPU for requests and DNS, divides by 60 % utilization, adds one spare host for high availability, and prints the edge hosts, memory, bandwidth, telemetry growth and the control plane size. The result is a planning figure for small cached objects. Large objects, a different CPU, or traffic from few client addresses change it.

Worked examples, with the WAF off or on for every domain:

| Peak requests per second | WAF | Edge hosts of 8 vCPU (with one spare) | Edge CPU needed |
| --- | --- | --- | --- |
| 500 | off | 2 | 3 vCPU |
| 500 | on | 3 | 10 vCPU |
| 2,000 | off | 3 | 12 vCPU |
| 2,000 | on | 7 | 42 vCPU |

## Not measured

Cell bandwidth above 34 MB/s, TLS handshake capacity of a cell (the gateway limits one client), edge memory above 601 domains, and more than two edge hosts.
