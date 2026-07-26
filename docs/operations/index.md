---
title: Operations
description: Operate health, reconciliation, backups, incidents, and capacity for CDNFoundry.
---

# Operations

The administrator dashboard and `/api/admin/system/components` expose dependency,
queue, scheduler, backup, MMDB, TLS, runtime-task, and edge-capacity state.
Prometheus scrapes the token-protected `/metrics` endpoint plus Vector,
DNSdist, PowerDNS, Alertmanager, and node-exporter.

- Use [Monitoring](/operations/monitoring) for health and alert interpretation.
- Use [Backup and recovery](/operations/backup-and-recovery) before an incident.
- Use [Incident runbooks](/operations/runbooks) for bounded recovery steps.
- Use [Scaling](/operations/scaling) to add capacity without adding per-domain processes.
- Use [Troubleshooting](/troubleshooting/) for symptom-first diagnosis.
