---
title: Proxy and origins
description: Configure proxied hostnames, origin safety, forwarding, health checks, and rollback.
---

# Proxy and origins

A proxied hostname uses one DNS record and one explicit origin. Only `A`,
`AAAA`, and `CNAME` records can use `proxied` mode. Their DNS `content` becomes
platform-managed; do not treat it as the origin.

## Origin fields

| Field | Behaviour |
| --- | --- |
| `host` | DNS hostname or IP address, maximum 253 characters |
| `scheme` | `http` or `https` |
| `port` | Derived as 80 for HTTP or 443 for HTTPS |
| `host_header` | Required upstream `Host`, maximum 253 characters |
| `sni` | Required for verified HTTPS, maximum 253 characters |
| `verify_tls` | Verify the origin certificate when HTTPS |
| `connect_timeout_ms` | 100–10,000 ms |
| `response_timeout_ms` | 500–60,000 ms |
| `retry_count` | 0–2 |
| `websocket` | Permit WebSocket upgrade |
| `health_check` | Optional path and 60–86,400 second interval |

The control plane resolves and validates the destination before saving. The edge
agent resolves and validates again immediately before an origin test or runtime
connection.

## Destination safety

Non-bypassable policy rejects unspecified, loopback, link-local, multicast,
metadata, reserved, platform-listener, edge-service, and proxy-loop addresses
for IPv4 and IPv6. Private destinations are rejected unless they fall inside a
narrow `origin_safety.private_origin_allowlist`. Additional networks and
individual addresses can be blocked through platform settings.

The private allowlist cannot permit a built-in unsafe range.

## Proxy defaults

Domain proxy settings include enablement, HTTPS redirect, HTTP versions
(`1.1`, `2`), retry count, and optional maintenance response. Platform
`proxy_defaults` are copied when a domain has no explicit value. Runtime
settings are revisioned and delivered as signed artifacts.

## Origin tests

`POST .../origin/test` is rate limited and asynchronous. A runtime task resolves
the destination, connects with bounded timeouts, does not follow redirects, and
records status, address, latency, HTTP status, or a stable failure reason.
Scheduled health checks run only when explicitly enabled and are dispatched in
batches of at most 100 per minute.

## Forwarding and cache

OpenResty strips hop-by-hop input, controls `Host`, SNI, and forwarded headers,
and uses bounded request buffering, response buffering, temporary storage, and
origin connection accounting. See [Cache and purge](/guides/cache) for admission
and stale behaviour.

## Deployment and rollback

Every origin or proxy change increments the domain revision and queues an edge
reconcile operation. Inspect `/deployment` and `/revisions`. Rollback selects a
retained validated revision and creates a new monotonic revision.
