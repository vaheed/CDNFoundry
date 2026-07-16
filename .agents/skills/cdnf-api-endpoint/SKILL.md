# cdnf-api-endpoint

## Purpose
Add or change a bounded, authorized API endpoint.
## When to use
For synchronous desired-state CRUD or asynchronous operations.
## Required inputs
Route, actors, request/response contract, state change, bounds, rate class, and reconciliation requirement.
## Files normally touched
Routes, policies, form requests, resources, controllers/jobs, OpenAPI, and feature tests.
## Procedure
Classify PostgreSQL-only versus external work; use policy-aware binding; validate typed bounded input; return a resource/cursor page or `202` operation; add idempotency and stable errors; update OpenAPI and tests.
## Validation commands
`cd core && php artisan test --compact`; OpenAPI contract command when present; `vendor/bin/pint --test`.
## Definition of done
Contract, authorization, validation, idempotency, pagination/operation behavior, errors, tests, and docs agree.
## Stop conditions
Stop if HTTP would directly mutate a runtime or the permission/data owner is unclear.
## Forbidden shortcuts
No offset pagination, secret replay, unbounded bulk input, or external calls in requests.
## Expected completion summary
Endpoint contract, state/effects classification, tests, and limitations.
