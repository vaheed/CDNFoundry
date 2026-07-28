---
title: Edge gateway ingress
description: Operate destination-address and Host/SNI routing to bounded OpenResty cells.
---

# Edge gateway ingress

The edge gateway is the only process that binds customer HTTP and HTTPS
service addresses on a gateway-enabled edge. It performs a bounded lookup on
destination address plus HTTP `Host` or TLS SNI, then proxies the untouched
connection to an assigned OpenResty cell. It does not terminate customer TLS,
load certificates, cache content, run WAF rules, contact origins, or call the
control plane.

## Data and activation flow

PostgreSQL remains the desired-state source. Signed edge artifacts carry
hostname and pool assignment. The edge agent validates and atomically activates
those artifacts, combines them with operator-owned service bindings, and writes
`gateway.json`. The gateway validates the complete candidate before swapping
one immutable routing table. It writes `last-valid.json` before activation and
uses it when a candidate is absent or invalid at restart.

`EDGE_GATEWAY_BINDINGS` is a JSON array with at most 32 entries:

```json
[
  {
    "address": "192.0.2.10",
    "pool": "shared-default",
    "http": "127.0.0.1:18081",
    "https": "127.0.0.1:18444"
  },
  {
    "address": "2001:db8::10",
    "pool": "shared-default",
    "http": "127.0.0.1:18081",
    "https": "127.0.0.1:18444"
  }
]
```

For a multi-cell pool, replace the top-level `http` and `https` target with a
bounded `cells` array. Each item has the stable `name`, `http`, and `https`
target for that cell. The compiler uses each cell's assigned hostname set, so
unrelated cells receive neither routes nor domain artifacts:

```json
[{"address":"192.0.2.10","pool":"shared-default","cells":[
  {"name":"cell-01","http":"127.0.0.1:18081","https":"127.0.0.1:18444"},
  {"name":"cell-03","http":"127.0.0.1:18083","https":"127.0.0.1:18446"},
  {"name":"cell-04","http":"127.0.0.1:18084","https":"127.0.0.1:18447"}
]}]
```

Every address must be assigned to the host. Each address/pool pair expands only
to hostnames assigned by signed artifacts. Candidates are rejected for unknown
pools, invalid addresses or targets, duplicate address/hostname pairs, more
than 64 listeners, more than 100,000 protocol routes, or a size over 32 MiB.

The gateway sends PROXY protocol version 2 to private cell ports `8081` and `8444`.
This is the trusted client-identity contract for HTTP and encrypted HTTPS.
Cell ports `8080` and `8443` are not published. All customer traffic is forced
through the gateway and reaches cells only on the private contract ports.

## Deployment and migration

Production uses host networking so sockets see the destination address. Grant
only `NET_BIND_SERVICE`; drop all other capabilities. Restrict port `9105` to
the edge agent and monitoring source. Set `EDGE_GATEWAY_MAX_CONNECTIONS` from
the qualified host ceiling; invalid or out-of-range values use 8,192.

1. Assign every service IPv4/IPv6 address to the host.
2. Set `EDGE_GATEWAY_BINDINGS` with explicit pools and private cell targets.
3. Start the agent, cells, state initializer, and gateway. Require gateway
   readiness and a map revision equal to the agent sequence.
4. Probe every configured address/Host and address/SNI combination. Run IPv6
   probes only when an IPv6 service address is configured.
5. Confirm cell HTTP/HTTPS ports are not publicly published before enabling
   customer DNS.

Never edit generated gateway, cell, or last-valid files. Change desired state
or operator bindings and let reconciliation render a candidate.

## Monitoring and failures

`/metrics` exposes readiness, active revision, listener and route counts,
connections, bounded errors, activations, and candidate rejections. The agent
reports this bounded snapshot in its heartbeat and the administrator edge page
displays it and refreshes live runtime state every five seconds. Readiness is
false when the gateway is unavailable or its revision differs from the active
agent sequence.

The active configuration sequence and gateway map revision are monotonic
identities, not retained configurations or allocated resources. They can safely
grow to millions; do not reset them daily or weekly. Resetting would remove the
ordering used to reject stale candidates and acknowledgements. PostgreSQL
artifact retention may remove old payload rows without reusing or resetting
their sequence values.

`Gateway routes` is the size of the current in-memory routing map. One hostname
may contribute an HTTP and an HTTPS route for each service address on which it
is available. It is not a lifetime counter. Inspect desired hostname placement
in the domain and cell views; inspect the generated map only through bounded
gateway metrics and qualification tooling, never by editing the derived file.

- Unknown or malformed Host/SNI: close before dialing a cell.
- Unknown destination/name pair: close before dialing a cell.
- Invalid candidate: retain the active table and increment rejection metrics.
- Cell outage: only routes targeting that cell fail.
- Control-plane outage: continue from local active state.
- Gateway restart: load a valid candidate or `last-valid.json`.
- Agent restart: rebuild derived files from durable signed local state.

If the gateway process is ready but an endpoint says `gateway_not_ready`, first
confirm the edge heartbeat is fresh. A stale panel snapshot can be distinguished
from a real outage because the live status section refreshes every five seconds.
For a real failure, compare gateway readiness and revision with the agent's
active sequence, then inspect the agent log for a rejected heartbeat or
candidate.

Logs contain the listener and a bounded reason, never bodies, maps,
certificates, customer content, or secrets.

## Qualification evidence

On 2026-07-27 `python3 tests/e2e/gateway_ingress.py` passed real HTTP Host and
HTTPS SNI routing through OpenResty on dual-stack and IPv4-only gateways,
unknown-route rejection, restart, invalid-candidate rejection, and last-valid recovery.
HTTPS used the active Pebble ACME root with strict hostname and chain
verification; no insecure client override was used.

The reproducible 50,000-mapping test ran on a VMware x86_64 host with 32 Intel
Xeon E5-2697 v4 vCPUs, 15 GiB RAM, Docker 29.1.3, and Go 1.24.6. It used two
dual-stack pairs, eight listeners, 50,000 host mappings, 100,000 protocol
routes, and 64 concurrent workers.

| Result | Value |
| --- | --- |
| Candidate validation | 242.192 ms |
| Additional Go heap | 12.59 MiB |
| Concurrent lookups | 1,280,000 |
| Lookup wall / CPU | 0.209 s / 3.439 CPU-s (16.45 average cores) |
| Lookup throughput | 6,120,620/s |
| Average lookup latency | 0.163 µs |
| Saturation | Not observed at 64 workers |
| Accepted qualification ceiling | 64 concurrent lookup workers |

The live-socket load used 50,000 mappings and a bounded local TCP upstream. At
16, 64, and 128 concurrent connections it completed 3,200, 12,800, and 25,600
requests with zero errors. The 128-concurrency tier sustained 8,683 requests/s
with p50/p95/p99 latency of 13.480/22.356/26.960 ms; saturation was not
observed, so 128 is the accepted connection-concurrency ceiling from this run.

The runtime suite separately qualifies socket parsing, PROXY protocol identity,
OpenResty handoff, and TLS pass-through. Operators
must load-test their NIC, kernel, and uplink before setting production limits.
