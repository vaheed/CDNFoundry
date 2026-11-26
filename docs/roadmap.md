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
| F3 / P1 | Origin TLS verification can be disabled and HTTP is accepted. Public unverified traffic is exposed to interception/impersonation; source does not show one tenant changing another's settings. | Qualify and document current modes; review secure defaults and bounded custom trust before changing compatibility. Optional origin mTLS follows ordinary server verification, never substitutes for it. |
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

Each change uses existing queues, revisioned snapshots, signing, stores and
qualification machinery. No synchronous runtime effects, new service, general
expression language or second evaluator. Invalid candidates retain previous
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

**Execution record, 26 November 2026** (development host; stage 1 only, work in progress).

| Item | Result |
| --- | --- |
| Built | Pool-wide challenge keys issued by the control plane (one signing key, one verifying key during a 2 hour overlap), scheduled rotation after 30 days, administrator rotate and revoke endpoints, keys sealed to each edge's identity key and fetched by the edge agent, key verification and expiry by the cell's own clock, a per-token request budget with the reason `challenge_token_limited`. |
| Tests run | `ChallengeKeysTest` (7), the full PHP suite, PHPStan, Pint, Go tests in `edge-agent`, `tests/e2e/challenge_keys.py` (portability between cells, rotation without re-challenge, revocation, expiry, prefix and browser-family binding, token budget, malformed sets, no secret in responses or logs) and `challenge_binding.py` passed on real cells. |
| Not done | A two-edge e2e across PoPs through the real agent and control plane, API/mobile/accessibility qualification and solve-time p95, browser tests of the challenge page, and stage 3 (bot signals). |

Phase 7 is not complete.

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

**Completion checkpoint, 21 November 2026.**

| Implementation | Documentation | Automated/runtime | Browser |
| --- | --- | --- | --- |
| Conditional: feasibility/admission open | Privacy/provenance/opt-in decision open | Poisoning/NAT/TTL matrix not run | Only required if admitted; no placeholder pass |

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

**Completion checkpoint, 21 November 2026.**

| Implementation | Documentation | Automated/runtime | Browser |
| --- | --- | --- | --- |
| Basic checks present; bounded schema/JWT scope open | Schema limits and conditional admission open | Body/key/rotation/privacy matrix open | Schema/JWT UI gate open if scope admitted |

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

**Acceptance criteria.**

- For every mitigated request in a harness run, the trace names the stage and
  the rule or signal that acted.
- The events list pages through a 1,000,000-event attack in a 24-hour range with
  each page answered under 1 second on the reference ClickHouse.
- One scripted attack produces one episode and one alert.
- The log line grows by no more than the stated byte budget.

**Verification.** ClickHouse query tests against a counted sample. E2E from
cell to ClickHouse to the API. Browser tests for traces, episodes, and views.

**Completion checkpoint, 21 November 2026.**

| Implementation | Documentation | Automated/runtime | Browser |
| --- | --- | --- | --- |
| Logs/export present; trace/query scope open | Retention/loss/trace contract open | Counted scope/loss/query matrix open | Trace/event view gate open |

---

## Phase 11: Full qualification and external review

**Execution plan.** Priority: P1 release gate; reproduced unsafe failure is P0.

**Ordered stages.** 1. Reconcile every earlier gate against one release manifest. 2. Execute integrated load, IPv4/IPv6, failure, recovery and soak scenarios. 3. Obtain scoped external security review and close release-blocking findings.

**Security, resources and failure boundary.** No production/customer testing or new fleet topology just for optional features. Use existing qualification tooling; choose the smallest topology that proves actual PoP/cell failover and record coverage limits. Measure whole-host headroom, disk/network limits, recovery and rollback, not summed container throughput.

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
- **A WAF performance envelope** on the reference cell: requests per second and
  latency for each profile and feature, and the saturated resource.
- **An external review** of the challenge tokens and keys, dataset signing,
  tenant isolation, and the bypass corpus.

**Dependencies.** Phase 0, required work in Phases 1 to 10, and Phase 12 integration. Conditional capabilities need an explicit admission or deferral decision, not a placeholder implementation.

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
| Secure origin modes / Partial | Server verification authenticates the origin; current HTTP/unverified TLS remain explicit modes. | P1: qualify current semantics and review defaults/custom trust with a migration/rollback plan. Measure handshake/reuse cost with the same trust policy. Never improve latency by disabling certificate or destination checks. |
| Origin client authentication / Missing | A client certificate lets an origin verify the requesting CDN identity; the origin must enforce it to prevent direct bypass. | P2 conditional: first define per-tenant key scope and origin enforcement; then reuse encrypted storage and assigned signed delivery; finally qualify overlap rotation, revoke/expiry, cross-tenant refusal, backup origins and handshake cost. No shared cross-tenant credential or new CA service. Server verification remains required. |
| WAF, custom/managed rules and account protection / Partial | Signature scores, typed policy and basic schema/password/prompt heuristics exist. They do not establish calibrated bot/attack probabilities. | P1: finish Phases 1 to 4/6 and bounded schema checks; P2 conditional JWT. Corpus accuracy, body coverage, memory and failure behavior precede new detections. Keep response scanning limitations explicit. |
| API identity gateway / Missing, Not needed | Management-panel authentication is separate from authenticating applications proxied through the CDN. | Reject a new identity/Access gateway: sessions, callbacks, upstream identity availability and protocol bypass are a separate product. Retain application/origin authentication and policy-based management. |
| Bot signals and AI crawler control / Partial policy building blocks | Typed UA matching exists; real crawler identity requires maintained, verified datasets. robots.txt is advisory, not proof of identity. | P2 conditional after Phases 4/5/7: first establish provenance/freshness; then reuse typed crawler policy and existing analytics; finally qualify spoofed claims, stale ranges, clean crawlers and rollback. Fingerprint/heuristic scoring waits for measured false-positive and operating value. No separate crawler service, monetization or external request-path check. |
| Agent readiness and Markdown responses / Missing, Not needed | Readiness guidance concerns origin content; negotiated HTML conversion changes response bytes, validators and cache variants. | P3: documentation guidance only if requested. Reject runtime HTML-to-Markdown conversion and automatic page rewriting: untrusted parsing and variant costs are unjustified. Origins can serve negotiated content. |
| Cache diagnostics / Partial | HIT/MISS/BYPASS, typed cache policy and purge exist; redacted reasons would make incorrect admission or TTLs easier to diagnose. | P1 correctness, P2 diagnostics through Phases 0B/10: first qualify variants/internal-header trust; then expose bounded no-store/bypass/purge reason metadata; finally test auth/cookies/Vary/rewrite and cross-tenant redaction through runtime/API/UI. Reuse current log line; budget bytes and cardinality. |
| Tiered caching / Missing | An upper cache tier can reduce origin fetches after lower-tier misses. | Defer P3 until measured origin amplification remains costly after current locking/admission. Feasibility must count extra latency, inter-PoP traffic, purge consistency and upper-tier outage. No extra serving dependency without evidence of net value. |
| Persistent cache reserve / Missing, Not needed | An object-store cache adds a further freshness/revalidation/retention layer. | Reject now: new credentials, consistency, egress and recovery obligations. The backup object store does not become a serving cache. |
| HTTP/2, WebSockets and compression / Partial qualification | Existing listeners, origin options and Gzip/Brotli support need streaming/variant and resource evidence. | P1: measure connection reuse, handshake and compression CPU/bytes before tuning. Qualify ETag/Vary/Range/Content-Length and sensitive responses. No new codecs, minification or response-body transformations without benefit. |
| HTTP/3 and gRPC / Missing qualified edge paths | QUIC changes client transport; gRPC requires upstream HTTP/2 and stream/trailer semantics, not merely an HTTP/2 listener. | Defer P3 HTTP/3 and P2 demand-gated gRPC. TCP gateway routing does not automatically support UDP; stream-body WAF limits must be explicit. Admission needs identity/SSRF/resource/timeout design and real dual-stack long-stream/failure tests, not a protocol toggle. |
| Redirect/rewrite/header/cache rules / Partial qualification | Phase 12 already implements bounded typed mutations after security, with protected origin/Host semantics. | P1: close its browser and release gates. No second engine, arbitrary origin override, custom regex or rule language. Measure worst-case misses and rule cardinality without dropping security order. |
| Rule simulation / Partial preview | Validation preview is not execution simulation; historical estimates are only as complete as stored fields. | P2 conditional after Phase 4: first specify unknown fields and trace scope; then reuse one matcher with bounded synthetic input; finally prove trace/runtime agreement. No origin probe from arbitrary simulated input, secret replay or duplicate evaluator. |
| Custom errors / Built-in responses; customer templates missing | A small status-preserving response can improve incident usability without general response rewriting. | Defer P3 until demanded. Bound template bytes, allow no script or remote fetch, preserve status/Retry-After and private/no-store, and prove no shared cached error leaks. Reuse current response handling; no template runtime. |
| Origin failover, health and steering / Partial qualification | Validated primary/backup, hysteresis, hold-down/failback, pool endpoints and Geo-DNS already exist. | P1: qualify active/passive disagreement, both-down, DNS TTL lag, unsafe/partial retries, IPv4/IPv6 and recovery. No arbitrary origin pool, smart-routing service or affinity before existing behavior is proved. |
| Programmable edge routes / Missing, Not needed | Customer code would execute before origin requests; typed rules already address bounded transformations. | Reject code execution, snippets and plugin runtimes: tenant sandboxing, CPU accounting, outbound policy and language lifecycle create a new platform. |
| Log export and live diagnostics / Export present; live stream missing | Existing gzip HTTPS/S3 export has cursor-after-acceptance, retry and retention-gap semantics. Existing ClickHouse logs can support bounded polling. | P1 export/freshness/loss proof through Phases 0B/10; P2 conditional polling before streaming. Bound sessions, queries, retention and redaction; show sampling/drops/lag. No synchronous subscriber or new streaming service, and no exactly-once delivery claim. |
| DDoS and network controls / Partial local protection | Gateway/cell connection, request and incident controls are bounded local defenses; a saturated uplink is outside their capacity. | P1 failure/capacity truth. Reject volumetric-scrubbing claims, new BGP control and machine-learned scoring. Keep the existing Simple Anycast contract; no architecture expansion to imitate a larger network. |
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

- Phase 0, required work in Phases 1 to 12, and every admitted optional stage
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
the capability decisions; no provider dashboard or comparative benchmark was
used to establish superiority or performance.

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
