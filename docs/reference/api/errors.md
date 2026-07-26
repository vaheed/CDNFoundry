---
title: API errors
description: Handle CDNFoundry stable JSON errors, validation failures, conflicts, and service outages.
---

# API errors

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
| 409 | `conflict` or a more specific controller code |
| 422 | `unprocessable` |

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
