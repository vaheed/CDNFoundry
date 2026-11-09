---
title: Operations
description: "Start here to run a CDNFoundry fleet day to day: health, monitoring, upgrades, backups, incidents, and capacity."
---

# Operations

This section is for the people who keep a CDNFoundry fleet running. Pick the page that matches what you are doing now.

::: warning A green control plane is not end-user proof
The control plane can be healthy while customers cannot reach your CDN. Combine dashboard state with probes from
outside: authoritative DNS, the gateway, the origin, TLS, and an external client. A healthy API alone does not
prove that customer traffic reaches both failure domains.
:::

## Before the first release

- [Production best practices](production-best-practices.md): network, secret, DNS, edge, origin, cache, TLS,
  observability, backup, capacity, and change-control checklists. Read it before the first release or any topology change.
- [Production qualification](production-qualification.md): the two-POP acceptance gate and its evidence.
- [v2.0.3 release notes](releases/v2.0.3.md): Fleet fixes from real-host testing, monitoring and backup settings in Fleet web, and a step-by-step HA guide.
- [v2.0.2 release notes](releases/v2.0.2.md): a live action page, hosts that prepare together, an inventory, and an optional Alertmanager webhook.
- [v2.0.1 release notes](releases/v2.0.1.md): Fleet picks, signs, and installs the release for you, and the Fleet web has a new layout.
- [v2.0.0 release notes](releases/v2.0.0.md): what changed, what is qualified, and what stays with the operator.

## See what is happening

- [Monitoring](monitoring.md): health and alert interpretation.
- [Laravel operations dashboard](laravel-operations-dashboard.md): the admin **Overview** page, its filters, data
  sources, refresh times, and unsupported metrics.
- [Grafana dashboards](grafana.md): the provisioned system and per-domain views.
- [Operational logging](operational-logging.md): Loki, per-host Vector collectors, redaction, live tail, and recovery.

The admin dashboard and the `/api/admin/system/components` endpoint report dependency, queue, scheduler, backup,
MMDB, TLS, runtime-task, and edge-capacity state. Prometheus scrapes the token-protected `/metrics` endpoint, plus
Vector, DNSdist, Alertmanager, and node-exporter. PowerDNS stays private. You observe its backend through DNSdist
metrics.

## Change the fleet

- [Fleet engine, vault, and local web](fleet-engine.md): how Fleet stores state, reaches nodes, and upgrades from
  signed release manifests.
- [Bounded fleet rollouts](fleet-rollouts.md): upgrade the edge runtime image set in canary waves through the API, with pause, resume, and rollback.
- [Atomic edge runtime generations](runtime-generations.md): how an edge publishes, validates, recovers, and rolls back its runtime configuration.
- [Software supply-chain verification](software-supply-chain.md): verify image digests, signatures, SBOMs, provenance, and scans.
- [Signing-key rotation](signing-key-rotation.md): replace the artifact signing key without re-enrolling any PoP.
- [Scaling](scaling.md): add capacity without adding per-domain processes.
- [Bounded cell inventory](cell-inventory.md) and [Multi-cell pools](multi-cell-pools.md): slot assignment,
  capacity, movement, and recovery.

## Edge traffic and addresses

- [Edge gateway ingress](gateway-ingress.md): service-address binding, Host/SNI routing, migration, last-valid
  recovery, metrics, and scale evidence.
- [Pool service endpoints](pool-service-endpoints.md): Geo-Unicast addressing.
- [Simple Anycast pools](simple-anycast.md): operator-routed shared POP pairs.
  [Simple Anycast qualification](simple-anycast-qualification.md) records the agent evidence and the remaining owner-run release gate.
- [DNS nodes and the hidden primary](dns-nodes.md): how zones reach DNS nodes, and how to add or lose a node.
- [CDNFoundry CLI commands](cli-commands.md): the Artisan commands and their schedules.

## Prepare for and recover from failure

- [Control-plane high availability](control-plane-high-availability.md): add an optional warm standby control node.
- [Set up control-plane HA step by step](set-up-control-plane-ha.md): add the standby over a local network or WireGuard, check it, and practise a switchover.
- [Backup and recovery](backup-and-recovery.md): read it before an incident.
- [Incident runbooks](runbooks.md): bounded recovery steps.
- [Troubleshooting](../troubleshooting/index.md): diagnosis that starts from a symptom.
