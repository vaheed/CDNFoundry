---
name: cdnf-feature-module
description: Plan and implement an owner-admitted CDNFoundry feature end to end (model, API, UI, runtime, docs, tests) inside the AGENTS.md contract. Use at the start of any feature or behavior change.
---

# cdnf-feature-module

## Purpose
Turn one admitted requirement into the smallest complete Laravel slice that keeps the AGENTS.md invariants.

## When to use
At the start of any feature or behavior change, before choosing files. Then load the area skill (`cdnf-api-endpoint`, `cdnf-dns-runtime`, `cdnf-edge-runtime`, `cdnf-tls-lifecycle`, `cdnf-telemetry-analytics`, `cdnf-filament-ui`).

## Required inputs
The requirement and its roadmap finding id (`docs/roadmap.md`), durable state, external effects, bounds, permissions, rollback, and the user-visible docs page.

## Files normally touched
Use `cdnf-codebase-map` to locate them. Typically: a migration in `core/database/migrations`, a model in `core/app/Models`, a controller under `core/app/Http/Controllers`, a job under `core/app/Jobs`, a Filament resource or page, `core/tests/Feature/*Test.php`, one `tests/e2e/*.py`, and the matching `docs/guides` or `docs/reference` page.

## Procedure
1. Read the finding and the existing code path first. Write down the state, the effects, and the bounds.
2. Add the schema with database constraints (CHECK, UNIQUE, FK). JSONB only for structures validated in a `Support/*Data` class.
3. Keep CRUD in controllers and Eloquent. No repository or service wrappers.
4. For external work, use the reconciliation pattern (`cdnf-reconciliation`): transaction, revision bump, `Operation::coalesceDomain`, one unique job, `202` with an operation id.
5. Expose the same policy through API and Filament (`DomainPolicy`, admin middleware).
6. Write the failing regression test first, then the fix.
7. Update the docs page that describes the behavior.

## Validation commands
- Backend: `make dev-test`, or one class with `docker compose -f compose.dev.yml -f compose.test.yml run --rm --no-deps core php artisan test --compact --filter <Class>` (the test override forces in-memory SQLite; never run tests against PostgreSQL).
- Style: `docker compose -f compose.dev.yml -f compose.test.yml run --rm --no-deps core vendor/bin/pint --test`.
- Contract: `make openapi-check` after API changes, and `make config-check` after Compose changes.
- Runtime: the matching `tests/e2e/*.py` against the dev stack (`make dev-up`).

## Definition of done
The implementation, regression test, runtime test, and docs are all present. Replaced code is deleted. There is no lifecycle label (`V2`, `New`, a phase number) in names.

## Stop conditions
Stop and ask when the change needs a new service, a per-domain process/timer/server block, Laravel in the traffic path, or a rule that conflicts with AGENTS.md.

## Forbidden shortcuts
No synchronous external calls in HTTP requests, no mocks in place of runtime qualification, no `RefreshDatabase` against PostgreSQL, and no backward-compatibility shims (2.0 is zero-state).

## Expected completion summary
Files changed, behavior, migrations, tests actually run with their results, docs updated, and limitations.
