---
title: Production deployment
description: Start here to deploy CDNFoundry to production. Pick Fleet or manual Docker Compose, then follow the ordered pages for install, certificates, and upgrades.
---

# Production deployment

This page helps you choose how to deploy CDNFoundry and shows which page to read next.

**You need:** Linux servers you control (at least three for the smallest production layout), a domain whose nameservers you can change, and root SSH access to the hosts.

## How production works

Production uses `compose.prod.yml`, a per-host `.env.prod` file, and immutable image references from GHCR (the GitHub container registry). Some hosts also use files under `deploy/production/`.

- Hosts never build application images. They pull images that CI built and signed.
- Container startup never migrates a database. You run each migration as an explicit step.
- Each host starts only the Compose profiles assigned to its role.

## Choose a path

<CardGrid>
<Card title="Fleet (recommended)" link="/deployment/production-quick-start">
Copy a JSON topology, validate it, and let Fleet generate and deploy a bundle for every node.
</Card>
<Card title="Manual Docker Compose" link="/deployment/manual-compose">
Run the same three-host layout with hand-managed environments, secrets, certificates, and migrations. It uses no repository scripts or Make targets.
</Card>
</CardGrid>

The [production quick start](production-quick-start.md) is the first-install procedure. It covers bootstrap DNS, private certificates, explicit migrations, cluster checks, edge enrollment, acceptance checks, and diagnosis. The other pages explain single decisions and are linked from it where they matter.

For several regions or separated roles, use the [multi-region quick start](production-quick-start-multi-region.md).

## Layout

The smallest documented layout is one control and telemetry host plus two combined DNS and edge hosts. The same Compose file also supports a single host for development-like qualification. Compose profiles and generated bundles split the roles when you need more hosts. See [Topology](topology.md).

## Read before you deploy

1. [Production reference architectures](../architecture/production-reference-architectures.md): choose failure domains and where each role runs.
2. [Production best practices](../operations/production-best-practices.md): what must be ready before and after a change.
3. [Topology](topology.md): networks, profiles, and public ports.
4. [Certificates](certificates.md): the edge-control and DNS API certificate authorities.
5. [Configuration](../reference/configuration.md): every `.env.prod` key.
6. [Production quick start](production-quick-start.md): the first install, end to end.
7. [Production setup after the quick start](production-setup-after-quick-start.md): administrator handoff and daily operations.
8. [Upgrade](upgrade.md): schema, worker, DNS, and edge order, and rollback.

Fleet details are in the [Fleet operator guide](production-fleet-operator-guide.md), the [Fleet configuration reference](production-fleet-config-reference.md), and the [Fleet architecture reference](production-fleet.md).

## Rules that apply to every deployment

- Set `CDNF_RELEASE` to an exact commit SHA or an exact semantic release tag. Never use a mutable tag.
- Keep `.env.prod` at mode `0600` and out of version control.
- Run `make prod-migrate` on the control host and `make prod-pdns-migrate` where the PowerDNS database runs (the control host). Run them before you start application processes.
- Start only the profiles assigned to the host.
- Keep these off public networks: the control database, Valkey, PowerDNS, the PowerDNS database, ClickHouse, internal metrics, and Grafana port 3000.
- Never remove named volumes during an upgrade. The control PostgreSQL volume must survive.

**Check it worked:** after the first install, `/api/health` and `/api/ready` on the control host answer, and the quick start's acceptance checks pass.
