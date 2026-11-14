---
title: API errors
description: Handle CDNFoundry stable JSON errors, validation failures, conflicts, and service outages.
---

# API errors

::: warning Automate against codes, not prose
Clients should branch on HTTP status and stable machine-readable error codes.
Human messages may gain clarification and must not be parsed as a protocol.
:::

Every route under `/api` and `/edge` answers errors as JSON.

## Stable error

```json
{
  "message": "The operation could not be completed.",
  "code": "conflict",
  "details": {}
}
```

These HTTP statuses map to codes:

| HTTP | Code |
| ---: | --- |
| 401 | `unauthenticated` |
| 403 | `forbidden` |
| 404 | `not_found` |
| 405 | `method_not_allowed` |
| 409 | `conflict` or a more specific controller code |
| 413 | `payload_too_large` |
| 422 | `unprocessable` |
| 429 | `too_many_requests`, with `Retry-After` |
| 5xx | `server_error` |

A domain whose nameservers are not verified answers every route except its own record, status, verification,
and DNS record routes with `409 domain_not_verified`.

A domain you cannot view answers `404 not_found`, exactly like a domain that does
not exist. This stops anyone from guessing domain IDs. A missing resource always
answers with the fixed message "The requested resource was not found." The
message never names an internal class. `403 forbidden` stays for a resource you
can see but may not change.

An unexpected failure answers `500 server_error` with the fixed message "The
server could not complete the request." It never carries internal detail. Any
other status without a mapping above uses the code `http_error`.

Some errors have a more specific code:

| HTTP | Code | When |
| ---: | --- | --- |
| 401 | `otp_required` | The account uses two-factor login and the login request had no authenticator code |
| 403 | `account_disabled` | The account is disabled |
| 403 | `token_scope_exceeded` | The API token is not allowed to do this |
| 409 | `idempotency_conflict` | The `Idempotency-Key` was used earlier with different input |
| 409 | `idempotency_busy` | A request with the same key is still running |
| 409 | `waf_capable_pool_unavailable` | No enabled WAF-capable pool can host the domain |
| 409 | `waf_exclusions_unavailable` | Creating a WAF exclusion is not available yet |
| 422 | `invalid_idempotency_key` | The `Idempotency-Key` is not a UUID |
| 503 | `analytics_unavailable` | ClickHouse is not reachable for an analytics or log query |
| 503 | `control_standby` | This control node is a standby. Retry after the `Retry-After` delay (5 seconds) |

## Validation error

```json
{
  "message": "The provided data is invalid.",
  "code": "validation_failed",
  "errors": {
    "name": [
      "The name field is required."
    ]
  }
}
```

Do not parse human messages as identifiers. Use `code`, HTTP status, and field
keys. Messages can change to improve clarity.

## Retry guidance

- Retry 429 only after the delay in the `Retry-After` header.
- Retry a 503 with bounded exponential backoff. For `control_standby`, wait for `Retry-After`.
- Retry a mutation only with the same `Idempotency-Key` and identical input.
- Poll a returned operation instead of repeating accepted work.
- Fix validation and conflict input before retrying.
- Do not retry 401 or 403 until credentials or authorization change.
