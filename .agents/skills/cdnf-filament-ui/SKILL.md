# cdnf-filament-ui

## Purpose
Build policy-consistent Filament administrator or domain-user UI.
## When to use
For resources, pages, widgets, actions, and operational status.
## Required inputs
Panel, actors, policy, typed fields, desired/active states, destructive actions, and E2E flow.
## Files normally touched
Panel provider, Filament resource/page/widget, shared policy/model logic, browser tests, UI docs.
## Procedure
Scope the panel/query; build bounded tables and typed forms; show desired, pending, active, and failed state; confirm destructive actions; route external work through operations; cover empty/degraded/failure states and E2E.
## Validation commands
`cd core && php artisan test --compact`; browser E2E command; `vendor/bin/pint --test`.
## Definition of done
The right users can complete the workflow and wrong/disabled users cannot see or invoke it.
## Stop conditions
Stop if UI would duplicate business rules or conceal pending/failure state.
## Forbidden shortcuts
No separate frontend, unbounded widgets, direct runtime mutation, fake placeholder pages, or navigation-only authorization.
## Expected completion summary
Panel, workflows, access boundaries, tests, and limitations.
