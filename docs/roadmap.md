---
title: CDNFoundry roadmap
description: Evidence-based priorities for CDNFoundry security, reliability, measured performance and bounded product improvements, preserving the existing WAF and request-rule commitments.
---

# CDNFoundry roadmap

**Status: active.** Originally written 14 November 2026; reviewed on
**21 November 2026**, starting at `dev` commit `2f99af78` plus the working tree.
This revision preserves the existing twelve phases, their useful acceptance
criteria and implemented work. It adds a platform foundation checkpoint,
corrects stale starting points, and separates mandatory reliability work from
conditional feature proposals. It is planning only; it changes no runtime,
production code, infrastructure or dependencies.

**Extension, 27 November 2026.** Phase 10.5 (operational hardening,
observability and reliability) is added between Phase 10 and Phase 11 and is a
mandatory prerequisite for Phase 11. No existing phase was renumbered or
rewritten, and its evidence and checkpoints are unchanged. The extension is
planning only: it changes no runtime, migration, Compose file, test or
deployment, and every one of its checkpoints is open.

This roadmap is part of the product contract in `AGENTS.md`. Security comes
first, measured performance second, and simplicity third. A conflicting idea
stops here until the contract and documented evidence are reconciled. Work
happens on `dev`, in bounded increments. Commit and push only on owner request.

## 1. Starting point

### Evidence and status vocabulary

- **Verified:** implemented and supported by appropriate passing checks, with
  the scope, release and evidence stated. Contract checks verify their contract
  only, not production serving.
- **Partial:** meaningful implementation exists with a demonstrated limitation.
- **Unverified:** code or historical evidence exists, but adequate current
  qualification has not been established. It does not mean missing or broken.
- **Missing:** no meaningful implementation was found in the inspected paths.
- **Not needed:** insufficient value or a conflict with the product contract.

Source findings below are high confidence where a named branch or fixture
states the behavior. Absence findings are medium confidence and scoped to the
inspected model, compiler and runtime paths. No subsystem is declared Verified
merely because its code, menu, feature flag or test script exists.

### Configuration, serving and recovery boundaries

| Boundary | Source evidence inspected | Existing mechanism and remaining proof |
| --- | --- | --- |
| Configuration → authorization | `core/routes/api.php`, `core/app/Policies/DomainPolicy.php`, `core/app/Models/Domain.php`, `core/app/Http/Middleware/IdempotentRequest.php` | Disabled-user, assigned-domain and token scope checks, policy-aware binding and idempotency exist. Recheck nested resources, bulk operations, cursor scope, token revocation and concurrent idempotency through the API. |
| Validation → durable state | `core/app/Support/DomainRequestRules.php`, `OriginData.php`, `DnsZoneValidator.php`, `SecurityRuleData.php` | Typed bounds, transactional revisions, audit records and dispatch after commit exist. Qualify constraints, concurrent writes, obsolete jobs and operation rollback. |
| Desired state → DNS | `core/app/Jobs/ReconcileDnsZone.php`, `core/app/Support/PowerDnsClient.php`, `PowerDnsZone.php` | Deterministic render/checksum, per-zone job exclusion, RRset replacement, read-back and restoration exist. Qualify timeout after mutation, SOA monotonicity, secondary convergence and negative answers. |
| Desired state → edge | `core/app/Jobs/ReconcileEdgeDomain.php`, `core/app/Support/ArtifactSigner.php`, `edge-agent/main.go`, `generation.go`, `state_store.go`, `signing_keys.go` | Signed artifacts, bounded candidates, atomic generation pointers, encrypted recovery and rollback exist. Qualify tampering, wrong assignment, interrupted publication, replay and signing-key retirement. |
| Internet → gateway → cell | `edge-gateway`, `docker/nginx/edge.conf`, `docker/openresty/runtime.lua`, `client_key.lua` | Host/SNI admission, trusted PROXY identity, connection ceilings, forwarding policy and IPv6 prefix keys exist. Qualify hostile forwarding headers, framing ambiguity, HTTP/2 and direct-cell access together. |
| Cell → origin | `core/app/Support/OriginData.php`, `OriginPolicy.php`, `edge-agent/origin_policy.go`, `probes.go`, `docker/nginx/origin-proxy.conf` | Destination exclusions, connection-time checks, verified and explicitly unverified HTTPS paths exist; plaintext HTTP is also allowed. Revalidate every A/AAAA answer and backup destination. Do not claim all origin connections are authenticated. |
| Cell → telemetry → operator | `docker/vector`, `docker/clickhouse`, `core/app/Support/AnalyticsStore.php`, `core/app/Jobs/PushDomainLogs.php`, `core/app/Actions/RecordSecurityReport.php` | Direct runtime collection, bounded queries, per-domain reporting, cursor-based export and retries exist. Qualify redaction, cross-tenant filters, loss accounting, full buffers and outage recovery without slowing serving. |
| Release → failure → recovery | `scripts/cdnfoundry_fleet`, `compose.prod.yml`, `tests/qualification/test_evidence.py` | Signed releases, resource limits, health checks, encrypted edge recovery and a documented recovery secret set exist. Qualify fencing, restore and reboot during control outage on the same release. |

Each source path establishes a mechanism, not a successful production run.
The lifecycle must be exercised across these boundaries, not just function by
function. Laravel, analytics and external providers stay off the serving path.
PostgreSQL remains the owner of desired state; derived state stays rebuildable.

### Current capability status

| Capability | Status at review | Evidence and actual remaining work |
| --- | --- | --- |
| Managed WAF and body integrity | Partial | `waf_body.lua`, runtime `admit_waf_body` and `score_request` read decoded bodies independently of Content-Length, bound size/encoding and handle scoring failure. `tests/e2e/waf_body_inspection.py` exists and has a historical passing record. The old chunked-body bypass description is obsolete; requalify it rather than implement the reader again. |
| Profiles, scores and tuning | Partial | ModSecurity/CRS profiles and rule controls exist. Scoring opens a loopback connection; log-only controls can require two passes. Matched CRS IDs and scoped exclusions remain incomplete; a converted anomaly score is not a calibrated probability. |
| Custom and platform rules | Partial | Typed conditions/actions and validation previews exist (`SecurityRuleData`). Shadow, expiry, measured impact and staged rollout remain Phase 4 work. Query/cookie presence is not full value matching. |
| Client identity and fairness | Partial | `client_key.lua`, `domain_budget.lua`, containment, breadth-aware reports and challenge binding exist. `rate_limit_isolation.py`, `ipv6_rate_limit_keys.py`, `client_containment.py`, `security_report_fairness.py` and `challenge_binding.py` have historical passing records. Budget overflow still skips new rule-key counting. |
| Challenge and incident protection | Partial | Local PoW, incident profiles, per-domain/client limits and prefix binding exist. UA binding is optional; tokens remain cell-scoped and keys do not have the planned pool-wide rotation lifecycle. Broad bot classification and verified crawler datasets are missing in inspected runtime paths. |
| API/account protection | Partial | Paths, methods, parameters, body-size checks and small local password/prompt heuristics exist. Full bounded JSON schema validation and qualified JWT enforcement remain incomplete. Response scanning is limited, not general response DLP. |
| DNS and traffic routing | Partial | Zone validation/import/export, Geo-DNS, hidden primary and TSIG/catalog secondaries, pool placement and primary/backup origin failover exist. DNSSEC desired-state/key/DS lifecycle was not found; the secondary SQLite backend has `gsqlite3-dnssec=no`. Internal secondary DNS is not missing. |
| TLS and origin trust | Partial | DNS-01 managed and uploaded edge certificates, encrypted keys and renewal exist. `verify_tls=false` is accepted and an unverified origin location exists. Origin client-certificate delivery was not found; agent-control mTLS protects a different hop. |
| Cache and compression | Partial | Canonical PHP/Lua keys, epoch/full and URL purge, locking/revalidation, status TTLs, auth/cookie bypass and Gzip/Brotli exist. Runtime response admission rejects private/no-store/no-cache, Set-Cookie and unsupported Vary; native Vary is ignored only with this conservative admission path. Combined variant/pressure/fleet proof and useful reason diagnostics remain. |
| Request/response rules | Partial qualification | Phase 12 code, forward migrations, rollback, real-cell IPv4/IPv6 and benchmark evidence exist. The qualification record still shows the complete browser/snapshot/accessibility gate in progress at inspection. Do not rebuild these rule types. |
| Analytics, DNS logs and export | Partial | ClickHouse queries/rollups, DNS dnstap and scoped logs exist. `PushDomainLogs` exports gzip NDJSON through HTTPS/S3, advances cursors after acceptance and records retention gaps. A separate qualified live stream and complete decision trace are absent. |
| Deployment and recovery | Unverified as a full current release | Fleet, signed release manifests, Compose limits, encrypted edge state, HA/recovery and evidence tooling exist. Their presence and old runs do not establish current integrated fleet qualification. |

### Existing records and unresolved evidence

- The [performance audit](operations/performance-audit-2026-11-21.md) includes
  cgroup CPU measurements, raw edge/DNS JSONL and before/after experiments.
  Short isolated runs do not establish integrated host capacity or a soak
  envelope. The recorded failures were `custom_rules` (missing monitored access
  row), `application_protection` (missing `schema_body_too_large` count), and
  `cell_generation_reload` (loading state not observed).
- The later [request rules record](operations/request-rules-qualification-2026-11-21.md)
  reports `custom_rules` passing after bounded access-log polling. Reconcile it
  for that recorded image/run, not for every release. The other two failures
  still need disposition. Phase 12 browser qualification remains pending until
  its record and actual suite results close it.
- The [15 November staging record](operations/staging-qualification-2026-11-15.md)
  explicitly leaves IPv6, failure injection, cache variants and sustained load
  untested. Later local performance fixes do not close fleet gates; the old
  cell cost is also not the current baseline.
- `tests/e2e/production_qualification.py` and its evidence validator already
  exist. Extend that runner and its release records; do not add a second system.

### Findings and priorities

| ID / priority | Evidence, impact and uncertainty | Action and smallest useful result |
| --- | --- | --- |
| F1 / P0 for deployments relying on rule limits as an abuse boundary | Runtime `rate_limit_exceeded` returns false for new keys refused by `domain_budget.admit` or on counter allocation failure. `rate_limit_isolation.py` explicitly expects the attacked domain to lose counting. Other tenants and built-in domain/Nginx protections remain; this is not proof of a WAF bypass or data leak. Source confidence high; trigger cost and exploit impact unmeasured. | Finish the remaining Phase 3 overflow work first. Define a bounded, conservative domain-scoped fallback using reserved capacity; new keys must not silently evade the rule's protection. Test repeated requests on keys first seen after exhaustion, allocator pressure and a healthy comparison domain. |
| F2 / P1 | Unresolved historical telemetry/loading observations and pending browser qualification can conceal incomplete behavior. They are not proven serving defects. | Reproduce on stamped images, distinguish fixture timing from runtime failure, and close four separate completion fields. Escalate to P0 only if reproduction shows isolation, serving or last-valid-state failure. |
| F3 / P1 | Origin TLS verification can be disabled and HTTP is accepted. Public unverified traffic is exposed to interception/impersonation; source does not show one tenant changing another's settings. | Qualify and document current modes; review secure defaults and bounded custom trust before changing compatibility. Optional origin mTLS follows ordinary server verification, never substitutes for it. Update, 27 November 2026: the owner requires new origins to default to verification off; Phase 10.5 Stage H records that change and its documentation and keeps every stored explicit value. |
| F4 / P1 | Cache safety depends on response filters, internal header trust, normalization and purge agreeing after rewrites. Unknown-length responses require bounded spool/admission proof. No leak was demonstrated. | Qualify auth/cookies, repeated Vary, HEAD/Range, compression, rewrite/purge, full temporary storage and restart. Keep unsupported variants uncacheable; hit ratio never outranks isolation. |
| F5 / P1 | Combined gateway/cells/agent/Vector/DNS, sustained load, bursts and recovery were not measured after local improvements. Per-container limits do not ensure whole-host headroom. | Establish integrated saturation/recovery baselines before tuning quotas, buffers, connection reuse or log volume. Preserve source/global protections; do not improve benchmarks by weakening admission. |
| F6 / P1 feasibility, P2 implementation | DNSSEC and origin client authentication offer useful authenticity, but require signing/key/DS or per-tenant certificate lifecycle and recovery not currently present. | Complete bounded feasibility/security decisions first. Reuse PowerDNS, artifact delivery and encrypted TLS storage. Defer implementation if restoration or rotation cannot be proved without new infrastructure. |

P0 is conditional on the exposure stated above, not an invented global severity
rating. No external exploit, cross-tenant disclosure or cryptographic break was
demonstrated in this audit. Performance opportunities require a new comparison,
not inference from source appearance.

## 2. Direction

- **Secure first, then measure.** Every detection, limit, and challenge change is judged
  against the same corpora and benchmark, built in Phase 1. A phase that cannot
  show its false positive rate, its false negative rate, and its latency cost
  is not complete.
- **Decide at the edge.** Every security decision is made in the cell from
  local state. The control plane manages policy, compiles and signs data,
  aggregates reports, and runs analytics. No request waits on Laravel, another
  cell, another PoP, or an outside service.
- **Mitigate the client first, the domain last.** Most actions target a client
  key (an address, an IPv6 prefix, a session, or a token). Domain-wide
  tightening is kept for attacks that come from many sources.
- **Bring intelligence in as data.** Crawler ranges, reputation, IP lists, and
  password corpora, when admitted, reach cells as signed, versioned, bounded datasets. Each
  dataset has a fixed memory size and a last valid version that stays active.
- **Share between cells asynchronously.** Cells report bounded summaries, the
  control plane aggregates them, and cells receive the result within minutes.
  Losing that path removes only the shared layer, never local protection.
- **Make every decision explainable.** Each decision records which stage acted,
  which rule or signal matched, and which datasets it used. Heuristics are named
  and readable. There is no machine-learned scoring.
- **Ship every new detection observe-first.** It starts in shadow or log mode,
  shows its measured impact, and only then blocks.

Bounds that apply in every phase: one engine per concern, typed settings, no
expression language, no unbounded regex, no per-domain process, file, or reload,
and shared memory that is bounded and partitioned so one tenant cannot evict
another tenant's state.

## 3. Phase rules

### Gate

A phase is complete only when every field passes. The fields are recorded
separately at the end of the phase.

| Field | Required evidence |
| --- | --- |
| Implementation | Merged to `dev` in small commits. Replaced code is deleted. |
| Tests | Feature and unit tests for the happy path, permissions, validation, idempotency, failure, and last valid state. Go and Lua tests where the runtime changed. |
| Runtime qualification | Python E2E under `tests/e2e` on real cells, including IPv4 and IPv6, and the Phase 1 harness re-run with its numbers recorded. |
| Browser suite | `tests/browser` passes for every changed screen, with visual snapshots and accessibility checks. Every input has an example placeholder and every complex item has a help tooltip. |
| Documentation | Every affected operator and user page states the new behavior and its limits. |

### Frontend rules

Every changed screen, in both panels and the Fleet web page, has a short tooltip
on every complex item. Every text, number, URL, list, and tag input has a
realistic example placeholder. A placeholder is never the only label and never a
real secret.

### Measurement rules

Reuse `tests/e2e/edge_cell_benchmark.py`, `dns_benchmark.py` and the existing
qualification runner. The November audit's one-CPU cached-object medians were
402 microseconds CPU/request with WAF off and 2,839 with balanced WAF. These are
historical workload-specific observations, not an SLO or evidence that disabling
protection is an optimization. Do not repeat its already-implemented fixes.

Record commit/dirty diff, image digest, kernel, CPU quota, memory limit, network,
object/body size, domain/rule cardinality, generator capacity, concurrency,
errors, warm/cold state, p50/p95/p99, CPU/request, peak RSS, disk/temp bytes,
drops and recovery. Repeated control runs establish noise. Publish sustainable
capacity with stated headroom, not the highest burst or summed cell results.

Existing numerical targets below are retained as proposed experiment criteria
unless an attributed qualification record already supports them. Before an
implementation stage, ratify or revise each target from its reference baseline,
noise, security and user/operational need, and record the reason. Corpus sizes
are coverage inputs, not proof of accuracy; test data must represent distinct
traffic classes. No claimed 1% false-positive rate without denominators and
uncertainty. Security invariants, bounds and last-valid-state requirements are
mandatory regardless of performance. A development-host number is labeled as
such and cannot alone close fleet qualification.

### Stage contract and execution order

Every stage produces a reviewable result and closes the implementation,
documentation, automated/runtime and browser fields separately. The phase's
objective/current state identify its problem and starting evidence; its scope,
execution plan, security/resource boundary and tests all apply to every stage.
Before coding, record durable state, permissions, external effects, retry,
rollback and exact bounds. Every new major subsystem starts with feasibility
and security evaluation; failure to justify it means deferral, not scaffolding.

Run Phase 0 first, including the remaining Phase 3 overflow stage. Extend the
Phase 1 baseline and finish Phases 2 and 3 before adding detection capabilities.
Then follow documented dependencies. Phase 12 is already independently admitted
and implemented; close its current gates without restarting it. Phase 11
integrates the same release, including Phase 12. Phases 7 bot signals, 8 shared
reputation, and 9 JWT/discovery are conditional; an explicit deferral removes
their optional fields from dependent scopes, never waives a core security gate.
This avoids the Phase 7/8 reputation dependency cycle.

Phase 10.5 runs after Phase 10 and before Phase 11. It is mandatory, not
conditional. Its stages have their own order, stated in the phase, and Phase 11
cannot start its release gate until all of its checkpoints are closed.

Each change uses existing queues, revisioned snapshots, signing, stores and
qualification machinery. No synchronous runtime effects, new service, general
expression language or second evaluator. The one exception is the optional
origin health-check container admitted in Phase 10.5 Stage G, with the limits
stated there. Invalid candidates retain previous
valid state. Time-limited trust/mitigations also honor expiry/revocation; last
valid state must not mean trusting a revoked key or keeping a stale block forever.

---

## Phase 0: Platform foundations and evidence reconciliation

**Objective.** Close exposed protection gaps and establish a truthful release
baseline across the control, DNS, serving, telemetry and recovery planes.

**Priority and starting point.** F1 is P0 for deployments relying on rule limits;
F2 to F5 are P1 qualification/hardening work. The source and historical records
in section 1 are the starting evidence, not a current production pass.

**Scope and exclusions.** Finish existing safeguards and qualification. No new
customer feature, rewrite, dependency, public traffic test or infrastructure
component. Preserve PostgreSQL and named development volumes. No destructive
refreshes, production secrets or customer-data captures.

**Dependencies.** None. Baseline collection starts before any fix or tuning;
Phase 1 expands it rather than creating another harness.

### Stage A: Protection and trust boundaries

- Reproduce F1 with a disposable real cell: saturate the rule domain's budget,
  then repeat the same previously unseen key beyond its threshold. Exercise
  physical allocation failure, maximum domain assignment and a healthy tenant.
  Keep builtin domain/Nginx fallbacks enabled and record which layer acts.
- Complete the remaining Phase 3 overflow design with reserved, bounded state.
  The result must preserve a specified abuse boundary and cross-tenant
  isolation at saturation. Do not merely suppress the state-full event or
  loosen limits. Reuse the existing counters, not a remote quota service.
- Requalify API/session/token equivalence, revoked/disabled users, bulk and
  nested-resource isolation, idempotency conflicts, revision races and rollback.
- Exercise Host/SNI/PROXY/forwarding trust, ambiguous framing, duplicate headers,
  mixed A/AAAA, rebinding and backup-origin policy. Qualify current HTTP and
  verified/unverified HTTPS behavior, including invalid cert/chain/name/expiry.
  Review a secure origin-mode policy and transition before proposing changes;
  origin client authentication is a later conditional stage.

**Acceptance and tests.** A new key after exhaustion cannot silently remove the
configured protection, and another tenant's threshold/state is preserved.
Unauthorized mutations never reach desired state or origin. Record actual
fallback errors and resource bounds. Use isolated PHP tests through
`make dev-test`, relevant PostgreSQL concurrency fixtures, existing Go origin
and gateway tests, and real `origin_destinations.py`, `sni_host_binding.py`,
`waf_body_inspection.py`, `rate_limit_isolation.py` and new overflow cases.
Mocks/contract checks supplement these runs, never replace them.

### Stage B: Serving correctness and durable recovery

- Qualify cache isolation and canonical keys through lookup, rewrite and purge:
  Authorization/cookies/Set-Cookie, repeated Vary, private/no-store/no-cache,
  HEAD/Range, validators, compression and unknown-length/large responses.
  Full cache/temp filesystems and concurrent fills must remain bounded and
  must not expose private bytes or return an unrelated tenant's response.
- Qualify DNS render/read-back/rollback, monotonic serials, NXDOMAIN versus
  NODATA, UDP truncation/TCP fallback, Geo-DNS parity and TSIG/catalog secondary
  convergence/expiry. Do not mistake internal replication for a missing feature.
- Qualify managed/uploaded TLS renewal failure, DNS-only exclusion, invalid
  replacement, rotation and recovery with the previous valid certificate.
  Exercise signed artifact refusal, assignment, replay, interrupted activation,
  restart with control down, HA fencing and off-host reconstruction using the
  complete documented encryption/signing/PKI recovery set.
- Run telemetry and export outages with bounded full buffers. Confirm redaction,
  tenant-scoped filters, export retries/duplicates/late inserts and retention-gap
  accounting; dependency failure cannot delay serving.

**Acceptance and tests.** No cross-tenant/private cache response, unsafe origin
connection, invalid active bundle, serial regression or false successful
operation. Record any unavailable dual-stack path as nonpassing. Reuse
`openresty_runtime.py`, `request_rules.py`, `edge_purge_protocol.py`,
`dns_authoritative.py`, `dns_node_convergence.py`, `managed_tls.py`,
`uploaded_tls.py`, `telemetry_analytics.py`, `log_push.py`,
`offhost_recovery.py` and the existing HA/failure fixtures as applicable. Record
measured RPO/RTO and restore limitations, not just successful snapshot listing.

### Stage C: Integrated budgets and honest release evidence

- Reconcile the historical `custom_rules` rerun and reproduce the remaining
  `application_protection` and `cell_generation_reload` failures. Classify
  serving defects separately from observation races; retain the failing result
  until a release-matched fix/rerun supports closure.
- Measure the integrated gateway, cells, agent, Vector and DNS with the control
  and telemetry topology recorded, including cold start, steady load, burst,
  soak, saturated resources and recovery. Source/global rate protections stay
  enabled. Compare active/quarantine placement and full-host memory/disk/network
  headroom rather than multiplying isolated-cell throughput.
- Reconcile Phase 12 browser/snapshot/accessibility evidence and all existing
  implementation records. Record feasibility/admission decisions for optional
  datasets, DNSSEC and origin mTLS using the candidate register; do not start
  their implementation during this foundation phase.

**Acceptance and tests.** Each record has a source/image identity, environment,
executed command, original exit status, measurement and limitation. Unavailable,
failed, skipped and stale evidence cannot become a release pass. Reuse
`production_qualification.py` and its validator, the existing benchmarks and
`tests/browser/run.sh` for any UI implementation being qualified. Visual and
accessibility results are mandatory for the existing current-phase UI gate.

**Security, performance and simplicity requirements.** Do not weaken TLS,
inspection, source limits, log redaction or tenant isolation for a measurement.
Ratify CPU, RSS, descriptors, shared memory, connections, temp/cache bytes,
telemetry buffers and queue/query budgets from measured sustainable capacity
with recovery headroom. No unbounded retries or fan-out; bulk work cannot starve
interactive/runtime lanes. Use existing components and fixtures. Failure
preserves prior valid state and reports degradation explicitly.

**Execution record, 25 November 2026** (development host, working tree on `dev`;
edge-runtime image built from this checkout; the development host number is not a
fleet result).

| Item | Result |
| --- | --- |
| F1 overflow | Implemented: keys without a counter share one overflow counter per rule and window, held to the rule's threshold. `rate_limit_isolation.py` passed with the new late-key and healthy-tenant assertions. The dense-cell share rule (no floor beyond about 650 domains) is covered by contract tests only; a real cell at maximum domain count and physical allocation failure were not run. |
| API isolation | `ApiTenantIsolationTest` passed: nested routes, idempotency keys per user, token revocation. |
| Origin, SNI, body | `origin_destinations.py`, `sni_host_binding.py`, `waf_body_inspection.py` passed. No secure-origin-mode change is proposed: the API requires an explicit `verify_tls`, the panel defaults it to on, and HTTP and unverified HTTPS stay documented, opt-in modes. |
| Real-cell runs that passed | `custom_rules` (rerun alone; the first batch run hit its 30 minute limit with no output, most likely a first image build, cause not proven), `request_rules`, `challenge_binding`, `client_containment`, `ipv6_rate_limit_keys`, `security_report_fairness`, `client_address`, `openresty_runtime`, `dns_node_convergence`, `uploaded_tls`, `telemetry_analytics`, `log_push`, `offhost_recovery`. |
| Historical failures classified | `application_protection` (`schema_body_too_large` missing): an observation limit, not a serving defect. The request is refused with 403; the heartbeat report carries five reasons per domain ranked by count, and this fixture's domain has six. The test now asserts the bound. `cell_generation_reload` (state not loaded): a fixture defect. It ran a 20 day old development image that lacked a variable the mounted configuration uses; it now runs the image built from the checkout and passes. A product-level change to rotate rare reasons into the report is not admitted. |
| Not passing or not run | `dns_authoritative` failed and `managed_tls` stopped at the DNS-01 publishing step: the development stack has no PowerDNS primary or database containers (`pdns-primary`, `pdns-db`, `pdns-migrate`), and the platform identity update could not resolve `local-pdns`. The likely cause is that environment, not the code, but this is unproven. `edge_purge_protocol` needs registry digest-pinned images and was not run. |
| Not started | Stage B HA fencing and restart with the control plane down, signed-artifact replay and wrong-assignment cases, cache variant and pressure matrix, UDP truncation and negative-answer cases. Stage C integrated gateway, cell, agent, Vector and DNS budgets: cold start, steady, burst, soak, saturation and recovery on a reference host. Phase 12 browser gate reconciliation. |
| Browser | `edge-health` and `every-screen` passed (108 of 108, desktop and phone, light and dark, with accessibility checks). The full suite was not run. |

**Completion checkpoint, 25 November 2026.**

| Implementation | Documentation | Automated/runtime | Browser |
| --- | --- | --- | --- |
| Open: F1 shipped; Stage B and C items above remain | Current for shipped behavior | Open: the matrix above has failures and gaps, no release-matched pass | Open: the full suite and the Phase 12 gate are not closed; no waiver |

Phase 0 is not complete.

---

## Phase 1: Verification baseline

**Execution plan.** Priority: P1, before behavior changes.

**Ordered stages.** 1. Inventory existing tests, image identity and version fields. 2. Extend the existing runner with versioned clean/evasion corpora and counted expectations. 3. Record repeated profile and full-host baselines with failure/recovery controls.

**Security, resources and failure boundary.** No new test platform or runtime hooks. Synthetic data only; corpus framing must reach a real cell. Budget corpus/runtime duration and generator capacity separately from the target. A harness error fails qualification rather than changing serving.

Each stage closes the shared phase gate with the phase-specific tests and
acceptance criteria. Scope not named in this phase is excluded.

**Objective.** Make false positives, false negatives, and latency measurable,
and record today's numbers, before any detection changes.

**Current state.** Real-cell fixtures and a measured edge/WAF benchmark exist.
The November performance record is reusable evidence, not a complete detection
corpus, false-positive study or integrated fleet budget. Inventory the current
version fields before adding any; extend the existing harness and runner.

**Scope.**

- An **attack corpus**: the CRS project's own regression tests for the pinned
  version, plus CDNFoundry cases for evasions that concern this runtime
  (chunked bodies, HTTP/2 without a content length, encodings, header limits,
  oversized bodies).
- A **clean corpus** of at least 10,000 requests: browser page loads, form
  posts, JSON APIs, file uploads, mobile API clients, webhooks, and crawlers.
  It is generated or recorded only from test systems, never from customer data.
- A **harness** that replays both corpora against a real cell at each profile
  and paranoia level and reports the block, log, and pass rates.
- A **latency and CPU benchmark** for WAF off, Observe, Recommended, and High
  sensitivity, with log-only rules, with 100 and 1,000 custom rules, with 20
  rate-limit rules, and with each detection on. Body sizes are 0, 4 KiB,
  64 KiB, and 1 MiB.
- **Ruleset and dataset versions per cell** in the heartbeat, shown on the cell
  and pool screens, with an alert when they differ from the pinned versions.

**Technical direction.** The harness is a Python tool under `tests/` that
drives the cell over the network. It needs no hook inside the runtime. Its
results are written as a machine-readable record that each later phase
compares against.

**Dependencies.** None.

**Acceptance criteria.**

- The harness produces, for each profile, the attack detection rate, the clean
  false positive rate, and the latency at p50, p95, and p99 with CPU per request.
  The baseline is recorded in this roadmap.
- The chunked-body case is in the corpus. Its result today is recorded, whatever
  it is.
- A cell running a different CRS version from the pinned one raises an alert
  within two heartbeats.

**Verification.** The harness itself is tested against a stub origin. One full
run on the reference cell is stored with the release record. The version
mismatch is tested by starting a cell from an image with a changed version label.

**Recorded baseline, 26 November 2026.** Commit `808c527d`, clean tree, development host,
ModSecurity and CRS at the pinned commits. Full tables, method, limits and the raw
records are in [WAF verification baseline](operations/waf-verification-baseline.md).

| Level (paranoia 1) | In-scope CRS attacks detected | Ordinary clean requests blocked | CPU per request, cached GET | p99, cached GET |
| --- | ---: | ---: | ---: | ---: |
| Off | 0% | 0% | 436 µs | 33 ms |
| Observe | 94.25% (2,311 stages) | 0% of 9,670 | 2,914 µs | 211 ms |
| Recommended | 94.25% (93.21% blocked) | 9.18% (8.62% to 9.78%) | 3,361 µs | 230 ms |
| High | 94.25% (94.20% blocked) | 9.18% (8.62% to 9.78%) | 4,006 µs | 286 ms |

- The whole 9.18% is PUT, PATCH and DELETE (888 of 888), blocked by CRS rule 911100; none
  of 8,782 GET, HEAD and POST requests was. Paranoia 2 adds 2.8 points and paranoia 4 blocks 66.6%.
- **The chunked-body case is blocked** on Recommended and High at every paranoia level, as are
  HTTP/2 without a length and the other framings; the one attack that passes is a script tag in a
  multipart filename.
- Cost grows faster than body size: 10 ms of CPU for a 4 KiB body, 81 ms for 64 KiB, and no 1 MiB
  request completed in 5 seconds at Recommended. Ten log-only rules double the cost of a request
  (3,361 to 6,400 µs) through the second scoring pass.
- **Revised target.** "An alert within two heartbeats" is revised: a cell's commits are recorded from
  its first heartbeat, and the cells list, pools list and metric show a mismatch within one scrape.
  The alert fires after 2 minutes, because a cell replaced during a rollout must not page anyone.
  Reason: heartbeats arrive every few seconds and scrapes every 15, so two heartbeats is shorter
  than the shortest observable interval.

**Completion checkpoint, 26 November 2026.**

| Implementation | Documentation | Automated/runtime | Browser |
| --- | --- | --- | --- |
| Complete: corpora, harness, comparison, latency matrix, version display on Cells and Service pools lists | Complete: [WAF verification baseline](operations/waf-verification-baseline.md), cell inventory, runbook, testing, changelog | Passed: harness unit tests on a stub cell, full replay of 13 hosts, 24-case latency matrix, version mismatch on a real cell, alert rule test. One development-host record; fleet qualification stays open | Passed: edge-health, screens-rich and every-screen specs (112 passed, 52 skipped by design, 0 failed) |

---

## Phase 2: Inspection integrity and cost

**Execution plan.** Priority: P1; any reproduced inspection bypass becomes P0.

**Ordered stages.** 1. Requalify existing body admission and fallback. 2. Add bounded matched-rule detail and scoped exclusions. 3. Evaluate a security-equivalent one-pass optimization and retain it only with measured benefit.

**Security, resources and failure boundary.** No engine replacement, partial-body bypass, raw payload logging or unsafe connection reuse. Include headers/body/listener deadlines and temporary bytes in the Phase 1 budget. A rejected candidate keeps the prior valid engine/configuration.

Each stage closes the shared phase gate with the phase-specific tests and
acceptance criteria. Scope not named in this phase is excluded.

**Objective.** Inspect every request that should be inspected, once, at a known
cost, and record why each request was blocked.

**Current state.** Decoded body acquisition, bounded admission and explicit
failure handling are implemented. Scoring still uses a new loopback connection,
and log-only rules can need two passes. Matched CRS detail and scoped exclusions
remain incomplete. A scoring-listener failure leaves inline inspection active;
its unadjusted defaults and tuning differences need explicit qualification.

**Scope.**

- **Body coverage.** Preserve and requalify the decoded-body reader for chunked
  and HTTP/2 requests. Blocking mode refuses oversized, unreadable and
  unsupported-encoded bodies with explicit outcomes. Monitoring may record
  incomplete inspection, but must never label it inspected. A prefix-only
  inspection is not a substitute for complete blocking-mode coverage.
- **One pass.** Prove feasibility of one CRS evaluation with equivalent
  log-only/tuning behavior. Profile copying and loopback setup; reuse
  connections only if request isolation, framing and timeout recovery are
  proved on the pinned connector. Retain the safe existing path if the
  optimization cannot preserve inspection and last-valid behavior.
- **Matched rules.** Up to 8 matched rule IDs and their categories reach the
  cell and the request event for every blocked or logged request. Matched data
  is never included.
- **Scoped exclusions.** A rule or category is turned off for a hostname, path
  prefix, or method. The cell evaluates the scope and passes it to the scoring
  pass through the existing off-list mechanism. Exclusions on a single parameter
  are added only if a mechanism is proven free of the earlier bypass.
- **Explicit failure.** A scoring failure is counted, logged with a reason, and
  alerted. The fallback stays inline inspection, and its effect on tuning is
  documented.

**Technical direction.** Keep ModSecurity and CRS pinned in the image. Change
how the cell hands requests to the engine, not the engine. Every change is
proven on the pinned ModSecurity version, because libmodsecurity's behavior
differs from the reference.

**Dependencies.** Phase 1.

**Acceptance criteria.**

- Every attack in the corpus that is blocked with a `Content-Length` is also
  blocked when sent chunked, and over HTTP/2 with no length.
- A one-pass candidate preserves every detection/failure outcome and records
  p50/p95/p99, CPU/request and peak memory against repeated Phase 1 controls.
  Admit it only for a benefit exceeding measured noise within the predeclared
  resource budget. If infeasible, record the measured duplicate-pass cost and
  the explicit optimization deferral; never remove inspection for a target.
- 100% of WAF blocks in the harness carry at least one matched rule ID besides
  `949110`.
- A path-scoped exclusion stops its rule only on that path. Sending the excluded
  path in a query, header, or encoded form does not trigger it.
- The clean false positive rate at Recommended is no higher than the Phase 1
  baseline.

**Verification.** The harness with the chunked and HTTP/2 variants. E2E for each
exclusion scope and the bypass attempts. A failure injection that stops the
scoring listener and checks the fallback, the counter, and the alert. The
benchmark compared with Phase 1.

**Recorded results, 26 November 2026.**

- **Body coverage (stage 1).** The Phase 1 harness re-run on the Phase 2 image: every attack
  blocked with a `Content-Length` is also blocked chunked, over HTTP/2 over TLS and over
  cleartext HTTP/2 with no length, in query, form, JSON and multipart bodies (all expectations
  met, 0 unmet). The body-admission, failure-policy and scoring-listener-failure qualifications
  (`waf_body_inspection.py`, `managed_waf.py`) pass on the new image.
- **Matched rules (stage 2).** Each scoring CRS rule appends its id when the image is built, the
  scoring listener returns the ids with the points, and the cell keeps at most 8 in the new
  `waf_rule_ids` field. In the re-run, 56,737 of 56,737 blocks (100%) carry a matched rule other
  than `949110`. Categories are the first three digits of the id. A block decided by the inline
  fallback after a scoring failure has no ids and still says `949110`.
- **Scoped exclusions (stage 2).** `tuning.scoped_exclusions`: rules or categories off for a
  normalized path prefix, methods, or both, in the API and the domain WAF dialog. The real-cell
  qualification (`waf_scoped_exclusions.py`) shows a rule stops only inside its scope: a longer
  name, another path, dot segments, case, another method, and the excluded path in the query, a
  header, a path parameter or a later segment do not enter it, and another category still blocks
  inside it. Per-parameter exclusions stay unavailable.
- **Explicit failure (stage 2).** Scoring failures were already logged as `waf_inspection_failed`;
  they are now exported as `cdnfoundry_waf_scoring_failures_recent` and alerted by
  `WafScoringFailing` (rule-tested), with a runbook. The fallback and its unadjusted rules are
  documented.
- **Clean false positives** at Recommended are unchanged against the Phase 1 baseline
  (`compare` reports no regression for all 13 hosts).
- **One pass (stage 3): deferred, with the measured cost.** The duplicate pass exists only when a
  domain has log-only rules: ten of them raise a cached GET from 3,361 to 6,400 µs of CPU
  (+90%). A single-pass candidate is possible in principle, because every scoring rule now
  reports its id and could report its points, letting the cell subtract log-only matches from
  one evaluation. It is not admitted: equivalence with removing the rule is unproven (CRS rules
  read accumulated scores, and per-category and per-paranoia totals would each need the same
  subtraction), and keeping the existing connection for several requests was not evaluated.
  Inspection is not reduced for a target; the two-pass path stays, and domains pay the measured
  cost only when they use log-only rules.
- **Cost against the Phase 1 controls** (medians of three runs, same method): cached GET CPU per
  request 399 vs 436 µs Off, 3,369 vs 2,914 µs Observe, 2,852 vs 3,361 µs Recommended and
  4,245 vs 4,006 µs High; a 4 KiB POST at Recommended 9,077 vs 10,440 µs; peak memory within 2.4
  MiB. The differences run both ways, from -15% to +16%, which is the size of the run-to-run
  spread on this host, so no change in cost is claimed.

**Completion checkpoint, 26 November 2026.**

| Implementation | Documentation | Automated/runtime | Browser |
| --- | --- | --- | --- |
| Complete for stages 1 and 2. Stage 3 deferred with the measured cost recorded | Complete: WAF reference, managed WAF guide, telemetry schema, runbook, monitoring, baseline page | Passed: harness re-run (no regression, 100% of blocks name a rule, 0 unmet evasion expectations), body inspection, managed WAF, scoped exclusion and mismatch qualifications on the new image, Vector and ClickHouse schema checks, alert rule tests, full PHP suite (802 passed), latency subset. Fleet qualification stays open | Passed: `waf-tuning` spec with the new exclusion form (placeholders, tooltips, accessibility, four snapshots); earlier `edge-health`, `screens-rich` and `every-screen` |

---

## Phase 3: Client identity, fairness, and signal fidelity

**Execution plan.** Priority: P0 for F1 exposure; P1 for remaining fairness proof.

**Ordered stages.** 1. Carry forward existing prefix/containment/reporting evidence. 2. Finish and qualify conservative overflow under per-domain and physical memory exhaustion. 3. Requalify tenant isolation, escalation and full-domain reporting.

**Security, resources and failure boundary.** Pull stage 2 forward into Phase 0; do not implement it twice. No synchronous counters or new store. Budget all counter/marker/evidence keys at maximum assigned domains, including the minimum-share floor. Overflow must preserve a stated protection boundary and other tenants, not silently allow new rule keys.

Each stage closes the shared phase gate with the phase-specific tests and
acceptance criteria. Scope not named in this phase is excluded.

**Objective.** Mitigate the client that misbehaves without harming other
visitors or other tenants, and report every domain's events accurately.

**Current state.** Prefix keys, domain budgets, containment and fair reporting
are implemented with historical runtime evidence. Counter-budget overflow still
returns no rule decision for new keys; physical dictionary saturation and the
minimum-share floor also need qualification at maximum assigned-domain count.
Complete and requalify the scope below; do not rebuild existing mechanisms.

**Scope.**

- **One client key** for the cell and the gateway: IPv4 address, or an IPv6
  prefix (default /64, configurable per domain from /48 to /128). It is used by
  built-in limits, rate-limit rules, and challenge binding.
- **Fair memory.** Each domain gets a bounded share of counter memory. At its
  limit, use an explicit conservative domain-scoped fallback with reserved
  capacity, never a silent loss of protection. Measure collateral impact and
  tenant isolation; do not mandate ASN grouping without evidence that it is
  safer than the existing domain fallback.
- **Client penalty box per cell.** A client key that trips repeated escalating
  events on a domain is challenged or blocked for a bounded time on that domain.
  The thresholds and actions are typed settings.
- **Breadth-aware escalation.** The automatic domain state escalates only when
  events come from more than a set number of distinct client keys. Events from
  fewer sources go to the penalty box.
- **Complete reporting.** Heartbeat summaries are bounded per domain and in
  total, ranked by count, and carry distinct client counts, so every domain's
  counts reach escalation and alerts.

**Technical direction.** All of it stays in the cell's shared memory and the
existing overlay and heartbeat paths. No new service. Counters are partitioned
by domain within fixed dictionaries.

**Dependencies.** Phase 1.

**Acceptance criteria.**

- A flood rotating through 1,000,000 IPv6 addresses inside one /64 is limited
  as one client.
- A flood with 1,000,000 distinct keys against domain A does not change when
  domain B's rate-limit rule trips: B's threshold is still met within ±5%.
- A single client generating 1,000 escalating events in 5 minutes does not
  change the domain state, and is penalized within 10 seconds on that cell.
  Traffic from 500 distinct clients does change the state.
- With 200 domains under attack on one cell, every domain's count reaches the
  control plane within two heartbeats.

**Verification.** E2E on a real cell with IPv4 and IPv6 traffic generators. A
cardinality flood test across two domains. A heartbeat test with 200 noisy
domains. Shared memory usage recorded under the flood.

**Completion checkpoint, 21 November 2026.**

| Implementation | Documentation | Automated/runtime | Browser |
| --- | --- | --- | --- |
| Prefix/budget/containment/reporting present; overflow open | Remaining overflow semantics open | Historical fairness passes; saturation matrix open | Changed limit/containment UI requires suite |

**Execution record, 26 November 2026** (development host; edge-runtime image built from this checkout; not a fleet result).

| Item | Result |
| --- | --- |
| Overflow (stage 2) | Already shipped in Phase 0 and not implemented again. Re-qualified: `rate_limit_isolation.py` passed with a new check that the victim's threshold of 100 trips at 100 requests (limit 5%) after a 30,000-key flood on another domain. New `rate_limit_exhaustion.py` passed: 700 domains (past the 650-domain floor) on a 32 KiB dictionary, then 60 fresh keys on one domain allowed 2 (threshold 2, 10 workers), `rate_limit_state_full` reported, no allocation error in the log. The same test also passes on the previous image, so it shows the overflow counter holds under saturation, not that a failed allocation was reached: Nginx evicts the least recently used entry instead of failing, and a failed allocation could not be provoked. A worker-local fallback was written, found untestable and removed. |
| Reporting (stage 3) | Found a defect: a cell listed only 100 entries, always the most recently active domains, and the agent re-sent every domain whose running total had changed, so with 200 domains under sustained attack the same first domains took every heartbeat. The cell now lists up to 1000 entries and the agent sends 100 per heartbeat, the domains reported longest ago first. The Go test with 200 domains and totals that change before every heartbeat reports all 200 within two heartbeats. `security_report_fairness.py` (130 domains) passed against the new limit. |
| Scale figures | Run on 27 November 2026 on a real cell (4 CPUs, 3 workers, wrk as the generator, image built from this checkout): `million_key_floods.py`. 1,410,114 requests from distinct IPv6 addresses inside one /64 (about 28,000 per second) let exactly 100 through against a rate-limit rule of 100, every later address was refused, a different /64 was not limited, and the counter dictionary grew by 0 bytes. 1,415,447 distinct header keys against one domain (50 s) left another domain's rule of 100 tripping at 100 (limit 5%), reported `rate_limit_state_full` against the flooded domain only, and logged no allocation failure; after the flood `rate_rules` held 0.5 MB of its 32 MiB, `security_limits` 0.2 MB and `client_events` 0.2 MB, because per-domain budgets and the overflow counter stop one domain's keys from taking more than its share. The cell now reports each counter dictionary's capacity and free bytes under `shared_memory` in its status. |
| 200-domain heartbeat | `security_report_fairness.py` now runs 200 domains with 300 extra events on one of them on a real cell: all 200 are in the cell's report once, none dropped. The report is then fed to the agent's own selection (`TestARealCellReportOf200DomainsIsSentInTwoHeartbeats`) for two heartbeats with totals that change before each: all 200 domains were sent. The control plane's acceptance of those heartbeats is covered by its feature tests, not by this run. |
| Not run | The 500-distinct-client escalation case on a real cell and control plane together (covered by the control plane's feature tests and `client_containment.py` for the single client). Fleet qualification: needs hosts managed by the Fleet tool, which this single development host is not. |
| Browser | No UI changed. The suite was not run for this item. |

**Completion checkpoint, 27 November 2026.**

| Implementation | Documentation | Automated/runtime | Browser |
| --- | --- | --- | --- |
| Complete: overflow, reporting, shared-memory report; the penalty box and breadth escalation were already present | Current: security guide, edge agent reference, changelog | Passed at the stated scale on a real cell: 1.4 million addresses in a /64, 1.4 million keys, 200-domain report through the agent's selection. Not run: the 500-client case on a real cell with the control plane, and fleet qualification | Not applicable: no UI changed |

Phase 3 is complete except for the two items under "Not run", which need a fleet and a control plane that this host does not have.

--- | --- | --- | --- |
| Complete for overflow and reporting; the penalty box and breadth escalation were already present | Current: security guide, edge agent reference, changelog | Partly passed: the runs above. Open: million-key floods, a real-cell 200-domain heartbeat, fleet qualification | Not applicable to this change; not run |

Phase 3 is not complete: the stated scale figures are open.

---

## Phase 4: Policy lifecycle

**Execution plan.** Priority: P1.

**Ordered stages.** 1. Deliver shadow/expiry through model, API and signed runtime. 2. Add bounded match counts and qualified partial impact estimates. 3. Qualify staged rollout, propagation and revision rollback.

**Security, resources and failure boundary.** No second matcher or capture of unstored secrets. Missing historical fields are unknown, not a zero-match prediction. Bound event bytes, retention, query time and rollout fan-out. Expiry works locally; outage leaves last valid policy, with no stale temporary block beyond expiry.

Each stage closes the shared phase gate with the phase-specific tests and
acceptance criteria. Scope not named in this phase is excluded.

**Objective.** Make every security change testable before it blocks, visible
while it runs, and quick to undo.

**Current state.** Rules apply on save. Revisions can be rolled back. There is
no shadow mode, no expiry, and no per-rule match count. Platform rulesets apply
to all their domains at once.

**Scope.**

- **Shadow mode** for any custom, rate-limit, or platform rule: the cell
  evaluates the rule and records what it would have done
  (`would_block`, `would_challenge`) without acting. A rule can move from shadow
  to enforce in one step.
- **Expiry** on any rule. The cell enforces it by its own clock, so temporary
  blocks end even when the control plane is down.
- **Match counts** per rule, enforced and shadow, by hour, from a ClickHouse
  rollup, on the rule list and in the API.
- **Impact estimate** before saving: what the rule would have matched in the
  last 24 hours, computed only over the fields the request events store, and
  labeled as partial.
- **Staged platform rulesets**: shadow first, then a chosen subset of domains,
  then all.
- **Measured propagation**: the time from saving a policy to it being enforced
  on every cell.

**Technical direction.** Shadow and expiry are rule attributes in the existing
typed model and snapshot. Counts and estimates come from ClickHouse, outside the
request path. Rollback stays the existing revision rollback.

**Dependencies.** Phase 2 (rule references in events), Phase 1.

**Acceptance criteria.**

- A shadow rule never changes a response. Its `would_*` count matches an
  enforce-mode run of the same traffic within 1%.
- An expiring rule stops acting within 2 seconds of its expiry, with the control
  plane stopped.
- Propagation p95 is 60 seconds or less from save to enforcement on all cells
  in a two-edge test. The measured value is documented.
- A rollback restores the previous behavior, proven by the harness.

**Verification.** Feature tests for the new attributes, permissions, and
idempotency. E2E for shadow, expiry, and rollback. A ClickHouse query test
against a counted sample. Browser tests for the shadow badge, the counts, and
the estimate.

**Completion checkpoint, 21 November 2026.**

| Implementation | Documentation | Automated/runtime | Browser |
| --- | --- | --- | --- |
| Lifecycle scope open | Behavior and operator guide open | Shadow/expiry/rollback matrix open | Rule lifecycle gate open |

**Execution record, 26 November 2026** (development host; edge-runtime image built from this checkout).

| Stage | Result |
| --- | --- |
| 1. Shadow and expiry | Delivered for custom and platform rules: `mode` and `expires_at` through the migration (with database checks that only block and challenge rules can be shadow rules), validation, API, the signed snapshot and the cell. Rollback restores both. Feature tests (7, plus the rollback case) passed. E2E `shadow_and_expiring_rules.py` passed on a cell with no control plane: shadow counted 60 against 60 enforced blocks, no response changed, a shadow rate limit never limited, and an expiring rule stopped 0.07 s after its expiry (limit 2 s). |
| 2. Match counts and impact estimate | Delivered. The cell names the shadow rules that matched in each request's log line (`shadow_rule`), Vector validates it, and two materialized views fill `rule_matches_hourly` from it and from the enforced `security_rule`. The rule list and `GET .../security/rules` show 24-hour counts per rule, enforcing and shadow separately, and `null` rather than 0 when ClickHouse cannot answer. `POST .../security/rules/estimate` counts over the stored request fields only, is always labeled partial, and names unknown fields instead of guessing. Feature tests (5) passed. E2E `rule_match_counts.py` passed against a real ClickHouse and a counted sample (rollup counts for both modes exact; the PHP estimate code returned 10, 7, at most 10 with the header condition unknown, and 9). The cell e2e `shadow_and_expiring_rules.py` also proves the log line carries `shadow_rule` for shadow matches and `security_rule` for enforced ones. |
| 3. Staged rollout and propagation | Delivered. A platform ruleset has `rollout_stage` (`shadow`, `subset`, `all`) and `enforced_domain_ids`; blocking and challenging rules record only where the stage says so, other actions act as written, and a stage change is one saved change and one chunked rollout. Feature tests (5) passed. Propagation was measured with `policy_propagation.py` on the development stack's real control plane, queue and two edge agents: 12 trials, each saving one rule and timing until both agents logged activation of the generation carrying it. Save to activation on both edges: p50 7.1 s, p95 12.6 s, max 12.6 s (bound 60 s). Rollback to the previous revision, with the rule gone from the list, was active on both agents after p50 8.1 s, p95 10.0 s. Limit: the stack's domain is not placed on a cell, so this times the control plane and the agents' activation of the signed generation, not a request answered by a cell; the cell's own reload is timed by `cell_generation_reload.py`. |
| Browser | Delivered: the rule sheet has a mode field (offered for block and challenge only), an end time and an "Estimate the last 24 hours" button with a labeled partial result; the list has a Shadow badge, the end time and a per-rule 24-hour count; platform rulesets have a rollout field, an "Enforce on" picker and a rollout badge. The antislop Delivery Gate was applied: the screens reuse the existing sheet and table patterns, show no invented numbers (the estimate and counts come from ClickHouse and say "-" or "unknown" when it cannot answer), keep labels short with tooltips, and the new controls are native or Filament comboboxes with accessible names. The full run of the firewall rules, platform rulesets and security events specs passed (60 passed in light and dark, desktop and phone, with the accessibility check), after two spec corrections; the three new specs were rerun and passed (12). The rest of the suite was not rerun for this increment. |

**Completion checkpoint, 26 November 2026.**

| Implementation | Documentation | Automated/runtime | Browser |
| --- | --- | --- | --- |
| Done: shadow and expiry, match counts and estimate, staged rollouts, UI | Current: rule reference, security guide, telemetry schema, data model, API reference, changelog | Passed: feature tests, real-cell shadow and expiry e2e, ClickHouse rollup and estimate e2e, propagation p95 12.6 s on two edge agents. Not proven: propagation to a request answered by a cell on the stack (its domain has no cell placement), and the full PHP suite after the last UI edits ran only for the security, platform and panel groups | Passed for the three affected specs; the other specs were not rerun |

Phase 4 is complete except for the stated limits above.

---

## Phase 5: Signed security datasets

**Execution plan.** Priority: P1 for safe delivery when required; P2 for expanded datasets.

**Ordered stages.** 1. Prove feasibility and admission limits using the existing artifact channel. 2. Deliver one justified typed dataset with provenance, expiry and revocation. 3. Qualify max-size lookup, memory, rotation and corrupt/stale candidate refusal.

**Security, resources and failure boundary.** No generic feed platform, runtime download or mandatory new provider. Set per-type/per-cell budgets from the reference host before implementation. Keep last valid nonexpired data; expiry semantics must be explicit per type and cannot preserve a revoked allowlist indefinitely.

Each stage closes the shared phase gate with the phase-specific tests and
acceptance criteria. Scope not named in this phase is excluded.

**Objective.** One safe way for large, changing security data to reach cells,
without a release and without any runtime download.

**Current state.** The leaked-password list is built into the image. GeoIP and
ASN databases are files on the host. Rule lists hold 100 values each. No
general dataset channel exists.

**Scope.**

- A **dataset artifact**: typed (CIDR list, hash set), versioned, signed by the
  control plane's artifact key, with a fixed maximum size per type. Cells hold
  it in a fixed-memory structure with lookups in logarithmic time or better.
- **Named IP lists** that customers and administrators maintain (up to a
  platform limit, for example 10,000 entries per domain list), usable from rules
  with `in_list`.
- **Platform datasets**: published search engine crawler ranges, a larger
  leaked-password corpus sized from measured detection benefit and memory, and,
  only if admitted, the greylist
  (Phase 8). Sources come with a release or from an import an administrator
  enables in the control plane. A cell never fetches anything.
- Per-cell dataset versions in the heartbeat (built on Phase 1).

**Technical direction.** Reuse the signing, agent delivery, and atomic
activation already used for snapshots. A dataset that fails validation is
refused, the last valid version stays active, and the failure is alerted.

**Dependencies.** Phase 1, Phase 3 (memory bounds), Phase 4 (propagation evidence).

**Acceptance criteria.**

- A 10,000-entry list adds under 20 µs at p99 per request lookup, measured.
- A corrupted, oversized, or wrongly signed dataset is refused on every cell,
  and the previous version keeps matching.
- A list change reaches every cell within the Phase 4 propagation bound.
- The cell's memory stays within the stated budget with every dataset at its
  maximum size.

**Verification.** Go tests for signing and refusal. Lua tests for lookups. E2E
for activation, refusal, and last valid state. A memory measurement at maximum
size.

**Stage 1 evaluation, 26 November 2026** (development host; the cell's own `ip_list.lua` under the edge-runtime image's LuaJIT, `tests/e2e/ip_list_feasibility.py`).

| Question | Finding |
| --- | --- |
| Can the existing artifact channel carry a list? | Yes. The domain artifact's `security` object is passed to the cell unchanged by the agent, so a compiled list travels in the signed domain snapshot with the existing signing, size-bound request (16 MB), atomic activation and last-valid retention. No new transport, no runtime download. A separate artifact kind would avoid re-sending a list with unrelated domain changes; it is not needed at the admitted sizes and stays an option if the measured cost below is found too high. |
| Representation | The control plane compiles a list to sorted, non-overlapping ranges: IPv4 as whole numbers, IPv6 as four 32-bit words per address. A cell answers by binary search. An earlier IPv6 form (hex strings) measured p99 28 to 575 µs and was dropped. |
| Lookup time, 10,000 ranges | p99 1.3 to 3.1 µs (IPv4, IPv6, and both together), p50 0.7 to 1.2 µs, against the 20 µs bound. The timer overhead is subtracted; maximums of 0.4 to 1.8 ms are single outliers on a loaded host. |
| Size and load | One list at 10,000 ranges adds 215 KB (IPv4), 614 KB (IPv6) or 414 KB (5,000 of each) of snapshot JSON, decodes and validates in 2.7, 9.1 and 6.0 ms, and holds 0.27, 1.06 and 0.66 MB per worker. Every worker holds its own copy and a cell runs at most 8 workers, so one maximum IPv6 list costs about 8.5 MB per cell. |
| Admission limits chosen | 10,000 ranges per list, 5 lists per domain, and a per-cell budget across all domains that the agent enforces by refusing the artifact that would exceed it. The first estimate of 200,000 ranges was disproved by the stage 3 measurement on a real cell (73 to 92 MB per worker, so up to about 730 MB per cell) and lowered to 100,000 ranges. |
| Refusal | The cell-side validator refuses unsorted, overlapping, odd-length, wrongly sized, negative and over-maximum lists (6 cases tested). The agent will run it before activation so a corrupt list rejects its domain's artifact and the previous state keeps serving. |
| Justified first dataset | Named IP lists for a domain, usable from rules, because rule lists are capped at 100 values and customers need allow and deny lists in the thousands. Platform datasets (crawler ranges, a larger leaked-password corpus, the greylist) are not admitted in this increment: each needs a source, a measured benefit and a revocation rule of its own. |

**Execution record, 27 November 2026** (development host).

| Stage | Result |
| --- | --- |
| 1. Feasibility and admission limits | Done, recorded above. |
| 2. One typed dataset with provenance, expiry and revocation | Delivered for named IP lists: `ip_lists` (JSONB entries with a database bound of 10,000 and a name check), validation and canonical form (`IpListData`), API (`PUT`, `PATCH`, `GET`, `DELETE` under `/security/ip-lists`, idempotent, `202` only when an enabled rule uses the list), the `client_ip_list` rule field, compiled ranges in the signed snapshot only for lists an enabled rule uses, agent validation before activation, and the cell lookup. Provenance is the audit log entry of every change (who, list, entry count, revision); revocation is deleting the entry or the list, which reaches the cells as one revision; a list has no expiry of its own, the rule that uses it can expire (Phase 4). Feature tests (9) and agent tests (4, plus the full agent suite) passed. |
| 3. Max-size lookup, memory, rotation, refusal | `ip_list_feasibility.py`: lookup p99 1.3 to 3.1 µs at 10,000 ranges. `ip_lists_cell.py` on a real cell: matching agreed with an independent reference on 600 random IPv4 and IPv6 clients for a deny list and an allow list; a changed list took effect in 0.5 s; a torn runtime file left the previous list matching; a cell with 100,000 IPv6 ranges (the cell budget, 8.1 MB of runtime file) grew each worker by about 46 MB against 64 MB allowed. Corrupt, unordered, oversized and over-budget lists are refused by the agent (14 cases tested) and the previous state keeps serving. |
| Not delivered | Platform datasets (crawler ranges, a larger leaked-password corpus, the greylist): not admitted in this increment, each needs a source, a measured benefit and a revocation rule. Per-cell dataset versions in the heartbeat. Rotation of a list is a normal list change; there is no separate rotation mechanism. A rollback restores rules, not lists. |

**Completion checkpoint, 27 November 2026.**

| Implementation | Documentation | Automated/runtime | Browser |
| --- | --- | --- | --- |
| Named IP lists done end to end; platform datasets and heartbeat dataset versions not delivered | Current: security guide, rule reference, API reference, changelog, data model | Passed: PHP suite, agent suite, real-cell matching, change, torn-file and memory runs | Passed for the IP lists spec (3 specs in light and dark, desktop and phone, 12 passed, with accessibility checks) and the firewall rules specs (44 passed in the same run); the rest of the suite was not rerun |

Phase 5 is complete for named IP lists only.

---

## Phase 6: Rate limiting

**Execution plan.** Priority: P1 after F1 is closed.

**Ordered stages.** 1. Deliver measured window/key semantics. 2. Add bounded response-outcome counting for login/API abuse. 3. Qualify per-cell and approximate shared modes under skew, membership change and outage.

**Security, resources and failure boundary.** No exact-global-quota claim or request-path coordination. Bound key length/cardinality and hashed values. Traffic on one of three cells must not be assessed only by a balanced-load test. Preserve fallback budgets and state error bounds, underutilization and recovery behavior.

Each stage closes the shared phase gate with the phase-specific tests and
acceptance criteria. Scope not named in this phase is excluded.

**Objective.** Rate limits that follow real abuse patterns, are accurate at
window edges, and give a stated approximation across cells.

**Current state.** Fixed windows of 10 s, 1 min, and 10 min, per cell. Keys are
address, address and path, or header. The actions are block, challenge, and log.
Only requests are counted.

**Scope.**

- **Sliding window approximation** (weighted current and previous window) for
  rate-limit rules.
- **More keys**: client key (Phase 3), ASN, country, cookie value, query
  argument, and combinations of two. Header and cookie values are hashed.
- **Counting on outcome**: a rule can count only responses with chosen statuses,
  for example 401 and 403 on a login path, counted in the log phase.
- **Responses**: 429 with `Retry-After`, challenge, or block, with a
  machine-readable body for non-HTML clients.
- **Multi-cell modes**: `per_cell` (today), and `shared`, where each cell
  enforces the threshold divided by the number of cells serving the domain.
  Long windows (1 hour, 24 hours) are aggregated by the control plane from
  heartbeat summaries and sent back as client-key mitigations. The documentation
  states the error bound of each mode.

**Technical direction.** Enforcement stays in cell shared memory. Cross-cell
aggregation is asynchronous and only adds mitigations; it never delays a request.

**Dependencies.** Phase 3, Phase 4, Phase 5 (lists as keys and exemptions).

**Acceptance criteria.**

- On one cell, a rule trips within ±5% of its threshold for steady traffic and
  within ±10% for traffic around window edges.
- In `shared` mode over three cells with balanced traffic, admitted requests
  stay at or below 120% of the threshold.
- A credential-stuffing run (5,000 failed logins from 200 addresses) is stopped
  by an outcome rule, and successful logins from the same NAT address are never
  counted.
- Long-window mitigations reach all cells within 5 minutes.

**Verification.** E2E on one and three cells with IPv4 and IPv6. A replayed
login abuse scenario. A memory bound test at maximum keys. Browser tests for the
new rule options.

**Completion checkpoint, 21 November 2026.**

| Implementation | Documentation | Automated/runtime | Browser |
| --- | --- | --- | --- |
| Fixed limits present; enhancements open | Approximation/error semantics open | Skew/outcome/saturation matrix open | New rule-option gate open |

**Execution record, 26 November 2026** (development host).

| Item | Result |
| --- | --- |
| Built | Sliding windows, cookie, query-argument, ASN, country and two-part keys, 1 hour and 24 hour periods, outcome counting by response status in the log phase, a shared scope that divides the threshold by `cells_serving`, `Retry-After` and a JSON 429 body, the typed validation and the rule sheet fields. |
| Real-cell run | `rate_limit_semantics.py` passed: steady traffic within 5%, a boundary burst held to 100 of 200 by the sliding window (the fixed window let 200 through), 5,000 failed logins from many addresses stopped with at most 6 reaching the origin for one address (1,200 in all), and three cells at threshold 300 admitted 300 when balanced in `shared` mode, 100 when all traffic hit one cell, and 900 in `per_cell` mode. `rate_limit_isolation.py`, `rate_limit_exhaustion.py` (2 of 60 allowed after exhaustion) and `ipv6_rate_limit_keys.py` passed. |
| Browser | `specs/firewall-rules.spec.ts`: 44 passed in light and dark at desktop and phone width, with the new rate-limit option specs. |
| Long windows | `shared` rules of 1 hour and 24 hours are added up by the control plane from heartbeat reports (`cdnf:rate-limits:aggregate`, every minute) and returned as client-key mitigations through the agent. `rate_limit_long_window.py` on three real cells: 40 requests on each of three cells (120 in all, threshold 90, cell count 1) were admitted until the mitigation arrived, then refused on all three while another client was not; a restarted cell was given the mitigations again; a mitigation ended by the cell's clock; an older version and malformed commands were refused; 60 heavy clients reported 50 keys. `LongWindowRateLimitsTest` covers the heartbeat intake, the aggregation, the bounds and delivery to the serving edges only; Go tests cover the agent. The chain is one heartbeat, one minute of aggregation and a 30 second delivery cycle, so the 5-minute bound holds by construction; it was not timed through a full control plane with real edges. |
| Credential stuffing | 500 successful logins from one NAT address were never counted, and 5 failures from it were then blocked (`rate_limit_semantics.py`). |
| Not done | Browser tests cover the rule options (rate-limit sheet); no UI was added for the aggregation. A timed run of the long-window chain through the full development stack is not recorded. |

Phase 6 is complete within the limits stated in the security guide: counts are per cell, `shared` is an approximation, and long-window aggregation adds mitigations only.

| Implementation | Documentation | Automated/runtime | Browser |
| --- | --- | --- | --- |
| Complete | Complete (windows, keys, outcome counting, shared scope, long windows with error bounds) | Complete: semantics, isolation, exhaustion, IPv6 keys, long-window e2e, PHP and Go tests | 44 passed (`firewall-rules.spec.ts`) |

---

## Phase 7: Challenge and bot mitigation

**Execution plan.** Priority: P1 for token correctness; P2 conditional for new bot signals.

**Ordered stages.** 1. Qualify pool-wide encrypted keys, rotation, revocation and domain/client binding. 2. Qualify API/mobile/accessibility behavior and bounded solve cost. 3. Admit crawler/fingerprint/bot signals only after provenance, privacy and false-positive experiments.

**Security, resources and failure boundary.** Token portability does not make token budgets globally exact. Protect secrets from customer-visible snapshots; bound retained keys, tokens and solve CPU. Keep valid overlap keys during rotation; reject untrusted keys. Fingerprints or solved work are not proof of humanity, and crawler trust cannot bypass unrelated security.

Each stage closes the shared phase gate with the phase-specific tests and
acceptance criteria. Scope not named in this phase is excluded.

**Objective.** A challenge that works across cells and PoPs, adapts to risk,
does not break APIs or mobile clients, and is backed by explainable bot signals.

**Current state.** Proof of work uses a per-cell key without the planned
rotation lifecycle. Tokens bind to the client prefix key, with optional UA
binding, and incident commands accept bounded bypass-prefix settings. Qualified
pool-wide portability, verified crawler data and bot signals are still absent.
The historical exact-address/mandatory-UA description is obsolete.

**Scope.**

- **Pool-wide keys with rotation**: challenge keys issued by the control plane,
  delivered in signed bundles with a key ID, two keys active during a rotation.
  A token works on any cell that serves the domain, in any PoP.
- **Binding** to the client key (Phase 3) and the user agent family, so a client
  that changes address inside its prefix keeps its token. Each token has its own
  request budget, so a token shared by a bot farm stops working.
- **Adaptive difficulty** from the penalty box (Phase 3), optional reputation (Phase 8),
  and the domain state, capped by a measured solve time. A missing/deferred
  reputation signal is neutral; Phase 7 must not depend cyclically on Phase 8.
- **API-aware behavior**: non-HTML requests get a machine-readable refusal with
  the reason. Each domain chooses its own bypass prefixes and methods for under
  attack mode, instead of the fixed list.
- **Verified crawlers**: a request whose user agent claims a known crawler and
  whose address is in that crawler's published ranges (Phase 5) gets
  `verified_bot` and a category. The same claim from outside the ranges gets
  `fake_crawler`. Verified crawlers skip the challenge by default.
- **TLS fingerprint**: the gateway already parses the ClientHello. It computes a
  JA4-style fingerprint and passes it to the cell in a PROXY v2 TLV. Without a
  gateway the field is empty.
- **Bot signals**: a `bot_score` from named, readable components (the
  fingerprint does not match the claimed browser, browser headers are missing,
  automation markers, a fingerprint in a known automation dataset, a verified
  crawler). The components are logged, and the score is a rule field.

**Technical direction.** No third-party CAPTCHA and no outside service on the
request path. Key material is never in snapshots the customer can read and never
logged. The challenge page meets the browser suite's accessibility checks. Its
copy says that JavaScript is needed and what the visitor can do.

**Dependencies.** Phase 3, Phase 5, Phase 6.

**Acceptance criteria.**

- A token issued on one cell is accepted on another cell in another PoP. A key
  rotation re-challenges no client whose token is still valid.
- A mobile client moving between addresses in one /64 keeps its token.
- Solve time p95 at maximum difficulty is 3 seconds or less on the slowest device
  in the test matrix.
- A headless flood (scripted HTTP clients and a headless browser) does not get
  through more than the measured fraction stated in the documentation. Real
  browsers in the browser suite pass on desktop and phone.
- A verified crawler reaches the origin under attack mode. A fake crawler does
  not.
- `bot_score` marks under 1% of the clean corpus as automated at the default
  threshold.

**Verification.** Go tests for the fingerprint and TLV. Lua tests for token and
key handling. E2E across two edges. The harness with bot and clean corpora.
Browser and accessibility tests of the challenge page. An external review of
the token and key design, included in Phase 11.

**Completion checkpoint, 21 November 2026.**

| Implementation | Documentation | Automated/runtime | Browser |
| --- | --- | --- | --- |
| Local challenge present; portability/rotation open | Key/failure/accessibility contract open | Cross-cell/rotation/clean-client matrix open | Challenge mobile/visual/accessibility gate open |

**Execution record, 26 November 2026** (development host).

| Item | Result |
| --- | --- |
| Stage 1: keys | Pool-wide challenge keys issued by the control plane (one signing key, one verifying key during a 2 hour overlap), rotation after 30 days, administrator rotate and revoke endpoints, keys sealed to each edge and fetched by the agent, verification and expiry by the cell's own clock, a per-token request budget (`challenge_token_limited`). Seeds now carry a random nonce, so two page loads of one client in one second no longer share a seed (the second was refused as a replay). `challenge_keys.py`, `challenge_binding.py`, `ChallengeKeysTest` and the Go tests pass. |
| Stage 2: behavior | Adaptive difficulty from the client's recent security events and the domain state, capped (17 bits by default, `EDGE_CHALLENGE_MAX_BITS`), inside the signed seed. The solver is plain synchronous JavaScript in up to three Web Workers (about 20 times faster than the earlier per-hash `crypto.subtle` loop, which took 8 to 11 seconds at 16 bits on a throttled thread) and needs no secure context. A domain chooses the path prefixes and methods that skip the challenge when it starts under attack mode (API field, panel dialog, `incident_bypass_*` columns, agent and cell validation). Machine-readable refusals for non-page requests were already present. `challenge_adaptive.py`, `challenge_flood.py`, `IncidentProfileTest`, Go and Lua paths pass. |
| Solve time | One worker, 17 bits, 15 solves on a real cell: p95 1.13 s. Solver speed on a thread throttled six times: 147,551 hashes a second, so p95 at 17 bits is 2.66 s (3 s or less), and 18 bits would be about 5.3 s, which is why the cap is 17. Chromium's CPU throttle does not slow a worker, so the slow device is derived from the throttled hash rate, not measured end to end; this is stated in the security guide. |
| Browser | `challenge-specs` (disposable cells, `tests/browser/run-challenge.sh`): 30 passed and 6 skipped (the two timing tests run once) in light and dark at desktop and phone width: page text, accessibility scan, visual snapshots, no horizontal overflow, solving without workers, two tabs, no JavaScript, refused and unreachable states, cookie flags. |
| Flood | 2,000 requests from scripted clients that never solve: 0 reached the origin. Clients that implement the solver: 3,000 of 3,500 (the token budget refused 500). One token on 30 other addresses: 0 of 30. |
| Stage 3 | Not admitted. Verified-crawler ranges need a maintained source, a freshness rule and revocation (a stale list turns a real crawler into a blocked one); a TLS fingerprint and a `bot_score` need a corpus of real traffic that shows under 1% of real visitors flagged, and none exists here. Customers can reach the same result with an IP list of the ranges a search engine publishes and an `allow` rule (documented in the security guide). A synthetic corpus would not be evidence. |
| Two real edges | `challenge_keys_two_edges.py` on the development stack (real control plane, two real agents, their real cells): both edges held the issued version, a rotation reached both in 61.8 seconds (the agents refresh once a minute), and a revocation reached both; no cell status held a secret. Token portability between cells holding keys is `challenge_keys.py`. |
| Token across two real edges | `challenge_token_two_edges.py` on the development stack (a real proxied domain served by the shared cell of both edges, through both gateways, under attack mode started by the admin API): one client address solved the challenge on edge A and the token was accepted on edge B (the answer was the development origin's own 502, not the challenge), the reverse direction passed, both tokens carried the same key ID, the same address without the token was still challenged on the other edge, and the token was refused for another address on both edges. The client address was a second loopback address on the host, so both gateways saw one peer. Run time about 15 seconds. |
| Not done | Real phones were not tested; the slow device is the throttled-thread figure above. |

Phase 7 stages 1 and 2 are complete within the stated limits; stage 3 is deferred, not partly done, and the phase is complete only on that understanding.

| Implementation | Documentation | Automated/runtime | Browser |
| --- | --- | --- | --- |
| Stages 1 and 2 complete; stage 3 deferred | Complete for stages 1 and 2 (keys, difficulty, bypass, solver, measured solve time and flood table) | Complete, including the cross-edge token run through a real domain | 30 passed (challenge page); incident dialog spec per the run below |

---

## Phase 8: Client reputation and shared threat intelligence

**Execution plan.** Priority: P2 conditional; not a core release prerequisite.

**Ordered stages.** 1. Run a poisoning/privacy/NAT feasibility experiment. 2. If admitted, deploy opt-in shadow summaries with decay and bounded retention. 3. Enforce only after independent multi-tenant and outage evidence.

**Security, resources and failure boundary.** No new service or synchronous lookup. A source tenant must not be able to punish another through fabricated or low-confidence evidence. Cap sources, aggregation CPU, entries and TTL; expire stale influence and fall back to local protection. If feasibility fails, explicitly defer the feature.

Each stage closes the shared phase gate with the phase-specific tests and
acceptance criteria. Scope not named in this phase is excluded.

**Objective.** A client that attacks one domain or PoP is treated with suspicion
everywhere within minutes, without blocking shared NATs, without exposing one
tenant's data to another, and without making a request wait on a remote system.

**Current state.** Nothing is shared between cells. The penalty box (Phase 3) is
per cell and per domain.

**Scope.**

- Cells report a bounded summary of their top offending client keys with reason
  counts, every heartbeat.
- The control plane aggregates the summaries over a sliding window across
  edges, PoPs, and domains, and assigns each client key a reputation band that
  decays over time. It publishes the result as a greylist dataset (Phase 5) with
  a TTL on each entry.
- Cells expose `client_reputation` (a band) and `greylisted` as rule fields. The
  platform default is to challenge greylisted clients, never to block them.
  Only explicitly enrolled domains participate; cross-tenant influence is off
  until poisoning and NAT-safety qualification passes. Stricter handling must
  not turn reputation alone into an unreviewed platform-wide block.
- **NAT and crawler safety**: a key with a high share of clean traffic or solved
  challenges is excluded or capped. Verified crawlers are never greylisted.
- **Privacy and isolation**: client addresses in the aggregate are kept for 7
  days at most. A customer sees "platform reputation" as the cause, never the
  other domain or tenant.

**Technical direction.** This builds only on the heartbeat, the control plane
queues, and the dataset channel. There is no cell-to-cell traffic and no
synchronous lookup. When the control plane is down, cells keep the last greylist
until its entries expire, then fall back to local protection only.

**Dependencies.** Phase 3, Phase 5, Phase 6, Phase 7.

**Acceptance criteria.**

- A client attacking domain A through three PoPs is challenged on domain B in
  another PoP within 5 minutes at p95.
- In a NAT simulation (one address, 95% clean traffic from many browsers, 5%
  attack), the address is not greylisted.
- With the control plane stopped, greylist entries expire on schedule and no
  request is blocked by a stale entry after its TTL.
- No customer-facing API or screen shows another tenant's domain or data.

**Verification.** E2E on a two-PoP staging stack. NAT and crawler simulations
in the harness. Policy tests for tenant isolation. A retention test for the
aggregate.

**Stage 1 record, 26 November 2026** (feasibility model, `reputation_feasibility.py`; a seeded model of the aggregation rule on constructed traffic, not a measurement on real visitors).

| Question | Result |
| --- | --- |
| Time to act | One attacker on one domain through three edges reaches a cell on another edge in 125 s at p50 and 179 s at p95 (budget 300 s), assuming 60 s for each of report, aggregation and delivery (the agents' measured refresh is 61.8 s). The report interval is an assumption until the heartbeat path is measured. |
| NAT | A key with 5% attack traffic is not greylisted. Without any solved challenge a key at 50% attack or more is. A solved challenge exempts the key in this rule, and that exemption is also an escape: an attacker that solves once is never greylisted. The exemption has to be tied to the share of clean traffic, not to one solve. |
| Poisoning | The acceptance case (one domain attacked through three edges) and a poisoner (one tenant's page makes a victim's browser hit that tenant's own domain through three edges) produce the same signal. Corroboration by distinct edges does not separate them, and corroboration by distinct domains only moves the bar to a poisoner with two domains. No threshold on these signals tells the two apart. |
| Harm bound | The consequence is a challenge, never a block, so a poisoned victim pays one proof of work per domain (p95 about 2.7 s on the slowest device in Phase 7) until a solve or the entry TTL ends it. The bound comes from the consequence, not from the rule. |
| State | At most 50 keys per cell report, 200 cells, a 5 minute window: 50,000 rows, about 4.8 MB. Published entries capped and carrying a TTL; address-bearing aggregates kept 7 days at most. |

Finding: feasible as a challenge-only, opt-in, shadow-first signal with bounded state. Not feasible as an enforcement signal, because poisoning cannot be told from a real multi-edge attack on one domain. Admitting stage 2 (shadow summaries, no effect on traffic) is the owner's decision; stage 3 (enforcement) needs the independent multi-tenant evidence the plan already requires, and the exemption rule above has to change first.

**Stage 2 record, 27 November 2026** (shadow summaries; admitted by the owner's request to implement this phase).

| Item | Result |
| --- | --- |
| Built | The domain opt-in (`domains.reputation_sharing`, API and the domain page's "Platform reputation" dialog), a cell dictionary of offending clients (`client_reputation`, 2 MiB, at most 1,000 client and domain pairs per five minutes) reported as the 50 heaviest in the heartbeat, agent forwarding, intake that drops reports of domains that did not opt in, `cdnf:client-reputation:aggregate` (two or more cells, events at least half of the requests seen, bands at 50, 200 and 1,000, an hourly half-life, 7 day and 5,000 client bounds) and an administrator list. Nothing is published to a cell. |
| Tests | `ClientReputationShadowTest` (7: sharing across cells, NAT share, opt-in and opt-out deletion, decay, bounds and malformed entries, heartbeat intake and tenant isolation, permissions); Go test for the agent's collection bounds; `client_reputation_report.py` on a real cell image (IPv4 and IPv6 prefix keys, ranking, the 50 bound, 1,500 distinct clients with free memory and no allocation errors). |
| Not done | Stage 3 (enforcement, the `client_reputation` and `greylisted` rule fields, the greylist dataset): the stage 1 finding stands, poisoning cannot be told from a real multi-edge attack, and the independent multi-tenant evidence does not exist. Verified-crawler exclusion. The two-PoP time-to-act measurement and the TTL expiry on a stopped control plane, which need a published list. An administrator screen for the bands (the API is the only reader). |

Phase 8 is complete for stages 1 and 2 only. Stage 3 is deferred, not partly done.

**Completion checkpoint, 21 November 2026.**

| Implementation | Documentation | Automated/runtime | Browser |
| --- | --- | --- | --- |
| Stages 1 and 2 complete; stage 3 deferred | Complete: security guide section, CLI reference, changelog | Passed: stage 1 model; stage 2 PHP and Go tests and the real-cell run. Not run: two-PoP timing, TTL expiry with a stopped control plane (no list is published) | See the run recorded below |

---

## Phase 9: API and account protection

**Execution plan.** Priority: P1 for schema safety; P2 conditional for JWT/discovery.

**Ordered stages.** 1. Finish bounded JSON/body schema semantics and test incomplete inspection. 2. If admitted, qualify uploaded-key JWT algorithm/issuer/audience/expiry and rotation. 3. Admit discovery or YAML only for demonstrated demand and safe compilation.

**Security, resources and failure boundary.** No remote JWKS on the request path, unbounded schema recursion or credential logs. Reuse typed compiled tables; budget compile time, bytes, fields and validation CPU. A malformed candidate preserves the previous schema/key set; expired credentials never become valid during control outage.

Each stage closes the shared phase gate with the phase-specific tests and
acceptance criteria. Scope not named in this phase is excluded.

**Objective.** Positive security for APIs and protection against account
takeover, both decided at the edge.

**Current state.** The API schema checks paths, methods, and parameters, and the
body for size only. Only OpenAPI JSON is accepted. The leaked-password list is
small. There is no JWT validation and no endpoint discovery.

**Scope.**

- **JSON body validation** against the schema: types, required fields, enums,
  and unknown fields, with bounded depth, field count, and body size. Cookie
  parameters. YAML documents are accepted and converted in the control plane.
- **JWT validation** at the edge for marked paths: signature against keys the
  customer uploads (no runtime JWKS fetch), `exp`, `nbf`, `iss`, and `aud`. Sets
  `jwt_valid`, plus a hashed subject usable as a rate-limit key (Phase 6).
- **Account takeover**: login outcome counting (Phase 6) together with the
  large leaked-password corpus (Phase 5). Usernames and passwords are never
  logged.
- **Endpoint discovery**: the control plane groups observed paths into
  templates from request events and offers them as a starting schema.

**Technical direction.** Compile the schema and keys in the control plane into
bounded tables in the snapshot. The cell never parses OpenAPI. Every new check
starts in shadow mode (Phase 4).

**Dependencies.** Phase 4, Phase 5, Phase 6.

**Acceptance criteria.**

- On an API clean corpus, body validation marks under 0.5% of requests, and adds
  under 1 ms p99 for 16 KiB bodies.
- Tokens that are expired, wrongly signed, or for another audience are refused,
  and valid tokens pass, with IPv4 and IPv6.
- A leaked password from the large corpus sets the flag, and no log or event
  holds it.
- Discovered templates cover at least 90% of the requests in a test API's
  traffic.

**Verification.** Feature tests for compilation and refusal. Lua tests for
validation. E2E per check. Harness measurements of false positives and latency.
Browser tests of the schema and JWT screens.

**Record, 27 November 2026** (stages 1 and 2; admitted by the owner's request to implement this phase).

| Item | Result |
| --- | --- |
| Stage 1: bodies and cookies | The API schema compiles each operation's JSON `requestBody` into a bounded tree (5 levels, 64 fields per body, 32 properties per object, 16 enum values, 2,000 fields in all; `$ref` only inside `#/components/schemas`, cycles refused) and the cell checks JSON bodies for type, `required`, `enum`, `maxLength`, nested objects and arrays, and unknown fields of objects that set `additionalProperties: false`. Cookie parameters are read from the `Cookie` header. New violations `invalid_body` and `unknown_field` and the events `schema_invalid_body` and `schema_unknown_field` start as events and a rule field only. Combinators (`allOf`, `oneOf`, `anyOf`) are not interpreted and accept any value; `null` inside a body is accepted; the cell visits at most 1,000 values and does not check the rest of a larger valid body. |
| Stage 1: measured | `application_protection.py` on a real cell: every body violation kind refused through a rule, valid bodies, `text/plain` bodies, a 1,400-line valid body and cookie parameters pass, and a 16 KiB body added 0.2 ms at the median (7.76 ms with the schema against 7.58 ms without). The 0.5% false-positive rate on an API clean corpus and p99 under 1 ms were not measured: no API corpus exists in the repository, and the run measures the median only. |
| Stage 2: JWT | `jwt_validation` on the domain (up to 8 public keys, RS256 and ES256, paths, issuer, audience, allowance, per-key end time), the rule field `jwt_valid`, the event `jwt_invalid` and the counting key `jwt_subject`. `jwt_validation.py` on a real cell: valid RS256 and ES256 tokens (named and unnamed key ID, audience as a list, inside the clock allowance) pass; absent, expired, not yet valid, no expiry, wrong issuer, wrong audience, a foreign key (also with a known key ID), `none`, HS256, an RS256 token naming an ES256 key, an unknown key ID, a critical header, a tampered payload, a short ES256 signature, malformed and oversized tokens, and a non-bearer scheme are refused; a key retired by its end time stopped working within the cell's own clock with no control plane; a rate limit by subject counted one subject and left another alone; no token, subject or claim reached the logs. `JwtValidationTest` covers validation (private keys, weak keys, wrong curves, duplicates, bounds), signing, the edge payload, permissions and the rule dependency. IPv6 clients were not exercised separately: nothing in the check reads the address. |
| Stage 3 | Deferred, not partly done: endpoint discovery and YAML documents have no demonstrated demand and would add a YAML parser to the control plane. |
| Account takeover | No change in this phase: the login outcome counting of Phase 6 and the leaked-password check of Phase 5 already cover it, and the check keeps passwords out of logs and events. The corpus size is the Phase 5 result. |

Phase 9 is complete for stages 1 and 2 within the limits above.

**Completion checkpoint, 27 November 2026.**

| Implementation | Documentation | Automated/runtime | Browser |
| --- | --- | --- | --- |
| Stages 1 and 2 complete; stage 3 deferred | Complete: security guide (API schema bodies, JWT validation), changelog | Passed: `ApiSchemaTest`, `JwtValidationTest`, `application_protection.py`, `jwt_validation.py`. Not measured: false-positive rate on a corpus, p99 latency | See the run recorded below |

---

## Phase 10: Investigation and analytics

**Execution plan.** Priority: P1 for decision trace and loss visibility; P2 for episode views.

**Ordered stages.** 1. Deliver bounded trace fields and explicit unavailable signals. 2. Qualify scoped cursor queries, redaction and log/export freshness and drops. 3. Add attack episodes/views only after counted samples prove their value.

**Security, resources and failure boundary.** No serving-path query or extra analytics service. Reuse ClickHouse rollups and existing export jobs; set line-byte, cardinality, retention and query budgets. Telemetry failure may lose accounted events but never stop serving. Do not repeat the existing LogPush implementation.

Each stage closes the shared phase gate with the phase-specific tests and
acceptance criteria. Scope not named in this phase is excluded.

**Objective.** An operator or domain owner can explain any decision and
follow an attack from start to end.

**Current state.** Request events carry the action, reason, rule reference, and
scores. The events tab shows the newest 500 of 24 hours. The alert counts
heartbeat reports. Attacks are not grouped.

**Scope.**

- A **decision trace** on each mitigated or shadow-matched request: the stage,
  the rule references, matched CRS IDs (Phase 2), scores, client key,
  reputation band, bot components, and dataset versions. It holds no request
  data beyond what events store today.
- **Security events without the 500 cap**: cursor pagination over a bounded time
  range, with the same filters.
- **Attack episodes**: mitigations grouped by domain, time, and overlapping
  client keys or rules into one record with a start, an end, a peak, and its top
  sources, paths, and rules. Alerts are sent per episode.
- **Views**: top client keys, rules by match count, bot and reputation
  breakdowns, and false-positive candidates (blocks followed by solved
  challenges or by allows from the same key).
- **Stated retention** for each of these, with redaction rules unchanged.

**Technical direction.** Everything is computed in ClickHouse rollups and
bounded queries. The request path only adds bounded fields to the existing log
line.

**Dependencies.** Phases 2, 3 and 4 for core traces. Phases 7 and 8 only for their admitted signal fields; deferred signals are explicitly unavailable.

**Relationship to Phase 10.5.** Historical analytics, traces, episodes and
views stay in ClickHouse and are not a source of durable accounting. Durable
usage and accounting summaries, the ClickHouse-independent dashboard state and
their freshness labels belong to Phase 10.5 Stage B. Remaining Phase 10 work
reads those summaries and adds no PostgreSQL table that grows with events;
an episode needs only bounded state for its alert, and unavailable analytics
shows as unavailable, never as zero. The Phase 10 checkpoint below is unchanged.

**Acceptance criteria.**

- For every mitigated request in a harness run, the trace names the stage and
  the rule or signal that acted.
- The events list pages through a 1,000,000-event attack in a 24-hour range with
  each page answered under 1 second on the reference ClickHouse.
- One scripted attack produces one episode and one alert.
- The log line grows by no more than the stated byte budget.

**Verification.** ClickHouse query tests against a counted sample. E2E from
cell to ClickHouse to the API. Browser tests for traces, episodes, and views.

**Record, 27 November 2026.**

| Item | Result |
| --- | --- |
| Stage 1: trace fields | The decision trace names the stage, reason, rule references, shadow rules, matched managed rule IDs and scores from stored fields. Cells now log two bounded fields on mitigated and shadow-matched requests only: `client_key` (a 12-character hash keyed with the node secret, so it is not the address and differs on every edge) and `snapshot_sequence`. Vector validates both and ClickHouse stores them (migration `2026_11_27_edge_event_decision_trace.sql`). A mitigated line grows by 15 bytes against a budget of 64, other lines by 0 (`decision_trace.py`). |
| Stage 1: unavailable signals | Named in the trace and the API, never shown empty: the reputation band (shadow mode), bot signals, and platform dataset versions (signed datasets are not delivered to cells). Dataset versions are replaced by the snapshot sequence, because named IP lists travel inside the signed snapshot and carry no version of their own. |
| Stage 2: scoped cursor queries | The mitigations list pages by cursor over `(occurred_at, event_id)`, up to 100 rows a page, with a `client_key` filter. `security_event_paging.py` on the development ClickHouse with 1,000,000 mitigated requests over 23 hours: a 300-page walk with no repeat and no break in order, a page from the middle identical to ClickHouse's own answer, the last page ending the cursor; 301 pages at p50 0.39 s, p95 0.45 s, maximum 0.50 s against the 1 s target (a development host, not fleet evidence). Redaction is unchanged: addresses stay masked. |
| Stage 3: episodes | An episode runs from the first blocked minute to the last before 5 quiet minutes and counts at 20 blocked requests or more; the API lists up to 20 per 24-hour range with start, end, blocked total, peak per minute and the top five rules, paths and client keys, and `/security/client-keys` lists the top 20 keys. Computed from stored events when asked, with bounded queries; no table, no job. `attack_episodes.py` on real ClickHouse from a counted sample (300 and 30 blocked requests in two attacks, a below-minimum burst, allowed requests and another domain's blocks): exactly two episodes with exact totals, peaks, durations and top sources. |
| Stage 3: alerts per episode | The security alert sends one notification per episode (a marker kept while blocked requests keep arriving, ended by 5 quiet minutes) instead of one per hour. `SecurityAlertTest` runs a one-hour attack as one alert and a later attack as a second. |
| Not built | The bot and reputation breakdowns (the signals are not logged), and the false-positive candidates: blocks followed by solved challenges or by allows from the same key need the client key on every allowed request, which would add bytes to every log line. No claim is made for them. The key's reach is one edge, so an attack spread over several edges shows one key per edge. Not exercised: IPv6 clients in `decision_trace.py` (the key is derived from the same counting key the IPv6 /64 tests already cover). |
| Measurement | `edge_cell_benchmark.py`, one CPU, WAF off, cached object, 64 connections, 3 runs of 10 s on this development host: 342, 358, 337 microseconds of CPU per request before and 348, 339, 314 after (a tie within the run-to-run noise); the added work runs only on a mitigated request, one SHA-256 over a short string. A mitigated-path benchmark was not run. |

**Checkpoint, 27 November 2026. Phase 10 stays open: the owner is adding stages, so this is not a completion gate.**

| Implementation | Documentation | Automated/runtime | Browser |
| --- | --- | --- | --- |
| Stages 1, 2 and 3 as first scoped (episodes, per-episode alert, top client keys) delivered; the false-positive and bot/reputation views are not built and are named as such | Complete: security guide (trace, client key, episodes, alerts), telemetry schema, API reference, changelog | Passed: full PHP suite (870 tests), PHPStan, pint, Vector config tests, contract tests, `decision_trace.py` on a real cell, `decision_trace_pipeline.py` (Vector to ClickHouse to the API), `security_event_paging.py`, `attack_episodes.py`, `clickhouse_migrations.py`, `shadow_and_expiring_rules.py` and `telemetry_proof.py` as regressions | Passed: the security events spec (5 specs in light and dark, desktop and phone, 20 passed, with accessibility checks) and the security alert spec; the rest of the suite was not rerun |

---

## Phase 10.5: Operational hardening, observability and reliability

**Execution plan.** Priority: P1 mandatory prerequisite for Phase 11. Stage H
changes a security default at the owner's explicit request and is P1 for the
documentation of its consequence. Stage G is P1 only after Stages E, F and H.

**Ordered stages.** The stage letters are identifiers, not the order of work.
Execute in this order: 1. Stage A.1 audit (read-only baseline). 2. Stages H, F
and I (small correctness and trust changes). 3. Stage B (data ownership and
durable summaries). 4. Stage E (narrow Fleet changes). 5. Stage G (origin
health infrastructure). 6. Stages C and D (edge pages, then headers). 7. Stages
A.2 and A.3 (feature dashboards and Loki templates, after the new signals
exist). 8. Stage J. 9. Stage K.

**Security, resources and failure boundary.** No new database, observability
platform, tracing system, queue or analytics store. One service is admitted:
the optional origin health-check container of Stage G, with the limits stated
there. Telemetry, dashboards, logging and probing failures never stop serving
and never turn missing evidence into a healthy or a zero value. Every runtime
change follows the AGENTS.md path: authorize, validate, write desired state
and revision in one transaction, dispatch one unique job, sign, activate
atomically, verify, and keep the previous valid state.

Each stage closes the shared phase gate with the phase-specific tests and
acceptance criteria. Scope not named in this phase is excluded.

**Objective.** Make the platform tell the truth about itself: dashboards and
logs that can be trusted, durable usage that does not depend on ClickHouse,
anonymous edge-generated pages, a configurable public header namespace,
service-level Fleet changes, origin health that is correct and bounded by a
budget instead of the number of edges, and health that separates the platform
from its customers.

**Current state.** Written from a source and configuration inspection of `dev`
at `c4fb583f` plus the working tree on 27 November 2026. Rows H1 to H23 are
source observations unless a row says otherwise. A second pass on the same day
executed temporary experiments (an isolated in-memory test database, disposable
containers, offline Fleet rendering and the read-only development Grafana);
rows H24 to H33 come from them, and the section "Resolution of the remaining
uncertainties" lists each experiment, its environment and its limits. The
experiment scripts were not retained and no repository test was added, so none
counts toward a gate. Statuses use the vocabulary of section 1, with
**Confirmed defect** for behavior the source or an executed experiment states
directly.

| ID | Area | Status | Evidence in the inspected paths | Consequence and open question |
| --- | --- | --- | --- | --- |
| H1 | Grafana inventory | Verified inventory | 35 dashboards in 8 folders generated by `docker/grafana/build_dashboards.py`, 23 library panels, four provisioned alert rules and data sources Prometheus, ClickHouse, the control database and Loki. `tests/e2e/grafana_observability.py` already runs every panel target against its real source. | The audit starts from this inventory and runner. Panel-by-panel correctness was not established. |
| H2 | Dashboard filters | Confirmed defect | Every dashboard declares `environment`, `region`, `pop`, `edge`, `pool`, `domain` and `hostname`. In `dashboards_security.py` the shared `RAW` and `RAW_WEEK` conditions carry no variable filter, so WAF processing time, top rules, top client networks, ASNs, user agents and paths ignore the selected region, PoP, edge and often domain. The logs explorer query `{service=~"$service", level=~"$level"}` ignores `environment`, `host` and `domain`. | A narrowed view silently shows fleet-wide numbers. Enumerate every inert or partly applied variable per panel. |
| H3 | Empty versus zero | Confirmed defect (mechanism); per-panel impact unverified | `dashboard_lib.py` sets `noValue` to "No traffic" for every panel whose unit is `reqps`, `Bps`, `bps`, `short` or `none`, by unit and not by meaning. 26 query lines (30 provisioned targets) use `or vector(0)`. | A count such as exclusions in use or emergency modes can read "No traffic", and an absent series reads as an actual zero. Zero, no traffic, no match, disabled, unsupported, stale and unavailable are not distinguished. |
| H4 | Percentiles | Unverified | Prometheus panels use `histogram_quantile` over the recording rule `cdnf:edge_latency_bucket:rate5m`. ClickHouse panels use `quantilesTDigestMerge` on rollups, and the WAF panels use exact `quantile` on raw `edge_events`. | Three methods answer one question. Bucket bounds, merge order and agreement were not compared. |
| H5 | Query cost | Unverified | 33 dashboards refresh every 30 s and two every 10 s, over a one- or six-hour default; many ClickHouse panels read raw `edge_events` (7-day TTL). The platform sets `max_execution_time` 3 s for Laravel only. | Concurrent dashboards may amplify ClickHouse load. Grafana's own query limits were not found. |
| H6 | Feature visibility | Missing | No panel, recording rule or alert references challenge outcomes or difficulty, challenge-key synchronization, shared or long-window rate limits, client reputation, JWT validation, IP lists, incident profiles, staged rulesets or expiring rules. `shadow_rule` and `request_rule` exist in the ClickHouse event, and `rule_matches_hourly` already has a shadow view. No `grafana_*` read model exists for the rate, reputation, challenge-key or list tables. | The data for part of the list exists and only needs panels. The rest needs a small bounded signal first. |
| H7 | Loki | Partial | Vector sets the low-cardinality labels `environment`, `host`, `service` and `level` and keeps request, operation, job, task, domain, edge, cell and revision identifiers in structured JSON (`OperationalJsonFormatter`). `cdnf-logs-explorer` holds one markdown table of per-service queries, `cdnf-incident` and `cdnf-request-trace` hold one query each. Retention (168 h default), bounded disk buffers and Docker log rotation (10 MB by three files) exist, and alerts cover Loki and collector failures. | There are no reusable templates by failure class, and no identifier variables. Whether the Go services emit the same normalized fields as Laravel is unverified. |
| H8 | Usage summaries | Partial | `usage_rollups` holds hour, day and month rows per `domain_id` with requests, bytes in and out, cache hits and DNS queries, compacted by `cdnf:usage:compact` (35 hourly and 365 daily days by default). The admin and domain panels read it when ClickHouse is down. | See H9 to H12. |
| H9 | Usage finalization | Confirmed defect | `cdnf:usage:finalize` queues only the single hour that ended. It never catches up after an outage. `BuildUsageRollups` writes a `finalized` row for every domain and hour, with zeros when ClickHouse returns no row for the domain, so a Vector or telemetry outage is stored as final zero usage. It records `source_finalized_at` as the build time, not as a completeness statement. | Gaps and false zeros become permanent, and a compacted row is never rebuilt. This is the direct opposite of the billing-readiness rule that missing telemetry is never free usage. |
| H10 | Usage attribution and durability | Confirmed defect | Rows carry `domain_id` only, with `ON DELETE CASCADE` to `domains`. DNS counts are mapped from the zone name to the current domain names. Nothing records which user or account owned the domain in a period. | Deleting a domain deletes its accounting rows, and a later owner change rewrites history by omission. Whether final deprovisioning hard-deletes the row (the report uses `withTrashed`) is unverified. |
| H11 | Accounting guarantee | Confirmed limitation | Vector buffers use `when_full: drop_newest`. `edge_events` is plain `MergeTree` and the hourly views are `SummingMergeTree`, so a retried delivery is counted twice and a dropped event is lost. Raw events live 7 days and hourly rollups 400 days. | Telemetry is at-least-once on retry and lossy on saturation. It is a usable operational measure and not a durable accounting source. No edge-side counter exists to reconcile against. |
| H12 | Write volume | Confirmed | The build writes a row per domain per hour even for zero traffic. At the default retention one domain holds about 840 hourly, 330 daily and 12 yearly month rows. | PostgreSQL grows with domains times hours, not with traffic. At roughly 230 bytes per row with three indexes that is about 0.27 MB per domain, or about 27 GB at 100,000 domains. These are estimates for Stage B to measure. |
| H13 | Other PostgreSQL growth | Partial | Security events are bucketed per domain, edge, reason and hour with a unique index and a retention prune. Rate and reputation reports, edge tasks, purges, orders, operations, audit logs and revisions each have a prune command or setting. `edge_probe_results` holds one latest row per observer, target and address. | Each is bounded by design. The product of domains, edges, reasons and hours per table was never computed or measured, and the probe table grows with the square of the edge count. |
| H14 | ClickHouse outage | Partial | Panel widgets catch the failure and show an unavailable state, usage reads PostgreSQL, and the client uses a 1 s connect and 4 s total timeout. The security event list and rule impact paths throw `AnalyticsUnavailableException`. | Fallback summaries carry no age, finalization or completeness label, and several widgets can each wait up to 4 s in one render. Per-component isolation was not tested. |
| H15 | Edge-generated pages | Partial; unverified in a browser | Only the browser challenge, a plain-text maintenance body and JSON rejection bodies are written by the runtime. Most rejections end in a bare `ngx.exit(status)`, so Nginx's built-in page names the server software (`server_tokens off` does not remove the "openresty" footer), and the gateway's 421 has an empty body. The challenge page is already minimal, light and dark, nonce-bound and states no product name, but it sets the cookie `cdnf_chal` and the element id `cdnf-solver`. | Pages are inconsistent and some reveal the runtime. Which status a visitor receives after `ngx.exit(444)` in `origin_failure` was not observed. |
| H16 | Public headers | Confirmed defect | The runtime emits `X-CDNF-Cache`, `X-CDNF-Origin`, `X-CDNF-Origin-Transition`, `X-CDNF-Error`, `X-CDNF-Security-Reason` and `Server` as one platform switch (`diagnostic_response_headers`, default off). A snapshot that predates the setting shows them. The access log reads the same names from the upstream. There is no ray header, no PoP header and no per-domain control. Client `X-CDNF-*` request headers are stripped. Whether an origin or a response rule can set a response header with that prefix was not tested. | The prefix is hard coded in the runtime and in the telemetry format. `X-CDNF-Origin` reveals the origin role. Nothing correlates a visitor's response to a log line. |
| H17 | Fleet granularity | Confirmed defect (source); effect unmeasured | A release diff yields roles, and `node_needs_restart` is `role in d.roles`. A configuration change re-renders a node bundle, and the plan is `provision`, `upload`, `validate`, `activate`, `gate`, with `activate` running `start.sh` (`docker compose up -d`). The management and telemetry allowlists are environment values of the `caddy` and `telemetry-gateway` services, so a change to either list changes their Compose configuration. | An allowlist edit is planned as a whole-node activation and likely recreates two ingress services. Dashboard files are probably release configs of the monitoring role (unverified). The plan names no service and no availability impact. No container-ID or start-time test exists. |
| H18 | Origin health state | Confirmed defect | `dns_records.origin_health` is one JSON value. A manual test, a scheduled test and a passive failure report each overwrite it, and the passive writer stores `status: unhealthy`. The panel column falls back to "Not tested" and colors only healthy and unhealthy. | There is no disabled, pending, degraded, unknown or stale state. A passive failure can replace active evidence and the reverse. With checks disabled a record can still read unhealthy with no label saying why. |
| H19 | Probe fan-out | Confirmed defect | Each scheduled check is an `origin_test` operation that creates one task for every heartbeat-fresh placement edge, up to 20, so the load on one origin per interval is the smaller of the placement size and 20, not a budget. The probe runs inside the edge agent (`runOriginTest`). It already refuses blocked destinations, pins approved addresses, follows no redirects and reads at most 4 KiB. Separately, each edge probes every other PoP's listeners every 15 s (at most 128 targets), which grows with the square of the edge count. | Probing is neither isolated from the agent nor independent of fleet size. Customer-origin traffic is already capped at 20 edges, which is still too many for one origin. |
| H20 | Probe controls | Missing | The per-origin settings are `enabled`, `path` and an interval of 60 to 86,400 s. There is no platform switch, no per-destination or per-user budget, no regional selection, no confirmation rule and no deduplication across domains that share an origin. | A customer can multiply load by adding domains. A restarted fleet is spread only by a per-record jitter of at most 300 s. |
| H21 | Origin TLS default | Confirmed (current) | The API requires `verify_tls`. The panel toggles default to on and force it off for HTTP. The Phase 0 record decided that no secure-origin-mode change is proposed. | The owner has now required the creation default to be off. This reverses that decision for new origins only. Import paths were not inspected. |
| H22 | Platform health | Resolved for DNS deployments by experiment (see H24 to H25 and Stage I); failed edge tasks and failed TLS orders by source only | `SystemHealth` counts every failed `edge_task`, every failed TLS order and every failed or pending non-tombstone DNS deployment as platform degradation, and the public status page maps them to "Runtime tasks", "Certificates", "Nameservers" and "Zone changes". A failed `origin_test` task is an `edge_task`, and a customer's failed ACME order is a `TlsOrder`. | The suspicion that unverified domains degrade the platform is not supported for DNS: pending, unverified, child, competing and lost-delegation states produced zero drift in ten scenarios. The real defects are a transient takeover row, customer-content rejections counted as platform faults, and no classification or threshold (Stage I.4). Edge tasks and TLS orders were not exercised. |
| H23 | Correlation | Partial | `request_id` exists in the edge event, the Loki JSON and the request trace dashboard. The visitor never receives it, and the nginx identifier is not documented as a stable public reference. | A visitor's report cannot be joined to a log line. |
| H24 | Fleet delivery of file-only changes | Confirmed defect (experiment) | Activation renames the live bundle to `.previous`, renames `.next` into place and runs `docker compose up -d`. Compose recreates a container only when its configuration hash changes, and the hash ignores file contents. A replica of the exact sequence kept the container ID and the old content for a bind-mounted file and directory after activation, and the directory was empty after the next activation deleted `.previous`. An offline render of a changed Prometheus target file changed 0 of 29 control-node service hashes. | Targets, dashboards, alert rules and other mounted files are not delivered to running services until something else changes their hash, yet the node gate passes. This is a correctness defect in Fleet, not only an efficiency problem. Not run on a real Fleet host. |
| H25 | Fleet blast radius | Confirmed (offline render and a disposable gateway) | Adding or removing a host firewall address changes only `firewall.sh`, on every node, and 0 service hashes; by the digest comparison in `config_plan` (not run) every node then gets the full provision, upload, validate, activate and gate cycle, and `start.sh` runs the database, PowerDNS and ClickHouse migration containers, ownership changes and the firewall script each time. A management-network change changes the hash of `caddy`, `core`, `horizon`, `log-pusher`, `migrate`, `notifier` and `scheduler` on the control node only. An edge public-address change changes `caddy` only on the control node. A scrape-address change recreates `node-exporter` and `vector` on that edge. An identical re-render changes nothing. `firewall.sh` flushes its chains and rebuilds them. Disposable Caddy: recreating it failed 297 of about 1,010 requests with a 2.3 s gap; `caddy reload` failed none, with a 55 ms longest gap and a 372 ms reload. | An allowlist edit restarts the application workers. A firewall edit costs a fleet-wide activation. The flush leaves the chain empty for a moment, which is a short fail-open window on internal ports (source). |
| H26 | Visitor response after origin failure | Confirmed defect (experiment) | Disposable cell on the runtime image with the working-tree runtime and configuration, direct listeners. The internal `444` reaches the visitor as Nginx's built-in 502 page for DNS failure, refused connection, response timeout and origin TLS failure alike: HTML, 154 bytes, `Server: openresty`, naming the server software, whatever the `Accept` header; a response timeout is a 502, not a 504; the connection is never closed. HEAD, HTTP/1.0, HTTP/1.1, cleartext HTTP/2, HTTPS 1.1 and HTTPS HTTP/2 give the same answer. Stale content is served inside the stale-if-error window and a 502 outside it or for a bypassed request. | API clients get HTML, the page names the software, and the status does not separate timeouts from refusals. See Stage C.3 for the matrix. |
| H27 | Dashboard inventory | Executed on the development stack | 365 targets in 336 panel instances (46 targets through library panels): 222 Prometheus, 103 ClickHouse, 33 control database and 7 Loki. All ran at a six-hour range with 0 query errors. 11 returned no rows (6 need an input such as a request ID). 181 targets carry the "No traffic" empty text and 115 of them are not traffic (for example backups healthy, hosts up, domains, exclusions in use). 67 ClickHouse, control database and Loki targets reference no dashboard variable. Fixed windows that ignore the selected range appear 17 times at 7 days, 6 at 30 days, 3 at 60 days and 3 at 1 day. The slowest target took 802 ms (Loki). | Provisioning and query validity are established and correctness is not. See Stage A.1 for the required corrections. |
| H28 | Percentile methods | Executed (disposable ClickHouse, deterministic data) | Merged t-digest states are within 0.6% of the exact value in every scenario. The raw-event `quantile` is off by up to 15.4% (p99, long tail). The Prometheus histogram estimate is off by up to 53% (p95, long tail) and 60% (p99, unequal minutes) because it has 11 buckets from 5 ms to 10 s. Averaging per-edge p95 is 203% too high and averaging per-minute p95 is 87% too low. | The methods are not equivalent. See Stage A.2a. |
| H29 | Bandwidth percentile | Executed | `quantile(0.95)` over 5-minute buckets is used for the monthly 95th-percentile bandwidth. A 30-day month has 8,640 buckets, above the 8,192 values the estimator keeps exactly. It returned the same value three times (949,964.45 against an exact 950,329) in this run. | The figure is an estimate with a small bias. Use an exact method for anything billed. |
| H30 | Usage counting cost and sources | Executed (micro-benchmark) and source | The cell already keeps one cell-wide per-second request counter in a shared dictionary. No per-domain request or byte counter exists, and DNSdist keeps no per-zone counter; DNS usage exists only through dnstap, Vector and ClickHouse. Seven shared-dictionary increments cost 2.2 to 3.2 µs per request on one worker (4.2 to 4.7 µs when the key is rebuilt per request). | Edge-side counters are affordable. DNS counters need a feasibility test. See Stage B.7. |
| H31 | Probe resource cost | Executed (stand-in worker) | A Go stand-in with the candidate limits (0.25 vCPU, 128 MB, 128 PIDs, 8 concurrent, fresh connection per probe, 3 s and 5 s timeouts, 16 KB body cap, no redirects) used 8 to 10% of the CPU quota at 20 HTTP probes per second and 15% at 20 HTTPS probes per second, and saturated the quota at roughly 40 to 75 probes per second, HTTP or HTTPS (the achieved rate was erratic once throttled). RSS stayed under 10 MiB and PIDs under 20. | The CPU quota, not memory, is the binding limit. The real worker and a loaded cell were not measured. |
| H32 | Scheduling simulation | Executed (model of the proposed algorithm) | For 1,000 origins on 6 groups, probes per interval were 3,000 at 3, 10, 80, 100, 200 and 400 edges, against 3,000, 10,000, 20,000, 20,000, 20,000 and 20,000 for the current fan-out of at most 20 edges. The busiest worker carries 1,000 probes per minute at 3 edges and 45 at 100. | The budget is independent of fleet size. Small fleets concentrate load on few workers, so capacity admission is mandatory. |
| H33 | Go service logs | Resolved by source | `edge-agent` and `edge-gateway` write `slog` JSON with `event` (52 calls), `edge_id` (43), `error_code` (14), `revision_id` (11) and `task_id` (5). No call carries `domain_id`, `cell_id`, `request_id` or `operation_id`. Nginx and Lua errors are plain text. | Loki templates can filter Go services by edge, task and revision only; domain, cell and request filters need fields added, and cell errors need a structured form. |

Not found and not assumed: a second analytics store, a tracing system, or any
external provider on the request path. None is added by this phase.

**Scope.** Stages A to K below. Each stage records durable state, permissions,
external effects, retry, rollback and exact bounds before coding.

### Stage A: Observability audit and completion

**A.1 Dashboard correctness (audit first, then fix).**

- Build the panel inventory from the provisioned JSON, not from documentation:
  for every folder, dashboard, library panel, variable, target, transformation,
  link, threshold, unit, `noValue`, alert reference and data-source permission,
  record the query, the source table or series, the filter variables it honors,
  its time range and refresh, and whether it can distinguish the seven states
  below. Extend `grafana_observability.py` to emit this inventory and to run
  every panel with specific variable values, not only `All`.
- Validate against real data on a seeded stack: Prometheus recording rules and
  counter resets, ClickHouse aggregation and duplicate counting, the PostgreSQL
  `grafana_*` read models, Loki queries, variable cascades, region, PoP, edge,
  cell and domain filters, percentile agreement, cache-hit and bandwidth units
  (bits versus bytes, per second versus per interval), stale series, obsolete
  and unsupported metrics, and per-panel query cost (ClickHouse `query_log`
  rows read, duration and memory).
- Every panel states which of seven conditions it shows: an actual zero, no
  traffic, no matching records, disabled functionality, an unsupported metric,
  stale information (with age) and an unavailable data source. Replace unit-
  based `noValue` with per-panel semantics. Remove `or vector(0)` wherever it
  turns an absent series into a value, and replace it with an explicit
  unavailable or no-data state. Never invent data, hide a failed query or tint
  a failure green.
- Panels that ignore a selected filter either honor it or say, on the panel,
  that they are fleet-wide. Panels over raw events state their seven-day limit.
- Decide one percentile method per question and document the difference where
  two remain. Record the cost of each dashboard at the default range and
  refresh, and set a ratified budget; reduce refresh or move to a rollup where
  a panel exceeds it.

**A.1 results and required corrections (27 November 2026, executed).** The
inventory and query run are in H27. Corrections, each with its acceptance:

- Replace the unit-based empty text with a per-panel statement. Acceptance: a
  contract test fails any panel whose empty text is "No traffic" and whose
  title or query is not a request, byte or query rate; the 115 non-traffic
  targets of H27 each carry an explicit state (zero, none, disabled,
  unsupported, stale or unavailable).
- Make the 67 variable-blind targets honor the variables or say they are
  fleet-wide. Acceptance: a test per dashboard sets one region, one PoP and one
  domain and shows each panel either changes its query or carries the label.
- Remove the 30 `or vector(0)` targets that turn an absent series into 0.
  Acceptance: a fixture with the series removed shows "unavailable", and a
  fixture with the series at 0 shows 0.
- State the fixed window (7, 30, 60 or 1 day) on every panel that ignores the
  range. Acceptance: the panel description names it.
- Expected-value verification: the run compared results with known answers only
  for the request, byte and latency rollups of Stage A.2a. Every remaining panel
  needs a deterministic fixture (ClickHouse rows, Prometheus samples through a
  seeded exporter, PostgreSQL rows, Loki lines) with the expected number written
  beside the query. Until that exists a panel is "query valid, value
  unverified", never "verified".

**A.2 Visibility for current features.** Reuse the Security overview, WAF,
Telemetry integrity, Origin health, Cache, Control plane and Domain overview
dashboards. Add panels, not dashboards, unless a dashboard would exceed its
folder's panel budget. Required coverage, each with its source:

| Capability | Signal and source to confirm |
| --- | --- |
| Managed WAF, shadow rules, staged rulesets, expiring rules | Existing ClickHouse fields (`waf_*`, `shadow_rule`, `request_rule`, `rule_matches_hourly`) plus a `grafana_*` view for staged and expiring rules |
| Challenge outcomes and adaptive difficulty | Reason values already in `security_reason`; a bounded per-cell counter by outcome and difficulty band if the log cannot answer |
| Challenge key synchronization | Key epoch and acknowledgement age per edge from the control database |
| Shared and long-window rate limits | Report age, keys over threshold and aggregation lag from the rate report tables |
| Client reputation | Report age, band counts and shadow-versus-enforced state |
| JWT validation, IP lists, incident profiles | Denial counts by reason and list or profile version per cell |
| Cells, gateways, resource saturation | Existing gateway and cell capacity metrics, extended with shared-memory and descriptor use where the cell reports them |
| Configuration revisions | Desired versus active revision and age per edge, domain and cell |
| Telemetry delivery and loss | Vector accepted, dropped and rejected counters tied to the integrity dashboard |

A signal that does not exist is marked unsupported on the panel until it is
added. A new Prometheus series must carry only fixed-value labels and the
existing PoP and edge labels. No domain, client or request label is added to
Prometheus.

**A.2a Percentile and measurement contract (executed 27 November 2026).** Method:
a disposable ClickHouse built from `init.sql` and the migrations, deterministic
data, an exact nearest-rank reference, and the dashboards' own aggregate
expressions. Scenarios: uniform 1 to 1,000 ms, lognormal (80 ms, 0.8), a 2%
Pareto tail, n = 60 and n = 300, 400,000 requests, two edges with 95% and 5% of
the traffic (median 40 and 400 ms), and 90% of the traffic in one minute. Each
large scenario has 400,000 events. Measured errors against the exact value:

| Method | Worst error | Where |
| --- | --- | --- |
| Merged t-digest states (`quantilesTDigestMerge` over the 1-minute and hourly rollups) | 0.6% (p50), 0.3% (p95, p99) | all scenarios; the hourly rollup equals the minute rollup within 0.3% |
| Raw-event `quantile` | 15.4% low (p99), 4.6% low (p95) | long tail, unequal edges, n = 60 |
| Raw-event `quantileExact` | 18.7% high (p95), 12.7% (p99) | n = 60 and n = 300 only, because its rank definition differs from nearest-rank by one sample |
| Histogram estimate over summed bucket counters | 53% (p95), 60% (p99), 40% at n = 60 | long tail, unequal minutes, small n |
| Mean of per-edge p95 | 203% high | unequal edges |
| Mean of per-minute p95 | 87% low | unequal minutes |

Contract:

- **Request-latency percentile (dashboards, domain analytics, reports).** The
  merged t-digest states, over the population "requests of the selected scope
  in the selected range", merged across edges, minutes, hours and domains. The
  tolerance is 1% against the exact value for p50, p95 and p99 with at least 100
  samples (p99: 1,000); below that the panel shows the count and a "low volume"
  state instead of a number. The rank definition is nearest-rank. Qualification
  reruns the scenarios above with the exact reference and fails above 1%.
- **Live histogram estimate (NOC wall, alerts).** Named "estimated from
  buckets" on the panel, never shown beside the t-digest figure as the same
  measurement. Service-level objectives use the share of requests under a
  threshold that is a bucket bound (`le`), not a quantile. Adding buckets near
  the thresholds in use (candidate 75, 150 and 750 ms) is an experiment: it
  needs the measured error and the series cost before it is adopted.
- **Raw-event percentiles in WAF panels** use `quantileExact` and show their
  sample count, or are replaced by the rollup figure.
- **Billing-style bandwidth percentile** (95th percentile of 5-minute bits per
  second per domain) is a different quantity: population is 5-minute buckets of
  bytes sent (headers included) times 8 over 300 s, not requests. It uses
  `quantileExact` with its rank definition written down, because a 30-day month
  has 8,640 buckets and the estimator keeps only 8,192 exactly (H29).
- **Never** average, weight or otherwise combine already calculated
  percentiles. A contract test scans the dashboard sources and recording rules
  for an average over a quantile series and fails on one.
- Failure handling: a method that exceeds its tolerance in qualification is
  replaced or relabeled before release; the tolerance is revised only from a
  recorded baseline.

**A.3 Loki investigation templates.** Extend `cdnf-logs-explorer`; do not
duplicate HTTP access or DNS query logs into Loki, which stay in ClickHouse.
Provision one reusable LogQL template per class: all errors and critical
events; control plane; jobs and scheduler; edge agent; gateway and cells;
origin connectivity and TLS; DNS and zone synchronization; certificate
issuance and renewal; WAF runtime; challenge failures; shared rate-limit and
reputation processing; configuration deployment and revision mismatch; telemetry
delivery; database and queue; Fleet deployment, rollback, backup and recovery;
and request or operation correlation. Variables: environment, host, service,
severity (existing labels), and domain, edge, cell, request ID, operation ID,
task ID, revision, event type and free text, applied as parsed fields and never
as new labels. Add normalized fields to the Go services only where a class has
none, with redaction unchanged. Qualify with a seeded log fixture that every
template returns a known line, that a mismatched filter returns an explicit
empty state, that no label exceeds its cardinality budget, and that a Loki
outage shows unavailable rather than empty. Preserve bounded disk buffers,
retention and asynchronous delivery.

**Acceptance.** The panel inventory is complete and committed with its checks.
No panel conflates the seven conditions or ignores a filter without saying so.
Every listed capability has a panel or a recorded unsupported marker. Every
template returns its seeded line. The measured query cost of each dashboard is
within its recorded budget.

### Stage B: Storage ownership, bounded summaries and analytics availability

**B.1 Ownership.** Record one table in the operations documentation and
enforce it in review.

| Store | Owns | Does not own |
| --- | --- | --- |
| PostgreSQL | Configuration, users and authorization, domains and DNS, security policy, certificates, edge and Fleet inventory, transactional and required live security state, bounded operational history, durable usage and accounting summaries, last-known dashboard summaries, latest origin monitoring state | Per-request, per-query, per-event or per-probe rows |
| ClickHouse | Raw HTTP and DNS events, detailed traffic, cache, geography and latency analytics, historical security and origin-probe observations, high-volume telemetry | Anything the control plane needs to run, or the only copy of finalized accounting |
| Valkey | Queues, leases, scheduling coordination, bounded transient counters | Anything that must survive a restart as a record |
| Loki | Operational application and container logs | Traffic logs |
| Prometheus | Bounded operational metrics and alert evaluation | Per-domain or per-client series |

**B.2 Growth audit.** For every PostgreSQL table, record its dataset class
(authoritative configuration, transactional state, required live security
state, accounting summary, dashboard fallback summary, temporary coordination
state or historical telemetry), its writers, readers, indexes, prune path, row
bound as a formula, JSON field maximum and WAL contribution. Do not remove
`usage_rollups`, and do not move live security coordination state (mitigations,
reputation bands, challenge keys, lists) into ClickHouse. A table that grows
with request, query or event volume is moved, bucketed or capped.

**B.3 Durable summaries.** Extend the existing `usage_rollups` mechanism; do not
add a second one. Direction to evaluate, with Expand and Contract migrations:

- Keep the unique `(domain, interval, granularity)` shape and add the measured
  fields that the telemetry can support: upload and download bytes (existing
  `bytes_in`, `bytes_out`), total transferred, cache-served requests and bytes,
  origin-served requests, mitigation total, and DNS queries, each defined by the
  existing ClickHouse rollup definitions so a dashboard and a summary agree.
- Add an account attribution that is written with the row, not derived from the
  domain's current owner: the owning user at the time of the interval, with
  ownership changes splitting the period. Rows stop cascading on domain delete;
  a deleted domain's accounting rows remain under a retained identifier until
  their retention policy ends.
- Replace write-per-domain-per-hour with sparse rows plus an explicit coverage
  record, so a domain with no traffic costs nothing but its coverage. Evaluate
  dropping hourly rows from the default and keeping day and month only, with the
  shortest hourly window the dashboards actually need.
- Retention tiers: current period and a short hourly window, bounded daily rows,
  finalized monthly rows, and a long accounting tier only where an explicit
  policy requires it. No redundant copy of an aggregate.
- Record expected PostgreSQL size at 100, 1,000, 10,000 and 100,000 domains with
  the formula and a measurement on a populated database, for rows, table, index
  and WAL, and show that raising the request rate tenfold does not change them.

**B.4 Availability without ClickHouse.** Define, per component of both panels,
what it shows when ClickHouse is down: authentication, authorization, domain,
DNS and proxy management and usage summaries stay fully usable, finalized
accounting stays readable, and last-known totals stay visible. Detailed views
show an explicit temporary-unavailable state and never a zero. Every fallback
summary shows its last successful update, its age and one of **current,
delayed, stale, incomplete** plus its finalization status. Components load
independently with a bounded timeout and a short-lived failure memory (an
existing cache entry, not a new circuit-breaker library), so one dead query does
not delay the page by the sum of the widgets' timeouts. A dashboard total that
is stored is labeled as stored, with its age.

**B.5 Billing readiness (foundation only).** No invoice engine, price, wallet or
gateway. Define and test:

- States: provisional, finalized, reconciled, corrected and incomplete usage,
  stored per period with the reason for each correction.
- Idempotent aggregation keyed by source interval and a source watermark;
  duplicate prevention; late events within a bounded window re-open the period
  to provisional and are recorded as a correction rather than rewriting a
  finalized row silently; a catch-up job that walks every missing interval.
- A period is never finalized while telemetry completeness is unknown or below
  a ratified threshold; it becomes `incomplete` with the measured gap. Missing
  telemetry is never zero and never estimated.
- Reconciliation of ClickHouse against an independent count the edges can
  report (for example per-edge request and byte counters sent in the existing
  heartbeat), so loss and double delivery are measured. State plainly which
  guarantee is missing today (H11) and whether the counters close it. If they
  do not, the documentation says usage is an operational measure and not an
  invoice-grade record.
- Account attribution, domain ownership change, counter correction and period
  finalization are each covered by a test.
- Finalized and corrected summaries are inside the backup set and the documented
  restore test.

**B.6 Retention and growth guarantees.** Record a measured bound and a check for
PostgreSQL rows, table and index sizes, WAL per day and JSON size; temporary
security reports; operational history; dashboard and accounting summaries;
ClickHouse partitions and TTLs; Valkey memory; Loki and Prometheus retention;
Vector buffers; and Docker log rotation. Run them under representative HTTP,
DNS, security and operational workloads at two request rates and show no
proportional PostgreSQL growth. Ship the migration with a reconciliation query
that compares old and new totals, a backup before it, and a tested rollback.
Existing finalized rows are never recomputed.

**B.7 Recommended accounting architecture (decision 27 November 2026;
implementation qualification required).** Two independent paths, one owner each:
detailed analytics stay edge and DNS telemetry to Vector to ClickHouse; durable
accounting is a second, much smaller path of bounded counters reported
asynchronously to PostgreSQL. The accounting path never needs a ClickHouse write
and adds no synchronous database or network call to serving.

- **Producers.** One per cell and one per DNS node. Identity is the edge or node
  identifier, the cell name and a generation identifier created with the
  producer's durable state and renewed whenever that state is lost or a counter
  can no longer be trusted. A producer counts per domain (or per served zone)
  into fixed keys: requests, bytes in, bytes sent, cache-served requests and
  bytes, origin requests, mitigations; DNS queries per zone. Bucket rotation is
  done by a timer, not by building a key per request (H30). Memory is bounded by
  assigned domains times counters; a full dictionary sends increments to an
  explicit `unattributed` counter and never evicts another domain's count.
- **Reports.** Closed, UTC-aligned five-minute buckets with a monotonic sequence
  per generation. Sequenced interval reports are chosen over cumulative counters:
  the control plane needs one watermark row per producer instead of one state row
  per producer and domain, a lost report is visible as a gap instead of being
  hidden by the next cumulative value, and a counter reset needs no inference.
  The edge agent pulls the open bucket from each cell and the DNS node agent from
  DNSdist about once a minute, writes it to a durable per-producer journal
  (bounded to 24 hours of non-zero buckets and 10 MB), and sends unacknowledged
  reports on the existing authenticated heartbeat. A report is removed from the
  journal only after the control plane acknowledges it. A journal overflow drops
  the oldest bucket and records an explicit lost-interval marker.
- **Ingestion.** One transaction: the producer's watermark row (last contiguous
  sequence, highest sequence, at most 64 open gaps) is checked for duplicates
  and order, and the counts are added into `usage_rollups` for the domain and
  hour. A duplicate or an already filled gap changes nothing; an out-of-order
  report fills its gap. Ledger size scales with producers, not with intervals or
  domains. A generation change closes the previous generation and records any
  open gap as lost.
- **Attribution.** Counts are keyed by domain identifier. A small
  ownership-period table (domain, user, from, to) is written at claim, transfer
  and deletion, and ingestion attributes each hour to the owner at that time,
  splitting an hour at a transfer. Domain deletion keeps its rows; the cascade
  is removed (H10). DNS zones map to domains through the served-zone list that
  already exists.
- **Reconciliation.** For each domain-hour the control plane compares the two
  paths. Path B wins for accounting when its producers have no open gap; path A
  is used only to detect loss, double counting and attribution errors and, when
  B is incomplete, as a labeled provisional estimate. Disagreement above a
  ratified threshold (candidate 1%) is recorded, not silently resolved.
- **States.** A period is provisional until every producer assigned to its
  domains has reported contiguously through the period end plus a grace window,
  finalized when it has, `incomplete` with the measured gap when it has not,
  corrected when a late report changes a finalized value (the old value is kept
  with the reason), and reconciled when both paths agree within the threshold.
- **Failure behavior.** Cell or edge restart: new generation, the last journaled
  open bucket is reported as partial; DNS restart: same. Counter reset or
  eviction: the generation changes. Duplicate and out-of-order reports: handled
  at ingestion. Control plane down: the journal holds up to 24 hours, then
  explicit lost markers. ClickHouse down or telemetry loss: path B is unaffected
  and the reconciliation flags path A. Domain deletion and owner change: see
  attribution.
- **Remaining loss windows, stated.** (a) A cell crash loses up to one pull
  interval of its counts. (b) An agent or node crash between a pull and the
  durable write loses one pull. (c) A host that never returns loses everything
  it had not reported. (d) Requests that never reach the cell counters (a
  gateway refusal, a connection dropped before the request line) are not
  counted. (e) DNS queries the kernel drops before DNSdist are not counted.
  (f) Journal overflow after more than 24 hours of control-plane outage. Delivery
  is at-least-once and ingestion is idempotent, so a delivered report is counted
  once; exactly-once and billing-grade completeness are not claimed. They need
  at least 30 days of reconciliation inside the ratified threshold and the
  owner's explicit acceptance of windows (a) to (f).
- **Cost and feasibility.** Seven shared-dictionary increments cost 2.2 to 3.2 µs
  per request on one worker, 0.5 to 0.8% of the 402 µs cached-object CPU per
  request and 0.1% of the 2,839 µs balanced-WAF figure in the Measurement rules.
  Cross-worker contention, dictionary bytes per key and the DNSdist per-zone
  counter cost at the reference query rate are not measured and are the first
  qualification experiments. If DNSdist per-zone counting exceeds its budget (the
  candidate is no more than 1% of query CPU), DNS usage stays on the analytics
  path and is labeled "analytics-grade" in accounting.
- **Experiments required before implementation.** Per-worker contention and
  memory of the counters under `edge_cell_benchmark.py`; DNSdist per-zone cost
  under `dns_benchmark.py`; kill and restart of a cell, an agent and a DNS node
  during load with the loss measured against the stated windows; duplicate,
  reordered and delayed report injection; a control-plane outage of 1 hour and
  of more than 24 hours; a ClickHouse outage; an ownership change inside an hour.
  Expected outcome: measured loss never exceeds windows (a) to (f); duplicate and
  reordered reports change nothing; an outage beyond 24 hours yields explicit
  lost markers. Failure handling: any result outside this keeps accounting
  labeled operational and blocks the claim of finalized usage.
- **Dependencies.** Stage B.3 states and attribution, the edge agent heartbeat,
  the DNS node agent, and the backup of the usage tables. Do not implement
  invoicing, pricing, wallets or payment processing.

**Acceptance.** Storage ownership is documented and each table is classified.
No PostgreSQL table grows with request volume in the measured run. The usage
build catches up after a ClickHouse outage, marks the gap `incomplete` and
never writes a final zero for missing telemetry. Both panels remain usable with
ClickHouse stopped, within a recorded time, with age and status on each
summary. A domain deletion and an owner change leave earlier accounting intact.

### Stage C: Anonymous edge-generated pages

**C.1 Inventory and design.** Enumerate every response the edge writes itself:
browser challenge and failure, access denied, security block, rate limit,
origin unavailable, timeout and TLS failure, gateway error, service
unavailable, capacity rejection, maintenance, and unknown or unavailable
domain (including the gateway's empty 421). Use one small shared template for
the HTML case: neutral background, system font stack, spacing, a simple heading,
one or two short sentences, a responsive layout, light and dark through
`prefers-color-scheme`, and an optional opaque reference. Reuse the existing
challenge styling. No external CSS, font, script, image, animation or framework.
Self-contained HTML with a nonce'd inline style under the existing CSP.

**C.2 Anonymity.** No product, project, platform, vendor or runtime name, logo,
powered-by line, project or repository link, or identifier that reveals the
product, in visible text, `<title>`, metadata, comments, accessible names, the
`Server` header, cookie names, DOM ids, script identifiers or static error
content. Rename the challenge cookie and element ids to neutral values with an
overlap period so a challenge in flight survives the change. Replace Nginx's
built-in error pages, which name the server software, for every edge-generated
status.

**C.3 Correctness.** Preserve each status code, `Retry-After`, content
negotiation (JSON and API clients keep a machine-readable body), HEAD without a
body, `Cache-Control: no-store`, the CSP and challenge nonces, challenge
verification, accessibility and existing security semantics. Do not expose
origin addresses, stack traces, rule internals, versions or topology. Origin-
generated error pages are not replaced unless an existing policy requires it.
**Observed response matrix (27 November 2026, executed).** A disposable cell on
the runtime image with this checkout's runtime and Nginx configuration, driven
directly on its HTTP and TLS listeners with `curl`; the gateway was not in the
path (source shows it relays bytes and writes only the fixed 421). Origins were
disposable containers. The stale window was 3 s on a 1 s edge TTL.

| Case | Visitor receives |
| --- | --- |
| Origin DNS failure, refused connection, response timeout (after 1.1 to 2.6 s) or TLS verification failure, cold cache, browser GET | `502 Bad Gateway`, built-in HTML page of 154 bytes with the server software name in its footer, `Server: openresty`, connection kept alive, `X-CDNF-Cache: MISS` |
| The same with `Accept: application/json` | The same HTML 502 |
| HEAD | 502 with the page's headers and no body |
| HTTP/1.0, HTTP/1.1, cleartext HTTP/2, HTTPS with HTTP/1.1, HTTPS with HTTP/2 | The same 502 on each, with `Connection: close` on HTTP/1.0 |
| Request with `Authorization` or `Cookie`, or a POST (cache bypass) | 502 with `X-CDNF-Cache: BYPASS` |
| Expired object inside the stale window (timeout and refused origins), GET | 200 `STALE`, after the origin attempt failed (1.1 s refused, 2.5 s timeout) |
| The same, HEAD, in the window | 200 `STALE` over HTTP/2; a later HTTP/1.1 HEAD was a 502 (`EXPIRED`), which timing suggests was past the window but was not isolated |
| Expired beyond the stale window, bypassed request, or uncached path | 502 (`EXPIRED`, `BYPASS`, `MISS`) |
| Connection closed without a response | Never observed |

Findings: every failure class maps to 502, API clients get HTML, the page names
the runtime, a timeout is not a 504, and each stale response waits for the
origin attempt to fail. The internal `444` and the stale fallback work as
designed and must not change.

**Decision.** Keep the internal `444` and the outer cache's stale-if-error.
Before the inner `ngx.exit(444)` the origin failure handler stores its class
(refused, DNS, TLS, blocked destination, connect timeout, response timeout,
capacity) in a bounded shared-dictionary entry keyed by the request identifier
(a 10-second lifetime, one entry per in-flight failure, a fixed cap, and the
class `unknown` when absent). The outer location replaces the built-in 502 with
an internal handler that reads it and answers: 502 for refused, DNS, TLS and
blocked destination; 504 for either timeout; 503 with `Retry-After` for
capacity; 502 for unknown. Negotiation reuses the existing rejection logic: HTML
only when `Accept` lists `text/html`, JSON (`{"error":"origin_unavailable",
"message":"...", "reference":"..."}`, with the Stage D reference) otherwise; HEAD
returns the status and headers without a body; `Cache-Control: no-store`; the
error is never cached. The page is the anonymous template of C.1 and C.2. No
rule requires terminating the connection, so none is added; if a later security
policy requires it, it is an explicit, named exception. Origin-generated error
pages are untouched.

**Required tests and acceptance.** The matrix above becomes an E2E script under
`tests/e2e` on a real cell (IPv4 and IPv6): status per class, the anonymous
body, JSON for API clients, HEAD, the five protocol paths, STALE inside the
window and the error after it, bypass behavior, the error not being cached,
and no forbidden string in any body or header. A before and after throughput
run on the success path shows no change. Failure handling: if the shared entry
is missing, the answer is 502 with the generic page, never a hang. Dependency:
Stage D for the reference, and Stage E for no unrelated restart.

**Acceptance.** A scripted audit lists every edge-generated response, and a
search of every response body and header for the forbidden names finds none.
Each page has a rendered snapshot in light and dark at desktop and phone width,
an accessibility scan, and a functional check (status, headers, HEAD, JSON,
challenge solve and failure, retry). Browser qualification uses the existing
disposable-cell suites. The `X-CDNF-*` internal request headers are unchanged.

### Stage D: Configurable public headers and domain diagnostics

**D.1 Namespace.** One typed platform setting holds the public prefix, default
`cdnf-`. Validate it as an HTTP field-name token with a length limit, lowercase,
ending in a hyphen, and reject collisions with standard headers, the internal
security and forwarding headers (`X-CDNF-Internal-*`, `X-CDN-*`,
`X-Forwarded-*`), authentication and cookie headers, hop-by-hop and reserved
protocol fields, and any current platform control header. The internal control
namespace is fixed and never customizable. The value is compiled into the
existing signed snapshot, applied by cells on activation without a restart, and
read by the runtime from the snapshot instead of from string literals. During
mixed-version rollout a cell without the field keeps the current names; the
snapshot carries both until every cell reports the new version, then the old
names stop. State the migration of previously exposed `X-CDNF-*` names, and
rename the telemetry-only upstream channel so it no longer shares the public
prefix.

**D.2 Default headers.** The public contract is `ray`, `cache-status`, `pop`
and `mitigated` under the prefix, for example `edge-ray`. `ray` is the real
per-request identifier, unique per request and never copied from a cache entry,
a client or an origin, and is the same value stored in `request_id` so a visitor
reference joins a log line (Stage J). `cache-status` reports the actual cache
decision. `pop` is an intentionally public label set by the administrator, not
a host, cell or topology name. `mitigated` appears only on responses the edge
itself refuses and that policy permits disclosing. No IP, cell identifier,
version, revision, rule internals or credential appears in any default header.
Client, origin and response-rule values for any header in the public namespace
are removed or overwritten, and the existing response-rule validation rejects
a rule that names it.

**D.3 Diagnostics.** Replace the single global switch with a platform policy and
a per-domain value: inherit, enabled or disabled. Platform controls: the prefix,
public-header enablement, the default diagnostic policy and an override that
forces diagnostics off everywhere. New domains inherit a default of off, and an
existing explicit setting is preserved, including a platform value that is
currently on. Diagnostic headers use the same prefix with a clear `diag-`
suffix group. Sensitive diagnostics (origin role, transitions, errors, rule
details) stay in authenticated interfaces. Authorization follows the domain
policies, the value travels in the signed snapshot, and a rollback restores the
previous value.

**Acceptance.** Changing the prefix reaches every cell without a restart and
the old names stop at the stated time. A hostile client, origin and response
rule cannot inject or suppress a public header. A cache hit shows its own ray.
Each default header appears on the right responses only, and a domain with the
override off emits no diagnostic header. Tests cover authorization, tenant
isolation, cache correctness, signed propagation, rollback and a mixed-version
fleet, on IPv4 and IPv6.

### Stage E: Fleet service-level reconciliation

**E.1 Impact map.** Add a declared dependency map from each configuration input
to the smallest safe action, and compute the plan from it. The ladder is: no
operation; atomic file update; supported live reload; targeted restart;
targeted recreation; dependency-ordered rollout; and a node-wide activation
only when nothing narrower is safe. Inputs to map: host firewall, management
and telemetry allowlists, Prometheus targets and rules, Grafana provisioning,
Loki, Vector, TLS material, environment values, secrets, images, database and
edge and DNS configuration, networks and volumes.

**Observed behavior (27 November 2026, executed offline and on a disposable
gateway).** Fleet's renderer was run on a four-node fleet (control with
colocated monitoring, DNS, DNS and edge, edge), one change at a time from one
saved state, and the rendered files and `docker compose config --hash` of every
service were compared; the activation sequence was replayed with real Compose
and a disposable Caddy measured recreation against reload. Generated PKI
differences between separate renders were an artifact of the method and are
ignored.

| Change | Files changed | Service hashes changed | Result |
| --- | --- | --- | --- |
| Identical configuration | none | none | The plan is empty; no node is touched |
| Add or remove one host firewall address | `firewall.sh` on every node | 0 | No container recreated by Compose, yet every node gets the full activation, migrations included, and the firewall chains are flushed and rebuilt |
| Management networks | `.env.prod` on the control node | 7 of 29: `caddy`, `core`, `horizon`, `log-pusher`, `migrate`, `notifier`, `scheduler` | Application workers are recreated for a gateway allowlist |
| Edge public address (feeds the edge, control and log-source allowlists, the firewall and the scrape targets) | `.env.prod`, `firewall.sh`, four generated target files, the geo policy | 1 of 29 on control: `caddy` | The target files change on disk and no service sees them (H24) |
| Scrape address of one edge | target files on control; the rendered Compose file on the edge | 0 on control; `node-exporter` and `vector` on the edge | Targets stale on control (H24) |
| Telemetry allowlist | derived from the same public addresses; no independent input | as above | There is no separate telemetry-allowlist change; why `telemetry-gateway` did not change hash was not traced |
| Gateway recreation against `caddy reload` (disposable) | n/a | n/a | Recreation failed 297 of about 1,010 requests and left a 2.3 s gap; reload failed 0 with a 55 ms longest gap, took 372 ms and accepted a validated change; an invalid file left the old configuration serving |

**Minimum safe action per category (decision; qualification required).**

| Category | Minimum safe action |
| --- | --- |
| Identical rendered bundle | No operation, no activation, no migrations |
| Host firewall address list | Re-run the firewall step on affected hosts only. Build the new rules in a fresh chain and swap the jump atomically so the filter is never empty. No `up -d`, no migrations, no container action |
| Management and telemetry allowlists | Render them into a file in a stable live configuration directory that is updated by atomic rename inside the directory, include it from the gateway configuration, validate it, and run `caddy reload`. Pass the value as an environment variable only to a service that reads it. The Laravel services, queues, workers and databases are not recreated |
| Prometheus targets and rules, Grafana provisioning and dashboards, Vector and Loki files | Replace the file in the stable live directory (not the swapped bundle), then use the service's supported reload (Prometheus reload, Grafana's provisioning watcher, Vector validation then signal) or one targeted restart where none exists. Verify by reading the running service's view, not the host file |
| Environment value used by one service | Recreate that service only, in dependency order |
| Image digest | Recreate the services that use it, in dependency order, with the existing health gates |
| TLS material and secrets | Update in the live directory and reload where supported; otherwise a targeted recreation |
| Database configuration | A targeted recreation of that database with its own gate and a stated impact; never as a side effect of another change |
| Migrations | Run only when a release changes the migration set, not on every activation |
| Node-wide activation | Only for a release or an operator-requested rebuild |

**Root causes to remove.** (1) Mounted files come from the swapped bundle
directory, which Compose cannot see change (H24). (2) One shared environment set
reaches services that do not read it, which recreates workers for a gateway
setting (H25); the fix is to narrow what each service receives, after reading
which services actually read each value. (3) The plan is per node and has no
service level, so the activation always includes migrations and the firewall.
(4) The firewall flush.

**E.2 Plans.** The CLI and the local web page show one service-level plan per
change: host, service, change, action, reason, expected availability effect,
validation and rollback, derived from the same engine. Secret values are never
printed. Signed releases, digest-pinned images, secure firewall operations,
SSH, the deployment lock, HA ordering, boot recovery and the last-known-good
bundle stay as they are. A failed narrow step rolls back that step and leaves
the rest.

**E.3 Verification.** Add the rendering comparison above as a Fleet test (it
needs no host): for each change it asserts the files and the service hashes that
may change and fails on any other. Regression tests on real containers prove that unrelated
container IDs, start times and restart counts are unchanged for an allowlist
edit, a dashboard edit, a Prometheus rule edit and a no-op apply, and that the
targeted service did change. Measure request and DNS continuity during a reload
and a targeted recreation. Cover partial failure, an invalid configuration
refused before activation, and rollback.

**Acceptance.** Each row of the map has a test. An allowlist edit changes no
container. A dashboard edit leaves PostgreSQL, ClickHouse and Laravel
untouched. An identical bundle produces an empty plan.

### Stage F: Origin health state correctness

**F.1 States.** Use one vocabulary: disabled, pending, healthy, unhealthy,
degraded, unknown and stale. An origin with active monitoring off shows
`Health check disabled`. Absent evidence is unknown or pending, never
unhealthy.

**F.2 Separate signals.** Store the scheduled active result, the manual test,
the passive traffic failures, the runtime failover state and the historical
reliability as separate fields or rows keyed by record and origin role (primary
or backup), each with its source, observer, time and configuration checksum.
A passive failure never overwrites an active result and the reverse. Turning
active monitoring off clears the active state to disabled and does not disable
passive detection or runtime failover. Handle an origin edit (checksum
mismatch makes old evidence stale), a delayed or out-of-date report (dropped on
revision mismatch), deletion, primary and backup roles and edge reassignment.
Expire evidence by age to stale. Expand and Contract the column, with old
`origin_health` readable until the new fields are written.

**Acceptance.** The panel, API resource and Grafana show the same state for the
same record. A table test covers every state transition, including disabled
with an unhealthy passive report, and the runtime failover decision is shown to
be unchanged by the monitoring switch.

### Stage G: Dedicated, budgeted origin health-check infrastructure

**Admission and reconciliation with the architecture rules.** The owner admits,
on 27 November 2026, exactly one new optional container per edge host: the
origin health-check worker. It is not a microservice: it has no inbound API, no
datastore and no domain model, and it exists per host, never per domain. It
reuses the agent's probe code and signed task contract. All other restrictions
stand, including no per-domain process, timer or container. The AGENTS.md
statement about new services and the roadmap rules that forbid a new service
are amended by this narrow exception when implementation starts, not before.

**G.1 Container.** A separate Go binary built from the edge-agent module that
shares `runOriginTest` and `origin_policy`, started by the existing Fleet
lifecycle as its own Compose service, not inside a serving cell, the gateway,
a request worker, the agent process or Laravel. The agent only delivers signed
tasks and forwards results. The container has its own lifecycle and readiness
report, explicit CPU, memory and PID limits, bounded concurrency, a bounded
queue, a bounded outbound rate, a read-only root with no other mounts, no
capabilities, and an egress rule that permits only public origin addresses and
the agent's local socket. Candidate limits to ratify from measurement: 0.25
CPU, 128 MB, 128 PIDs, 8 concurrent probes, a 256 task queue. Priority
protection for serving is by cgroup weight and a host-level check that the
worker stops pulling work when the cell's CPU or connection headroom is below a
threshold. Updating or restarting the container never restarts a serving cell.

**G.2 Platform switch.** A typed administrator setting enables or disables
active origin monitoring for the whole platform. Disabled means: no scheduling,
queued and expiring probe tasks cancelled safely, no outbound probe, evidence
set to disabled, passive detection and failover untouched, and the container not
deployed or stopped by the Fleet lifecycle so it costs nothing idle. The
platform switch overrides every domain and origin setting; a domain switch
works only while the platform allows it. The default for new installations is
off. On upgrade it is on if any origin already has a scheduled check, and the
migration states this; an operator can turn it off at any time.

**G.3 Regional scheduling.** The control plane schedules and never probes. For
each origin it selects a bounded set of regions from the domain's real serving
topology (its pools and placements), and from each region one representative
worker, instead of every edge. Starting design: one worker per eligible
region, at most one normal scheduled probe per region per origin per interval,
a configurable maximum number of regions and workers per origin, a hard
per-origin probe budget per interval, and a deterministic stagger. Do not
assume every edge in a continent reaches an origin alike. The implementation
design must justify the grouping (continent, region, pool or administrator
set) against the actual fleet's region labels and pools, with the weaker
policies kept as evaluated alternatives. One automatic policy is the default;
administrators control only the minimum interval, global and per-destination
budgets, the maximum representative count and worker budgets. A domain user can
lower, never raise, any limit.

**G.3a Grouping decision (27 November 2026).** Four policies were compared on
what the repository already knows.

| Policy | Existing data | Verdict |
| --- | --- | --- |
| Continent | Set when an edge is enrolled (country and continent) | Too coarse for reachability; use only as a fallback when a region label is missing |
| Region | The `region` label already carried by edge metrics and the analytics events | Best existing proxy; one representative per region |
| Serving pool | Pools and domain placements | Says which edges serve a domain, not whether they reach an origin the same way; use it to restrict the candidate set, not to group |
| Network and egress | No field exists | Cannot be derived. Allow one optional, administrator-set `probe_group` label on an edge for the case where edges in one region leave through different networks. No topology system is added |

Decision: a reachability group is the pair (region, origin-facing address
families the edge can use), taken only from edges that serve the domain. The
control plane picks the (at most three) groups present in the domain's
placement with rendezvous hashing on the destination, and in each group one
representative, again by rendezvous hashing, so a representative changes only
when that worker is unavailable or the rotation epoch changes. A dual-stack
origin alternates its probed address family between intervals inside the same
budget. An IPv6-only origin uses only groups with IPv6 egress. A group with no
eligible worker is reported as "not monitored", never as healthy. A successful
representative says "reachable from that group at that time" and nothing about
other edges; the existing passive per-edge failure reports remain the evidence
for edge-specific problems and are shown beside it. A failed representative
triggers one confirmation from a different worker of the same group first (to
separate a worker fault from an origin fault) and then from another group,
inside the per-origin budget; the fleet is never probed. A platform-wide disable
stops every new active probe, manual tests included, unless an explicit
administrator policy (default off) allows manual tests while monitoring is off.
Manual tests are one probe from one worker, at most one per origin per 30
seconds, and use the same budgets.

Missing inventory: no production fleet inventory (regions, egress networks or
address families) is available in this checkout, and the development stack has
two edges. The grouping is therefore qualified on synthetic topologies, not on
an invented production layout: one region with 3 edges; three regions with one
edge each; six regions with about 13 edges each (80 edges); 100 edges with two
egress groups in one region; IPv6-only and IPv4-only edges; and a single pool
shared by all edges. Finalizing the production grouping is blocked until that
inventory is supplied.

**G.4 Worker selection and failover.** Choose a worker by eligibility,
region, readiness, freshness, spare capacity, network compatibility (IPv4 and
IPv6) and current probe load. Assign it through the existing expiring
`edge_tasks` rows with a lease, so two workers cannot run the same probe.
Rotate the representative on a schedule. When the worker is unavailable, assign
one other eligible worker within the same budget and mark the first as a worker
failure, not an origin failure. A failed task is never broadcast to all edges.

**G.5 Origin protection budgets.** Enforce, in the control plane before a task
exists and again in the worker: probes per origin, per destination address and
port, per domain, per user, per worker and for the platform; concurrent
probes; retries and confirmation probes; response bytes (the existing 4 KiB
read); and connection duration. Mechanisms: a minimum interval, deterministic
stagger with bounded jitter, retry backoff, global and per-destination
concurrency, duplicate suppression, failure-aware scheduling, queue expiry and
load shedding. Probes for different domains that resolve to the same address,
port, host header, SNI, path and TLS policy are deduplicated into one probe whose
result is stored for each domain that is authorized to see it, and results never
cross tenants. Probes that cannot be merged share one destination budget, so
creating more domains, hostnames or records adds no probe capacity. A fleet
start or restart does not burst: first runs are spread over the interval from
a stable per-origin offset and a global start-up rate limit.

**G.6 Failure confirmation.** One failed regional probe never marks an origin
globally down. Record and show separately: confirmed origin failure, regional
connectivity failure, worker failure, local network failure, timeout, missing
evidence and stale evidence. Confirm a failure with one other eligible worker
or region, inside the same per-origin budget, then apply bounded failure and
recovery thresholds. Show results as sampled by region and say plainly that
representative probes do not show that every edge reaches the origin. Active
evidence never withdraws a healthy serving edge or changes global routing on
its own, and passive per-edge traffic evidence is kept apart from it.

**G.7 Policies.** Ship the automatic policy of G.3a. A bounded administrator set
of groups is allowed only as an override of the automatic choice. Every number
in G.11 is a candidate until its qualification passes.

**G.11 Numeric limits.** Classes: **D** product default; **A** administrator
limit within a range; **C** non-bypassable ceiling that no setting can exceed;
**P** deployment-dependent capacity, computed and not configured; **B**
threshold taken from a benchmark.

| Parameter | Candidate | Class | Basis |
| --- | --- | --- | --- |
| Platform active monitoring | off | D | New installs; see G.2 for upgrades |
| Origin active monitoring | off | D | Needs the platform setting on |
| Probe interval | 60 s | D | Equals the current minimum in validation |
| Minimum interval | platform floor 60 s; an administrator may lower it to 30 s; nothing below 30 s | A, C at 30 s | A 30 s floor needs the destination budget below to hold |
| Representatives per group | 1 | D | G.3a |
| Representatives per origin | 3, ceiling 5 | A, C | Simulation: 3 probes per origin per interval at any fleet size |
| Concurrent probes per destination (address and port) | 1 | C | Not configurable |
| Probes per destination per minute, including confirmations | 8 | C | 3 representatives at 60 s plus one confirmation is 4; at a 30 s floor 6 plus 2 is 8, so it just fits |
| Probes per origin per interval | representatives plus 1 confirmation | C | Simulation: 4.0 when every origin fails |
| Connect timeout, total timeout | 3 s, 5 s | C | Clamps the origin's own 100 to 10,000 ms and 500 to 60,000 ms for probes |
| Response body read | 16 KiB | C | The agent reads 4 KiB today |
| Redirects | never followed | C | Existing behavior |
| Failures to unhealthy, successes to recover | 3 and 2, range 1 to 10 | D, A | Detection takes about 3 intervals plus a confirmation; the flap behavior needs a scenario run |
| Worker container | 0.25 vCPU, 128 MB, 128 PIDs | C | Stand-in measurement: CPU is the limit (8 to 15% at 20 probes per second, saturated at roughly 40 to 75 probes per second), RSS under 10 MiB, PIDs under 20; keep memory and PIDs as ceilings |
| Worker concurrency and queue | 8 slots, 64 queued tasks, a task expires after one interval | C | 8 slots over a 5 s timeout is at most 1.6 probes per second in the all-dead case |
| Worker budget | 4 slot-seconds per second (50% of the slots), charged by measured duration | P, B | A dead origin costs 5 s, a healthy one about 0.2 s; sustained dead-origin load is then at most 48 per minute per worker |
| Probes per account | 300 per minute | A, P | Candidate; set from the platform budget and the number of accounts |
| Platform total | the sum of worker budgets at 50% | P | Computed |
| Manual tests | 1 per origin per 30 s, counted in all budgets | C | G.3a |
| Serving protection | the worker stops taking work when the cell's CPU or connections pass a threshold taken from the cell benchmark | B | Not yet measured |

Admission rule: the scheduler charges every probe its measured duration and
stretches the interval of a slow or failing origin (never beyond five
intervals) when demand exceeds the worker budget; it reports those origins as
`Delayed (capacity)` and refuses to enable more monitoring when the platform
budget is already committed. The simulation shows why: with 1,000 origins the
busiest worker handles 1,000 probes per minute on 3 edges and 515 on 10, far
over the 48 a worker can sustain against dead origins, while at 80 edges it is
55 and at 100 edges 45.

**G.8 Network and probe security.** The worker probes from the same network
vantage as serving: verify equivalent reachability, source routing, DNS
resolution, IPv4 and IPv6, the Host header, SNI, the origin TLS policy of Stage
H and egress firewall rules. Keep and extend the existing controls: destination
exclusions and connection-time revalidation, address pinning against rebinding,
no private, link-local, metadata or platform destinations, no redirects, signed
task authorization with expiry and replay protection, tenant isolation, a
response-size limit and timeouts. The worker is never an arbitrary request or
scanning service: it accepts only a signed task built from a stored origin.

**G.9 Results.** Keep only bounded latest state in PostgreSQL, keyed by record,
origin role, signal and region, with an age. Store each probe observation in
ClickHouse through the existing telemetry path with a probe identifier for
idempotency and a TTL. No PostgreSQL row per probe. The control surfaces show
the latest state while ClickHouse is down.

**G.10 Qualification.** Use real containers and a simulated-edge harness for
3, 10, 80 and 100 or more edges; several continents and regions; several edges in
one region; several domains on one origin; worker failure; whole-origin and
regional origin failure; control plane down; container restart; platform switch
off; domain switch off; delayed, duplicate and expired tasks; a configuration
change during probing; resource saturation; and a simultaneous fleet start. Show
that the number of probes, the maximum concurrency and the load on each
destination follow the configured budget and not the edge count (the count at
10 and at 200 edges must be equal for the same origins and regions apart from
confirmation probes), and measure CPU, memory, network, and the effect on
serving throughput and latency with the benchmark in `edge_cell_benchmark.py`
with probing on and off.

**Executed so far (27 November 2026).** The scheduling model gave identical
probe counts at 3 to 400 edges (H32), 600 probes per interval instead of 30,000
for 10,000 domains on 200 shared destinations, an unchanged count after a
representative died, and 4 probes per destination per minute with every origin
failing. The stand-in worker measured CPU and memory (H31). Not executed: the
real worker, the 100-edge harness against real containers, restart and
saturation scenarios, simultaneous fleet start, and any effect on a loaded
cell. Those need the reference host and `edge_cell_benchmark.py` and remain
required.

**Acceptance.** The criteria in G.10 hold with recorded numbers. Disabling the
platform switch stops all probes and every container within a stated time. The
serving benchmark difference is inside the ratified noise bound. No scenario
produces a probe the budget forbids.

### Stage H: Origin TLS verification default

New origin configurations default to **Verify origin TLS: off**. This is an
explicit product requirement and it lowers the default protection from the
Phase 0 position that the panel defaults to on. Required work:

- Audit every entry point: domain creation, DNS and proxy record creation, the
  admin and domain panels, the API (where `verify_tls` is currently required),
  imports, primary and backup origins, runtime configuration and the
  health-check worker. Define the omitted-field behavior of the API as an
  Expand and Contract change to the OpenAPI document: an omitted value on create
  becomes false, an explicit value is always kept, and an omitted value on
  update never changes the stored one.
- Preserve every stored explicit value. An old configuration that lacks the
  field is not treated as unverified by the migration; its current effective
  behavior is read and kept.
- Keep the setting easy to turn on. When on, the runtime must enforce the
  certificate chain, hostname, validity dates and the SNI. When off, still send
  the SNI that the origin expects and apply every destination check.
- Document that HTTPS without verification encrypts the connection but does not
  authenticate the origin, so a network attacker can impersonate or intercept
  it, and put that wording next to the control in the panel and the API
  reference. The health-check worker follows the same per-origin policy.
- Do not change TLS verification for any other platform connection (control,
  agent, ACME, telemetry, DNS and backup).

**Acceptance.** A new origin created through each entry point stores false;
an existing true or false is unchanged by upgrade; enabling it produces
verified connections and a failure on an invalid chain, name or date; the
worker agrees with the runtime in a paired test; documentation and the OpenAPI
check pass.

### Stage I: Platform health and customer health

**I.1 Platform health.** Only conditions of the shared platform: control plane,
database, queues, DNS infrastructure, the edge fleet, shared routing,
configuration delivery, infrastructure certificates, telemetry, backup
readiness, high availability and shared capacity.

**I.2 Customer health.** Domain-specific conditions: pending or lost delegation,
unconfigured nameservers, customer DNS problems, origin availability, a
customer's certificate problems and domain configuration errors. These are
shown to the domain's users and to authorized administrators, in a separate
group and a separate count. A new, unverified domain, or any number of them,
never changes the platform status or the public status page. A platform fault
that affects customer domains (a failed placement, an undeliverable revision, a
DNS deployment that fails for a verified domain) stays in platform health. Do
not hide an infrastructure fault by calling it customer-specific; classify each
existing check by cause, with a test per classification, starting with failed
edge tasks, failed TLS orders and pending DNS deployments (H22).

**I.3 Consistent semantics.** Review the public status page, liveness and
readiness endpoints, the administrator health API, customer dashboards, Grafana,
Prometheus, Alertmanager, Fleet health gates, notifications, availability
calculations and SLOs, and use one state vocabulary: serving availability,
management availability, observability availability, customer configuration
health, regional health, disabled and insufficient evidence. An unavailable
analytics database is an observability fault and never reports serving as down.

**I.4 Root cause of DNS deployment drift (executed 27 November 2026).** A
temporary feature test on the isolated in-memory database ran the real
`ReconcileDnsZone` job, `retireCompetingClaims` and `SystemHealth` against a
faked PowerDNS API, one scenario after another. `drifted` is the count of
deployments that are `failed`, or `pending` and not a tombstone.

| Scenario | Deployments created | Drifted | `dns_deployments` and `authoritative_dns` |
| --- | --- | --- | --- |
| New domain, no nameservers configured | One, `succeeded` (delegation zone) | 0 | healthy |
| 22 pending domains, none delegated | One each, `succeeded` | 0 | healthy |
| Delegation-zone deployment fails (PowerDNS 500) | One, `failed` | 1 | degraded; back to 0 after a retry succeeds |
| Delegation-zone deployment rejected (PowerDNS 422) | One, `failed` | 2 | degraded; back to 0 after a retry |
| Competing claim while a holder is active | None (deferred) | 0 | healthy |
| Unverified child of a managed parent | None (deferred) | 0 | healthy |
| Takeover: the winner inherits a `pending` row with revision 0 | One, `pending` | 1 until its first reconcile, then 0 | degraded in between |
| Active domain, delegation lost | Unchanged | 0 | healthy (`delegation_lost_at` touches no deployment) |
| Active zone, PowerDNS 500 | `failed` | 1 | degraded; recovered to 0 |
| Cluster health check failing | none | unchanged | `authoritative_dns` degraded |
| Active zone, PowerDNS 422 on customer content | `failed` | 1 | degraded; recovered to 0 |
| Retirement of a deleted domain fails | `failed` tombstone | 1 | degraded |

Counts in the table are cumulative while an earlier failure was still
unrecovered.

**Root cause.** Unverified, pending, child, competing and lost-delegation states
do not by themselves degrade platform health. The scope has four real defects:
(1) a planned `pending` row (the takeover winner) counts as drift at once, as
the code comment says only a planned tombstone should not; (2) there is no
classification, so a PowerDNS 422 on customer zone content cannot be told from
an infrastructure failure, and both degrade the platform; (3) any single failed
deployment flips the DNS rows of the public status page, with no age and no
share-of-domains threshold; (4) `cdnf:dns:repair-drift` checks 100 deployments
every five minutes, so a large outage clears slowly. Affected components:
`SystemHealth` (`dns_deployments`, `authoritative_dns`), the metric
`cdnfoundry_dns_deployments_drifted`, the public "Nameservers" and "Zone
changes" rows and the platform alert. Failed edge tasks and failed TLS orders
(H22) were not run; their source behavior is the same pattern.

**Decision.** Customer-side delegation problems never independently degrade the
platform, and platform-owned provisioning failures stay visible. Record on each
deployment a failure class and the time of its first failure:

- `platform`: transport errors, timeouts, 5xx, a failed read-back or rollback,
  authentication or server-identity errors, and a failed retirement. Degrades
  the platform after a short threshold (candidate 2 minutes) so a retry window
  does not flap the status.
- `customer_content`: PowerDNS 422 or 409 for a zone that rendered and validated.
  Shown to the domain's users and to administrators in a customer-health list;
  not counted as a platform fault. If more than a ratified share of domains
  (candidate 5% or 20 domains within 15 minutes) hit it, that is a platform
  validator mismatch and degrades the platform.
- `planned`: a `pending` row created by a takeover or a revision change. Counts
  only when older than a threshold (candidate 10 minutes, above the queue
  latency and inside the 30-minute retry window).

Delegation states (pending, lost, superseded claim) are customer-health states
only. The unverified-domain exclusion is by cause, not by lifecycle: an
unverified domain whose delegation-zone deployment fails because PowerDNS is
down still counts. The repair command orders platform-class failures first and
its rate is sized from the failed count.

**Regression tests (feature tests, in-memory database).** One test per scenario
above asserting `drifted` split into platform and customer series, the
`SystemHealth` rows, the metric labels and the public snapshot; frozen-time
tests for both thresholds; a test that 22 unverified domains plus a customer
failure leave the status healthy while one platform failure degrades it; the
repair ordering and rate; and the same classification for failed `origin_test`
tasks and failed TLS orders. Failure handling: an unclassified failure is
treated as `platform`, never hidden. Dependency: a Stage B-style
Expand and Contract migration for the two columns.

**Acceptance.** A test fixture with many unverified domains, failed customer
orders and unhealthy customer origins leaves the platform status healthy and
shows each customer condition to its owner. A fixture with a real platform
fault changes it. Every consumer of health uses the same classification.

### Stage J: Cross-system reliability

Evaluate with a measurement for each item and fix only what is demonstrated:

- Correlation: the public `ray` equals the ClickHouse `request_id`, appears in
  the Loki line that mentions it, and links the request trace dashboard, the
  panel log view and the support reference. Operation and task identifiers carry
  through the control plane, agent and Loki.
- Configuration drift and stale acknowledgements; signed snapshot delivery;
  activation on edges and cells; security dataset synchronization.
- Telemetry failure isolation and buffer saturation, with drops counted.
- Database query amplification, excessive polling and unnecessary scheduled
  work (the scheduler has 22 per-minute jobs of 45 entries, and the edge agents poll),
  resource exhaustion, slow dashboards and wrong timeouts.
- Missing alerts for the new signals of Stages A, B, F and G and for backup
  and recovery completeness, including the accounting tier.
- PoP-to-PoP listener probing grows with the square of the edge count; measure
  it at 80 and 100 edges and cap it if its cost is not negligible.
- Serving continues while ClickHouse, Loki, Grafana, Prometheus, the telemetry
  transport and the control plane are each stopped in turn; the WAF, challenge,
  JWT, IP list, shared rate-limit, reputation and overlay guarantees hold.

**Acceptance.** Each item has a recorded measurement and either a fix with its
test or a documented reason it is not needed. The serving-continuity matrix
passes on real cells.

### Stage K: Qualification

Per stage, close the four gate fields separately. Use the existing tooling:
`grafana_observability.py`, `edge_cell_benchmark.py`, the E2E suites in
`tests/e2e`, the contract and qualification tests, Playwright under
`tests/browser` and `production_qualification.py`; add scripts only for new
behavior. Required evidence:

- The complete panel inventory and the runtime query run, with Loki templates.
- PostgreSQL growth at two request rates, the storage formula and the
  ClickHouse-down check of both panels.
- Usage catch-up, reconciliation, correction, late-event, ownership-change and
  incompleteness tests, and the billing-readiness limitation text.
- The page audit and the forbidden-name search; the prefix, spoofing and
  diagnostics tests.
- The Fleet no-restart tests with continuity numbers.
- The origin state table, the platform switch, the 3 to 200 edge probe runs
  with budget, resource and serving-impact numbers, and the TLS default tests.
- The platform and customer health fixtures and the security regression suite
  for every changed runtime path.
- The percentile tolerance run of Stage A.2a and the dashboard scan for averaged
  percentiles; the origin-failure matrix of Stage C.3 as an E2E; the Fleet
  rendering and container-continuity tests of Stage E; the accounting
  experiments of Stage B.7; and the synthetic-topology and probe-budget runs of
  Stage G.
- A before and after measurement for each performance-relevant change on the
  same host and workload, with budgets taken from the baseline and not
  invented, and an upgrade and rollback run from the previous release.

A test that was not executed is not reported as passed, and planning is never
recorded as implementation.

**Dependencies.** Phase 0 evidence reconciliation, and the shipped Phases 3 to 9
and the Phase 10 paging and trace already on `dev`. Stage G needs Stages E, F
and H. Stage D needs Stage E (no unrelated restart). Stages A.2 and A.3 need the
new signals of B, F and G. Phase 10's remaining episode and view work uses the
summaries of Stage B and must not add its own storage. Phase 11 requires this
phase complete.

**Migration requirements.** Expand and Contract for every changed field
(`origin_health`, usage columns and the cascade, the header setting, the
verify default). Each migration is production-safe, reversible or paired with a
restore point, and ships with a reconciliation check. PowerDNS migrations stay
separate. No volume removal, no destructive refresh, and no `RefreshDatabase`
test against PostgreSQL.

**Verification.** PHP feature tests for permissions, validation, idempotency,
failure and last valid state; Go and Lua tests for the runtime, agent and the
worker; Python E2E under `tests/e2e` on real cells and containers with IPv4 and
IPv6; the Phase 1 harness re-run with numbers recorded; and `tests/browser` for
every changed screen and page with snapshots and accessibility checks. The
documentation (operators, users, API reference, OpenAPI, Fleet reference,
Grafana reference and the roadmap record) is updated with each stage.

**Resolution of the remaining uncertainties (27 November 2026).** Every open
item of the first pass was worked on. Status vocabulary: **Source** resolved
through source evidence; **Executed** resolved through executed experiments;
**Decided** architectural decision established, implementation qualification
required; **Blocked** blocked by missing runtime access or production inventory.
No experiment below is a repository test, and nothing here marks planned work
as implemented.

Experiments executed (all temporary, scripts not retained, nothing written to
the development databases or volumes):

| Experiment | Environment | Limits |
| --- | --- | --- |
| DNS drift scenarios (twelve cases) | The isolated test command: in-memory SQLite, real jobs and `SystemHealth`, faked PowerDNS HTTP | The fake models success, 500 and 422 only; edge tasks and TLS orders not run |
| Origin failure matrix | Disposable OpenResty cell on a locally built runtime image, disposable origins | Direct listeners; no gateway; no HTTP/3; IPv4 only |
| Fleet rendering comparison | Offline renderer, a four-node state, `docker compose config --hash` | No host, no real activation, no gate; Compose recreation inferred from hashes |
| Activation-swap replay | Real Compose, two services, the exact rename sequence | Two toy services, not the production bundle |
| Gateway recreation against reload | Disposable Caddy with a 5 ms request loop | Stand-alone, no health wait, no Fleet |
| Dashboard inventory and run | The development Grafana, read-only | Six-hour range, dev data, All for every variable |
| Percentile comparison | Disposable ClickHouse with the repository schema, 2.0 million events | Synthetic distributions; the histogram is a model of the 11-bucket estimate, not a Prometheus run |
| Probe worker resource cost | Go stand-in under 0.25 vCPU, 128 MB, 128 PIDs, local origin | Not the real worker; no WAN latency; the hanging-origin run was invalid and is not reported |
| Scheduling model | Python model of the proposed algorithm | A model, not an implementation; synthetic topology |
| Counter cost | One OpenResty worker, shared dictionary, 500,000 requests | One worker, quiet host, no cross-worker contention |

| ID | Question | Status | Existing evidence | Decision and required experiment | Acceptance and failure handling | Dependency |
| --- | --- | --- | --- | --- | --- | --- |
| R1 | Do unverified domains degrade platform health? | Executed; fix is Decided | I.4: ten states, drift 0 for pending, child, competing and lost delegation | Classify failures and add thresholds (I.4); extend the same scenarios to failed edge tasks and TLS orders | I.4 tests pass; an unclassified failure counts as platform | Expand and Contract columns; Stage F for origin task results |
| R2 | What does a visitor receive after the internal 444? | Executed; fix is Decided | C.3 matrix: built-in 502 for every class and client | Class-carrying shared entry and an anonymous page (C.3); run the matrix again with the gateway and on IPv6 | The E2E of C.3 passes with unchanged stale behavior; a missing entry gives a generic 502 | Stages C and D |
| R3 | Does an allowlist-only change restart unrelated services, and does a file-only change arrive? | Executed (offline and disposable); real-host check Decided | H24, H25, E.1 tables | Stable live directory, reload for the gateway, narrow environment, no migrations per activation (E.1); run the six changes on a real Fleet host recording container IDs, created and started times, restart counts and traffic | Container IDs and start times unchanged for rows that need no restart; a changed target is visible in Prometheus; reload failure leaves the old configuration | Stage E; blocks Stage G deployment of a new container |
| R4 | Is every dashboard panel correct? | Partly executed; the rest Decided | H27: 365 targets run without error; rollup values verified in A.2a only | Per-panel fixtures with written expected values and the corrections of A.1 | A panel is "verified" only with a passing expected-value check; the rest say "query valid, value unverified" | Stage A; the seeded exporter and ClickHouse fixtures |
| R5 | Do the percentile methods measure the same thing? | Executed | H28, H29, A.2a | Adopt the contract and the 1% tolerance; evaluate extra histogram buckets | Tolerance run fails above 1%; the average-of-percentiles scan finds none | Stage A |
| R6 | Can bounded edge and DNS counters give accounting that survives ClickHouse loss? | Decided; counter cost partly executed | H30, B.7 | The experiments listed in B.7, DNSdist per-zone cost first | Measured loss inside windows (a) to (f); idempotency under duplicate and reordered reports; otherwise usage stays labeled operational | Stage B.3 and B.5; edge and DNS agents |
| R7 | Do the Go services emit normalized log fields? | Source | H33 | Add `domain_id`, `cell_id`, `request_id` and `operation_id` where a template needs them; give cell errors a structured form | Each Loki template returns its seeded line | Stage A.3 |
| R8 | What is the right probe grouping for the real fleet? | Blocked for production; algorithm Decided | G.3a, H32, no region, egress or address-family inventory in the repository | Region plus address family within the serving placement, optional `probe_group`; qualify on the six synthetic topologies; finalize when inventory exists | Probe count independent of edge count on every topology; a group without a worker is "not monitored" | Production inventory; Stage G |
| R9 | Are the numeric limits safe and sufficient? | Partly executed; Decided | G.11, H31, H32 | Run the real worker and 100-edge harness; measure serving impact with `edge_cell_benchmark.py`; set the serving-protection threshold from it; scenario runs for flapping | Probe totals follow the budget at 3, 10, 80 and 100 or more edges; serving difference inside the ratified noise; any breach blocks enabling | Stage G; reference host |
| R10 | Is the long-window `quantile` bias acceptable for billing-style bandwidth? | Executed | H29 | Use `quantileExact` and write the rank definition | Equal to the exact value in the fixture | Stage A.2a |
| R11 | How do failed edge tasks and TLS orders classify? | Decided (source) | H22 | Same classes as I.4; a failed `origin_test` is customer health, a failed platform task is platform health | Fixture test of I.4 | Stage F |
| R12 | Is the firewall rebuild a fail-open window? | Source; Decided | `firewall.sh` flushes and re-adds its chains | New chain and atomic swap (E.1); measure the window with a packet loop | No unfiltered packet to an internal port during an update | Stage E |

**Implementation record, 27 November 2026.**

| Item | Result |
| --- | --- |
| Stage I.4: DNS deployment health | Implemented: deployments record `failure_class` (`platform`, `customer_content`, or none, which counts as platform) and `first_failed_at` (migration `2026_11_30_000100_classify_dns_deployment_failures`, expand-only). A PowerDNS `422` on the zone content raises `ZoneContentRejectedException` and is `customer_content`; every other failure is `platform`. The drift scope counts platform failures after 2 minutes (a failed retirement and legacy rows with no time count at once), a pending non-tombstone row after 10 minutes, and customer-content rejections only when 20 or more occur within 15 minutes. `SystemHealth` reports `customer_rejected` beside `drifted`; `cdnfoundry_dns_deployments_drifted` is now the platform count and `cdnfoundry_dns_deployments_customer_rejected` is new; the deployment API shows `failure_class` to the owner and the administrator and `last_error` to the administrator only; `cdnf:dns:repair-drift` looks at platform failures first. The thresholds are constants in `DnsDeployment`, candidates not yet ratified from fleet evidence. |
| Stage I.4: tests | `DnsDeploymentHealthTest` (11 cases on the isolated in-memory database, real reconcile job, fake PowerDNS): 22 pending domains, a competing claim, an unverified child and a lost delegation stay healthy; platform failure grace, first-failure time kept across retries and cleared on recovery; an unverified domain with PowerDNS down still counts; content rejection is customer health, with owner and administrator visibility; the surge rule and its window; takeover pending grace; failed retirement; failing cluster health check; metrics; repair order. `UnverifiedDomainTest` was updated for the pending grace. |
| Not done in Stage I | I.1 to I.3 as a whole: the failed-edge-task and failed-TLS-order classification (R11), customer-health display in the domain panel and the status page's separate customer group, the consistent state vocabulary across Grafana, Alertmanager and Fleet gates, and the Grafana panels and alert rules that read the changed metric. No browser test was run because no screen changed. |

**Completion checkpoint, 27 November 2026.**

| Implementation | Documentation | Automated/runtime | Browser |
| --- | --- | --- | --- |
| Open: only Stage I.4 is implemented (see the record above); nothing else in Stages A to K is | Open: the DNS guide and changelog describe Stage I.4 only; every other stage has no page | Open: the full PHP suite (881 tests), PHPStan, pint, the observability contract tests and `make docs-check` passed with Stage I.4; no E2E ran, and the experiments of the Resolution section are not gate evidence | Open: the page, panel, dashboard and Fleet web gates are required and not run |

Phase 10.5 is not complete.

---

## Phase 11: Full qualification and external review

**Execution plan.** Priority: P1 release gate; reproduced unsafe failure is P0.

**Ordered stages.** 1. Reconcile every earlier gate against one release manifest. 2. Execute integrated load, IPv4/IPv6, failure, recovery and soak scenarios. 3. Obtain scoped external security review and close release-blocking findings.

**Security, resources and failure boundary.** No production/customer testing or new fleet topology just for optional features. The origin health-check container admitted in Phase 10.5 is part of the standard edge host and release manifest, not an added topology. Use existing qualification tooling; choose the smallest topology that proves actual PoP/cell failover and record coverage limits. Measure whole-host headroom, disk/network limits, recovery and rollback, not summed container throughput.

Each stage closes the shared phase gate with the phase-specific tests and
acceptance criteria. Scope not named in this phase is excluded.

**Objective.** Prove that the finished WAF works as one system on a real
multi-PoP fleet, under load and failure.

**Current state.** Staging and local performance records exist, as linked in
the starting point. They leave integrated load/failure/IPv6 gates open. No
release-matched external review was established by this audit.

**Scope.**

- A production-like staging fleet that proves independent PoP failure and
  multi-cell behavior. The original three-PoP/two-region target is used only
  when the deployed routing/failure model requires it; justify the topology
  instead of creating infrastructure for an arbitrary count.
- **Attack scenarios**: an L7 flood from 10,000 addresses and from IPv6
  rotation, credential stuffing, scanners, the evasion corpus, and a headless
  browser farm. The clean corpus runs at the same time.
- **Failure injection**: control plane down, agent down, a corrupt dataset, the
  scoring listener down, full shared memory, ClickHouse down, and a PoP lost.
  Both panels stay usable with ClickHouse down, and probe traffic stays inside
  its Phase 10.5 budget during each injection.
- **A WAF performance envelope** on the reference cell: requests per second and
  latency for each profile and feature, and the saturated resource.
- **An external review** of the challenge tokens and keys, dataset signing,
  tenant isolation, and the bypass corpus.

**Dependencies.** Phase 0, required work in Phases 1 to 10, **Phase 10.5 (mandatory, complete with every checkpoint closed)**, and Phase 12 integration. Conditional capabilities need an explicit admission or deferral decision, not a placeholder implementation.

**Acceptance criteria.**

- Every scenario has a counted expected outcome, measured detection/admission
  result and stated coverage limit. Clean-traffic false positives remain within
  the baseline-derived, ratified phase budget. No promise to stop every attack.
- Each failure matches its documented policy: retain last valid serving state
  where available, honor expiry/revocation, and fail closed when identity or
  recovery material is unavailable. Record degraded capacity and recovery time.
- The sizing guide quotes only numbers from this run.
- The external review is published with its scope, findings, and fixes.

**Verification.** The harness and scenario scripts on staging, each with a
stored record listing the release, the commit, and every result. No unexecuted
test is reported as passed.

**Completion checkpoint, 21 November 2026.**

| Implementation | Documentation | Automated/runtime | Browser |
| --- | --- | --- | --- |
| Existing runner present; full release gate open | Historical records retained; current release record open | Integrated fleet/failure/external review open | Release-matched suite required |

---

## Phase 12: Request and response rules

**Execution plan.** Priority: P1 to close admitted work; implementation present.

**Ordered stages.** 1. Reconcile the existing four increment records and migrations. 2. Close complete browser, visual and accessibility qualification. 3. Integrate WAF ordering, cache-key/purge, invalid-candidate and rollback evidence on the release.

**Security, resources and failure boundary.** No second engine or repeated table/migration creation. Preserve current bounds and the recorded 50-rules-per-type benchmark comparison. Original visitor and rewritten origin targets must remain unambiguous; blocked/challenged requests cannot be transformed into an allow. Invalid state retains the active generation.

Each stage closes the shared phase gate with the phase-specific tests and
acceptance criteria. Scope not named in this phase is excluded.

**Admission.** The owner admitted this phase on 2026-11-21 independently of
the other WAF phases. Its four increments are now implemented with the
[qualification record](operations/request-rules-qualification-2026-11-21.md).
Close the outstanding browser gate and integrate security/cache regression
evidence; do not restart its migrations or implementation.

**Objective.** Let a domain redirect, rewrite, change headers, and set cache
behavior per request, with the same typed conditions as custom rules, decided
in the cell from the signed snapshot.

**Historical starting point before implementation.** A domain had typed custom rules (block, challenge, log,
allow, skip managed rules) and up to 16 cache path rules that match a path
prefix or suffix and either bypassed the cache or set an edge TTL. The only
redirect was the HTTPS redirect. Nothing rewrote the URL sent to the origin.
Request and response headers could not be set or removed per domain.

**Scope.** One **Rules** tab on the domain page, next to Firewall rules, with one
section per rule type. Every rule has a name, an on/off switch, a priority, and
up to five conditions from the custom-rule condition builder. Only fields that
are known before any detection runs are offered: hostname, path, query string,
method, country, continent, ASN, client IP, user agent, referer, header, cookie,
file extension, and TLS version. Score and detection fields are not offered, so
a rule never makes a request pay for scoring.

1. **Redirect rules.** The cell answers with a redirect and the request never
   reaches the origin. The target is a fixed URL, the same path on another
   host the rule names, or the path with one prefix replaced by another, with
   the query string kept or dropped. The status is 301, 302, 307, or 308. The
   first matching rule wins.
2. **URL rewrite rules.** The path and query string sent to the origin change.
   The visitor's URL does not. The path is set to a fixed value or has a prefix
   replaced. The query string is kept, dropped, or set to a fixed value. The
   first matching rule wins.
3. **Header rules.** Set or remove request headers sent to the origin, and set
   or remove response headers sent to the visitor. Every matching rule applies,
   in priority order. Response headers are applied on cache hits too.
4. **Cache rules.** Bypass the cache, or set the edge TTL, the browser TTL, and
   whether origin cache headers are respected. The first matching rule wins.
   Cache rules replace the cache path rules: each stored path rule becomes a
   cache rule with a `starts with` or `ends with` path condition, through an
   expand and contract migration.

**Presets.** Each type offers presets for common needs. A preset asks for a few
values, writes ordinary rules marked with the preset's name, and replaces its
earlier rules when it is saved again. A preset rule edited by hand becomes an
ordinary rule, as custom-rule presets do today.

- Redirects: www to the apex domain, the apex domain to www, and moving a path
  prefix to a new prefix.
- URL rewrites: removing a path prefix before the origin, and adding one.
- Headers: security response headers (`Strict-Transport-Security`,
  `X-Content-Type-Options`, `Referrer-Policy`, `X-Frame-Options`,
  `Permissions-Policy`), removing origin identity headers (`Server`,
  `X-Powered-By`), and allowing cross-origin use of static files.
- Cache: caching static files by extension for a chosen time, bypassing the
  cache for admin and sign-in paths, and bypassing it when a session cookie is
  present.

**Bounds.** At most 50 rules of each type per domain, five conditions per rule,
ten header operations per rule, and 50 header operations applied per request.
Redirect targets and rewritten paths are at most 2,048 bytes. Header names are
tokens of at most 64 bytes and values are at most 1,024 bytes, with no control
characters. Values are fixed text only: there is no regex, no capture groups,
and no expression language.

**Protected behavior.**

- Rules run after the security stages (custom rules, rate limits, the managed
  WAF, challenges, and the built-in limits), so a redirect or rewrite never lets
  a request skip them. Custom rules and the WAF match the visitor's URL.
- The cache key and URL purge stay on the visitor's URL. Two URLs that rewrite
  to the same origin path are cached separately, and purging the visitor's URL
  removes what the visitor sees. A cache rule's outcome is part of the cache
  policy hash, as a path rule's outcome is today.
- A header rule cannot set or remove `Host`, `Content-Length`,
  `Transfer-Encoding`, `Connection` and the other hop-by-hop headers,
  `Forwarded`, `X-Forwarded-*`, `X-CDN-*`, `X-CDNF-*`, or the headers that
  carry the challenge, security, and diagnostic decisions. A request header
  rule does not change the cache key, and the documentation says so.
- A rewritten path is normalized and must start with `/`. A path that leaves
  the root after normalization (`..`) is refused when the rule is saved and
  again in the cell. The origin's address and Host header never change.
- A redirect target is `http` or `https` with a valid hostname, or a path. A
  rule whose fixed target is the URL it matches is refused when it is saved.
  Response body rewriting stays out of scope.

**Technical direction.** One `request_rules` table holds every type: the domain,
the type, the name, the priority, the switch, the typed conditions, the typed
action, the preset, and a fingerprint. Database constraints check the type, the
priority, and the unique fingerprint. Controllers, form requests, and policies follow the
custom-rule endpoints, with cursor pagination, `Idempotency-Key`, and a `202`
operation for every change. Each change raises the domain revision and is
compiled into the signed snapshot as ordered lists per type. The cell evaluates
them in its access phase with the custom-rule condition matcher, after the
security stages and before the cache key. Response header operations run in the
header filter. Cells ship the evaluator before the control plane sends the new
snapshot field, and a snapshot without the field means no rules. The access log
records the matched rule of each type, so Analytics and Logs can show which
rule acted.

**Dependencies.** No implementation dependency on earlier WAF phases; current
release completion still requires Phase 0 and Phase 11 integration.

**Acceptance criteria.**

- Each type behaves as described on a real cell over IPv4 and IPv6, including
  a cache hit for response headers and a purge of a rewritten URL.
- A blocked or challenged request is never redirected or rewritten.
- A refused rule (a protected header, a looping target, a path outside the
  root, a value over its bound) is refused by the API and the panel with a
  stable error code, and an invalid snapshot never replaces the active one.
- Every stored cache path rule is served the same way after the migration, as
  shown by the cache headers of the same requests before and after.
- The edge cell benchmark in `tests/e2e/edge_cell_benchmark.py`, with 50 rules
  of each type and none matching, costs at most 5% more CPU per cached request
  than with no rules. The measured numbers are recorded in this phase.

**Verification.** Feature tests for each type: the happy path, permissions
(administrator, assigned user, other user), validation of every bound,
idempotency, reorder, and rollback to the previous revision. Contract tests for
the snapshot shape. `tests/e2e/request_rules.py` on real cells. Browser tests
for the Rules tab with visual snapshots and accessibility checks, run with the
antislop and `cdnf-ui-design` delivery gates. The domain-panel guide and the
API reference describe every type and its bounds.

**Completion checkpoint, 21 November 2026.**

| Implementation | Documentation | Automated/runtime | Browser |
| --- | --- | --- | --- |
| Four increments present; preserve migrations | Reference and qualification record present | Historical IPv4/IPv6/runtime/performance passes; integration open | Pending complete suite/visual/axe evidence; current checkpoint does not pass |

---

## Capability decisions and prerequisites

These are capability-fit decisions, not a replacement feature checklist.
**P0** means a demonstrated or exposure-specific production blocker; **P1**
means essential reliability or measured high-value work; **P2** means a useful
conditional capability; **P3** means optional work that can wait. A candidate
row does not authorize implementation, a new dependency or a new service.
Existing commitments above remain visible; optional stages need an explicit
admission/deferral decision before dependent work begins.

| Capability / status | Value and actual behavior | Priority, cost, decision and proof |
| --- | --- | --- |
| Domain overview and administration / Partial qualification | Claims, delegation, assigned users and both panels already manage desired state. | P1: preserve lifecycle/permission paths; Stage 0A proves competing parent/child claims, revocation, deletion and idempotency. No registrar, new hierarchy or dashboard. |
| Authoritative/secondary DNS and DNS analytics / Partial qualification | Hidden primary plus TSIG/catalog secondaries and dnstap/ClickHouse already provide serving/replication/visibility. | P1: finish Stage 0B negative-answer, transfer/expiry, serial, dual-stack and loss/rcode evidence. External multi-provider secondary imports are deferred: conflicting state ownership adds little immediate value. |
| DNSSEC / Missing lifecycle | Authenticating signed answers requires both signing and a valid parent DS chain; it does not encrypt DNS. | P1 feasibility, P2 conditional implementation: first evaluate key ownership/escrow, Geo-DNS and signed transfers; then, if admitted, revisioned signing/DS workflow; finally real validating-resolver tests for rotation, expiry, stale DS, restore, TCP and larger responses. No hand-managed runtime-only keys. Defer if recovery cannot preserve trust without new infrastructure. |
| Secure origin modes / Partial | Server verification authenticates the origin; current HTTP/unverified TLS remain explicit modes. | P1: qualify current semantics and review defaults/custom trust with a migration/rollback plan. Measure handshake/reuse cost with the same trust policy. Never improve latency by disabling certificate or destination checks. Phase 10.5 Stage H sets the creation default to off at the owner's request; stored explicit values are preserved and the limit is documented. |
| Origin client authentication / Missing | A client certificate lets an origin verify the requesting CDN identity; the origin must enforce it to prevent direct bypass. | P2 conditional: first define per-tenant key scope and origin enforcement; then reuse encrypted storage and assigned signed delivery; finally qualify overlap rotation, revoke/expiry, cross-tenant refusal, backup origins and handshake cost. No shared cross-tenant credential or new CA service. Server verification remains required. |
| WAF, custom/managed rules and account protection / Partial | Signature scores, typed policy and basic schema/password/prompt heuristics exist. They do not establish calibrated bot/attack probabilities. | P1: finish Phases 1 to 4/6 and bounded schema checks; P2 conditional JWT. Corpus accuracy, body coverage, memory and failure behavior precede new detections. Keep response scanning limitations explicit. |
| API identity gateway / Missing, Not needed | Management-panel authentication is separate from authenticating applications proxied through the CDN. | Reject a new identity gateway: sessions, callbacks, upstream identity availability and protocol bypass are a separate product. Retain application/origin authentication and policy-based management. |
| Bot signals and AI crawler control / Partial policy building blocks | Typed UA matching exists; real crawler identity requires maintained, verified datasets. robots.txt is advisory, not proof of identity. | P2 conditional after Phases 4/5/7: first establish provenance/freshness; then reuse typed crawler policy and existing analytics; finally qualify spoofed claims, stale ranges, clean crawlers and rollback. Fingerprint/heuristic scoring waits for measured false-positive and operating value. No separate crawler service, monetization or external request-path check. |
| Agent readiness and Markdown responses / Missing, Not needed | Readiness guidance concerns origin content; negotiated HTML conversion changes response bytes, validators and cache variants. | P3: documentation guidance only if requested. Reject runtime HTML-to-Markdown conversion and automatic page rewriting: untrusted parsing and variant costs are unjustified. Origins can serve negotiated content. |
| Cache diagnostics / Partial | HIT/MISS/BYPASS, typed cache policy and purge exist; redacted reasons would make incorrect admission or TTLs easier to diagnose. | P1 correctness, P2 diagnostics through Phases 0B/10: first qualify variants/internal-header trust; then expose bounded no-store/bypass/purge reason metadata; finally test auth/cookies/Vary/rewrite and cross-tenant redaction through runtime/API/UI. Reuse current log line; budget bytes and cardinality. |
| Tiered caching / Missing | An upper cache tier can reduce origin fetches after lower-tier misses. | Defer P3 until measured origin amplification remains costly after current locking/admission. Feasibility must count extra latency, inter-PoP traffic, purge consistency and upper-tier outage. No extra serving dependency without evidence of net value. |
| Persistent object-store cache / Missing, Not needed | An object-store cache adds a further freshness/revalidation/retention layer. | Reject now: new credentials, consistency, egress and recovery obligations. The backup object store does not become a serving cache. |
| HTTP/2, WebSockets and compression / Partial qualification | Existing listeners, origin options and Gzip/Brotli support need streaming/variant and resource evidence. | P1: measure connection reuse, handshake and compression CPU/bytes before tuning. Qualify ETag/Vary/Range/Content-Length and sensitive responses. No new codecs, minification or response-body transformations without benefit. |
| HTTP/3 and gRPC / Missing qualified edge paths | QUIC changes client transport; gRPC requires upstream HTTP/2 and stream/trailer semantics, not merely an HTTP/2 listener. | Defer P3 HTTP/3 and P2 demand-gated gRPC. TCP gateway routing does not automatically support UDP; stream-body WAF limits must be explicit. Admission needs identity/SSRF/resource/timeout design and real dual-stack long-stream/failure tests, not a protocol toggle. |
| Redirect/rewrite/header/cache rules / Partial qualification | Phase 12 already implements bounded typed mutations after security, with protected origin/Host semantics. | P1: close its browser and release gates. No second engine, arbitrary origin override, custom regex or rule language. Measure worst-case misses and rule cardinality without dropping security order. |
| Rule simulation / Partial preview | Validation preview is not execution simulation; historical estimates are only as complete as stored fields. | P2 conditional after Phase 4: first specify unknown fields and trace scope; then reuse one matcher with bounded synthetic input; finally prove trace/runtime agreement. No origin probe from arbitrary simulated input, secret replay or duplicate evaluator. |
| Custom errors / Built-in responses; customer templates missing | A small status-preserving response can improve incident usability without general response rewriting. | Defer P3 until demanded. Bound template bytes, allow no script or remote fetch, preserve status/Retry-After and private/no-store, and prove no shared cached error leaks. Reuse current response handling; no template runtime. |
| Origin failover, health and steering / Partial qualification | Validated primary/backup, hysteresis, hold-down/failback, pool endpoints and Geo-DNS already exist. | P1: qualify active/passive disagreement, both-down, DNS TTL lag, unsafe/partial retries, IPv4/IPv6 and recovery. No arbitrary origin pool, smart-routing service or affinity before existing behavior is proved. Phase 10.5 Stages F and G correct the health states and bound active probing by a budget. |
| Programmable edge routes / Missing, Not needed | Customer code would execute before origin requests; typed rules already address bounded transformations. | Reject code execution, scripts and plugin runtimes: tenant sandboxing, CPU accounting, outbound policy and language lifecycle create a new platform. |
| Log export and live diagnostics / Export present; live stream missing | Existing gzip HTTPS/S3 export has cursor-after-acceptance, retry and retention-gap semantics. Existing ClickHouse logs can support bounded polling. | P1 export/freshness/loss proof through Phases 0B/10; P2 conditional polling before streaming. Bound sessions, queries, retention and redaction; show sampling/drops/lag. No synchronous subscriber or new streaming service, and no exactly-once delivery claim. |
| DDoS and network controls / Partial local protection | Gateway/cell connection, request and incident controls are bounded local defenses; a saturated uplink is outside their capacity. | P1 failure/capacity truth. Reject volumetric-scrubbing claims, new BGP control and machine-learned scoring. Keep the existing Simple Anycast contract; no architecture expansion beyond what the measured capacity supports. |
| Email DNS versus mail forwarding / DNS records present; forwarding missing, Not needed | MX/TXT can describe mail policy; routing/catch-all would receive and deliver messages to verified destinations. | Reject forwarding/catch-all: SMTP queues, abuse, loops, retries and forwarding authentication are a new operational plane. Retain email DNS; bounded SPF/DKIM/DMARC diagnostics only when requested, with no automatic policy rewrites. |
| Image optimization / Byte caching present; transformations missing, Not needed | Decode/resize/transcode creates expensive derived variants. | Reject in this roadmap: decoder vulnerabilities, bombs, source-fetch SSRF and variant explosion lack a demonstrated need. Cache origin-produced variants. |

### Engineering references

Official primary references consulted for the retained engineering decisions:

- [HTTP caching semantics](https://www.rfc-editor.org/rfc/rfc9111.html) for shared
  cache admission, authorization, variants and revalidation; this motivates the
  cache isolation matrix rather than maximizing hit ratio.
- [NGINX proxy module](https://nginx.org/en/docs/http/ngx_http_proxy_module.html)
  for cache locking/revalidation, temporary storage and upstream TLS settings.
  Runtime safety depends on the actual Lua/filter configuration as well.
- [PowerDNS DNSSEC](https://docs.powerdns.com/authoritative/dnssec/index.html)
  and [zone metadata](https://docs.powerdns.com/authoritative/domainmetadata.html)
  for signing/transfer design; backend capability is not a completed key/DS
  lifecycle in CDNFoundry.
- [CRS anomaly scoring](https://coreruleset.org/docs/2-how-crs-works/2-1-anomaly_scoring/)
  for the distinction between detection points and the eventual threshold
  decision. A numeric transformation is not a calibrated probability.
- [TLS 1.3](https://www.rfc-editor.org/rfc/rfc8446.html) for authenticated
  connections and certificate lifecycle reasoning. Client authentication and
  server verification address different directions of trust.

These describe behavior, not a comparative benchmark or proof of this runtime.
No new library or infrastructure requirement follows from a reference.

## 4. Completion

The roadmap is complete when:

- Phase 0, required work in Phases 1 to 12, Phase 10.5, and every admitted optional stage
  have passed their gates on the same release. Deferred candidates are recorded
  as out of scope, never as implemented or passed.
- Each retained capability has scoped, release-matched verification or an
  explicit documented limitation. A limitation cannot waive an unresolved
  security, serving, recovery or current UI checkpoint.
- The harness runs in qualification for every release. A release that worsens
  a recorded false positive rate, detection rate, or latency beyond its
  tolerance fails.
- Every security decision a cell makes can be traced to its rule, signal, or
  dataset version in the analytics.

## 5. Out of scope unless the owner admits it

Volumetric scrubbing and BGP control (the edge cannot absorb a saturated
uplink), machine-learned attack or bot scoring, third-party CAPTCHA or any
outside verification on the request path, synchronous calls between cells or
PoPs, custom regex or an expression language in rules, full CRS response
inspection and response body rewriting, customer-uploaded `SecRule` content,
Kubernetes, microservices, Kafka, and a second dashboard or backend.

## 6. Review scope and validation

The review sampled every requested subsystem's entry points, data flow and
trust boundaries; it is not a line-by-line security proof. Source absence
findings remain scoped to the inspected paths. Historical records retain their
original attribution and workload limits. Public primary documentation informed
the capability decisions; no outside product, dashboard or comparative
benchmark was used to establish performance.

During this planning audit, `python3 -m unittest discover -s tests/contract`
passed **55 tests in 2.507 seconds**, and
`python3 -m unittest discover -s tests/qualification` passed **26 tests in
4.429 seconds**. The latter includes intentionally failed/invalid evidence
cases; its passing result verifies the evidence tooling, not an actual TLS,
browser or fleet qualification run. Full logs were captured in temporary files.
`make docs-check` passed the documentation generators, local links, executable
blocks, Markdown lint, production build and built-site validation. Compose
`config --quiet` passed for development, development with the isolated test
override, and production using the example environment. These are syntax and
documentation checks, not running-service qualification.

No new runtime, fleet, browser, throughput, detection-rate or false-positive
run was performed. Existing UI qualification remains open as recorded above;
this prose-only review cannot close it. No migration, persistent database
operation, volume removal, production deployment, dependency change or source
implementation was performed. Existing screenshot and browser snapshot changes
were preserved. Only this roadmap is a retained planning deliverable.

On 27 November 2026 a second planning pass for Phase 10.5 executed temporary
experiments: a feature test on the isolated in-memory database, disposable
runtime, Caddy, nginx, ClickHouse and Go containers, offline Fleet rendering, a
replica of the Fleet activation sequence, a read-only run of every panel query on
the development Grafana, and Python models of percentile and probe scheduling.
Their scripts were not retained, the development PostgreSQL, ClickHouse and named
volumes were not modified, and the roadmap is the only retained change. Their
results are recorded in Phase 10.5 with their limits; none is a gate result.
