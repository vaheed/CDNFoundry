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

The panels use the Kumo design language (the design system behind `kumo`, MIT), ported into Filament. Kumo itself is a React library and is not installed. Its token values, type scale, ring edges, and component shapes are written as CSS in `resources/css/filament/shared/theme.css` and applied to Filament's own classes and to the Blade components in `resources/views/components/ui`. Cloudflare's orange and logo are not used. Never write a hex value, a raw color function, a Filament default color, or an inline color in a screen; `DesignSystemConsistencyTest` fails on all of them.

| Token group | Rule |
| --- | --- |
| Surfaces | `--cdn-canvas` (app background), `--cdn-base` (cards, tables, the page panel), `--cdn-elevated` (card and table headers), `--cdn-recessed` (code, empty data), `--cdn-overlay` (menus, dialogs), `--cdn-control` (inputs). Neutrals have no hue. |
| Lines and fills | `--cdn-line` for edges (drawn as a 1 px ring), `--cdn-hairline` for dividers inside a card, `--cdn-tint` for hover and selection, `--cdn-fill` for neutral badges and meter tracks. |
| Text | `--cdn-text-strong` (titles, selected items), `--cdn-text-default`, `--cdn-text-subtle` (labels, meta), `--cdn-text-placeholder`, `--cdn-link`. |
| Accent | `--cdn-brand` (Kumo blue) for primary buttons, checked controls, and focus rings only. Selection stays neutral. |
| Status | `--cdn-success`, `--cdn-warning`, `--cdn-danger`, `--cdn-info`, `--cdn-neutral`, each with a `-text` and a `-tint` variant. Light subtle and warning text are darker than Kumo's so they meet AA on every surface. Every badge carries a word, never color alone. |
| Shape | 8 px radius (`--cdn-radius-lg`) for cards, buttons, inputs, and dialogs; 6 px for small buttons; badges fully rounded. Edges are rings, and only buttons, inputs, and simple cards add `--cdn-shadow-xs`. |
| Type | Inter, bundled, with tabular digits. Kumo scale: 12, 13, 14, and 16 px. Body, controls, and table data are 14 px. Headings are semibold and sentence case, with no letter-spacing change and never bold. Code values use `.cdn-code` at 0.9em. |
| Icons | Phosphor, regular weight (`phosphor-<name>`, from `codeat3/blade-phosphor-icons`), 16 px next to 14 px text. Filament's own icons are mapped in `App\Providers\Filament\PhosphorIcons`. Heroicons are not used. |
| Modes | Light and dark are complete, using Filament's `.dark` class; both are checked by the browser suite. |

### Components

| Kumo component | In CDNFoundry |
| --- | --- |
| Sidebar | Filament sidebar, 16.25 rem wide, 34 px items, neutral active tint, sticky footer (`<x-ui.sidebar-footer>`). |
| Command Palette | Filament global search (Ctrl+K or Cmd+K). |
| Page Header, Breadcrumbs | Filament page header with the status badge and actions. |
| Button | `primary` is the brand fill, `gray` is the secondary ring button, `danger` is destructive, icon buttons are ghost. |
| Badge | Tint badges in headers and rows (`<x-ui.status-pill tone="…">`). Dot badges (`appearance="dot"`) for statuses in lists; Filament table badges without an icon render as dot badges automatically. |
| Table, Pagination | Filament tables: compact elevated header, hover tint, no zebra striping. |
| Layer Card | Filament sections, `<x-ui.detail-section>`, `.cdn-panel`: elevated header strip, base body. Never nest one inside another. |
| Banner | "Needs attention" rows (`.cdn-condition-row` with `data-tone`). |
| Meter | The `meter` value of a `<x-ui.kpi-strip>` item (0 to 1). |
| Empty | `<x-ui.empty-state icon="…">` and Filament empty states. |
| Dialog | Filament modals and right-side sheets, with lines under the header and over a sticky footer. |
| Tabs | Underline tabs in ink, with counts as neutral badges. |
| Clipboard Text, Sensitive Input | The copy icon in `<x-ui.detail-section>` rows and the one-time secret dialog (`.cdn-secret-value`). |
| Charts | Filament Chart.js widgets with the `--cdn-chart-*` colors. |

### Patterns

- **Shell:** the canvas background, the sidebar on it, and the page in one base panel with a line ring. Global search opens with Ctrl+K or Cmd+K. Groups collapse. The sidebar footer shows the documentation link and the version. Both panels share this through `App\Providers\Filament\PanelDesign`.
- **List page:** title, one description line, primary action top right, a search box, a table with status badges, and the empty state with one action. Example: `DomainResource::table()`.
- **Detail page:** the title with a status badge and the action menus, a KPI strip, a "Needs attention" box that lists only problems and disappears when there are none, one row of quick-action buttons, and one tab row. The first tab, Overview, is two columns of compact cards (one column on phone) that leave out rows with nothing to say; the other tabs are the related tables with counts. Example: `ViewDomain`, `DomainDetailSections::overview()`, `DomainKpiStrip`, `DomainNeedsAttention`.
- **Create, edit, and inspect:** a form longer than a few fields opens in a right-side sheet (`->slideOver()->modalWidth(Width::TwoExtraLarge)`), single column, grouped. A form of one or two fields (purge cache, development mode) opens as a small centered dialog (`->modalWidth(Width::Medium)`). The confirm button names the action ("Purge cache", never "Submit"). Example: `ViewDomain::getHeaderActions()`, the DNS record sheet.
- **Form help:** every field whose format is not obvious has an example placeholder (`203.0.113.0/24`, `mail.example.net.`) and, when needed, one helper line saying what it expects; both follow the selected type. Countries and continents are searchable selects with names (`GeoVocabulary::countryOptions()`), never a bare code box. List fields say "Type a value and press Enter", never "New tag".
- **Tabs:** a page with related tables shows one tab row at the top: Overview first, then each table with its count (`hasCombinedRelationManagerTabsWithContent()`). Example: `ViewDomain`, `ViewEdge`.
- **Tables:** every row has the same height; empty cells and action cells do not make a row taller. Each column holds one fact on one line; secondary facts move to columns that are off by default.
- **Statuses:** green, amber, red, blue, or grey badges with a dot and a word, in sentence case ("Pending verification"). Counts and entered values are neutral chips without a dot. Menus list every item in ink with an icon; only destructive items are red.
- **Settings:** one row per setting with the label on the left, the control on the right, its one-line description under the control, and a hairline between rows, the default value visible, a Reset link, and "Save changes" disabled until something changes. Example: `PlatformSettings`.
- **Overview:** one status line with a "Polling active" indicator, a KPI strip of at most five values, "Needs attention" (at most four rows, then a link), and the five most recent operations. Detailed charts live on the telemetry page.
- **Live views:** poll every ten seconds or more; Livewire stops polling while the tab is hidden.
- **Copy:** at most one description line per card; explanations go to the documentation. Run the `antislop` skill (`.agents/skills/antislop/SKILL.md`) on every screen and on UI copy.
- **KPI strip:** one card with hairline dividers (`<x-ui.kpi-strip>`), at most five values, never separate boxes. A ratio adds a meter.
- **Rows:** a record's facts are label-and-value rows (`<x-ui.detail-section>`); a value made of several facts shows chips, a long list collapses with "Show N more", and copy is an icon on the same line.
- **Menus:** a "…" menu holds two or more actions; a single action is shown directly.
- **Owner design rules:** the review rules every screen must meet are in `.agents/skills/antislop/SKILL.md` under "Owner design rules". Agents load that file before any UI work.
- **Test ids:** every interactive element that a test touches has `data-testid="<entity>-<element>-<qualifier>"`.

## Browser suite

Agents own browser qualification. The Playwright suite in `tests/browser` signs in to both panels in light and dark mode at desktop (1440 px) and phone (390 px) width, takes visual snapshots, and runs axe against every page (serious and critical violations fail).

```sh
tests/browser/run.sh                       # run
tests/browser/run.sh --update-snapshots    # after an intended design change; say what changed and why
```

`run.sh` builds assets, starts `browser-app` from `compose.browser.yml` (a disposable SQLite database on tmpfs, generated credentials, no PostgreSQL and no named volumes), runs the suite, and removes the container. It refuses any base URL other than localhost. Never point it at production. The seed (`tests/browser/seed.php`) is deliberately awkward: 40 domains, very long names, IPv6, punycode, long errors, ten revisions, and failures, because most layout defects only show with real data. `spike.spec.ts` covers the rebuilt workflows with snapshots, and `every-screen.spec.ts` loads every screen of both panels and runs axe on each. Set `CDNF_BROWSER_REVIEW=1` to also write review screenshots to `tests/browser/review/`.

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
