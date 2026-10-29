---
title: Coding agents
description: Project skills and the CodeGraph MCP server that help coding agents work on CDNFoundry with fewer tokens.
---

# Coding agents

CDNFoundry ships two aids for coding agents such as Claude Code. Both are
optional and run only on the developer's machine.

| Aid | Where | What it does |
| --- | --- | --- |
| Project skills | `.agents/skills/`, linked as `.claude/skills/` | Short, task-specific guides with the real file paths, commands, tests, and known traps for each area. The agent loads one only when a task needs it. |
| CodeGraph MCP server | `.mcp.json` | A local, pre-built code graph (symbols, callers, callees, imports) for PHP, Go, Lua, and Python. The agent queries the graph instead of searching and reading whole files. |

`AGENTS.md` remains the contract. Skills explain how to apply it in each area,
and they never override it.

## Skills

| Skill | Use it for |
| --- | --- |
| `cdnf-codebase-map` | Finding the right file and test before searching the tree |
| `cdnf-feature-module` | Starting any feature or behavior change |
| `cdnf-api-endpoint` | Routes, validation, idempotency, resources, OpenAPI |
| `cdnf-reconciliation` | Jobs that move desired state to DNS, edges, or ACME |
| `cdnf-dns-runtime` | Records, zones, Geo-DNS, PowerDNS, DNSdist |
| `cdnf-edge-runtime` | Snapshots, the edge agent and gateway, cells, cache, origins |
| `cdnf-tls-lifecycle` | Managed and uploaded certificates |
| `cdnf-telemetry-analytics` | Vector, ClickHouse, analytics and logs |
| `cdnf-filament-ui` | Admin and domain panel screens |
| `cdnf-compose-operations` | Compose, images, profiles, the fleet installer |
| `cdnf-production-qualification` | Release and fleet qualification evidence |

Edit skills in `.agents/skills/`. Each file keeps the YAML front matter
(`name`, `description`) and the section headings that
`core/tests/Feature/RepositoryContractTest.php` checks.

## Enable CodeGraph

[CodeGraph](https://github.com/colbymchenry/codegraph) is MIT licensed, runs
locally, and stores its index in SQLite under `.codegraph/`, which Git
ignores. The project configuration pins version 1.6.2 and turns off its
anonymous telemetry.

1. Install Node.js 20 or later on the developer machine.
2. Build the index once from the repository root:

   ```sh
   CODEGRAPH_TELEMETRY=0 npx -y @colbymchenry/codegraph@1.6.2 init
   ```

3. Start Claude Code in the repository and approve the `codegraph` server
   from `.mcp.json` when prompted. Check it with `/mcp`.

CodeGraph re-indexes changed files incrementally. Delete `.codegraph/` to
rebuild the index from scratch.

## Optional: Laravel Boost

[Laravel Boost](https://laravel.com/docs/boost) is Laravel's official MCP
server. It adds documentation search that matches the installed Laravel and
Filament versions, database schema inspection, and route and log tools. It is
not installed by default, because it adds a Composer development dependency.
To try it locally:

```sh
docker compose -f compose.dev.yml exec core composer require laravel/boost --dev
claude mcp add laravel-boost -- docker compose -f compose.dev.yml exec -T core php artisan boost:mcp
```

Boost's documentation search sends queries to Laravel's hosted documentation
service. Its database tools reach the development PostgreSQL, so use them
only against development data.
