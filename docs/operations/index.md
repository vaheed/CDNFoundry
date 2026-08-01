---
title: Operations
description: Operate health, reconciliation, backups, incidents, and capacity for CDNFoundry.
---

# Operations

- [Atomic edge runtime generations](runtime-generations.md)

::: warning A green control plane is not end-user proof
Combine dashboard state with authoritative DNS, gateway, origin, TLS, and
external client probes. A healthy API alone does not prove that customer
traffic reaches both failure domains.
:::

See [Edge gateway ingress](gateway-ingress.md) for service-address binding,
Host/SNI routing, migration, last-valid recovery, metrics, and scale evidence.
See [Pool service endpoints](pool-service-endpoints.md) for Geo-Unicast and
[Simple Anycast pools](simple-anycast.md) for operator-routed shared POP pairs.
The [Simple Anycast qualification](simple-anycast-qualification.md) records
agent evidence and the remaining owner-operated release gate.

Read [Production best practices](production-best-practices.md) before the first
release or topology change. It consolidates network, secret, DNS, edge, origin,
cache, TLS, observability, backup, capacity, and change-control expectations.

The administrator dashboard and `/api/admin/system/components` expose dependency,
queue, scheduler, backup, MMDB, TLS, runtime-task, and edge-capacity state.
Prometheus scrapes the token-protected `/metrics` endpoint plus Vector,
DNSdist, PowerDNS, Alertmanager, and node-exporter.

Use [Laravel operations dashboard](laravel-operations-dashboard.md) for the
administrator overview's investigation context, operational condition rules,
derived conditions, refresh strategy, cache isolation, and unsupported metrics.

- Use [Monitoring](monitoring.md) for health and alert interpretation.
- Use [Production best practices](production-best-practices.md) for readiness
  and change checklists.
- Use [Grafana command centers](grafana.md) for the provisioned system and
  per-domain operational views.
- Use [Operational logging](operational-logging.md) for Loki, per-host Vector
  collectors, redaction, live tail, and failure recovery.
- Use [Bounded cell inventory](cell-inventory.md) and
  [Multi-cell pools](multi-cell-pools.md) for stable slot assignment, capacity,
  movement, and recovery.
- Use [Backup and recovery](backup-and-recovery.md) before an incident.
- Use [Incident runbooks](runbooks.md) for bounded recovery steps.
- Use [Scaling](scaling.md) to add capacity without adding per-domain processes.
- Use [Bounded fleet rollouts](fleet-rollouts.md) for immutable canary upgrades.
- Use [Production qualification](production-qualification.md) for the final
  two-POP release evidence and decision.
- Use [Troubleshooting](../troubleshooting/index.md) for symptom-first diagnosis.
