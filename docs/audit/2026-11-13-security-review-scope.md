---
title: Independent security review scope
description: What the external review in roadmap item 10.1 covers, where each area lives in the code, the tests that already exercise it, and the questions the reviewer answers.
---

# Independent security review scope, 2026-11-13

Roadmap item 10.1 asks for an **external** review of seven areas, with its scope, findings, and fixes published. This page
is the package the reviewer starts from. It is written by the project, so it states what the code claims and which tests
exercise each claim; the reviewer decides whether the claims hold. Nothing on this page is a finding.

**Status:** not started. No reviewer has been engaged. Phase 10 stays open until the review is published.

## How to set up the review environment

- The repository at the release under review (tag and commit recorded in the report).
- A development stack: `make dev-up`, with the seeded browser data from `tests/browser/seed.php` for the panels.
- For runtime areas, the Python end-to-end tests under `tests/e2e`; each file states what it starts.
- No production data, no customer domains, and no real credentials are used or needed.

## Areas

### 1. Artifact signing

What it claims: every edge configuration artifact and manifest is signed by the control plane; the edge agent refuses an
artifact whose signature, checksum, or signing key it cannot verify, and keeps the previous valid generation.

| Where | What |
| --- | --- |
| `core/app/Support/ArtifactSigner.php` | Signing of manifests and artifacts |
| `core/app/Http/Controllers/EdgeAgentController.php` (`manifest`, `artifact`) | Delivery to agents |
| `edge-agent/signing_keys.go`, `edge-agent/artifact_apply.go` | Verification and activation |
| `docs/operations/signing-key-rotation.md` | Key rotation procedure |

Tests: `edge-agent/signing_keys_test.go`, `edge-agent/artifact_apply_test.go`, `EdgeProxyTest`, `EdgeRevisionHistoryTest`.

Questions: can an agent be made to activate an unsigned, replayed, or downgraded artifact? Does key rotation leave a window
where an old key is accepted longer than documented?

### 2. Origin validation

What it claims: a proxied hostname has one origin; loopback, link-local, metadata, multicast, private platform, and
edge-service destinations are refused when saved and again after DNS resolution, right before the connection.

| Where | What |
| --- | --- |
| `core/app/Support/OriginData.php`, `core/app/Support/NetworkAddress.php` | Validation at save |
| `EdgeAgentController::originPolicy`, `docker/openresty/runtime.lua` | Policy delivered to cells and checked at connect |

Tests: `OriginDestinationSafetyTest`, `tests/e2e/origin_destinations.py`.

Questions: can DNS rebinding, IPv4-mapped IPv6, decimal or octal address forms, or a CNAME chain reach a refused range?

### 3. Cache isolation

What it claims: one deterministic cache key per domain, host, and URL policy; a purge or a full purge epoch of one domain
never affects another; private responses are not stored.

| Where | What |
| --- | --- |
| `core/app/Support/CacheKey.php`, `docker/openresty/cache_key.lua` | The one key, built the same way on both sides |
| `docker/nginx/proxy-cache.conf` | Admission and storage |

Tests: `CacheApiTest`, `tests/e2e/openresty_runtime.py`, `tests/e2e/edge_purge_protocol.py`, `tests/contract` cache key tests.

Questions: can one tenant read, poison, or purge another tenant's objects through host, SNI, or header variation?

### 4. Edge enrollment

What it claims: an edge enrolls once with a single-use bootstrap token, then holds only short-lived client certificates;
edge-control accepts only mutual TLS from enrolled identities.

| Where | What |
| --- | --- |
| `EdgeAgentController::register` | Enrollment |
| `docker/nginx/edge-control.conf` | The mTLS listener |

Tests: `EdgeEnrollmentTest`, `tests/e2e/edge_control_mtls.py`.

Questions: can a bootstrap token be reused or brute forced? Can an enrolled edge act as another edge?

### 5. Identity renewal and revocation

What it claims: identities renew before expiry without a gap; an administrator can rotate or revoke one edge, after which
its old certificate is refused.

| Where | What |
| --- | --- |
| `EdgeAgentController::renewIdentity`, `Admin/EdgeController::rotate` | Renewal and rotation |
| `edge-agent/identity_renewal.go` | Renewal on the agent |

Tests: `EdgeIdentityRenewalTest`, `edge-agent/identity_renewal_test.go`, `tests/e2e/edge_control_mtls.py`.

Questions: how long does a revoked identity keep working? Is the window what the documentation says?

### 6. Deployment integrity

What it claims: Fleet deploys only digest-pinned release manifests it builds and signs with its own vault key (or that an
operator signs with a pinned key), and never changes an image or config on a host outside a release.

| Where | What |
| --- | --- |
| `scripts/cdnfoundry_fleet/manifest.py`, `release.py`, `vault.py` | Manifest building, signing, and the key vault |
| `docs/operations/software-supply-chain.md` | Image provenance and scanning |

Tests: `tests/fleet/test_manifest.py`, `tests/fleet/test_release.py`.

Questions: can a host be made to run an image that is not in the signed manifest? Is the vault key protected at rest?

### 7. Log push and notifier egress

What it claims: customer log push destinations and generic notification webhooks pass the same destination checks as an
origin, again at connection time; Slack and Discord URLs must be HTTPS on the provider's own host; only the domain's own rows
leave, with a fixed field allowlist.

| Where | What |
| --- | --- |
| `core/app/Support/LogPush/DestinationData.php`, `LogPushSender.php`, `LogDatasets.php` | Log push checks and allowlist |
| `core/app/Support/Notifications/ChannelData.php`, `OutboundDestination.php` | Notifier checks |

Tests: `LogPushTest`, `NotificationChannelTest`, `tests/e2e/log_push.py`,
`tests/e2e/notifications.py`.

Questions: can a tenant use log push or a webhook to reach a private address, the control plane, or another tenant's data?

## Out of scope

Volumetric DDoS absorption, BGP, the operating systems of the hosts, and third-party services the operator chooses (DNS
registrars, S3 providers). The roadmap does not claim them.

## What the report must contain

The commit reviewed, the method, every finding with severity and a reproduction, the fix commit for each fixed finding, and
the findings accepted as known limits. The project publishes it under `docs/audit/` unchanged.
