---
title: Project layout
description: Navigate CDNFoundry source, configuration, runtime, deployment, tests, and documentation.
---

# Project layout

| Path | Purpose |
| --- | --- |
| `core/` | Laravel modular monolith and Filament panels |
| `core/app/Http/Controllers` | Account, domain, administrator, edge, health, metrics APIs |
| `core/app/Filament` | Administrator and domain-user pages/resources |
| `core/app/Jobs` | Bounded asynchronous side effects and global reconciliation |
| `core/app/Models` | Desired and operational Eloquent state |
| `core/app/Support` | External boundaries and reusable typed policy |
| `core/database/migrations` | Control PostgreSQL schema |
| `core/routes` | API, web/edge, and scheduler definitions |
| `edge-agent/` | Go enrollment, sync, activation, heartbeat, and task agent |
| `docker/openresty/` | Data-driven request/runtime Lua |
| `docker/nginx/` | Web, edge-control, OpenResty, proxy, cache, and fixtures |
| `docker/pdns`, `docker/dnsdist` | Authoritative DNS runtime |
| `docker/postgres` | PowerDNS base schema and separate runtime migrations |
| `docker/vector`, `docker/clickhouse` | Telemetry pipeline and schema |
| `docker/prometheus`, `docker/alertmanager` | Metrics and alerts |
| `compose.dev.yml` | Persistent full development topology |
| `compose.prod.yml` | Role-based production service definitions |
| `deploy/production/` | Public/split-host/IPv6/external-data overlays |
| `scripts/` | PKI, environment, and Compose validation helpers |
| `tests/e2e/` | Agent-owned non-UI real-runtime qualification |
| `tests/docs/` | Documentation validation |
| `.github/` | Issue forms and CI/publish workflow |
| `.agents/skills/` | Repository execution guides governed by `AGENTS.md` |
| `docs/` | VitePress Markdown source and machine-generated API artifact |
| `docs/legacy/` | Verbatim archived documentation, excluded from the site |

## Module rule

The control plane remains one Laravel application. Normal CRUD uses controllers,
form requests, policies, Eloquent models, resources, and Filament resources.
Jobs own asynchronous effects. Support classes exist for true external
boundaries or typed reusable policy, not as repository/manager wrappers around
ordinary CRUD.

Production names describe behaviour and must not include roadmap phase numbers,
`V2`, `New`, or `Final`.
