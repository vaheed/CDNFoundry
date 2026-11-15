---
title: Staging qualification and stress test, 2026-11-15
description: Partial staging qualification of the two-edge staging fleet. DNS ramp and single-domain HTTP results, what was not tested, and the findings that need follow-up.
---

# Staging qualification and stress test, 2026-11-15

This report records what was measured on the running staging fleet on 2026-11-15 (UTC 11:10 to 11:30) and what was not. It is **partial**: the DNS functional checks, a DNS UDP ramp, and a single-domain HTTP cache-HIT probe were executed. Cache MISS, WAF, TLS, origin, integrated, soak and failure tests were not. Hardware guidance built on these numbers is in [Hardware requirements and capacity planning](hardware-requirements.md).

All addresses and names are masked. Real values stay outside the repository.

| Gate | Status |
| --- | --- |
| Implementation under test | Deployed release; image digests not recorded in the repository |
| Documentation | This page and the hardware page |
| Automated and runtime qualification | Partial, see [Not tested](#not-tested) |
| Browser suite | Not run (no UI change) |

## Environment

The deployed fleet differs from the planning file in `.stage`, which was stale. Live inspection found:

| Role | Masked address | Contents |
| --- | --- | --- |
| control-1 | 203.0.113.10 | Laravel, Horizon, scheduler, PostgreSQL, Valkey, PowerDNS primary, ClickHouse, Vector, Prometheus, Grafana, Loki, Caddy (25 containers) |
| edge-a | 198.51.100.77 | 8 OpenResty cells, edge gateway, edge agent, DNSdist, PowerDNS auth, Vector (17 containers) |
| edge-b | 198.51.100.78 | Same as edge-a |
| loadgen | 198.51.100.76 | Idle spare. Used as the load generator (dnsperf, wrk installed) |

Every host is a VMware guest with 4 vCPU (Intel Xeon E5-2697 v4, 2.3 GHz), 3.9 GB RAM, a 73 GB virtual disk and a 10 Gbit/s NIC. The edge hosts have no swap. Cells are limited to 0.5 CPU and 512 MB each. The cache limit per cell is 10 MiB. Configured data: one active customer domain, two DNS records, two edges, all cells `ready`.

## Baseline (10 minutes, 15 s samples)

| Host | CPU busy min / mean / max | Load (1 min) | Min memory available | Swap used |
| --- | --- | --- | --- | --- |
| control-1 | 19 / 58 / 90 % | 4.4 | 1048 MB (27 %) | 1034 MB |
| edge-a | 2 / 14 / 25 % | 0.9 | 1395 MB | 0 |
| edge-b | 1 / 6 / 13 % | 0.8 | 1395 MB | 0 |

control-1 was already near the 95 % CPU guardrail before any test. For that reason no load was aimed at it and no control-plane API test was run.

## DNS

### Functional (from the load generator, directly to each edge)

- SOA, NS, A, TXT and the platform zone answered with the `aa` flag on both edges, with identical SOA serials.
- A nonexistent name returned NXDOMAIN. Names outside served zones returned REFUSED. ANY returned NOTIMP. Zone transfer returned NOTAUTH.
- Geo steering works: the same name resolved to different edges for different client networks.
- No AAAA or MX records are configured, so those returned NOERROR with an empty answer.
- DNSSEC, UDP truncation, TCP fallback timing and IPv6 transport were **not** verified (the TCP, DNSSEC and EDNS lines in the raw output were not reviewed, and the edges' IPv6 addresses are not reachable from the load generator).
- The record create, update and delete reconciliation test was **not run**: it needs an API token and writes through the control plane.

::: warning DNS from outside the subnet is intercepted
From the engineer workstation network, every edge address (including the idle spare that runs no DNS) answered queries with the recursion-available flag and no `aa`, over UDP and TCP, in about 1 ms. Something on that network path answers port 53. DNS tests from that network say nothing about the staging DNS path, so that run was discarded and DNS was tested from inside the staging subnet.
:::

### UDP ramp against edge-a

Tool: dnsperf, 50 clients, 4 threads, 15 s per stage, 2 s timeout. One source host.

| Offered qps | Positive: completed qps | Loss | Mean latency | Mixed: completed qps | Loss |
| --- | --- | --- | --- | --- | --- |
| 2,000 | 2,000 | 0 % | 1.5 ms | 2,000 | 0 % |
| 5,000 | 4,997 | 0 % | 2.7 ms | 4,998 | 0.03 % |
| 10,000 | 9,508 | 4.7 % | 9.6 ms | 9,408 | 4.7 % |
| 20,000 | 15,030 | 5.8 % | 10.1 ms | 16,113 | 5.3 % |
| 40,000 | 14,579 | 6.3 % | 12.4 ms | 12,529 | 7.2 % |

Every returned response was NOERROR or NXDOMAIN. There were no SERVFAIL or REFUSED replies, so the loss is dropped packets.

- **Highest tested:** about 16 k completed qps (measured peak, not sustainable).
- **Clean operating point measured:** 5 k qps, no meaningful loss. This is a 15 s stage, not a sustained result.
- **Bottleneck: not attributed.** edge-a reached 81 % CPU in coarse samples, control-1 peaked at 91 % during the run, and the load generator itself was not monitored. The loss could come from DNSdist, a per-client limit, the VM network or the generator. Per-process CPU and DNSdist counters are needed to attribute it.
- **Open finding:** about 30 % of the mixed queries were random names, yet NXDOMAIN stayed near 2.3 k per stage whatever the rate. The behavior of the random-name path is unexplained.

## HTTP cache (one domain, one edge)

wrk from the load generator to edge-a, one 3 KB static object, 15 s per run.

| Connections | Requests/s | p50 | p99 | Errors |
| --- | --- | --- | --- | --- |
| 1 | 37.8 | 17 ms | 82 ms | none |
| 8 | 23.2 | 326 ms | 518 ms | none |
| 32 | 25.3 | 1.21 s | 1.69 s | none |
| 64 | 26.0 | 1.41 s | 2.00 s | 1335 connect errors, 293 timeouts |

What the edge showed:

- All traffic landed on one cell. A domain is placed on one cell per edge, so one domain gets the capacity of one cell.
- All 1,224 logged requests in the window were `200` with `cache_status` `HIT`, so these are real cache hits and no request reached the origin.
- That cell ran at about 51 % of one core, which is its 0.5 CPU limit. It was throttled in 757 of 3,173 periods. The edge host itself stayed below 41 % CPU.
- A cache HIT costs roughly 13 to 26 ms of CPU. That is very high for a static HIT. The cause was not isolated. The domain's WAF profile is `balanced`, and the WAF-off baseline needed to separate the two was not run because it requires changing the domain configuration.
- The configured per-client limit of 100 rps was not reached, so the limiter did not cause this ceiling.

**Measured HIT capacity for this domain is about 25 to 38 rps per cell, about 25 rps under concurrency.** This is not a platform capacity figure.

## Tuning run

After the first run the owner allowed changes to staging, so a second run changed settings, measured, and restored them. Load came from the idle spare host. Every change below was reverted and verified.

What was changed on the test cell (one cell on one edge) and on the one test domain:

- Cell CPU and memory limits (0.5 CPU / 512 MB up to 4 CPU / 1 GB) through a Compose override.
- A patched copy of the cell's nginx configuration mounted read-only: per-client request and connection limits lifted, then the stdout access log buffered or removed.
- On the domain: the WAF profile (balanced and off) and the per-domain rate and handshake limits.

### Results

| Case (one cell, cached 3 KB object, 64 connections unless noted) | Requests per second | Notes |
| --- | --- | --- |
| WAF balanced, 0.5 CPU (original) | 25 | one worker, throttled |
| WAF off, 0.5 CPU, limits lifted | 130 | one worker |
| WAF off, 2 CPU | 3,340 | two workers; 2,025 at 16 connections |
| WAF off, 4 CPU | 3,120 to 3,450 | host CPU 98 % busy, not the cell |
| WAF off, 4 CPU, stdout log buffered | 3,440 | dockerd CPU 50 % down to 19 %, connect errors 1,848 down to 113 |
| WAF off, 4 CPU, stdout log removed | 4,010 | dockerd idle |
| WAF balanced, 2 CPU | 230 to 250 | all 2xx |
| WAF balanced, 4 CPU, 8 connections | 217 | all 2xx, host about 90 % idle |
| Cache MISS, unique keys, WAF off, 8 connections | 194 | median 37 ms, origin bound |

### What limits capacity

- **The WAF.** CRS evaluation costs roughly 10 to 20 ms of CPU per request, about 15 to 20 times a plain cached response. It does not speed up from 2 to 4 CPUs at low concurrency, so the cell's scoring path rather than raw CPU may be a second limit.
- **The telemetry path.** At 3,500 requests per second the edge host was 98 % busy: the cell about one core, Vector 0.8, the log collector 0.25, the gateway 0.55, dockerd 0.5. Telemetry and logging take about a third of the host.
- **Cell CPU limit.** A 0.5 CPU cell starts one nginx worker, so it cannot use more than half a core.
- **DNS.** At about 16,600 queries per second DNSdist used about 1.1 cores and PowerDNS 0.8. The DNSdist configuration contains a per-source-IP rule (500 queries per second, answering with truncation). Truncated replies were not observed at 5,000 queries per second, so whether it shaped the single-source results is unconfirmed.

### Client containment

At about 2,000 requests per second or more from one address the cell banned the client within seconds (`client_temporarily_blocked`, 300 s, cleared by recreating the cell), which made WAF-on runs above that rate invalid. Events recorded before the ban were `client_rate_exceeded`, even though the domain limits had been raised. The trigger was not identified. WAF-on capacity at 4 CPUs is therefore an estimate.

### Code changes from this run

- The stdout access log is buffered (`buffer=64k flush=1s`).
- Cell CPU and memory limits can be set with `EDGE_CELL_CPUS` and `EDGE_CELL_MEMORY`. Defaults are unchanged.

### Restoration

The deployment directory was byte-identical to its backup after the override was removed, cell-06 is back to 0.5 CPU, 512 MB and 128 PIDs with its original mounts, the domain WAF profile and security settings equal the originals, the temporary API token was deleted, and all containers, volumes and record counts match the start.

## Scale run: control plane, DNS onboarding, telemetry

This run asked what a 50,000 domain deployment needs. Synthetic domains (all named with a `qual-` prefix, removed afterwards) were added in steps, and each component was measured. Staging has no hardware that can hold 50,000 live domains, so the larger figures are extrapolated and marked as estimates.

### Measured

| Item | Result |
| --- | --- |
| Control database, 50,005 domains and 150,000 DNS records | 145 MB total, up 76 MB from 1,005 domains, about 1.5 KB per domain |
| Bulk insert of 49,000 domains and 147,000 records | 27 seconds |
| Zone onboarding, 1,000 active zones of 3 records through the normal job path | about 10 minutes, about 1.6 zones per second |
| Onboarding bottleneck | The PowerDNS primary's PostgreSQL ran at 80 to 100 % of one core |
| Edge PowerDNS memory | 67.6 MB with 3 zones, 76 MB with 1,003 zones, about 8 KB per zone |
| PowerDNS primary database | 10 MB for 3,600 records |
| DNS query rate with 1,003 zones | the same ceiling as with 3 zones: about 16,000 queries per second, 9,800 at 1.9 % loss |
| API request, authenticated read | 0.38 to 0.42 s each, with 1,005 or 50,005 domains alike; the framework floor (`/up`) is 0.18 s |
| API throughput, 4 to 16 concurrent clients | 7 to 10 requests per second |
| API throughput with the PHP pool raised from 5 to 16 workers | no change (9.6 against 9.75 requests per second) |
| Scheduler container | 0.5 to 1 core at all times: about 30 `artisan` commands a minute, each about 1 CPU second to start, whatever the domain count |
| ClickHouse storage per event | 49 bytes (edge events), 24 bytes (DNS events), compressed |
| ClickHouse system logs | 3.1 GB beside 32 MB of telemetry after eight days |

### What this means

- **Domain count is cheap; zone onboarding and traffic are not.** The database, API latency and DNS serving did not change between 1,000 and 50,000 domains. Putting 50,000 zones into PowerDNS would take about 8.7 hours at 1.6 zones per second (estimate from the measured rate).
- **The control host is CPU bound, not memory bound.** The API is slow because the 4 vCPU host is shared with ClickHouse, the scheduler, PostgreSQL and PowerDNS. Raising the PHP pool did not help.
- **The scheduler is a fixed cost.** Each scheduled command starts a new PHP process. Caching configuration and routes did not change the cost, and CLI opcache saved about 20 %. A real fix is to run scheduled work inside one process.
- **Telemetry grows with traffic.** At the 49 byte per event figure, 3,500 requests per second for a day is about 15 GB (estimate, assumes every request is logged).

### Tuning tested

| Change | Result | Kept |
| --- | --- | --- |
| ClickHouse system logs off, query log expires after 3 days | ClickHouse used 27 % of a core against 40 % for the original, over 60 seconds each, and stops the 3 GB growth | Yes, committed |
| PHP-FPM pool 5 to 16 workers | no throughput change on a CPU-starved host | No |
| Laravel config and route cache | no measurable change | No |
| Live PHP-FPM reload with a signal | left the pool without workers; a container restart fixed it | Do not do this |

The ClickHouse measurement is one 60-second window per side on a busy host, so treat the size of the gain as approximate.

### Not measured in this run

Edge memory per domain, since synthetic proxied domains would have needed certificates and a reachable origin. Grafana, Prometheus and Loki were only observed at idle. Queue throughput above one worker pool.

## Not tested

Not run, with the reason:

- Cache MISS, expiry, revalidation, stale behavior, purge, Vary, cookies, Range, compression, large objects, TLS handshake rate. Not reached; the HIT probe found a cell ceiling that makes these a poor use of a shared staging origin until understood.
- WAF security regression, body-inspection matrix, WAF overhead comparison, scoring-service failure. Not run. The WAF-off comparison needs a configuration change that this task does not allow.
- DNS write path, DNSSEC, IPv6, truncation. Needs an API token or IPv6 reachability.
- Integrated stack, multi-cell and multi-POP load, telemetry back-pressure, analytics outage. Not run.
- Sustained 15 to 60 minute runs, burst after soak, recovery curves. Not run. The control host has no headroom for the telemetry volume.
- Failure injection. Prohibited for this task.
- Database, Valkey and ClickHouse capacity. Only baseline CPU and memory were sampled.

## Final verification

After the tests: all three stack hosts showed every container `healthy` with no restarts or restarting containers, domain, DNS record and edge counts were unchanged (3, 2, 2), and all cells were `ready`. No volumes were removed. Load generators had stopped. The only changes were packages and a temporary hosts entry on the idle load-generator host; the entry was removed. Management access worked throughout.

## Risks and findings to follow up

1. **control-1 has no headroom.** 4 vCPU and 3.9 GB carry the entire control plane and telemetry, with CPU averaging 58 % and 1 GB of swap in use at idle.
2. **A cache HIT costs 13 to 26 ms of CPU.** Find out whether the WAF, Lua or logging path accounts for it.
3. **DNS drops about 5 % of queries above 10 k qps** from a single source, with the cause unattributed.
4. **Random-name DNS queries mostly return NOERROR**, which does not match the zone data.
5. **Edge hosts have no swap** and cells can each grow to 512 MB, which exceeds the 1.4 GB available if all eight grow.
6. **The planning inventory is stale** (it lists a different host layout than the live fleet).

## Raw evidence

Sanitized results: `docs/operations/evidence/staging-2026-11-15/results.json`. Raw command output and host samples are kept outside the repository because they contain real addresses.
