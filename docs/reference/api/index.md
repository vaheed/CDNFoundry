---
title: API conventions
description: Authenticate to the CDNFoundry API and handle pagination, idempotency, operations, and responses.
---

# API conventions

::: warning Retry mutations safely
Do not retry a mutation without the same UUID `Idempotency-Key` and identical
input. A timeout can occur after the server commits desired state.
:::

The control API is rooted at `/api`. The admin and domain panels use the same
access rules, so a user can do through the API only what the panels allow.
The [endpoint catalog](endpoints.md) is generated from the Laravel route list.
The [OpenAPI 3.1 contract](https://raw.githubusercontent.com/vaheed/CDNFoundry/main/docs/public/openapi.json)
is a downloadable build file.
Errors are described in [API errors](errors.md).

## Authentication

Log in to get a bearer token. `POST /api/auth/login` accepts `email`,
`password`, and an optional `device_name`. If the account uses two-factor
login, also send `otp`. Without it the API answers `401 otp_required`.
The response shows the plaintext token once. Store it then.

```sh
curl --fail --request POST \
  --header "Content-Type: application/json" \
  --data '{"email":"user@example.com","password":"correct horse battery staple","device_name":"automation"}' \
  https://control.example.com/api/auth/login
```

Send later requests with `Authorization: Bearer TOKEN`. `POST /api/auth/logout`
revokes the current token. Manage personal access tokens through
`/api/me/tokens`. Each user may hold at most 50 tokens across login, the API,
and the panel. Revoke an old token before you create another.

A same-origin browser request can use the signed-in panel session instead.
Laravel Sanctum accepts the session only from configured stateful origins.
The same account and domain rules apply, and every change needs a CSRF token.
For a session request, `POST /api/auth/logout` ends that browser session and
rotates its CSRF token. A bearer-token logout revokes only that token.

Four endpoints need no login: `/health`, `/ready`, `/nameservers`, and
`/auth/login`. Administrator endpoints also require an administrator account
(`users.type=admin`). A domain user sees only the domains assigned to them.

## Response envelopes

Successful JSON responses normally contain:

```json
{
  "data": {},
  "meta": {},
  "links": {}
}
```

A resource collection may use Laravel's resource envelope instead. Export
endpoints return JSON, CSV, or BIND text, as the feature guide describes.

## Cursor pagination

Lists use opaque cursor pagination. Follow the response's `links.next`, or
pass the returned cursor as `?cursor=...`. Do not build or decode a cursor
yourself, because its format can change.

## Idempotency

Mutations marked in the endpoint catalog accept an `Idempotency-Key` header.
The key must be a UUID. A key that is not a UUID returns `422 invalid_idempotency_key`.

Repeat the same method, path, query string, and body within 24 hours. The API
returns the recorded JSON response and the header `Idempotency-Replayed: true`.
The same key with different input returns `409 idempotency_conflict`.

The change and its replay record commit in one PostgreSQL transaction. If a
request with the same key is still running, you get `409 idempotency_busy`.
Retry the same request after the first one finishes. If a worker dies before
commit, both are rolled back. Queued jobs start only after the commit.

Access rules run before a replay. A user whose domain assignment was removed
cannot read an older cached response. Receipts and replayed responses leave out
one-time tokens.

A domain user can read an operation that belongs to a domain they can still
access. Removing the assignment also removes access to older operation input
and results. Only administrators can read global operations and the operation
history.

```sh
curl --fail --request POST \
  --header "Authorization: Bearer ${CDNF_TOKEN}" \
  --header "Idempotency-Key: 34cf14b2-d732-4a66-a360-0ef86d08b7b5" \
  --header "Content-Type: application/json" \
  --data '{"name":"example.com"}' \
  https://control.example.com/api/domains
```

## Asynchronous operations

External, global, or long work returns HTTP 202 with an `operation_id`. This
covers cache settings, cache purges, Geo-DNS changes, pool and endpoint
changes, cell actions, fleet rollouts, and failed-job retries. A purge also
returns the same ID as `data.operation_id`. A rollout's operation stays
`running` while the rollout is running, paused, or rolling back. Poll the
operation:

```sh
curl --fail \
  --header "Authorization: Bearer ${CDNF_TOKEN}" \
  "https://control.example.com/api/operations/${OPERATION_ID}"
```

An operation is `pending`, `running`, `succeeded`, or `failed`. A `succeeded`
operation means the control plane finished its part. When you need to know that
an edge or DNS server applied the change, use the domain deployment or task
endpoint.

## Rates and payloads

Login, account requests, bulk requests, origin tests, edge registration, and
edge-agent traffic each have their own rate limit. Most limits are
[platform settings](../platform-settings.md). A request over the limit gets
`429` with a `Retry-After` header. [Limits](../limits.md) lists payload limits.

## Route binding and permissions

A record, purge, rule, or operation cannot be used through another domain's
URL. A nested resource that belongs elsewhere, or that you may not access,
normally returns 404 or 403 and shows no content.
