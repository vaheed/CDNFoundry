# Phase 8 qualification record

Phase 8 implementation and local agent-owned qualification are complete. Phase
8 is **not release-qualified** because the fresh physical replacement-host,
external backup system, disposable-host clock, production-like canary, and
owner-run browser/real-traffic gates still require owner infrastructure.

## Implemented and automatically covered

- Admin-only component health with stable states and bounded/redacted failed-job
  inspection, audited retry/delete, and protected Prometheus metrics.
- Coalesced DNS, edge, TLS, purge, and usage reconciliation on bounded lanes.
- PostgreSQL-backed operational thresholds, scheduler heartbeat, alert rules,
  and bounded audit retention.
- Horizon master freshness, MMDB age, DNSdist and PowerDNS scrape health,
  DNSdist backend state, and bounded edge listener/cell/pool, drift, emergency,
  and reported-resource-pressure health.
- Encrypted Restic backup API/CLI, asynchronous preflight, exact restore
  confirmation plus password re-authentication, redacted metadata, operation
  records, and a maintenance-only restore executor.
- Expand-compatible schema changes, an explicit edge-agent compatibility
  version, recovery/upgrade/capacity runbooks, and the exact owner checklist.

The isolated Laravel suite covers permissions, validation, redaction,
idempotent/coalesced dispatch, metrics authentication, backup lifecycle,
restore confirmation/re-authentication, and audit pruning. Browser automation
was not run.

## Recovery evidence

`tests/e2e/phase8_operations.py` passed the application backup and restore
lifecycle with backup `019f7f96-89ce-72f3-8c75-115fe7b98cc5` and Restic
snapshot `b85acac3880a58138b0611c9f26b876ac09b2f2decabfb303d50427facf8af17`.
Full-pack verification succeeded and the dump restored into an empty temporary
PostgreSQL 18 instance with source/restored counts equal.

`tests/e2e/phase8_recovery.py` then used a separate, disposable S3-compatible
object host (`quay.io/minio/minio:RELEASE.2025-07-23T15-54-02Z`), independently
generated object credentials, and separately generated Restic decryption
material. It rejected a wrong repository password, verified 100% of repository
data, and restored snapshot
`2be94c40679387f32e3223d1d461f732ffae852405d9db597f15fd0a1861f04c` into a
fresh PostgreSQL 18.4 tmpfs container. The marker and these counts matched:
42 users, 73 domains, 304 DNS records, 61 DNS deployments, 2 edges, 46 edge
artifacts, and 2 TLS certificates. The current application then applied forward
migrations on the replacement and reached the repository's exact 39-migration
schema. Against a new empty Valkey instance, five bounded global reconciliation
jobs all succeeded and reconstructed 25 runtime/certificate-purge jobs from
durable state. The usage reconciler rebuilt one finalized hourly interval for
each of the 14 active domains from retained ClickHouse data. Measured
backup-cutoff RPO was 11.114 seconds, restore, forward-migration, and
reconciliation RTO was 31.669 seconds, and total exercise time was 51.501
seconds. No named volume was removed.

This proves encrypted object-host recovery in clean replacement containers on
the qualification dataset, including queue reconstruction and usage rebuilding.
It does not substitute for the roadmap's external off-host repository and fresh
physical replacement-host rehearsal, where PowerDNS, a new edge, TLS, and real
DNS/HTTP traffic must also be reconstructed and timed.

## Upgrade and runtime evidence

`tests/e2e/phase8_upgrade.py` built the prior committed release
`a584fee012d8280c2e694b1cc7703ae333454ce5` and the candidate independently.
The prior/current edge agents reported `1.0.0`/`1.1.0`; signed artifact tests
passed in both builds. On fresh PostgreSQL, the prior release installed 36
migrations, the candidate expanded to 39, and the prior release then ran and
wrote successfully against the expanded schema. Rollback restored no database
backup. This proves the mixed-version application/schema contract locally; the
multi-host control-worker, DNS-target, and edge canary remains an owner gate.

The real Phase 4 OpenResty suite passed after adding regressions for cache
admission and more than 64 sequential cache hits. The qualification exposed and
fixed two bounded-runtime defects: cache-admission pressure could bypass an
already resident object, and active-request counters were not released after an
internal cache redirect. Resident cache hits now remain available and request
counters are released in the log phase through request-scoped Nginx variables.
The same real-runtime job proves an invalid replacement never displaces the
last-valid configuration and an 8 KiB in-flight response completes after the
OpenResty master receives graceful `SIGQUIT`.

## Capacity, restart, and isolation evidence

`tests/e2e/phase8_throughput.py` passed on Linux 6.8.0-134 x86_64 with an Intel
Xeon E5-2697 v4, 32 logical CPUs, and 16,784,982,016 bytes host memory. The
single OpenResty cell was restricted to 1 CPU, 512 MiB, 128 PIDs, and 65,536
file descriptors. Its image was
`sha256:2f968173f12efa3372d2116da3b84716df788a250743f69cdf4611f3145bcf0e`.
The profile used 64 pre-warmed cache-HIT domains and 32 isolated client
containers paced below the runtime's 100 requests/second/client bound, with
HTTP/1.1 new connections:

| Transport | Requests | Errors | Requests/s | p50 | p95 | p99 |
|---|---:|---:|---:|---:|---:|---:|
| HTTP | 4,729 | 0 | 268.68 | 2.178 ms | 5.883 ms | 19.788 ms |
| HTTPS | 872 | 0 | 53.14 | 162.363 ms | 551.890 ms | 842.368 ms |

These are reproducible lower-bound results for the stated constrained cell and
new-connection workload, not a universal hardware promise.

The established scale job remains valid: 500,000 domains, 1,000,000 DNS
records, 50,000 changes, and a 10,000-change burst passed. Existing real-runtime
evidence also proves:

- control PostgreSQL, Valkey, Laravel, Horizon, Scheduler, and web can be down
  while existing authoritative DNS continues; PowerDNS and DNSdist restart
  with the latest answers (`phase2_dns.py`);
- a sibling edge/cell failure remains isolated, target activation precedes
  source drain, and bounded cell drain/restart recovers traffic
  (`phase4_control_plane.py` and `phase4_runtime.py`);
- ClickHouse and Vector outage/restart does not stop DNS or edge serving and the
  bounded Vector backlog drains (`phase7_analytics.py`).
- a real MMDB updater provider failure preserves the previous checksum, leaves
  no activation candidate, and keeps the updater running
  (`phase8_mmdb.py`, checksum
  `6cfd04ff7d30de5be30016afbe41bd240ffe1c1c0d6fbfe47d52bf1a609131f1`).

Promtool rule tests prove unsynchronized time and absolute clock offset over
five seconds alert after two minutes. The API suite proves the database-backed
threshold degrades component health. A real offset on a disposable host remains
an external gate. The pinned DNSdist 2.1 configuration check and live private
scrapes also passed: the DNSdist container became healthy and Prometheus
reported both `dnsdist` and `powerdns` targets up. The API suite injects stale
MMDB, listener/cell/pool failure, edge configuration and placement drift,
resource pressure, and an active emergency mode and verifies each component
degrades independently.

## Outstanding release gates

- Restore from the approved external encrypted backup system onto a fresh
  physical replacement host; rebuild PowerDNS, queue state, a fresh edge, TLS,
  and retained usage while measuring end-to-end RPO/RTO and real traffic.
- Run the mixed-version control-worker, DNS-target, and edge canary/rollback on
  production-like separate hosts.
- Rehearse and resolve real clock drift on a disposable host.
- Complete and record every owner-run Phase 8 browser and final real-traffic
  checkpoint in `docs/manual-browser-qualification.md`.

No unavailable infrastructure test is reported as passed.
