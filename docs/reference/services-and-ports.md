---
title: Services and ports
description: Map CDNFoundry Compose services, profiles, listeners, networks, and durable volumes.
---

# Services and ports

::: danger A bind is not a firewall policy
Wildcard and private listener binds still require provider and host firewall
rules. Keep databases, control endpoints, metrics, cell diagnostics, and
telemetry internals off public networks.
:::

## Production profiles

| Profile | Services |
| --- | --- |
| `control` | `core`, `web`, `edge-control`, `horizon`, `scheduler`, local `control-db`, local `redis` |
| `dns` | `pdns-db`, `pdns-auth`, `dnsdist`, `mmdb-updater` |
| `telemetry` | `clickhouse`, `vector`, `prometheus`, `node-exporter`, `alertmanager`, `grafana`, `grafana-control-db-provision` |
| `edge` | `cell-01` through `cell-08`, `edge-agent`, `edge-gateway`, `vector`, `mmdb-updater` |
| `logs` | one `log-collector` on the current host; combine once with its role profile |
| `tools` | explicit `migrate` and `pdns-migrate` one-shot services |

`compose.prod.yml` is the only production Compose source. Profiles select
long-running roles; Fleet-generated per-node manifests filter those services
and can point Laravel, Valkey clients, Vector, or Grafana at typed external
data endpoints.

## Production listeners

| Listener | Default host bind | Exposure |
| --- | --- | --- |
| Browser/API web | `127.0.0.1:8080` | The `control` profile's Caddy service publishes HTTPS |
| Edge control mTLS | `0.0.0.0:8443` | Restrict to registered edge sources |
| DNSdist | `${DNS_BIND_V4}:53` TCP and UDP | Dual-homed on `ingress` and `dns-private`; public authoritative DNS with a private PowerDNS backend |
| Cell slot host diagnostics | loopback `18081`–`18088`, `18444`–`18451`, `19081`–`19088` | HTTP, HTTPS, and status; never public |
| Edge gateway | mapped local service IPv4/IPv6 TCP `80`, `443` | Public/NAT ingress maps one-to-one to local listeners; TLS passes through |
| Gateway metrics | TCP `9105` | Restrict to edge agent and monitoring |
| Cell gateway contract | TCP `8081`, `8444` | Private gateway-to-cell network; PROXY protocol version 2 required |
| DNS API Caddy | `${HOST_BIND_IPV4}:8444` | Dual-homed on `ingress` and `dns-private`; exact-source allowlist and TLS protect external access |
| Telemetry Caddy | `${HOST_BIND_IPV4}:8444` | Dual-homed on `ingress` and `telemetry`; exact-source allowlist and TLS; routes Loki push and ClickHouse ingestion privately |
| Grafana | `127.0.0.1:3000` | Operator UI; publish only through an authenticated HTTPS reverse proxy |
| Loki (development only) | `127.0.0.1:3100` | Direct diagnostics; production has no host publication |
| Operational collector metrics | `127.0.0.1:9599` | Bind to a private monitoring address for remote scraping |

Host binds are deliberately separate from the public, routed, or NAT addresses
advertised in DNS. The default IPv4 wildcard works when those addresses exist
only on an external firewall or load balancer. Configure IPv6 only when the
host has a working route, firewall policy, local bind, and externally published
AAAA/service address; otherwise retain the documented nullable IPv6 values.

The edge gateway is stricter than the shared host publications: every
control-plane service endpoint must be mapped one-to-one to a distinct local
address in `EDGE_GATEWAY_ADDRESS_MAP`. The production agent rejects an
unmapped endpoint, so it never binds the advertised public/NAT address.

PowerDNS `8081`, DNSdist statistics `8083`, Vector ingestion `8686`/`8687`,
Vector traffic metrics `9598`, Loki `3100`, operational Vector metrics `9599`, ClickHouse `8123` and exporter `9363`, Prometheus `9090`, Alertmanager
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
`pdns-db`, `clickhouse`, `vector-data`, `operational-vector-data`, `loki-data`, `prometheus`, `grafana-data`, `edge-state`,
`edge-agent-state`, `mmdb`, and Caddy data/config volumes.

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
