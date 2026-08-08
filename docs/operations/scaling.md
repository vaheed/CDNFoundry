---
title: Scaling
description: Scale CDNFoundry control workers, DNS, edges, telemetry, and data services without per-domain processes.
---

# Scaling

::: warning Scale measured bottlenecks
Add bounded workers, DNS capacity, ClickHouse capacity, edges, or fixed cells
only after identifying the saturated resource. Never solve growth by creating a
default per-domain process, container, worker, timer, or reload path.
:::

Scale by repeating bounded service roles:

- add Horizon workers for a specific queue lane;
- add private PowerDNS backends behind DNSdist;
- add public DNSdist capacity;
- add edge hosts and equivalent cells;
- add ClickHouse capacity or move telemetry to dedicated hosts;
- use replicated external PostgreSQL and Valkey selected and operated by the owner.

Do not scale by creating a process, container, worker, timer, Nginx server block,
cache directory, or reload per domain.

## Add an edge-only host

1. prepare host firewall, immutable release, `.env.prod`, runtime certificate,
   server CA, status token, advertised service identities, and private local
   gateway address mappings;
2. validate `compose.prod.yml` plus `compose.prod.yml`;
3. create the edge row and configure its cells;
4. enroll its agent with the one-time token;
5. wait for fresh heartbeat and ready cells;
6. confirm existing pool routing includes the new cell only after readiness;
7. qualify HTTP/HTTPS and telemetry before relying on capacity.

Adding an edge does not require a new DNS cluster.

## Add a DNS host

1. prepare a distinct public IP, private PowerDNS data, valid MMDB, and DNS API certificate;
2. run the separate PowerDNS migration;
3. start DNSdist, private PowerDNS, and DNS API with the `dns` profile;
4. register/test the cluster through its source-restricted HTTPS API;
5. enable and reconcile;
6. verify UDP/TCP, SOA, delegation, and Geo-DNS from outside.

Adding DNS capacity does not create an edge runtime.

## Move telemetry

Use the `telemetry` profile or a generated monitoring-role bundle with an exact
edge-source allowlist. Set control
`CLICKHOUSE_URL` and edge Vector endpoints to verified TLS. Keep ClickHouse and
Prometheus private. Prove that telemetry outage and backlog drain do not affect
serving.

## External control data

Set `DB_URL` and `REDIS_URL` in the generated control bundle to owner-operated
replicated services with verified
TLS, exact-source firewalls, backup, failover, and capacity plans. The repository
does not configure database replication or automatic failover.

All control replicas must share application encryption, artifact-signing,
session, database, queue, and edge identity material.

## Capacity gate

Before adding traffic, measure:

- DNS UDP/TCP latency and backend saturation;
- edge request, connection, TLS, origin, cache, temporary-storage, memory, and CPU pressure;
- queue depth and age per lane;
- PostgreSQL and Valkey latency;
- Vector buffer and ClickHouse insert/query load;
- backup duration and restore time.

The bundled throughput and scale tests provide correctness evidence on their
host only. They are not a universal production capacity promise.
