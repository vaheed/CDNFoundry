---
title: Documentation contributions
description: Writing rules, review checklist, components, and validation commands for CDNFoundry documentation contributors.
---

# Documentation contributions

::: warning Document observed behavior
Verify fields, routes, limits, services, and commands against the current code
and rendered Compose configuration. Do not promote roadmap proposals into current
instructions before implementation qualifies.
:::

Authored documentation lives in `docs/**/*.md`. VitePress config, theme code, and static SEO assets support the site but are not documentation content. The OpenAPI JSON file is generated machine-readable contract data.

The overrides in `docs/package.json` keep VitePress on version `1.6.4` and pin patched versions of its transitive dependencies (Vite, markdown-it, and others). Recheck the overrides when you upgrade VitePress. Do not remove them without a clean `npm audit`.

## Writing rules

These rules apply to every page. A reviewer checks a page against this list
before it merges, and records the result in the pull request.

### Shape of a page

1. **Task first.** A task page has four parts, in this order: what you will do
   and what you need, the steps, how to check that it worked, and what to do
   if it did not.
2. **One page per task or concept.** If a page answers two questions, split it.
3. **Command or screen first.** Show the command or the screen, then explain it
   in a sentence or two.
4. **Every step has an expected result.** After a command, say what the reader
   sees when it worked.
5. **Show each way once, in tabs.** When a task can be done in the admin panel,
   through the API, and with Fleet, show all of them in the same tabs:
   **Admin panel**, **API**, **Fleet CLI** (or **Fleet web**). Do not write
   separate pages per method.

### Language

1. Short sentences. One idea in each.
2. Plain English that a reader who does not speak English natively can follow.
   Prefer "use" to "utilise" and "start" to "initiate".
3. Name the actor. Write "Fleet copies the file", not "the file is copied".
4. Define a CDN or DNS term the first time a page uses it, or link to the
   [glossary](../reference/glossary.md).
5. No marketing words. Every claim names a feature that exists and passed
   qualification.
6. No em dashes. Use a comma, a colon, or a new sentence.

### Where contract language lives

Product rules from `AGENTS.md` belong in the contributor pages. A user page
tells the reader what to do and what happens. It does not copy the rule list
that explains why the product behaves that way. Link to
[the architecture pages](../architecture/index.md) for the reasons.

### Review checklist

Copy this list into the pull request for each page you add or change.

- [ ] The page has one task or one concept.
- [ ] The first screen says what the reader will do and what they need.
- [ ] The command or screen comes before its explanation.
- [ ] Every step has an expected result.
- [ ] A way to check success and a way to recover are present.
- [ ] Tabs show every supported way (Admin panel, API, Fleet).
- [ ] No rule list is copied from `AGENTS.md`.
- [ ] Terms are defined or linked.
- [ ] `make docs-check` passes.

### Components

Use the documentation components instead of plain Markdown where they fit. The allowed HTML tags are `Card`, `CardGrid`, `DocTabs`, `Screenshot`, `template`, and `div`.

- Cards: `<CardGrid>` with `<Card title="..." link="...">` for paths into the
  site.
- Steps: wrap an ordered list in `<div class="steps">` for a numbered
  procedure.
- Tabs: `<DocTabs :labels="['Admin panel', 'API', 'Fleet CLI']">` with one
  `<template #tab-1>` per label.
- Callouts: `::: info`, `::: tip`, `::: warning`, `::: danger`.
- Screenshots: `<Screenshot name="admin-edges" alt="..." />`. The browser suite
  (`tests/browser/specs/docs-screenshots.spec.ts`) generates the files for both
  themes from the seeded demo stack at every release. Never add or edit one by
  hand; `scripts/ci/check_no_screenshots.py` fails the build on any other image.
- Diagrams: a `mermaid` fence.
- Executable blocks: open a shell block with ```` ```sh test ```` and CI runs it
  from the repository root with `bash -euo pipefail`. The block must pass
  without a running stack, secrets, or the network.

## Source-of-truth order

When sources disagree, trust them in this order:

1. executable code and runtime configuration;
2. tests that prove the behavior;
3. `AGENTS.md` and repository governance for product invariants;
4. current documentation.

## Page requirements

Each page must have:

- front matter with a unique `title` and a useful `description`;
- one H1 and meaningful headings;
- relative internal links;
- language-tagged code fences;
- no raw secret values.

Link to a shared reference instead of repeating limits or procedures.

Screenshots are the only images (see [Components](#components)). Prose images are not used.

Use Mermaid when a state machine, request path, trust boundary, ownership relationship, or multi-step activation is easier to understand visually. Repeat the same meaning in nearby prose or a table, so the page still works for assistive technology and plain Markdown readers.

Use VitePress callouts deliberately:

- `::: info` for architectural boundaries;
- `::: tip` for safe shortcuts and verification;
- `::: warning` before risky or commonly misunderstood work;
- `::: danger` where data loss, secret exposure, or traffic outage is possible.

## Add or move a page

<div class="steps">

1. Add the Markdown file in the section for its audience.
2. Add it to the navigation in `.vitepress/config.mts` when it is a primary page.
3. Update inbound links instead of leaving redirects.
4. Update `tests/docs/check_links.py` if the required architecture changes.
5. Run `make docs-check`.

</div>

`make docs-check` runs these checks in order: `tests/docs/check_tokens.py` (docs design tokens match the product theme), the Fleet CLI reference check, `tests/docs/check_links.py`, `tests/docs/run_test_blocks.py` (the `sh test` blocks), the markdown lint, the VitePress build, and `tests/docs/check_built_site.py`.

The source validator rejects missing files and anchors, root-relative links, and extensionless Markdown links that would fail on GitHub. It also extracts Make targets, script and Compose command paths, and environment variables from the implementation. After VitePress builds, a second validator crawls every generated internal link and anchor. CI fails on a documented command that no longer exists, a new configuration key without reference coverage, or a source or rendered link that does not resolve.

## Generated API files

After changing Laravel API routes:

```sh
docker compose -f compose.dev.yml run --rm core php artisan cdnf:api:openapi
```

Expected result: the command rewrites `docs/public/openapi.json` and `docs/reference/api/endpoints.md`. Commit both. CI runs `make openapi-check`, which runs the command with `--check` and fails when the files are stale.

## SEO deployment

VitePress generates clean URLs, local search, metadata chunks, and a sitemap. `transformHead` emits canonical and social metadata for each page. Set `DOCS_SITE_URL` and `DOCS_BASE` to the production location. Update `docs/public/robots.txt` if the canonical origin changes.

GitHub Pages project sites are published below a path such as `/CDNFoundry/`. Search crawlers read `robots.txt` only at the host root, so the project file cannot control `vaheed.github.io` or advertise the sitemap by itself. Submit the generated `sitemap.xml` through the matching Google Search Console URL-prefix property. A custom domain can serve a root-level `robots.txt`.

The `CI` GitHub Actions workflow does the documentation work. Its documentation job checks out the full Git history so VitePress can calculate each page's modification date. It validates the source and the rendered output and uploads `docs/.vitepress/dist`. The deploy job publishes that artifact to GitHub Pages on a push to `main`, after the core, compose, backend-e2e, scale-e2e, go, and docs jobs pass. The CI build uses:

```text
DOCS_SITE_URL=https://vaheed.github.io/CDNFoundry
DOCS_BASE=/CDNFoundry/
```

In the repository, set **Settings → Pages → Build and deployment → Source** to **GitHub Actions**. A push to `main` then publishes <https://vaheed.github.io/CDNFoundry/>. The deploy job uses the protected `github-pages` environment and needs no repository secret.

The default VitePress theme provides keyboard-accessible local search, responsive navigation, and a light and dark appearance switch. The local diagram component renders the full diagram source on the server as a fallback. Mermaid then replaces it with an SVG in the browser and renders again when the color theme changes. Diagrams have a compact height limit by default. Readers open the full-size view with **View larger** and close it with the button or Escape. If client rendering fails, readers see a diagnostic fallback instead of an empty box.

Before an artifact can deploy, the rendered-site validator requires navigation, search, the appearance control, a fallback for every diagram, rendered custom callouts, and the Google Search Console verification meta tag.

For pages with a specific search intent, say that intent in the visible title, heading, introduction, and body. The optional `keywords` front matter gives general metadata. It does not replace visible content and does not influence Google Search ranking. The generated head also contains `TechArticle` or `WebSite` and `BreadcrumbList` JSON-LD. Titles and descriptions must describe verified behavior and must not promise unsupported scale or security.
