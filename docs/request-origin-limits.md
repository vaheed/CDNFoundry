# Request, connection, cache, and origin limits

Limits are per hostname and compiled with its numeric domain ID. Request zones
use both domain/client-IP and total-domain keys; connection zones separately
bound one client and the whole domain. Shared dictionaries have fixed sizes, and
only bounded identifiers enter keys.

Profiles bound request rate and burst, client/domain connections, TLS
handshakes, body/header sizes, header/body/keep-alive/request timeouts, requests
per connection, origin concurrency, connect/read/send timeouts, retries,
circuit thresholds/recovery, cache-key length, and cache admissions. Values and
current ceilings are returned by `GET /api/domains/{domain}/security` and shown
in the domain Security action.

## Profile choices

There are three read-only recommended profiles and one editable profile:

| Profile | Intended use | Limit editing |
|---|---|---|
| `standard` | Balanced defaults for ordinary production traffic | Disabled; all submitted values must exactly match the preset |
| `protected` | Lower traffic and resource ceilings for elevated risk | Disabled; all submitted values must exactly match the preset |
| `quarantine` | Strictest traffic isolation and longest recovery cooldown | Disabled; all submitted values must exactly match the preset |
| `manual` | One custom policy for a domain with explicit operator-selected limits | Enabled; every value is bounded by the platform safety range |

Changing the selector in **Security → Security profile and limits** updates all
displayed limit values immediately. The three presets remain visible as
disabled details so a user can compare them without editing them. Selecting
`manual` enables the same fields. Saving increments the domain revision once,
coalesces one edge reconciliation operation, and refreshes the displayed
configured profile. Closing or cancelling the modal changes no durable state.

The exact shipped values and manual ranges are:

| Field | Unit / meaning | Standard | Protected | Quarantine | Manual range |
|---|---|---:|---:|---:|---:|
| `requests_per_second` | Requests per client | 100 | 50 | 10 | 1–100 |
| `request_burst` | Additional client burst requests | 200 | 75 | 10 | 1–200 |
| `connections_per_client` | Concurrent client connections | 64 | 24 | 4 | 1–64 |
| `connections_per_domain` | Concurrent total domain connections | 512 | 256 | 48 | 1–512 |
| `tls_handshakes_per_second` | New TLS handshakes per domain | 50 | 20 | 5 | 1–50 |
| `maximum_request_body_size` | Bytes | 16,777,216 | 8,388,608 | 1,048,576 | 1–16,777,216 |
| `maximum_header_size` | Bytes | 32,768 | 16,384 | 8,192 | 1–32,768 |
| `client_header_timeout` | Seconds | 10 | 7 | 5 | 1–10 |
| `client_body_timeout` | Seconds | 30 | 15 | 10 | 1–30 |
| `keepalive_timeout` | Seconds | 30 | 15 | 5 | 1–30 |
| `maximum_requests_per_connection` | Requests | 1,000 | 250 | 50 | 1–1,000 |
| `maximum_request_duration` | Seconds | 60 | 30 | 15 | 1–60 |
| `origin_max_connections` | Concurrent origin requests | 128 | 64 | 16 | 1–128 |
| `origin_connect_timeout` | Seconds | 3 | 2 | 1 | 1–3 |
| `origin_read_timeout` | Seconds | 30 | 15 | 10 | 1–30 |
| `origin_send_timeout` | Seconds | 30 | 15 | 10 | 1–30 |
| `origin_retry_limit` | Retries per incoming request | 2 | 1 | 0 | 0–2 |
| `origin_failure_threshold` | Failures before circuit opens | 10 | 5 | 3 | 1–10 |
| `origin_recovery_timeout` | Seconds before circuit recovery | 30 | 60 | 120 | 1–120 |
| `maximum_cache_key_length` | Bytes | 4,096 | 2,048 | 1,024 | 1–4,096 |
| `cache_admissions_per_second` | New cache admissions | 50 | 20 | 5 | 1–50 |

The manual upper bound is calculated per field across all shipped presets. This
matters for controls such as `origin_recovery_timeout`, where a longer cooldown
is stricter even though its number is larger. Operational state is separate
from configured choice: a saved manual or standard profile compiles under the
protected ceilings while the domain is suspected/restricted, and under the
quarantine ceilings while it is quarantined. The configured values remain
available when the domain returns to normal.

## API behavior

`GET /api/domains/{domain}/security` returns the configured `profile`, complete
`limits`, and `platform_ceilings`. For `manual`, `platform_ceilings` contains the
field-wise safety ceiling shown in the table. `PATCH` replaces the complete
settings document; it is not a partial limit patch. A successful mutation
returns `202 Accepted` with an operation ID.

Preset payloads must contain every limit at its exact shipped value. An altered
preset returns `422` and changes neither the revision nor stored settings.
Manual payloads must contain exactly the documented fields: missing, extra,
below-minimum, or above-ceiling values return `422`. `Idempotency-Key` replay
returns the original response, while reuse with different input returns the
stable `idempotency_conflict` error.

## Automated qualification

`python3 tests/e2e/phase6_security.py` first exercises the real control-plane
API and persistent PostgreSQL state for policy authorization, all profile
choices, fixed-preset rejection, every manual field boundary, complete payload
shape, persistence, and idempotency. It then builds and runs real OpenResty for
IPv4/IPv6 rules, request/body/rate/connection/origin enforcement, emergency
mode, bounded event reporting, resource isolation, and last-valid-state
preservation. It performs no rendered-UI inspection or browser automation; the
owner checklist remains required.

Rejection occurs before origin work when possible. Origin concurrency is
reserved before proxying, retries are capped, and repeated failures open a
bounded circuit. Capacity exhaustion or an open circuit returns a controlled
response, while cached/stale policy may continue serving. Cache admission
rejects excessive or oversized keys and the cell cache/temp directories have
fixed quotas. No per-domain shared-memory zone, process, or cache directory is
created.

HTTP/3 and WebSocket are disabled. Nginx applies bounded HTTP/1 and HTTP/2
headers, bodies, streams, keep-alive requests, timeouts, listen backlog, worker
connections, and temporary storage. Each cell also has explicit CPU, memory,
PID, file-descriptor, and filesystem limits.
