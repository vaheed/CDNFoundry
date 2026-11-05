---
title: Documentation contributions
description: Maintain CDNFoundry documentation as verified, linked, accessible, and buildable Markdown.
---

# Documentation contributions

::: warning Document observed behavior
Verify fields, routes, limits, services, and commands against the current code
and rendered Compose configuration. Do not promote roadmap proposals into current
instructions before implementation qualifies.
:::

Authored documentation lives in `docs/**/*.md`.
VitePress config, theme code, and static SEO assets support the site but are not
documentation content. The OpenAPI JSON file is generated machine-readable
contract data.

The package overrides keep VitePress on its stable `1.6.4` site contract while
pinning audited transitive Vite and YAML parser patches. Recheck the overrides
when upgrading VitePress; do not remove them without a clean `npm audit`.

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

Use the documentation components instead of plain Markdown where they fit.

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

1. executable code and runtime configuration;
2. tests that prove the behaviour;
3. `AGENTS.md` and repository governance for product invariants;
4. current documentation.

## Page requirements

Each page must have front matter with a unique `title` and useful
`description`, one logical H1, meaningful headings, relative internal links,
language-tagged code fences, and no raw secret values. Prefer links to a shared
reference over repeating limits or procedures.

Use images only when they add information. Give every Markdown image meaningful
alt text; use empty alt text only for a truly decorative image. The current site
uses no prose images.

Use Mermaid when a state machine, request path, trust boundary, ownership
relationship, or multi-step activation is easier to understand visually.
Include the same meaning in nearby prose or a table so the page remains useful
to assistive technology and plain Markdown readers.

Use VitePress callouts deliberately:

- `::: info` for architectural boundaries;
- `::: tip` for safe shortcuts and verification;
- `::: warning` before risky or commonly misunderstood work;
- `::: danger` where data loss, secret exposure, or traffic outage is possible.

## Add or move a page

1. add the Markdown file in the audience-oriented section;
2. add it to `.vitepress/config.mts` navigation when it is a primary page;
3. update inbound links instead of leaving redirects undocumented;
4. update `tests/docs/check_links.py` if the required architecture changes;
5. run `make docs-check`.

The source validator rejects missing files and anchors, root-relative links,
and extensionless Markdown links that would fail on GitHub. It also extracts
Make targets, script/Compose command paths, and environment variables from the
implementation. After VitePress builds, a second validator crawls every
generated internal link and anchor. A documented command that no longer exists,
a newly introduced configuration key without reference coverage, or an
unresolvable source or rendered link fails CI.

## Generated API files

After changing Laravel API routes:

```sh
docker compose -f compose.dev.yml run --rm core php artisan cdnf:api:openapi
```

Commit both `docs/public/openapi.json` and
`docs/reference/api/endpoints.md`. CI runs the command with `--check`.

## SEO deployment

VitePress generates clean URLs, local search, metadata chunks, and a sitemap.
`transformHead` emits canonical and social metadata from each page. Set
`DOCS_SITE_URL` and `DOCS_BASE` to the production location; update
`docs/public/robots.txt` if the canonical origin changes.

GitHub Pages project sites are published below a path such as `/CDNFoundry/`.
Search crawlers only recognize `robots.txt` at the host root, so the project
file cannot control `vaheed.github.io` or advertise the sitemap by itself.
Submit the generated `sitemap.xml` through the matching Google Search Console
URL-prefix property. A custom domain can serve an effective root-level
`robots.txt`.

The single `CI` GitHub Actions workflow checks out full Git history so VitePress
can calculate accurate per-page modification dates, validates the source and
rendered output in its documentation stage, uploads `docs/.vitepress/dist`, and
deploys that artifact to GitHub Pages after every validation stage passes on
`main`.
Production uses:

```text
DOCS_SITE_URL=https://vaheed.github.io/CDNFoundry
DOCS_BASE=/CDNFoundry/
```

The repository's **Settings → Pages → Build and deployment → Source** must be
set to **GitHub Actions**. A push to `main` that changes documentation,
documentation validation, or the website workflow then publishes
<https://vaheed.github.io/CDNFoundry/>. The deployment workflow uses the
protected `github-pages` environment and does not need a repository secret.

The default VitePress theme provides keyboard-accessible local search,
responsive navigation, and a light/dark appearance switch. The local diagram
component server-renders the complete diagram source as a fallback, then uses
Mermaid to replace it with an SVG and rerenders when the color theme changes.
Rendered diagrams use a compact page-height limit by default; readers can open
the full-size view with **View larger** and close it with the button or Escape.
If client rendering fails, readers see a diagnostic fallback instead of an
empty box. The rendered-site validator requires navigation, search, appearance
control, a fallback for every diagram, rendered custom callouts, and the Google
Search Console verification meta tag before an artifact can deploy.

Pages with specific search intent should express it naturally in the visible
title, heading, introduction, and useful body content. The optional `keywords`
frontmatter provides general metadata but is not a substitute for visible
content and does not influence Google Search ranking. The generated head also
contains `TechArticle` or `WebSite` and `BreadcrumbList` JSON-LD. Titles and
descriptions must describe verified implementation rather than promise
unsupported scale or security.
