---
title: Production topology and Compose roles
description: Understand CDNFoundry control, DNS, edge, and telemetry roles, networks, listeners, and Compose overlays.
---

# Production topology and Compose roles

::: info Procedure versus reference
This page explains the role model and gives a compact command map. Use the
[Production quick start](production-quick-start.md) for the complete
first-install sequence, its prerequisites, acceptance checklist, and failure
diagnostics.
:::

## Minimum topology

The smallest production-oriented layout documented by the repository is:

1. one control host running `control` and `telemetry`, plus the control Caddy overlay;
2. two combined DNS/edge hosts, each running `dns` and `edge` plus the DNS API overlay.

The two DNS/edge hosts provide distinct authoritative nameserver and edge
addresses. This layout is not automatic high availability: PostgreSQL, Valkey,
ClickHouse, backup storage, external firewalls, routing, and failure procedures
remain operator responsibilities.

Use one operator-owned DNS zone for control, edge-control, telemetry, and DNS API
hostnames. Use a separate platform DNS zone for CDNFoundry nameservers, proxy
hostnames, and pool records.

## Deployment sequence overview

### 1. Prepare hosts and DNS

Install Docker Engine, the Compose plugin, GNU Make, Git, OpenSSL, and a host
firewall. Create public records for:

- `control.<operator-zone>` → control host;
- `edge-control.<operator-zone>` → control host;
- `telemetry.<operator-zone>` → control/telemetry host;
- one `dns-api-N.<operator-zone>` → each DNS host.

Create nameserver host/glue records for `ns1.<platform-zone>` and
`ns2.<platform-zone>` at the parent. Add IPv6 only where the host and firewall
are actually ready.

### 2. Install one immutable release

Check out the same revision on every host. Set `CDNF_RELEASE` to an exact
40-character successful commit SHA or exact `vMAJOR.MINOR.PATCH` tag. Authenticate
to GHCR if the packages are private.

```sh
make prod-pull
```

Do not deploy mutable `latest`, major, or minor aliases.

### 3. Create host-private environments

The helper supports the minimum `control` and combined `dns-edge` roles:

```sh
scripts/generate-production-env.sh --output .env.prod
chmod 600 .env.prod
```

Run it separately on each host. Review every key against
[Configuration](../reference/configuration.md). For split DNS-only, edge-only, or
telemetry-only hosts, copy `.env.prod.example` and populate only the owning
profile's required values.

### 4. Create secret files

Create the metrics token and Restic password at the absolute paths in
`.env.prod`, mode `0600`. Initialize the off-host Restic repository using its
separately stored password. Restrict backup credentials to the CDNFoundry prefix.

### 5. Generate internal PKI

On an offline or protected administration host:

```sh
scripts/generate-production-certificates.sh \
  /secure/cdnfoundry-pki \
  edge-control.ops.example.com \
  edge-runtime.ops.example.com \
  "" \
  "" \
  dns-api-1.ops.example.com \
  dns-api-2.ops.example.com
```

Distribute only the files described in [Certificates](certificates.md).
Never copy CA private keys to edge hosts.

### 6. Validate configuration

From the repository:

```sh
make config-check
docker compose --env-file .env.prod -f compose.prod.yml config --quiet
```

Validate each exact overlay combination before starting it. The repository's
`scripts/validate-production-overrides.sh` qualifies control, combined DNS/edge,
DNS-only, edge-only, telemetry-only, opt-in IPv6, and external control-data
configurations with documentation addresses.

### 7. Start the control host

Run migrations before application processes:

```sh
make prod-migrate
docker compose --env-file .env.prod \
  -f compose.prod.yml \
  -f deploy/production/compose.control-host.yml \
  --profile control --profile telemetry up -d
```

Create the first administrator inside `core` with `cdnf:admin:create`. Check
`/api/health`, `/api/ready`, administrator component health, Horizon, Prometheus,
and the first verified backup.

### 8. Start each DNS/edge host

Apply the separate PowerDNS runtime migration, then start the profiles:

```sh
make prod-pdns-migrate
docker compose --env-file .env.prod \
  -f compose.prod.yml \
  -f deploy/production/compose.dns-edge-host.yml \
  --profile dns --profile edge up -d
```

The MMDB updater must activate a valid database before PowerDNS becomes ready.
DNSdist starts after PowerDNS health. The DNS API gateway exposes only a
source-restricted TLS endpoint; never publish PowerDNS port 8081 directly.

### 9. Configure platform state

In the administrator panel:

1. configure and apply system DNS identity;
2. register each DNS API cluster, test it, then enable it;
3. create shared and quarantine pools if they are not present;
4. create each edge row and copy its one-time UUID/token;
5. assign bounded cells to pools and create one public service endpoint pair for
   each participating edge/pool; keep management addresses distinct.

Put `EDGE_ID` and `EDGE_BOOTSTRAP_TOKEN` into the corresponding edge host, start
or restart `edge-agent`, wait for registered identity and fresh ready cells,
then remove the bootstrap token from `.env.prod`.

### 10. Qualify traffic

Delegate a test customer domain and use [First domain](../getting-started/first-domain.md).
From external networks, verify:

- DNSdist UDP and TCP;
- IPv4 HTTP and HTTPS;
- IPv6 only where enabled;
- managed TLS issuance and renewal visibility;
- origin safety and headers;
- cache hit, development mode, full purge, URL purge;
- security rule and quarantine behaviour;
- telemetry and usage without affecting serving;
- backup and recovery procedure.

Run the current non-browser qualification suite. Record the exact revision,
topology, operation IDs, certificate fingerprints, checks, and deviations.

## Split-role overlays

- `compose.dns-host.yml` adds the DNS API gateway to a DNS-only host.
- `compose.edge-host.yml` documents the base edge-only role; the base file owns its listeners.
- `compose.telemetry-host.yml` adds public, source-restricted telemetry TLS.
- `compose.external-control-data.yml` disables local PostgreSQL and Valkey.
- `*-ipv6.yml` files explicitly add IPv6 publications.

Use [Scaling](../operations/scaling.md) before splitting data services or adding
workers.
