---
name: cdnf-api-endpoint
description: Add or change a CDNFoundry REST endpoint: routing, policy binding, validation, idempotency, cursor pagination, 202 operations, stable errors, and OpenAPI. Use for any change under core/routes/api.php.
---

# cdnf-api-endpoint

## Purpose
Build bounded, policy-checked JSON endpoints that behave the same for browser sessions and API tokens.

## When to use
Any route added or changed in `core/routes/api.php` or `core/routes/web.php` (`/edge/v1`).

## Required inputs
The route, its actor (domain user, admin, or edge), its input bounds, and whether the effect is PostgreSQL-only or external.

## Files normally touched
`core/routes/api.php`, `core/app/Http/Controllers/*.php` or `Controllers/Admin/*.php`, `core/app/Http/Requests/**`, `core/app/Http/Resources/*.php`, `core/app/Policies/DomainPolicy.php`, `core/app/Http/Middleware/IdempotentRequest.php`, `core/app/Console/Commands/GenerateOpenApi.php`, `docs/reference/api/endpoints.md`, `docs/reference/api/errors.md`.

## Procedure
1. Place the route in the right group: authenticated customer routes, the `admin` group (`EnsureUserIsAdmin`), or `/edge/v1` (`AuthenticateEdge`).
2. Mutations get `->middleware('idempotent')`. Bulk routes also get `throttle:bulk` and explicit item and byte limits.
3. Authorize with `Gate::authorize(...)` on the bound model. Re-check it inside the locking transaction.
4. Validate typed and bounded input. Return errors with stable codes from `docs/reference/api/errors.md`.
5. Lists use `cursorPaginate`. External or long work returns `202` with an `operation_id`.
6. **Always return an API resource with an explicit field list.** Never serialize a raw model or paginator: `GET /domains/{domain}/revisions` leaked decrypted TLS keys that way (roadmap C1, L5).
7. Regenerate OpenAPI: `php artisan cdnf:api:openapi` and check with `make openapi-check`.

## Validation commands
- Backend: `make dev-test`, or one class with `docker compose -f compose.dev.yml -f compose.test.yml run --rm --no-deps core php artisan test --compact --filter <Class>` (the test override forces in-memory SQLite; never run tests against PostgreSQL).
- Style: `docker compose -f compose.dev.yml -f compose.test.yml run --rm --no-deps core vendor/bin/pint --test`.
- `make openapi-check`.
- Add tests for: happy path, another tenant's id (404/403), a disabled user, a validation error, an idempotency replay, and a key reuse with different input (409).

## Definition of done
Policy, validation, idempotency, pagination, resource, OpenAPI, error codes, and tests all agree.

## Stop conditions
Stop if the endpoint must call PowerDNS, an edge, ACME, or ClickHouse synchronously, or if it needs a new role model (custom RBAC is forbidden).

## Forbidden shortcuts
No `response()->json($model)`, no offset pagination, no unbounded `->get()`, and no secret values outside their one-time display boundary.

## Expected completion summary
Routes, the auth and policy matrix, bounds, idempotency behavior, the OpenAPI diff, and tests run.
