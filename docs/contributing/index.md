---
title: Contributing
description: Contribute to CDNFoundry within its architecture, scope, safety, and qualification contracts.
---

# Contributing

Read `AGENTS.md` before changing behaviour.
The key invariants are:

- one Laravel modular monolith owns management;
- PostgreSQL owns desired state;
- DNS and HTTP traffic never pass through Laravel;
- external effects are asynchronous, revisioned, bounded, and last-valid-state preserving;
- no default per-domain process, container, timer, cache directory, or server block;
- real non-browser runtime qualification lives under `tests/e2e`.

Keep changes small and production named. Do not introduce lifecycle labels such
as phase numbers into production filenames or classes. Normal CRUD belongs in
controllers and Eloquent; add abstractions only for real external boundaries or
genuinely reusable policy.

See [Documentation contributions](/contributing/documentation) and the
[Documentation audit](/contributing/documentation-audit).
