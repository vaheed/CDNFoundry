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

## Delivery blocker and next job

Run [35439995763](https://github.com/vaheed/CDNFoundry/actions/runs/35439995763)
finished for `1de3c3e817123dc7252bed2e3811216fb1ff6f00`: **all six functional/build
jobs passed**, including the full backend sequence and all nine image builds.
The infrastructure vulnerability scan failed and image publication was skipped.
The owner requires a fully green pipeline and successful publication; dependency
remediation is now active, not deferred completion. No successful release is
claimed for that run.

**Publication is blocked.** The owner explicitly chose on September 19 to retain
the High/Critical vulnerability gate for `dev`; main/versioned releases remain
equally strict. No waiver, scanner exclusion or warning-only path was added.

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
insufficient. The full application-image scan/sign/publication job has not run;
successful builds do not establish a vulnerability pass.

Next bounded job: **`dependency-remediation`**, now a Phase 0 prerequisite in the
roadmap. Select and qualify supported replacements, rescan exact infrastructure
and application images, then resume delivery and verify complete release publication.
Live staging and manual browser qualification remain separate and unexecuted.

Local remediation progress: Alpine 3.22.6 and Valkey 9.1.2 passed the strict
image gate. The managed Caddy 2.11.4 build also passed, including its detected
Go binary, real HTTPS proxy/allowlist checks, and Fleet/Compose contracts. These
resolve 3 of the original 12 dependency entries locally; remote publication
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
