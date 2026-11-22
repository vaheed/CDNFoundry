---
title: Performance audit, 2026-11-21
description: Measured cost of the edge cell, the WAF, the authoritative DNS path and the control plane, what was changed, and the before and after results.
---

# Performance audit, 2026-11-21

This page records a measured performance audit of the edge cell, the managed WAF, the authoritative DNS path, telemetry and the control plane: the baseline, what limited each one, what was changed, and the same measurements afterwards. Sizing advice built on it is in [Hardware requirements](hardware-requirements.md). The raw results are in [`evidence/performance-2026-11-21`](https://github.com/vaheed/CDNFoundry/tree/910b32d77801cce0a8dd67cbf4041a754734756d/docs/operations/evidence/performance-2026-11-21).

| Gate | Status |
| --- | --- |
| Implementation | Edge runtime, WAF scoring listener, ClickHouse system logs and health check, control-plane caches, sizing calculator |
| Documentation | This page, hardware requirements, configuration reference, monitoring, changelog |
| Automated and runtime qualification | Contract, Compose, fleet and calculator checks; WAF, rate-limit, containment, telemetry, origin, purge and OpenResty runtime suites. Three runtime tests fail on the unchanged code as well; see [Test results](#test-results) |
| Browser suite | Not run: no UI change |

## Environment

One VMware guest: 32 vCPU Intel Xeon E5-2697 v4 at 2.3 GHz (the CPU model of the 2026-11-15 staging fleet), 16 GB RAM, Ubuntu 24.04, kernel 6.8, cgroup v2, Docker Engine 29.1.3, containerd 2.2.1, runc 1.3.4, json-file logging (10 MB, 3 files). The development stack ran beside the benchmarks; each benchmark used its own Docker network and containers and removed them afterwards.

Every component under test was limited with a Docker CPU quota, so its cost is its own and is read from its cgroup (`cpu.stat`), not sampled. Throttling and memory come from the same cgroup. This is not a whole edge host under combined load: the gateway, the agent, Vector and DNS were not on the same quota.

## Method

**Edge cell** (`tests/e2e/edge_cell_benchmark.py`): one cell from the edge-runtime image, a static nginx origin, a UDP sink named `vector` that receives the syslog access log as Vector would, and wrk in its own container (6 CPUs). 20 proxied hostnames, cached 10 KB object unless noted, keep-alive connections, three 20-second runs after a 5-second warm-up per case. The baseline is the image built from commit `ef75af64`, the change is the image built from this tree. Nginx's per-client `limit_req` (100 r/s) and `limit_conn` (64) are raised in a copy of the configuration, and the snapshot raises the per-domain limits: one load generator is one client address, and without this the benchmark measures the rate limiter. The limiters still run on every request.

**DNS** (`tests/e2e/dns_benchmark.py`): PowerDNS with the repository's `pdns.conf` (SQLite and GeoIP backends) and 1,000 generated zones, DNSdist with the repository's `dnsdist.conf`, a Vector that receives the dnstap stream and discards it, and dnsperf with 32 sockets and up to 2,000 queries in flight. Each step is 20 or 30 seconds. The report includes the mean response size and DNSdist's truncation count, because dnsperf counts a truncated reply as a completed answer. The per-source rule `MaxQPSIPRule(500)` is raised for the same reason as the edge limits, and the global `MaxQPSRule(50000)` only where noted.

**Control plane**: the production `core` image, with the development stack's database and Valkey, `APP_ENV=production`. A fresh application is bootstrapped and handles one request per iteration in one process with OPcache on, which is what an FPM worker does for every request. CLI commands were timed as separate processes, as the scheduler runs them.

## Edge cell

CPU per request is the cell's cgroup CPU time divided by the requests served. Medians of three runs; no run had an error.

| Case | Requests per second, before | After | CPU per request, before | After | Less CPU | p99 before | p99 after |
| --- | ---: | ---: | ---: | ---: | ---: | ---: | ---: |
| WAF off, 1 CPU, 32 connections | 587 | 2,489 | 1,703 µs | 402 µs | 4.2× | 293 ms | 33 ms |
| WAF off, 2 CPU, 64 connections | 1,047 | 5,165 | 1,909 µs | 386 µs | 4.9× | 152 ms | 32 ms |
| WAF off, 0.5 CPU (default), 16 connections | 122 | 736 | 4,125 µs | 681 µs | 6.1× | 204 ms | 65 ms |
| WAF off, HTTPS, 1 CPU | 552 | 2,606 | 1,810 µs | 383 µs | 4.7× | 131 ms | 27 ms |
| WAF balanced, 1 CPU, 32 connections | 155 | 352 | 6,428 µs | 2,839 µs | 2.3× | 404 ms | 201 ms |
| WAF balanced, 2 CPU, 64 connections | 297 | 735 | 6,701 µs | 2,717 µs | 2.5× | 390 ms | 162 ms |
| WAF balanced, 0.5 CPU, 16 connections | 30 | 70 | 16,811 µs | 7,164 µs | 2.3× | 680 ms | 304 ms |
| WAF balanced, HTTPS, 1 CPU | 125 | 384 | 7,994 µs | 2,602 µs | 3.1× | 482 ms | 167 ms |
| WAF balanced, 20 KB HTML page, 1 CPU | 152 | 350 | 6,561 µs | 2,854 µs | 2.3× | 392 ms | 213 ms |
| Cache MISS, WAF off, 1 CPU, 16 connections | 382 | 784 | 2,616 µs | 1,273 µs | 2.1× | 98 ms | 43 ms |
| 4 KB POST, WAF balanced, no cache, 1 CPU | 80 | 110 | 12,523 µs | 9,102 µs | 1.4× | 368 ms | 302 ms |

The 0.5 CPU baseline (122 with the WAF off) matches the 130 measured on staging, so the harness reproduces the staging cell.

An idle cell with no domains used 211 MiB and became ready 6.8 seconds after it started; it now uses 92 MiB and is ready in 2.3 seconds.

### What limited the cell

Profiles taken with `perf` on the worker process:

1. **ModSecurity ran on every request, also with the WAF off.** Every cache location had `modsecurity on`. For a request whose mode was `off`, the first CRS rule switched the engine off with `ctl:ruleEngine=Off`, but libmodsecurity applies that switch only from the next phase: every remaining phase-1 CRS rule still ran, after the transaction had copied every request header. ModSecurity, its C++ string handling and musl's allocator were about 60 % of the CPU of a cached request with the WAF off. A WAF request that was already scored, and a request a `skip_managed` rule exempts, paid the same.
2. **The CRS rule set was parsed four times per cell**, once per cache location that named `modsecurity_rules_file`: about 110 MiB of memory and 4.5 seconds of startup.
3. **The WAF scoring listener ran the CRS response rules on its own redirect**, which carries nothing of the client's: about 6 % of a WAF request.
4. **musl's allocator.** ModSecurity allocates and copies strings for every rule it evaluates (the CRS rule 941010 adds a tag-based target exclusion that makes every later rule evaluate its tags again). memcpy and malloc in musl were about a third of a WAF request's CPU. jemalloc gave 30 % less CPU for a WAF request, about 6 % more for a cached request with the WAF off, and about 30 MiB less memory per cell; it pays for itself once about 3 % of requests use the WAF. mimalloc was tested and gained less.
5. **The access log was written twice.** Besides the syslog line to Vector, every request was written to stdout. dockerd spent about 0.55 of a core on it at 4,600 requests per second on one cell, and the production operational log collector read every line through the Docker API and parsed it as JSON only to drop it.

### What was changed

- A request whose WAF mode is `off` goes to a cache location without ModSecurity (`@cache_*_no_inline_waf`). The mode and the location are chosen from one value, set in one place. A request still in `block` or `monitor` mode, because its score could not be computed, is inspected inline exactly as before. Nothing about what is inspected or blocked changed; the managed WAF, body-inspection and spoofed-header qualifications pass unchanged.
- The inline rule set is declared once for the public server and shared by the inline locations.
- The scoring listener skips the CRS response-header and response-body rules and does not buffer the response body. The logging phase still runs, so a detection keeps its sanitized audit summary (an earlier attempt that switched the engine off in phase 3 lost it, and `tests/e2e/managed_waf.py` caught that).
- OpenResty runs with jemalloc.
- The access log goes to Vector only. `EDGE_ACCESS_LOG_STDOUT=true` restores the copy, and a cell that cannot resolve Vector at startup still falls back to it.

## DNS

The 2026-11-15 staging ramp reported about 5,000 queries per second with no loss. The mean response there was 31 bytes for a 27-byte query, too short for an answer. Repeated here with the repository configuration and one load generator:

| Offered | Answered | Truncated by DNSdist | Mean response | Reported loss |
| ---: | ---: | ---: | ---: | ---: |
| 1,000 | 1,000 | 9,502 of 20,000 | 42 bytes | 0 % |
| 5,000 | 5,000 | 89,500 of 100,000 | 33 bytes | 0 % |
| 10,000 | 10,000 | 189,500 of 200,000 | 32 bytes | 0 % |

The per-source rule (`MaxQPSIPRule(500)`) answers everything above 500 queries per second from one address with an empty truncated reply, and dnsperf counts those as answers. The staging DNS figures, and the calculator's 8,000 queries per second per vCPU, measured that path.

With the per-source rule lifted, real answers (51 bytes for a positive answer, 93 for NXDOMAIN), DNSdist and PowerDNS limited to 4 CPUs each:

| Offered | Positive answers | Loss | Mean latency | Random NXDOMAIN answers | Loss |
| ---: | ---: | ---: | ---: | ---: | ---: |
| 10,000 | 10,000 | 0 % | 0.7 ms | 9,999 | 0 % |
| 12,000 | 12,000 | 0 % | 0.8 to 0.9 ms | not run | |
| 14,000 | 13,700 to 13,870 | 0.9 to 2.0 % | 8 to 10 ms | not run | |
| 20,000 | 15,350 | 5.6 % | 11 ms | 15,240 | 5.7 % |
| 45,000 | 17,630 | 4.9 % | 10 ms | 17,070 | 5.1 % |

**About 12,000 queries per second is the loss-free limit**, with DNSdist at 1.6 cores, PowerDNS at 0.86 and the Vector that takes the dnstap stream at 1.45: about 3,000 queries per second per vCPU for the three together. Neither server is CPU bound at the limit. The first failure is the kernel dropping datagrams from full UDP receive buffers (`RcvbufErrors`) at DNSdist and PowerDNS. A random-name (NXDOMAIN) flood costs PowerDNS about 3 times the CPU of a positive query, because no packet cache helps it.

What was tried and did not help, so nothing was changed:

| Variant | Result |
| --- | --- |
| No dnstap logging | Same limit; DNSdist about 10 % less CPU. Vector's 1.45 cores is the real telemetry cost |
| Four DNSdist UDP listeners (`reusePort`) | Same limit |
| Four listeners and four backend sockets, 2 CPUs | Lower: more threads contended for the quota |
| PowerDNS `receiver-threads=2`, `reuseport=yes` | Same as the repository configuration over two repeated runs |
| All of them, 4 CPUs | About 50 % more answers under overload, lower loss, but double the latency, and the same 12,000 loss-free limit |

A DNSdist packet cache was not tested as a default: it is correct for GeoDNS (DNSdist keys it by the client subnet it adds), but it would keep an old answer for up to its maximum TTL after a zone change, which PowerDNS does not, because it purges its own cache after a transfer. Larger UDP receive buffers need a host setting (`net.core.rmem_max`) and were not tested.

## Telemetry and ClickHouse

- ClickHouse 26.8 enables `system.background_schedule_pool_log` by default and keeps it forever; it is now off. `system.error_log` and `system.query_metric_log` now expire after three days like the query log.
- When ClickHouse finds a system log table that differs from its configuration, it renames the old table to `<name>_0` and never drops it. On the development server those tables held 21 GB. See [Remove old ClickHouse system log tables](#remove-old-clickhouse-system-log-tables).
- The development ClickHouse container had been created before `system-logs.xml` was mounted. Recreated with the repository configuration, it went from 0.37 to 0.24 of a core and from 1,524 to 884 MiB while idle.
- The production health check started `clickhouse-client` every 10 seconds, about 0.3 s of CPU each time; it now runs the same authenticated `SELECT 1` over the local HTTP interface.

### Remove old ClickHouse system log tables

After an upgrade, list the renamed tables and drop the ones you do not need. They hold only ClickHouse's own diagnostics, never CDN telemetry:

```sql
SELECT name, formatReadableSize(total_bytes) FROM system.tables
WHERE database = 'system' AND match(name, '_[0-9]+$') ORDER BY total_bytes DESC;
-- then, for each name listed:
DROP TABLE system.trace_log_0 SYNC;
```

## Control plane

The production image built no Laravel caches. Every FPM request read every configuration file, registered every route and Filament panel and, without Filament's icon cache, scanned the icon sets:

| Path, production image | CPU per request, before | After `optimize` and `filament:optimize` |
| --- | ---: | ---: |
| `/up` | 92.7 ms | 30.2 ms |
| `/api/health` | 78.9 ms | 27.6 ms |
| A scheduled command (`cdnf:scheduler:check`), separate process | 1.02 s | 0.91 s |

This is the likely reason the 4 vCPU staging control host answered only 7 to 10 API requests per second. A production container now builds these caches as `www-data` when it starts (about 3 seconds); if they cannot be built it says so and serves without them.

The scheduler starts about two dozen commands a minute as separate PHP processes, and the CLI has no OPcache, so each compiles the framework again: about 1 CPU-second per command, about 0.45 of a core on the development control plane. A shared OPcache file cache would remove most of it, but health checks run as root through `docker exec` and would then execute opcode files that `www-data` can write, so it was not enabled. The health checks of Horizon and the scheduler boot Laravel in the same way, about 1.3 to 2.4 CPU-seconds every 30 seconds each.

Other control-plane observations, not changed: the idle development database ran about 134 transactions and 145 new sessions a minute, mostly from the agent API, the metrics scrape and the scheduler; platform settings are read once per request or job.

## Not measured

- A whole edge host under combined load (cells, gateway, agent, Vector and DNS on one host) after the changes. The host-level figures on the hardware page predate them.
- Vector's cost per edge access-log event, the gateway's relay cost, HTTP/2 and HTTP/3, large objects, and long soak runs. Every run here was 20 or 30 seconds.
- Control-plane API throughput under concurrent load, and the scheduler converted to queued jobs.
- Host kernel settings: UDP and TCP buffers, conntrack capacity for DNS published through Docker's port mapping.

## Test results

Passed on this tree: `make contract-check` (including `config-check` and `openapi-check`), `make fleet-test`, `make qualification-tools-check`, and the runtime qualifications `managed_waf`, `waf_body_inspection`, `rate_limit_isolation`, `ipv6_rate_limit_keys`, `client_containment`, `security_report_fairness`, `challenge_binding`, `traffic_volume_accuracy`, `telemetry_proof`, `sni_host_binding`, `origin_destinations`, `edge_purge_protocol`, `openresty_runtime`, `edge_telemetry_startup` and `core_image_unprivileged`.

Failed on the unchanged commit as well, so not caused by this change: `custom_rules` (a monitored request's access row is missing), `application_protection` (`schema_body_too_large` is not counted) and `cell_generation_reload` (its cell never reports loading state).
