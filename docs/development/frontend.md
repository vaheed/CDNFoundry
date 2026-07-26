---
title: Frontend development
description: Develop and manually qualify the CDNFoundry Filament administrator and domain-user panels.
---

# Frontend development

The frontend is Filament inside the Laravel monolith. There is no second SPA or
frontend API client. Both panels compile the shared
`resources/css/filament/shared/theme.css` through Vite.

## Panels

| Path | Audience | Navigation |
| --- | --- | --- |
| `/admin` | administrators | Control plane, Customers, Edge network, Operations, Observe, Account |
| `/app` | assigned domain users | Domains, Observe, Account |

The administrator panel exposes dashboard, system DNS identity, platform
settings, users, domains, DNS clusters, edge pools, edges/cells, operations,
audit logs, telemetry, profile, and tokens.

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
stat cards, empty states, data tables, form actions, and list rows. Keep
business validation in controllers/support types and mirror bounds in Filament
forms for usability.

## Qualification

Feature tests render panel routes, scope resources, confirm shared themes and
navigation, and exercise Livewire/Filament actions without browser automation.
Feature tests do not claim to prove visual layout, keyboard navigation, mobile
viewports, focus behavior, or real browser downloads.
