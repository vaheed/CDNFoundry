---
title: Frontend development
description: Develop and manually qualify the CDNFoundry Filament administrator and domain-user panels.
---

# Frontend development

::: warning Browser qualification is owner-run
Agents may build assets and maintain the manual checklist, but must not launch
Playwright, Chromium, Selenium, Cypress, or other browser automation. Report UI
qualification as not run until the owner completes it.
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
