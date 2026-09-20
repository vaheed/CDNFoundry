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
| Management DNS | Owner supplied suffix; all six required A records resolve to their intended hosts; no AAAA records; public HTTPS pending startup |
| Public DNS | Owner supplied platform zone; nameserver glue, disposable customer zone and registrar workflow remain pending |
| Origin | Owned HTTP and verified HTTPS endpoint, Host/SNI, disposable hostname and cacheable test resource |
| Address families | IPv4 only per owner; Fleet IPv6 disabled; public service/firewall reachability still requires qualification |
| Protected configuration | Protected draft and release evidence under `.prod`; ACME contact supplied; administrator credential delivery remains pending; use the existing zero-credential GeoIP provider |

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

Row 1 release verification and local bundle generation are complete; control
startup is in progress. Other live smoke results remain **not run**. Run non-browser
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

### Completion gate

| Gate | Current result |
| --- | --- |
| Implementation/installation | Verified bundles generated; control startup in progress; full selected-host installation gate remains open |
| Documentation | Preparation and exact owner browser steps written; deployment observations pending |
| Automated/runtime qualification | Local documentation/configuration checks **passed**; live staging matrix **not run** |
| Owner-run browser qualification | **Not run**; owner executes [Phase 1 smoke](https://github.com/vaheed/CDNFoundry/blob/dev/docs/manual-browser-qualification.md#phase-1--empty-staging-smoke) and supplies results |

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
  deployment configuration and DNS inputs remain incomplete.

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
untouched; no volume deletion or destructive database refresh is permitted. Phase 1 remains open until all required evidence is recorded.
Phase 2 is the next separate roadmap job only after Phase 1 completes.

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
