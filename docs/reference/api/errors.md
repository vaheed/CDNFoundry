---
title: API errors
description: Handle CDNFoundry stable JSON errors, validation failures, conflicts, and service outages.
---

# API errors

::: warning Automate against codes, not prose
Clients should branch on HTTP status and stable machine-readable error codes.
Human messages may gain clarification and must not be parsed as a protocol.
:::

API and edge routes render JSON errors.

## Stable error

```json
{
  "message": "The operation could not be completed.",
  "code": "conflict",
  "details": {}
}
```

Common framework mappings are:

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
| 5xx | `server_error`; the message is fixed and never carries internal detail |

A domain you cannot view answers `404 not_found`, exactly like a domain that does
not exist, so domain ids cannot be enumerated. A missing resource always answers
with the fixed message "The requested resource was not found."; messages never
name internal classes. `403 forbidden` remains for a resource you can see but
may not change.

Analytics dependency failure uses HTTP 503 and `analytics_unavailable`.
Account disablement and idempotency conflicts use stable specific codes where
the controller or middleware supplies them.

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

- Retry 429 only after the response's rate-limit window.
- Retry dependency 503 with bounded exponential backoff.
- Retry a mutation only with the same `Idempotency-Key` and identical input.
- Poll a returned operation instead of repeating accepted work.
- Fix validation and conflict input before retrying.
- Do not retry 401 or 403 until credentials or authorization change.
