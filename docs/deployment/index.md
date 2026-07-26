---
title: Production deployment
description: Deploy CDNFoundry production profiles with immutable images and explicit migrations.
---

# Production deployment

Production uses `compose.prod.yml`, `.env.prod`, immutable GHCR image tags, and
optional files under `deploy/production/`. It does not build application images
on production hosts and never migrates a database during container startup.

The minimum documented layout is one control/telemetry host plus two combined
DNS/edge hosts. The base file also supports colocated development-like
qualification, while overlays expose split roles with restricted TLS gateways.

Before deploying, read:

1. [Topology](/deployment/topology) for networks, profiles, and public ports.
2. [Certificates](/deployment/certificates) for the edge-control and DNS API PKI.
3. [Configuration](/reference/configuration) for every `.env.prod` key.
4. [Upgrade](/deployment/upgrade) for schema, worker, DNS, and edge sequencing.

The complete installation procedure appears later on this page after the
topology and secret prerequisites are understood.

## Deployment rules

- Pin `CDNF_RELEASE` to an exact commit SHA or exact semantic release tag.
- Keep `.env.prod` mode `0600` and outside version control.
- Run `make prod-migrate` and `make prod-pdns-migrate` explicitly.
- Start only the profiles assigned to the host.
- Keep the control database, Valkey, PowerDNS, PowerDNS PostgreSQL, ClickHouse,
  and internal metrics off public networks.
- Preserve the control PostgreSQL volume and all named volumes during upgrades.

See [Production procedure](/deployment/topology#production-procedure) for the
verified command sequence.
