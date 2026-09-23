---
title: CDNFoundry staged delivery roadmap
description: Bounded jobs for dev publication, staging tests, remaining review, and production qualification.
---

# CDNFoundry staged delivery roadmap

This plan replaces the open-ended audit goal on **2026-09-19**, at the owner's
request. The owner confirmed on **2026-09-20** that the previous delivery job
is finished and the application images are published. The owner has since
authorized sequential agent-owned work across the remaining phases while
manual browser qualification stays pending. The production staging
environment has no customer traffic and is for early testing. Image publication
and empty-stage smoke tests do not establish general production readiness.

The previous roadmap is preserved unchanged at
[`docs/legacy/production-hardening-roadmap.md`](https://github.com/vaheed/CDNFoundry/blob/dev/docs/legacy/production-hardening-roadmap.md).
The older `docs/legacy/roadmap.md` is also retained. Archiving closes the planning
document, not its unverified acceptance criteria; remaining work is assigned
below. See the [handoff report](operations/development-handoff.md) for actual
results, release evidence, implemented fixes and limitations.

## Contract for each new job

- Start **one named job** per request. Finish its changes, tests, documentation
  and small commits, then stop at its acceptance gate. Record discoveries for
  later jobs instead of restarting an unrestricted audit.
- Report completed scope, remaining gates, tests and working-tree status after
  each commit. Commit counts and partially reviewed files are not a reliable
  overall completion percentage.
- Use focused tests per fix and complete applicable suites at the job boundary.
  Retain failure evidence; never weaken a check to obtain green CI.
- Browser qualification is manual and owner-run. Agents run non-UI Python
  qualification under `tests/e2e`; browser automation remains prohibited.
- Preserve PostgreSQL and named volumes. Laravel migration tests require
  effective `testing / sqlite / :memory:` through the supported isolated command.
- Preserve one Laravel/Filament control plane, PostgreSQL desired state,
  asynchronous revisioned effects, bounded shared cells and last-valid rollback.
  DNS, customer traffic, security decisions and raw telemetry stay outside Laravel.
  No per-domain infrastructure, new backend or unrelated architecture is admitted.
- Preserve tenant policies, safe origins, encrypted TLS keys, signature checks
  and production migration compatibility. The observability exception remains
  exactly the system/domain Grafana dashboards, Vector/ClickHouse and bounded Loki.
  The owner-approved rebuilt ClickHouse plugin uses the signed image as its
  trust boundary; its sole plugin-signature exception is documented in the
  [supply-chain policy](operations/software-supply-chain.md#approved-rebuilt-clickhouse-plugin).

## Phase 0 — Dev delivery handoff

**Job `delivery-handoff`: complete per owner handoff on 2026-09-20.** Commit the pending Fleet corrections and
existing audit work; archive the roadmap; publish this plan and the report.
Validate locally, push `dev` without changing remote `main`, inspect the exact
commit's public Actions run through `curl`, and fix delivery-blocking failures.
Require the scan/sign/attest/manifest publication pipeline for every release image.
Finish with a clean tree, pushed commit, successful run URL and retrieval steps.
No live staging deployment belongs to this job.

Completion gate: implementation and documentation present; local checks and
remote publication pass for the delivered commit; browser **not applicable to
publication**. Exact status is established by the report and linked Actions run.

Local gate status: **passed** for all 17 release images, all 3 external
production images and the documented runtime checks. Implementation and operator
documentation are present. The [latest dev Actions run](https://github.com/vaheed/CDNFoundry/actions/workflows/ci.yml?query=branch%3Adev)
records the remote gate: every required job and image publication must succeed
for the delivered commit. Publication completion is accepted from the owner
handoff; this job does not rerun delivery. The selected manifest and exact run
identity must still be recorded before staging deployment. The [handoff report](operations/development-handoff.md)
records the evidence and approved, narrowly scoped Grafana exceptions. No
warning-only publication is allowed. A successful remote gate completes this
job and admits Phase 1; it does not qualify a live staging installation.

**Prerequisite job `dependency-remediation`.** Resolve the selected infrastructure
image findings before resuming publication. Select supported patched versions or
rebuilds, pin exact digests, update every Compose/Fleet reference consistently,
and qualify affected DNS, database, telemetry and ingress behavior. Preserve
database compatibility and rollback. Rescan every selected dependency and every application or managed
infrastructure image with complete evidence. A missing vendor fix remains a
blocker; do not hide it, ignore all unfixed findings or waive the dev gate.
This delivery prerequisite precedes Phase 1; Phase 8 retains clean-host release
verification and the wider publishing/credential review.

Completion gate for the prerequisite: supported replacements are documented;
applicable configuration/runtime tests and exact-image vulnerability gates pass;
no existing data is reset. Browser **not applicable to image scanning**; document
any changed operator workflow. Local status: **remediation and qualification
passed**. The rebuilt Grafana backend and plugin pass their combined datasource
checks; approved Tempo classifications retain the raw findings and expire October
19. Delivery is complete per the owner handoff; retain its exact release evidence
when selecting the staging release.

## Phase 1 — Install and smoke-test the empty staging environment

**Job `staging-install-and-smoke`.** Inputs: published release manifest,
staging host access/inventory, independent management DNS, test-zone registrar
access, origin endpoint and approved address families. Follow the starter Fleet
quick start on one control/telemetry host and two DNS/edge hosts, or explicitly
limit claims for a smaller topology. Verify images/bundles; apply migrations
explicitly; exercise control HTTPS, queues, scheduler, authoritative UDP/TCP DNS,
enrollment, first domain, origin HTTP/TLS, cache/purge, security, telemetry and
restart continuity. Fix reproducible installation blockers and save sanitized
source/image identities, commands and results in the existing report format.
The owner executes implemented screens in `docs/manual-browser-qualification.md`.

Completion gate: installation works on the stated hosts, operator docs are current,
non-UI runtime checks pass and owner browser status is recorded separately.
Historical status: **job stopped at the owner's release checkpoint on
2026-09-20; Phase 1 remained incomplete**. That checkpoint committed current
work for `v0.9.8`; it did not assert full staging qualification or production
readiness. The owner resumed the same bounded job on 2026-09-23.
See the [release notes](operations/releases/v0.9.8.md) and
[staging job record](operations/development-handoff.md#staging-install-and-smoke-job).

- Implementation: all three hosts are installed. The verified `80eea902` agent
  and runtime fixes are deployed on both PoPs; full purge now succeeds. The
  signed `1818132d` control image is deployed, its display-timezone migration was
  applied explicitly, and the final Vector enrolled-edge identity mapping is
  deployed on control and both PoPs. Named volumes were preserved.
- Documentation: Fleet browser/API setup, manual checkpoints, release notes and
  the sanitized handoff record are current. Legacy roadmaps remain unchanged.
- Automated/runtime qualification: control/DNS, account isolation, Grafana APIs,
  HTTP origin, verified visitor HTTPS, cache/URL/full purge and security checks
  passed. Both edges served during a control outage; one PoP Docker restart
  preserved serving on its peer and recovered verified HTTPS with named volumes
  intact. Real origin latency and error telemetry passed. After the resumed
  rollout, public control health/readiness/auth checks, a verified-HTTPS cache
  sequence on both PoPs, and live ClickHouse edge-identity attribution passed.
  Verified HTTPS origin transport was not exercised because the owner selected
  an HTTP origin with visitor TLS.
  The owner chose to retain the missing-backup warning and configure backups
  separately; no backup/recovery readiness is claimed.
- Owner-run browser qualification: **partial**. Both login/access checks passed
  per the owner. The healthy-edge KPI correction is deployed and awaits its
  browser retest. Timezone, recovery presentation, edge-filter telemetry and the
  other remaining checks are **not run**. Use the
  [Phase 1 checklist](https://github.com/vaheed/CDNFoundry/blob/dev/docs/manual-browser-qualification.md#phase-1--empty-staging-smoke).

The owner deferred the remaining manual browser checks on 2026-09-23 and
explicitly requested work on Phase 2. Phase 1 remains open; its browser gate
is not waived or recorded as passed. Agent-owned deployment and runtime gates
passed on 2026-09-23. Collect and record the owner's browser results before
closing Phase 1. See the resumed execution record in the
[staging handoff](operations/development-handoff.md#resume-execution-2026-09-23).

On 2026-09-23 the owner directed implementation through all roadmap phases
without waiting for browser qualification, which the owner will execute after
the agent-owned phases. Record each browser gate as pending; this instruction
does not turn an unrun browser check into a pass or establish production
readiness. Agent-owned phases still proceed in roadmap order.

## Phase 2 — Identity, authorization and domain lifecycle

**Job `identity-and-domain-boundaries` (owner-directed while Phase 1 browser
qualification remains open).** Review sessions/tokens/CSRF, disabled
users, policies/binding, Filament/Livewire parity, operations, imports, parent
verification, claim expiration/reclaim, assignments and queued authorization.
Extend cross-tenant tests, actual PostgreSQL races and signed parent-delegation
checks, including supported IPv4/IPv6 and existing-installation migration paths.

Completion gate: assigned source inventory reviewed and confirmed defects fixed;
API/operator docs current; relevant application/PostgreSQL/DNS checks pass;
owner browser checkpoint recorded separately. Current status: **partial**.
The first review found that same-origin panel sessions were not admitted to API
routes. Stateful Sanctum API middleware and session logout invalidation are now
implemented; API documentation and a policy-scoped session regression were
added. The isolated Laravel suite, disposable PostgreSQL claim/race and
legacy-migration job, and real-BIND parent-delegation fixture with IPv4/IPv6
transports pass. Assignment also rechecks eligibility under a row lock, with
a disposable PostgreSQL disable/attach race proving rejection. API and Filament
use the same assignment boundary. Queued DNS imports now recheck current actor
scope, and duplicate workers commit one import and one operation receipt.
Domain-user operation reads now require current assignment. Token issuance is
bounded at 50 active tokens per user across API login, manual API creation and
the panel; a PostgreSQL race proved the final slot is not overfilled. Token
metadata now commits with issuance, so a failed suffix write rolls back the
unseen secret. The deprovisioning delay is now rechecked under the domain lock
at finalization. Domain deletion now rechecks assignment under the domain lock
before starting deprovisioning; an isolated revocation regression passed. The
additive claim migration now admits a partial existing
installation with a JSON assignment and retains its values as JSONB; a
disposable PostgreSQL replay passed. A read-only resolver check inside the
staging control container matched both assigned public parent nameservers;
the earlier workspace DNSSEC probe had failed before comparison. The remaining
source inventory is open. The Phase 1 and Phase 2 owner browser checklists are
pending for the owner's later qualification pass.

## Phase 3 — Managed TLS and queue recovery

**Job `tls-and-job-recovery`.** Finish nonterminal issuance transitions, current
lifecycle/name checks, changed inputs during finalization, retry scheduling and
worker death between commit and dispatch. Verify key/CSR persistence before CA
finalization, lost responses, duplicate workers, managed-chain admission, bounded
order/alert selection and renewal continuity. Extend existing TLS/PostgreSQL
checks and qualify a real isolated test CA with edge HTTPS clients.

Completion gate: lifecycle defects fixed, TLS/worker runbooks current,
failure/retry/rollback runtime checks pass and owner TLS checklist recorded.
Current status: **partial**; prior terminal-state/upload fixes are retained.
The issuer now persists the encrypted key and CSR before CA finalization and
reuses them after a lost response. An injected lost-response regression passes;
the real Pebble/DNSdist managed-issuance fixture also passes. Late certificate
downloads now recheck domain lifecycle and hostname eligibility under the lock.
After refreshing the development cell images, the extended fixture passed
CA-verified SNI and exact issued-certificate fingerprint checks through both
gateways. The remaining lifecycle inventory is open. Owner browser
qualification is deferred until the owner-run pass.
The hourly maintenance pass now redispatches stale, due nonterminal orders in
a bounded batch, guarded by per-order queue uniqueness. The isolated recovery
regression and managed TLS suite pass. The change awaits a published image and
staging rollout.

- Implementation: **partial**; remaining lifecycle and queue-recovery inventory
  is open.
- Documentation: **current for implemented behavior**; TLS guide, development
  handoff and owner checklist reflect this pass.
- Automated/runtime qualification: **partial**; 352 isolated Laravel tests and
  the real Pebble/DNSdist/dual-gateway TLS fixture passed, while the remaining
  Phase 3 failure and recovery matrix is open.
- Manual browser: **not run**; owner will execute the written checklist after
  the agent-owned roadmap work.

## Phase 4 — DNS, proxy, cache and edge trust boundaries

**Job `data-plane-boundaries`.** Review DNS types/imports/zone isolation and
last-valid publication; connection-time SSRF/rebinding, forwarding, framing,
backup origins, WebSockets, cache isolation and purge parity; enrollment, mTLS
provenance, scoped artifacts, replay and signing trust/rotation. Extend actual
DNS/HTTP/TLS, Go and IPv6 adversarial tests, not only mocks.

Completion gate: assigned inventory and defects resolved; runtime/operator
contracts current; negative and failure checks pass; owner DNS/cache/edge
checkpoints recorded. Current status: **partial**.

## Phase 5 — Durable activation and real WAF qualification

**Job `runtime-durability-and-waf`.** Carries forward the old roadmap's first two
workstreams. Exercise generation activation, corrupt/partial downloads, fsync
failures, process/host restarts, matching gateway/cell state, rollback and bounded
retention. Qualify real ModSecurity/OWASP CRS Off/Monitor/Block modes, exclusions,
bypass attempts, bounded resources and mixed-version last-valid behavior.

Completion gate: implementation reviewed, truthful docs, actual failure-injection
and WAF enforcement checks pass, owner diagnostics/security checklist recorded.
Current status: **implementation and prior evidence exist; full gate remains open**.
The local real OpenResty and managed CRS fixtures pass. WAF profile and
exclusion mutations now recheck assignment under the domain lock, and the
exclusion limit is checked in the same transaction. Staging enforcement and
failure-injection checks remain open.

## Phase 6 — Fleet operations, upgrades and clean-host recovery

**Job `fleet-recovery`.** Review every Fleet module, generated command, role
override, secret/PKI lifecycle, permission and entrypoint. Test repeated/interrupted
setup, rotation/adoption, multi-region, dedicated monitoring, external database,
canary/drain/failed wave and rollback. Back up and restore onto clean hosts,
including application/signing/encryption keys and external TLS material;
preserve ownership/assignments and rebuild derived DNS/edge state.

Completion gate: supported installation/upgrade/restore paths work on recorded
hosts; recovery docs/material are complete; runtime checks pass and owner
operational checklist is recorded. Current status: **partial**.

## Phase 7 — Telemetry and remaining repository cleanup

**Job `telemetry-and-cleanup`.** Review tenant log/analytics/export access,
redaction, bounded ingestion/query cost, retention, alerts and failure isolation.
Assign every remaining admin/config/build/asset/doc/test-tool inventory path.
Resolve AUD-055: bound Docker service log size/retention and document applying
rotation to existing containers without deleting database volumes. The current
local installation exhausted disk space on unbounded ClickHouse/Horizon logs.
Extend the cleanup ledger and remove only proven unused/superseded items after
framework, generator and documented-caller tracing. Preserve applied migrations,
rollback material, regression coverage and supported external contracts.

Completion gate: assigned inventory reviewed; cleanup decisions and compatibility
documented; contracts/links/outage checks pass; owner Grafana/admin/export
checkpoints recorded. Current status: **partial**.

## Phase 8 — Dependency and release verification closure

**Job `release-verification`.** Carries forward the old third workstream. Review
current advisories; fix supported dependency defects and rescan exact digests.
Verify source → all release images → complete SBOM/scan/provenance/signatures → signed
manifest → Fleet projection and clean-host pull. Test verifier failures, publishing
refs, CI permissions, credential boundaries and rollback without blanket exceptions.

Completion gate: current selected-release scans and verification pass; operator
instructions execute on a clean host; negative tests pass. Browser **not applicable
to cryptographic verification**. Current status: **pipeline implemented; exact
release evidence comes from CI and operator verification**.

## Phase 9 — Measured production acceptance

**Job `production-acceptance`.** Prerequisites: Phases 1–8, complete inventory
assignment/review and no unresolved required high/critical findings. Run the full
existing qualification matrix on the selected release/topology/dataset: load,
noisy neighbors, queue starvation, bounded storage/telemetry failures, control/DB/
queue outages, public DNS/TLS, IPv6, canary/rollback and restore. Include Anycast
only if claimed. Review every original requirement against direct evidence.
Publish measured ceilings, limitations and the release decision.

Completion gate: implementation and docs complete, full applicable automated/
runtime matrix passes, owner browser checklist passes. Any missing required
proof means **not qualified for production**. Current status: **not run as a
complete release qualification**.

## Start the next bounded job

Current request: **continue implementation and agent-owned qualification through
the roadmap while the owner defers browser checks until the final pass.** Keep
each manual gate open and record its actual results when supplied. Do not infer
a browser pass from implementation or non-browser qualification.
