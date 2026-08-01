---
title: Documentation audit
description: Trace how the CDNFoundry documentation was reconstructed from code and which legacy claims, gaps, and conflicts were found.
---

# Documentation audit

::: warning An audit date is not permanent proof
Re-run route, environment, Compose, link, build, and feature checks whenever the
implementation changes. This page records review scope; it does not replace
current automated and owner-run qualification evidence.
:::

This documentation system was reconstructed on 2026-07-26 and re-audited on
2026-08-01 against release `v0.9.1` plus the current working tree. The previous
corpus is preserved verbatim under `docs/legacy/` and in Git history. It is
excluded from current navigation, search, lint, build, and link guarantees.

## Audited implementation surfaces

The audit covered every tracked project area:

| Surface | Verified sources |
| --- | --- |
| API and authorization | `core/routes`, controllers, middleware, requests, resources, policies, API tests |
| Durable state | every Laravel migration and model |
| Async workflows | jobs, actions, operation model, Horizon configuration, scheduler |
| Administrator UI | panel provider, pages, resources, relation managers, Blade views |
| Domain-user UI | panel provider, pages, domain resource/actions, relation managers |
| DNS | record/zone support, PowerDNS client/rendering, DNSdist, PowerDNS config/schema/migration |
| Edge | edge controllers/models/jobs, Go agent and tests, Nginx/OpenResty/Lua runtime |
| TLS/cache/security | controllers, support validators, jobs, runtime, feature and E2E tests |
| Telemetry | Vector transforms/sinks, ClickHouse DDL, analytics queries, metrics and alerts |
| Configuration | Laravel configs, both example environments, Compose interpolation, scripts |
| Infrastructure | development/production Compose, every production overlay, Dockerfiles, Caddy |
| Development and CI | Make targets, shell scripts, PHP/Go/Python tests, GitHub workflow/forms |
| Existing documentation | all legacy Markdown, generated OpenAPI, root guides, pull-request template |

The audit used file inventories, route registry output, environment-key
extraction, class/function indexes, migration constraints, Compose-rendered
services, and targeted full-file review. The 2026-08-01 pass additionally
checked bounded cells, gateway ingress, Geo-Unicast and Simple Anycast pools,
cache/compression/origin failover, managed WAF, fleet rollout, Grafana, Loki,
production overlays, the environment generator, and every quick-start command.
Generated dependency lockfiles were treated as dependency evidence, not prose
to paraphrase.

The current documentation also includes a first-principles CDN learning path,
an end-to-end CDNFoundry usage guide, production reference architectures, and a
production best-practices checklist. These pages derive their product-specific
claims from the audited implementation surfaces above; generic CDN background
does not expand the implemented product boundary.

## Outdated or incorrect legacy assumptions

| Legacy claim or pattern | Verified implementation |
| --- | --- |
| Create the first administrator through `artisan tinker` and direct model creation. | `cdnf:admin:create` is the supported interactive, password-history-safe command. |
| A successful backup restore leaves maintenance mode active. | `RestoreControlBackup` runs migrations, queues reconciliation, then calls `artisan up`; only failure leaves maintenance active. |
| The cumulative `make dev-e2e` covers phases 1–5. | The Make target now includes security, analytics, operations, and runtime programs through the current phase 8 boundary. |
| A link-only checker was sufficient documentation validation. | The old checker checked target-file existence only; it did not validate anchors, duplicate headings, Markdown, required pages, route catalog drift, or the site build. |
| A client-only Mermaid container was sufficient diagram output. | The old integration server-rendered an empty box and depended entirely on successful browser hydration. The local component now server-renders the complete source fallback, replaces it with SVG when Mermaid succeeds, and retains visible diagnostics on failure. |
| `caution` was a supported VitePress callout type. | VitePress emitted those blocks as literal `:::` text. Current pages use supported callout types, and source plus built-site checks reject future container drift. |
| The raw `docs/openapi.json` was the API documentation. | The generator describes routes but has intentionally generic bodies/responses. A generated human endpoint catalog and authored conventions/feature guides are also required. |
| `core/README.md` described the project. | It was the generic Laravel skeleton README and did not document CDNFoundry. |
| Historical phase test counts were current qualification status. | Counts such as 118 or 140 tests apply only to their recorded commits; the current suite contains more tests and must be rerun. |
| Roadmap future stages, current operation, agent rules, and qualification evidence belonged in one public guide. | Governance and owner qualification are repository concerns, not public product documentation; legacy keeps the original proposal history. |
| Telemetry retention settings automatically define runtime TTLs. | Masking/finalization are active application policy, while shipped ClickHouse TTLs are static in `docker/clickhouse/init.sql` and require an operator migration to change. |
| A public address must exist on each host so Docker can bind it. | Public/NAT addresses are advertised identities. Shared production overlays bind `HOST_BIND_IPV4`/`HOST_BIND_IPV6`; the edge gateway requires a complete advertised-to-private `EDGE_GATEWAY_ADDRESS_MAP` behind NAT or a layer-4 load balancer. |
| Restic is required to render or start the control profile. | Built-in Restic backup is optional. Empty settings skip the daily job and fail backup requests explicitly while leaving serving available and backup health degraded. |
| One-shot migrations can bootstrap their own databases during first install. | The supported first-install sequence starts and health-checks PostgreSQL/Valkey or PowerDNS PostgreSQL before running its explicit migration container. |

## Duplication and conflicts removed

- Installation, development, production, scaling, architecture, and phase files
  repeated the same commands with different completeness and dates. Shared
  command, configuration, service, and limit references now own exact values.
- Cache, TLS, DNS, edge, security, and telemetry failures were split into many
  one-page runbooks. One incident-runbook page now links to focused
  symptom-based troubleshooting.
- API routes appeared in the roadmap, authentication guide, frontend coverage,
  and generated JSON. The route-derived catalog is the single endpoint list.
- Production certificate steps appeared in quick start and operations files.
  One internal-certificate guide now owns generation, distribution, and rotation.
- The root README, installation guide, and development guide used different
  first-administrator procedures. They now use `cdnf:admin:create`.
- Old relative links assumed all pages lived in one flat `docs/` directory.
  Current pages use explicit portable `.md` paths that work on GitHub and in
  VitePress; source and rendered anchors are validated.

## Areas not fully documented by executable contracts

These are implementation or operator gaps, not invitations to invent behaviour:

- OpenAPI request and success schemas remain generic; controller validation is
  authoritative. The endpoint catalog is complete, but a fully typed schema for
  every request/response is not generated.
- GitHub Pages publishing is automated, but no custom documentation domain is
  configured or verified by the repository.
- The project has no committed dedicated-pool Compose template; operators must
  repeat the bounded cell service shape with unique bindings and volumes.
- PostgreSQL/Valkey/ClickHouse clustering, load balancers, provider firewalls,
  DNSSEC, registrar APIs, off-host object storage, and upstream volumetric DDoS
  services are deployment-owner choices.
- Alertmanager's committed receiver is a local placeholder.
- Private service certificates use manual long-lived internal PKI scripts; no
  automatic private-CA renewal service exists.
- The built-in registrable-domain suffix list is deliberately small, not the
  complete Public Suffix List.
- Raw dnstap fallback events may have `domain_id=0`; no authoritative name-to-ID
  enrichment stage is committed.
- No formal API backward-compatibility/versioning policy beyond the current
  `/api` and artifact compatibility envelope is implemented.
- No fixed security-response SLA or supported-version matrix is encoded.
- Owner-run browser, public multi-vantage Geo-DNS, real IPv6, external off-host
  restore, and production RPO/RTO evidence remain environment-owned.

## Maintenance rule

When code and documentation disagree, fix the current Markdown from verified
implementation and record a product defect separately if the code violates
`AGENTS.md` or the repository product contract. Do not silently change legacy
files; their value is historical traceability.
