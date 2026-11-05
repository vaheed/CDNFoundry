---
title: Contributing
description: "Where to start before changing CDNFoundry: the product contract in AGENTS.md, the key invariants, and the rules for code and documentation changes."
---

# Contributing

::: info Start with the product contract
Read `AGENTS.md` in the repository root before you change anything. A change that
looks clean is still invalid when it breaks bounded resources, asynchronous effects,
last-valid rollback, authorization, or the one-monolith architecture.
:::

The key invariants are:

- one Laravel modular monolith owns management;
- PostgreSQL owns desired state;
- DNS and HTTP traffic never pass through Laravel;
- external effects are asynchronous, revisioned, bounded, and last-valid-state preserving;
- no default per-domain process, container, timer, cache directory, or server block;
- real non-browser runtime qualification lives under `tests/e2e`.

Keep changes small and use production names. Do not put lifecycle labels such as
phase numbers into production filenames or classes. Normal CRUD belongs in
controllers and Eloquent. Add an abstraction only for a real external boundary or
a reusable policy.

For setup, start with [Developer setup](../development/index.md). For a map of
the repository, see [Project layout](../development/project-layout.md).

To change the documentation, see [Documentation contributions](documentation.md).
