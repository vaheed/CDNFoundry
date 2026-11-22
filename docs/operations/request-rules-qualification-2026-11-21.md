---
title: Request rules qualification
description: Qualification evidence and completion gates for request and response rules, recorded on 21 November 2026.
---

# Request rules qualification

Recorded on **21 November 2026** on `dev`. This qualifies the request and response rules admitted in
[Phase 12](../roadmap.md#phase-12-request-and-response-rules). It does not close the separate multi-PoP WAF staging gate.

Qualification is in progress: final browser checks and visual review are pending.

## Completion gates

| Increment | Implementation | Documentation | Automated and runtime qualification | Browser qualification |
| --- | --- | --- | --- | --- |
| Redirect rules | Present | [Reference](../reference/request-rules.md#redirects) | Passed | In progress |
| URL rewrite rules | Present | [Reference](../reference/request-rules.md#url-rewrites) | Passed | In progress |
| Header rules | Present | [Reference](../reference/request-rules.md#header-rules) | Passed | In progress |
| Cache rules and migration | Present | [Reference](../reference/request-rules.md#cache-rules) | Passed | In progress |

## Executed checks

The PHP environment was checked before migration-capable tests: `APP_ENV=testing`, `DB_CONNECTION=sqlite`,
and `DB_DATABASE=:memory:`. Browser tests use their disposable SQLite database and generated credentials.
No persistent PostgreSQL database or named volume was reset.

| Check | Result |
| --- | --- |
| Full PHP suite after the implementation and rollback fixes | 787 passed, 16,321 assertions, 224.62 seconds |
| Request rules, revision history, edge proxy, and rollback after the final backend changes | 41 passed, 866 assertions, 18.86 seconds |
| Edge agent `go vet` and full `go test` | Passed; full tests took 96.67 seconds |
| Final request-rule Go tests | Passed; invalid candidates preserve the current generation |
| Final Rules panel tests after tab switching and repeated-field validation fixes | 7 passed, 152 assertions |
| Real cell `tests/e2e/request_rules.py` | Passed over IPv4 and IPv6 |
| Existing firewall matcher and security actions on the final image, `tests/e2e/custom_rules.py --image IMAGE` | Passed after bounded polling replaced an immediate access-log read |
| Cache path migration comparison against the pre-change runtime | Passed; identical cache headers for prefix TTLs, suffix bypasses, first-match priority, and default caching |
| Observability and qualification Python contracts | 56 passed |
| Compose and OpenAPI checks | Passed |
| Documentation checks and production site build | Passed, including this evidence page, raw measurement download, and production site links |
| PHP formatting and static analysis | Passed against the committed implementation; 574 files pass formatting and PHPStan reports no errors |
| Single-click rule tab regression | 4 passed across both themes and both viewport sizes |
| Complete browser suite, visual snapshots, and accessibility | In progress |

The real-cell tests cover redirects and rewrites after block and challenge decisions, fixed and prefix targets,
query changes, response headers on cache hits, protected headers, the shared budget of 50 header changes,
cache lifetimes and bypasses, separate visitor URL cache entries, and a purge of a rewritten visitor URL.
They alternate headers, cookies, and referrers to verify that the bounded match cache cannot reuse a different
request's result. Corrupt runtime state retains the loaded generation.

The PHP tests cover all four types under the assigned-user, administrator, and unrelated-user policies,
idempotent replay and conflicting reuse, validation, presets, reordering, snapshot shape, migration order,
transaction rollback when operation creation fails, and restoration of all four types from a retained revision.

The earlier `dev` pipeline failed its log-push retry test because its fixed cursor had aged outside retention.
The test now fixes its clock inside that retention window; the retry and retention behavior are unchanged.

## CPU gate

The same runtime image was used for both sides:
`sha256:f3a11d9fddda4bafec5cb4608c3ed8f44c80484f2d33af83a86092e3c31e7dd0`.
The host has an Intel Xeon E5-2697 v4 at 2.30 GHz, 32 logical CPUs, Linux 6.8.0-147-generic, and Docker 29.1.3.
The cell had one CPU, one worker, 1 GiB of memory, and a 512 MiB cache tmpfs. The synthetic load used
20 hostnames, 64 connections, four load threads, cached 10 KiB responses over HTTP, and the WAF off.
Each side ran three 30-second measurements after warmup. Every run returned zero errors.

| Rules per type | CPU microseconds per cached request, each run | Median |
| --- | --- | --- |
| 0 | 460.5, 436.7, 395.2 | 436.7 |
| 50 | 454.9, 481.8, 410.5 | 454.9 |

The median increase is **4.2%**, below the maximum allowed increase of **5%**. This passes the development-stack
gate; the shared host introduces measurement noise and is not an isolated-host capacity measurement. These numbers
describe the repeated cached-request workload, not the cost of every possible matching rule or varying request.
[Raw measurements](https://raw.githubusercontent.com/vaheed/CDNFoundry/dev/docs/public/evidence/request-rules-2026-11-21/edge-cell.jsonl) include image identity, latency, CPU, memory,
request counts, and the environment for all six runs.

The benchmark now writes the same runtime stamp as the agent. Without it, the fixture repeatedly decoded
the whole bundle on timer ticks, unlike a deployed cell. The evaluator retains only the most recent condition
match set per bundle, comparing every input those conditions read with its original type, without allocating
a serialized key on each request. Actions still run on every request.
No map grows with the number of clients. Empty match sets skip action evaluation.

Reproduce either side with `tests/e2e/edge_cell_benchmark.py`, the image above, and these arguments:

```bash
python3 tests/e2e/edge_cell_benchmark.py --image IMAGE --waf off --cpus 1 --duration 30 --repeat 3
python3 tests/e2e/edge_cell_benchmark.py --image IMAGE --waf off --cpus 1 --duration 30 --repeat 3 --request-rules 50
```

## Operations

Follow the [deployment and rollback instructions](../reference/request-rules.md#deployment-and-rollback).
The two forward migrations preserve development data. No production deployment was performed during qualification.
Request-rule changes and revision restoration return `202` with an operation ID and reconcile asynchronously.
The edge agent rejects malformed rules before publishing a generation; the cell also bounds each action.

The migrations are `2026_11_21_000100_create_request_rules_table.php` and
`2026_11_21_000200_add_request_rule_types_and_move_cache_path_rules.php`. They were exercised through the
isolated test environment; no persistent application database migration was run during this qualification.
The before-and-after cache comparison used a runtime built from commit `7719d3e3`, before request rules.

## Implementation files

- `core/app/Support/DomainRequestRules.php`, `RequestRuleData.php`, and `RequestRulePresets.php`: typed state, atomic operations, presets, and bounds.
- `core/app/Http/Controllers/RequestRuleController.php` and `core/app/Http/Resources/RequestRuleResource.php`: authorized, cursor-paginated API responses with explicit fields and `202` operations.
- `core/app/Support/ProxyRevisionRollback.php`: transactional restoration of every active request-rule type.
- `core/app/Filament/Domain/Resources/Domains/RelationManagers/RequestRulesRelationManager.php`: synchronized rule tabs, actions, presets, and validation feedback.
- `edge-agent/request_rules.go` and `docker/openresty/runtime.lua`: bundle validation, bounded evaluation, and the shared header budget.
- `tests/e2e/request_rules.py`, `edge_cell_benchmark.py`, `tests/browser/specs/request-rules.spec.ts`, and the PHP and Go tests: runtime, migration, performance, policy, rollback, browser, and accessibility evidence.

## UI delivery gate

Design read: retain the domain detail page, existing theme tokens, typed condition builder, grouped sheets, and
table/preset patterns. Use neutral tab selection and blue primary actions. Keep state and row actions visible
on a phone. Dials: **ENERGY 1 / RHYTHM 1 / MOTION 1**. The final browser-dependent items below remain pending
until the full run and snapshot review finish.

### Hard gate

| Item | Result and evidence |
| --- | --- |
| R-02, authored UI punctuation | PASS: the changed labels and validation messages contain no em dashes |
| R-03, mobile layout | Pending: final phone snapshots and row-action viewport assertion |
| R-17, sourced statistics | PASS: rule counts come from the scoped database; benchmark figures link to six raw measurements |
| R-18, testimonials | PASS: the Rules screen contains no testimonials |
| R-23, visual assets and navigation | PASS: the owner-admitted Rules tab uses existing Phosphor icons and theme components |
| R-24, working links | PASS: documentation link validation includes the new reference and qualification page |
| R-25, contrast | Pending: final axe checks in both themes |
| R-26, working controls | Pending: final create, delete, type, preset, and validation browser checks |
| R-27, states | PASS: each type has an empty-state message; validation keeps its sheet open and operation feedback reports queued changes |
| R-28, relevant FAQ | PASS: no FAQ was added to the Rules screen |
| R-32, keyboard access | Pending: final single-click test uses Escape to close preset menus; axe checks accessible controls |
| R-33, source implementation | PASS: the implementation is in the Filament relation manager and shared form source |
| R-34, theme integrity | Pending: final light and dark snapshot review |
| R-35, executed UI | Pending: full browser suite and visual review |
| R-36, evidenced claims | PASS: runtime and CPU claims above name the executed checks and their limitations |
| R-37, direction | PASS: the design read above follows the repository theme and frontend patterns |
| R-38, honest content | PASS: qualification uses the documented synthetic browser seed, not customer claims or data |
| R-39, semantics and interaction | Pending: final axe checks, labeled row menu, active-tab semantics, and phone checks |
| R-40, media and performance | PASS: the screen adds no media; CPU figures come from the attached measurements |

### Purpose gate

| Item | Result and evidence |
| --- | --- |
| R-01, gradients | PASS: the changed screen uses the existing flat theme surfaces |
| R-04, icons | PASS: arrows mean reorder, pencil means edit, trash means delete, and sliders open presets |
| R-06, typography | PASS: the shared Inter theme renders labels and values; no local font was added |
| R-07, background patterns | PASS: no background pattern was added |
| R-08, decorative arrows | PASS: arrows appear only on the two reorder actions |
| R-09, badges | PASS: badges communicate the stored Enabled or Disabled state |
| R-10, glass effects | PASS: no glass effect was added |
| R-12, shadows | PASS: sheets and menus retain the shared elevation styles |
| R-13, glow | PASS: no glow was added |
| R-14, repeated cards | PASS: rules use the existing table rather than feature cards |
| R-19, motion | PASS: no screen-specific animation was added |
| R-22, illustrations | PASS: no illustration was added |

### Liveliness

| Item | Result and evidence |
| --- | --- |
| Explicit dials | PASS: ENERGY 1, RHYTHM 1, MOTION 1, as declared above |
| Consistency with dials | PASS: compact operational tables and restrained state badges retain the existing product tone |
| Focal point | PASS: each type exposes one Add action beside its preset menu |
| Structural spacing | PASS: the domain tabs, type tabs, table, and sheet groups follow the existing hierarchy |
| Deliberate accent | PASS: blue marks the primary action while current tabs remain neutral |
| Identity motif | PASS: Phosphor icons, dot state badges, and the shared grouped-sheet pattern recur |
| Declared design read | PASS: the direction is recorded before this gate |

### Craftsmanship and consistency

| Item | Result and evidence |
| --- | --- |
| C-1, intentional choices | PASS: row controls move into one menu to preserve text space, especially on phones |
| C-2, functional completeness | Pending: final action and preset browser checks |
| C-3, content-driven composition | PASS: the four rule types are the admitted product scope; no template sections were added |
| C-4, resilience | Pending: final themes, phone layout, validation, and repeated tab-switch checks |
| C-5, evidence | PASS: the gate distinguishes executed checks from pending qualification |
| R-05, layout specificity | PASS: the Rules screen extends the established domain detail layout |
| R-11, shapes | PASS: controls, badges, sheets, and tables retain the shared component shapes |
| R-15, action labels | PASS: Add redirect, Add rewrite, Add header rule, and Add cache rule name their effect |
| R-16, copy | PASS: the changed copy describes rule behavior, validation, and queued operations without marketing claims |
| R-20, product fit | PASS: typed conditions, origin rewriting, header protection, and cache policy are CDN operations |
| R-21, theme choice | Pending: both user-selected themes receive final browser qualification |
| R-29, color system | PASS: no local color or theme token was added |
| R-30, independent identity | PASS: the screen uses the established CDNFoundry shell and components |
| R-31, reasons | PASS: the design read and mobile-table pattern explain the layout and interaction choices |
