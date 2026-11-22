---
title: Documentation audit, 2026-11-22
description: Source-backed corrections to onboarding, request rules, DNS and HTTP architecture, deployment guidance, screenshots, and documentation validation.
---

# Documentation audit, 2026-11-22

The documentation audit started on `dev` at `daee2d58`. The scope is documentation, its
generators, capture fixtures, and validation. No serving behavior, dependencies,
database schema, deployment infrastructure, or production data changed. The
active roadmap is preserved byte for byte relative to the audit's starting
commit. Historical findings and release records keep their original meaning.

## Review method

Every maintained Markdown page was included in the inventory below, source
metadata and link checks, command/path checks, and searches for stale concepts.
Manual source checks focused on the behavior and trust boundaries in the source
matrix. This is a documentation consistency audit, not a line-by-line security
review or a new production qualification. A retained page means no discrepancy
was found within this scope, not that every runtime claim was independently
reproduced.

Current task guides are distinct from dated evidence and planned work. Existing
architecture, feature, operation, and reference pages remain canonical for their
purpose. One practical request-rule guide was added; the technical reference
retains the full field, preset, and API definitions.

## Corrected gaps

| Area | Problem | Result |
| --- | --- | --- |
| Landing page | Release-warning introduction, broad API parity claim, no request-rule overview or product image | Product-first README, scoped capabilities, real screenshot, simple architecture, role-based links, and installation requirements |
| Request rules | Technical reference without a first-use procedure | A panel/API guide with a temporary redirect, deployment checks, presets, ordering, recovery, and cache interactions |
| HTTP architecture | Gateway and request-rule stages omitted; cache hits implied to be automatic | Gateway trust boundary, early exits, rule stages, cache eligibility, and original-URL cache/purge behavior |
| DNS architecture | Feature guide described direct multi-cluster activation and omitted secondary stores | One enabled hidden primary, signed secondary transfers, primary receipts, and separate node convergence |
| Security | Diagram used the wrong stage order; a migration note implied a 1.x upgrade path | Source-order diagram and supported-upgrade wording consistent with the upgrade guide |
| Data model | Request rules absent | Domain relationship, durable table, constraints, bounds, and signed snapshot compilation |
| TLS troubleshooting | Bootstrap certificate described as a customer fallback | Failed-handshake behavior and checks for a wrong listener or older deployment |
| Cache troubleshooting | Development-mode check inverted; purge described as emptying storage | Correct bypass check, cache-rule interaction, and epoch invalidation rather than disk deletion |
| Fleet | Manual rendering limitations stated as limits of all Fleet workflows | Guided registration, enrollment, host preparation, and key provisioning distinguished from manual bundle work |
| Sizing and upgrades | Measurements said not to exist; adding arbitrary cells suggested; failed steps said never to stop traffic | Scoped evidence links, shipped cell layout, host overhead, and explicit host/network/certificate failure limits |
| API documentation | Download linked to `main` while this work targets `dev`; input schema coverage overstated | Generator-produced development link, release-matching guidance, and explicit schema limits |
| Telemetry | Request-rule trace absent; WAF score described only as the threshold | Bounded raw-event trace and scored-versus-inline behavior; standard log API field omission documented |
| Navigation | Request rules missing from learning paths; platform rulesets absent from the admin tour | Guide, sidebar, feature index, tours, glossary, learning paths, and operator evidence links |
| Screenshots | Two unused captures, missing domain feature views and backup history, misleading analytics alternative text | Contextual placement, generated feature captures, accurate availability caption, and paired-theme/unused-asset validation |
| Documentation accessibility | Dark Mermaid edge labels failed the browser contrast check | Existing readable foreground/background tokens applied to edge labels; four-theme/viewport browser coverage added |
| External references | Four broken targets in the first 48-target check | Current-source relative links and immutable historical references; final 46-target check passed |

## Source and capability checks

| Concern | Source checked | Current documentation |
| --- | --- | --- |
| Domains and access | `DomainController`, `DomainPolicy`, domain lifecycle and claim tests, panel resources | [Domains](../guides/domains.md), [access](../guides/users-and-access.md), [first domain](../getting-started/first-domain.md) |
| DNS and Geo-DNS | `ReconcileDnsZone`, `PowerDnsClient`, DNS cluster validation, `docker/pdns`, DNS node feed, `GeoDnsCompiler` | [DNS](../guides/dns.md), [DNS nodes](../operations/dns-nodes.md), [Geo-DNS](../guides/geo-dns.md) |
| Edge activation | `ReconcileEdgeDomain`, `edge-agent/generation.go`, `edge-gateway/main.go`, runtime activation and certificate selection | [Data flows](../architecture/data-flows.md), [runtime generations](../operations/runtime-generations.md) |
| Request and firewall rules | `RequestRuleData`, `RequestRulePresets`, `DomainRequestRules`, `RequestRuleController`, `PlatformRulesets`, `runtime.lua` access stages | [Request-rule guide](../guides/request-rules.md), [reference](../reference/request-rules.md), [firewall rules](../guides/security.md) |
| Durable rule state | `RequestRule`, `Domain::requestRules`, request-rule migrations and rollback compiler | [Data model](../architecture/data-model.md) |
| TLS and origins | Certificate selection, certificate jobs, typed origin policy, failover guide and runtime boundary | [TLS](../guides/tls.md), [origins](../guides/proxy-and-origins.md), [troubleshooting](../troubleshooting/tls-and-cache.md) |
| Cache and purge | `CacheController`, `CachePolicy`, cache epoch, runtime key and rule selection | [Cache](../guides/cache.md), [request rules](../reference/request-rules.md#cache-key-and-purge) |
| Telemetry | Vector redaction and request-rule validation, ClickHouse schema/migration, `AnalyticsStore::logs`, WAF outcome recording | [Schema](../reference/telemetry-schema.md), [analytics](../guides/analytics.md) |
| Infrastructure and recovery | Fleet bootstrap, DNS registration/identity, enrollment, backup receipts, prerequisite recipes, Compose definitions | [Quick start](../deployment/production-quick-start.md), [Fleet](../operations/fleet-engine.md), [backups](../operations/backup-and-recovery.md) |
| API and operations | `core/routes/api.php`, controller authorization/cursors, operation binding, OpenAPI generator | [API conventions](../reference/api/index.md), [catalog](../reference/api/endpoints.md) |
| User interfaces | Panel navigation/resources, existing browser specs, generated captures, Fleet fixture | [Admin tour](../guides/admin-panel.md), [domain tour](../guides/domain-panel.md), [Fleet web](../operations/fleet-web.md) |

Implemented capabilities remain subject to their documented bounds. This audit
does not promote roadmap proposals such as DNSSEC signing or HTTP/3 into product
features. The existing [performance audit](../operations/performance-audit-2026-11-21.md),
[request-rule evidence](../operations/request-rules-qualification-2026-11-21.md),
and [Fleet host evidence](../operations/fleet-bootstrap-qualification.md) retain
their workload, environment, and missing-qualification limits.

## Screenshot coverage

The maintained image set has 19 names in both themes. All images were generated
by the repository's browser fixtures. Existing images were decoded and visually
reviewed, and new domain Overview, DNS records, Firewall rules, Request Rules,
and Fleet backup-history captures fill specific guide gaps. The admin-domain
and operation captures now have a home in the admin tour. Analytics captures
show the test stack's availability state rather than claiming populated live
traffic. Grafana captures were reviewed and retained; this audit did not rerun
Grafana's data-source qualification.

Two dark-phone request-rule baselines still showed older desktop-like columns.
They were compared with the current capture and responsive source before
refreshing. No application layout or behavior was changed to make a baseline
pass. New documentation-site baselines cover the rendered guide and architecture
page in both themes and widths.

## Document inventory

The table records every documentation page present at the review, excluding
this report itself. `Updated` indicates a source-backed correction or discovery
improvement. `Retained` indicates structural/command/link review without a
required content change. Dated evidence is preserved; two historical source
URLs were repaired without changing their conclusions.

| Page | Treatment |
| --- | --- |
| [architecture/components.md](../architecture/components.md) | Retained |
| [architecture/data-flows.md](../architecture/data-flows.md) | Updated |
| [architecture/data-model.md](../architecture/data-model.md) | Updated |
| [architecture/index.md](../architecture/index.md) | Retained |
| [architecture/production-reference-architectures.md](../architecture/production-reference-architectures.md) | Retained |
| [audit/2026-10-30-codebase-audit.md](../audit/2026-10-30-codebase-audit.md) | Historical record; source URL repaired |
| [audit/2026-11-12-waf-upkeep-check.md](../audit/2026-11-12-waf-upkeep-check.md) | Retained historical evidence |
| [audit/2026-11-13-security-review-scope.md](../audit/2026-11-13-security-review-scope.md) | Retained historical evidence |
| [concepts/cdn-fundamentals.md](../concepts/cdn-fundamentals.md) | Updated |
| [concepts/desired-state.md](../concepts/desired-state.md) | Retained |
| [concepts/domains-and-dns.md](../concepts/domains-and-dns.md) | Retained |
| [concepts/edges-and-cells.md](../concepts/edges-and-cells.md) | Retained |
| [concepts/how-cdnfoundry-works.md](../concepts/how-cdnfoundry-works.md) | Updated |
| [concepts/index.md](../concepts/index.md) | Retained |
| [concepts/revisions-and-operations.md](../concepts/revisions-and-operations.md) | Retained |
| [concepts/the-four-flows.md](../concepts/the-four-flows.md) | Updated |
| [contributing/documentation.md](../contributing/documentation.md) | Updated |
| [contributing/index.md](../contributing/index.md) | Retained |
| [deployment/certificates.md](../deployment/certificates.md) | Retained |
| [deployment/choose-installation.md](../deployment/choose-installation.md) | Retained |
| [deployment/index.md](../deployment/index.md) | Retained |
| [deployment/manual-compose.md](../deployment/manual-compose.md) | Retained |
| [deployment/production-fleet-config-reference.md](../deployment/production-fleet-config-reference.md) | Retained |
| [deployment/production-fleet-operator-guide.md](../deployment/production-fleet-operator-guide.md) | Updated |
| [deployment/production-fleet.md](../deployment/production-fleet.md) | Updated |
| [deployment/production-guide.md](../deployment/production-guide.md) | Retained |
| [deployment/production-quick-start-multi-region.md](../deployment/production-quick-start-multi-region.md) | Retained |
| [deployment/production-quick-start.md](../deployment/production-quick-start.md) | Updated |
| [deployment/production-setup-after-quick-start.md](../deployment/production-setup-after-quick-start.md) | Retained |
| [deployment/sizing.md](../deployment/sizing.md) | Updated |
| [deployment/topology.md](../deployment/topology.md) | Retained |
| [deployment/upgrade.md](../deployment/upgrade.md) | Updated |
| [development/coding-agents.md](../development/coding-agents.md) | Retained |
| [development/frontend.md](../development/frontend.md) | Retained |
| [development/index.md](../development/index.md) | Retained |
| [development/project-layout.md](../development/project-layout.md) | Updated |
| [development/scripts-and-ci.md](../development/scripts-and-ci.md) | Retained |
| [development/testing.md](../development/testing.md) | Updated |
| [getting-started/faq.md](../getting-started/faq.md) | Retained |
| [getting-started/first-domain.md](../getting-started/first-domain.md) | Updated |
| [getting-started/index.md](../getting-started/index.md) | Retained |
| [getting-started/installation.md](../getting-started/installation.md) | Retained |
| [getting-started/journeys.md](../getting-started/journeys.md) | Updated |
| [getting-started/private-cdn-design.md](../getting-started/private-cdn-design.md) | Retained |
| [getting-started/using-cdnfoundry.md](../getting-started/using-cdnfoundry.md) | Updated |
| [getting-started/whats-in-the-box.md](../getting-started/whats-in-the-box.md) | Updated |
| [getting-started/why-cdnfoundry.md](../getting-started/why-cdnfoundry.md) | Retained |
| [guides/admin-panel.md](../guides/admin-panel.md) | Updated |
| [guides/analytics.md](../guides/analytics.md) | Updated |
| [guides/cache.md](../guides/cache.md) | Retained |
| [guides/compression.md](../guides/compression.md) | Retained |
| [guides/dns.md](../guides/dns.md) | Updated |
| [guides/domain-panel.md](../guides/domain-panel.md) | Updated |
| [guides/domains.md](../guides/domains.md) | Retained |
| [guides/edges.md](../guides/edges.md) | Retained |
| [guides/geo-dns.md](../guides/geo-dns.md) | Retained |
| [guides/incident-response.md](../guides/incident-response.md) | Retained |
| [guides/index.md](../guides/index.md) | Updated |
| [guides/log-push.md](../guides/log-push.md) | Retained |
| [guides/managed-waf.md](../guides/managed-waf.md) | Updated |
| [guides/notifications.md](../guides/notifications.md) | Retained |
| [guides/proxy-and-origins.md](../guides/proxy-and-origins.md) | Retained |
| [guides/request-rules.md](../guides/request-rules.md) | Added practical guide |
| [guides/security.md](../guides/security.md) | Updated |
| [guides/tls.md](../guides/tls.md) | Retained |
| [guides/users-and-access.md](../guides/users-and-access.md) | Retained |
| [index.md](../index.md) | Updated |
| [operations/backup-and-recovery.md](../operations/backup-and-recovery.md) | Retained |
| [operations/cell-inventory.md](../operations/cell-inventory.md) | Retained |
| [operations/cli-commands.md](../operations/cli-commands.md) | Retained |
| [operations/control-plane-high-availability.md](../operations/control-plane-high-availability.md) | Retained |
| [operations/dns-nodes.md](../operations/dns-nodes.md) | Updated |
| [operations/fleet-bootstrap-qualification.md](../operations/fleet-bootstrap-qualification.md) | Retained |
| [operations/fleet-engine.md](../operations/fleet-engine.md) | Retained |
| [operations/fleet-rollouts.md](../operations/fleet-rollouts.md) | Retained |
| [operations/fleet-web.md](../operations/fleet-web.md) | Updated |
| [operations/gateway-ingress.md](../operations/gateway-ingress.md) | Retained |
| [operations/grafana-by-team.md](../operations/grafana-by-team.md) | Retained |
| [operations/grafana.md](../operations/grafana.md) | Retained |
| [operations/hardware-requirements.md](../operations/hardware-requirements.md) | Retained |
| [operations/health-probes.md](../operations/health-probes.md) | Retained |
| [operations/index.md](../operations/index.md) | Updated |
| [operations/laravel-operations-dashboard.md](../operations/laravel-operations-dashboard.md) | Retained |
| [operations/monitoring.md](../operations/monitoring.md) | Retained |
| [operations/multi-cell-pools.md](../operations/multi-cell-pools.md) | Retained |
| [operations/operational-logging.md](../operations/operational-logging.md) | Retained |
| [operations/performance-audit-2026-11-21.md](../operations/performance-audit-2026-11-21.md) | Historical record; source URL repaired |
| [operations/pool-service-endpoints.md](../operations/pool-service-endpoints.md) | Retained |
| [operations/production-best-practices.md](../operations/production-best-practices.md) | Retained |
| [operations/production-qualification.md](../operations/production-qualification.md) | Retained |
| [operations/releases/v1.2.1.md](../operations/releases/v1.2.1.md) | Retained historical evidence |
| [operations/releases/v2.0.0.md](../operations/releases/v2.0.0.md) | Retained historical evidence |
| [operations/releases/v2.0.1.md](../operations/releases/v2.0.1.md) | Retained historical evidence |
| [operations/releases/v2.0.2.md](../operations/releases/v2.0.2.md) | Retained historical evidence |
| [operations/releases/v2.0.3.md](../operations/releases/v2.0.3.md) | Retained historical evidence |
| [operations/releases/v2.0.4.md](../operations/releases/v2.0.4.md) | Retained historical evidence |
| [operations/releases/v2.0.5.md](../operations/releases/v2.0.5.md) | Retained historical evidence |
| [operations/releases/v2.0.6.md](../operations/releases/v2.0.6.md) | Retained historical evidence |
| [operations/releases/v2.0.7.md](../operations/releases/v2.0.7.md) | Retained historical evidence |
| [operations/releases/v2.0.8.md](../operations/releases/v2.0.8.md) | Retained historical evidence |
| [operations/releases/v2.1.0.md](../operations/releases/v2.1.0.md) | Retained historical evidence |
| [operations/releases/v2.1.1.md](../operations/releases/v2.1.1.md) | Retained historical evidence |
| [operations/releases/v2.1.2.md](../operations/releases/v2.1.2.md) | Retained historical evidence |
| [operations/releases/v2.1.3.md](../operations/releases/v2.1.3.md) | Retained historical evidence |
| [operations/reports.md](../operations/reports.md) | Retained |
| [operations/request-rules-qualification-2026-11-21.md](../operations/request-rules-qualification-2026-11-21.md) | Retained historical evidence |
| [operations/runbooks.md](../operations/runbooks.md) | Retained |
| [operations/runtime-generations.md](../operations/runtime-generations.md) | Retained |
| [operations/scaling.md](../operations/scaling.md) | Retained |
| [operations/set-up-control-plane-ha.md](../operations/set-up-control-plane-ha.md) | Retained |
| [operations/signing-key-rotation.md](../operations/signing-key-rotation.md) | Retained |
| [operations/simple-anycast-qualification.md](../operations/simple-anycast-qualification.md) | Retained |
| [operations/simple-anycast.md](../operations/simple-anycast.md) | Retained |
| [operations/software-supply-chain.md](../operations/software-supply-chain.md) | Retained |
| [operations/staging-qualification-2026-11-15.md](../operations/staging-qualification-2026-11-15.md) | Retained historical evidence |
| [reference/alerts.md](../reference/alerts.md) | Retained generated reference |
| [reference/api/edge-agent.md](../reference/api/edge-agent.md) | Retained |
| [reference/api/endpoints.md](../reference/api/endpoints.md) | Updated |
| [reference/api/errors.md](../reference/api/errors.md) | Retained |
| [reference/api/index.md](../reference/api/index.md) | Updated |
| [reference/cli-and-scheduler.md](../reference/cli-and-scheduler.md) | Retained |
| [reference/configuration.md](../reference/configuration.md) | Retained |
| [reference/dns-records.md](../reference/dns-records.md) | Updated |
| [reference/fleet-cli.md](../reference/fleet-cli.md) | Retained generated reference |
| [reference/glossary.md](../reference/glossary.md) | Updated |
| [reference/grafana-dashboards.md](../reference/grafana-dashboards.md) | Retained generated reference |
| [reference/index.md](../reference/index.md) | Retained |
| [reference/limits.md](../reference/limits.md) | Retained |
| [reference/platform-settings.md](../reference/platform-settings.md) | Retained |
| [reference/request-rules.md](../reference/request-rules.md) | Updated |
| [reference/security-rules.md](../reference/security-rules.md) | Retained |
| [reference/services-and-ports.md](../reference/services-and-ports.md) | Retained |
| [reference/telemetry-schema.md](../reference/telemetry-schema.md) | Updated |
| [reference/waf.md](../reference/waf.md) | Retained |
| [roadmap.md](../roadmap.md) | Preserved planning contract |
| [security/hardening.md](../security/hardening.md) | Retained |
| [security/index.md](../security/index.md) | Retained |
| [security/reporting.md](../security/reporting.md) | Retained |
| [troubleshooting/by-symptom.md](../troubleshooting/by-symptom.md) | Updated |
| [troubleshooting/dns.md](../troubleshooting/dns.md) | Retained |
| [troubleshooting/edge-and-origin.md](../troubleshooting/edge-and-origin.md) | Retained |
| [troubleshooting/index.md](../troubleshooting/index.md) | Retained |
| [troubleshooting/telemetry.md](../troubleshooting/telemetry.md) | Retained |
| [troubleshooting/tls-and-cache.md](../troubleshooting/tls-and-cache.md) | Updated |

Root `README.md` was modernized. `CONTRIBUTING.md`, `SECURITY.md`,
`CODE_OF_CONDUCT.md`, `core/README.md`, and the pull request template remain
consistent with their scoped contribution, disclosure, licensing, and safe-test
roles. They were included in documentation lint and local-link validation.

## Validation and limits

These are checks actually executed during this audit. Passing tooling checks
does not establish a new production release qualification.

| Check | Result |
| --- | --- |
| Required PHPStan Compose command | Passed, no errors; the baseline was not expanded |
| `make docs-check` | Passed: 151 source documents, 146 built pages, more than 9,800 checked internal links, 48 rendered diagrams, 105 callouts; generated references, lint, executable documentation block, screenshot pairs, and build passed |
| Documentation browser suite | 12 passed across light/dark and desktop/phone; keyboard tabs, diagram enlargement/Escape, local search, image loading, overflow, accessibility scans, and eight visual comparisons |
| Panel documentation captures and focused request-rule browser coverage | Initial focused run: 57 passed, 25 intentional capture skips, two stale dark-phone snapshots; both snapshots reviewed and refreshed, then both cases passed without snapshot updates |
| Fleet documentation browser captures | Six passed; six intentional phone capture skips |
| Broader panel suite attempt | Interrupted after six passes; 469 cases not run and one interrupted. This is not a full-suite pass |
| Contract tooling tests | 55 passed |
| Qualification tooling tests | 26 passed; negative fixtures deliberately produce a failed release decision |
| Compose syntax | Development, development/test, and production example configurations passed |
| OpenAPI | Regenerated; subsequent generator `--check` passed. Only the generated Markdown footer changed, not the JSON contract |
| PHP formatting | Pint passed for the changed OpenAPI generator |
| Documented redirect payload | Typed validation passed without saving a domain or rule |
| Screenshot validator | 19 paired names passed; four isolated positive/negative fixture cases passed; Python compilation passed |
| Image integrity and review | All 38 themed PNGs decoded and reviewed, including direct inspection of new feature and backup views |
| Raster allowlist | Passed |
| External references | All 46 final checked HTTP targets succeeded |
| Preserved contracts | Roadmap and PHPStan baseline unchanged relative to `daee2d58` |

No migration, volume removal, production deployment, real customer access,
dependency change, or serving-runtime change was performed. Generated panel
captures use the browser suite's disposable SQLite database and generated test
credentials. Fleet captures use local fixtures. The retained Grafana captures
were inspected but were not regenerated or independently requalified here.
The unavailable analytics fixture has an explicit caption rather than a claim
that live telemetry was reproduced.

The complete panel and Fleet application browser suites were not qualified by
this audit. No new DNS, HTTP, TLS, failover, recovery, or performance runtime
qualification is claimed. Dated release evidence remains dated evidence. A
VitePress home-content hydration style diagnostic was observed during browser
diagnosis; exercised navigation and search pass, and no dependency was patched
or diagnostic suppressed. Pushing `dev` does not publish the public documentation
site, whose publication follows the repository workflow.

## Documentation design review

Direction: retain the existing CDNFoundry documentation shell, typography,
tokens, and restrained accent. Let actual product screens and request-flow
diagrams explain the product. ENERGY 1 / RHYTHM 1 / MOTION 1. The only visual
style correction uses the existing foreground/background token pair to make
Mermaid labels readable. No new palette, icon system, motion, or illustration
was introduced.

The following antislop Delivery Gate applies to the changed documentation and
its exercised controls; it is not a new certification of every application
screen. Each item records the relevant evidence or the technique's absence.

### Hard gate

- R-02 PASS: reviewed changed introductory and feature copy uses plain punctuation; retained code and historical evidence keep their original syntax.
- R-03 PASS: guide, diagrams, and search navigation pass document-width assertions at 390 and 1,440 pixels in both themes; phone snapshots reviewed.
- R-17 PASS: measurement references point to dated evidence; no new uptime, adoption, or throughput statistics.
- R-18 PASS: no testimonials or invented people added.
- R-23 PASS: owner-requested screenshots come from seeded browser fixtures; no invented logo, customer asset, or avatar.
- R-24 PASS: source and built-site link checks pass; the new sidebar guide and search result resolve.
- R-25 PASS: serious/critical WCAG axe findings are zero on the exercised guide and diagrams in all four projects; dark label contrast corrected and visual comparisons pass.
- R-26 PASS: exercised tabs, diagram enlarge/close, search, result navigation, and home entry link have real behavior or destinations.
- R-27 PASS: existing screenshot loading and diagram failure handling retained; the analytics unavailability state is captioned honestly; no new data-driven widget.
- R-28 PASS: no generic FAQ added; troubleshooting follows actual platform symptoms.
- R-32 PASS: keyboard-only tab arrows and diagram Escape pass in all four projects; focus is checked explicitly.
- R-33 PASS: contrast correction is committed in the theme source; no external source-patching script.
- R-34 PASS: light/dark image sources, diagrams, layout, and visual comparisons pass at both widths.
- R-35 PASS: production documentation build and 12 browser cases pass; the changed guide's tabs, diagram controls, home link, and search/result flow are exercised.
- R-36 PASS: security and failure claims cross-checked against runtime stages; qualification limits stated above.
- R-37 PASS: existing product design direction retained; conservative dials and the label-contrast purpose recorded.
- R-38 PASS: feature descriptions map to source in the capability table; screenshots use explicit test fixtures.
- R-39 PASS: native tab buttons, image alternatives, existing dimensions, landmarks, and focus behavior retained; accessibility and keyboard checks pass for the changed pages.
- R-40 PASS: Screenshot component retains width/height; no new hero media or third-party blocking script; no invented performance score.

### Purpose gate

- R-01 PASS: no new gradient or glow.
- R-04 PASS: no new icon or icon library.
- R-06 PASS: existing documentation typography retained; monospace remains for commands and identifiers.
- R-07 PASS: no decorative grid or background pattern.
- R-08 PASS: diagram arrows express actual request or publication direction; no decorative button arrows added.
- R-09 PASS: no promotional capsule badge added.
- R-10 PASS: no glass effect added.
- R-12 PASS: no shadow system added.
- R-13 PASS: no glow added.
- R-14 PASS: practical prose, procedures, reference tables, and real screenshots follow their information needs; no feature-card grid added.
- R-19 PASS: no animation added; existing diagram controls keep the low-motion documentation direction.
- R-22 PASS: all new product images are real fixture captures; no generic illustration.

### Liveliness

- Dials PASS: ENERGY 1 / RHYTHM 1 / MOTION 1 explicitly retained for technical reading.
- Consistency PASS: quiet typography, ordered steps, and static images match those dials; four-project visual review passed.
- Focal point PASS: page title and first task lead each guide; request-flow diagrams lead the architecture explanation.
- Whitespace PASS: existing section, paragraph, and code spacing separates procedures and references; phone snapshots show the result.
- Accent PASS: existing link/action accent retained; no competing palette.
- Identity PASS: concrete CDN terminology, control-plane/runtime boundaries, and actual panel captures recur across the changed pages.
- Design read PASS: existing CDNFoundry documentation system and low-motion reading direction were retained before the visual correction.

### Craftsmanship and consistency

- C-1 PASS: each change resolves a documented gap; the visual correction resolves a reproduced contrast failure.
- C-2 PASS: changed-page tabs, diagram controls, and search/result navigation pass browser interactions.
- C-3 PASS: sections follow setup choices, operator tasks, or source-backed flow stages; no filler section added.
- C-4 PASS: both themes and widths, keyboard focus, overflow, image loading, and visual snapshots pass the documentation suite.
- C-5 PASS: examples are labelled examples, screenshots use fixtures, and runtime/performance evidence retains its date and limits.
- R-05 PASS: existing reading layout retained; guide steps and architecture flows follow actual work rather than a marketing template.
- R-11 PASS: existing radii retained; no new pill treatment.
- R-15 PASS: home entry names the task, 'Choose your starting point'; README setup links name their destination.
- R-16 PASS: revised copy describes CDN features, procedures, and bounds without promotional buzzwords.
- R-20 PASS: PowerDNS publication, gateway routing, Fleet, and domain request-rule screens make the content specific to CDNFoundry.
- R-21 PASS: existing theme choice retained; light and dark both qualified.
- R-29 PASS: existing neutral and accent tokens retained; no new color literal.
- R-30 PASS: no external product layout or branding copied.
- R-31 PASS: readable token-paired labels, real screenshots, role-based onboarding, and a practical rule example each have a stated reader purpose.

## Completion gate

| Area | Status |
| --- | --- |
| Implementation | Complete for the documentation tools, capture fixtures, and theme correction; no product behavior change |
| Documentation | Complete for this audit: inventory, corrections, guide, references, screenshots, and review record |
| Automated/runtime qualification | Documentation/build/static/contract checks passed; no new production or real-runtime qualification claimed |
| Browser suite | Documentation suite passed; focused panel and Fleet capture results recorded above; full application browser qualification remains outside this audit's result |

## Commits

The audit is split by concern on `dev`; the README is its own commit. The
final audit-record commit follows these changes. The starting commit records
the owner's previously pending work, which this audit preserves.

| Commit | Concern |
| --- | --- |
| `cb4ed590` | Domain feature and Fleet backup screenshot fixtures and captures |
| `b58885b7` | Practical request-rule guide and navigation |
| `6d7abb41` | Newcomer paths and feature discovery |
| `6125ae3d` | DNS publication, HTTP stages, and data model |
| `57f88ca4` | Security, telemetry, tours, and troubleshooting |
| `74e71b93` | Guided Fleet setup, sizing, and supported upgrades |
| `806138a3` | Immutable historical evidence links |
| `25570e43` | API generator link and contract coverage limits |
| `4a3e22e0` | Product-first README |
| `483c3791` | Fleet startup-order primary registration clarification |
| `561e972e` | Documentation contrast correction and browser/visual checks |
| `e1e64fe7` | Screenshot validation and contributor testing instructions |
