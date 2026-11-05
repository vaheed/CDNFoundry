---
name: cdnf-filament-ui
description: Build or change CDNFoundry Filament screens in the admin (/admin) or domain (/app) panel, with scoped queries, typed forms, operation-backed actions, and the browser suite. Use for any UI change.
---

# cdnf-filament-ui

## Purpose
Give administrators and domain users bounded, policy-scoped screens that show desired, pending, active, and failed state.

## When to use
Any change under `core/app/Filament/**` or `core/resources/views/filament/**`.

## Required inputs
The panel, the actor, the query scope, the fields and their validation, and the operations an action starts.

## Files normally touched
`core/app/Filament/Admin/Resources/**`, `Admin/Pages/*.php`, `Admin/Widgets/*.php`, `core/app/Filament/Domain/Resources/Domains/**` (`ViewDomain.php`, relation managers), `core/app/Providers/Filament/AdminPanelProvider.php`, `DomainPanelProvider.php`, `core/app/Support/FilamentHelp.php`, `core/app/Providers/Filament/PanelDesign.php` and `PhosphorIcons.php`, `core/resources/css/filament/shared/theme.css`, `tests/browser/**`.

## Procedure
0. Load `cdnf-ui-design` and the `antislop` skill before changing any screen or UI copy.
1. Scope every query to the user (`DomainPolicy`). Domain users never see other domains, platform settings, or other tenants' data.
2. Reuse the API's validation (`Support/*Data` classes) so the UI and API cannot disagree.
3. Actions that cause external work create an `Operation` and dispatch the same job as the API, and never call a target directly.
4. Tables are paginated and bounded. Show empty, degraded, and failure states. Confirm destructive actions.
5. Put help text next to fields with `FilamentHelp::label(...)`.
6. Follow `cdnf-ui-design` (the Kumo design language ported into Filament) and the patterns in `docs/development/frontend.md`. Use Filament components and the shared `x-ui.*` Blade components; their Kumo look comes from the skins in `theme.css`, never from classes or styles added per screen. Icons are Phosphor names (`phosphor-<name>`), and Filament's own icons are mapped in `PhosphorIcons`. Add or update the Playwright spec, visual snapshots, and axe check under `tests/browser`, and run them against a development or test stack (never production). Report their results; never report an unrun browser test as passed.

## Validation commands
- Backend: `make dev-test`, or one class with `docker compose -f compose.dev.yml -f compose.test.yml run --rm --no-deps core php artisan test --compact --filter <Class>` (the test override forces in-memory SQLite; never run tests against PostgreSQL).
- Style: `docker compose -f compose.dev.yml -f compose.test.yml run --rm --no-deps core vendor/bin/pint --test`.
- Feature: `FilamentPanelAccessTest`, `FilamentWorkflowTest`, `DesignSystemConsistencyTest` (tokens only, neutral greys, Phosphor icons only).
- Frontend assets: `make dev-assets`.

## Definition of done
Policy-correct screens, the same validation as the API, operation-backed actions, passing browser tests, snapshots, and accessibility checks, and passing feature tests.

## Stop conditions
Stop if the UI needs a second frontend, custom RBAC, or state that the API cannot express.

## Forbidden shortcuts
No direct PowerDNS, edge, or ACME calls from Livewire actions, no unscoped `Model::all()`, no logic change hidden in a presentation change, and no claiming that a browser check passed.

## Expected completion summary
Screens changed, scoping, actions and operations, tests run, and the browser suite, snapshot, and accessibility results (with any snapshot changes and why).
