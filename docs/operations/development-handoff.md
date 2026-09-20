---
title: Development branch handoff
description: Completed audit batch, actual test evidence, remaining jobs, and dev image retrieval.
---

# Development branch handoff

**Checkpoint date: 2026-09-19.** This closes the previous open-ended work batch
as a development delivery handoff, at the owner's request. Remaining security,
installation and production acceptance work is assigned to separate jobs in the
[new roadmap](../roadmap.md). It does not claim that the full audit is complete.

The requested deliverable is remote `dev` with a successful CI run and all application and managed infrastructure
GHCR images published. Live deployment is a separate Phase 1 job;
no staging host access or live deployment was requested for this handoff.

## Staging install and smoke job

**2026-09-20 — Release CI correction.** The Fleet qualification step in
[run 35532096806](https://github.com/vaheed/CDNFoundry/actions/runs/35532096806/job/106136942429)
failed because Vector's new edge identity mapping introduced an inline Compose
default. Production Compose now reads `EDGE_ID` directly from the environment
file, matching the edge agent. Fleet already emits this field, including an empty
value before enrollment. No migration, persistent-state change or deployment is
required by this correction; reverting the single Compose line reverses it.
All 80 Fleet tests, documentation links, Compose configuration, production
overrides and empty/enrolled identity projection passed locally. Real Vector
HTTP ingestion, identity mapping, redaction and all
11 origin metric cases passed for both identity values using locally available
image `sha256:c4af7611f5df8dfd162a27858332e24c2082a080246fedf451c7e769ff86407f`.
The exact release image remains subject to CI qualification. Manual browser
qualification was not run; this correction changes no browser workflow.

**2026-09-20 — Phase 1, `staging-install-and-smoke`.** The owner confirms the
previous delivery job is finished and images are published. Delivery statements
below are historical checkpoints, not instructions to repeat publication or
remediation. This job starts from clean `dev` at `a8c694fd`; neither archived
roadmap is changed. No staging installation or production readiness is inferred
from publication.

### Inputs and scope

Host access and the management/platform domain choices have now been supplied
through the protected local `.prod` inventory. IPv6 is explicitly disabled for
this staging job. Reference protected files or access methods, never paste
credentials into this report. Remaining inputs are tracked below.

| Input | Required detail |
| --- | --- |
| Release | Selected run [35472074232](https://github.com/vaheed/CDNFoundry/actions/runs/35472074232), source `a8c694fd34a2ac5210daa243d9a77ab4b1437451`; manifest plus all 17 image signatures, SBOMs and provenance verified |
| Hosts | Three supplied SSH targets reachable with passwordless sudo; one control/telemetry and two DNS/edge roles; Ubuntu 22.04, 4 CPUs, about 4 GB RAM and 66 GB free disk each; no existing CDNFoundry installation found |
| Management DNS | Owner supplied suffix; all six required A records resolve to their intended hosts; no AAAA records; public control HTTPS passed |
| Public DNS | Platform and initial customer zones published; exact customer claim delegation remains owner-pending |
| Origin | Owned HTTP and verified HTTPS endpoint, Host/SNI, disposable hostname and cacheable test resource |
| Address families | IPv4 only per owner; Fleet IPv6 disabled; public service/firewall reachability still requires qualification |
| Protected configuration | Protected draft and release evidence under `.prod`; ACME contact supplied; administrator and assigned domain-user credentials delivered through protected local files; use the existing zero-credential GeoIP provider |

Use the [starter quick start](../deployment/production-quick-start.md) in its
existing order. Verify the selected release with the existing
[release verifier](software-supply-chain.md#verify-a-release); do not republish,
rebuild or repeat scans solely because a new roadmap job started. Generate and
validate bundles from the verified image projection before transfer. Preserve
existing PostgreSQL data, named volumes, Fleet state, keys and TLS material.

The durable changes are installation state, explicit application/PowerDNS
migrations and disposable desired-state records in PostgreSQL. External effects
are host activation, delegated DNS, queued runtime deployment and certificate
issuance. Use authenticated host access and administrator/API policies; send
idempotency keys for API mutations and follow operation acknowledgements.
Bound smoke traffic to the disposable zone and a single probe sequence per
host/family; poll operations with a fixed deadline and record timeout as failure.
Retain previous verified bundles/images and last-valid runtime state. Recover
through the documented rollback procedure, never database refresh, key
regeneration or volume deletion. A fresh installation has no earlier serving
release to claim as a tested rollback target.

### Execution and evidence matrix

Release verification, control installation, both DNS roles and direct platform
DNS checks have passed. Both edge roles are enrolled with ready shared-pool endpoints; customer proxy/TLS/cache
and security smoke checks remain **not run**. Run non-browser
probes using Python under `tests/e2e` against the selected inventory; adapt only
where a reproduced installation gap requires it. Existing development fixtures
are not automatically safe or suitable for remote staging. Do not point an
unreviewed destructive fixture at these hosts or launch the full production
acceptance matrix as a substitute for this bounded smoke job.

| Order | Check and expected result | Evidence to retain |
| --- | --- | --- |
| 1 | Verify signed manifest/images, Fleet dry-run, generated bundle validation; all components match selected immutable release | Source SHA, run URL, manifest hash, image digests, sanitized validation results |
| 2 | Start control using generated explicit migration workflow; health/readiness over verified public HTTPS succeed; Horizon processes a queued operation and Scheduler executes due work | Migration status, service health, operation ID/result, worker/scheduler timestamps |
| 3 | Start DNS roles; restricted API TLS/auth succeeds from control, private services are not publicly reachable; register/test/enable both clusters | Cluster IDs, connection results, listener observations without credentials |
| 4 | Publish platform identity and initial customer SOA/NS to both targets before delegation; then delegate/verify and add DNS-only record | Desired/acknowledged revisions, public parent answers, UDP/TCP SOA/NS/A and configured AAAA from each host |
| 5 | Enroll each edge, assign shared pool/cells/endpoints; listener-only generation and fresh heartbeat converge before proxying | Edge/pool IDs, gateway/cell generation and revision, endpoint addresses |
| 6 | Validate origin, proxy test hostname, obtain DNS-01 TLS; HTTP and verified HTTPS reach expected origin via each edge/family | Origin result, certificate public fingerprint/expiry/name, status and request IDs; no private material |
| 7 | Repeat cacheable request for MISS/HIT; URL purge and full epoch purge each acknowledge and cause fresh fetch; controlled security deny then restore succeeds | Operation IDs, cache results, epoch/revision, deny/restore status and continued comparison traffic |
| 8 | Vector delivers the generated traffic to ClickHouse; metrics, four Grafana datasources and two dashboard UIDs respond through APIs; Loki receives sanitized operational event | Bounded API/query results, event/request IDs and freshness; no rendered UI inspection |
| 9 | Restart one role/host at a time; identities, desired state, DNS and HTTPS recover using retained volumes; control unavailability preserves last-valid serving | Before/after generation and revision, DNS/HTTP observations, restart/recovery times and health |

Use verified TLS throughout; do not suppress certificate errors. Execute DNS
UDP/TCP and HTTP/TLS checks from the recorded external probe location for every
approved address family. Explicitly record unavailable IPv6 or a smaller topology
as an unqualified limitation. Stop and retain sanitized evidence when a check
fails; fix only reproducible Phase 1 installation blockers and rerun affected
checks. Load testing, broad adversarial audit, clean-host recovery and dependency
review remain assigned to their later roadmap jobs.

For each executed row retain timestamp, operator, environment/topology, selected
source/image identities, command and exit code, expected/actual result,
operation/revision IDs and a sanitized evidence reference. Use the structured
step fields in [production qualification](production-qualification.md#owner-evidence)
when exchanging JSON evidence, but do not label this partial smoke record a
full production qualification pass. Keep raw evidence in a protected store.

### Owner-requested release checkpoint — 2026-09-20

The owner requested that this job stop, all remaining source changes be committed,
`dev` be merged into `main`, and tag `v0.9.8` be published with release notes.
The job ends at this checkpoint; **Phase 1 is not complete**. Do not resume
installation or start Phase 2 automatically. The next requested roadmap job must
close the remaining Phase 1 gates. See the [release notes](releases/v0.9.8.md). Their inventory covers the full
pre-merge `main` (`602c605b`) to `dev` comparison and changes since v0.9.7,
including the earlier security, TLS, origin, Fleet and dependency work. The
rendered documentation checker now resolves dotted version-page names correctly.

Deployed state at stop:

- Control remains on signed source `a8c694fd`; it has not received the shared
  timezone migration, independent healthy-edge KPI or recovered-operation query.
- Both PoPs use the verified `80eea902` agent and runtime images. Its manifest
  signature and all 17 image signatures, SPDX and SLSA attestations passed.
  Manifest SHA-256: `19a5ee738b68d782557734b04c23475accd1976a33beda36d1c2e3b3aa539ab2`.
  Its migration marker is stale; subsequent source derives that marker correctly.
- The previously failed full purge recovered through normal asynchronous purge
  reconciliation and both edge receipts succeeded. A fresh full purge then
  advanced epoch 2 to 3; refresh and subsequent HIT passed on both edges.
- The origin-error Vector correction is deployed on all hosts. Following runtime
  deployment, actual origin timings were 51–69 ms; cache hits retained null
  latency, and successful sampled traffic carried zero origin errors. The final
  enrolled-edge identity correction is committed but not deployed.
- `tests/e2e/staging_continuity.py` passed both control-outage and single-PoP
  Docker-restart modes against staging. HTTPS certificates and content hashes
  remained correct; control readiness recovered and named volumes were retained.
  The initial runner's 90-second stop timeout was shorter than Horizon's existing
  120-second grace period. Services were restored; a corrected 180-second timeout
  passed without changing production shutdown policy.
- A live health check after purge recovery showed only the seven historical
  verification failures and the missing verified backup. The committed query
  excludes those failures after a later successful same-domain check. The owner
  explicitly retained the backup warning and deferred backup setup.

Latest local checks actually run: **25 Laravel tests / 172 assertions** for
operations dashboard and domain lifecycle in guarded SQLite memory; **9
supply-chain tests**; **19 observability tests**; real Vector identity-override and
relay modes (11 origin-metric cases each); PHP formatting; Compose/config checks;
and documentation validation. Earlier relevant timezone, Go and real-runtime
results below remain valid and were not rerun unnecessarily. No browser automation
was run. PostgreSQL and named volumes were preserved; no new control migration was
applied. Protected deployment credentials and raw evidence remain outside Git. The
temporary staging API token was revoked at closeout; both user login credentials
remain in their protected local files.

Remaining operations: verify the final release workflow and signed evidence;
deploy the selected control image with a fresh protected database snapshot and
explicit `2026_09_20_170000_add_display_timezone_setting` migration; deploy the
Vector identity configuration; check PostgreSQL recovery-health results and
per-edge aggregates; collect the exact owner browser results. Existing rollback
image references and protected host environment backups are retained. Release
publication alone does not close these gates.

### Completion gate

| Gate | Current result |
| --- | --- |
| Implementation/installation | All three roles installed; corrected agent/runtime deployed on both PoPs; final control/UI and Vector identity changes await deployment |
| Documentation | Current browser/API setup, manual steps, runtime results and release checkpoint recorded |
| Automated/runtime qualification | Control/DNS, account isolation, Grafana APIs, customer HTTP/HTTPS, cache/URL/full purge, security, control outage and PoP restart **passed**; final changes await deployment/retest |
| Owner-run browser qualification | **Partial**: both login/access checks passed; healthy-edge KPI retest outstanding; remaining checks **not run** |

Preparation checks executed on 2026-09-20:

- `make config-check`: **passed** development/test/production Compose parsing,
  production environment generation and role override validation; no services
  started or images rebuilt.
- `make docs-check`: **passed** source links (99 documents), Markdown lint,
  VitePress build and built-site validation (93 pages, 3,582 internal links).
  The first build failed on two links to the unpublished manual checklist;
  repository links corrected the failure and the full rerun passed.
- `git diff --check`: **passed**. Archived legacy roadmaps remain unchanged.
- Application tests, live staging artifact verification and non-browser runtime
  probes: **not run** in this preparation unit; no application code changed and
  these were not run during the initial documentation-only preparation.

### Host preparation and release verification checkpoint

- Public release run: **35472074232**, source
  `a8c694fd34a2ac5210daa243d9a77ab4b1437451`. The public artifact download used
  a relay because unauthenticated GitHub artifact API access returned 401.
  The relay is not a trust authority: the manifest and every image were verified
  using pinned Cosign against the exact official `refs/heads/dev` workflow and
  GitHub OIDC issuer before projecting any digest.
- **Passed:** signed manifest verification and all **17/17** image signatures,
  SPDX attestations and SLSA provenance, including subject digest, source commit,
  workflow, build type and builder checks. No image rebuild or scan rerun.
  Manifest SHA-256:
  `95087bd7705c8ff04ba4960fb45f217a75864dce77dc32a65c31d499c8446f28`.
- **Passed:** three-host SSH/sudo access, external GHCR HTTPS connectivity,
  synchronized NTP, and independent management DNS A records. IPv6 is disabled
  in the protected Fleet configuration; operating-system IPv6 is not part of
  the qualified service scope.
- Docker 29.1.3 and Compose 2.40.3 prerequisite installation and checks passed
  on control and both PoPs. The first PoP's pre-existing unattended kernel
  update held the package lock; bounded retries preserved that update, and its
  prerequisite installation subsequently passed. The required reboot completed; Docker/Compose
  returned healthy on kernel `5.15.0-191-generic`.
- **Passed:** exact-release Fleet dry-run, protected bundle generation and
  all three bundle validations (Compose, immutable references, Caddy config and
  certificate chain). Control transfer completed; startup stopped before migrations because Docker
  Hub returned 403 for the pinned Valkey digest. Transfer of the exact image
  from the verification workstation is in progress. This is not an HTTPS pass.
- Reproduced and corrected a quick-start ordering omission: fresh metrics-token
  ownership must be set to `root:82`, mode `0640`, before pre-start validation.
  The [quick start](../deployment/production-quick-start.md)
  now records the same restricted ownership that `start.sh` applies. The
  original validation failed; validation after the ownership correction passed.
- Inventory, SSH key/known-hosts, Fleet state, bundles, release evidence and
  command logs remain protected under ignored `.prod`. No private material is
  committed. Existing workspace `.gitignore` changes are preserved.

The initial documentation preparation changed no hosts. Subsequent preparation
installs prerequisites and starts the selected control bundle with its explicit
migration workflow. Existing development PostgreSQL and named volumes are
untouched; no volume deletion or destructive database refresh is permitted.

### Control and DNS runtime checkpoint

- Control startup and explicit application migrations completed. PostgreSQL,
  Valkey, ClickHouse, MMDB, core/web, Grafana, Prometheus, Loki and Vector are
  healthy. Administrator bootstrap through `cdnf:admin:create` and the real
  `POST /api/auth/login` succeeded. Credentials remain in the protected local
  `.prod/staging-admin.json`; the API token is stored separately and must be
  revoked after qualification. No secrets appear in this report.
- The domain-user account is assigned only to the disposable customer zone.
  API login and its single-domain listing passed; administrator health access
  returned 403. The probe token was revoked. Administrator login is `/admin/login`;
  domain-user login is `/app/login`. Credentials are stored separately in local
  `.prod/staging-admin.json` and `.prod/staging-domain-user.json` (mode 0600).
  Browser login remains **not run** until the owner supplies results.
- The owner supplied an HTTPS Docker Hub mirror. It was merged into all three
  daemon configurations, validated and applied by SIGHUP; effective mirror
  configuration was verified. A pinned Alpine pull passed. A prior exact-digest
  Valkey archive transfer also passed destination digest verification; a redundant
  ClickHouse archive transfer was stopped once the mirror pull succeeded.
  Registry identity/digest pins and TLS verification were retained.
- Both PoPs initially failed DNSdist startup because wildcard port 53 conflicted
  with Ubuntu's loopback resolver. Fleet `bind_ipv4` was changed to each assigned
  local public address, bundles rerendered and validated, and previous bundles
  retained before activation. Both DNS roles now start healthy with the original
  PostgreSQL volumes. The host resolvers were preserved.
- Private listeners (8083, 8443, 8444, 9100, 9599 as applicable) are restricted
  through host INPUT and Docker DOCKER-USER rules to their control/edge sources.
  The rules are installed through a Docker pre-start hook for persistence.
  Untrusted external connections were blocked or closed; both queued cluster
  connection tests from the permitted control source passed. Restart persistence
  remains to be exercised with the later continuity check.
- Both DNS clusters were created disabled, asynchronously tested, then enabled.
  Platform identity validation/confirmation and deployment succeeded. The
  disposable customer zone was created pending verification and its initial
  SOA/claim-specific NS records deployed on both PoPs. Parent-delegation verification
  remains pending the owner's exact assigned-nameserver update.
- **Passed:** `tests/e2e/staging_health.py` from the control host: verified public
  control health/readiness and Grafana health, unauthenticated administrator API
  denial, authoritative platform SOA over UDP and TCP on both PoPs, and matching
  serials. This focused probe is read-only and does not establish the remaining
  phase gates. Its report is retained in `.prod/control-vantage-health-report.json`.
- The first workspace DNS probe **failed**: this workspace intercepts port 53
  and returned recursive responses even for the reserved address `192.0.2.1`.
  The strict AA check was retained. The independently executed control-host probe
  returned actual authoritative responses and passed. The failed report is kept
  separately; the workspace is not a qualified direct-DNS vantage point.
- Edge records and a bounded shared pool are created; eight cell slots per edge,
  no per-domain processes. Country/continent metadata uses the deployed MMDB
  result for the PoP addresses. Enrollment/image activation is in progress.
- The owner supplied an HTTP origin on port 8096. It responds with 302 and is
  supported by the API's custom-port validator. The earlier default-port probe
  failed (HTTP unavailable and HTTPS hostname mismatch); origin HTTPS is not
  qualified by the HTTP endpoint. Public edge HTTPS still requires DNS-01 issuance
  and real traffic checks after domain verification.

Focused probe invocation from a non-intercepting host:

```bash
python3 tests/e2e/staging_health.py \
  --control https://control.example.net \
  --grafana https://grafana.example.net \
  --zone example.org --dns-server 192.0.2.20 --dns-server 192.0.2.30 \
  --report /protected/path/staging-health.json
```

Replace the documentation names/addresses with the protected staging inventory.
The runner does not execute browsers, create records, migrate databases or
qualify cache/security/restarts. It records only the checks actually requested.

Phase 1 remains open until all required evidence is recorded.
Phase 2 is the next separate roadmap job only after Phase 1 completes.

### Edge enrollment and operator-path checkpoint

- Both agents enrolled against the published images and send fresh heartbeats.
  Each has eight bounded slots and one cell assigned to `staging-shared` (pool 3).
  Both IPv4 service endpoints report `gateway_state: ready`. Pool creation is
  disabled; assign cells and endpoints before enabling. The quick start's former
  enable-before-assignment ordering was corrected against actual API behavior.
- Docker generated a literal `invalid IP` hosts entry for `host-gateway` on both
  PoPs. A validated host-local Compose override maps that name to the inspected
  edge bridge gateway. Recreating only the agents restored gateway status and
  endpoint acknowledgement without rebuilding images or altering desired state.
  Gateway TCP 9105 is included in persistent private-listener rules; external
  probes were blocked/closed while local agent and control access remained valid.
- The inherited Docker resolver returned SERVFAIL for DNSSEC queries. Explicit
  upstream resolver probes validated DS absence, parent NS and parent addresses.
  A control-host override applies the tested DNS servers to Core, Horizon and
  Scheduler. All three must receive the setting; Core-only changes do not fix
  queued verification. These host overrides must accompany future bundle updates.
- The actual `.ir` parent returned the base platform names for the customer
  domain, while recursive answers exposed the child zone's claim-prefixed names.
  The owner confirmed saving only the base names. Exact claim delegation at the
  registrar is still required; force-verification was not used. Failed operations
  are retained as evidence, not counted as passed activation. After the worker
  resolver correction, verification reached the parent check and returned
  `Observed nameservers do not exactly match the nameservers assigned to this claim.`
- **Passed:** `tests/e2e/staging_observability.py`: authenticated health for
  Prometheus, ClickHouse, control PostgreSQL and Loki datasources; exactly the two
  provisioned dashboard UIDs; both dashboard API documents. Credentials are read
  from a mode-0600 file, never printed. Report:
  `.prod/staging-observability-report.json`. This is API availability evidence,
  not rendered dashboard or telemetry-under-customer-traffic qualification.
- **Passed:** first-PoP Docker restart, retained volume-name inventory, persistent
  private-listener INPUT rule, fresh edge heartbeat and ready endpoint, and the
  control-vantage health/authoritative UDP/TCP DNS probe after recovery. Core and
  agents also recovered after targeted recreation. This does not establish
  customer HTTPS continuity or serving during control outage; those require an
  active domain and traffic first. No PostgreSQL or named volume was removed.
- Spent bootstrap tokens were cleared from both protected host env files after
  enrollment; persisted edge identities remain in their original volumes.
- Browser/API setup documentation now separates Fleet host installation from
  desired-state onboarding, explains administrator/domain-user accounts and exact
  registrar claims, lists API request fields and operation polling, and records
  host-network diagnosis and overrides. Browser checkpoint remains **not run**.

The owner subsequently confirmed saving both full assigned names. A parent
TCP query now returns the new assignment; the verification job reports parent
authority disagreement while propagation continues. A bounded four-parent probe
tracks convergence. The owner selected the supplied HTTP origin with managed
Let’s Encrypt visitor TLS; origin HTTPS is **not exercised**, not a passed check.
The HTTP favicon returned 200, a bounded 49,334-byte image, and public cache
headers; its digest is retained for edge comparison. The bounded
`tests/e2e/staging_traffic.py` probe is prepared: compilation/help, a verified
HTTPS control-health request and rejection of a mismatched TLS hostname passed.
Customer edge traffic with this probe remains **not run** until activation. The initial DNS-only record
is saved but customer records remain withheld while the claim is pending.

Remaining Phase 1 gates: parent propagation/claim verification, customer proxy/
client TLS/cache/purge/security checks, traffic telemetry, serving continuity,
and remaining owner browser results. The owner confirmed administrator overview
login and the domain user’s assigned-domain-only access without admin navigation
on 2026-09-20. These specific browser subchecks passed; other browser checks
remain **not run**.
The next separate roadmap job remains Phase 2; it has not started.

### Remote metrics correction

The deeper target query found **8/17 remote scrapes down** despite healthy
Grafana datasource checks: Prometheus had only internal network attachments.
Fleet now retains its private service networks and adds the existing outbound
`egress` network, without publishing a Prometheus port. A protected override
applied the same correction to the selected already-published staging bundle;
only Prometheus was recreated and its existing volume/image retained.

The actual follow-up query passed **17/17 targets up**. Loki reported recent
production operational events. Customer-request telemetry remains pending until
activation. Regression tests cover both colocated and dedicated monitoring,
retained internal networking and absence of public port publication. Compose
validation passed. The first Fleet suite run had 79 passing cases and one stale
documentation assertion (`all four` after the management table gained two PoPs);
the assertion was updated to check the four control names plus separate PoP
names. The focused corrected tests passed, followed by **80/80 Fleet tests**,
`make config-check`, and `make docs-check` (99 documents, 93 built pages, 3,595
internal links). No images were rebuilt or republished.

### Customer traffic and full-purge protocol correction

The registrar parent now publishes the assigned claim nameservers; normal
verification activated the staging domain. Both PoPs serve the selected HTTP
origin and verified Let's Encrypt visitor HTTPS. Static-resource SHA-256 matches
on both edges. Cache admission produced BYPASS, MISS, then HIT. URL purge succeeded
on both edges. A temporary single-client security deny returned 403 from both
edges while an independent client retained 200; removing the rule restored 200.
The first traffic probe incorrectly required the first post-purge request to be
MISS; it now accepts the documented admission sequence and still requires MISS
and subsequent HIT. HTTPS origin transport was not exercised: the owner selected
an HTTP origin with managed visitor TLS.

Full purge failed on the published agent: an API null `cache_keys` became JSON
null in the cell command, whose strict protocol requires an empty array. The
agent now emits an empty array without weakening cell validation. Go formatting,
vet, all agent tests and build passed with the pinned Go toolchain. The new
`tests/e2e/edge_purge_protocol.py` passed both null and empty-array inputs through
the modified Go agent into the published real OpenResty runtime. The isolated
fixture needed a local Vector hostname for its existing syslog configuration;
the initial fixture failure was retained. No staging image was replaced yet.

Implementation gate: correction present. Documentation gate: results recorded.
Automated gate: focused Go and real-cell protocol checks passed. Deployment gate:
requires a newly signed agent image and successful full purge on both staging
PoPs. Browser gate: only the two owner-confirmed logins passed; remaining checks
are not run. Customer-serving continuity remains outstanding.

### Dashboard telemetry and display timezone

At 16:53 UTC, direct application health checks showed healthy Vector, ClickHouse,
queues, scheduler, DNS, TLS, two fresh edges and all sixteen cells. Four remaining
conditions were failed runtime tasks, failed purge, retained failed operations and
no verified backup. All observed request events were in the still-open 16:00 UTC
hour, explaining the overview's empty completed-hour query. The dashboard had
incorrectly hidden the independently healthy-edge count with traffic KPIs; that
coupling is removed. Health-check time and traffic-bucket time now have distinct
labels. Historical failure evidence is retained.

The owner requested one shared timezone in Platform settings. The **Display →
Display timezone** setting now controls both panels through Filament's shared
formatter and custom analytics views, including chart labels, freshness, domain
history, heartbeat and timeline tooltips. The dashboard has no independent
selector. Stored timestamps, API values and complete-hour query bounds remain UTC.
The additive `2026_09_20_170000_add_display_timezone_setting` migration seeds UTC
and preserves existing preferences on rerun or image rollback. Admin-only writes
use existing validation, audit and idempotency handling without a runtime job.
External Grafana retains its independent timezone configuration.

Implementation and documentation are present. Focused isolated tests cover Tehran
conversion, repeated DST hours, UTC query-bound preservation, invalid settings,
authorization, idempotency, migration preservation and empty-traffic edge health.
The isolated Compose suite passed **32 tests, 242 assertions** across
`OpsDashboardTest`, `SystemSettingsTest` and `AnalyticsApiTest`; Pint passed on the
changed PHP sources. A full settings-form test exposed numeric checkbox values
for HTTP version 2; form dehydration now preserves canonical strings without
relaxing API validation. The initial fixture failures and corrected passing run
are retained in protected local evidence. Documentation validation passed (99
documents, 93 pages, 3,600 internal links). Browser qualification is
**not run** for these changes; exact steps are in the Phase 1 checklist. Signed
core image rollout and explicit migration are still pending.

At 17:06 UTC, the completed 16:00 bucket was fresh and contained 496 requests,
confirming that the initial no-data state was the complete-hour boundary.
OpenAPI generation and freshness checks passed after using the root file writer
for repository-owned documentation.

A separate live aggregate check also found successful upstream HTTP statuses
stored in `origin_error`, inflating error counts, and the runtime always emitted
null origin latency. This is a current telemetry qualification defect, not a
successful telemetry gate; correction and runtime qualification remain open.

### Edge identity in traffic telemetry

The updated runtime produced real origin latency samples from both assigned
cells, with successful requests carrying no origin error. Inspection also found
that access events used local cell names as `edge_id`, which cannot match the
administrator's enrolled-edge UUID filter. The production Vector collector now
receives its host's enrolled `EDGE_ID` as `CDNF_TELEMETRY_EDGE_ID` and attributes
local events to that edge. A collector without that setting preserves incoming
identity, including the control-side relay. Existing historical rows are retained.

The pinned published Vector binary passed the actual HTTP decoder/transform job
both with an enrolled UUID and without an override: each run passed all 11 origin
metric cases, identity assertions and query redaction. All 19 observability
contract tests and production Compose/config checks passed. Implementation and
documentation are present; this final mounted-config correction is **not deployed**
at the owner's release checkpoint. Owner edge-filter browser check: **not run**.

### Active health after recovery

The 19:04 UTC live check confirmed four conditions: one failed full purge, its
two failed edge tasks, seven earlier nameserver-verification failures, and no
verified backup. DNS, TLS, edges and telemetry components were healthy. The
operation health query now excludes a failed nameserver verification only when
a later verification of the same domain finished successfully. History retains
the failed attempts; unrelated failures, pending retries and newer failures stay
active. Dashboard impact and condition age use the same current failure query,
independently of the selected historical traffic range. The backup warning stays
visible until a real backup succeeds and is verified. The owner explicitly
chose to keep this warning visible and configure backups separately.

Implementation and documentation: present. Isolated SQLite dashboard tests passed
**14 tests, 105 assertions**, including recovery, domain isolation, newer failure,
retained history and the independent backup warning. The initial new test fixture
omitted a required display name; correcting the fixture produced the passing run.
Signed core deployment and live PostgreSQL qualification remain pending. Owner
browser status: **not run** for recovery presentation. Release manifest generation
also now derives its migration marker from the actual source migration files;
the previous fixed marker predated the new display timezone migration.

### Origin telemetry correction

The runtime now includes its final upstream response timing in access events.
Vector converts valid bounded measurements to milliseconds, preserves absent
measurements as null, and removes the transport-only timing field before storage.
Legacy upstream status lists no longer count successful responses, redirects or
4xx responses as origin failures; 5xx attempts and explicit failure codes remain.
A recovered retry with an earlier 5xx still records an origin failure. The Vector configuration is a Fleet bind mount and can use the existing verified
binary. This does not change proxy retry or serving behavior and requires no ClickHouse migration.
Previously stored aggregates retain their original values; they are not silently
rewritten, and the cutover must be recorded when images are deployed.

The pinned published Vector binary passed the real HTTP decoder and production
transform test with **11 origin-metric cases**, plus DNS decoding and query-string
redaction. An initial image download timeout and two VRL compile errors were fixed
before the passing run; no invalid transform was deployed. The changed production
OpenResty configuration also passed startup and the real Go-to-cell purge test.
Additional Filament panel/access/workflow tests passed **41 tests, 372 assertions**
for the shared timezone change. Implementation is present; staging deployment and
end-to-end ingestion retest remain pending. Browser qualification remains not run
except the two earlier owner-confirmed login checks.

### Live telemetry configuration and release preparation

The corrected Vector configuration from `d25461cf` was validated inside each
existing verified Vector container, installed with its previous file retained,
and activated by recreating only Vector on all three hosts. All three became
healthy and retained their images and named buffer volumes. Protected local Fleet
bundles now match the deployed configuration. Four fresh customer requests after
the final cutover reached ClickHouse with HTTP 200, empty `origin_error` and null
latency. Both edges still served verified HTTPS with the expected static-resource
hash. Actual latency measurements await the updated runtime image; old aggregate
values were retained.

A protected control PostgreSQL custom-format snapshot was made before the
additive display-setting migration. Its archive catalog was verified without
restoring or changing the live database. The initial catalog check used the
wrong buffered file offset and was corrected by reopening the snapshot. This
snapshot is not the later encrypted off-host backup/restore qualification and
does not clear the missing verified-backup condition.

The first new publication run found a Markdown list numbering/blank-line error
in the pool setup instructions. Commit `80eea902` corrected the sequence and
passed documentation lint and link validation. Its
[new release run](https://github.com/vaheed/CDNFoundry/actions/runs/35525286908)
is the candidate for the required core, agent and runtime fixes. Publication,
signature verification, image deployment and the remaining continuity/browser
gates are pending; an image build alone is not deployment evidence.

## Source and release evidence

The remote base was `602c605b3f50551d18ddab442670d8fbc7e5f545` on `main`.
The accumulated implementation batch contains 38 local commits through
`7cf3a250` before the roadmap/report commit. The full history is retained; remote
`main` is not rewritten. Delivery uses a new `dev` branch.

- [Dev source and commit history](https://github.com/vaheed/CDNFoundry/commits/dev)
- [Dev CI runs](https://github.com/vaheed/CDNFoundry/actions/workflows/ci.yml?query=branch%3Adev)
- [Workflow and publication gates](https://github.com/vaheed/CDNFoundry/blob/dev/.github/workflows/ci.yml)
- [Detailed findings and qualification evidence](security-audit.md)
- Per-file review scope: `docs/operations/security-audit-coverage.json`.

The remote run is the authoritative publication result for its exact SHA. This
report is committed before that run starts; do not treat its existence as proof
that CI or publication passed. The handoff response records the final run URL
and result. A failed, skipped or approval-waiting publication job is not success.

## Delivery gate and next job

**Local release qualification passed:** all **17/17** application and managed
infrastructure images passed the complete release gate, and **3/3** remaining
external production images passed their separate gate. The combined patched
Grafana backend/plugin passed generated production qualification with all four
datasources healthy. Evidence is retained under ignored
`storage/qualification/dependency-remediation`: `release-gate/summary.json`,
`dependency-gate/summary.json`, and `grafana-combined-runtime.log`.

The owner retains the High/Critical gate for dev and main/versioned releases.
Only the two exact, expiring Tempo false-positive classifications and the
single rebuilt ClickHouse plugin trust boundary were explicitly approved; see
[the supply-chain policy](software-supply-chain.md). Complete raw findings remain
available alongside classified reports. Other findings still block publication.

**Remote delivery authority:** the [latest dev Actions run](https://github.com/vaheed/CDNFoundry/actions/workflows/ci.yml?query=branch%3Adev)
for the pushed commit must show every required job and **Publish GHCR images**
successful. The final handoff response records that exact run URL and SHA.
This report does not substitute local qualification for that remote result.
After that gate succeeds, the next job is **Phase 1: staging-install-and-smoke**.
The current job performs no live staging deployment; owner browser checks are
not run. Remote main is unchanged.

### Earlier failed runs

Run [35439995763](https://github.com/vaheed/CDNFoundry/actions/runs/35439995763)
for `1de3c3e817123dc7252bed2e3811216fb1ff6f00` passed all six functional/build
jobs, but failed the infrastructure scan and skipped publication. The dependency
remediation and complete local passes above supersede that dependency checkpoint;
the older run itself remains failed and is not release evidence.

The first run, [35438336177](https://github.com/vaheed/CDNFoundry/actions/runs/35438336177),
qualified source `800ec21cd457a3e2e0d97c6ba2b8cf866fc74115`. Go and bounded DNS scale
passed. The Compose job passed, including builds of all nine production images,
origin/TLS checks and generated production observability qualification. Docs
failed its dependency audit, PHP failed its BIND fixture, and backend E2E failed
its provisioned edge identity. The subsequent commits fix those reproduced
tool/fixture defects; the next exact-SHA run determines whether all remote
functional checks now pass. These corrections do not resolve image findings.

The local September 19 scan of the same 12 infrastructure pins failed as follows.
Counts are package findings, including repeated advisories across packages, not
distinct exploitable defects. “No fix reported” means Trivy supplied no fixed
package version for that selected image; it is not proof that every alternative
image is affected. Exact pins remain in the source and complete JSON/table reports
are retained in the CI `production-dependency-scans` artifact and local ignored
`storage/qualification/dev-dependencies` directory.

| Selected image | High/Critical findings | No fix reported |
| --- | ---: | ---: |
| Alpine 3.22 | 2 | 0 |
| Caddy 2.11.4-alpine | 40 | 0 |
| ClickHouse 26.3.12.3-alpine | 2 | 0 |
| PostgreSQL 18.4-alpine | 32 | 0 |
| DNSdist 2.1.0 | 142 | 67 |
| PowerDNS Authoritative 5.1.3 | 226 | 150 |
| Alertmanager 0.32.1 | 74 | 0 |
| Node exporter 1.10.2 | 40 | 0 |
| Prometheus 3.12.0 | 52 | 0 |
| Vector 0.55.0-alpine | 17 | 0 |
| Vector 0.55.0-debian | 92 | 45 |
| Valkey 9.1.0-alpine | 9 | 0 |

The refreshed Caddy 2.11.4-alpine candidate at
`sha256:de23def33b17fb5d1290b0f6c2add1d70780e52341896c00a4c8a2a2fe9d355e`
still had 17 High/Critical findings in embedded Go dependencies/toolchain after
its OS fixes. It was scanned but not adopted. A same-tag refresh alone is
insufficient. The remote application-image scan/sign/publication job has not run;
successful builds do not establish a vulnerability pass.

Next bounded job: **`dependency-remediation`**, now a Phase 0 prerequisite in the
roadmap. Select and qualify supported replacements, rescan exact infrastructure
and application images, then resume delivery and verify complete release publication.
Live staging and manual browser qualification remain separate and unexecuted.

Local remediation progress: Alpine 3.22.6 and Valkey 9.1.2 passed the strict
image gate. The managed Caddy 2.11.4 build also passed, including its detected
Go binary, real HTTPS proxy/allowlist checks, and Fleet/Compose contracts. These
together with ClickHouse 26.8.7.19, PostgreSQL 18.6, both Vector collectors, and
the three Prometheus monitoring images and both DNS services resolve all 12
original dependency entries locally; remote publication
remains pending. No vulnerability exception was added.

## Implemented and committed work

| Area | Completed behavior and evidence | Still required |
| --- | --- | --- |
| Domains and access | Fresh parent-delegation claims, public-suffix/namespace guards, creation-path parity, queued authorization and transactional idempotency; application, BIND and PostgreSQL evidence in the audit | Complete identity/lifecycle inventory and public delegation qualification |
| Edge trust | Client-only identities and exact enrolled-certificate authentication, scoped reports/artifacts, monotonic revisions and atomic task receipts | Complete enrollment, trust rotation, replay and recovery review |
| Origins and runtime | Parsed IPv4/IPv6 destination rejection, whole DNS-answer validation, origin probe authorization, retry/accounting bounds and reservation cleanup | Full proxy/framing/cache review and production load/outage matrix |
| TLS | Uploaded chain/key/name/strength checks, canonical public chains, locked selection/activation, fair bounded maintenance, cleanup lock order, late-worker terminal-state protection and atomic obsolescence receipts | Nonterminal transitions, current lifecycle/name checks, CSR/key custody across lost CA responses, renewal/retry recovery |
| Fleet | Atomic bundle-generation activation, protected PKI and PowerDNS rotation, literal env preservation/redaction, typed input, actual enrollment activation, explicit-null address removal and repeatable setup contact handling | Supported clean-host install, all topology variants, upgrade and restore |
| Delivery and tests | Pinned build inputs, complete scan evidence, verifiable qualification input, Fleet CI inclusion, Go failure propagation and isolated Laravel/OpenAPI checks | Current remote gates and exact-release evidence; clean-host verifier execution |
| Cleanup/docs | Dead build arguments removed and defective/superseded implementations replaced; current runbooks and cleanup ledger retained | Complete remaining inventory and prove non-use before further deletion |

Findings AUD-001 through AUD-055 have individual evidence and status in the audit
register. Some are broader ongoing qualifications, including dependency/image
risk; this table must not be read as “all findings resolved.” Historical fixes
and historical scan failures remain visible rather than being erased by this handoff.

## Checks actually executed

| Check | Result and scope |
| --- | --- |
| Isolated Laravel application suite, September 19 | **Passed:** 330 tests / 12,554 assertions; 82.901 seconds total; effective testing/SQLite in memory |
| Fleet suite for unchanged Fleet implementation, September 12 | **Passed:** 78 tests, 77.191 seconds; source diffs checked against this handoff |
| Go formatting/vet/tests/build, September 19 | **Passed:** edge-agent and edge-gateway; 93.687 seconds |
| PHP formatting, September 19 | **Passed:** Pint |
| Compose, OpenAPI and supply-chain policy, September 19 | **Passed:** 57.059 seconds; includes seven policy fixtures and eight qualification-tool regressions |
| Documentation, September 19 | **Passed:** source links, lint and full build; 93 pages / 3,545 internal links; 55.544 seconds |
| PostgreSQL/BIND/origin/TLS/Fleet runtime regressions | Prior executed results are in the audit; exact dev CI reruns its configured gates. No blanket claim that every runtime suite ran locally today |
| GitHub Actions and GHCR publication | Exact-SHA remote result required; use the linked run and final handoff result |
| Owner browser checklist | **Not run**; retained for Phase 1 and relevant later jobs |
| Fresh staging deployment and full production qualification | **Not run** in this delivery job |

Local command logs, exit codes, timings and source identities are under ignored
`storage/qualification/security-audit/dev-handoff-*`. CI retains release and
image scan artifacts. A dependency scanner pass is not an installer/load pass.

Local environment limitations: the full control-plane rerun encountered invalid
persisted `edge_runtime` settings, which were preserved for migration/compatibility
review. The clean remote control-plane fixture subsequently passed. The host
also exhausted disk space with unbounded Docker logs (AUD-055); existing logs
and database volumes were preserved. The container-based mTLS signer passed
locally after correction. The next run must still qualify the entire backend
sequence and the unprivileged BIND fixture together.

## Retrieve the complete app for staging

A successful `publish-images` job publishes these GHCR repositories:

| Component | Repository |
| --- | --- |
| Core | `ghcr.io/vaheed/cdnfoundry-core` |
| Web ingress | `ghcr.io/vaheed/cdnfoundry-web` |
| Edge control ingress | `ghcr.io/vaheed/cdnfoundry-edge-control` |
| OpenResty runtime | `ghcr.io/vaheed/cdnfoundry-edge-runtime` |
| Edge agent | `ghcr.io/vaheed/cdnfoundry-edge-agent` |
| Edge gateway | `ghcr.io/vaheed/cdnfoundry-edge-gateway` |
| MMDB updater | `ghcr.io/vaheed/cdnfoundry-mmdb-updater` |
| Grafana | `ghcr.io/vaheed/cdnfoundry-grafana` |
| Loki | `ghcr.io/vaheed/cdnfoundry-loki` |
| postgres | `ghcr.io/vaheed/cdnfoundry-postgres` |
| vector | `ghcr.io/vaheed/cdnfoundry-vector` |
| node-exporter | `ghcr.io/vaheed/cdnfoundry-node-exporter` |
| alertmanager | `ghcr.io/vaheed/cdnfoundry-alertmanager` |
| prometheus | `ghcr.io/vaheed/cdnfoundry-prometheus` |
| pdns | `ghcr.io/vaheed/cdnfoundry-pdns` |
| dnsdist | `ghcr.io/vaheed/cdnfoundry-dnsdist` |
| Caddy ingress | `ghcr.io/vaheed/cdnfoundry-caddy` |

Each has the full source-SHA tag. `dev` and `dev-latest` are convenience aliases;
use the immutable digest references in the signed manifest for deployment.
Download `release-evidence-<full-source-sha>` from that successful run. It includes
`release-manifest.json`, its Sigstore bundle and component scan/provenance/SBOM
evidence. Follow [release verification](software-supply-chain.md#verify-a-release)
to verify identity and project every release digest into Fleet configuration, then
follow the [starter quick start](../deployment/production-quick-start.md).
PostgreSQL, DNS, queue and other infrastructure images come from the pinned
Compose topology; the images alone are not a running installation.

## Compatibility, operations and rollback

This batch includes the domain-delegation claim migration
`2026_09_05_160000_add_domain_delegation_claims`; apply supported application and
PowerDNS migrations explicitly as documented, without refreshing persistent data.
The detailed audit and upgrade runbooks record any additional ordering, including
mTLS ingress-before-core rollout and canary identity rotation. The last TLS and
Fleet fixes introduce no new migration or key rewrite.

Back up PostgreSQL, application/signing/encryption keys, Fleet state/private PKI
and externally retained TLS material before deployment. Preserve assignments and
existing claims. Retain prior immutable images and bundles; use the documented
canary/rollback procedure. Do not roll back applied database history or delete
named volumes to recover an image failure. No persistent data was reset for this
handoff, and no traffic-bearing host was changed.

## Remaining work and decision

The last audit coverage checkpoint records **4 reviewed, 103 partial and 656
pending files**. Added handoff/archive documents are tracked separately in the
updated inventory. These numbers are review accounting, not a completion estimate.
The [roadmap](../roadmap.md) assigns all remaining review/qualification areas to
named jobs, including the three old hardening workstreams. Do not mark a later
phase complete merely because its implementation or tests exist.

Decision: **development release candidate for an empty staging environment only
after the exact commit's CI and publication gates pass; not yet qualified for
customer production traffic.** The immediate next job is `dependency-remediation`;
Phase 1, `staging-install-and-smoke`, follows successful publication with host
access and topology supplied separately.
