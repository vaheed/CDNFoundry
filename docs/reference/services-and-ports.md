---
title: Services and ports
description: Map CDNFoundry Compose services, profiles, listeners, networks, and durable volumes.
---

# Services and ports

## Production profiles

| Profile | Services |
| --- | --- |
| `control` | `core`, `web`, `edge-control`, `horizon`, `scheduler`, local `control-db`, local `redis` |
| `dns` | `pdns-db`, `pdns-auth`, `dnsdist`, `mmdb-updater` |
| `telemetry` | `clickhouse`, `vector`, `prometheus`, `node-exporter`, `alertmanager` |
| `edge` | `cell-01` through `cell-08`, `edge-agent`, `edge-gateway`, `vector`, `mmdb-updater` |
| `tools` | explicit `migrate` and `pdns-migrate` one-shot services |

`deploy/production/compose.external-control-data.yml` replaces local
`control-db` and `redis` with configured external endpoints. The control, DNS,
edge, and telemetry host overlays add only role-specific publication and
gateways.

## Production listeners

| Listener | Default host bind | Exposure |
| --- | --- | --- |
| Browser/API web | `127.0.0.1:8080` | Publish through the control Caddy overlay |
| Edge control mTLS | `0.0.0.0:8443` | Restrict to registered edge sources |
| DNSdist | `${DNS_BIND_V4}:53` TCP and UDP | Public authoritative DNS |
| Cell slot host diagnostics | loopback `18081`–`18088`, `18444`–`18451`, `19081`–`19088` | HTTP, HTTPS, and status; never public |
| Edge gateway | operator service IPv4/IPv6 TCP `80`, `443` | Public ingress; TLS passes through |
| Gateway metrics | TCP `9105` | Restrict to edge agent and monitoring |
| Cell gateway contract | TCP `8081`, `8444` | Private gateway-to-cell network; PROXY protocol version 2 required |
| DNS API Caddy | `${PUBLIC_BIND_IPV4}:8444` | Exact-source allowlist, TLS |
| Telemetry Caddy | `${PUBLIC_BIND_IPV4}:8686`, `:8687` | Exact-source allowlist, TLS |

IPv6 publication exists only when the matching `compose.*-host-ipv6.yml`
overlay is supplied. Do not use a placeholder IPv6 address.

PowerDNS `8081`, DNSdist statistics `8083`, Vector ingestion `8686`/`8687`,
Vector metrics `9598`, ClickHouse `8123`, Prometheus `9090`, Alertmanager
`9093`, PostgreSQL `5432`, Valkey `6379`, and OpenResty control `9080` are
container-private in the intended production topology.

## Networks

| Network | Property |
| --- | --- |
| `control` | internal control plane |
| `dns-private` | internal PowerDNS and DNS API |
| `telemetry` | internal telemetry |
| `ingress` | reverse-proxy ingress |
| `edge` | edge runtime and agent |
| `egress` | explicit outbound access |

Compose network isolation complements, but does not replace, provider and host
firewalls.

## Durable volumes

The base production file defines `core-storage`, `control-db`, `redis`,
`pdns-db`, `clickhouse`, `vector-data`, `prometheus`, `edge-state`,
`edge-agent-state`, and `mmdb`. Caddy overlays add their data/config volumes.

Do not remove these volumes during routine stop, upgrade, or testing. Recovery
requires the control database plus its encryption/signing keys and external TLS
material; the PowerDNS database and edge snapshots are rebuildable but still
reduce recovery time when retained.

## Development services

The development topology adds persistent dependency/bootstrap volumes, two
shared and two quarantine cells, two optional agents, Pebble, origin fixtures,
PowerAdmin, and development PKI. Host publications are listed in
[Developer setup](../development/index.md).

PowerAdmin is enabled by the `devtools` profile used by `make dev-up`. It is not
part of production.
