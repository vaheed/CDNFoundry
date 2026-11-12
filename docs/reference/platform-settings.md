---
title: Platform settings reference
description: Reference every PostgreSQL-backed CDNFoundry platform policy field, default, and bound.
---

# Platform settings reference

<Screenshot name="admin-platform-settings" alt="The Platform settings page in the admin panel." />

::: warning Settings are durable product policy
These values live in PostgreSQL, are audited, and may queue global runtime
reconciliation. An environment variable does not replace a setting, and a
runtime change is incomplete until affected targets acknowledge it.
:::

Platform settings are groups of typed fields. The groups and their limits are
defined in `core/config/platform.php`. CDNFoundry stores the values in the
`system_settings` table in PostgreSQL and checks every value against its bounds
before saving. Only administrators can change them.

You can manage them in three ways:

- The admin panel page at `/admin/platform-settings`.
- The API at `/api/admin/system/settings`. Use `GET` to read and `PATCH` to change.
- The commands `cdnf:platform:settings:show` and `cdnf:platform:settings:set`.

Updating `edge_runtime`, `origin_safety`, or `proxy_defaults` queues an
asynchronous global edge reconcile operation. Other groups apply to later
requests or scheduled work. They do not rewrite every domain.

Each table below lists the field name used in the API and commands, the default,
and the allowed range. Ranges are inclusive. The API group names are `display`,
`operations`, `telemetry`, `observability`, `dns_lifecycle`, `domain_monitoring`, `revision_history`,
`rate_limits`, `edge_runtime`, `origin_safety`, and `proxy_defaults`.

The platform DNS zone and its nameservers have their own settings endpoint,
`/api/admin/system/settings/dns`. This page does not cover it.

## Display

| Field | Default | Allowed |
| --- | --- | --- |
| `timezone` | `UTC` | IANA timezone, such as `Asia/Tehran` |

The administrator picks one display timezone for both panels. It applies to
date and time fields, tables, charts, and analytics views. API timestamps,
exports, and database values stay in UTC. Changing this group is audited and
queues no edge operation. It does not change the timezone of an external Grafana.

## Operations

| Field | Default | Allowed |
| --- | --- | --- |
| `audit_retention_days` | 365 | 30–3,650 |
| `security_event_retention_days` | 30 | 7–365 |
| `operation_retention_days` | 90 | 7–3,650 |
| `scheduler_stale_seconds` | 180 | 60–3,600 |
| `clock_drift_warning_seconds` | 5 | 1–300 |
| `backup_stale_hours` | 26 | 1–168 |
| `failed_operations_window_minutes` | 60 | 5–1,440 |

- `audit_retention_days`: a daily job deletes audit events older than this, in small batches.
- `security_event_retention_days`: a daily job deletes edge-reported security events older than this. The control plane keeps one row per domain, edge, reason, and hour, so a flood of reports cannot grow the table faster than that.
- `operation_retention_days`: a daily job deletes finished operations older than this. Pending and running operations, and failed operations nobody has acknowledged, are kept.
- `scheduler_stale_seconds`: health turns degraded when the scheduler heartbeat is older than this.
- `clock_drift_warning_seconds`: the largest host clock offset that monitoring and qualification accept.
- `backup_stale_hours`: the largest accepted age of the newest verified off-host control database backup.
- `failed_operations_window_minutes`: how long a failed operation counts toward the `cdnfoundry_operations_failed_recent` gauge and the `CDNFoundryFailedOperations` alert. The alert resolves once the last failure is older than the window.

## Telemetry

| Field | Default | Allowed |
| --- | --- | --- |
| `raw_retention_days` | 7 | 1–30 |
| `one_minute_retention_hours` | 48 | 12–168 |
| `hourly_retention_days` | 400 | 30–730 |
| `daily_retention_days` | 1,095 | 365–3,650 |
| `prometheus_retention_days` | 30 | 7–365 |
| `loki_retention_days` | 14 | 1–90 |
| `finalization_delay_minutes` | 15 | 5–1,440 |
| `ipv4_mask_bits` | 24 | 8–32 |
| `ipv6_mask_bits` | 48 | 16–64 |

The masking and finalization fields are active application policy.
`finalization_delay_minutes` labels intervals newer than the delay as
provisional. The mask fields set how many leading bits of a client address stay
visible in normal views and exports.

The shipped ClickHouse schema has fixed TTLs (time-to-live deletion rules).
Changing a retention value does not migrate ClickHouse tables, Prometheus, or
Loki. Each default below matches the shipped schema and Compose files, and a
contract test fails if they drift.

### Retention tiers

| Tier | Holds | Retention | Setting |
| --- | --- | --- | --- |
| Raw events | Every request, security, TLS, and DNS event | 7 days | `raw_retention_days` |
| 1-minute rollup | Live and NOC panels, split by PoP, protocol, and status | 48 hours | `one_minute_retention_hours` |
| Hourly rollup | Traffic with latency quantiles, 5-minute usage for billing | 400 days | `hourly_retention_days` |
| Daily rollup | The same traffic at day resolution | 3 years | `daily_retention_days` |
| Prometheus | Metrics, recorded SLO and dashboard series | 30 days | `prometheus_retention_days` |
| Loki | Operational logs | 14 days | `loki_retention_days` |

The 5-minute usage rollup (`edge_usage_5m`) is kept as long as the hourly
rollup, because 95th-percentile billing needs every 5-minute interval of a
month.

## Observability links

| Field | Default | Allowed |
| --- | --- | --- |
| `grafana_explore_url` | empty | absolute `http` or `https` URL, up to 2,048 characters, or empty |

The admin panel uses this URL for the **Live Logs** link. When it is empty,
CDNFoundry falls back to the `GRAFANA_EXPLORE_URL` environment variable, see the
[configuration reference](configuration.md). The link never takes part in
serving traffic.

## DNS lifecycle

| Field | Default | Allowed |
| --- | --- | --- |
| `deprovision_delay_days` | 7 | 1–365 |
| `domain_reclaim_cooldown_days` | 7 | 1–365 |

- `deprovision_delay_days`: a disabled domain keeps serving its last valid DNS and edge state for this long. Removal then starts asynchronously.
- `domain_reclaim_cooldown_days`: a fully removed domain name stays reserved for this long before another account can claim it.

## Domain monitoring

| Field | Default | Allowed |
| --- | --- | --- |
| `nameserver_enforcement` | on | on or off |
| `active_check_hours` | 12 | 1–24 |
| `mismatch_confirmations` | 3 | 1–10 |
| `confirmation_spacing_minutes` | 60 | 5–720 |
| `registration_check_days` | 7 | 1–30 |
| `registration_warning_days` | 30 | 1–90 |

- `nameserver_enforcement`: when on, an active domain whose nameservers changed is un-verified. When off, the change is only recorded in the audit log and the domain stays active.
- `active_check_hours`: how often each active domain's delegation is re-checked.
- `mismatch_confirmations` and `confirmation_spacing_minutes`: the number of consecutive checks that must show a different delegation, and the time between them, before a domain is un-verified. A failed lookup never counts.
- `registration_check_days`: how often each registration expiry is looked up. A domain within the warning window is checked daily.
- `registration_warning_days`: panels highlight an expiry inside this window, and users get a reminder when it starts and again at 14, 7, 1, and 0 days.

## Revision history

| Field | Default | Allowed |
| --- | --- | --- |
| `retention_days` | 1 | 1–365 |
| `minimum_revisions_per_domain` | 10 | 2–100 |

CDNFoundry keeps every validated edge revision from the last `retention_days`
days. It also always keeps the newest `minimum_revisions_per_domain` revisions of
each domain as rollback points. Revision numbers keep rising and are never reused.

## API rate limits

Each limit applies per identity, counted over one minute unless the name says
`per_hour`. A user or API token is one identity. `login_per_minute` counts per
source IP address and account name. `edge_registrations_per_hour` counts per
source IP address.

| Field | Default per identity | Allowed |
| --- | --- | --- |
| `login_per_minute` | 10 | 1–1,000 |
| `account_reads_per_minute` | 600 | 1–10,000 |
| `account_mutations_per_minute` | 240 | 1–5,000 |
| `bulk_per_minute` | 12 | 1–1,000 |
| `origin_tests_per_minute` | 20 | 1–1,000 |
| `edge_registrations_per_hour` | 10 | 1–1,000 |
| `edge_agent_per_minute` | 600 | 1–10,000 |

## Edge runtime

| Field | Default | Allowed |
| --- | --- | --- |
| `heartbeat_fresh_seconds` | 45 | 10–300 |
| `placement_drain_seconds` | 300 | 30–86,400 |
| `promotion_quorum_percent` | 100 | 1–100 |
| `promotion_grace_seconds` | 120 | 30–3,600 |
| `probe_min_vantage_points` | 2 | 2–20 |
| `probe_result_max_age_seconds` | 180 | 30–3,600 |
| `max_domain_artifact_bytes` | 2,097,152 | 65,536–16,777,216 |

- `heartbeat_fresh_seconds`: an edge is routable only while its last authenticated heartbeat is this recent.
- `placement_drain_seconds`: the minimum overlap after a new placement is active before the old one may drain.
- `promotion_quorum_percent`: the share of healthy PoPs that must acknowledge a placement before it is promoted. At least one must always acknowledge.
- `promotion_grace_seconds`: a PoP that has not acknowledged after this long in deploying counts as lagging. It stops counting toward the quorum and catches up on its own.
- `probe_min_vantage_points`: how many other PoPs must report on a PoP before failed probes can withdraw it from DNS. A fleet with fewer never withdraws a PoP on probes.
- `probe_result_max_age_seconds`: a probe result older than this no longer counts.
- `max_domain_artifact_bytes`: the hard size limit for one per-domain edge artifact before signing.

Existing rows may still contain the older telemetry quorum, maximum-age, and
future-skew values. Current workers ignore those three stored fields. Updates
keep them for mixed-version compatibility, and the API does not accept them as
new input. Other unknown stored fields still fail validation.

## Origin safety

| Field | Default | Allowed |
| --- | --- | --- |
| `private_origin_allowlist` | empty | up to 128 private IPv4/IPv6 CIDRs |
| `blocked_origin_networks` | empty | up to 128 IPv4/IPv6 CIDRs |
| `blocked_origin_addresses` | empty | up to 128 IPv4/IPv6 addresses |

Both the control plane and the edge agent apply these lists after DNS
resolution. The allowlist cannot override the rejection of unspecified,
loopback, link-local, metadata, multicast, reserved, platform, edge-service, or
proxy-loop destinations. The blocked lists add to the built-in rules.

## Proxy defaults

| Field | Default | Allowed |
| --- | --- | --- |
| `enabled` | true | boolean |
| `redirect_https` | false | boolean |
| `http_versions` | `1.1`, `2` | one or both |
| `retry_count` | 0 | 0–2 |
| `diagnostic_response_headers` | false | boolean |

`enabled`, `redirect_https`, `http_versions`, and `retry_count` are copied into the
edge snapshot only when a domain has no explicit value of its own. `retry_count`
applies to failed idempotent origin connections.

`diagnostic_response_headers` is one platform-wide switch and no domain can
override it. When it is off (the default) the edge removes `Server`,
`X-CDNF-Cache`, `X-CDNF-Origin`, `X-CDNF-Origin-Transition`, `X-CDNF-Error`, and
`X-CDNF-Security-Reason` from every response, so clients cannot see the edge
software, the cache result, or which origin answered. Turn it on while you debug
caching or origin failover. Telemetry, the access log, and Grafana are unaffected,
because they read the same values on the edge before the headers are removed.
Changing it queues a global edge reconciliation, so it reaches every edge as one
signed revision. The `Server` header never shows a version, whatever the setting.

## API examples

A `PATCH` of `edge_runtime`, `origin_safety`, or `proxy_defaults` returns
`202 Accepted` with an operation ID you can follow. Send a new `Idempotency-Key` for each change.

Read all groups:

```sh
curl --fail \
  --header "Authorization: Bearer ${CDNF_TOKEN}" \
  https://control.example.com/api/admin/system/settings
```

Update one group:

```sh
curl --fail --request PATCH \
  --header "Authorization: Bearer ${CDNF_TOKEN}" \
  --header "Idempotency-Key: 8d90bd25-afcb-40df-89e1-61087b85ca91" \
  --header "Content-Type: application/json" \
  --data '{"values":{"heartbeat_fresh_seconds":45}}' \
  https://control.example.com/api/admin/system/settings/edge_runtime
```
