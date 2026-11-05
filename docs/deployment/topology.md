---
title: Production topology and Compose roles
description: Learn the CDNFoundry host roles (control, DNS, edge, telemetry), the Compose profile each one runs, the networks, and the public ports, with the manual command sequence for a first install.
---

# Production topology and Compose roles

This page explains the host roles and the Compose profile each role runs. It also gives a compact command map for a manual install.

**You need:** to know how many hosts you have and which failure domains (rack, site, or provider) they sit in. See [reference architectures](../architecture/production-reference-architectures.md).

::: info Procedure versus reference
Use the [Production quick start](production-quick-start.md) for the complete first-install sequence, its prerequisites, acceptance checklist, and failure diagnosis. Fleet generates the per-host bundles for you. The commands below show what runs on each host.
:::

## Minimum topology

The smallest serving layout is three hosts:

1. `CONTROL` runs the `control` profile and the public Caddy ingress (the web server that terminates HTTPS for the panels and API).
2. `EDGE_1` and `EDGE_2` each run the `dns` and `edge` profiles.

After DNS and HTTP serving pass your checks, you can enable the `telemetry` profile on `CONTROL`, and one `logs` collector on each host. This adds ClickHouse, Prometheus, Alertmanager, Grafana, Loki, exporters, and central operational logs. None of them is in the serving path.

`EDGE_1` and `EDGE_2` give you two distinct authoritative nameserver addresses and two edge addresses. One control node is a supported production setup. While it is down, DNS and HTTP keep serving from the last valid state, and recovery is a restore from backup. ClickHouse, backup storage, external firewalls, routing, and failure procedures are your responsibility.

To keep the control plane itself available, run `cdnfoundry-fleet control add-standby` to add a warm standby control node at any time. `control remove-standby` removes it. See [Control-plane high availability](../operations/control-plane-high-availability.md). This is two nodes and a one-command promotion, not automatic failover.

::: danger Management DNS is an external dependency
Host the operator DNS zone for the `control`, `edge-control`, `telemetry`, and `grafana` management names outside CDNFoundry. Never serve that zone from CDNFoundry PowerDNS. If CDNFoundry DNS fails, you still need these names to reach the control plane.

Use a separate platform DNS zone for the CDNFoundry nameservers, proxy hostnames, and pool records. CDNFoundry keeps those records as desired state and builds its private PowerDNS data from them.
:::

## Deployment sequence overview

### 1. Prepare hosts and DNS

Install Docker Engine, the Compose plugin, GNU Make, Git, OpenSSL, and a host
firewall. Create public records for:

- `control.<operator-zone>` → `CONTROL`;
- `edge-control.<operator-zone>` → `CONTROL`;
- `telemetry.<operator-zone>` → control/telemetry host.

At the parent zone, create nameserver host records (glue) for `ns1.<platform-zone>` and `ns2.<platform-zone>`. Add IPv6 records only where the host and firewall are ready.

### 2. Install one immutable release

Check out the same revision on every host. Set `CDNF_RELEASE` to an exact 40-character commit SHA that passed CI, or to an exact `vMAJOR.MINOR.PATCH` tag. Log in to GHCR if the packages are private.

```sh
make prod-pull
```

**Expected result:** every image pulls by digest. Do not deploy mutable `latest`, major, or minor tags.

### 3. Create host-private environments

The helper asks questions and supports the minimum `control` role and the combined `dns-edge` role:

```sh
scripts/generate-production-env.sh --output .env.prod
chmod 600 .env.prod
```

Run it on each host. It refuses to overwrite an existing file. Review every key against
[Configuration](../reference/configuration.md). For split DNS-only, edge-only, or
telemetry-only hosts, copy `.env.prod.example` and populate only the owning
profile's required values.

### 4. Create secret files

Create the metrics token file at its absolute path, owned by `root:www-data` with mode `0640`. The generated activation script sets this ownership, so the PHP worker and the group used by Prometheus can both read it.

If you enable the optional S3-compatible Restic backup, also create its separate password file, initialize the off-host repository, and limit the backup credentials to the CDNFoundry bucket and prefix. Empty backup settings do not block startup. They leave backup health degraded until you record another recovery method.

### 5. Generate internal PKI

On an offline or protected administration host:

```sh
scripts/generate-production-certificates.sh \
  /secure/cdnfoundry-pki \
  edge-control.ops.example.com \
  edge-runtime.ops.example.com
```

Copy only the files described in [Certificates](certificates.md) to each host. Never copy certificate authority private keys to edge hosts.

### 6. Validate configuration

From the repository:

```sh
make config-check
docker compose --env-file .env.prod -f compose.prod.yml config --quiet
```

**Expected result:** both commands exit without errors. Validate each exact profile combination before you start it. `compose.prod.yml` defines the `control`, `dns`, `edge`, `telemetry`, `logs`, and one-shot `tools` profiles. A generated Fleet bundle keeps only the services assigned to that node.

### 7. Start `CONTROL`

Start the database dependencies, wait for their health checks, run the migration, and only then start the application processes. `make prod-migrate` does the first two commands:

```sh
docker compose --env-file .env.prod \
  -f compose.prod.yml \
  --profile control up -d --wait --wait-timeout 120 control-db redis
docker compose --env-file .env.prod \
  -f compose.prod.yml \
  --profile tools run --rm migrate
docker compose --env-file .env.prod \
  -f compose.prod.yml \
  --profile control up -d
```

Create the first administrator in the `core` container with `php artisan cdnf:admin:create` (options `--name` and `--email`). Then check `/api/health`, `/api/ready`, the administrator component health page, and Horizon. If the built-in backup is enabled, create its first verified snapshot.

If you enable monitoring, each host must use its generated, stable `LOG_HOST`, `LOG_ROLE`, and `LOG_COLLECTOR_ID`, and must run `--profile logs` once. Grafana listens on loopback (`GRAFANA_BIND=127.0.0.1:3000` by default) until you add a separately authenticated HTTPS reverse proxy. See the [Grafana runbook](../operations/grafana.md).

### 8. Start `EDGE_1` and `EDGE_2`

The PowerDNS database (`pdns-db`) and its runtime migration belong to the control host, where the hidden PowerDNS primary runs. Apply that migration once on `CONTROL`, before you start the DNS nodes (`make prod-pdns-migrate` does this):

```sh
docker compose --env-file .env.prod \
  -f compose.prod.yml \
  --profile control up -d --wait --wait-timeout 120 pdns-db
docker compose --env-file .env.prod \
  -f compose.prod.yml \
  --profile tools run --rm pdns-migrate
```

Then start the `dns` profile on each DNS host:

```sh
docker compose --env-file .env.prod \
  -f compose.prod.yml \
  --profile dns up -d
```

**Expected result:** DNSdist answers UDP and TCP on port 53. The GeoIP (MMDB) updater must activate a valid database before PowerDNS becomes ready, and DNSdist starts after PowerDNS is healthy.

A DNS node keeps a local SQLite store that the primary can rebuild, so it needs no database setup. It has no inbound management endpoint. Its agent configures the local PowerDNS and pulls zones from the hidden primary (see [DNS nodes](../operations/dns-nodes.md)). Never publish PowerDNS port 8081 on any host. Publish the primary's transfer port 5300 only to the DNS node addresses.

### 9. Configure platform state

In the administrator panel:

<div class="steps">

1. Register the DNS primary as disabled (API URL, key, and transfer endpoint), test it, then enable it.
2. Configure and apply the system DNS identity. Wait until every cluster acknowledges the platform revision.
3. Create the shared and quarantine pools if they are missing.
4. Create the `EDGE_1` and `EDGE_2` rows. Copy each one-time UUID and token.
5. Assign bounded cells to pools. Create one public service endpoint pair (IPv4 and IPv6) for each participating edge and pool.

</div>

On each prepared edge host, put the edge's `EDGE_ID` and `EDGE_BOOTSTRAP_TOKEN` in `.env.prod`, then run:

```sh
sudo docker compose --env-file .env.prod --profile edge up -d
```

If you start the whole host with the local `start.sh` script, add `--profile edge` to its Compose command so later starts include the role.

**Expected result:** the edge shows a registered identity and fresh, ready cells. Wait for both before you publish the endpoint.

### 10. Qualify traffic

Delegate a test customer domain and follow [First domain](../getting-started/first-domain.md). From networks outside your hosts, verify:

- DNSdist answers over UDP and TCP;
- IPv4 HTTP and HTTPS work;
- IPv6 works, where you enabled it;
- managed TLS issues, and renewal is visible;
- origin safety checks and forwarded headers behave as documented;
- a cache hit, development mode, a full purge, and a URL purge work;
- security rules and quarantine behave as expected;
- telemetry and usage appear without affecting serving, if you enabled the `telemetry` profile;
- the backup and recovery procedure works.

Record the exact revision, the topology, operation IDs, certificate fingerprints, the checks you ran, and any deviations.

## Split-role profiles

| Profile | What it runs |
| --- | --- |
| `control` | Laravel, web ingress, Horizon, the Scheduler, edge-control, the hidden PowerDNS primary and its PostgreSQL database (`pdns-db`), and the default local PostgreSQL and Valkey. |
| `dns` | DNSdist and a PowerDNS secondary with a local SQLite store. |
| `edge` | The edge agent, the destination, Host, and SNI gateway, the bounded cells, and the edge traffic Vector. |
| `telemetry` | ClickHouse, Prometheus, Alertmanager, Grafana, Loki, and telemetry ingress. |
| `logs` | One host log collector. |
| `tools` | One-shot helpers such as the explicit migrations. It is not a long-running role. |

Fleet can render hosts with separated roles and external data endpoints, so you do not edit Compose. Review each generated Compose manifest and `.env.prod` for its node. Do not assume that services from another role are present.

## Public ports

| Port | Host role | Open to |
| --- | --- | --- |
| 53 UDP and TCP | DNS (DNSdist) | The Internet |
| 80 and 443 | Edge gateway | The Internet |
| Browser and API HTTPS | Control | Operators and API clients, through the authenticated reverse proxy in front of Caddy (`CONTROL_BIND` is `127.0.0.1:8080` by default) |
| 8443 | Control (edge-control, `EDGE_CONTROL_BIND`) | Edge hosts, with mutual TLS |
| 5300 | Control (PowerDNS primary) | DNS node addresses only |

Everything else stays private, including 8081 (PowerDNS API), databases, Valkey, ClickHouse, and Grafana. The full list is in [Services and ports](../reference/services-and-ports.md).

Use [Scaling](../operations/scaling.md) before splitting data services or adding
workers.
