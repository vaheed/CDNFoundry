---
title: Platform settings reference
description: Reference every PostgreSQL-backed CDNFoundry platform policy field, default, and bound.
---

# Platform settings reference

::: warning Settings are durable product policy
These values live in PostgreSQL, are audited, and may queue global runtime
reconciliation. An environment variable does not replace a setting, and a
runtime change is incomplete until affected targets acknowledge it.
:::

Platform settings are validated groups in `core/config/platform.php` and stored
in `system_settings`. Manage them at `/admin/platform-settings`,
`/api/admin/system/settings`, or with `cdnf:platform:settings:show` and
`cdnf:platform:settings:set`.

Updating `edge_runtime`, `origin_safety`, or `proxy_defaults` creates an
asynchronous global edge reconcile operation. Other groups apply to later
requests or scheduled work without rewriting every domain.

## Display

| Field | Default | Allowed |
| --- | --- | --- |
| `timezone` | `UTC` | IANA timezone, such as `Asia/Tehran` |

The administrator selects one shared display timezone for both panels. It applies
to date/time fields, tables, charts and custom analytics views. API timestamps,
exports and database values remain UTC. Changing this group is audited and has no
edge reconciliation operation. It does not configure an external Grafana instance.

## Operations

| Field | Default | Allowed |
| --- | --- | --- |
| `audit_retention_days` | 365 | 30–3,650 |
| `scheduler_stale_seconds` | 180 | 60–3,600 |
| `clock_drift_warning_seconds` | 5 | 1–300 |
| `backup_stale_hours` | 26 | 1–168 |
| `failed_operations_window_minutes` | 60 | 5–1,440 |

`failed_operations_window_minutes` is how long a failed operation counts toward
the `cdnfoundry_operations_failed_recent` gauge and the `CDNFoundryFailedOperations`
alert. The alert resolves once the last failure is older than the window.

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

The masking and finalization fields are active application policy. The shipped
ClickHouse DDL has fixed TTLs; changing a retention value does not migrate
ClickHouse tables, Prometheus, or Loki automatically. Each default below matches
the shipped schema and Compose files, and a contract test fails if they drift.

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

## DNS lifecycle

| Field | Default | Allowed |
| --- | --- | --- |
| `deprovision_delay_days` | 7 | 1–365 |
| `domain_reclaim_cooldown_days` | 7 | 1–365 |

## Revision history

| Field | Default | Allowed |
| --- | --- | --- |
| `retention_days` | 1 | 1–365 |
| `minimum_revisions_per_domain` | 10 | 2–100 |

## API rate limits

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

Existing rows may still contain the older telemetry quorum, maximum-age and
future-skew values. Current workers ignore those three stored fields, retain
them during updates for mixed-version compatibility, and do not offer them as
new input. Other unknown persisted fields still fail validation.

## Origin safety

| Field | Default | Allowed |
| --- | --- | --- |
| `private_origin_allowlist` | empty | up to 128 private IPv4/IPv6 CIDRs |
| `blocked_origin_networks` | empty | up to 128 IPv4/IPv6 CIDRs |
| `blocked_origin_addresses` | empty | up to 128 IPv4/IPv6 addresses |

The allowlist cannot override unspecified, loopback, link-local, metadata,
multicast, reserved, platform, edge-service, or proxy-loop rejection.

## Proxy defaults

| Field | Default | Allowed |
| --- | --- | --- |
| `enabled` | true | boolean |
| `redirect_https` | false | boolean |
| `http_versions` | `1.1`, `2` | one or both |
| `retry_count` | 0 | 0–2 |

## API examples

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
