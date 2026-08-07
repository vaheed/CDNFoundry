---
title: Production deployment
description: Deploy CDNFoundry production profiles with immutable images and explicit migrations.
---

# Production deployment

Production uses `compose.prod.yml`, `.env.prod`, immutable GHCR image tags, and
optional files under `deploy/production/`. It does not build application images
on production hosts and never migrates a database during container startup.

::: tip Recommended starting point
For a simplified deployment using a single configuration file, see the [Production Fleet Configuration Guide](production-config.md).

Use the [Production quick start](production-quick-start.md) for the
complete three-host sequence: bootstrap DNS, private PKI, explicit migrations,
cluster qualification, edge enrollment, acceptance checks, and diagnosis.
:::

The minimum documented layout is one control/telemetry host plus two combined
DNS/edge hosts. The base file also supports colocated development-like
qualification, while overlays expose split roles with restricted TLS gateways.

## Quick Start Options

### Option 1: Configuration File (Recommended for New Deployments)

Use a single INI-style configuration file to define your entire fleet:

```bash
# 1. Clone at specific version (production - recommended)
git clone --branch v1.0.0 --depth 1 https://github.com/vaheed/CDNFoundry.git
cd CDNFoundry

# For testing/development: clone a specific branch
git clone --branch dev --depth 1 https://github.com/vaheed/CDNFoundry.git
cd CDNFoundry

# Or clone and checkout a specific commit
git clone https://github.com/vaheed/CDNFoundry.git
cd CDNFoundry
git checkout <commit-sha>
```

> **Important**: Always deploy from a pinned release tag or commit SHA in production. Never use mutable tags like `latest`, `main`, or major/minor version tags.

```bash
# 2. Create fleet-config.ini (see production-config.md for template)
cp deploy/production/fleet-config.template fleet-config.ini
# Edit fleet-config.ini with your values

# 3. Generate fleet state
sudo CONFIG_FILE=fleet-config.ini ./scripts/cdnfoundry-fleet setup --non-interactive
```

See [Production Fleet Configuration Guide](production-config.md) for complete details.

### Option 2: Manual Step-by-Step

Follow the traditional approach in [Production quick start](production-quick-start.md).

## Documentation Index

Before deploying, read:

1. [Production reference architectures](../architecture/production-reference-architectures.md)
   to choose failure domains and role placement.
2. [Production best practices](../operations/production-best-practices.md) for
   the readiness and change contract.
3. [Production Fleet Configuration Guide](production-config.md) for config-driven deployments **OR**
   [Production quick start](production-quick-start.md) for manual step-by-step installation.
4. [Topology](topology.md) for networks, profiles, and public ports.
5. [Certificates](certificates.md) for the edge-control and DNS API PKI.
6. [Configuration](../reference/configuration.md) for every `.env.prod` key.
7. [Upgrade](upgrade.md) for schema, worker, DNS, and edge sequencing.

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
