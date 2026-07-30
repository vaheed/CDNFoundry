---
title: Operations
description: Operate health, reconciliation, backups, incidents, and capacity for CDNFoundry.
---

# Operations

See [Edge gateway ingress](gateway-ingress.md) for service-address binding,
Host/SNI routing, migration, last-valid recovery, metrics, and scale evidence.
See [Pool service endpoints](pool-service-endpoints.md) for Geo-Unicast and
[Simple Anycast pools](simple-anycast.md) for operator-routed shared POP pairs.
The [Simple Anycast qualification](simple-anycast-qualification.md) records
agent evidence and the remaining owner-operated release gate.

The administrator dashboard and `/api/admin/system/components` expose dependency,
queue, scheduler, backup, MMDB, TLS, runtime-task, and edge-capacity state.
Prometheus scrapes the token-protected `/metrics` endpoint plus Vector,
DNSdist, PowerDNS, Alertmanager, and node-exporter.

- Use [Monitoring](monitoring.md) for health and alert interpretation.
- Use [Grafana command centers](grafana.md) for the provisioned system and
  per-domain operational views.
- Use [Operational logging](operational-logging.md) for Loki, per-host Vector
  collectors, redaction, live tail, and failure recovery.
- Use [Backup and recovery](backup-and-recovery.md) before an incident.
- Use [Incident runbooks](runbooks.md) for bounded recovery steps.
- Use [Scaling](scaling.md) to add capacity without adding per-domain processes.
- Use [Bounded fleet rollouts](fleet-rollouts.md) for immutable canary upgrades.
- Use [Production qualification](production-qualification.md) for the final
  two-POP release evidence and decision.
- Use [Troubleshooting](../troubleshooting/index.md) for symptom-first diagnosis.
