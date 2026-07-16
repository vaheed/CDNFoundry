# Coding-agent skills

The project-local execution guides live in `.agents/skills`. `AGENTS.md` and the roadmap have higher authority. Each guide was dry-run against one bounded Phase 1 scenario without creating speculative production code:

| Skill | Representative dry run | Result |
|---|---|---|
| `cdnf-feature-module` | Access-model extension | Selected only model, controller, UI, tests, and docs |
| `cdnf-api-endpoint` | Profile update | Classified as PostgreSQL-only, audited and idempotent |
| `cdnf-filament-ui` | User management | Shared access model, typed form, safe destructive actions, and policy boundary tests |
| `cdnf-reconciliation` | DNS identity apply | Operation + unique runtime-lane job; no request-time external call |
| `cdnf-dns-runtime` | Future A/AAAA record | Required canonical validation, one revision, PowerDNS privacy, real `dig` |
| `cdnf-edge-runtime` | Future origin routing | Rejected per-domain server blocks and required cell/resource bounds |
| `cdnf-tls-lifecycle` | Future managed certificate | Required proxied-host prerequisite, DNS-01, encryption and continuity |
| `cdnf-telemetry-analytics` | Future request analytics | Required Vector-to-ClickHouse direct path and bounded queries |
| `cdnf-compose-operations` | Phase 1 stack | Required explicit migration, private networks, health and restart checks |
| `cdnf-production-qualification` | Phase 1 acceptance | Kept real runtime checks separate from mocked feature tests and handed browser qualification to the user |

`RepositoryContractTest` verifies that every required skill exists and contains every Appendix B section. A dry run validates workflow structure; it does not mark future runtime acceptance tests as passed.
