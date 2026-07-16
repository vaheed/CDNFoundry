# cdnf-feature-module

## Purpose
Implement an accepted Laravel feature without speculative architecture.
## When to use
For a new feature area or meaningful extension.
## Required inputs
Roadmap requirement, data owner, allowed user types, API/UI needs, external effects, failure and rollback behavior.
## Files normally touched
Only required migrations, models, policies, requests, resources, controllers, jobs, Filament classes, tests, and module docs.
## Procedure
Read `AGENTS.md` and the requirement; define state, permissions, bounds, and rollback; implement the smallest standard-Laravel slice; add API and UI through shared policies; document behavior.
## Validation commands
`cd core && php artisan test --compact`; `cd core && vendor/bin/pint --test`; relevant real-runtime commands.
## Definition of done
Accepted behavior, permissions, bounds, audit/idempotency, tests, and docs are complete.
## Stop conditions
Stop for unclear ownership, unaccepted scope, missing rollback, or a required external decision.
## Forbidden shortcuts
No repositories, generic services, pre-created folders, phase names, synchronous external effects, or placeholder UI.
## Expected completion summary
Requirement, changed files, behavior, tests run/results, operations, and limitations.
