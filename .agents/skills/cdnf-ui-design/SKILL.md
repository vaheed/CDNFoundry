---
name: cdnf-ui-design
description: Apply the CDNFoundry design system to any admin, domain-user, or fleet web screen - tokens, type, shell, list and detail patterns, sheets, settings, secrets, empty states, live views, copy rules, and the browser, visual, and accessibility checks. Use with cdnf-filament-ui for every UI change.
---

# cdnf-ui-design

## Purpose
Make every CDNFoundry screen look and behave like one professional operator tool: quiet, dense, consistent, and fast to scan, in light and dark mode. The reference is the Bifrost gateway UI (neutral greys, one accent, monospace values, side sheets). `cdnf-filament-ui` decides what a screen may show and do (scoping, policies, operations). This skill decides how it looks and behaves.

## When to use
Any new or changed screen in the admin panel (`/admin`), the domain-user panel (`/app`), or the local fleet web. Also any change to the shared theme, the topology view, or the screenshot scenes used by the documentation.

## Required inputs
- The screen's job in one sentence ("see which PoPs are unhealthy and drain one").
- The pattern it uses (shell, list, detail sheet, detail page, settings, overview, graph) from `docs/development/frontend.md`.
- The values it shows, and which are numbers, IDs, timestamps, addresses, hostnames, codes, or statuses.
- The actions it offers, which ones start an operation, and which ones show a secret once.
- The empty, loading, degraded, and failed states.

## Files normally touched
- `core/resources/css/filament/shared/theme.css`: design tokens (colors, status colors, fonts, spacing, radius, light and dark mode). Add tokens here, never inline values in a screen.
- `core/app/Providers/Filament/AdminPanelProvider.php`, `DomainPanelProvider.php`: theme, fonts, navigation groups, global search, SPA mode.
- `core/app/Filament/**` (pages, resources, widgets) and `core/resources/views/filament/**` (custom views such as the topology view).
- `docs/development/frontend.md`: the written patterns. Update it when a pattern changes.
- `tests/browser/**`: Playwright specs, visual snapshots, and accessibility checks.

## Procedure
1. **Pick the pattern before writing code**, from `docs/development/frontend.md`. If none fits, stop and propose one. Never build a one-off layout.
2. **Tokens only.** Colors, spacing, radius, and shadows come from the theme tokens: zinc neutrals, one accent, status colors, 8 px base radius, borders rather than shadows. No hex values, no Filament default colors, no inline styles.
3. **Type.** Geist Sans (or the theme's UI font) for text. Geist Mono (or the theme's mono font) for every number, ID, timestamp, IP address, hostname, URL, revision, checksum, DNS record value, HTTP status, and event or code name. Numbers right-aligned in tables.
4. **Shell.** Light grey app background, sidebar on that background, and the page in one white bordered panel (dark mode: the same structure in dark tokens). Sidebar: ⌘K / Ctrl+K search at the top, icon plus label per item, collapsible groups with a chevron, an arrow icon on external links, small "Beta" tags where needed, and links and the version (monospace) in the footer.
5. **List page.** Title, one short description line, and the primary action at the top right. A filter bar with a search box and inline select filters (use a Filters button only when there are more than three filters). A table with a muted header, compact rows, row hover, status badges, inline toggles for enable and disable, and a "…" menu for row actions. Footer: "1–20 of 143 entries" on the left, paging on the right. A KPI strip (at most five values) only when the page is about metrics, such as analytics, logs, or the overview.
6. **Detail sheet (default).** Clicking a row opens a right-side sheet (`->slideOver()`), about 42 rem wide, widening for large tables. Header: the name, a status badge, and the main identifier (for example the origin URL) on a second line in muted text. Then a bordered grid of fields, three columns, with a small muted label above each value. Then sections such as history, each with a title, an action on the right, and its own table (expandable rows for nested detail).
7. **Detail page with tabs (only for central entities):** domains, edges, pools, and DNS clusters. Breadcrumb header with the status badge and actions, tabs with counts ("Records 24"), a KPI strip, and a "Needs attention" box that lists only problems, each with a link to its fix. Hide the box when nothing needs attention.
8. **Create and edit** open in a right-side sheet with a title, one description line, and fields with labels above. Complex values (lists of headers, origins, rules) use small inline tables with add and remove buttons. A form longer than one sheet becomes a page.
9. **Settings.** Sections with a heading and one description line. One row per setting: label and one-line description on the left, the control on the right. Background or warnings go in a collapsible "About …" box, never in paragraphs. "Save changes" at the bottom right, disabled until something changed. Show the default value and a reset action.
10. **Secrets shown once** (API tokens, bootstrap tokens, signing secrets): a centered dialog that explains in one or two lines that the value is shown only once, the value in monospace with a copy button, a "Read more" link, and a confirm button ("I've stored the token"). The value is never shown again.
11. **Badges.** Status badges use the shared status set (healthy, degraded, failed, pending, disabled) with the same colors everywhere: solid accent for an active state, muted grey for disabled. Codes and event names use soft tinted badges in monospace. Never color alone: every badge has a word.
12. **Long work.** An action that starts an operation returns at once and shows the operation in the bell panel for background work, with live progress. Never block the screen while the work runs.
13. **Live views.** Logs, operations, and edge status poll with a visible "live" indicator ("Listening for logs…"), at 5 seconds or more, and stop while the browser tab is hidden.
14. **Time.** Relative times in lists ("9 minutes ago"), with the exact time on hover and in nested rows.
15. **Empty states.** A large thin-line icon, a short title, one description line, and two buttons: "Read more" (to the documentation page) and the primary create action.
16. **Copy.** Short labels, sentence case, at most one description line per page or section. Explanations belong in the documentation, linked from the page or the empty state. Error messages say what failed and what to do next.
17. **Accessibility.** Every action works from the keyboard, focus is visible, contrast meets WCAG AA in both themes, and icon-only buttons have a label.
18. **Test ids.** Every interactive element has a stable `data-testid` named `<entity>-<element>-<qualifier>`, for example `domain-create-button` or `edge-row-menu`. Never rename one without updating `tests/browser`.
19. **Test it.** Add or update the Playwright spec for the screen, its visual snapshots in light and dark mode at desktop and phone width, and its accessibility check. If the screen appears in the documentation, update its screenshot scene.

## Validation commands
- Backend and style: `make dev-test`, and `vendor/bin/pint --test` through the test Compose override (see `cdnf-filament-ui`).
- Assets: `make dev-assets`.
- Browser: the Playwright suite under `tests/browser`, including visual snapshots and the axe accessibility checks, run only against a development, test, or staging stack with seeded data.
- The theme check that fails on any screen using Filament's default theme or colors.

## Definition of done
The screen uses one documented pattern, only theme tokens, monospace for every value, the shared badges, sheets for details, create, and edit (or a tabbed page for a central entity), and the copy rules. Every interactive element has a test id. Its browser test, visual snapshots (both themes, both widths), and accessibility check pass. `docs/development/frontend.md` is updated if a pattern changed.

## Stop conditions
- The screen needs a pattern that is not documented: propose the pattern first.
- The design needs a second frontend, a JavaScript build outside Filament's asset pipeline, or a new UI library: stop and ask the owner.
- The screen would show data the API cannot express or the user's policy does not allow: follow `cdnf-filament-ui` and stop.
- A browser run would need production, real customer data, or real credentials: stop.

## Forbidden shortcuts
- Hard-coded colors, spacing, or fonts in a screen.
- Copying Filament default styles "for now".
- Paragraphs of explanation on a screen instead of a documentation link or a collapsible "About" box.
- A full page where a sheet fits, or a KPI strip on a page that has no metrics.
- Showing a secret again after its one-time dialog.
- Updating a visual snapshot just to make it pass, without saying what changed and why.
- Running the browser suite against production, or reporting a browser test as passed without running it.

## Expected completion summary
Screens changed, the pattern each one uses, new or changed tokens, test ids added, browser tests, visual snapshots, and accessibility checks run (with results), snapshot changes and why, and any pattern update in `docs/development/frontend.md`.