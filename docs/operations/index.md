---
title: Operations
description: Operate health, reconciliation, backups, incidents, and capacity for CDNFoundry.
---

# Operations

The administrator dashboard and `/api/admin/system/components` expose dependency,
queue, scheduler, backup, MMDB, TLS, runtime-task, and edge-capacity state.
Prometheus scrapes the token-protected `/metrics` endpoint plus Vector,
DNSdist, PowerDNS, Alertmanager, and node-exporter.

- Use [Monitoring](monitoring.md) for health and alert interpretation.
- Use [Backup and recovery](backup-and-recovery.md) before an incident.
- Use [Incident runbooks](runbooks.md) for bounded recovery steps.
- Use [Scaling](scaling.md) to add capacity without adding per-domain processes.
- Use [Troubleshooting](../troubleshooting/index.md) for symptom-first diagnosis.
