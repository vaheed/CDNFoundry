---
title: Frontend development
description: Develop and qualify the CDNFoundry Filament administrator and domain-user panels with the design system and the Playwright browser suite.
---

# Frontend development

::: tip Browser qualification is agent-run
The Playwright suite in `tests/browser` covers both panels in light and dark mode at desktop and phone width, with visual snapshots and axe accessibility checks. Run it against a development or test stack with seeded data, never production. See [Browser suite](#browser-suite).
:::

The frontend is Filament inside the Laravel monolith. There is no second SPA or
frontend API client. Both panels compile the shared
`resources/css/filament/shared/theme.css` through Vite.

## Panels

| Path | Audience | Navigation |
| --- | --- | --- |
| `/admin` | administrators | Observe, Operate, Infrastructure, Customers, Governance, Account |
| `/app` | assigned domain users | Domains, Observe, Account |

The administrator panel exposes dashboard, system DNS identity, platform
settings, users, domains, DNS clusters, edge pools, edges/cells, operations,
audit logs, telemetry, profile, and tokens.

The `/admin` landing page is a full-width operations overview composed of
independently polling Filament widgets. Its compact investigation section spans
the entire dashboard width; filters persist in the URL, and charts use
Filament's bundled Chart.js integration. See
[Laravel operations dashboard](../operations/laravel-operations-dashboard.md)
for data sources, cache keys, refresh intervals, failure semantics, and the
performance budget.

The domain panel exposes dashboard, domains, domain detail/actions, DNS records,
security rules, analytics, profile, and tokens. Administrator domain views reuse
the same policy-aware domain resource and add administrator actions.

## Build assets

```sh
make dev-assets
```

This builds the production theme inside Docker and exports
`core/public/build`. `make dev-up` invokes it automatically. For host-local
development inside `core`, `npm run dev` and `npm run build` are available, but
the supported clean-checkout path does not require host Node.js.

## Shared components

Blade components under `resources/views/components/ui` provide status pills,
stat cards, empty/widget states, data tables, form actions, and list rows. Keep
business validation in controllers/support types and mirror bounds in Filament
forms for usability.

Use `App\Support\FilamentHelp::label()` for optional explanatory copy on
Filament fields, entries, table headings, and section headings. The rendered
label is the help target: pointer hover and keyboard focus reveal the tooltip,
without a separate help icon or persistent paragraph. Keep validation errors,
warnings, destructive-action confirmations, degraded-state reasons, live
measurements, and other operational evidence visible because they are state,
not optional help.

## Design system

All screens use the tokens in `resources/css/filament/shared/theme.css`. Never write a hex value, a Filament default color, or an inline style in a screen.

| Token group | Rule |
| --- | --- |
| Palette | Zinc neutrals (`--cdn-background`, `--cdn-surface`, `--cdn-border`, `--cdn-text*`) and one blue accent (`--cdn-accent`). |
| Status | `--cdn-healthy`, `--cdn-degraded`, `--cdn-failed`, `--cdn-pending`, `--cdn-disabled`, each with a `-soft` background. Every badge carries a word, never color alone. |
| Shape | 8 px radius, borders instead of shadows, compact rows. |
| Type | System UI font for text; the monospace stack (`.cdn-mono`, `FontFamily::Mono`) for every number, ID, timestamp, address, hostname, revision, and checksum. No font files ship. |
| Modes | Light and dark are complete; both are checked by the browser suite. |

### Patterns

- **Shell:** grey app background, sidebar on it, the page in one bordered white panel. Global search opens with Ctrl+K or Cmd+K. Groups collapse. The sidebar footer shows the documentation link and the version. Both panels share this through `App\Providers\Filament\PanelDesign`.
- **List page:** title, one description line, primary action top right, a search box, a table with status badges, and the empty state with one action. Example: `DomainResource::table()`.
- **Detail page:** header with the main actions, a KPI strip, a "Needs attention" box that lists only problems and disappears when there are none, and tabs with counts. Example: `ViewDomain`, `DomainKpiStrip`, `DomainNeedsAttention`.
- **Create, edit, and inspect:** open in a right-side sheet (`->slideOver()`, 42 rem wide). Example: the Add domain action in `ListDomains`.
- **Settings:** one row per setting with the label and a one-line description on the left and the control on the right, the default value visible, a Reset link, and "Save changes" disabled until something changes. Example: `PlatformSettings`.
- **Overview:** one status line with a "Polling active" indicator, a KPI strip of at most five values, "Needs attention" (at most four rows, then a link), and the five most recent operations. Detailed charts live on the telemetry page.
- **Live views:** poll every ten seconds or more; Livewire stops polling while the tab is hidden.
- **Copy:** at most one description line per card; explanations go to the documentation. Run the `antislop` skill (`.agents/skills/antislop/SKILL.md`) on every screen and on UI copy.
- **Test ids:** every interactive element that a test touches has `data-testid="<entity>-<element>-<qualifier>"`.

## Browser suite

Agents own browser qualification. The Playwright suite in `tests/browser` signs in to both panels in light and dark mode at desktop (1440 px) and phone (390 px) width, takes visual snapshots, and runs axe against every page (serious and critical violations fail).

```sh
tests/browser/run.sh                       # run
tests/browser/run.sh --update-snapshots    # after an intended design change; say what changed and why
```

`run.sh` builds assets, starts `browser-app` from `compose.browser.yml` (a disposable SQLite database on tmpfs, generated credentials, no PostgreSQL and no named volumes), runs the suite, and removes the container. It refuses any base URL other than localhost. Never point it at production. Only the screens rebuilt so far are covered.

## Qualification

Feature tests render panel routes, scope resources, confirm shared themes and
navigation, and exercise Livewire/Filament actions without browser automation.
Feature tests do not claim to prove visual layout, keyboard navigation, mobile
viewports, focus behavior, or real browser downloads.

The operations filter section spans the dashboard width. Telemetry exposes
range, domain, and edge scope in one column on mobile, two columns on tablet,
and one aligned row on desktop. Content-heavy overview widgets use half or full
rows instead of compressing several paragraphs into one-third-width cards.
Delayed and stale aggregate notices appear once in the page-level status area,
not repeatedly inside charts. Full-height states are reserved for a chart that
cannot render. Paired overview charts use equal grid spans and maximum heights.
