---
title: Services and ports
description: Map CDNFoundry Compose services, profiles, listeners, networks, and durable volumes.
---

# Services and ports

::: danger A bind is not a firewall policy
A listener address is not a firewall rule. Even a private bind needs provider
and host firewall rules. Keep databases, control endpoints, metrics, cell
diagnostics, and telemetry services off public networks.
:::

A Compose profile is a named group of services. Start the profiles that match
the role of each host. This table lists the long-running services in each
profile of `compose.prod.yml`.

## Production profiles

| Profile | Services |
| --- | --- |
| `control` | `caddy`, `core`, `web`, `edge-control`, `horizon`, `scheduler`, `control-db`, `redis`, `cache`, `pdns-db`, `pdns-primary`, `mmdb-updater`, `node-exporter` |
| `dns` | `pdns-auth`, `dnsdist`, `vector`, `node-exporter`, `mmdb-updater` |
| `telemetry` | `telemetry-gateway`, `clickhouse`, `vector`, `loki`, `prometheus`, `grafana`, `alertmanager`, `blackbox`, `node-exporter`, and one-shot setup services (`loki-data-init`, `grafana-plugins-init`, `grafana-control-db-provision`, `grafana-bootstrap`) |
| `edge` | `cell-01` through `cell-08`, `edge-agent`, `edge-gateway`, `edge-gateway-state-init`, `vector`, `node-exporter`, `mmdb-updater` |
| `logs` | `log-collector` and `docker-socket-proxy` on the current host. Combine it once with a role profile |
| `tools` | The one-shot `migrate`, `pdns-migrate`, and `clickhouse-migrate` services. Run them on purpose |

`compose.prod.yml` is the only production Compose file. Fleet generates a
manifest for each node. That manifest keeps only the services the node needs.
It can also point Laravel, Valkey clients, Vector, or Grafana at external data
endpoints.

## Production listeners

| Listener | Default host bind | Exposure |
| --- | --- | --- |
| Browser and API web (`web`) | `${CONTROL_BIND}`, default `127.0.0.1:8080` | Caddy in the `control` profile publishes HTTPS on `${HOST_BIND_IPV4}` ports 80 and 443 (TCP) and 443 (UDP) |
| Edge control mTLS | `${EDGE_CONTROL_BIND}`, default `0.0.0.0:8443` | Restrict to registered edge sources |
| DNSdist | `${DNS_BIND_V4}:53` TCP and UDP | Dual-homed on `ingress` and `dns-private`; public authoritative DNS with a private PowerDNS backend |
| Cell host diagnostics | loopback `18081` to `18088` (HTTP), `18444` to `18451` (HTTPS), `19081` to `19088` (status) | Never public |
| Edge gateway | The service IPv4 and IPv6 addresses, TCP `80` and `443` | The gateway runs on the host network. Public or NAT addresses map one-to-one to local listeners. TLS passes through |
| Gateway metrics | `${EDGE_GATEWAY_METRICS_ADDRESS}`, default `0.0.0.0:9105` | Restrict to the edge agent and monitoring |
| Cell listeners inside the cell | TCP `8081` (HTTP), `8444` (HTTPS) | Private gateway-to-cell network. PROXY protocol version 2 is required |
| DNS primary transfers | `${HOST_BIND_IPV4}:5300` (TCP and UDP) | Control host only; transfers are refused unless TSIG-signed. Restrict it to the node addresses |
| Telemetry gateway (Caddy) | `${HOST_BIND_IPV4}` ports 80, 443, and 8444 | Joins the `ingress` and `telemetry` networks. Uses an exact-source allowlist and TLS. Routes Loki push and ClickHouse ingestion privately |
| Grafana | `${GRAFANA_BIND}`, default `127.0.0.1:3000` | Operator UI. Publish it only through an authenticated HTTPS reverse proxy |
| Loki (development only) | `127.0.0.1:3100` | Direct diagnostics; production has no host publication |
| Operational collector metrics | `${LOG_METRICS_BIND}` port `9599` | Bind to a private monitoring address for remote scraping |
| Vector traffic metrics | `${VECTOR_METRICS_BIND}` port `9598` | Bind to a private monitoring address |

A host bind is separate from the public, routed, or NAT address that DNS
advertises. The default IPv4 wildcard works when the public address exists only
on an external firewall or load balancer. Configure IPv6 only when the host has
a working route, a firewall policy, a local bind, and a published AAAA or
service address. Otherwise leave the IPv6 values empty.

The edge gateway binds an advertised service address directly when the host owns
it. For NAT or a load balancer, map each advertised address one-to-one to a
distinct private local listener with `EDGE_GATEWAY_ADDRESS_MAP`. To require a
mapping for every endpoint, set `EDGE_GATEWAY_REQUIRE_ADDRESS_MAP=true`.

These ports stay inside containers in the intended production topology:
PowerDNS `8081`, DNSdist statistics `8083`, Vector ingestion `8686` and `8687`,
Loki `3100`, ClickHouse `8123` and its exporter `9363`, Prometheus `9090`,
Alertmanager `9093`, PostgreSQL `5432`, Valkey `6379`, and OpenResty control
`9080`.

## Networks

| Network | Property |
| --- | --- |
| `control` | Internal control plane |
| `fpm` | Internal. Only `core` and the two Nginx containers join. PHP-FPM listens here alone |
| `dns-private` | Internal. PowerDNS and its API |
| `telemetry` | Internal telemetry |
| `docker-api` | Internal. Only the log collector and its read-only Docker API proxy |
| `ingress` | Reverse-proxy ingress |
| `edge` | Edge runtime and agent. Cells trust PROXY protocol headers only from this network's subnet (`EDGE_NETWORK_SUBNET`) |
| `egress` | Explicit outbound access |

Compose network isolation complements, but does not replace, provider and host
firewalls.

## Durable volumes

`compose.prod.yml` defines these volumes: `core-storage`, `control-db`,
`redis`, `pdns-db`, `pdns-node`, `clickhouse`, `vector-data`,
`operational-vector-data`, `loki-data`, `loki-tools`, `prometheus`,
`grafana-data`, `grafana-plugins`, `edge-agent-state`, `edge-gateway-state`,
`mmdb`, one cache volume for each cell (`cell-01-cache` to `cell-08-cache`),
and the Caddy data and config volumes for both Caddy services.

`edge-runtime-memory` is a tmpfs volume of 512 MB shared by the edge agent and
the cells. It lives in memory and is empty after a restart. The persistent
`edge-agent-state` volume holds an encrypted recovery snapshot of the runtime.
The key for that snapshot stays in external secret storage.

Do not remove these volumes during a routine stop, upgrade, or test. To recover,
you need the control database, its encryption and signing keys, and your
externally stored TLS material. The PowerDNS database and the edge snapshots
can be rebuilt, but keeping them makes recovery faster.

## Development services

The development stack adds persistent dependency and bootstrap volumes and two
simulated edge nodes, `edge-a` and `edge-b`. Each node has a shared cell and a
quarantine cell, an agent, and a gateway. It also adds Pebble (a test ACME
server), origin test servers, PowerAdmin, and a development PKI. The
[developer setup](../development/index.md) lists the host ports.

PowerAdmin is for development diagnosis only. It is not part of production.
