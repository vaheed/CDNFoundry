---
title: Monitoring and alerts
description: Interpret CDNFoundry liveness, readiness, component health, metrics, queues, and alerts.
---

# Monitoring and alerts

## HTTP health endpoints

| Endpoint | Access | Meaning |
| --- | --- | --- |
| `/up` | framework | Laravel process health route |
| `/api/health` | public | Process liveness only |
| `/api/ready` | public | Required database, queue, and worker readiness |
| `/api/admin/system/status` | administrator | Control status summary |
| `/api/admin/system/health` | administrator | Overall health and components |
| `/api/admin/system/components` | administrator | Detailed dependency and operational states |
| `/metrics` | separate bearer token | Prometheus text metrics; unauthorized callers receive 404 |

Do not use `/api/health` as proof that DNS, edge, telemetry, or reconciliation is
healthy.

## Component checks

The system reports:

- control database and Valkey connectivity;
- Horizon master state and each queue;
- scheduler heartbeat;
- ClickHouse and Vector probes;
- maximum host clock offset from Prometheus;
- MMDB presence, readability, size, and age;
- enabled/fresh edges and listener readiness;
- cell, service-pool, artifact, placement, and capacity health;
- active pool maintenance controls and withdrawn pools;
- DNS clusters and deployments;
- TLS expiry and failed orders;
- purge and runtime-task failures;
- usage finalization;
- failed operations;
- most recent verified backup.

States are `healthy`, `degraded`, or `unavailable`. Only loss of the control
database or queue backend makes overall state `unavailable`; other failures
degrade the control plane while serving may continue.

The administrator dashboard shows the bounded evidence for every component and
a component-specific **How to fix** direction for non-healthy states. It
refreshes every 30 seconds. A degraded aggregate is not a request to restart the
whole platform: follow the named component, its counts/timestamps, and the
narrow runbook while preserving unrelated serving paths.

## Prometheus metrics

The control endpoint exposes component status, queue depth/age, failed
operations, drifted DNS deployments, stale edges, and expiring certificates.
Prometheus also scrapes the traffic Vector, every configured operational-log
collector, Loki, node-exporter, DNSdist, PowerDNS, and Alertmanager.
It additionally scrapes itself, the private ClickHouse Prometheus endpoint,
and deployment-configured edge gateways. The two provisioned
[Grafana command centers](grafana.md) consume these metrics plus read-only
ClickHouse and sanitized control-database views.

Key alert rules are:

| Alert | Trigger |
| --- | --- |
| `ControlPlaneMetricsUnavailable` | control scrape down for 2 minutes |
| `CDNFoundryComponentUnhealthy` | component gauge unhealthy for 5 minutes |
| `CDNFoundryQueueBacklog` | depth over 1,000 or age over 900 seconds for 5 minutes |
| `CDNFoundryFailedOperations` | any failed operation for 10 minutes |
| `CDNFoundryCertificateExpiry` | active certificate in alert window |
| `DNSDistUnavailable`, `PowerDNSUnavailable` | scrape down for 2 minutes |
| `DNSDistBackendUnavailable` | DNSdist backend marked down |
| `HostClockUnsynchronized` | node clock unsynchronized |
| `HostClockDrift` | absolute offset over 5 seconds |
| `TelemetryEventsDropped` | Vector discarded events increase |
| `TelemetryDeliveryFailures` | Vector component errors increase |
| `TelemetryBufferNearLimit` | buffer over 80% of 1 GiB |
| `TelemetryCollectorUnavailable` | Vector scrape down |

The shipped Alertmanager has a local placeholder receiver. Configure a real
receiver in deployment-specific secret/config management before relying on
notifications.

## First response

1. Capture exact revision and `docker compose ps`.
2. Read component details and the responsible queue.
3. Inspect failed operations, failed jobs, deployments, tasks, orders, or backups.
4. Verify that the last valid DNS or edge state is still serving.
5. Follow the narrow [incident runbook](runbooks.md).
