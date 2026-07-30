---
title: CDNFoundry production-hardening roadmap
description: Generation-atomic edge activation, real OWASP CRS enforcement, and production software supply-chain security.
---

# CDNFoundry production-hardening roadmap

Your goal is to complete three production-hardening workstreams:

1. Generation-atomic and power-loss-durable edge runtime activation.
2. Correct, testable WAF enforcement based on ModSecurity and OWASP CRS.
3. Production-grade software supply-chain security.

## Accepted observability extension

The production Grafana observability work is explicitly admitted alongside
these workstreams. It authorizes exactly two dashboards—**CDNFoundry — System
Command Center** and **CDNFoundry — Domain Command Center**—as the sole narrow
exception to the repository's multiple-dashboard prohibition. No third
dashboard, second application backend, request-path dependency, or telemetry
path through Laravel is accepted. PostgreSQL remains authoritative desired
state; Prometheus and ClickHouse remain read-only derived observability data.

Do not only analyze the repository or provide recommendations. Inspect the complete relevant codebase, implement the changes, add automated tests, update documentation, and leave the repository in a passing state.

## General engineering requirements

Follow the existing architecture and conventions of CDNFoundry.

Preserve these invariants:

* Customer HTTP traffic must never pass through Laravel.
* DNS traffic must never pass through Laravel.
* Laravel remains the control plane.
* OpenResty and the edge gateway remain the data plane.
* Do not create containers, processes, Nginx server blocks, caches, or workers per customer domain.
* Edge nodes must continue serving the last valid configuration when the control plane is unavailable.
* Invalid, incomplete, corrupt, or partially downloaded configurations must never replace the last valid state.
* Do not weaken existing SSRF, proxy-loop, private-address, metadata-address, signature, revision, or authorization protections.
* Do not introduce unbounded disk, memory, queue, log, cache, or retry behavior.
* Avoid unrelated refactors.
* Maintain backward compatibility unless a migration is clearly required.
* Any compatibility change must be documented and tested.

Before changing code:

1. Read the repository-level instructions and architecture documents.
2. Inspect all relevant Go, Lua, Nginx/OpenResty, Docker, Compose, Laravel, CI, test, deployment, and roadmap files.
3. Map the complete runtime configuration lifecycle:

   * control-plane reconciliation
   * artifact generation and signing
   * agent download
   * validation
   * compilation
   * staging
   * activation
   * acknowledgement
   * rollback
   * process restart
   * host restart
4. Map the complete WAF request path:

   * per-domain configuration
   * ModSecurity configuration
   * OWASP CRS loading
   * Lua access phases
   * blocking decisions
   * logging
   * metrics
   * API and UI terminology
5. Map the image build and release process:

   * base images
   * downloaded archives
   * Git dependencies
   * package managers
   * image publishing
   * release tagging
   * security scanning
   * provenance
   * SBOMs

Create a short implementation plan in the pull-request summary or final report, but proceed with implementation without waiting for approval.

## Workstream 1: Generation-atomic, durable runtime activation

### Runtime activation problem

The runtime configuration consists of multiple related files, including gateway configuration, cell configuration, pool configuration, and active-state metadata.

Atomic replacement of individual files is not sufficient. A process crash, container restart, host reboot, disk-full condition, or power loss during activation must not leave the gateway, cells, and agent using different generations.

The complete runtime generation must be activated as one logical transaction.

### Required design

Implement a generation-based runtime layout similar to:

```text
runtime/
  generations/
    <sequence-or-generation-id>/
      manifest.json
      gateway.json
      active.json
      cells/
        ...
      pools/
        ...
  current -> generations/<active-generation>
  previous -> generations/<previous-generation>
```

The exact layout may differ if the repository architecture requires it, but it must provide equivalent guarantees.

A runtime generation must be immutable after publication.

The agent must:

1. Build the complete candidate generation in a temporary directory located on the same filesystem as the final generation directory.
2. Validate every generated file before activation.
3. Write a generation manifest containing at least:

   * generation or sequence ID
   * configuration revision
   * creation timestamp
   * expected file list
   * file sizes
   * SHA-256 digest for every generated file
   * aggregate or manifest digest
4. Reject missing, unexpected, invalid, or digest-mismatched files.
5. Flush all generated files to durable storage.
6. Flush relevant directories to durable storage.
7. Rename the temporary generation directory into its final immutable location.
8. Flush the parent directory after the rename.
9. Atomically replace the `current` generation pointer.
10. Flush the directory containing the pointer.
11. Preserve the prior valid generation as `previous`.
12. Acknowledge activation only after the complete generation has been durably committed and runtime readers can observe it.
13. Never acknowledge a generation that is only partially written.
14. Never modify an already published generation in place.
15. Remove abandoned temporary generations safely.
16. Retain a bounded number of prior generations.
17. Never delete the active or rollback generation.
18. Recover automatically after an agent restart or host reboot.

Use safe filesystem operations appropriate to Linux. Handle errors from writes, closes, sync operations, renames, link changes, directory reads, and cleanup.

Do not silently ignore `fsync`, `fdatasync`, `close`, `rename`, or directory-sync failures.

### Reader behavior

Update the edge gateway and OpenResty runtime readers so they consume a complete generation through the active generation pointer.

Readers must never construct paths from untrusted request input.

Readers must:

* Detect a missing or invalid active pointer.
* Detect a missing or invalid generation manifest.
* Reject incomplete generations.
* Continue using the previously loaded valid in-memory configuration when a new generation cannot be loaded.
* Avoid observing a mixture of files from different generations.
* Expose the currently loaded generation ID and revision in internal health or status output.
* Avoid per-request filesystem parsing when the existing architecture already caches runtime configuration safely.

If symlinks are unsuitable for any container or deployment environment, implement an equivalent atomic pointer file or directory-switch mechanism and explain the decision.

### Rollback

Rollback must switch the active generation atomically.

It must not reconstruct old runtime files one by one.

Rollback must:

* verify the target generation manifest and digests
* atomically update the active pointer
* preserve the generation being replaced
* produce an acknowledgement or operation result
* expose the resulting active generation
* remain idempotent

### Failure injection tests

Add automated tests for at least these cases:

1. Failure while writing one generated file.
2. Failure before the generation directory rename.
3. Failure after generation publication but before active-pointer replacement.
4. Failure during active-pointer replacement.
5. Failure after pointer replacement but before acknowledgement.
6. Agent process restart at every significant activation stage.
7. Host-style restart after successful activation.
8. Corrupt manifest.
9. Missing runtime file.
10. Digest mismatch.
11. Disk-full or simulated `ENOSPC`.
12. Permission failure.
13. Duplicate activation of the same revision.
14. Activation of an older revision.
15. Rollback after a failed activation.
16. Cleanup while active and previous generations exist.
17. Concurrent reconciliation attempts.
18. Gateway and cells verifying that they loaded the same generation.
19. Last-valid-state behavior when the candidate is invalid.
20. Power-loss-oriented recovery from every durable boundary that can reasonably be simulated in tests.

Tests must prove that a partially written candidate never becomes active.

Tests must prove that after restart, the system selects either the previous complete generation or the new complete generation, never a mixture.

### Observability

Expose bounded metrics or structured logs for:

* activation started
* activation succeeded
* activation failed
* rollback started
* rollback succeeded
* rollback failed
* candidate validation failed
* generation recovery performed
* active generation ID
* active revision
* previous generation ID
* runtime reader generation mismatch
* abandoned candidate cleanup

Do not include secrets or complete signed artifacts in logs.

### Documentation

Document:

* generation directory format
* activation state machine
* durability guarantees
* acknowledgement semantics
* rollback behavior
* crash recovery
* retention behavior
* operator recovery procedure
* known filesystem assumptions

Include a clear statement of what is guaranteed for process crashes and host power loss.

## Workstream 2: Real ModSecurity and OWASP CRS enforcement

### Managed WAF problem

The repository includes ModSecurity and OWASP CRS, but the effective blocking path must be unambiguous.

A product setting described as WAF blocking must not merely run CRS in detection-only mode while relying only on a small unrelated Lua signature set.

Implement a truthful and testable WAF model.

### Target behavior

Use ModSecurity and OWASP CRS as the authoritative managed-WAF inspection engine.

Support explicit per-hostname modes:

* `off`
* `monitor`
* `block`

The names may follow existing public API conventions, but their meaning must be precise.

#### Off

* Managed CRS inspection is disabled for that hostname.
* Essential request-safety checks unrelated to the managed WAF may remain enabled.
* Document which protections remain active.

#### Monitor

* OWASP CRS evaluates the request and response as configured.
* Requests are not blocked solely because of CRS.
* The matched rule IDs, anomaly information, action recommendation, and relevant metadata are exported to bounded logs or telemetry.
* Sensitive request bodies, credentials, cookies, authorization values, and secrets must not be logged.

#### Block

* OWASP CRS evaluates the request.
* Requests exceeding the configured blocking threshold are blocked.
* The response uses a controlled CDNFoundry error response.
* The result is observable through metrics and sanitized security events.
* The exact rule ID or internal details should not be exposed to the visitor unless explicitly intended.

### Integration requirements

Inspect the phases and APIs available in the installed ModSecurity/OpenResty integration.

Implement a reliable bridge between the ModSecurity transaction result and CDNFoundry’s per-hostname runtime configuration.

Do not claim CRS enforcement based only on whether the module is loaded.

The implementation must prove that:

* OWASP CRS rules are loaded.
* The request was evaluated.
* The per-hostname mode was applied.
* In monitor mode, a CRS-detected attack was allowed and recorded.
* In block mode, the same attack was denied.
* In off mode, managed CRS enforcement was not applied.
* Different hostnames on the same OpenResty cell can use different modes without separate server blocks or workers.
* One customer cannot change or bypass another customer’s WAF mode.
* Invalid WAF configuration preserves last-valid runtime state.

Prefer a design that can safely use CRS anomaly scoring and standard disruptive actions.

If a global `SecRuleEngine DetectionOnly` setting prevents correct per-hostname enforcement, redesign the integration appropriately. Do not work around it with misleading terminology.

Possible approaches include transaction-level engine control, request variables consumed by ModSecurity rules, separate internal execution paths, or another mechanism supported by the actual module. Select the safest approach after inspecting the installed integration.

Do not create one OpenResty instance or server configuration per domain.

### Custom Lua rules

Audit the existing Lua-based WAF checks.

Classify each rule as one of:

* essential protocol or parser safety
* platform abuse protection
* managed WAF functionality
* legacy duplicate of CRS
* obsolete or misleading

Retain independent safety checks when justified.

Remove or reclassify duplicate managed-WAF rules when CRS becomes authoritative.

Do not silently maintain two different engines that can produce contradictory public behavior.

If custom platform rules remain, give them distinct terminology, metrics, configuration, and documentation.

### Configuration and API contract

Update all relevant:

* models
* validation
* reconciliation
* signed artifacts
* edge-agent compilation
* runtime schemas
* OpenAPI documentation
* API examples
* administrative UI labels
* operator documentation
* user-facing documentation

The API must reject unknown WAF modes and invalid thresholds.

Define safe defaults.

Existing installations must receive a documented migration path.

Do not automatically turn on blocking for customers who previously had detection-only behavior unless the compatibility policy explicitly requires it and is clearly documented.

### Security event schema

Create a bounded, sanitized event structure containing appropriate fields such as:

* timestamp
* edge ID
* cell ID
* hostname identifier
* request ID
* WAF mode
* action: allowed, monitored, or blocked
* CRS rule IDs
* paranoia level
* inbound anomaly score
* outbound anomaly score, if enabled
* threshold
* HTTP method
* normalized route or safely bounded URI information
* source IP according to the trusted-proxy model
* response status
* generation ID and configuration revision

Do not log:

* raw Authorization headers
* session cookies
* API tokens
* passwords
* complete request bodies
* unrestricted query strings
* unbounded rule messages
* secrets
* customer TLS key material

Apply size limits and cardinality controls.

### WAF tests

Add real-runtime tests using OpenResty, ModSecurity, and the repository’s actual CRS installation.

Test at least:

1. Benign GET.
2. Benign POST JSON.
3. SQL injection payload detected in monitor mode but allowed.
4. The same SQL injection payload blocked in block mode.
5. XSS payload.
6. Path traversal payload.
7. Command injection payload.
8. Malformed request body.
9. Oversized request body.
10. Multipart upload boundary handling.
11. False-positive regression fixtures for common application requests.
12. Two hostnames with different WAF modes on the same cell.
13. Runtime mode change without Nginx reload.
14. Invalid runtime WAF configuration preserving the previous mode.
15. Rule event telemetry.
16. Sensitive-value redaction.
17. Request ID correlation.
18. Threshold behavior.
19. Paranoia-level behavior, if exposed.
20. CRS startup failure.
21. Missing CRS rules.
22. ModSecurity module unavailable.
23. Safe behavior during control-plane outage.

Do not implement tests that only mock ModSecurity. At least the E2E tests must execute the real module and real CRS rules.

### Failure behavior

Choose and document fail-open or fail-closed behavior for each type of failure.

For example:

* invalid customer configuration
* missing runtime configuration
* CRS initialization failure
* ModSecurity transaction failure
* telemetry destination failure
* analytics outage

The choice must avoid converting observability outages into global traffic outages unless this is an explicit and tested policy.

### Documentation and product truthfulness

Update the documentation so it accurately explains:

* which engine performs managed WAF inspection
* the meaning of off, monitor, and block
* anomaly thresholds
* rule-set version
* update procedure
* expected false-positive handling
* exclusions
* logging and privacy
* failure behavior
* differences between managed CRS and platform safety checks

Remove claims that are stronger than the implemented behavior.

## Workstream 3: Software supply-chain hardening

### Goal

Make image builds and releases reproducible, verifiable, scanned, and traceable to a source commit.

The project should provide evidence for:

* exactly which source commit produced an image
* which dependencies and base images were used
* whether downloaded source archives were verified
* what packages are inside each image
* whether known vulnerabilities were detected
* who or what signed the image
* whether the image was built by the official CI workflow
* which exact image digests compose a release

### Base images

Pin production base images by immutable digest.

Use readable references such as:

```dockerfile
FROM openresty/openresty:<version>@sha256:<digest>
```

Do this for all production images where practical.

Document the update process.

Add automation that detects when a pinned digest is outdated while still requiring reviewed updates.

Do not use unpinned `latest` tags.

### Downloaded archives

For every source archive downloaded during a build:

* pin an explicit version
* use HTTPS
* verify a committed expected SHA-256 or stronger digest
* fail the build on mismatch
* avoid piping an unchecked network response directly into an interpreter or shell
* document the source and update process

For Git dependencies built from source:

* pin a full commit SHA rather than a branch or floating tag
* verify submodule behavior if applicable
* avoid mutable branch references
* record the commit in image labels or build metadata

Where upstream signed releases are available, verify upstream signatures in addition to checksums when practical.

### Dependency locking

Review every ecosystem used by the repository:

* Go modules
* Composer
* npm or other JavaScript package managers, if present
* operating-system packages
* Lua dependencies
* downloaded C/C++ modules
* Git-based dependencies

Require lockfiles or equivalent immutable version selection.

CI must fail when lockfiles are inconsistent with manifests.

Do not update dependencies as an unrelated bulk upgrade unless required for the hardening work.

### Image metadata

Add OCI labels to production images, including:

* source repository
* source commit
* version or release identifier
* build timestamp
* image description
* licenses where appropriate
* revision
* documentation URL where appropriate

Do not depend on mutable tags for internal release identity.

### SBOMs

Generate an SBOM for every published production image.

Use a standard format such as SPDX JSON or CycloneDX JSON.

Attach the SBOM to the image or publish it as an OCI artifact associated with the image digest.

Include:

* OS packages
* application dependencies
* compiled modules
* relevant Go, Composer, Lua, and native dependencies

Store CI artifacts where useful, but the canonical SBOM must remain discoverable from the published image digest.

### Vulnerability scanning

Scan every production image by digest before release publication or promotion.

Use a maintained scanner such as Trivy or Grype.

The policy must be explicit.

At minimum:

* report all severities
* fail on unfixed or fixable critical vulnerabilities according to a documented policy
* define how high-severity vulnerabilities are handled
* support reviewed, expiring exceptions
* never use permanent broad ignore rules
* include scanner database freshness information
* publish a human-readable and machine-readable report

Also retain existing dependency audits.

Avoid duplicate scans that add cost without additional evidence, but do not omit final image scanning.

### Image signing

Sign each published production image by digest.

Prefer keyless Sigstore Cosign signing using the official CI identity and OIDC.

Do not sign only mutable tags.

Verification must be possible using:

* expected repository identity
* expected workflow identity
* expected issuer
* image digest

Add documented verification commands for operators.

The signing job must run only after required tests and security checks succeed.

Do not expose private signing keys in repository secrets when keyless signing is available.

### Build provenance

Generate verifiable build provenance for each production image.

Use SLSA-compatible provenance or GitHub Actions artifact attestations.

The provenance should bind:

* source repository
* source commit
* workflow
* builder identity
* build parameters
* image digest

Publish the attestation with the image.

Add documented verification commands.

### Release manifest

Produce one machine-readable release manifest for each release.

The release manifest must contain:

* release version
* source commit
* creation timestamp
* workflow run identifier
* every production image name
* immutable image digest
* SBOM reference
* signature reference or verification identity
* provenance reference
* vulnerability-scan result summary
* relevant schema or protocol versions
* database migration identifier, if applicable

Sign or attest the release manifest.

The deployment documentation must instruct operators to deploy using image digests from this manifest rather than mutable tags.

### CI workflow security

Harden GitHub Actions workflows:

* Pin third-party actions to full commit SHAs.
* Add comments showing the corresponding action release version.
* Use minimal job-level permissions.
* Grant `id-token: write` only to jobs that require OIDC.
* Grant `packages: write` only to publishing jobs.
* Avoid exposing secrets to pull requests from forks.
* Use protected environments for production publishing if appropriate.
* Prevent untrusted pull-request code from accessing signing or publishing credentials.
* Keep build and publish responsibilities separated where practical.
* Ensure release publication requires all existing test gates.
* Add concurrency protection against conflicting releases.
* Add timeouts to jobs.
* Preserve useful logs and security artifacts.
* Avoid shell injection from branch names, tags, matrix values, or user-controlled inputs.

Audit all workflows, not only the main release workflow.

### Reproducibility

Improve build reproducibility where practical:

* Pin inputs.
* Avoid unnecessary timestamps in compiled artifacts.
* Normalize archive ordering and metadata where possible.
* Use deterministic dependency downloads.
* Record unavoidable nondeterministic inputs.
* Ensure rebuilding the same source with the same inputs produces equivalent artifacts, or document why byte-for-byte reproducibility is not currently possible.

Add a CI or documented verification process for comparing rebuild outputs.

### Supply-chain tests

Add checks that fail when:

1. A production Dockerfile uses an unpinned base-image tag.
2. A downloaded archive lacks a checksum.
3. A Git dependency uses a branch or nonimmutable reference.
4. A third-party GitHub Action is not pinned to a full commit SHA.
5. Required OCI labels are absent.
6. An SBOM is not produced.
7. An image is published without a signature.
8. An image is published without provenance.
9. A release manifest contains mutable tags without digests.
10. A critical vulnerability violates policy.
11. Workflow permissions are broader than allowed.
12. Lockfiles are inconsistent.

Use scripts or policy-as-code that can run locally and in CI.

### Operator documentation

Document:

* how to verify an image signature
* how to verify build provenance
* how to retrieve and inspect an SBOM
* how to verify the release manifest
* how to confirm deployed image digests
* how dependency and base-image updates are performed
* vulnerability exception policy
* incident response for a compromised dependency or image
* rollback to a previously verified release

## Cross-workstream requirements

### Schema and compatibility

Any new runtime fields must:

* have explicit schema validation
* use safe defaults
* reject unknown enum values
* preserve last-valid state when invalid
* be included in signed artifacts
* have compatibility tests between control plane and edge agent
* include a documented schema version strategy

Do not allow a partially upgraded control plane and edge fleet to silently misinterpret runtime configuration.

### No false success

A deployment must not be marked successful merely because Laravel produced an artifact.

Success must reflect the relevant data-plane outcome.

For runtime generation activation, acknowledgement must identify the generation and revision actually loaded.

For WAF mode updates, E2E tests must prove the intended mode is active.

For image releases, the final release manifest must reference only images that passed tests, scanning, signing, and provenance generation.

### Test execution

Run all existing repository test suites and all new tests.

At minimum, run the equivalent of:

* PHP tests
* Go tests for every module
* Lua or OpenResty tests
* schema tests
* OpenAPI validation
* Compose validation
* real-runtime E2E tests
* failure-injection tests
* image builds
* supply-chain policy tests
* vulnerability scanning

Do not delete or weaken existing tests to make the build pass.

Fix flaky tests when the flakiness represents a real race or nondeterministic production behavior.

If a test cannot run in the current environment, clearly identify:

* the exact command
* why it could not run
* what prerequisites are missing
* which remaining risk is unverified

### Documentation updates

Update relevant files such as:

* README
* architecture documentation
* production deployment documentation
* security documentation
* API/OpenAPI documentation
* runtime schema documentation
* incident-response documentation
* roadmap and qualification checklist
* release process
* operator runbooks

Do not mark production qualification checklist items complete unless automated or recorded evidence actually supports them.

## Required final report

At completion, provide a structured report containing:

1. Repository areas inspected.
2. Architecture decisions.
3. Files changed.
4. Database or schema migrations.
5. Runtime compatibility impact.
6. Generation activation state machine.
7. Durability guarantees.
8. Crash and power-loss recovery behavior.
9. Final WAF behavior for each mode.
10. How CRS enforcement is proven.
11. Remaining custom Lua security checks and their purpose.
12. Supply-chain controls added.
13. Image-signing and provenance verification instructions.
14. SBOM retrieval instructions.
15. Release-manifest format and an example.
16. Tests added.
17. Commands executed.
18. Test results.
19. Known limitations.
20. Remaining production risks.

Also include a concise list of acceptance criteria, marking each item as:

* completed
* partially completed
* blocked

Do not describe an item as completed without code, tests, and documentation supporting it.

## Definition of done

This goal is complete only when all of the following are true:

* Runtime activation switches a complete immutable generation atomically.
* An interrupted activation cannot expose a mixed generation.
* Successful activation is durable across agent restart and host reboot.
* Rollback switches complete generations atomically.
* The active generation and revision are observable.
* Real OWASP CRS execution determines managed-WAF monitoring and blocking.
* Per-hostname off, monitor, and block modes work on shared OpenResty cells.
* WAF terminology accurately matches actual enforcement.
* WAF events are bounded and redact sensitive data.
* Real-runtime WAF E2E tests pass.
* Production base images are digest-pinned.
* Downloaded build archives are checksum-verified.
* Git dependencies are pinned to immutable commits.
* Third-party GitHub Actions are pinned to immutable commits.
* Every production image has an SBOM.
* Every production image is scanned according to policy.
* Every production image is signed by digest.
* Every production image has verifiable build provenance.
* Every release has a signed or attested digest-based release manifest.
* Operators have documented verification and rollback procedures.
* Existing CI and new hardening tests pass.
* No CDNFoundry architectural invariant has been weakened.

Begin by auditing the repository and then implement the complete goal. Do not stop after producing an audit or plan.
