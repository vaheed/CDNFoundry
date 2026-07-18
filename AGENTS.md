# CDNFoundry implementation contract

The product contract is [docs/roadmap.md](docs/roadmap.md). Build a small, production-grade private CDN with predictable failure behavior, bounded resource use, and low operational complexity. Stop when an implementation idea conflicts with this file or the roadmap.

## Product invariants

- One Laravel modular monolith is the control plane. Filament provides both administrator and domain-user panels.
- PostgreSQL owns desired state. PowerDNS data, edge snapshots, artifacts, and aggregates are derived and rebuildable.
- DNS and HTTP traffic, security decisions, certificate selection, and raw telemetry never pass through Laravel.
- External side effects are asynchronous, revisioned, idempotent, coalesced, and preserve the previous valid state.
- Scale by adding workers, DNS capacity, ClickHouse capacity, edge nodes, and bounded OpenResty cells.
- Never create a default per-domain process, container, worker, timer, Nginx server block, cache directory, or reload.

## Scope and architecture

Implement only accepted Part One behavior or explicitly admitted Part Two work. Do not add microservices, Kafka, Kubernetes requirements, CQRS, event sourcing, GraphQL, custom RBAC, reseller or billing hierarchies, plugin runtimes, custom expression languages, multiple dashboards, or a second backend/frontend.

Use standard Laravel controllers, form requests, API resources, policies, Eloquent models, jobs, commands, events, notifications, Scheduler, and Horizon. Normal CRUD belongs in controllers and Eloquent. Do not create repository wrappers, manager/service layers for ordinary CRUD, empty abstractions, or interface/implementation pairs without a genuine second implementation or external boundary.

Synchronous external effects and synchronous deployment work are forbidden.

Use descriptive production names. Never put roadmap phase numbers, `V2`, `New`, `Final`, or similar lifecycle labels in production code or filenames.

## API and authorization

- Administrator access is `users.type = admin`; domain users see only assigned domains.
- Browser sessions and API tokens use the same policies and policy-aware route binding.
- Lists use cursor pagination. Bulk inputs have explicit item and payload limits.
- Mutations support `Idempotency-Key`; reuse with different input returns conflict.
- External or long work returns `202 Accepted` with an operation ID.
- Errors use stable machine-readable codes. Secrets are displayed only at their allowed one-time boundary.

## Runtime changes

For PowerDNS, edge, TLS, cache, security, and purge work: authorize; validate typed input; transactionally write desired state and increment its revision; commit; dispatch one unique job; skip obsolete work; render deterministically; checksum and validate; activate atomically; verify and acknowledge; record success/failure; retain previous valid state. HTTP requests never wait for runtime mutation. Global work is bounded and chunked, and bulk work cannot starve interactive/runtime lanes.

DNSdist is the only public authoritative DNS endpoint. PowerDNS stays private. Validate zone boundaries, Punycode, types, values, TTLs, CNAME rules, duplicates, A/AAAA parity, and monotonic serials. Never remove an active zone before validating its replacement. Runtime DNS never calls Laravel or an external GeoIP API. PowerAdmin is diagnostic and its edits are drift.

One proxied hostname has one explicitly validated origin. Reject loopback, link-local, metadata, multicast, internal platform, edge-service, and proxy-loop destinations and revalidate resolution before connection. Normalize forwarding and hop-by-hop headers. Bound connections, retries, timeouts, headers, bodies, cache admission, and temporary storage.

All OpenResty cells run one data-driven runtime and receive only assigned domains/certificates. Stable placement supports shared, quarantine, and exceptional dedicated cells. Activate a target before draining a source. Invalid bundles never replace active bundles. Protection remains bounded per client/domain/cell and does not claim volumetric scrubbing when the uplink is saturated.

Use DNS-01 only when a hostname first becomes proxied; do not issue for DNS-only domains or create fake records. Validate uploaded keys, chains, names, and expiry; encrypt private keys; spread renewal; preserve valid certificates.

Cache lookup and URL purge share one deterministic key. Full purge increments an epoch rather than scanning disk. Telemetry goes from Vector directly to ClickHouse with bounded buffers, retention, queries, and redaction; failure must never stop serving.

## Operations, data, and secrets

Keep internal services private. Compose files define networks, volumes, health checks, restart behavior, limits, and explicit migrations. Never commit or print production secrets, customer data, tokens, certificate keys, encryption keys, or signing keys. Recovery requires application encryption/signing keys and externally stored TLS material as well as PostgreSQL.

Use production-safe migrations and expand/contract for mixed-version fields. Add database constraints that protect correctness. JSONB accepts only validated typed structures. PowerDNS runtime migrations remain separate.

## Tests and change discipline

Before coding, identify the roadmap requirement, durable state, external effects, rollback, bounds, permissions, and real tests. Cover happy path, permissions, validation, idempotency, retry/failure, rollback/last-valid-state, IPv4/IPv6, and real runtime behavior where applicable. Do not replace runtime qualification with mocks.

Browser E2E qualification is manual and user-owned. Coding agents must not launch Chromium, Playwright, Selenium, Cypress, or any other browser automation, even when a browser script exists in the repository. Agents may maintain the manual browser job when UI behavior changes, but must report it as not run and hand the job to the user.

Non-UI end-to-end and real-runtime qualification is agent-owned and uses Python under `tests/e2e`. It may exercise HTTP APIs, queues, databases, Compose services, DNS, TLS, restarts, and runtime state, but it must not inspect or automate rendered UI.

Before completion run relevant non-browser tests, formatting/static checks, Compose validation, artifact validation, and documentation updates. Report files changed, behavior, tests actually executed, migrations/operations, the manual browser job status, and limitations. Never report an unexecuted test as passed.

Work roadmap phases in order. A phase is complete only after its implementation is present, its operator/user documentation is current, agent-owned automated and real-runtime tests pass, and its owner-run manual browser checklist is written with exact step-by-step fields and expected results. Keep `docs/manual-browser-qualification.md` limited to implemented/current phases; do not invent future screens. End every documented phase with a completion gate that separately records implementation, documentation, automated/runtime qualification, and manual browser status. Any unavailable UI is a failed current-phase checkpoint, not a future-phase placeholder.

Development PostgreSQL and named Compose volumes persist across every roadmap phase. Never remove volumes, run destructive database refreshes, or run `RefreshDatabase` tests against PostgreSQL. Laravel tests must fail closed unless `APP_ENV=testing`, `DB_CONNECTION=sqlite`, and `DB_DATABASE=:memory:` are effective. Use the supported isolated test command and verify its effective database before any test capable of migrations or truncation. Preserve and extend `docs/manual-browser-qualification.md` through the final roadmap phase whenever browser menus, fields, credentials, ports, runtime diagnostics, or operator workflows change.
