---
title: Production hardening manual qualification
description: Owner-run browser and real-runtime checklist for atomic edge generations, managed WAF enforcement, and software supply-chain security.
---

# Production hardening manual qualification

This is the manual, owner-run qualification job for the [current roadmap](roadmap.md). Coding agents must not launch or automate a browser. Automated tests and API probes support this job but never replace rendered UI or owner-observed real-traffic checkpoints.

Record every checkpoint as **Passed**, **Failed**, **Blocked**, or **Not run**. A missing menu, field, action, status, event, artifact, or runtime result is **Failed**. Do not mark a workstream complete until its implementation, documentation, automated/runtime qualification, and this manual qualification are independently complete.

## Qualification record

| Evidence | Recorded value |
| --- | --- |
| Result | Passed / Failed / Blocked / Not run |
| Date and operator | |
| Commit SHA and working-tree state | |
| Environment and topology | |
| Browser and version | |
| Desktop and narrow/mobile viewports | |
| Control, gateway, agent, and cell image digests | |
| IPv4 and optional IPv6 service addresses | |
| Disposable domains, hostnames, and origins | |
| Operation, revision, generation, and request IDs | |
| CI workflow and release identifier | |
| Sanitized logs, metrics, reports, screenshots, and recordings | |
| Automated/runtime qualification report | |
| Failures, owners, and retest evidence | |

Evidence must be sanitized. Never record passwords, API or bootstrap tokens, cookies, authorization values, private keys, signing material, customer data, complete request bodies, or unrestricted query strings.

## Preparation

1. Use a disposable production-like topology with at least one gateway, one shared OpenResty cell, two hostnames on that cell, and independently controlled origins.
2. Record immutable image digests, the source commit, runtime schema versions, service addresses, and whether IPv6 is configured.
3. Confirm the supported isolated test command was used for Laravel tests and that it reported `APP_ENV=testing`, `DB_CONNECTION=sqlite`, and `DB_DATABASE=:memory:`. Never refresh the persistent development PostgreSQL database or remove named volumes.
4. Record successful automated evidence for PHP, Go, Lua/OpenResty, schemas, OpenAPI, Compose, non-browser E2E, failure injection, image builds, supply-chain policy, and vulnerability scanning. Record exact unavailable commands and prerequisites as blocked.
5. Keep browser developer tools open for console and network inspection. Any unexpected JavaScript exception, failed asset, authorization leak, or unstable operation state fails the relevant checkpoint.

## Workstream 1 — Atomic and durable runtime generations

### Administrator workflow and observability

1. Sign in as an administrator and open the edge/runtime status surface. Confirm it shows the active generation ID and configuration revision without exposing signed artifacts or secrets.
2. Make one valid runtime-affecting change. Confirm the request returns an asynchronous operation rather than waiting for deployment.
3. Follow the operation to completion. Record its operation ID, desired revision, acknowledged generation ID, active revision, and timestamps. Confirm success is shown only after the data plane reports the loaded generation and revision.
4. Confirm internal health or status output reports the same generation ID and revision for the gateway and all affected cells. Any mismatch fails this checkpoint.
5. Confirm bounded metrics or structured events exist for activation started and succeeded. Verify previous generation identity is observable and no artifact contents or secrets appear in logs.

### Invalid candidates and last-valid state

1. Using the documented operator test procedure, submit or inject each supported invalid candidate: corrupt manifest, missing file, digest mismatch, unexpected file, invalid runtime schema, and older revision.
2. For every case, confirm the operation fails with a stable reason, the active generation and revision remain unchanged, and existing HTTP/HTTPS traffic continues on the last valid configuration.
3. Restart the agent after a rejected candidate. Confirm it recovers the same complete active generation and does not acknowledge the rejected candidate.
4. Confirm candidate-validation and abandoned-candidate-cleanup events are bounded and sanitized.

### Interruption, restart, and durability

1. Run the documented failure-injection job at each activation boundary: file write, before generation publication, after publication but before pointer replacement, during pointer replacement, and after pointer replacement but before acknowledgement.
2. After each interruption, restart the agent and verify it selects either the prior complete generation or the new complete generation—never a mixture. Record gateway and cell generation IDs.
3. After a successful activation, perform the documented host-style restart. Confirm the same generation remains active and traffic resumes without control-plane availability.
4. Exercise simulated disk-full and permission failures. Confirm no partial generation becomes active, traffic keeps using the last valid state, retries are bounded, and failure is visible.
5. Trigger concurrent reconciliation and duplicate activation of the same revision. Confirm the result is idempotent, obsolete work is skipped, and acknowledgements identify only a durably loaded generation.

### Rollback and retention

1. Activate two known-good generations, then use the documented rollback action. Confirm rollback verifies the target and atomically switches the entire generation.
2. Repeat the same rollback request. Confirm it is idempotent and reports the resulting active generation.
3. Confirm the replaced generation becomes the rollback generation and gateway/cells converge on the same generation without mixed files.
4. Generate enough valid revisions to cross the retention limit. Confirm cleanup remains bounded and never deletes the active or previous generation.
5. Confirm metrics or events exist for rollback started/succeeded/failed and recovery, with stable identifiers and no secrets.

### Workstream 1 completion gate

- Implementation: immutable generation publication, durable atomic pointer activation, verification, recovery, rollback, bounded retention, and observability are present.
- Documentation: layout, state machine, filesystem assumptions, durability guarantee, acknowledgement, recovery, rollback, retention, and operator procedure are current.
- Automated/runtime qualification: all roadmap failure-injection and real-runtime cases have recorded passing evidence.
- Manual browser/host qualification: owner-run; **not complete until every applicable checkpoint above is recorded as passed**.

## Workstream 2 — ModSecurity and OWASP CRS enforcement

### Configuration, permissions, and terminology

1. As an administrator, open the hostname security/WAF form. Confirm the managed WAF offers exactly the documented `off`, `monitor`, and `block` modes (or documented public equivalents), with accurate help text for each.
2. Confirm threshold and paranoia controls, if exposed, show bounded valid ranges and safe defaults. Unknown modes, invalid thresholds, and invalid paranoia levels must produce clear validation errors.
3. Save each valid mode and confirm an asynchronous operation is created. Confirm the resulting revision and generation become active without an Nginx reload.
4. Sign in as a domain user assigned to only one test domain. Confirm the user can view or change only authorized settings and cannot read or change the other hostname's WAF mode.
5. Confirm migration/compatibility messaging does not silently enable blocking for a hostname that previously used detection-only behavior.

### Real shared-cell enforcement

Use two hostnames on the same OpenResty cell. Record request IDs, generation ID, configuration revision, HTTP results, and sanitized security-event evidence.

1. Set hostname A to `off`. Send benign GET and POST requests, then the documented SQL injection, XSS, path traversal, and command injection probes. Confirm managed CRS enforcement is not applied and separately documented platform safety checks still behave as specified.
2. Set hostname A to `monitor`. Repeat the same probes. Confirm benign traffic succeeds; CRS-detected attacks are allowed; and bounded events record mode, allowed/monitored action, rule IDs, anomaly score, threshold, request ID, and generation/revision.
3. Set hostname A to `block`. Repeat the probes. Confirm attacks exceeding the threshold receive the controlled CDNFoundry response while benign traffic succeeds. The visitor response must not leak rule internals.
4. Keep hostname A in `block` and hostname B in `monitor` on the same cell. Send the identical attack to both and confirm A blocks while B allows and records it, with no separate per-domain process, worker, or server block.
5. Change both modes at runtime and repeat. Confirm behavior changes only after the acknowledged generation is loaded and no Nginx reload occurs.

### Thresholds, bodies, failures, and privacy

1. Exercise the documented threshold boundary and each exposed paranoia level. Confirm behavior matches the saved configuration and events report the effective values.
2. Exercise benign JSON POST, common false-positive fixtures, malformed bodies, oversized bodies, and multipart boundary handling. Confirm responses match documented bounded-body and fail-open/fail-closed policies.
3. Inject an invalid runtime WAF configuration. Confirm the previous mode remains active and the failed generation is not acknowledged.
4. Exercise documented CRS startup failure, missing rules, unavailable ModSecurity module, and transaction failure procedures. Confirm behavior exactly matches the documented failure policy and is clearly degraded rather than falsely successful.
5. Stop telemetry/analytics while serving traffic. Confirm the documented bounded buffering/drop behavior and that an observability outage does not become an undocumented global traffic outage.
6. Send unique canary secrets in authorization, cookie, query, and body fields. Search permitted logs, events, metrics, and UI views; confirm none contain those values, complete bodies, or unrestricted query strings.
7. Confirm security-event views and exports are bounded and contain the documented timestamp, edge/cell/hostname identifiers, request ID, mode, action, scores, threshold, safe request metadata, status, generation, and revision.

### Workstream 2 completion gate

- Implementation: real ModSecurity/CRS inspection authoritatively provides per-hostname off/monitor/block behavior on shared cells; remaining Lua protections are distinctly classified.
- Documentation: modes, thresholds, CRS version/update, false positives, exclusions, privacy, platform checks, compatibility, and failure policies are current and truthful.
- Automated/runtime qualification: real-module and real-CRS E2E cases from the roadmap have recorded passing evidence.
- Manual browser/traffic qualification: owner-run; **not complete until every applicable checkpoint above is recorded as passed**.

## Workstream 3 — Software supply-chain security

### Release identity and evidence

1. Open the selected official release workflow run and record its source commit, workflow identity, builder identity, release version, and immutable production image digests.
2. Confirm required tests and security checks completed before signing and publication. Confirm publishing credentials were unavailable to untrusted pull-request jobs.
3. Retrieve the machine-readable release manifest. Confirm it contains the release version, source commit, creation time, workflow run, every production image digest, SBOM/signature/provenance references, scan summary, schema/protocol versions, and migration identifier when applicable.
4. Confirm the manifest is signed or attested and contains no mutable tag as release identity.
5. Compare deployed container image digests with the release manifest. Every digest must match exactly.

### Independent verification

Run the documented operator commands from a clean verification environment and attach sanitized output.

1. Verify every image signature by digest against the expected repository identity, workflow identity, and issuer.
2. Verify provenance for every image and confirm it binds the digest to the recorded source commit, workflow, builder, and build parameters.
3. Retrieve each SBOM from its image digest. Confirm it is valid SPDX JSON or CycloneDX JSON and includes relevant OS, application, Go, Composer, Lua, and native dependencies.
4. Inspect human- and machine-readable vulnerability reports. Confirm database freshness, all severities, critical policy enforcement, and any high-severity disposition.
5. Inspect every exception. Confirm it is narrowly scoped, reviewed, justified, and expires; permanent broad ignores fail this checkpoint.
6. Inspect OCI labels for source, revision, version/release, build timestamp, description, and other documented metadata. Confirm they agree with the manifest.

### Build and workflow policy

1. Run the local supply-chain policy check. Confirm it detects unpinned production base images, unchecked archives, mutable Git dependencies, unpinned third-party actions, missing OCI labels, missing SBOM/signature/provenance, mutable release references, forbidden vulnerabilities, excessive workflow permissions, and inconsistent lockfiles.
2. Review production Dockerfiles and build scripts. Confirm base images use digests, downloaded archives use HTTPS and committed checksums, and source Git dependencies use full commit SHAs.
3. Review all GitHub Actions workflows. Confirm third-party actions use full commit SHAs with release comments, job permissions are minimal, OIDC/package permissions are isolated, jobs have timeouts, releases have concurrency protection, and user-controlled inputs cannot cause shell injection.
4. Follow the documented rebuild comparison procedure. Record whether artifacts are byte-identical or equivalent and every declared nondeterministic input.
5. Follow the documented base-image/dependency update procedure without publishing. Confirm proposed changes remain reviewable and lockfile consistency checks run.

### Incident response and rollback

1. Walk through the compromised dependency/image procedure using a disposable release. Confirm affected digests can be identified from manifests, SBOMs, provenance, and scan evidence.
2. Roll back to a previously verified release using manifest digests. Confirm deployed digests match that manifest and runtime/database compatibility rules are followed.
3. Confirm mutable tags are not used as the rollback source of truth and no signing private key is exposed when keyless signing is configured.

### Workstream 3 completion gate

- Implementation: immutable inputs, checksums, locking, OCI metadata, SBOMs, scanning, digest signing, provenance, attested release manifests, and hardened workflows are present.
- Documentation: verification, updates, exceptions, incident response, reproducibility, deployment by digest, and rollback are current.
- Automated qualification: image builds and every roadmap supply-chain policy test have recorded passing evidence.
- Manual release qualification: owner-run; **not complete until every applicable checkpoint above is recorded as passed**.

## Final production-hardening gate

1. Confirm no customer HTTP or DNS traffic passes through Laravel and no invariant in the roadmap or repository instructions was weakened.
2. Confirm control-plane outage testing preserves last-valid DNS and edge serving.
3. Confirm invalid, incomplete, corrupt, and partial configurations never replace the last valid generation.
4. Confirm WAF behavior, events, UI language, API/OpenAPI, and documentation agree exactly.
5. Confirm the released image set is traceable to source, scanned, signed, attested, SBOM-backed, and deployed by digest.
6. Record the final acceptance criteria as **Completed**, **Partially completed**, or **Blocked**. Nothing is completed without implementation, automated/runtime evidence, current documentation, and applicable owner-run evidence.

## Record the result

Record every failed or blocked checkpoint with an owner, stable evidence link, remediation, and retest result. Any broken flow, unauthorized access, false success, unexplained pending state, mixed runtime generation, last-valid-state regression, CRS behavior mismatch, sensitive-data leak, unverifiable artifact, mutable release identity, or missing required evidence fails qualification.
