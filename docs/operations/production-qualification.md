---
title: Production qualification
description: Run, record, and decide the final CDNFoundry production release qualification.
---

# Production qualification

CDNFoundry is release-qualified only when one report records every automated,
real-runtime, external-traffic, installer, load, and browser checkpoint as
passed. A skipped applicable check blocks release. A failed check fails release.
The runner never converts an absent external environment into a pass.

## Required environment

Use a disposable production-like environment with:

- two POPs and at least one edge in each POP;
- exactly eight installed cell slots on each edge;
- a shared pool with at least three cells on each edge;
- separate dual-stack reserved and quarantine pool endpoints;
- Geo-Unicast endpoints and, where routing is approved, one Simple Anycast pool;
- persistent cache with Gzip and Brotli, a backup origin, and managed WAF;
- a healthy comparison domain and pool throughout every failure exercise;
- real IPv4 and IPv6 paths, or an explicitly recorded blocked IPv6 result.

Record host CPU, memory, storage, network capacity, operating system, Docker
version, component image digests, topology, dataset, concurrency, latency,
throughput, saturation point, recovery time, and accepted operating limit.
Documentation addresses and `.test` names do not qualify public traffic.

## Automated and real-runtime run

Start and migrate the persistent development topology. Never remove its named
volumes. List the bounded checks before running:

```sh
python3 tests/e2e/production_qualification.py --list
make dev-production-qualification
```

The default report is
`storage/qualification/production-qualification.json`; individual logs are in
the adjacent `production-qualification/` directory. Use `--only ID` for a
focused rerun and repeat it to select several checks. A focused report marks
every unselected check `not_run`, so it cannot become release evidence by
accident. `--continue-on-failure` completes independent checks after a failure.

The runner covers:

- Compose, production override, OpenAPI, docs, Laravel, and Go contracts;
- gateway Host/SNI routing, invalid candidates, restart, and last-valid state;
- the bounded eight-slot inventory and cell isolation;
- cumulative DNS, TLS, cache, purge, compression, origin, WAF, telemetry outage,
  placement, movement, drain, quarantine, and rollback behavior;
- the bounded domain/change dataset, recovery, upgrade compatibility,
  throughput, and MMDB provider outage.

Keep the generated report and logs in an access-controlled evidence store.
They may contain local topology identifiers. Review and sanitize before sharing.

## Owner evidence

Coding agents do not automate browsers, operate public routing, or infer that a
local container topology proves public traffic. Complete the exact
[manual qualification](https://github.com/vaheed/CDNFoundry/blob/main/docs/manual-browser-qualification.md),
then provide one non-empty sanitized evidence file for each owner-operated
check:

```sh
export CDNF_QUALIFY_EXTERNAL_IP_EVIDENCE=/absolute/path/ipv4-ipv6.json
export CDNF_QUALIFY_ANYCAST_EVIDENCE=/absolute/path/anycast.json
export CDNF_QUALIFY_EXTERNAL_LOAD_EVIDENCE=/absolute/path/load.json
export CDNF_QUALIFY_FLEET_INSTALLER_EVIDENCE=/absolute/path/fleet.json
export CDNF_QUALIFY_BROWSER_EVIDENCE=/absolute/path/browser.json
make dev-production-qualification
```

An evidence file records the commit, date, operator, topology, command or exact
manual step, expected result, actual result, measurements, sanitized artifact
links, and outcome. Never place passwords, tokens, private keys, certificate
private material, customer data, or signing keys in evidence.

For an environment without approved Anycast routing, record the Anycast check
as `blocked`; do not set its evidence variable and do not claim final release
qualification. Product scope must be changed explicitly if that requirement is
removed.

## Failure exercises

Keep comparison traffic running while each exercise is performed:

1. submit invalid gateway, cell, and WAF candidates and confirm the active
   checksum and traffic remain unchanged;
2. move a domain target-first, fail target readiness, and confirm the source
   remains active; then complete movement and drain;
3. stop the control plane, queue, Vector, and ClickHouse in controlled windows;
   confirm DNS and edge traffic continue and telemetry recovers within bounds;
4. saturate one cell and confirm unrelated pools and cells continue serving;
5. fail the fleet canary, confirm automatic pause, then roll back through the
   fixed-purpose installer with no dynamic slot creation;
6. restore on a clean replacement host with the recovery secret set, create a
   fresh queue, reconcile all derived DNS/edge/TLS/purge/usage state, and
   requalify traffic before returning service.

Stop an exercise when the healthy comparison route fails. Preserve logs and the
last-valid artifact; do not continue destructive fault injection through an
unexplained isolation failure.

## Release decision

The JSON report uses only:

- `passed`: every check passed and links evidence;
- `failed`: at least one executed check failed;
- `blocked`: nothing failed, but at least one check was not run.

Release notes must quote measured results from the report, state topology and
hardware, distinguish local from public evidence, and list limitations. Do not
claim volumetric DDoS scrubbing, general BGP management, HTTP/3, origin shield,
or a warm standby. The current architecture provides bounded application-layer
controls and continues serving only while the edge host, network, and upstream
capacity remain available.
