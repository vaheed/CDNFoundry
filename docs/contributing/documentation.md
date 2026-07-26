---
title: Documentation contributions
description: Maintain CDNFoundry documentation as verified, linked, accessible, and buildable Markdown.
---

# Documentation contributions

Authored documentation lives in `docs/**/*.md`, excluding `docs/legacy/`.
VitePress config, theme code, and static SEO assets support the site but are not
documentation content. The OpenAPI JSON file is generated machine-readable
contract data.

## Source-of-truth order

1. executable code and runtime configuration;
2. tests that prove the behaviour;
3. `AGENTS.md` and the accepted roadmap for product invariants;
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

## Add or move a page

1. add the Markdown file in the audience-oriented section;
2. add it to `.vitepress/config.mts` navigation when it is a primary page;
3. update inbound links instead of leaving redirects undocumented;
4. update `tests/docs/validate_docs.py` if the required architecture changes;
5. run `make docs-check`.

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
