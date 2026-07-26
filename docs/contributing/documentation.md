---
title: Documentation contributions
description: Maintain CDNFoundry documentation as verified, linked, accessible, and buildable Markdown.
---

# Documentation contributions

Authored documentation lives in `docs/**/*.md`, excluding `docs/legacy/`.
VitePress config, theme code, and static SEO assets support the site but are not
documentation content. The OpenAPI JSON file is generated machine-readable
contract data.

The package overrides keep VitePress on its stable `1.6.4` site contract while
pinning audited transitive Vite and YAML parser patches. Recheck the overrides
when upgrading VitePress; do not remove them without a clean `npm audit`.

## Source-of-truth order

1. executable code and runtime configuration;
2. tests that prove the behaviour;
3. `AGENTS.md` and repository governance for product invariants;
4. current documentation;
5. legacy documents as historical evidence only.

Never copy a legacy claim without verifying it in the current tree.

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
- `::: warning` or `::: caution` before risky or commonly misunderstood work;
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

Do not edit `docs/legacy/` to repair old links. It is a verbatim archive and is
excluded from build, search, lint, and current-link guarantees.

## Generated API files

After changing Laravel API routes:

```sh
docker compose -f compose.dev.yml run --rm core php artisan api:openapi
```

Commit both `docs/public/openapi.json` and
`docs/reference/api/endpoints.md`. CI runs the command with `--check`.

## SEO deployment

VitePress generates clean URLs, local search, metadata chunks, and a sitemap.
`transformHead` emits canonical and social metadata from each page. Set
`DOCS_SITE_URL` and `DOCS_BASE` to the production location; update
`docs/public/robots.txt` if the canonical origin changes.

Pages with specific search intent should set a concise `keywords` frontmatter
list. The generated head also contains `TechArticle` or `WebSite` and
`BreadcrumbList` JSON-LD. Titles and descriptions must describe verified
implementation rather than promise unsupported scale or security.
