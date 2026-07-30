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

## Grafana observability — owner-run browser qualification

### Startup, access, and provisioning

1. Start the telemetry profile with the three unique production Grafana
   passwords set. Open the HTTPS reverse-proxy URL. Confirm plain HTTP redirects
   to HTTPS, direct non-loopback port 3000 access is blocked, anonymous access
   is denied, and the expected administrator login succeeds.
2. Open **Connections → Data sources**. Confirm exactly the provisioned
   `prometheus`, `clickhouse`, `control-db`, and `loki` UIDs are present and each health
   check succeeds. Confirm datasource editing is disabled.
3. Open **Dashboards**. Confirm there is one **CDNFoundry Operations** folder
   containing exactly **CDNFoundry — System Command Center** and **CDNFoundry —
   Domain Command Center**. Confirm the system dashboard is the home dashboard.
4. Restart Grafana with network egress blocked. Confirm it becomes healthy and
   both dashboards and the ClickHouse plugin load without a startup download.

### System Command Center

1. Display the system dashboard at 1920×1080. Confirm the default range is six
   hours, refresh is 30 seconds, there are no dashboard variables, and the
   incident strip is visible without scrolling.
2. Confirm the strip shows platform state, critical/warning alerts, unhealthy
   components, target availability, stale edges/gateways, DNS drift, TLS
   expiry, failed operations, telemetry errors/drops, HTTP rate, DNS QPS,
   egress, 5xx, cache hit ratio, endpoint mismatch, and rollout state. Create
   one controlled warning and confirm green/yellow/red behavior is consistent.
3. Expand each diagnostic row. Confirm every current SystemHealth component and
   all four queue lanes appear; active alerts show severity/name/state/duration;
   gateway, edge, cell, endpoint, capacity, HTTP, DNS, Vector, host,
   ClickHouse, PowerDNS, DNSdist, and Alertmanager panels populate from real
   sources. Follow each displayed runbook link and confirm its anchor exists.
4. Confirm **New firing alerts** is off by default and graph backgrounds remain
   readable. Generate controlled HTTP traffic and one new alert, enable the
   annotation, and confirm one bounded transition marker appears instead of a
   red marker at every scrape. Disable the annotation again.
5. Stop one datasource at a time. Confirm the affected panels show datasource
   failure/no data and never a fabricated zero, while unrelated datasources and
   serving traffic remain healthy.
6. Expand **Live Operational Logs**. Generate one controlled warning, one edge
   candidate rejection, and one queue failure. Confirm service/host matrices,
   latest errors, ingestion health, and the relevant grouped panels update.
   Follow a data link to Explore and confirm its range and Loki datasource.
7. Expand **HTTP analytics** and open **Recent proxy request tail**. Generate a
   MISS followed by a HIT. Confirm newest-first rows show client status, cache
   result, origin status/role/transition, edge, and byte counts within two
   refreshes. Confirm no client IP, query string, header, user agent, referrer,
   cookie, authorization value, or body appears.

### Domain Command Center

1. Open the domain dashboard. Confirm exactly one searchable, single-select
   **Domain** input exists, has no **All** option, and lists a current
   non-deleted zero-traffic domain by display name and DNS name.
2. Select that zero-traffic domain. Confirm authoritative lifecycle, revisions,
   DNS, placement/cells, cache, TLS, security, and WAF metadata are visible and
   every traffic panel says **No traffic in selected range** without reporting
   datasource failure.
3. Select a disposable traffic domain and generate HTTP/DNS, MISS/HIT, 5xx,
   primary/backup transition, TLS failure, security/WAF block, and compression
   samples. Confirm every panel changes only for the selected domain and the
   diagnosis row identifies each controlled failure.
4. Compare raw samples with average and exact p50/p95/p99 origin latency. Confirm
   paths contain no query strings, top lists are bounded, and no client IP,
   authorization, cookie, request body, user agent, or referrer appears.
5. Open **Recent proxy request tail**. Confirm it is newest-first, contains only
   the selected domain, distinguishes client status from origin status, and
   updates within two refreshes after a controlled request.
6. Select ranges inside seven days, crossing seven days, beyond 400 days, and
   beyond available retention. Confirm raw-detail completeness is explicit,
   exact quantiles are never synthesized from aggregates, and volume panels use
   the documented raw/hourly/daily boundaries.
7. Expand **Live Operational Logs**. Confirm all log panels remain scoped to the
   selected domain, failed deployment/DNS/origin/certificate/purge/security/edge
   task events appear, and changing the domain removes the previous domain's
   entries without adding another dashboard variable.

### Live Logs control-panel navigation

1. Set `GRAFANA_EXPLORE_URL`, restart `core`, sign in as an administrator, and
   confirm **Observe → Live Logs** opens Grafana Explore in a new tab.
2. Sign in as a domain user and confirm the entry is absent. Unset the variable,
   restart `core`, and confirm it is absent from the administrator panel too.
3. In Explore, live-tail one controlled operational event. Confirm request,
   operation, job, task, domain, edge, cell, and revision fields are searchable
   JSON but not stream labels. Confirm injected passwords, tokens, cookies,
   database URLs, PEM blocks, query strings, and sensitive command options are
   redacted.
4. Stop Loki while leaving an edge and DNS service under controlled traffic.
   Confirm serving and control work continues, the host collector buffer grows,
   and the Loki/Vector alerts fire only after their persistence windows. Restart
   Loki, confirm `/ready`, buffered delivery, Explore recovery, and alert clear.
5. Stop one host collector. Confirm only that collector identity becomes absent,
   other hosts continue ingesting, and its critical-service silence alert is
   bounded rather than one alert per line. Restart it with the same volume and
   `LOG_COLLECTOR_ID`, then confirm recovery without duplicate streams.

### Grafana observability completion gate

- Implementation: exactly two provisioned dashboards, pinned offline plugin,
  private hardened Grafana/Loki services, per-host collectors, and restricted
  datasource accounts are present.
- Documentation: startup, credentials, reverse proxy/TLS, accounts, dashboards,
  retention, split endpoints, and troubleshooting are current.
- Automated/runtime qualification: static contracts, Compose validation,
  datasource health, and both dashboard UID API checks have recorded passing
  evidence.
- Manual browser qualification: owner-run; **not complete until every
  applicable checkpoint above is recorded as passed**.

## Final production-hardening gate

1. Confirm no customer HTTP or DNS traffic passes through Laravel and no invariant in the roadmap or repository instructions was weakened.
2. Confirm control-plane outage testing preserves last-valid DNS and edge serving.
3. Confirm invalid, incomplete, corrupt, and partial configurations never replace the last valid generation.
4. Confirm WAF behavior, events, UI language, API/OpenAPI, and documentation agree exactly.
5. Confirm the released image set is traceable to source, scanned, signed, attested, SBOM-backed, and deployed by digest.
6. Record the final acceptance criteria as **Completed**, **Partially completed**, or **Blocked**. Nothing is completed without implementation, automated/runtime evidence, current documentation, and applicable owner-run evidence.

## Record the result

Record every failed or blocked checkpoint with an owner, stable evidence link, remediation, and retest result. Any broken flow, unauthorized access, false success, unexplained pending state, mixed runtime generation, last-valid-state regression, CRS behavior mismatch, sensitive-data leak, unverifiable artifact, mutable release identity, or missing required evidence fails qualification.
