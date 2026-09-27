---
title: Monitoring and alerts
description: Interpret CDNFoundry liveness, readiness, component health, metrics, queues, and alerts.
---

# Monitoring and alerts

::: info Metrics are evidence, not control state
Use metrics to detect and investigate symptoms. PostgreSQL desired state,
revision acknowledgements, and last-valid runtime state remain authoritative;
never repair deployment state by editing a dashboard or derived metric.
:::

## HTTP health endpoints

| Endpoint | Access | Meaning |
| --- | --- | --- |
| `/up` | framework | Laravel process health route |
| `/api/health` | public | Process liveness only |
| `/health` | public | Sanitized status page from the latest scheduled component snapshot |
| `/api/ready` | public | Required database, queue, and worker readiness |
| `/api/admin/system/status` | administrator | Control status summary |
| `/api/admin/system/health` | administrator | Overall health and components |
| `/api/admin/system/components` | administrator | Detailed dependency and operational states |
| `/metrics` | separate bearer token | Prometheus text metrics; unauthorized callers receive 404 |

Do not use `/api/health` as proof that DNS, edge, telemetry, or reconciliation is
healthy.

The home page links to `/health`. Browsers opening `/api/health` are redirected
there; API clients requesting JSON continue to receive the original liveness
response. The scheduler runs `cdnf:health:publish` once per minute and stores
only names and states for DNS, edge delivery, TLS, cache/runtime work, security,
telemetry, and control-plane checks. Counts, addresses, exception messages, and
credentials remain in the administrator view. The public page changes to
**Status unavailable** if the latest snapshot is over 150 seconds old or cannot
be read. It shows the last observation as historical until cache expiry.
Keep the Fleet control node's `scheduler`, `core`, and `web` services on the same
release when deploying this page. No DNS or customer HTTP request uses Laravel
for serving; these status signals report control and reconciliation health.

For a read-only path check, run `python3 tests/e2e/staging_health.py` with
`--control`, `--grafana`, `--zone`, one or more `--dns-server` values, and
`--report`. Add `--edge-hostname`, one or more `--edge` addresses,
`--cache-path` for a stable cacheable 200 resource, and
`--origin-probe-prefix` for an origin route that returns 200 for a fresh child
path. The report separately records authoritative UDP/TCP DNS and serial
parity, verified TLS, a cache HIT, and a unique origin fetch. The probe makes
bounded GET requests and does not follow redirects or inspect rendered UI.

## Component checks

The system reports:

- control database and Valkey connectivity;
- Horizon master state and each queue;
- scheduler heartbeat;
- ClickHouse and Vector probes;
- edge cells continue serving with bounded Docker stdout logs when Vector is
  absent at startup; their container log reports this fallback, and they need
  a restart after Vector recovers to restore direct syslog delivery;
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
collector, Loki, node-exporter, DNSdist, and Alertmanager. PowerDNS remains
private; its backend availability is evaluated from DNSdist's exported backend
status rather than by publishing the PowerDNS API/metrics port.
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
| `DNSDistUnavailable` | DNSdist scrape down for 2 minutes |
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
