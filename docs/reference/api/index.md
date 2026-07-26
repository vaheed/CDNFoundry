---
title: API conventions
description: Authenticate to the CDNFoundry API and handle pagination, idempotency, operations, and responses.
---

# API conventions

The control API is rooted at `/api`. It is the same policy boundary used by the
Filament panels. The [endpoint catalog](/reference/api/endpoints) is generated
from the Laravel route registry; the [OpenAPI 3.1 contract](/openapi.json) is a
downloadable build artifact.

## Authentication

`POST /api/auth/login` accepts `email`, `password`, and optional `device_name`.
The plaintext Sanctum bearer token appears only in that response.

```sh
curl --fail --request POST \
  --header "Content-Type: application/json" \
  --data '{"email":"user@example.com","password":"correct horse battery staple","device_name":"automation"}' \
  https://control.example.com/api/auth/login
```

Send later requests with `Authorization: Bearer TOKEN`. `POST /api/auth/logout`
revokes the current token. Personal access tokens are managed through
`/api/me/tokens`.

Public endpoints are `/health`, `/ready`, `/nameservers`, and `/auth/login`.
Administrator endpoints additionally require `users.type=admin`.

## Response envelopes

Successful JSON responses normally contain:

```json
{
  "data": {},
  "meta": {},
  "links": {}
}
```

Resource collections may use Laravel's resource envelope. Export endpoints
return JSON, CSV, or BIND text as documented by the feature guide.

## Cursor pagination

Lists use opaque cursor pagination. Follow the response's `links.next` or pass
the returned cursor as `?cursor=...`. Never construct or decode a cursor as a
stable identifier.

## Idempotency

Mutations marked in the endpoint catalog accept a UUID `Idempotency-Key`.
Replaying the same method, path, and body within 24 hours returns the recorded
JSON response and `Idempotency-Replayed: true`. Different input with the same
key returns `409 idempotency_conflict`.

```sh
curl --fail --request POST \
  --header "Authorization: Bearer ${CDNF_TOKEN}" \
  --header "Idempotency-Key: 34cf14b2-d732-4a66-a360-0ef86d08b7b5" \
  --header "Content-Type: application/json" \
  --data '{"name":"example.com"}' \
  https://control.example.com/api/domains
```

## Asynchronous operations

External, global, or long work returns HTTP 202 with `operation_id`. Poll:

```sh
curl --fail \
  --header "Authorization: Bearer ${CDNF_TOKEN}" \
  "https://control.example.com/api/operations/${OPERATION_ID}"
```

An operation can be `pending`, `running`, `succeeded`, or `failed`. Use the
domain-specific deployment or task endpoint when target acknowledgement matters.

## Rates and payloads

Login, account reads, account mutations, bulk requests, origin tests, edge
registration, and edge-agent traffic use independent rate limiters backed by
[Platform settings](/reference/platform-settings). Payload limits are collected
in [Limits](/reference/limits).

## Route binding and permissions

Policy-aware binding prevents using a record, purge, rule, or operation through
another domain's URL. Foreign or unauthorized nested resources normally return
404 or 403 without exposing their contents.
