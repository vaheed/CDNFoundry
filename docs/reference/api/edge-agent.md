---
title: Edge-agent API
description: Reference the private mutual-TLS protocol between CDNFoundry edge agents and the control plane.
---

# Edge-agent API

::: danger Edge identity is one host's credential
Enroll with the one-time token, persist the issued identity, then remove the
bootstrap token. Never clone an agent state volume or reuse one edge identity
on another host.
:::

Edge routes are rooted at `/edge/v1` and are not part of the customer API.
They are served by the dedicated edge-control listener. Registration is
bootstrap-token authenticated and rate limited; every later route requires a
valid client certificate whose serial belongs to the enabled edge.

| Method | Path | Purpose |
| --- | --- | --- |
| `POST` | `/register` | Exchange edge UUID, one-time token, version, and CSR for identity |
| `POST` | `/heartbeat` | Report sequence, listener readiness, bounded gateway/cell capacity, origin health, and security |
| `GET` | `/config/manifest?cursor=` | Fetch up to 500 newer artifact descriptors |
| `GET` | `/config/artifacts/{checksum}` | Fetch one signed encoded artifact |
| `GET` | `/config/full` | Fetch a bounded signed gzip recovery snapshot |
| `POST` | `/config/applied` | Acknowledge successful sequence activation |
| `POST` | `/config/rejected` | Record bounded rejection reason/details |
| `GET` | `/tasks` | Fetch up to 100 pending/retryable tasks |
| `POST` | `/tasks/{task}/result` | Complete or fail an assigned task |

## Trust

The Nginx edge-control listener permits a client certificate to be absent only
for registration. It forwards certificate verification and serial values to
Laravel over the internal FastCGI boundary. Later middleware verifies the
certificate, serial, edge state, and identity expiry.

The agent separately verifies the edge-control server against
`EDGE_CONTROL_CA_CERTIFICATE`. Never use the identity CA key as a server key.

## Artifact contract

Descriptors include sequence, kind, domain/revision identity, checksum,
signature, schema version, and minimum/maximum agent version. Agent version
`1.1.0` accepts the current `1.x` compatibility envelope. It verifies:

1. SHA-256 of canonical encoded payload;
2. Ed25519 signature over the checksum;
3. schema version;
4. version bounds;
5. compiled per-pool runtime structure.

Rejected candidates never replace active state.

When gateway mode is configured, the heartbeat `gateway` object reports
readiness, active map revision, listener and route counts, active/accepted/
rejected connections, errors, and rejected candidates. Values are bounded
integers and carry no hostnames or customer data. `listener_ready` is true only
when the gateway is ready and its revision equals the agent's active sequence.

## Task contract

Current tasks cover origin tests, cache purge, cell drain/undrain/restart, and
emergency controls. The agent checks target ownership, sends token-protected
internal control requests, persists drain/emergency state, and reports stable
failure reasons such as `cell_not_found`, `control_request_failed`, or
`invalid_cache_purge_task`.

This protocol is operator-internal. Do not expose it through the normal control
hostname or reuse a user API token.
