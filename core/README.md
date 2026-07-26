# CDNFoundry control plane

This directory contains the Laravel/Filament control plane. Project setup,
architecture, API, development, testing, and operational guidance live in the
[CDNFoundry documentation](../docs/index.md).

Use repository-level Make targets for supported development and tests. Do not
run migration-capable tests against PostgreSQL; `make dev-test` enforces the
isolated SQLite in-memory environment.
