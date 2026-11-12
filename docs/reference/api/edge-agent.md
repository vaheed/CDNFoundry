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

Edge routes are rooted at `/edge/v1`. They are not part of the customer API.
The dedicated edge-control listener serves them. Only `/register` works without
a client certificate: it needs the one-time bootstrap token and is rate limited.
Every other route needs a valid client certificate whose serial belongs to an
enabled edge.

| Method | Path | Purpose |
| --- | --- | --- |
| `POST` | `/register` | Exchange the edge UUID, one-time token, version, and CSR for an identity |
| `POST` | `/identity/renew` | Exchange a CSR (common name = edge ID) for a renewed identity certificate. It becomes current on its first authenticated use |
| `GET` | `/probes/targets` | List the other PoPs' endpoint addresses to probe on ports 80 and 443 (at most 128) |
| `POST` | `/probes` | Report up to 128 probe results. Results for unknown targets are ignored |
| `POST` | `/heartbeat` | Report sequence, listener readiness, bounded gateway and cell capacity, origin health, and security |
| `GET` | `/gateway/config` | Fetch the listener addresses to bind and the cells behind each one |
| `GET` | `/control-addresses?checksum=` | Fetch the signed list of control-plane addresses. Returns only `unchanged` while the checksum matches |
| `GET` | `/dns/changes?cursor=` | Fetch up to 200 zones that changed on the DNS primary after the cursor, oldest first. A zone that changes again moves to the end. A deleted zone is reported with `deleted: true` |
| `GET` | `/dns/transfer` | Fetch how to pull zones: the primary's address and port, the catalog zone, the TSIG key name, and the TSIG secret sealed to this edge's identity key |
| `GET` | `/origin-policy?checksum=` | Fetch the signed platform origin destination policy (blocked addresses and networks, private allowlist). Returns only `unchanged` while the checksum matches |
| `GET` | `/config/manifest?cursor=` | Fetch up to 500 newer artifact descriptors |
| `GET` | `/config/artifacts/{checksum}` | Fetch one signed encoded artifact |
| `GET` | `/certificates/{certificate}/key` | Fetch a certificate private key sealed to this edge's identity key. The edge must host a domain that uses the certificate, or the route answers `404` |
| `GET` | `/config/full` | Fetch a bounded, signed, gzip recovery snapshot |
| `POST` | `/config/applied` | Acknowledge that a sequence is active |
| `POST` | `/config/rejected` | Record that one domain's artifact was rejected. The reason is a lowercase identifier. An unknown reason is stored as reported and never refused |
| `GET` | `/tasks` | Fetch up to 100 pending or retryable tasks |
| `POST` | `/tasks/{task}/result` | Complete or fail an assigned task |

## Trust

The Nginx edge-control listener accepts a missing client certificate so that
registration can work. It forwards the certificate verification result and
serial to Laravel over the internal FastCGI boundary. Laravel middleware then
checks the certificate, the serial, the edge state, and the identity expiry on
every route except `/register`.

The agent separately verifies the edge-control server against
`EDGE_CONTROL_CA_CERTIFICATE`. Never use the identity CA key as a server key.

## Artifact contract

Each descriptor holds the sequence, kind, domain and revision, checksum,
signature, schema version, and minimum and maximum agent version. Agent version
`2.0.0` accepts the current `2.x` compatibility envelope (agent versions 2.0.0 to 2.99.0). It verifies:

1. SHA-256 of canonical encoded payload;
2. Ed25519 signature over the checksum;
3. schema version;
4. version bounds;
5. compiled per-pool runtime structure.

Artifact and full-snapshot downloads also carry a five-minute expiration and
an Ed25519 `delivery_signature` over
`<scope>|<checksum>|<sequence>|<expires_at>`. The scope of an artifact is
`artifact:<kind>:<domain_id>:<revision>`, built from the manifest entry the
agent is applying; the scope of a snapshot is `snapshot`. Because the kind,
domain and revision are signed, a replayed or rewritten manifest entry cannot
relabel a signed payload (for example turn an update into a tombstone), and a
snapshot signature cannot stand in for an artifact signature.

The agent rejects expired or far-future deliveries, sequence mismatches, and
incremental sequences that do not increase. For one domain it accepts only a
strictly greater revision; an equal or lower revision is rejected as
`artifact_revision_replay`. Deploy the updated control plane before the updated
agent, since the agent requires the scoped signature. During a signing-key rotation, `/config/manifest` also returns
a `key_rotation` object with the next public key and an endorsement signed by
the current key. The agent accepts the next key only after it verifies that
endorsement. See [Signing-key rotation](../../operations/signing-key-rotation.md).

The control-address list uses the same delivery signature with the
`control-addresses` scope.

The platform origin destination policy is not part of any domain artifact. The
agent fetches it from `/origin-policy` on every synchronization, verifies its
checksum, signature, and `origin-policy`-scoped delivery signature, stores it,
and injects it into every compiled origin and backup. A change in a pool
address or an origin-safety setting therefore changes only this one document:
no domain is recompiled or re-signed on the control plane. An agent with no
verified policy refuses to activate runtime files; one that holds a policy
keeps it through a control-plane outage or a rejected reply.

Rejected candidates never replace active state.

When gateway mode is configured, the heartbeat `gateway` object reports
readiness, active map revision, listener and route counts, active/accepted/
rejected connections, errors, and rejected candidates. Values are bounded
integers and carry no hostnames or customer data. `listener_ready` is true only
when the gateway is ready and its revision equals the agent's active sequence.

The control plane records `last_heartbeat_at` for any heartbeat whose required
fields are valid. `noisy_domains` entries (`domain_id`, optional `hostname`,
`reason_code`, `count`, `occurred_at`) and `passive_origins` entries are advisory:
an invalid entry, such as an unknown reason code, is dropped and counted rather than
failing the request. The accepted reason codes are the list in
`core/resources/contracts/security-event-reasons.json`, which `tests/contract`
checks against every reason the cell runtime can raise.

## Task contract

Current tasks cover origin tests, DNS probes, cache purge, cell
drain, undrain and restart, emergency controls, and runtime upgrades. The agent checks target ownership, sends token-protected
internal control requests, persists drain/emergency state, and reports stable
failure reasons such as `cell_not_found`, `control_request_failed`,
`invalid_cache_purge_task`, `cache_purge_persist_failed`, and
`cache_purge_capacity_exceeded`. Cache purge tasks carry a `purge_id`, which the
cells store as the URL generation.

A `security_overlay` task carries `domain_id`, `domain`, `overlay_sequence`, `state`
(`normal`, `suspected`, `restricted`, or `recovering`), and `limits` (stricter limit
values, or `null`). The agent stores the newest sequence per domain, removes the
overlay on `normal`, refuses a new domain beyond 256 overlays with
`overlay_capacity_exceeded`, and tells every cell. A cell that restarts reports
`overlays_restored: false` until the agent replays the stored overlays to it. Only
the newest pending overlay per domain and edge is kept.

Task receipts are scoped to the authenticated edge and committed transactionally
with their database effects. Concurrent reports serialize on the task and its
operation or purge aggregate. A terminal task is immutable; later reports return
`200` with `data.replayed=true`. A failed purge gets at most five attempts, with
backoff between them; duplicate failure reports during backoff return a replay receipt and
do not consume another attempt. A database failure rolls back the receipt and
its effects so the agent can report it again.

The `postgres-edge-tasks` production qualification check exercises independent
PHP processes against a fresh, isolated PostgreSQL database, including a late
terminal report, simultaneous sibling completion, and competing origin-test
dispatchers. It requires Docker and
host PHP with `pdo_pgsql` and installed application dependencies.

Origin-test delivery checks current domain authorization, active/verified state,
and the selected origin configuration. An obsolete pending probe is omitted
from `/tasks` and receives a failed receipt with `task_cancelled`. Late reports
remain task history but cannot update health for a changed or unauthorized
origin. Dispatch retries retain the operation's initial recipients and progress.
This adds no task wire fields or agent-version requirement.
Updated agents re-resolve origin hostnames before connection and reject answers
outside the task's approved addresses. A new test is required after an answer
changes. HTTPS success reports `tls_result=verified` when verification was
enabled and `unverified` when disabled; failed probes retain the existing
failure-reason protocol. No new task field is required, but the corrected
runtime behavior requires upgrading the agent image.

This protocol is operator-internal. Do not expose it through the normal control
hostname or reuse a user API token.
