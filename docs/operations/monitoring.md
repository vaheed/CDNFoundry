---
title: Monitoring and alerts
description: Read CDNFoundry health endpoints, component states, Prometheus metrics, and alerts, and know what to do first when one fires.
---

# Monitoring and alerts

<Screenshot name="admin-telemetry" alt="The Traffic and telemetry page in the admin panel." />

This page explains how to tell whether the platform is healthy. You need administrator access, and for the metrics sections you need access to Prometheus and Alertmanager.

::: info Metrics are evidence, not control state
Use metrics to find and investigate symptoms. PostgreSQL holds the desired state. Revision acknowledgements and the last valid runtime state stay authoritative. Never repair a deployment by editing a dashboard or a derived metric.
:::

## HTTP health endpoints

| Endpoint | Access | Meaning |
| --- | --- | --- |
| `/up` | framework | Laravel process health route |
| `/api/health` | public | The process is alive. Nothing more. |
| `/health` | public | Status page built from the latest scheduled component snapshot |
| `/api/ready` | public | Database and queue backend (Valkey) both answer. Returns 503 if either fails. |
| `/api/admin/system/status` | administrator | Database and queue check, plus depth and age of each queue |
| `/api/admin/system/health` | administrator | Overall health and components |
| `/api/admin/system/components` | administrator | Detailed state of every dependency |
| `/metrics` | separate bearer token | Prometheus text metrics. A caller with a wrong or missing token receives 404. |

Do not use `/api/health` as proof that DNS, edge, telemetry, or reconciliation works. It only proves that the Laravel process runs.

### The public status page

The home page links to `/health`. A browser that opens `/api/health` is redirected there. API clients that ask for JSON still get the plain liveness response.

The scheduler runs `cdnf:health:publish` every minute. It stores only names and states for DNS, edge delivery, TLS, cache and runtime work, security, telemetry, and the control plane. Counts, addresses, error messages, and credentials appear only in the administrator view.

The public page shows **Status unavailable** when the latest snapshot is older than 150 seconds or cannot be read. It then shows the last observation as historical until the cache entry expires.

When you deploy this page, keep the `scheduler`, `core`, and `web` services of the Fleet control node on the same release. No DNS or customer HTTP request passes through Laravel. These signals report the health of the control plane and of reconciliation, not of each request.

### Check the path from outside

`tests/e2e/staging_health.py` makes a read-only check of the public path. It needs `--control`, `--grafana`, and `--report`. These options add checks:

- `--zone` and one or more `--dns-server` values test authoritative DNS.
- `--edge-hostname` and one or more `--edge` addresses test HTTPS on each edge.
- `--cache-path` names a stable cacheable resource that returns 200.
- `--origin-probe-prefix` names an origin route that returns 200 for a fresh child path.

```sh
python3 tests/e2e/staging_health.py --help
```

The report records these results separately: authoritative UDP and TCP DNS with serial parity, verified TLS, a cache HIT, and a unique origin fetch. The probe sends bounded GET requests, does not follow redirects, and does not inspect rendered UI.

## Component checks

The system reports these components.

- Control database and Valkey connectivity.
- Horizon master state and each queue.
- Scheduler heartbeat.
- ClickHouse and Vector probes.
- Maximum host clock offset, read from Prometheus.
- MMDB (GeoIP database) presence, readability, size, and age.
- Enabled and fresh edges, and listener readiness.
- Cell, service pool, artifact, placement, and capacity health.
- Active pool maintenance controls and withdrawn pools.
- DNS clusters and deployments.
- TLS expiry and failed orders.
- Purge and runtime task failures.
- Usage finalization.
- Failed operations.
- The most recent verified backup.
- Control-plane high availability, shown as **Standby node**.

On a single control node, `ha: none` shows as a warning with a healthy state. When a standby exists, `ha: degraded` lists its reasons: `standby_unreachable`, `replication_lag`, `valkey_replication_broken`, `secret_fingerprint_mismatch`, `edges_missing_control_addresses`, and `addresses_not_published`.

States are `healthy`, `degraded`, or `unavailable`. Only the loss of the control database or the queue backend makes the overall state `unavailable`. Other failures degrade the control plane while serving continues.

The administrator dashboard shows the evidence for each component. For each state that is not healthy, it shows a component-specific **How to fix** direction. It refreshes every 30 seconds.

A degraded aggregate is not a request to restart the platform. Follow the named component, its counts and timestamps, and the narrow runbook. Leave unrelated serving paths alone.

### Telemetry buffers and edge logging

- Vector's security and DNS disk buffers are capped at 268435488 bytes each. This is the smallest size the production Vector version accepts. Startup qualification checks the live process, because configuration validation does not build the disk buffers.
- If Vector is absent when an edge cell starts, the cell still serves. It logs to bounded Docker stdout and writes this fallback to its container log. Restart the cell after Vector recovers to restore direct syslog delivery.
- Edge paths and referrers lose their query strings before either log output.

## Prometheus metrics

The control endpoint exposes component status, queue depth and age, failed operations, drifted DNS deployments, stale edges, and expiring certificates.

Prometheus also scrapes itself, the traffic Vector, every configured operational-log collector, Loki, node-exporter, DNSdist, Alertmanager, the private ClickHouse Prometheus endpoint, and the edge gateways your deployment configures. PowerDNS stays private. Its backend health comes from the backend status that DNSdist exports, so the PowerDNS API and metrics port are never published.

The two provisioned [Grafana command centers](grafana.md) read these metrics, read-only ClickHouse, and sanitized control-database views.

### Core alert rules

| Alert | Trigger |
| --- | --- |
| `ControlPlaneMetricsUnavailable` | control scrape down for 2 minutes |
| `CDNFoundryComponentUnhealthy` | a component gauge is unhealthy for 5 minutes |
| `ControlBackupStale` | the backups component is unhealthy for 30 minutes |
| `CDNFoundryQueueBacklog` | depth over 1,000 or oldest job older than 900 seconds, for 5 minutes |
| `CDNFoundryFailedOperations` | an operation failed inside the failed-operations window, for 1 minute |
| `CDNFoundryCertificateExpiry` | an active certificate is inside the alert window, for 5 minutes |
| `DNSDistUnavailable` | DNSdist scrape down for 2 minutes |
| `DNSDistBackendUnavailable` | a DNSdist backend is marked down for 2 minutes |
| `HostClockUnsynchronized` | a node clock is unsynchronized for 2 minutes |
| `HostClockDrift` | absolute clock offset over 5 seconds for 2 minutes |
| `TelemetryEventsDropped` | Vector discarded events increase |
| `TelemetryDeliveryFailures` | Vector component errors increase |
| `TelemetryBufferNearLimit` | a ClickHouse buffer is over 80% of its limit for 5 minutes |
| `TelemetryCollectorUnavailable` | Vector scrape down for 2 minutes |

The rule files in `docker/prometheus/` define more alerts for edge gateways, traffic ratios, propagation, security, SLO burn, Loki, and operational logs. See [operational logging](operational-logging.md) for the log alerts.

### Alert delivery

Alertmanager groups alerts and posts each group to a private receiver in the control plane (`/internal/alertmanager`), authenticated with the monitoring token. The receiver queues one message for the administrator channels, and the `notifier` container delivers it to Telegram, Discord, Slack, or a webhook. Add the channels in **Governance, Notifications**; see [Notifications](../guides/notifications.md). With no channel, alerts still fire and show in Prometheus and Alertmanager, but nobody is notified. Alertmanager reaches the receiver on the control network at `http://web:8080/internal/alertmanager`; a dedicated monitoring host sets `ALERT_RECEIVER_URL` in a Compose override.

The receiver is bounded. It stores nothing, reads no database rows, ignores an identical payload for a minute, and is rate limited at the web container. A group of thousands of alerts is one message with a count and the first ten summaries.

Prometheus also fires an always-on `Watchdog` alert. Alertmanager repeats it every minute, and the receiver records its arrival as a heartbeat instead of sending it. Watch `Watchdog` in Prometheus, or from an outside monitor, to know that alerting works.

| Alert | Fires when |
| --- | --- |
| `Watchdog` | always |
| `EdgeHeartbeatStale` | a registered edge sent no heartbeat for 3 minutes. DNS still publishes it. |
| `PoolServingLastPublishedSet` | every endpoint of a pool looks unhealthy, so DNS keeps the last published set |
| `EdgeIdentityCertificateExpiring` | an edge identity certificate expires within 30 days, which means renewal is failing |
| `ControlNodeUnreachable` | one control node cannot be scraped for 2 minutes. The standby's own Alertmanager fires when the active node is lost. |
| `ControlStandbyNotStreaming` | the active node sees no streaming replica for 5 minutes |
| `ControlReplicationLagHigh` | replication lag is over 10 seconds for 2 minutes |
| `ControlValkeyReplicationBroken` | the Valkey replica is not linked for 5 minutes |
| `ControlSecretFingerprintMismatch` | the control nodes do not hold the same secrets for 5 minutes |
| `EdgeMissingControlAddresses` | a PoP has not reported the current control address list for 15 minutes |

The last six alerts exist only when you configure a [standby control node](./control-plane-high-availability.md). On a single node they have no data and never fire.

The control endpoint also exports `cdnfoundry_control_ha_state{state}`, `cdnfoundry_control_epoch`, `cdnfoundry_control_replication_lag_seconds`, `cdnfoundry_control_standby_unreachable`, `cdnfoundry_control_valkey_replica_linked`, `cdnfoundry_control_fingerprint_mismatch`, and `cdnfoundry_control_edges_missing_addresses`.

### Container health checks

Compose restarts a hung process when its health check fails.

| Container | Check |
| --- | --- |
| `edge-control` | `/healthz` answers |
| `horizon` | `horizon:status` reports running |
| `scheduler` | `cdnf:scheduler:check`, which needs a heartbeat from the last 150 seconds |
| `edge-agent` | `edge-agent --healthcheck`, which needs a loop run in the last 3 minutes |
| edge gateway | `/healthz` answers |

## First response

When an alert fires, do these steps in order.

<div class="steps">

1. Capture the exact revision and the output of `docker compose ps`.
2. Read the component details and the queue that is responsible.
3. Inspect failed operations, failed jobs, deployments, tasks, orders, or backups.
4. Check that the last valid DNS or edge state still serves.
5. Follow the narrow [incident runbook](runbooks.md).

</div>
