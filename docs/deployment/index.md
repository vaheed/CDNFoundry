---
title: Production deployment
description: Deploy CDNFoundry production profiles with immutable images and explicit migrations.
---

# Production deployment

Production uses `compose.prod.yml`, `.env.prod`, immutable GHCR image tags, and
optional files under `deploy/production/`. It does not build application images
on production hosts and never migrates a database during container startup.

::: tip Recommended starting point
Use the [Production quick start](production-quick-start.md) for the
complete three-host sequence: bootstrap DNS, private PKI, explicit migrations,
cluster qualification, edge enrollment, acceptance checks, and diagnosis.
:::

The minimum documented layout is one control/telemetry host plus two combined
DNS/edge hosts. The base file also supports colocated development-like
qualification, while overlays expose split roles with restricted TLS gateways.

Before deploying, read:

1. [Production reference architectures](../architecture/production-reference-architectures.md)
   to choose failure domains and role placement.
2. [Production best practices](../operations/production-best-practices.md) for
   the readiness and change contract.
3. [Production quick start](production-quick-start.md) for an end-to-end first installation.
4. [Topology](topology.md) for networks, profiles, and public ports.
5. [Certificates](certificates.md) for the edge-control and DNS API PKI.
6. [Configuration](../reference/configuration.md) for every `.env.prod` key.
7. [Upgrade](upgrade.md) for schema, worker, DNS, and edge sequencing.

The [Production quick start](production-quick-start.md) is the
authoritative first-install procedure. The remaining deployment pages explain
individual decisions and are linked from that runbook where they become
relevant.

## Deployment rules

- Pin `CDNF_RELEASE` to an exact commit SHA or exact semantic release tag.
- Keep `.env.prod` mode `0600` and outside version control.
- Run `make prod-migrate` and `make prod-pdns-migrate` explicitly.
- Start only the profiles assigned to the host.
- Keep the control database, Valkey, PowerDNS, PowerDNS PostgreSQL, ClickHouse,
  internal metrics, and Grafana port 3000 off public networks.
- Preserve the control PostgreSQL volume and all named volumes during upgrades.

See [Production quick start](production-quick-start.md) for the
verified command sequence and [Topology](topology.md) for the role and
overlay model.
