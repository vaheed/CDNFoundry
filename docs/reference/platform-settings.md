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

## Operations

| Field | Default | Allowed |
| --- | --- | --- |
| `audit_retention_days` | 365 | 30–3,650 |
| `scheduler_stale_seconds` | 180 | 60–3,600 |
| `clock_drift_warning_seconds` | 5 | 1–300 |
| `backup_stale_hours` | 26 | 1–168 |

## Telemetry

| Field | Default | Allowed |
| --- | --- | --- |
| `raw_retention_days` | 7 | 1–30 |
| `hourly_retention_days` | 400 | 30–730 |
| `daily_retention_days` | 1,095 | 365–3,650 |
| `finalization_delay_minutes` | 15 | 5–1,440 |
| `ipv4_mask_bits` | 24 | 8–32 |
| `ipv6_mask_bits` | 48 | 16–64 |

The masking and finalization fields are active application policy. The shipped
ClickHouse DDL has fixed TTLs of 7, 400, and 3 years; changing retention values
does not migrate ClickHouse tables automatically.

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
| `max_domain_artifact_bytes` | 2,097,152 | 65,536–16,777,216 |

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
