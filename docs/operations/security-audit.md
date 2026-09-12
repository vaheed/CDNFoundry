---
title: Security audit and remediation evidence
description: Findings, review coverage, cleanup decisions, and qualification gates for the September 2026 audit.
---

# Security audit and remediation evidence

Audit in progress against `602c605b3f50551d18ddab442670d8fbc7e5f545`, initially clean
`main`, on 2026-09-05. Changes in this checkout are part of the tested source.
**Not yet qualified for production.** No public deployment, release publication,
or browser qualification has been performed.

## Environment and evidence

The local host runs Linux amd64, Docker 29.1.3, Compose 2.40.3, PHP 8.5.7,
Python 3.12.3 and npm 11.16.0. Existing development and single-host containers
and volumes are present and must be preserved. Initial free disk: 6.4 GiB.
Disposable checks must use distinct projects or temporary directories; Laravel
migration tests require effective testing / sqlite / :memory: configuration.
Raw local evidence and the tracked-file inventory are under
`storage/qualification/security-audit/` (ignored, access-controlled local files).
The inventory is not a claim of completed review.

## Findings register

| ID | Severity / confidence | Paths and evidence | Impact / preconditions | Remediation and verification | Status |
| --- | --- | --- | --- | --- | --- |
| AUD-001 | High / confirmed source | `scripts/supply-chain-policy.py`: extraction omits whitespace after FROM; baseline checker passes | Mutable production bases can escape the release policy | Parse instructions and arguments; negative fixtures; no data migration | Fixed parser; six adversarial policy test methods passed (including Git and Compose discovery). |
| AUD-002 | High / confirmed source | `tests/e2e/production_qualification.py`: any nonempty absolute file passes owner check | Unrelated evidence can falsely qualify a release | Require attributable structured outcomes bound to source/environment; negative fixtures | Fixed structured source/environment-bound evidence; six tool regressions passed. |
| AUD-003 | Medium / confirmed source | CI and qualification omit `tests/fleet/test_fleet.py` | Fleet defects can bypass required release gates | Install dependencies and run Fleet suite in both gates | Fixed CI/runner inclusion; Fleet checkpoint 74 passed, current rerun recorded separately. |
| AUD-004 | High / confirmed source | Filament CreateDomain omits API tombstone check | Reclaim cooldown bypass after deletion | Enforce transactional creation parity; race tests on isolated PostgreSQL | Fixed shared transactional API/Filament creation; application and isolated PostgreSQL races passed. |
| AUD-005 | High / confirmed source; attack reproduction pending | Verification compares recursive NS lookup with shared platform pair | Stale delegation does not prove applicant control | Trace claim-bound delegation, bootstrap, lifecycle and upgrade requirements | Fixed fresh claim assignments and parent-side verification; signed BIND tests passed; public delegation remains blocked. |
| AUD-006 | Medium / confirmed source | `DomainName.php`: short suffix list | Unsupported public suffixes accepted | Maintained suffix rules, normalization and namespace tests | Fixed pinned full public-suffix rules and namespace guards; regression suite passed. |
| AUD-007 | High / confirmed source; regression pending | Verification completion rejects only deprovisioning and clears disabled state | Queued verification may reactivate disabled domains | Recheck lifecycle, actor authorization and operation under lock | Fixed locked completion checks and bounded expiration; adversarial application tests passed. |

Each fix must record regression results, compatibility, rollback and limits here.
Hypotheses are not exploit claims. External/runtime blockers remain separate.

## Implementation plan and review coverage

1. Establish safe baseline, inventory and regression evidence for the named leads.
2. Repair supply-chain policy and qualification evidence/gate integrity.
3. Repair domain claim, canonicalization, lifecycle and tenant isolation flows.
4. Review all first-party application, runtime, Fleet, deployment and recovery
   paths; trace defenses before classifying findings; remediate verified defects.
5. Audit dependencies/images with current advisories; qualify generated bundles
   and non-browser runtimes in isolated environments.
6. Remove proven obsolete implementation, update all connected documentation,
   and record release decision and exact outstanding external gates.

Inventory areas: `.agents`, `.github`, application/routes/models/policies/requests/
Filament/jobs/support/commands/migrations/tests/assets in `core`, Go agent and
Gateway, OpenResty/Lua and service configs under `docker`, Fleet and scripts,
Compose/deploy/env examples, supply-chain evidence, Python test suites, and docs.
The per-file inventory and scoped review status are maintained in
`docs/operations/security-audit-coverage.json`; remaining source review is pending. Third-party/vendor/generated output is
excluded from first-party source review and requires dependency/image checks.
Historical docs are retained evidence, not qualification of this checkout.

## Cleanup ledger

| Candidate | Decision and evidence | Replacement / compatibility | Verification |
| --- | --- | --- | --- |
| Applied migrations and `docs/legacy/` | Retain: upgrade history and commit-specific evidence | Required historical compatibility | Further reference review pending |
| Supply-chain image extraction | Replaced defective parser; preserved existing checks | Same CLI, stronger validation | Six adversarial fixture methods passed |
| Qualification nonempty-file evidence rule | Replaced insufficient evidence contract | Structured attributable operator evidence required | Six tool regressions passed |
| Laravel test dependency/storage mounts | Replaced implicit runtime startup and writable development storage with `compose.test.yml` | Same `make dev-test` entry point; no data or volume deletion | Effective SQLite-memory preflight, 310-test checkpoint and Compose/docs validation passed; commit `094bbcaa` |
| Synthetic custom-chain PKI generator | Consolidated API and real-TLS fixtures in `core/tests/Support/CertificateChain.php` | Test-only helper, mounted explicitly into the PHP runtime fixture | Shared eight-case negative corpus and valid private-CA control |
| PHP purpose-only uploaded-chain check | Replaced by bounded native OpenSSL verification with explicit authentication strength | Same upload contract and private-CA support; pinned CLI required by the image | AUD-042: four new weak-chain regressions plus the original corpus, full application and real TLS restoration checks |
| Fixed-prefix TLS maintenance scan | Replaced the scan that repeatedly selected only the earliest domains | Same command, limit and eligibility; rebuildable shared-cache progress, no migration | AUD-047: bounded coverage, arrivals, retry, cursor-loss and lease regressions |
| Ignored `storage/qualification/security-audit/trivy-cache/` | Removed on 2026-09-12: duplicate reproducible scanner cache, not repository source | Retained `dependency-gate/cache/`, all scan reports and test evidence | Both 1,348,702,208-byte databases had SHA-256 `7a77cf4af9afbb891eb6e94d74968a36065fe71b0c56ce16e30d7081fc5cfab4`; neither cache was mounted by a running container. Removed 1,352,896,665 file bytes. |

## Qualification gates

Implementation, documentation and automated/runtime qualification are in
progress. Manual browser qualification is **Not run**, owner-owned. Public
IPv4/IPv6, Anycast, multi-host installer, load and clean-host restore evidence
is not yet available. No measured production capacity is claimed.

### Coverage and environment checkpoint — 2026-09-12

The current inventory contains **759 files: 664 pending, 91 partial and four
reviewed**. The user-facing estimate is approximately **20% of the overall
effort completed, 80% remaining**. This is a rough planning judgment reflecting
concentrated work on important trust boundaries, not a measured percentage of
security coverage or production readiness. Whole-file status is deliberately
separate from the verified fixes recorded below. Remaining work includes the
untouched inventory, unresolved image findings, complete Fleet installation/
upgrade/recovery qualification, release evidence and owner-run browser checks.

The interrupted AUD-038 change was committed as `b57e3175` after verifying that
the current source and staged tree still matched its completed runtime and
documentation evidence. The documentation job had completed successfully on
2026-09-08; it was not restarted or claimed as a new execution on September 12.

On resumption, the host filesystem reported zero available bytes. Removing the
duplicate cache above recovered about 1.35 GB, but ordinary-process available
space remained zero; root-reserved free space was approximately 2.02 GB at the
checkpoint. Larger builds/scans and production qualification need adequate free
space before execution. No broad Docker pruning, named-volume deletion, database
reset or Fleet/recovery-state removal was performed. Source review can continue;
this environmental constraint does not mark the overall audit complete or blocked.

## Recorded progress

- Initial Fleet baseline: **Passed**, 65 tests, 30.69 seconds.
- Initial `make config-check`: **Passed**, Compose and production env/override checks.
- Initial `make dev-test`: **Passed**, 249 tests / 11,985 assertions, SQLite memory.
- First lifecycle/cooldown regression run: **Passed**, 253 tests / 12,021 assertions.
- Both Go modules: **Passed**, formatting, vet, tests and builds using the supported script.
- Initial docs validation/build/links: **Passed**, 91 built pages / 3,368 links.
- Composer locked advisory audit and full docs npm audit: **Passed**, no advisories
  returned by their registries on this date; not an image scan.
- AUD-001 parser replacement and negative fixtures: **Passed**, 3 test methods
  with multiple adversarial Dockerfile cases. Existing checks retained.
- AUD-002 evidence validation: **Passed**, 6 negative/attribution/reporting tests.
  Partial and early-stopped reports now enumerate every unexecuted check.
- AUD-003: Fleet pytest and pinned Python dependencies added to CI and the
  production runner. Generated-production observability remains a distinct
  existing real-runtime gate; Fleet fixtures alone do not qualify an installer.
- Domain assignment and full PSL changes are now in progress; the above Laravel
  results precede those changes and do not qualify them.

The initial Fleet render, incomplete-CA, env interpolation and status-redaction
leads were subsequently fixed and exercised; see AUD-008 through AUD-011 below.

The public DNS validation probe using the host's BIND `delv` failed with a broken
DNSSEC trust chain for `com`. This is an environment failure, not evidence that
public delegation succeeds. Production verification must fail closed on this
condition. Private fixtures do not qualify public DNSSEC or registrar behavior.

## Additional confirmed findings and current evidence

| ID | Severity / confidence | Evidence and preconditions | Fix / status |
| --- | --- | --- | --- |
| AUD-008 | High / confirmed failure tests | Fleet moves an active bundle before replacement; a later-node failure can leave mixed generations | Whole-fleet staging and Linux atomic exchange; later-node failure and post-exchange directory-fsync failure both preserve the previous complete generation. Two injected-failure regressions passed. Full suite rerun pending. |
| AUD-009 | High / confirmed regression | An incomplete private CA pair was regenerated over a surviving private key | Fail closed with restore guidance; validate certificate/key pair, trust and expiry before reuse. Regression passed in Fleet suite. |
| AUD-010 | High / confirmed runtime regression | Fleet quoting interpreted literal dollar values through Compose; status JSON included arbitrary extra-env credentials | Literal quoting/adoption parser and redacted status; actual disposable container environment and status regressions passed. |
| AUD-011 | Medium / confirmed regression | Setup persisted earlier nodes before validating a later invalid node | Validate the whole proposed state before its first write. Regression passed; Fleet total 70 at that checkpoint. |
| AUD-012 | High / confirmed workflow | Trivy JSON was restricted to Critical, omitting lower-severity evidence and High enforcement | Scan by built image digest once with all severities; derive table and High/Critical/EOL enforcement from that exact JSON. Policy and negative fixtures pass. No release published. |
| AUD-013 | Critical / scanner-confirmed component versions; exploitability varies | Local audit images contain vulnerable Go standard libraries and dependencies in Grafana/Loki/plugin, OpenSSL, curl and utility libraries | Patch candidates selected below; rebuild/rescan and runtime compatibility in progress. No exceptions or risk acceptance. |
| AUD-014 | High / confirmed direct request regression | Idempotency replay preceded controller domain-policy checks after assignment revocation | Fixed policy-aware binding before replay; revoked assignment returns 403. Application regression passed. |

Application regression checkpoint: **Passed**, 262 tests / 12,055 assertions.
Isolated PostgreSQL claim qualification: **Passed** actual migrations, concurrent
applicants, unique constraint, finalization/tombstone/cooldown and advisory lock
blocking (1.724 seconds). Existing PostgreSQL volumes were not migrated or reset.
Generated production control/telemetry bundle: **Passed** local startup, explicit
migration, readiness, authenticated metrics, Prometheus discovery and Grafana
datasource checks (209 seconds). This excludes public HTTPS and starter topology.

The first local image scan executed successfully but **failed the production
security gate**: core had 20 Unknown findings; web and edge-control each 9 High;
edge-runtime 16 High; edge-agent and gateway each 10 High; MMDB updater 3 High;
Grafana 47 High / 3 Critical; Loki 12 High / 1 Critical. Counts are package/binary
occurrences, not unique exploitable vulnerabilities. Some entries lack advisory
details; Unknown is not evidence of safety. Logs bind each scan to its immutable
local image ID in `image-scan-summary.json`.

Patch selection stays on existing Go 1.26, Grafana 12.4 and Loki 3.7 lines.
[Go release history](https://go.dev/doc/devel/release) and the
[Go advisory](https://pkg.go.dev/vuln/GO-2026-6090) establish the affected/fixed
standard-library range. [Grafana 12.4.10 release notes](https://github.com/grafana/grafana/releases/tag/v12.4.10)
include security fixes; [Loki 3.7.7](https://github.com/grafana/loki/releases/tag/v3.7.7)
is the current patch candidate. The official
[ClickHouse plugin catalog](https://grafana.com/grafana/plugins/grafana-clickhouse-datasource/)
provides 4.21.2 for Grafana >=11.6; its actual ZIP was downloaded from Grafana's
versioned API, SHA-256 recorded and pinned. Current GitHub release metadata for
that plugin has no assets, so the generator uses the working official catalog
URL. [OpenSSL advisories](https://openssl-library.org/news/vulnerabilities/)
and [curl advisories](https://curl.se/docs/security.html) support targeted package
updates; Alpine repositories were queried for the exact available patch versions.
These selections are not claims that candidate scans or runtime tests pass.

Additional cleanup: removed two unused OpenResty build ARGs
`MODSECURITY_NGINX_VERSION` and `OWASP_CRS_VERSION`: source checkouts already use
full `*_COMMIT` arguments and neither version value reached a build command,
config, mount or generated artifact. Removed the Laravel template's commented
`MustVerifyEmail` import; the User model intentionally implements FilamentUser,
not email-verification behavior. Updated the Go qualification helper from a
mutable Go 1.24 image to the same pinned Go 1.26.8 toolchain used by release builds.
Preserved migrations, both dashboards, DNS-only behavior and public API routes.

Review coverage remains **partial**. Detailed source review has now covered
Domain creation/lifecycle/claim policy and jobs, parent DNS validation, Fleet
state/render/PKI/env/setup, release policy/evidence, authentication controllers,
account middleware, domain assignments, idempotency, panel providers, route
registrations, and relevant regression contracts. Other runtime, recovery,
telemetry and application flows still require completion; scanner output and
passing suites do not substitute for that review.

## Edge identity and latest runtime evidence

| ID | Severity / confidence | Evidence and preconditions | Fix / compatibility / status |
| --- | --- | --- | --- |
| AUD-015 | High / confirmed OpenSSL execution | `EdgeCertificateAuthority::sign` inherited the system CA extension profile; an actual issued identity parsed as `CA:TRUE`. Authentication trusted the chain and serial without matching the enrolled certificate. Requires possession of an enrolled edge private key. | Fixed leaf profile and exact certificate fingerprint matching, TLS provenance forwarded by edge-control and cleared by ordinary ingress. Application regression passed. Real Nginx/PHP/TLS qualification passed; forged child certificate rejected. Ingress-before-core rollout and canary identity rotation required; no automatic CA replacement. |
| AUD-016 | High / confirmed rollback regression | `IdempotentRequest` stored a receipt after a separately committed mutation; injected receipt failure left a newly created API token committed. Cache lock expiry did not provide durable exclusion. | Mutation and receipt now share one transaction, with PostgreSQL transaction advisory exclusion and queue dispatch after commit. Isolated PostgreSQL uses independent processes/local cache to prove conflict, single mutation/replay, and kill-before-commit retry recovery. Passed. No schema migration. |
| AUD-017 | Medium / confirmed Go regression | `verify` passed malformed-length decoded signing keys into `ed25519.Verify`, which panicked. | Reject wrong-length keys before verification. Both Go modules passed format/vet/tests/build after fix. No signing-key rotation or wire change. |

AUD-014 is now fixed: policy-aware domain binding runs before receipt replay.
Direct requests after assignment revocation return 403. The application checkpoint
including AUD-014/015/016 passed **265 tests / 12,069 assertions**. These results
precede the ongoing edge-reporting review and do not qualify subsequent edits.

Real signed parent-delegation qualification passed fresh/stale delegation,
DNSSEC corruption, existing DS refusal and child-apex refusal with actual BIND
and the production resolver. All UDP/TCP × IPv4/IPv6 loopback combinations ran.
An ephemeral COM trust anchor is a fixture only; public DNSSEC remains blocked.
The isolated PostgreSQL checkpoint passed actual claim migrations, race exclusion,
receipt atomicity and worker-death rollback in 27.655 seconds, with measured
claim-lock contention of 1.728 seconds. No existing database was refreshed.

The actual production Nginx configurations passed mTLS client authentication,
header overwrite/clearing, ordinary-ingress denial and rejection of a child
signed by an enrolled leaf, in 15.475 seconds. Environment
`cdnf-mtls-6f28247213b5`, source SHA-256
`3362e46e5cb278c4541e29d664b41e78573fe261af86c086c50f2d7ec9f9df9f`.
Private certificates were ephemeral and removed with that isolated environment.
No browser was launched.

All third-party production Compose images are now pinned by digest. Generated
bundle validation rejects mutable image references before starting a container;
operators must populate `CDNF_*_IMAGE` from verified release evidence. Local
image IDs are accepted for isolated builds but are not portable release evidence.
The expanded Fleet checkpoint passed 74 tests. A rebuilt generated control/
telemetry bundle using immutable local image IDs passed observability checks.

Third-party image scanning **failed** the High/Critical gate. Occurrence counts:
Caddy 38/1, ClickHouse 2/0, PostgreSQL 31/1, DNSdist 113/15, PowerDNS 199/10,
Alertmanager 70/2, node-exporter 38/2, Prometheus 48/2, Vector Alpine 15/2,
Vector Debian 86/3, Valkey 9/0, Alpine utility 2/0 (High/Critical respectively).
These are scanner findings tied to image digests, not proof that each advisory
is reachable in this topology. Advisory triage and further patch/runtime
qualification remain open; no exceptions have been accepted.

## Edge reporting and transport remediation checkpoint

| ID | Severity / confidence | Reproduction and impact | Fix and verification |
| --- | --- | --- | --- |
| AUD-018 | High / confirmed API regression | An enrolled edge submitted passive-origin failures and security events for a domain with no cell assignment on that edge. Health was overwritten; sufficiently large events could change domain security state. | Scope both report types to active domains with active/target cell assignments on the reporting edge. Lock security transitions and dispatch after commit; repair the missing reconcile-job import reached at the suspicion threshold. Assigned reports still succeed; unassigned reports have no effect. Passed. |
| AUD-019 | High / confirmed direct-controller and PostgreSQL contention tests | Applied acknowledgements compared against a model read before a concurrent update, allowing active sequence regression. A heartbeat that advances its stale model can overwrite a newer durable value too. | Atomic conditional acknowledgement and database-side maximum for heartbeat sequence. Actual independent PostgreSQL processes held a newer update while stale handlers waited: applied returned 409, heartbeat 200, both retained sequence 42. Waits 1.579/1.609 seconds. Passed. |
| AUD-020 | High / confirmed Go HTTP-server regression | Control requests accepted plaintext URLs and followed 307/308 redirects, forwarding a registration body to a second origin. Requires a misconfigured endpoint or redirecting control ingress. | HTTPS origins only, no URL credentials/path/query/fragment, and no control redirects. Tests use real TLS servers, preserve trusted certificate verification and assert no redirected/plaintext body delivery. Both Go modules passed formatting, vet, tests and builds. |
| AUD-021 | High / confirmed scan-gate omission | Only first-party release images were scanned; production Compose dependencies and role overrides could bypass vulnerability enforcement. | Existing supply-chain tool now discovers and scans every immutable production dependency, emits complete reports and enforces High/Critical/EOL from that JSON. CI publication depends on this job; production qualification includes it. Negative discovery fixtures passed. Actual dependency gate remains Failed due to unresolved advisories. |

The application checkpoint after AUD-018/019 passed **268 tests / 12,081
assertions**, including the assigned/unassigned report boundary and suspicion
transition. A pre-existing runtime test created a pending domain but asserted
active traffic health; that fixture now explicitly establishes verified active
state before its traffic checks. The assertions remain intact. Pending runtime
configuration has `settings.enabled=false`; this defense was traced before
classifying the reporting issue.

Patched first-party image scans: no High/Critical occurrences in core, web,
edge-control, edge-runtime, agent, gateway or MMDB updater. Web/edge-control
still have Unknown/Medium entries; no claim of advisory-free status is made.
Grafana still has 15 High / 1 Critical and Loki 9 High occurrences at the
`audit-patched` image checkpoint. Grafana's remaining OpenSSL packages are now
patched in source and await a new scan. No scanner exceptions were added.

Advisory triage distinguishes dependency presence from reachability:
[Tempo CVE-2026-21728](https://grafana.com/security/security-advisories/cve-2026-21728/)
affects the Tempo query service, and
[Tempo CVE-2026-28377](https://grafana.com/security/security-advisories/cve-2026-28377/)
affects its S3 configuration endpoint. This topology does not run a Tempo
service, but scanner matches in Grafana's linked Tempo module still require
binary/source reachability evidence before a false-positive determination.
The installed ClickHouse plugin is the current 4.21.2 catalog release and still
contains Go 1.26.5 plus vulnerable x/crypto and gRPC versions. Rebuilding it
locally would invalidate the upstream plugin signature; disabling Grafana's
plugin signature check is not an acceptable fix. A supported signed patched
plugin, or a rigorously demonstrated nonaffected classification, remains open.

Additional cleanup decisions: replaced the quick starts' invented `v1.0.0`
checkout command with explicit immutable source selection and verified-manifest
projection; aligned allowed publishing refs and exact attestation identity;
retained all nine image components and both dashboards. Updated certificate,
upgrade, Fleet config and manual jobs together. Generated output/recovery
material remains untouched by repository cleanup. Full cleanup coverage and
installation/upgrade qualification remain incomplete.

## Artifact assignment boundary

AUD-022 (**High, confirmed regression**): `ReconcileEdgeDomain` treated absence
of all cell placements as permission to distribute a complete domain snapshot
to every enabled edge. An unassigned enrolled edge received the origin/config
artifact; the same snapshot builder includes TLS private material when present.
The regression reproduced the unsolicited artifact row before remediation.

The fallback is removed. Recipients must have active/target cell assignments or
previous artifact history requiring a tombstone. Every domain payload names
explicit cells; an unassigned prior recipient receives only a tombstone. The
first-registration and origin-failover fixtures now establish real pool/cell
assignments before asserting delivery, preserving their configuration and safety
assertions. Application requalification passed **269 tests / 12,088 assertions**. Real runtime rollout and
mixed-version qualification for this boundary remain pending.

Compatibility: no schema migration or protocol version change. Existing
explicit assignments continue to receive configuration. Before qualifying a
canary, inventory actual pool/cell assignments; an installation relying on the
insecure unassigned fallback must establish explicit desired assignments before
rollout. Do not delete active runtime generations, history, or certificate keys.
Retain tombstones and acknowledge retirement before removing old placement
state. Reverting to unrestricted delivery reintroduces the disclosure defect.

The latest Grafana OpenSSL rebuild/scan removed its two OpenSSL High findings;
13 High and 1 Critical linked Go dependency findings remain. Image ID
`sha256:e91de0428be31e35e5c4db9fc87421e21923a9fc9cd05a97792621bf64c9ee19`.
The scan gate remains **Failed**. The current docs build/link check passed
91 pages and 3,387 internal links; Fleet passed 74 tests in 56.64 seconds. The
actual documented manifest-to-Fleet projection passed valid-source/component,
wrong-source, duplicate-component, wrong-publisher and existing-image-conflict
cases, including optimized Python execution, and a secret-safe Fleet dry run.

Open leads requiring additional source/runtime evidence (not exploit claims):
full snapshot memory bound before materialization; concurrent task-result
receipts; scope of historical artifact retrieval; reassigning an identical
payload after a tombstone at the same domain revision; generated start behavior
after host-local enrollment; complete CSR/CA expiry checks; remaining application,
Lua/gateway, recovery, queue/outage and cleanup inventory coverage.

## Fleet activation, rotation, and repeated-policy checkpoint

| ID | Severity / confidence | Evidence and affected paths | Remediation, compatibility and status |
| --- | --- | --- | --- |
| AUD-023 | Medium / confirmed generated-script execution | `scripts/cdnfoundry_fleet/render.py`: after host-local enrollment, the generated start command retained the pre-enrollment profile selection. The fixture set a valid UUID in the same bundle and reproduced missing edge activation. | Read current Compose enrollment before migrations; select edge services only with a valid UUID. No script edit/rerender required. Invalid UUID fails without activation and without echoing its value. Fixed; Fleet regression passed. Full host enrollment remains a separate runtime/manual gate. |
| AUD-024 | High / confirmed application and PostgreSQL regression | `ReconcileEdgeDomain`: inherited pool policy A → B → A at one domain revision reused the historical A artifact, leaving the latest sequence on B. `updateOrCreate` also rewrote a historical snapshot. | Advance desired domain revision when compiled policy or placement payload changes at an existing revision; coalesce recompilation and create immutable historical snapshots. Application passed 270 tests / 12,097 assertions. Independent PostgreSQL workers restored A at revisions 1/2/3 with exactly three unchanged historical artifacts. No schema or wire change; real agent consumption remains pending. |
| AUD-025 | Medium / confirmed permission regression | Generated `start.sh` widened credential-bearing `docker/pdns/pdns.conf` from 0600 to 0644. The mode-0700 bundle directory still blocks unrelated host traversal, but the bind-mounted file itself became readable by unrelated container users or if the bundle's outer protection was lost. Rotation also replaced the file with mode 0600, incompatible with the non-root PowerDNS user. | Exclude this file from generic readability repair; root activation sets root:82 mode 0640 and gives PowerDNS supplementary group 82. Validation rejects broad modes. Actual pinned PowerDNS starts, reads the file, and an unrelated container UID cannot read it. Fixed. Existing bundles must be rerendered and started as root; preserve private directories and existing credentials. |
| AUD-026 | High / confirmed real PostgreSQL failure | `_pdns_reconciliation_script`: `psql -c` did not interpolate `:'next_password'`, producing a syntax error. The script also sourced configuration as shell, overwrote its recovery copy on retry, and lacked concurrent/interrupted-rotation handling. | Replace embedded shell mutation with a self-contained Python helper behind the same generated shell entry point. Lock rotations, validate/stage both files before SQL, send SQL through stdin, preserve the initial private backup, accept already-applied pending credentials on retry, atomically activate each consumer, recreate and health-check only PowerDNS with `--no-deps`. Actual database authentication rejects the old password and accepts the pending password. Concurrent/invalid inputs make no password change; retry after database-only activation repairs consumers. Fixed with the limits below. |

`fleet_pdns.py` passed in 73.599 seconds on isolated project
`cdnf-pdns-qualification-0551b370b60f`, using PostgreSQL 18.4 and PowerDNS 5.1.3
at the Compose digests. Source identity before and after matched
`2fd8a9814dc0a67b7c1f188a114bf1c734a3d1319971e5430062187739b7866f`.
It qualified secret permissions, reproduced legacy SQL failure, and exercised
actual authentication, service health, repeated rotation and database-only
interruption recovery. It deliberately excluded GeoIP and public DNS. A subsequent staged pinned-image run caught dependency recreation when the
changed environment caused Compose to recreate PostgreSQL. Rotation now uses
`--no-deps`, and the regression checks database container identity through
normal and interrupted rotations. It did
not prove multi-host availability, power-loss durability, or a full starter
installation. Existing database volumes were not used or removed.

The full working-tree Fleet suite subsequently passed 75 tests in 62.29 seconds.
The PostgreSQL policy checkpoint passed in 46.127 seconds on disposable project
`cdnf-claim-qualification-7a6f247f100d`. These are bounded regression checkpoints;
full inventory review and final source-bound release qualification remain open.

Cleanup ledger additions: remove the superseded embedded shell/SQL rotation
implementation, retain its public `reconcile-pdns-password.sh` entry point, and
ship its standard-library Python implementation only in pending DNS bundles.
Replace blanket secret readability repair and stale fixed-profile instructions.
Preserve schema history, before-rotation recovery material and all data volumes.
No operator risk acceptance or production qualification is implied.

Local commit `c12601cc` records AUD-025/026 with the generated runtime gate and
operator procedure. Its isolated staged-tree Fleet suite passed 65 tests; the
final pinned-image regression passed in 59.140 seconds on
`cdnf-pdns-qualification-8875c77254d2`, including database container preservation.
Removing `--no-deps` reproduced unwanted database recreation in 42.157 seconds.
The broader working-tree fixes and complete audit remain in progress.

## Transactional edge task receipts

AUD-027 (**Medium, confirmed regression**) affects
`EdgeAgentController::taskResult` and aggregate updates. Independent PostgreSQL
processes reproduced a failed report overwriting a concurrently committed
successful receipt. A paused sibling reporter also overwrote the operation's
completed count with 1 while both tasks had completed. Separate application
regressions showed that an exception after the receipt write left it committed,
and immediate duplicate purge failures consumed another retry during backoff.

Receipts now lock their parent aggregate before the task row, then commit the
receipt and its database effects in one transaction. Terminal receipts remain
immutable. Purge backoff suppresses duplicate failure receipts before the next
attempt becomes available. Global purge reconciliation uses the same lock order
and rechecks completed state. The wire format and task IDs are unchanged; no
migration or new worker is required. Reverting this handler restores the race
and partial-write behavior. Existing inconsistent aggregates require review;
this fix does not retroactively assert that old receipts were correct.

The isolated PostgreSQL regression passed in 20.932 seconds on
`cdnf-task-qualification-21ccbef44d8e`, pinned PostgreSQL 18.4, with identical
before/after source identity. The contender waited 1.577 seconds and returned a
replay receipt, retaining success and one attempt. Sibling results serialized
and reported both completed tasks. `make dev-test` then passed **273 tests /
12,114 assertions** in the supported testing/SQLite-memory environment. PHP
formatting passed. No browser was run; no UI flow or schema changed.

Remaining task-flow review includes delayed reports across retry windows (the
legacy protocol has no attempt identifier), origin configuration changes while
a test is queued, dispatch concurrency, typed result payloads, and task retention.
These remain explicit open leads; this checkpoint does not claim full task-flow
or platform qualification.

Local commit `76f161e0` separately records current host enrollment startup.
Its staged Fleet suite passed 67 tests, including actual Compose parsing,
invalid UUIDs, and failure after valid JSON output. Docs passed 91 pages / 3,390
internal links. The owner-run enrollment checklist was updated and remains
**Not run**.

Local commit `11bbcf63` records the transactional task receipt fix and its CI/
production-runner gate. The independently exported staged tree passed the
PostgreSQL regression in 44.274 seconds on
`cdnf-task-qualification-deaba000111a`; no uncommitted controller changes were
needed for that check. The final contract checkpoint passed Compose, OpenAPI,
91 documentation pages / 3,391 internal links, seven supply-chain fixture
methods and six qualification-tool regressions. Manual browser status remains
**Not run** and the overall production decision remains **Not yet qualified**.

## Origin-test configuration, lifecycle, and dispatch

AUD-028 (**Medium, confirmed application and PostgreSQL regressions**) affects
`DnsRecord`, `ProxyController::testOrigin`, Filament's DNS record test actions,
`DispatchScheduledOriginChecks`, `DispatchOriginTest`, `EdgeTask`, and task
delivery/result handling. A queued test combined an edited origin's settings
with old resolved addresses, survived actor revocation or inactive domain state,
and could overwrite current origin health with a late result. Independent
PostgreSQL dispatchers produced two tasks for one edge and operation; retries
also reset progress and could expand recipients as edge availability changed.
Preconditions are an accepted/queued test and a subsequent configuration,
authorization, lifecycle change, or overlapping worker delivery. This is not
proof of arbitrary-destination SSRF or a runtime DNS-rebinding exploit.

API and Filament now share policy-aware admission on an active, verified domain.
New operations bind the selected origin configuration. Dispatch serializes on
the operation, rechecks access/state/configuration, creates a bounded recipient
set transactionally, and preserves recipients and progress on retry. Delivery
cancels obsolete pending probes; late receipts retain history without changing
health for an obsolete origin. Model updates clear health when origin or mode
changes. Duplicate Filament operation creation and redundant per-edge presence
queries were removed; public API/action names and task wire fields are retained.

No migration is needed. Older operations missing configuration binding must be
requested again; upgrade core and queue workers together so older workers no
longer create unbound tests. Existing task payloads remain readable. Reverting
core reopens the dispatch race and stale-result behavior. The upgrade guide,
origin guide, edge API reference and exact owner-run manual steps were updated;
manual browser status is **Not run**.

The baseline application regression failed five new cases. The PostgreSQL
baseline at `11bbcf63` reproduced two tasks on disposable project
`cdnf-task-qualification-f03f8adebf67` in 32.963 seconds. A first reproduction
attempt failed during PostgreSQL initialization and is not defect evidence;
the fixture now waits for TCP readiness rather than the temporary init socket.
The final independently exported staged tree
`ce82057a5c5efb09855b43929efe80598871932e` passed PostgreSQL receipt, aggregate,
and dispatch concurrency in 34.628 seconds on
`cdnf-task-qualification-b161d097096d`, retaining exactly one task. A working-tree
application checkpoint passed 284 tests / 12,174 assertions, including direct
backend Livewire action denial after assignment revocation. Local commit `65ba768b` records the fix. Its final staged application run passed
263 tests / 12,062 assertions in 66.756 seconds after preparing the example
environment and readable asset directory. PHP formatting, Compose validation,
OpenAPI and 91 documentation pages / 3,392 internal links passed. Source identity
changed only for the audit ledger during the contract check; this is regression
evidence, not a final source-bound release qualification.

Remaining origin-flow work includes fresh bounded edge-side DNS resolution,
current platform destination exclusions, scheduled-check coalescing under
outage, typed result bounds, retry attempt identity, task expiry/retention, and
real HTTP/TLS/address-family qualification. Whole-file inventory review and
starter/recovery/release qualification remain open. The overall production
decision remains **Not yet qualified**.

AUD-029 (**Low, confirmed development bootstrap failure**) affects `make
dev-assets`: Docker's fresh local export destination was mode 0700, preventing
the non-root PHP worker from reading `public/build/manifest.json`. Two fresh
staged application runs failed rendered-view assertions with the missing-manifest
error despite the file existing as 0644. Preparing the export directory as 0755
made the same staged tree pass all 263 tests. The Make target now explicitly
creates this public directory with that mode before export; this does not widen
secret directories or touch data volumes. The
exported staged tree `b482c769d852541b1e32e7d7f3571452501810e1` passed fresh and
repeated `make dev-assets` in 10.823 seconds: mode 0755, parseable identical
manifests, and actual non-root container readability. Documentation links
passed. No migration or manual browser action changes.

Local commit `4ae37aa5` records the public asset directory fix independently of
origin-test commit `65ba768b`. Both were committed locally after verification;
no remote publication or production deployment occurred.

Next origin review candidates: the agent currently uses only the first approved
address and does not resolve the configured hostname at connection time; HTTPS
probe results label successful connections as verified even when verification
is disabled; raw IPv6 URL construction does not use bracketed host syntax.
`OriginData::blockedAddress` also returns early for allowlisted private ranges
before checking configured blocked networks. These need focused adversarial
and real-connection regressions, accounting for existing runtime defenses,
before assigning exploit severity. No fix or qualification for them is claimed
by AUD-028.

## Edge origin-probe resolution and TLS evidence

AUD-030 (**Medium, confirmed real DNS/HTTP/TLS regressions**) affects
`edge-agent/main.go::runOriginTest` and `blockedIP`. Tests showed the previous
agent performed no DNS lookup and contacted the previously approved address
after the hostname's answers became loopback, mixed safe/unsafe, missing, or
unapproved. It did not connect to the new forbidden address; this evidence does
not establish arbitrary-destination SSRF. Successful HTTPS with verification
explicitly disabled was also labeled `verified`, misleading origin diagnostics.
The agent checked only the first approved address, allowing malformed mixed
approval sets past its own guard. Existing Go response-header defaults were
bounded, but exceeded the new explicit 64 KiB probe budget.

The agent now validates every approved address, resolves the configured hostname
under a bounded deadline, rejects all unsafe/unapproved answers, and connects
to a pinned address shared by the approved/current sets. New DNS addresses
require a fresh control-plane operation so platform exclusions are applied;
the edge cannot independently admit an address absent from that operation.
Address sets are capped at 64, headers at 64 KiB, DNS at the smaller of connection
timeout/three seconds, and the entire probe at its response deadline (maximum
60 seconds). IPv4-mapped spellings and scoped addresses are rejected by parsed
address type. Successful HTTPS distinguishes verified and unverified results.
Task fields and old receipts remain compatible; all edge agents must be upgraded
to obtain the new behavior. Reverting restores stale DNS and diagnostic behavior.
No database migration or customer-traffic reload is required.

The baseline `4ae37aa5` failed the new real-network corpus in 83.443 seconds on
`cdnf-origin-qualification-fbac6a166fa5`, including stale DNS, mixed approval,
header budget and incorrect TLS status. Both baseline and fixed agent passed
literal and AAAA-resolved IPv6: the suspected IPv6 URL failure was not reproduced
and is not recorded as a vulnerability. URL assembly now uses `JoinHostPort`
for explicit standard bracket handling. A preliminary fixed run exposed literal
IPv4 normalization differences in the resolver; literals now bypass DNS and are
validated directly. Its failed result is retained, not counted as a pass.

The fixed working-tree corpus passed in 81.940 seconds on
`cdnf-origin-qualification-c49984f2bbb9`, private IPv6 subnet
`fd18:a9e8:5a34::/64`, using the pinned Go 1.26.8 image. Source identity was
unchanged. It exercised real UDP A/AAAA, DNS timeout, rejected/unverified/trusted
TLS (trusted verification in a fresh process), header limits, loopback/mapped
addresses, and IPv4/IPv6 HTTP. The helper-process test is skipped in the outer
Go invocation because its parent already invokes it with the fixture trust
store. The required IPv6 test passed and is never accepted as skipped by
`tests/e2e/origin_probes.py`. This Python entry point runs in CI and the existing
production runner as `origin-probes`; it creates/removes only its own disposable
container/network and does not touch PostgreSQL or named volumes.

Both working-tree Go modules passed formatting, vet, tests and builds in 77.768
seconds. Final exported staged checks are recorded after completion. The
operator/API/testing guides and owner-run manual expectations were updated;
manual browser status remains **Not run**. Public/external IPv6, full agent/API
delivery, current-policy changes after admission, scheduled-check backlog,
retention and the rest of the runtime inventory still need qualification.

Additional CI lead requiring a negative fixture: the Go job's inline module
loop runs with `set +e`, so a failing earlier module may be masked by a later
successful module. The standalone `test-go.sh` fails closed; verify the inline
job separately before recording a confirmed gate bypass.

The final staged origin runtime checkpoint passed in 86.955 seconds for tree
`78c1b5aa26540013c9a99251dee93653c7436b8c`, including a mapped IPv4 AAAA answer.
The staged Go modules passed formatting/vet/tests/build in 77.867 seconds.
Compose and OpenAPI passed; documentation initially rejected undocumented
fixture-only environment keys, then passed after those were documented: 91
pages / 3,395 internal links in 42.755 seconds. Seven supply-chain fixtures and
six qualification-tool regressions passed. The runtime recipe and CI select
Go 1.26.8, verified from the pinned image's actual `go version` output.

Local commit `37186d22` records AUD-030 and its runtime/CI gates.

AUD-031 (**Medium, confirmed qualification-gate bypass**) affects the Go job in
`.github/workflows/ci.yml`. Its module loop ran with `set +e`; an earlier failed
vet/test/build was masked when the final module passed. A failed formatter
could also leave empty captured output and pass the formatting condition. The
new fixture executes the actual job shell with controlled tool exits: the
baseline returned success in five failing cases (four first-module tools and
the last-module formatter). This is evidence about CI exit propagation, not
about whether a particular production binary contains a vulnerability.

The logging pipeline now enables errexit and pipefail inside its worker group,
while the parent still captures pipeline status for the existing failure summary
and annotation. Both modules must pass. A new CI step runs two unittest methods
covering all eight first/last-module tool failures and a successful two-module
run. Evidence files are redirected to each fixture's temporary directory; no
real test or compiler result is mocked into a production pass. The regression
passed in the working tree in 2.652 seconds; the exported staged result is
recorded below. No application/runtime/schema behavior changed, and there is no
new manual browser job. The remote GitHub workflow itself was not executed.

The staged CI regression passed in 2.131 seconds for tree
`f425501353cc02ba0614c660e5c096ee861dfaf4`. CI YAML parsing, documentation links,
and Markdown lint passed. The complete audit and production qualification remain
in progress; no external deployment, browser run, or remote publication occurred.

## Commit and clean-worktree checkpoint — 2026-09-08

The owner requested focused commits of accumulated changes and a clean Git
worktree. This checkpoint packages the work already performed; the full audit
and production qualification remain incomplete.

AUD-032 (**High, confirmed control-plane destination-policy bypass**) affects
`NetworkAddress::isUnsafe` and `OriginData::blockedAddress`. An assigned domain
user could save primary or backup origins using expanded IPv4-mapped IPv6
spellings that bypassed a textual prefix guard. An explicitly blocked private
network could also be admitted through the private allowlist, and alternate IPv6
spellings bypassed configured individual-address exclusions. The baseline API
corpus failed all 16 denial cases while the existing 284 tests passed.

The fix uses packed CIDR matching for mapped addresses and configured individual
exclusions, and evaluates explicit blocked networks before the private allowlist.
The same 16 cases now return validation errors without changing the record,
domain revision or reconciliation operations. The supported isolated application
suite passed **300 tests / 12,270 assertions** in 46.43 seconds (66.749 seconds
including asset export and Compose setup). No schema change is required for this
fix. Existing stored origins are not rewritten; revalidate them before rollout.
Reverting restores the admission bypass. Operator guidance now documents deny
precedence and address spelling behavior.

This is an admission-policy finding, not a proven private-origin HTTP exploit.
A separate disposable OpenResty canary returned 502 for canonical loopback/mapped
forms and 500 for expanded forms; none reached the private canary. The 500 cause,
Lua address normalization and mixed DNS-answer handling require further tracing.
These runtime leads remain open and are not counted as remediated or qualified.

The following local commits record the accumulated implementation:

| Commit | Scope |
| --- | --- |
| `b236d37d` | AUD-031 CI Go failure propagation |
| `47788f3f` | AUD-032 origin admission policy |
| `f16ac4c6` | Fresh domain claims, parent delegation, lifecycle and authorization |
| `2275f05e` | Transactional API idempotency receipts and queue commit boundary |
| `5df65eaf` | Exact enrolled certificates and client-only identity issuance |
| `d1dcfc7f` | Scoped edge reports/artifacts and monotonic revisions |
| `693dd800` | HTTPS control origins, redirect rejection and signing-key length |
| `3e1964ce` | Typed Fleet inputs and complete atomic bundle generations |
| `b1cdc72a` | Pinned images and audited package updates; scan gate still fails |
| `3a2e3509` | Complete image scanning and attributable qualification evidence |

Fresh local checks exercised the unchanged implementation bytes before/during
commit grouping. Source-content SHA-256 was
`f7f004d91228cf1f774e34558e284a876cf2e127b4dee70288f067c130e2d02d`;
subsequent changes in this checkpoint are documentation only. Ignored local JSON
records contain before/after source identities, commands, duration and exit code:

| Evidence label | Actual result |
| --- | --- |
| `origin-address-fixed` | 300 application tests / 12,270 assertions passed; testing / SQLite / memory |
| `commit-clean-python` | 76 Fleet tests, seven supply-chain tests and eight qualification tests passed |
| `commit-clean-pint` | PHP formatting passed |
| `commit-clean-contracts` | Compose, production overrides/env generation, OpenAPI and 19 observability contract tests passed |
| `commit-clean-postgres` | Actual migrations, claim/idempotency concurrency, process death, sequence acknowledgements and policy restoration passed in disposable tmpfs PostgreSQL |
| `commit-clean-parent` | Signed BIND parent fixture passed, including negative DNSSEC/DS/child-apex cases and local IPv4/IPv6 transport |
| `commit-clean-go` | Both Go modules passed formatting, vet, tests and builds |
| `commit-clean-mtls` | Real Nginx/PHP TLS identity, header provenance and leaf-signing rejection passed |
| `commit-clean-supply-policy` | Static policy passed for eight Dockerfiles and two workflows; this is not a vulnerability scan pass |

The qualification-tool test log deliberately contains a failed synthetic release
result to verify failure propagation; its unittest suite passed. Persistent
PostgreSQL and named volumes were preserved. The additive domain-claim migration
was exercised only in isolated qualification; deployment order and forward
recovery are documented in the upgrade guide. Nothing was pushed or deployed.

Completion gates remain separate: implementation **partial across the full audit**;
documentation **current for these changes**; automated/runtime qualification
**passed only for the recorded checks, incomplete overall**; manual browser
qualification **Not run, owner-owned**. Grafana/Loki and third-party image scan
failures remain release blockers. Full first-party review, starter/multi-host
recovery, public delegation/IPv6 and a signed release bound to the final source
remain outstanding. A clean Git worktree does not close those gates.

## OpenResty origin address qualification — 2026-09-08

AUD-033 (**Medium, confirmed runtime defense gap and availability defect**)
affects `docker/openresty/runtime.lua` address matching and peer selection.
Expanded IPv6 origins passed textual guards but failed at the balancer with
`invalid port`: `origin_access` removed the brackets needed by peer selection.
The real canary established failed HTTP and verified HTTPS to a permitted ULA
origin. Its TLS-name failure also surfaced as 500 instead of the expected
bounded origin failure. Separately, the runtime compared excluded IPv6 addresses
as strings, accepted malformed forms past its guard, and rejected explicitly
allowlisted carrier-grade addresses despite the control-plane policy.

A directly supplied runtime host of `[::1]` reached the private loopback canary
on the baseline. This fixture supplies runtime state directly: the API removes
brackets before validating addresses, and customer mutations do not directly
write this file. Therefore the result proves a runtime defense gap, not a
customer-to-private-service exploit through signed artifact delivery. Expanded
unbracketed loopback/mapped forms returned 500 without reaching the canary. The
connection-format repair and guard repair must ship together.

The fix uses libc `inet_pton` to compare packed address values and CIDR prefixes,
rejects invalid/bracketed/scoped addresses at the runtime boundary, retains the
IPv6 peer brackets after validation, and aligns private/explicit-deny precedence
with the control plane. Hard exclusions are parsed once per worker. The shared
CIDR helper also serves trusted-proxy and security-rule matching; real IPv4 and
IPv6 matching/nonmatching requests are included in the regression corpus. No
new runtime service, per-domain resource, artifact field or database migration
is introduced. The Linux image already provides libc and LuaJIT FFI. Reference
semantics are documented by [Linux inet_pton](https://www.man7.org/linux/man-pages/man3/inet_pton.3.html)
and [OpenResty peer selection](https://github.com/openresty/lua-resty-core/blob/master/lib/ngx/balancer.md).

`tests/e2e/origin_destinations.py` uses one 512-MiB / one-CPU / 128-process cell,
two uniquely named private networks and a synthetic HTTP/TLS canary. The image
is resolved to its local immutable ID; current runtime/config sources are
mounted explicitly. IPv6 cannot be skipped. The complete baseline corpus at
`222eefdd` failed **18 of 44 checks** in 39.279 seconds, including the carrier
allowlist mismatch. It preserves the old runtime SHA and exact fixture SHA in
local evidence. The rebuilt fixed runtime passed all **44 checks** in 39.133
seconds, including denied-canary log assertions, trusted TLS/wrong SNI,
invalid-file last-valid state, and container restart. Image ID:
`sha256:5064a2ba9ce2fe5496830220d5906c2e8e9ae6292217dd23d7f980af7e4b82ab`.
A first restart attempt used the old ephemeral Docker host port and failed the
fixture readiness check; the fixture now reads the new published port after
restart. This failed attempt is retained and is not counted as a runtime pass.

The fixture runs in CI after image build and as the existing production runner's
`origin-destinations` gate. CI itself was not run remotely. Operator rollout and
owner-run IPv6 origin expectations were updated; manual browser status remains
**Not run**. Runtime mixed DNS-answer handling, public IPv6, signed agent-to-cell
activation for this corpus and the full production topology remain separate
open gates. No persistent database, named volume or production deployment was
changed.

Broader regression qualification initially stopped because the cumulative
OpenResty fixture assumed an unrelated Vector DNS name existed on its network.
The fixture now supplies a loopback syslog-host mapping for each test cell;
actual UDP telemetry delivery remains unavailable and must not stop serving.
This does not replace Vector/ClickHouse delivery qualification. The isolated
rerun passed in **164.928 seconds** on project `cdnf-origin-runtime-127f078cc3`,
private subnet `172.16.250.0/24`, with no application database. It covered the
existing cumulative cache/compression/TLS/isolation/restart/graceful-shutdown
checks. Only documentation changed during this run; this is not signed final
release evidence. Compose/OpenAPI, 19 observability contracts, seven supply-chain
and eight qualification-tool tests also passed in 48.439 seconds. Documentation
build/link checks passed 91 pages / 3,398 internal links; the subsequently
corrected owner steps use the actual form labels and read-only port behavior.
Full production qualification and manual browser execution remain incomplete.

## Complete origin DNS answer validation — 2026-09-08

AUD-034 (**Medium, confirmed runtime destination-policy and timeout defects**)
affects `docker/openresty/runtime.lua::resolve`. The previous loop returned after
its first address, before validating the rest of that answer or the other family.
It also ignored DNS error codes while falling back to another query. Its resolver
socket timeouts did not impose a deadline on the entire A/AAAA operation.
Precondition: an origin hostname's DNS replies contain a later unsafe address,
a family error, excessive answer records or slow responses. The baseline made
requests to the permitted canary despite those incomplete/invalid results. It
did not connect to the forbidden loopback address: this is not evidence of an
arbitrary private-destination SSRF exploit.

The real DNS fixture reached Docker's embedded resolver over UDP and TCP and
returned controlled records with zero TTL. Against source `3bf0f472`, **11 of
65 checks failed** in 42.807 seconds: mixed A records, mixed families, mapped
AAAA, family errors, 65-record sets, unsafe CNAME/TCP responses, a cumulative
lookup deadline and a change to unsafe DNS before another request. The baseline
canary logs independently confirmed the unexpected origin requests. The
original 44 address/TLS/restart tests continued to pass.

The fix validates all addresses in both successful answer sections before
selecting a pinned peer. Successful NODATA is accepted for an absent family;
NXDOMAIN, other DNS errors, empty final resolution, any blocked address, or more
than 64 combined answer records fails closed. CNAME records count toward that
budget. There is no new CNAME chasing subsystem: normal recursive responses
containing terminal addresses remain supported. At most one DNS worker coroutine
and one deadline coroutine are created per lookup; the losing coroutine is
cancelled and all resolver sockets are explicitly destroyed. Existing per-domain
origin-connection bounds still apply before lookup starts. The deadline is the
smaller of three seconds and the configured origin response timeout (whose valid
minimum is 500 ms); upstream connection/response limits remain separate.

The packaged [lua-resty-dns documentation](https://opm.openresty.org/package/openresty/lua-resty-dns/)
and actual installed resolver source distinguish socket/retry limits from total
elapsed time, and describe automatic TCP fallback. The implementation uses
[OpenResty light-thread wait/cancellation](https://github.com/openresty/lua-nginx-module#ngxthreadwait)
to bound the entire operation. New stable failure reasons distinguish resolution
failure, response-limit rejection and deadline expiry. No database, signature,
artifact schema or customer cache-key change is involved. A canary runtime-image
replacement is required; rolling back restores the weaker admission behavior.

The rebuilt runtime passed the extended corpus, followed by **71 checks** in
48.538 seconds with additional TCP and maximum-deadline cases. The configured
500-ms cases completed in 0.509/0.514 seconds; the three-second case completed in
3.020 seconds. Four concurrent deadline requests completed in 0.507–0.512 seconds,
and the authenticated cell counter returned to **zero origin connections**.
The allowed 64-record set, A-only/AAAA-only names and valid recursive CNAME/TCP
answers passed. Image ID:
`sha256:1b3b2b0e5c820c4e8088dc68e9652f4032dfc525644bdf0836b156e35834c41a`.
The DNS helper uses a pinned Python image with 64 MiB / half a CPU / 64 processes,
private fixture networks and no published DNS port. It is test-only and does
not add a product service or public resolver dependency.

The broader OpenResty suite passed in 159.387 seconds in disposable project
`cdnf-origin-runtime-643be25f90`; Compose/OpenAPI, 19 observability contracts,
seven supply-chain fixtures and eight qualification-tool tests passed in 49.856
seconds. The existing CI and production `origin-destinations` gate run the
expanded corpus. Operator/user guidance and exact owner-run DNS checks were
updated. Manual browser status remains **Not run**. Public resolver behavior,
full production topology/recovery, throughput limits, complete first-party review
and unresolved image advisories still prevent a production qualification claim.

AUD-035 (**Medium, confirmed failover availability defect**) was exposed while
qualifying the backup path for AUD-034. `M.origin_failure` converts an internal
origin failure into a 444 response so the outer cache can apply stale policy.
For pre-connect DNS failures, no upstream HTTP status exists, and `M.origin_done`
treated that 444 as success. It reset primary failure counts instead of activating
the backup. The origin-role variables were also assigned only after resolution,
so backup DNS failures could be attributed to the primary.

The NXDOMAIN-primary regression failed three of 76 checks in 47.947 seconds:
backup never activated after two failures, backup failure attribution was absent,
and restoring backup DNS did not restore serving. These failover functions were
unchanged from `3bf0f472`; the reproduction included the DNS-validation patch.
The fix preserves an internal failure flag for receipt accounting and records
the selected role before resolution. The external cache failure protocol is
unchanged. This also preserves release of the correct origin-role connection
slot after an internal error redirect. No schema or artifact change is needed;
rolling back restores the failover defect.

The rebuilt image passed **all 76 checks** in 54.536 seconds. Two primary DNS
failures activated the AAAA-only backup, a changed unsafe backup answer recorded
`backup_failure` while remaining on backup, and restoring its DNS restored HTTP
200 with the backup role. The origin-connection counter remained zero after the
concurrent deadline corpus. Image:
`sha256:c037f85dfceabba70609d31ab374386b429e3f981307997abe0e87931a4e3c6d`.
The final staged check records the exact tree separately in local evidence.

The origin-slot and retry-status leads are now confirmed below as AUD-036/037.
The broader audit remains partial and manual browser qualification remains
**Not run**.

## Origin reservation accounting

AUD-036 (**Medium, confirmed origin-limit bypass and incorrect diagnostics**)
affects `docker/openresty/runtime.lua` and `docker/nginx/edge-runtime.conf`.
The roadmap requires bounded origin connections and predictable local rejection.
On parent `922e5250`, `M.origin_done` reconstructed a connection key from the
hostname even when access rejected the request before acquiring a reservation.
Internal error redirects discard `ngx.ctx`, so the reconstruction also masked
whether acquisition had happened. An unauthenticated requester reaching a
configured proxied hostname could repeatedly release another request's slot.
Other cell/client limits remain applicable; this is not evidence of unlimited
host capacity or a cross-tenant data disclosure.

The disposable runtime reproduction held one real HTTP origin request under a
one-connection limit. The first excess request returned 503 but changed the
reported active count from one to zero. The next two reached the canary with
HTTP 200 while the first request still ran. Completing the held request left
the cell counter at **−1**. Five of 81 checks failed in 51.931 seconds; the
source and synthetic request/canary evidence are recorded in the local
`runtime-capacity-before` checkpoint. No application database was used.

The fix stores the acquired key in a request variable that survives named
redirects, clears it before releasing the slot, and never infers a reservation
from current hostname configuration. Local rejections also skip passive origin
failure and failover accounting. Zero-valued reservation counters are retained
in the existing bounded shared dictionary, avoiding a decrement-then-delete
window that could erase another worker's newly incremented counter. Durable
desired state, permissions and artifact schemas are unchanged; runtime effects
are local shared-memory accounting only. Roll out a matching runtime/config
image through the existing canary process. A rollback restores the defect;
there is no migration or persisted-data reversal.

The rebuilt image passed the initial **81 checks** in 55.860 seconds: all three
excess requests remained 503 with one active connection, completion returned the
count to zero, and the next request succeeded. The extended regression also
checks the same behavior with a configured backup and verifies no passive
failure or false failover is recorded: **86 checks passed** in 58.414 seconds
using image `sha256:bb5402982dd3d5d5a712a119b369ef5abae6fc3de714afaf3151afb70305a46d`.
The broader OpenResty runtime suite passed in 158.732 seconds in disposable
project `cdnf-origin-runtime-02fbbfab6b`. Compose/OpenAPI, observability contracts,
supply-chain/qualification-tool tests and documentation validation also passed.
Documentation and the additional backup-capacity test were edited during the
broader run; its runtime Lua/Nginx sources were unchanged. These checkpoints
are local regression evidence, not a signed final-source production release.
No UI changes are involved; the existing owner
manual browser checklist is retained and **Not run**. Full topology/recovery,
production load, image advisories and whole-codebase review remain open gates.

## Origin retry budget and health accounting

AUD-037 (**Medium, confirmed retry amplification and false failover**) affects
`M.balance`, `M.record_passive_failure`, `M.origin_done` in
`docker/openresty/runtime.lua` and the shared origin server in
`docker/nginx/edge-runtime.conf`. The roadmap forbids unbounded retries.
The precondition is a proxied hostname with retries enabled and an origin
returning retryable errors. Ordinary customer requests could amplify failing
origin traffic beyond the configured retry count; recovered requests could
unnecessarily activate backup. Other client, connection and I/O timeout limits
remain in force. No tenant-data disclosure was observed.

On parent `eae00912`, the balancer replenished the additional-attempt budget on
every invocation. A one-retry request made **12** actual origin requests before
the synthetic canary's safety ceiling returned 200, instead of stopping after
two attempts. A stricter security limit was exceeded as well. Separately,
503→200 and 503→503→200 sequences returned 200 to the client but recorded a
503 passive failure and activated backup. Four of 92 checks failed in 55.329
seconds in `runtime-retry-baseline`; disabled-retry controls passed. The earlier
`runtime-retry-before` experiment also exposed a fixture observation limitation:
the status endpoint's bounded key scan could omit receipts after the large
destination corpus. Receipt assertions now run before that corpus; missing
records are not treated as proof of correct health accounting.

The correction grants the retry budget only once per origin request, clamps it
to the existing supported maximum of two retries and adds a Nginx hard ceiling
of three total attempts. Passive health and failover read the final upstream
status, while preserving pre-connect DNS failure handling and single reservation
release. This follows the documented semantics of
[OpenResty's additional-attempt API](https://github.com/openresty/lua-resty-core/blob/master/lib/ngx/balancer.md#set_more_tries)
and [Nginx's multiple upstream statuses](https://nginx.org/en/docs/http/ngx_http_upstream_module.html#var_upstream_status).
These runtime changes do not alter desired state, authorization, task delivery,
artifact schemas or database data. Use the existing canary image rollout with
matching Lua and Nginx configuration; rollback restores the defects.

The rebuilt runtime passed **96 checks** in 63.540 seconds, using image
`sha256:a40b8584f1cf55491f5241b2bed51feb427b5a7e025ba48812f3d51da2db5f79`.
Canary logs confirmed exactly one, two or three attempts according to the
configured/security limit. A POST that received 503 was sent only once; verified
IPv6 HTTPS passed both recovery and exhaustion cases. Final 200/404 responses
left primary active with no passive failure; exhausted requests recorded one
503 receipt and activated backup. All origin reservations returned to zero.
The previous address, DNS, capacity, last-valid-state and restart checks also
passed. No application database or browser was used. This does not qualify
public infrastructure, production load or the whole release.

The broader OpenResty suite passed in **164.430 seconds**, in disposable project
`cdnf-origin-runtime-039bcd2d1e`. Compose/OpenAPI, 19 observability contracts,
seven supply-chain fixtures and eight qualification-tool tests passed in
39.130 seconds. Documentation was edited during these broader checks; the
runtime/configuration and test sources were unchanged. The current CI and
production `origin-destinations` gate automatically include the new corpus.
Manual browser qualification remains **Not run**; existing UI steps are
unchanged. Full Fleet topology/recovery, production load, release image
advisories and the rest of the first-party audit remain open.

## Origin cleanup during hostname removal

AUD-038 (**Medium, confirmed reservation leak during runtime updates**) affects
`docker/openresty/runtime.lua::M.record_passive_failure`. An origin response
can finish after an asynchronous runtime snapshot removes its hostname. The
failure path returned early when current configuration was absent, bypassing
release of the request's acquired connection reservation. Desired state and
authorization were unchanged, but the stale counter could consume capacity
after a hostname was restored and inflate cell diagnostics. This requires an
in-flight failure overlapping removal; ordinary successful responses already
released correctly. No tenant-data disclosure was observed.

The real OpenResty reproduction on parent `872bafce` held a request at a
synthetic origin, atomically published a snapshot without the hostname, and
confirmed new requests returned 421 before releasing the held response. The
successful-response control returned its counter to zero. The failing response
left **one occupied slot**; restoring that hostname under a one-connection limit
then returned **503 instead of 200**. Two of 102 checks failed in 65.167 seconds
in local checkpoint `runtime-lifecycle-before`. The canary log proved exactly
one origin attempt for each held request. No database or shared volume was used.

The correction invokes reservation cleanup even when current hostname
configuration is absent. It records no new passive receipt for a removed host.
The existing request-owned key determines which slot to release, independently
of the new snapshot. Permissions, durable desired state, artifact schema and
runtime activation remain unchanged. Roll out the updated runtime image through
the existing canary process; no database migration is needed. Rolling back
restores the leak. This fixture exercises runtime snapshot replacement directly,
not end-to-end control-plane deletion or signed agent delivery.

The rebuilt runtime passed **102 checks** in 73.367 seconds, including both
removal outcomes, restored HTTP 200, and zero occupied slots after completion.
Image: `sha256:f9835db95a10c4ca5e533cc6470faf1c3495867d549fb3fb395881bbd40fa89b`.
Compose and production-environment/override validation passed in 5.587 seconds.
Documentation changed during those checks; the runtime and fixture sources
were unchanged. The broader OpenResty suite was not repeated for this branch
correction; its preceding result is recorded under AUD-037. Manual browser
qualification remains **Not run**, with no UI changes. Full topology/recovery,
production load, unresolved image advisories and remaining first-party review
still prevent production qualification.

## Isolated checks and uploaded TLS chains — 2026-09-12

| ID | Severity / confidence | Affected boundary and observed failure | Correction / status |
| --- | --- | --- | --- |
| AUD-039 | Low / confirmed qualification reliability defect | `Makefile` test and OpenAPI targets unnecessarily waited for development PostgreSQL/Redis; tests also mounted writable persistent application storage/cache | `compose.test.yml` provides bounded disposable storage and forced SQLite-memory configuration with no runtime dependency startup. Test isolation committed as `094bbcaa`; OpenAPI uses the same override. |
| AUD-040 | Medium / confirmed API and real TLS failure | `UploadedCertificate::validateChain` checked only signatures/order, accepting non-CA/non-signing issuers, expired issuers/roots, path-length violations, client-only leaves, excluded DNS names and unknown critical extensions | Native TLS-server-purpose chain verification rejects all eight cases before durable mutation. Valid private CAs remain supported. Fixed for new/repeated uploads; existing installed bundles require operator review. |
| AUD-041 | High / confirmed plaintext private-material persistence | Chain parsing ignored non-certificate blocks but stored the original input in unencrypted `tls_certificates.chain_pem`; both API and Filament reused that field unchanged on same-leaf uploads | Store only exported public certificates and replace the chain on repeat uploads. API and Filament regressions pass. Historical rows/backups are not silently scrubbed; affected keys require rotation/revocation and restricted recovery handling. |

AUD-040 requires an authorized custom-certificate upload and affects that domain's
serving availability; it does not confer public CA trust or access to another
tenant. The roadmap requires uploaded chain validation and preservation of the
previous valid certificate. Before correction, all eight adversarial chains
returned **202 instead of 422** and could supersede the active certificate.
AUD-041 requires private-key PEM to be included in the chain field. Reading that
unencrypted database/backup field could then reveal key material without the
application encryption key. No production key or customer data was used or
exposure in an existing installation claimed; all fixtures are synthetic.

Baseline `tls-chain-baseline` on `98c6ed37` plus its recorded test/override diff
failed **nine tests**, with 301 passing in 104.815 seconds. The ninth failure
proved raw private-key PEM reached the public-chain column. Initial remediation
passed 310 tests in 100.115 seconds. A subsequent same-leaf regression
`tls-repeat-before` failed one of 15 tests in 20.553 seconds: re-upload retained
legacy chain text. Both upload entry points now replace that chain under the
existing domain lock and publish the normal new revision, preserving the
certificate ID. Rejected chains leave the active ID, status, revision,
certificate count and edge artifact count unchanged.

The validator retains the 16-KiB leaf/key, 64-KiB chain and ten-certificate bounds.
Only canonical public certificates enter two temporary files, closed on success
or failure. The supplied self-signed root is the explicit trust anchor. Native
verification must return **true**; false and error returns reject the chain.
This follows the [PHP OpenSSL purpose-check contract](https://www.php.net/manual/en/function.openssl-x509-checkpurpose.php)
and [OpenSSL chain-verification semantics](https://docs.openssl.org/3.5/man1/openssl-verification-options/).
No remote validation, synchronous runtime mutation, new schema or data migration
is introduced. This is not a public-browser trust or revocation check.

Final application checkpoint `tls-upload-application` passed **312 tests /
12,367 assertions**, 57.28 seconds inside PHPUnit and 139.475 seconds for the
supported `make dev-test` target. This includes PHP/Livewire action tests for
repeat uploads; no browser was launched. The shared PKI helper now throws on
generation failure instead of incrementing PHPUnit's assertion count for each
generated fixture certificate. Non-application documentation/qualification
files changed during this run; the tested PHP application/test sources did not.

`tests/e2e/uploaded_tls.py` mounts the current chain validator into a networkless
PHP container and uses a disposable OpenResty cell with real TLS sockets.
The valid private-CA control returned HTTP 200. Each rejected chain was then
deliberately forced into runtime state to prove that a verifying client refused
it for the corresponding constraint failure. Restoring the valid snapshot
restored its exact fingerprint and HTTP 200 after every case. This directly
qualifies chain/client behavior and snapshot restoration; API last-valid
transactions are covered separately. Signed agent delivery and ACME renewal
are not exercised by this fixture.

The first runtime experiment failed because a single matching handshake did
not establish that every worker had reached its one-second refresh timer.
The fixture now waits for that refresh before checking actual peer identity.
`tls-upload-runtime-after` passed in 39.746 seconds; the final formatted fixture
passed in **41.315 seconds** as `tls-upload-runtime-formatted`, instance
`cdnf-uploaded-tls-826aa6e8b21f`. Image IDs:

- PHP: `sha256:31a2938dc87baf01a37e1080a85a06e359eaeff82043b5cf20748daa0a0fe4eb`;
- OpenResty: `sha256:f9835db95a10c4ca5e533cc6470faf1c3495867d549fb3fb395881bbd40fa89b`.

The compatible existing PHP image reports OpenSSL 3.5.7; the client reports
OpenSSL 3.0.13. Current validator/runtime files were mounted explicitly; this
does not claim rebuilt release-image or advisory qualification. PHP was bounded
to 256 MiB/one CPU/64 processes, and OpenResty to 512 MiB/one CPU/128 processes,
with bounded tmpfs and only a random loopback TLS port. No existing database or
named volume was used by that runtime fixture. Documentation changed during
the final runtime run; its PHP and Python fixture and runtime sources did not.

Roll out the updated control-plane image and restart its application workers
through the existing deployment workflow. Rollback requires no schema changes
but restores the admission and plaintext-chain defects. Review existing custom
bundles without logging their contents; re-upload a currently valid chain to
normalize the active row. If a key was stored in the old chain field, rotate it,
replace/revoke its certificate and restrict existing backup copies as described
in [TLS certificates](../guides/tls.md). This local audit has not modified any
live installation or erased historical data.

CI and the production runner now include the required `uploaded-tls` gate.
Compose, OpenAPI and the seven supply-chain/eight qualification-tool regressions
passed in **53.484 seconds** (`tls-upload-contracts-after`). The deliberate
failure printed by the negative evidence fixture is its expected assertion,
not a failed real TLS run. OpenAPI dependency isolation is committed as
`0953775b`. Documentation lint, build and links passed in **47.458 seconds**
(`tls-upload-docs`), covering 91 built pages and 3,408 internal links before
this result annotation. Its source hash was unchanged across the command;
only the local commit advanced. Pint and Python compilation passed.
The updated inventory contains **763 files:
661 pending, 98 partial and four reviewed**. Overall effort remains roughly
20% complete, not measured security coverage. Implementation and documentation
for this bounded correction are present; manual browser steps are updated and
**Not run**. Whole-source review, image advisories, complete Fleet topology/
recovery, public traffic and production load remain open. **Not yet qualified
for production.**

## Uploaded chain cryptographic strength

AUD-042 (**Medium, confirmed authenticated-domain availability defect**) affects
`UploadedCertificate::validateChain`. The PHP purpose check added in AUD-040
enforces chain constraints but exposes no authentication-strength option.
An authorized uploader could still submit a leaf with a 1024-bit RSA issuer or
root, or a SHA-1 signature on the leaf/intermediate. A valid chain relationship
alone does not make those certificates usable by the serving TLS stack.
No cross-tenant access or practical key-recovery attack is claimed.

On `12ea51bc` plus the recorded regression diff, `tls-strength-before` failed
**four of 20 tests** in 22.269 seconds. Every new weak-chain case returned 202
instead of 422. All 16 existing upload tests passed.
The same assertions check that rejected uploads preserve the previous active
certificate, domain revision and edge artifact count.

The validator now runs the local OpenSSL command with `verify -purpose sslserver
-auth_level 2`, the uploaded root as its only trust anchor and a five-second
process timeout. Public leaf PEM arrives through stdin; public issuer/root PEM
uses the existing two temporary files, closed on every outcome. No private key
enters the command, its arguments or its input. Canonical PEM persistence,
hostname coverage, existing bounds and asynchronous runtime publication remain
unchanged. Authentication level 2 enforces at least 112-bit strength across
chain keys and non-root signatures. Root self-signatures are not evidence of
trust. The native verifier handles algorithm parameters rather than adding a
custom signature parser. See the
[OpenSSL authentication-level and explicit-trust options](https://docs.openssl.org/3.5/man1/openssl-verification-options/)
and [standard-input verification contract](https://docs.openssl.org/3.5/man1/openssl-verify/).

`core/Dockerfile` now explicitly installs `openssl=3.5.8-r0` alongside the
already-pinned libraries. The existing compatible image contained OpenSSL
3.5.7. An isolated qualification layer upgraded its CLI and both libraries to
3.5.8 successfully in **9.132 seconds** (`tls-strength-image`); this small layer
does not qualify a rebuild of the complete production image.

The supported `make dev-test` invocation invalidated the frontend export's PHP
dependency layer after the Dockerfile edit. It was deliberately cancelled while
installing compiler dependencies: ordinary available disk was zero and
root-reserved free space had fallen to about 1.43 GB. Checkpoint
`tls-strength-application` exited 2 after 181.960 seconds, before PHPUnit ran.
No migration or database cleanup was performed. Application qualification
continues through the same `compose.test.yml` isolation with already-built
assets; a complete fresh image/build gate still needs sufficient disk.

Compose, OpenAPI, seven supply-chain and eight qualification-tool regressions,
and immutable-source policy passed in **50.489 seconds**
(`tls-strength-contracts`). This is not a new image vulnerability scan. Pint and
Python compilation passed. No schema migration is needed. Roll out the updated
control-plane image; rollback restores weak-chain admission. Existing installed
weak chains need a newly issued strong replacement; do not downgrade client or
server security levels. Owner-run browser steps are extended and **Not run**.

The isolated full application command was:

```sh
docker compose -f compose.dev.yml -f compose.test.yml run --rm --no-deps core php artisan test
```

It passed **316 tests / 12,403 assertions**, with 67.70 seconds inside PHPUnit
and 74.109 seconds total (`tls-strength-application-isolated`). It used the
existing vendor/assets preparation and the same forced testing/SQLite-memory
environment and disposable storage as `make dev-test`. The cancelled build is
not counted as passed. PHP application/test sources stayed unchanged during the
completed run; runtime-fixture and documentation changes account for the
recorded overall source-hash change.

The first strength runtime attempts required every bad certificate to reach
the client's verification step. The weak-issuer case instead failed inside
OpenResty's certificate callback with `SSL_add0_chain_cert() failed`, before a
peer certificate could be observed. That is an actual serving failure from the
accepted weak bundle, not successful rejection at the upload boundary. The
fixture now accepts that distinct result only after observing the candidate's
active revision and a new matching certificate-installation diagnostic. An
unrelated TLS alert or old log line cannot satisfy that assertion. After every
negative case, the original valid fingerprint and HTTP 200 must be restored.

`tls-strength-runtime-qualified` passed in **54.345 seconds**, instance
`cdnf-uploaded-tls-0c1aa11a5fed`. All twelve invalid chains were rejected by the
actual upload validator. Forced weak-chain snapshots produced fresh server
certificate-installation failures; the original eight constraint cases failed
client verification. Every restoration returned the original fingerprint and
HTTP 200. The CLI and its loaded library report OpenSSL 3.5.8; PHP's compile-time
constant still reports 3.5.7 in this existing-image derivative. The Python client
reports OpenSSL 3.0.13. Images were:

- qualification PHP layer: `sha256:c1c6cd6efb496f847e06738e81bf4e812aff5a2815a88a2b339de3d2ec55070d`;
- OpenResty: `sha256:f9835db95a10c4ca5e533cc6470faf1c3495867d549fb3fb395881bbd40fa89b`.

Current validator/runtime sources were mounted explicitly. Only documentation
changed during this completed runtime run. Its disposable container/network
were removed. The superseded PHP purpose-only call is replaced by the bounded
native verifier; API fields, uploaded private-CA support and encrypted-key
custody are preserved. This is a scoped correction, not full deployment or
image-advisory qualification. Full first-party review, adequate build disk,
Fleet topology/recovery, public traffic/load and owner browser evidence remain
open; the overall goal is active and production remains unqualified.

Documentation lint/build/link checks passed in **46.823 seconds**
(`tls-strength-docs`), covering 91 built pages and 3,409 internal links before
the final result annotations. Final annotation lint and source-link validation
are checked separately. No runtime/application code changed afterward.

## TLS upload revision races and panel authorization

AUD-043 (**Medium, confirmed PostgreSQL concurrency defect**) affects the API
and Filament custom-upload actions. Hostname coverage was checked before the
bounded OpenSSL verification, then the later transaction accepted that result
without binding it to the domain revision. Another authorized request could
add a proxied hostname during validation; the stale candidate could replace a
certificate that covered the new name. This is an authenticated same-domain
configuration race, not a cross-tenant certificate disclosure.

The extended `postgres_domain_claims.py` uses actual application migrations on
its own disposable PostgreSQL and two PHP processes. A test-only PATH wrapper
pauses the real OpenSSL command after the upload reads hostname coverage. The
other process adds `api.example.test` through the actual DNS controller and
commits revision 3, while the pending upload was validated against revision 2.
The original certificate covers both names; the candidate covers only
`www.example.test`. On parent `ac8b6641`, `tls-concurrency-before` failed in
**54.688 seconds** because that stale upload returned **202 instead of 409**.
Separate deterministic API and Filament regressions both failed in 10.172
seconds before correction. Those feature tests inject a revision change at the
verifier boundary; they do not substitute for the real PostgreSQL interleaving.

Both upload entry points now capture the revision used for validation and
compare it after acquiring the existing domain row lock, before any certificate
or domain mutation. DNS controller mutations use that same lock. A mismatch
returns API `409` / `conflict`, or a field validation error in the panel, with
instructions to reload and retry. No certificate is superseded, new revision
written, or reconciliation dispatched by the rejected upload. Current-state
validation still runs on retry. Cryptographic verification remains outside
the transaction; no new synchronous runtime effect, abstraction, schema or
migration is introduced. Rollback restores the race. Deploy through the normal
control-plane image/worker rollout; no installed data needs rewriting for this
correction.

`tls-concurrency-after` passed the real PostgreSQL suite in **53.665 seconds**,
instance `cdnf-claim-qualification-6d81a6a8451d`, using
`postgres@sha256:9a8afca54e7861fd90fab5fdf4c42477a6b1cb7d293595148e674e0a3181de15`.
The stale upload returned 409, preserved revision 3 and the original certificate
ID/count, and retained both proxied names. Retrying the insufficient bundle
returned 422. Existing claim uniqueness/reclaim, idempotency/process-death,
edge sequence and policy-revision concurrency cases also passed. The fixture
used a 512-MiB tmpfs database and one CPU, with a random loopback port, and
removed its own container. No development database, named volume or browser
was used. This qualifies the transaction boundary, not signed edge delivery
or a fresh production-image build.

The separate concern about submitting a previously mounted upload dialog after
domain access revocation was **not reproduced**. The installed Filament
`ViewRecord::hydrate` reauthorizes access on subsequent requests, and the
current domain policy queries the assignment. A filled-dialog regression
passed on the unchanged implementation in **9.763 seconds**
(`tls-revocation-panel`): submission after detachment returned 403 with unchanged
revision, no active certificate and no certificate/operation rows. That
between-request scenario needs no production authorization change. This does
not qualify every other action or in-flight authorization transition.

The isolated application command recorded under AUD-042 passed **319 tests /
12,427 assertions**, 71.19 seconds inside PHPUnit and **77.589 seconds** total
(`tls-concurrency-application`). API conflict and panel field-error behavior,
normal uploads and revoked access all passed. Current PHP/test sources were
unchanged during the run; documentation/coverage edits explain the overall
source-hash change. Compose, OpenAPI, seven supply-chain fixtures and eight
qualification-tool regressions passed in **49.697 seconds**
(`tls-concurrency-contracts`). Pint and Python compilation passed. The existing
CI and production `postgres-claims` gate includes the new scenario without a
second qualification system.

Operator and owner-browser instructions now explain conflicts, retry coverage
and revoked-dialog behavior. Manual browser execution remains **Not run**.
Review of remaining TLS mode/renewal transitions and the wider first-party
inventory continues. Full image builds, Fleet topology/recovery and external
production evidence remain open; production is still unqualified.

Documentation lint/build/link checks passed in **43.982 seconds**
(`tls-concurrency-docs`), covering 91 built pages and 3,410 internal links before
this result annotation. Final relevant PHP/PostgreSQL fixture diffs were also
compared with their completed test evidence and matched.

## Managed TLS activation concurrency and rollback

AUD-044 (**Medium, confirmed same-domain integrity and availability defect**)
affects `core/app/Jobs/EnsureManagedCertificates.php`. The job read desired
state without locking the domain, then wrote a certificate activation and
revision separately from its operation record. A delayed job could overwrite a
newer custom-certificate selection or reuse a revision already committed by a
DNS writer. An operation-write failure could leave an activation committed
without the corresponding reconciliation operation. This requires competing
authorized work or a persistence failure; cross-tenant access was not shown.

On parent `c186cae3`, the extended real PostgreSQL fixture reproduced both races
in **62.241 seconds** (`managed-tls-concurrency-before`). Separate PHP processes
run the actual TLS/DNS controllers and managed job; PostgreSQL activity confirms
domain-row lock contention before the writer is released. In the custom case,
the job replaced the writer's custom certificate with a managed certificate
while leaving mode `custom`. In the DNS case it activated at revision 7, already
used by the writer, instead of 8. The application failure-injection regression
also failed before correction in **9.311 seconds**
(`managed-tls-rollback-before`): revision 2 survived the operation failure when
revision 1 and no activation should have remained.

Managed planning now runs in a database transaction after locking and rereading
the domain. Eligibility, mode, certificate reuse and revision decisions use
current desired state. Activation, orders and operation records commit together;
issuance and edge jobs dispatch after commit. No ACME/network call was moved
inside the transaction. Existing name sets, order limits, jitter and job
uniqueness remain unchanged. No schema migration or stored-data rewrite is
required. Deploy through the normal control-plane image and worker rollout;
rolling back the code restores these races and partial-write behavior.

`managed-tls-concurrency-after` passed in **60.830 seconds**, instance
`cdnf-claim-qualification-4c84ea043e3e`, using
`postgres@sha256:9a8afca54e7861fd90fab5fdf4c42477a6b1cb7d293595148e674e0a3181de15`.
The custom writer's mode, certificate ID and revision 5 were preserved. The DNS
writer committed revision 7 and managed activation used revision 8. Existing
claim, idempotency/process-death, edge-sequence, policy and TLS-upload races also
passed. The fixture used its own 512-MiB tmpfs PostgreSQL, one CPU and random
loopback port, then removed only its container. No persistent database or named
volume was changed.

The supported isolated Compose application command passed **320 tests / 12,436
assertions**, 66.20 seconds inside PHPUnit and **72.550 seconds** total
(`managed-tls-application`). The injected operation failure now preserves the
prior revision and null activation, creates no operation and queues no edge
job; retry commits activation, its operation and dispatch. The recorded source
hash was unchanged throughout both successful runs. Evidence files retain
commands, parent commit, source hashes and source diffs under the ignored
`storage/qualification/security-audit/` directory.

The coverage inventory now records **763 files: 658 pending, 101 partial and
four reviewed**. These entries remain partial; operation-receipt semantics,
other TLS transitions and broader lifecycle bounds still need review. The owner
browser checklist was extended and remains **Not run**. Signed edge delivery,
full production image builds, Fleet recovery and external production gates are
not qualified by these transaction tests. The overall audit remains active and
production remains **not yet qualified**.

Compose validation, OpenAPI and the seven supply-chain/eight qualification-tool
regressions passed in **52.384 seconds** (`managed-tls-contracts`). Pint passed
for both changed PHP files and Python compilation passed for the PostgreSQL
fixture. Final implementation and regression diffs matched both successful
application/PostgreSQL test records; subsequent edits only document results.

Documentation lint, build and link checks passed in **45.121 seconds**
(`managed-tls-docs`), covering 91 built pages and 3,411 internal links before
this result annotation. No implementation or regression source changed after
its successful qualification.

## Custom TLS mode selection after removal or expiry

AUD-045 (**Medium, confirmed same-domain availability defect**) affects
`TlsController::update` and the Filament `ViewDomain` TLS-mode action. Each
checked for a usable custom certificate before acquiring the domain lock, then
selected the certificate again inside the transaction without rejecting a null
result. Concurrent removal or expiry during that wait could therefore commit
custom mode with no active certificate. This requires authorized changes or a
certificate expiring during selection; no cross-tenant access was demonstrated.

On parent `fdd0aa45`, `tls-mode-removal-before` reproduced the removal race in
**64.396 seconds** using actual PHP controllers and disposable PostgreSQL.
The removal request held the domain lock, revoked the custom certificate and
selected a managed fallback at revision 10. PostgreSQL activity confirmed that
the custom-mode request was waiting before removal committed. The waiting
request then returned **202**, advanced to revision 11 and replaced the saved
fallback with a null certificate in custom mode. Existing managed activation
and DNS-concurrency cases still passed in this baseline.

The API and Filament expiry regressions both failed before correction in
**10.750 seconds** (`tls-mode-expiry-before`, two tests / eight assertions).
They advance the test clock past expiry when the action rereads the domain in
its transaction. The API returned 202 instead of 409; the panel accepted the
change without a field error. This deterministic clock boundary complements,
but does not substitute for, the real PostgreSQL removal interleaving.

Both entry points now select the usable certificate under the existing domain
lock and reject custom mode when that selection is null, before changing desired
state or writing its audit record. The existing API `409` / `conflict` and panel
**Mode** validation message remain the contract. The failed action creates no
revision, operation, audit entry or edge dispatch. Valid selection and other
mode behavior are preserved; no synchronous runtime action or schema change was
introduced. Deploy the normal control-plane image/worker update. No migration
or data rewrite is required, and code rollback restores the defect. Operators
who already have custom mode without a valid certificate should upload valid
coverage or explicitly select managed mode and verify runtime acknowledgement
and HTTPS; this fix does not silently rewrite existing domain choices.

`tls-mode-removal-after` passed in **65.217 seconds**, instance
`cdnf-claim-qualification-afdbc0a9abaa`, using the same pinned PostgreSQL digest
and isolated tmpfs/loopback bounds recorded for AUD-044. The custom selection
returned **409**, retaining revision 10, managed mode and the managed certificate
ID. All earlier PostgreSQL cases passed. This qualifies the desired-state
transaction, not signed edge delivery or externally served production HTTPS.

The isolated application suite passed **322 tests / 12,461 assertions**, 74.08
seconds inside PHPUnit and **80.381 seconds** total (`tls-mode-application`).
Both expiry entry points now reject the change and preserve mode, revision,
operation/audit counts and dispatch state. Compose, OpenAPI, seven supply-chain
fixtures and eight qualification-tool regressions passed in **56.932 seconds**
(`tls-mode-contracts`). Pint passed on the three changed PHP files; Python
compilation passed. Documentation edits account for source-hash changes during
these runs; implementation and regression diffs are checked against the captured
source evidence before commit.

The owner-run mode-removal checklist is current and **Not run**. Coverage remains
**763 files: 658 pending, 101 partial and four reviewed**. Managed-operation
reporting and remaining TLS transitions are still under review. Production
remains **not yet qualified**, including the previously documented image-build,
Fleet/recovery, dependency and external-infrastructure gates.

Documentation lint/build/link checks passed in **43.222 seconds**
(`tls-mode-docs`), covering 91 built pages and 3,412 internal links before this
result annotation. Final implementation/regression diffs matched both passed
application and PostgreSQL evidence records.

## Managed TLS operation type isolation

AUD-046 (**Low, confirmed operation-reporting defect**) affects
`EnsureManagedCertificates::handle`. With healthy managed coverage and pending
renew/reissue requests, the ordinary planner completed both operation types
without creating an order. The forced job could later create an order, but
could not update the already-completed reissue receipt. This falsely reported
forced planning success and lost its order association; certificate disclosure
or issuance bypass was not demonstrated.

The regression submits both actual API requests, runs the ordinary job first
and reads their operation resources. On parent `fb18fff8`,
`tls-operation-type-before` failed in **8.307 seconds**: reissue was `succeeded`
where it should still be `pending`. The job now completes only the operation
type corresponding to its force flag. Existing domain scoping, pending-status
filter, transaction, coalescing and certificate/order behavior are unchanged.
The regression continues through forced planning and retry, requiring the
correct order ID, no duplicate order and unchanged active certificate/revision.

No schema migration, API response shape or runtime artifact changes are needed.
Roll out the control-plane image and workers normally; rollback restores the
incorrect receipt completion. Existing completed receipts are not rewritten:
operators should inspect actual TLS orders before relying on older reissue
results. Planning success does not establish CA issuance or edge activation.
Remaining receipt/admission races, ineligible-domain outcomes and maintenance
coverage still require review. No ACME or edge-runtime qualification is claimed
for this operation-state correction.

The isolated application suite passed **323 tests / 12,480 assertions**, 71.69
seconds inside PHPUnit and **78.425 seconds** total (`tls-operation-application`).
The forced request stayed pending through ordinary renewal, then recorded its
new order ID; retry retained that ID and did not duplicate the order. The
completed renewal receipt and active certificate/revision stayed unchanged.
Final PHP diffs matched the captured successful source evidence.
Compose, OpenAPI, seven supply-chain fixtures and eight qualification-tool
regressions passed in **48.366 seconds** (`tls-operation-contracts`); Pint passed
both changed PHP files. No migration, browser automation or persistent database
mutation was performed. Manual browser status remains **Not run** and the wider
audit/production gates remain open.

Documentation lint/build/link checks passed in **49.001 seconds**
(`tls-operation-docs`), covering 91 built pages and 3,413 internal links before
the result annotations. Coverage remains 763 files: 658 pending, 101 partial and
four reviewed. Production remains **not yet qualified**.

## Bounded TLS maintenance coverage

AUD-047 (**High production-availability impact, confirmed dispatch starvation**)
affects `DispatchManagedTlsMaintenance`. Every hourly invocation selected the
same lowest-ID eligible domains and stopped at its limit (500 by default).
Later eligible domains could therefore remain outside automatic renewal
planning indefinitely unless another action explicitly queued them. This is a
scale-dependent renewal defect, not evidence of a cross-tenant exploit.

On parent `0f95d101`, `tls-maintenance-coverage-before` failed in **8.759 seconds**:
with three eligible domains, three ineligible controls and a batch limit of two,
the second invocation repeated IDs 1 and 3 instead of reaching ID 6. The command
now retains a cursor and fixed upper domain ID in shared cache, selects at most
the existing limit, and starts a new sweep after finishing the range. New
arrivals do not extend an in-progress sweep indefinitely. Eligibility remains
active lifecycle, verified nameservers and at least one proxied record. Existing
TLS-mode inclusion, challenge cleanup and alert behavior are unchanged.

A shared 300-second lease serializes domain scans. It is refreshed during
dispatch and before saving progress; detected lease loss stops progress
publication. Queue exceptions leave the previous cursor available for retry.
Cache loss restarts a sweep and can repeat unique/idempotent work. The cache
holds rebuildable scheduling metadata, not desired domain state or certificate
material. No schema migration, per-domain timer, service or runtime artifact is
introduced. Normal image/worker rollout enables the change; rollback restores
starvation. Operators must use shared persistent cache and budget batch cadence,
queue latency and CA/DNS capacity against the renewal window. Cache resets or
repeated failures can delay a sweep, so the scheduling formula in the TLS guide
is not a production throughput qualification.

Initial rotation/failure/lock regressions passed before adding lease-loss and
separate-process cases. The final isolated application suite passed **327 tests
/ 12,509 assertions**, 66.38 seconds inside PHPUnit and **72.592 seconds** total
(`tls-maintenance-application`). It covers filtering, late arrivals, wraparound,
queue failure/retry, lost progress, competing scans and replacement of an
expired lease, including preservation of the replacement owner's lock. Pint
and Python compilation passed. Compose, OpenAPI, seven supply-chain fixtures
and eight qualification-tool regressions passed in **57.020 seconds**
(`tls-maintenance-contracts`).

The first separate-process PostgreSQL attempts failed their test expectation
(`tls-maintenance-postgres`, **67.860 seconds**, and its diagnostic repeat,
**64.514 seconds**). The fixture initially omitted the eligible domain left by
the preceding policy gate. Diagnostic output showed the command correctly
included it and saved cursor 5 with upper ID 8. The fixture now explicitly
includes both earlier eligible domains plus its three new ones, retains the
bounded batch assertions, and records progress before/after each command.
These attempts are failed qualification runs, not passing production evidence.

A further attempt (`tls-maintenance-postgres-qualified`, **9.812 seconds**)
failed before maintenance: application tables were absent when the claim
processes ran. The fixture used socket-based `pg_isready` and discarded its
initialization output. It now probes TCP readiness, rejects a failed migration
command, and requires the exact successful initialization marker before
starting concurrent work. This strengthens the existing gate without touching
a persistent database or accepting a failed check. The final runtime result is
recorded separately below.

Documentation lint/build/link checks passed in **42.783 seconds**
(`tls-maintenance-docs`), covering 91 built pages and 3,414 internal links before
the final result annotations. The owner-run browser checklist remains **Not
run**. Coverage is **763 files: 657 pending, 102 partial and four reviewed**;
challenge cleanup/alerts and the wider TLS/runtime/Fleet review remain open.

After readiness correction, `tls-maintenance-postgres-verified` ran for
**70.394 seconds** and demonstrated the complete first sweep: `[4, 5]`, `[6, 7]`,
then `[8]`, with progress shared across processes and reset at the range end.
Its immediate wraparound expectation failed because those queued jobs still
held their real shared-cache uniqueness locks. The fixture now advances only
its PHP test clock between scheduler ticks, preserving the locks and modeling
the documented hourly cadence. It does not wait real hours or claim measured
hourly throughput. The failed run remains recorded; no production dispatch or
uniqueness rule was weakened. Inspection of the pinned PostgreSQL image also
confirmed that its initialization server is socket-only, supporting the TCP
readiness correction.

`tls-maintenance-postgres-hourly` passed in **67.112 seconds**, instance
`cdnf-claim-qualification-18d3822e5829`, on
`postgres@sha256:9a8afca54e7861fd90fab5fdf4c42477a6b1cb7d293595148e674e0a3181de15`.
Separate PHP processes using the disposable PostgreSQL cache store recorded
batches `[4, 5]`, `[6, 7]`, `[8]`, then `[4, 5]` on simulated hourly ticks.
The cursor and fixed upper ID persisted across processes, reset at sweep end,
and remained unchanged while a different process held the scan lease. All
previous claim, idempotency/process-death, policy, edge-sequence and TLS race
cases also passed. The fixture used its existing 512-MiB tmpfs database, one CPU
and random loopback port, then removed its own container. No named volume or
persistent application database was modified.

Final application PHP diffs matched the successful application evidence; the
PostgreSQL command/fixture diffs matched this successful process-level run.
This qualifies bounded scheduling with the tested cache driver and workload,
not production Redis outage recovery, complete issuance throughput, signed edge
delivery or the wider Fleet topology. No new migration or operator data rewrite
is required. Production remains **not yet qualified** and the audit stays active.
