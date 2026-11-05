---
title: Monitoring and alerts
description: Interpret CDNFoundry liveness, readiness, component health, metrics, queues, and alerts.
---

# Monitoring and alerts

<Screenshot name="admin-telemetry" alt="The Traffic and telemetry page in the admin panel." />

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
- Vector's security and DNS buffers are capped at 268435488 bytes each, the
  minimum accepted by the production Vector version; startup qualification
  checks the live process because configuration validation does not build the
  disk buffers;
- edge cells continue serving with bounded Docker stdout logs when Vector is
  absent at startup; their container log reports this fallback, and they need
  a restart after Vector recovers to restore direct syslog delivery. Query
  strings are removed from edge paths and referrers before either log output;
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
- most recent verified backup;
- control-plane high availability (**Standby node**): `ha: none` on a single node is
  shown as a warning with a healthy state; `ha: degraded` lists its reasons
  (`standby_unreachable`, `replication_lag`, `valkey_replication_broken`,
  `secret_fingerprint_mismatch`, `edges_missing_control_addresses`).

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

Alertmanager sends every alert to one webhook, `ALERT_WEBHOOK_URL`, and
**refuses to start** when it is empty or not an `https://` URL (development sets
`ALERT_WEBHOOK_ALLOW_HTTP=true` and points it at the `alert-sink` container).
Prometheus also fires an always-on `Watchdog` alert that is forwarded like any
other, repeated every minute: if your notifier stops receiving `Watchdog`, alerting is broken. Point the
webhook at a service that pages you when `Watchdog` stops arriving.

| Alert | Fires when |
| --- | --- |
| `Watchdog` | always |
| `EdgeHeartbeatStale` | a registered edge has sent no heartbeat for 3 minutes; DNS still publishes it |
| `PoolServingLastPublishedSet` | every endpoint of a pool looks unhealthy, so DNS keeps the last set |
| `EdgeIdentityCertificateExpiring` | an edge identity certificate expires within 30 days, meaning renewal is failing |
| `ControlNodeUnreachable` | one control node cannot be scraped by the other for 2 minutes (standby's own Alertmanager fires when the active node is lost) |
| `ControlStandbyNotStreaming` | no streaming replica seen by the active node for 5 minutes |
| `ControlReplicationLagHigh` | replication lag over 10 seconds for 2 minutes |
| `ControlValkeyReplicationBroken` | the Valkey replica is not linked for 5 minutes |
| `ControlSecretFingerprintMismatch` | the control nodes do not hold the same secrets for 5 minutes |
| `EdgeMissingControlAddresses` | a PoP has not reported the current control address list for 15 minutes |

The last six alerts exist only when a [standby control node](./control-plane-high-availability.md)
is configured; on a single node they have no series and never fire. The control
endpoint also exports `cdnfoundry_control_ha_state{state}`, `cdnfoundry_control_epoch`,
`cdnfoundry_control_replication_lag_seconds`, `cdnfoundry_control_standby_unreachable`,
`cdnfoundry_control_valkey_replica_linked`, `cdnfoundry_control_fingerprint_mismatch`,
and `cdnfoundry_control_edges_missing_addresses`.

Control containers have health checks: `edge-control` (`/healthz`), `horizon`
(`horizon:status`), and `scheduler` (`cdnf:scheduler:check`, which needs a
heartbeat from the last 150 seconds). The edge agent (`edge-agent --healthcheck`,
its loop ran in the last 3 minutes) and the gateway (`/healthz`) are checked too,
so Compose restarts a hung process.

## First response

1. Capture exact revision and `docker compose ps`.
2. Read component details and the responsible queue.
3. Inspect failed operations, failed jobs, deployments, tasks, orders, or backups.
4. Verify that the last valid DNS or edge state is still serving.
5. Follow the narrow [incident runbook](runbooks.md).
