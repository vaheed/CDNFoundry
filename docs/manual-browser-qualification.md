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

## Implemented product regression

Complete this regression before the current hardening workstreams. It preserves
the owner-run coverage for all functionality shipped through `v0.9.1`; a newer
workstream does not replace an earlier product checkpoint.

### Accounts, scope, and navigation

1. At desktop and narrow/mobile widths, sign in to `/admin` as an administrator
   and `/app` as a domain user. Confirm navigation, tables, forms, badges,
   dialogs, help text, validation, empty states, and notifications remain
   readable without labels breaking into fragments or requiring page-wide
   horizontal scrolling.
2. Under **Customers → Users**, create one domain user, assign exactly one
   disposable domain, disable and re-enable the account, and verify the domain
   user never sees another domain, administrator navigation, global operations,
   platform settings, clusters, edges, pools, users, or backups.
3. Under **Account → API tokens**, create a bounded token, record it only at its
   one-time boundary, use it for one permitted API request, then revoke it and
   confirm subsequent use fails. Confirm password/profile changes invalidate
   the documented sessions and never reveal password history.

### System DNS, clusters, and domains

1. Under **Infrastructure → DNS clusters**, create each cluster disabled. Confirm
   the form groups **Cluster connection**, **Authoritative nameservers**, and
   **Operator context**, with labels **API URL**, **API key**, **Server ID**, and
   **Zone capacity**. Run **Test connection**, require verified TLS and a healthy
   result, then enable it. A bad key, CA, name, or unreachable API must fail
   without creating a usable deployment target.
2. Open **Infrastructure → System DNS identity**. Confirm **Public DNS
   identity**, **Authoritative nameservers**, and **SOA and TTL policy** are
   full-width readable sections. Enter platform domain, proxy hostname, two
   nameservers with IPv4 and optional IPv6, SOA primary/mailbox, refresh, retry,
   expire, minimum TTL, and default TTL. Choose **Validate and preview**, inspect
   normalization, apply the exact confirmation, and record the asynchronous
   operation and active checksum on both clusters.
3. Create a domain, assign the user, verify nameservers, activate it, then cover
   DNS-only A, AAAA, CNAME, MX, TXT, NS, CAA, SRV, and reverse PTR records.
   Exercise BIND preview/import/export, duplicate/CNAME/zone-boundary failures,
   bulk bounds, and Geo-DNS country/continent/default preview. Confirm UDP and
   TCP answers externally on both authoritative hosts.
4. Disable, re-enable, and deprovision only a disposable domain. Confirm delayed
   cleanup, tombstone/reclaim behavior, policy scope, and last-valid DNS while
   a replacement candidate is invalid.

### Edge pools, cells, gateway, and routing

1. Under **Infrastructure → Service pools**, create a Geo-Unicast shared pool and
   a quarantine pool. Record Kind, Cache profile, Compression profile, managed
   WAF capability, Routing mode, Minimum ready cells, Replicas per edge, and
   Maximum domains per cell. Confirm invalid replicas/capacity/address
   combinations fail before activation.
2. Under **Infrastructure → Edges**, create two edges with **Cell slots = 8**,
   country, continent, and optional management addresses. Enroll each agent with
   its one-time token, remove that token after registration, and confirm fresh
   heartbeat, current runtime versions, gateway process, traffic listener,
   gateway revision/listeners/routes, capacity, and no deployment rejection.
3. In each edge's **Cells** relation, use **Assign service pool** for the
   intended stable slots. Exercise drain, undrain, restart, and unassign; confirm
   unrelated cells keep serving and active placement prevents unsafe removal.
4. Create each Geo-Unicast pool endpoint with its distinct advertised service
   IPv4 and optional IPv6. Map every address to a distinct assigned local
   listener with `EDGE_GATEWAY_ADDRESS_MAP`. Confirm the gateway binds only the
   local pair, DNS publishes only ready advertised endpoints, Host/SNI routing
   reaches the intended cell, and management addresses are never published or
   bound for customer traffic.
5. Where the provider approves BGP, create one **Simple Anycast** pool with a
   shared IPv4/optional IPv6 pair and explicit addressless participation on two
   POPs. Verify external route origin/path and three independent vantage points,
   POP drain/withdrawal/restoration, DNS state, HTTP/HTTPS POP selection, and
   convergence. Confirm CDNFoundry never claims to announce BGP or protect a
   saturated uplink.

### Proxy, TLS, cache, compression, and security

1. Add one proxied hostname with primary origin host/IP, scheme, port, Host
   header, TLS SNI/verification, connection/response timeouts, health check,
   and optional backup origin plus failure/recovery thresholds and hold-down.
   Run **Test origin** and **Test backup**; unsafe, mixed safe/unsafe, metadata,
   internal-service, and proxy-loop destinations must fail closed.
2. Exercise primary failure, bounded transition to backup, hold-down, failback,
   both-origin failure, WebSocket policy, forwarding headers, and control-plane
   outage. Record `X-CDNFoundry-Origin`, request IDs, revisions, and comparison
   traffic showing unrelated hosts remain available.
3. Use **TLS mode** for managed DNS-01 and a valid custom certificate. Exercise
   **Renew managed certificate**, **Reissue managed certificate**, **Upload
   custom certificate**, and **Remove custom certificate** with valid/invalid
   keys, chains, names, and expiry. Confirm DNS-only hosts do not cause issuance
   and a failed order or upload preserves the previous valid certificate.
4. Use **Cache settings** to cover TTLs, object bound, origin-header policy,
   query policy/selected parameters, bypass cookies, status TTLs, admission,
   stale windows, and variant bounds. Prove MISS then HIT, development-mode
   bypass/expiry, URL purge, epoch-based full purge, retry, and exact cache-key
   agreement. Confirm Gzip and Brotli follow the pool profile while storing one
   canonical object and falling back safely under pressure.
5. Use **Security profile and limits**, ordered security rules, maintenance,
   Under Attack mode, quarantine, and expiring controls. Verify IPv4/IPv6/CIDR,
   country, and continent rules, trusted-client handling, allow/deny/rate-limit
   outcomes, bounded error bodies, and continued comparison traffic. Complete
   the managed WAF checkpoints below on a WAF-capable pool.

### Analytics, operations, recovery, and upgrade

1. Sign in at `/admin` and open **Observe → Operations overview** at desktop,
   tablet, and narrow/mobile widths. In **Investigation context**, select each
   **Time range** value (1 hour, 6 hours, 24 hours, and 7 days), confirming
   **24 hours** is selected when the URL contains no range,
   toggle **Compare previous period**, search and select one **Domain**, then
   search and select one **Edge**. Expect the URL query string to change. Copy
   it into a second authenticated administrator tab and expect the same filter
   labels and scope. An invalid/deleted domain or edge in a copied URL must show
   **Invalid investigation filter** and must not silently show global metrics.
2. Above the first scroll, confirm the service banner explicitly labels the
   condition **Healthy**, **Degraded**, **Critical**, **Maintenance**,
   **Unknown**, or **Stale** and
   includes active conditions, affected domains/edges/regions, condition start,
   metric freshness, **Polling**, and **Investigate**. Stop ClickHouse/Vector
   in the disposable environment: expect unknown/stale telemetry, never green,
   while comparison DNS/HTTPS keeps serving. Restore them and expect recovery
   without a full-page refresh.
3. Confirm **Operational KPIs**, **Traffic and egress**, and **Errors and origin
   latency** render complete hourly evidence with units, previous-period state,
   source time, and accessible data summaries. A zero or absent comparison must
   say **No comparable baseline**. Confirm unsupported percentile and DNS
   latency values say **Unavailable**, not zero. Follow the 5xx, cache, origin,
   and telemetry links and confirm the range/domain/edge query parameters are
   retained.
4. Confirm **Active conditions**, **Edge health and capacity**, **Cache
   efficiency**, **DNS health**, **Queue and worker health**, **Operations
   timeline**, and **Data freshness** independently update at their documented
   intervals. Search/sort/filter/paginate the edge table. Verify stale
   heartbeat, disabled, drained, degraded, and healthy badges include text;
   inspect each row link. Confirm queue depth distinguishes unavailable from
   zero and the timeline answers what changed before the test condition.
   In the edge table, confirm there is no Pools column and **Peak resource**
   names Memory, Cache, Temporary, or Connections; its tooltip identifies the
   cell and used/limit values.
   Confirm an idle edge is not interpreted as resource-free. Follow an active
   condition and the banner **Investigate** action: edge and DNS conditions
   must open their relevant resource, while runtime failures must open
   Operations with the Failed filter active.
5. Open **Observe → Traffic and telemetry** from a scoped dashboard link.
   Confirm **Investigation context** exposes Time range, Domain, and Edge
   filters plus one **Apply filters** action. Confirm the controls stack in one
   column on mobile, two columns on tablet, and one aligned row on desktop;
   each select must have the same visible bordered box, height, radius, focus
   ring, and dropdown indicator as the Operations overview controls. Labels and
   selected values must never wrap letter by letter. Confirm the
   Analytics focus and HTTP status filters are also visible and that selecting
   4xx or 5xx scopes the bounded recent-log query. Confirm a delayed/stale
   aggregate notice appears once in **Telemetry status** near the top, never as
   repeated chart-sized boxes. Confirm both overview charts have equal width
   and height. Confirm the six filtered KPI cards appear above both charts. For
   a 7-day investigation, confirm **Recent logs** and **Compression savings**
   explicitly use the latest bounded 24 hours and can show recent events. When
   DNS has no aggregate rows, confirm DNS health still shows healthy/enabled
   cluster counts and links to DNS analytics and DNS clusters. Confirm the
   selected scope persists, the two
   Chart.js charts render, table/log previews stay bounded, partial/finalized
   semantics remain explicit, exports remain authorized, and ClickHouse/Vector
   outage states do not interrupt serving. The page must state that its legacy
   detailed tables are not yet edge-scoped when an edge is selected.
6. As a domain user open **Observe → Analytics and logs** without a `domain`
   query parameter. Expect no telemetry query and **Select an assigned domain**.
   Use the searchable **Select domain** action, search an assigned domain, and
   expect only that domain's data. Searching for or sharing another user's
   domain ID must not reveal its name or metrics.
7. Under **Operate → Operations**, exercise failed-job retry, global
   reconciliations, quarantine recovery, and operation visibility. Under
   **Governance → Audit logs**, verify matching change evidence without input
   secrets. Confirm the dashboard timeline links to both destinations.
8. If built-in Restic backup is configured, create and verify a snapshot,
   perform restore preflight with exact confirmation and current password, then
   complete a clean-host restore with the full recovery secret set. If it is
   disabled, record the alternative tested recovery method and the expected
   degraded backup component; do not mark recovery passed without real restore
   evidence.
9. Run one bounded fleet canary and wave rollout with immutable gateway, agent,
   normal-cell, and WAF-cell digests. Inject a canary failure, confirm automatic
   pause, roll back, and verify no dynamic cell creation. Upgrade one edge at a
   time, then control/DNS components, while comparison DNS/HTTPS stays healthy.

### Implemented product completion gate

- Implementation: all applicable baseline capabilities above are present.
- Documentation: feature, operations, reference, troubleshooting, and
  deployment pages match the observed fields and behavior.
- Automated/runtime qualification: current isolated and real-runtime suites
  have recorded passing evidence for the tested commit.
- Manual browser/public qualification: owner-run; **not complete until every
  applicable checkpoint above is recorded as passed**.

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

The coding agent does not run this browser checklist. Real-runtime automated
qualification executes the actual ModSecurity module and pinned CRS image; the
steps below remain owner-run and must be recorded separately.

### Configuration, permissions, and terminology

1. As a domain owner, open **Web application firewall (WAF)**. Confirm it offers exactly **Off**, **Observe**, **Recommended**, and **High sensitivity**, corresponding to the internal `off`, `monitor`, `balanced`, and `strict` profiles, with accurate help text for each.
2. Confirm owners cannot enter arbitrary thresholds, paranoia levels, rules, or expressions. Unknown profiles and malformed exclusions must produce clear validation errors; the fixed profiles own their reviewed thresholds.
3. Save each valid profile and confirm an asynchronous operation is created. Confirm the resulting revision and generation become active without an Nginx reload.
4. Sign in as a domain user assigned to only one test domain. Confirm the user can view or change only authorized settings and cannot read or change the other hostname's WAF mode.
5. Confirm migration/compatibility messaging does not silently enable blocking for a hostname that previously used detection-only behavior.

### Real shared-cell enforcement

Use two hostnames on the same OpenResty cell. Record request IDs, generation ID, configuration revision, HTTP results, and sanitized security-event evidence.

1. Set hostname A to **Off** (`off`). Send benign GET and POST requests, then the documented SQL injection, XSS, path traversal, and command injection probes. Confirm managed CRS enforcement is not applied and separately documented platform safety checks still behave as specified.
2. Set hostname A to **Observe** (`monitor`). Repeat the same probes. Confirm benign traffic succeeds; CRS-detected attacks are allowed; and bounded events record profile, allowed/monitored action, numeric rule ID, anomaly score, request ID, and generation/revision.
3. Set hostname A to **Recommended** (`balanced`). Repeat the probes. Confirm attacks exceeding the fixed profile threshold receive the controlled CDNFoundry response while benign traffic succeeds. The visitor response must not leak rule internals.
4. Repeat with **High sensitivity** (`strict`) and confirm it applies the documented stricter fixed policy without exposing an arbitrary-expression surface.
5. Keep hostname A on **Recommended** and hostname B on **Observe** on the same cell. Send the identical attack to both and confirm A blocks while B allows and records it, with no separate per-domain process, worker, or server block.
6. Change both profiles at runtime and repeat. Confirm behavior changes only after the acknowledged generation is loaded and no Nginx reload occurs.

### Thresholds, bodies, failures, and privacy

1. Exercise the documented boundary for Recommended and High sensitivity. Confirm behavior matches each fixed profile and events report the effective profile and anomaly result.
2. Exercise benign JSON POST, common false-positive fixtures, malformed bodies, oversized bodies, and multipart boundary handling. Confirm responses match documented bounded-body and fail-open/fail-closed policies.
3. Inject an invalid runtime WAF configuration. Confirm the previous mode remains active and the failed generation is not acknowledged.
4. Exercise documented CRS startup failure, missing rules, unavailable ModSecurity module, and transaction failure procedures. Confirm behavior exactly matches the documented failure policy and is clearly degraded rather than falsely successful.
5. Stop telemetry/analytics while serving traffic. Confirm the documented bounded buffering/drop behavior and that an observability outage does not become an undocumented global traffic outage.
6. Send unique canary secrets in authorization, cookie, query, and body fields. Search permitted logs, events, metrics, and UI views; confirm none contain those values, complete bodies, or unrestricted query strings.
7. Confirm security-event views and exports are bounded and contain the documented timestamp, edge/cell/hostname identifiers, request ID, mode, action, scores, threshold, safe request metadata, status, generation, and revision.

### Workstream 2 completion gate

- Implementation: real ModSecurity/CRS inspection authoritatively provides per-hostname Off/Observe/Recommended/High sensitivity behavior on shared cells; remaining Lua protections are distinctly classified.
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

1. Sign in as an administrator, open **Governance → Platform settings →
   Observability links**, enter an absolute HTTP or HTTPS address in **Grafana
   Explore URL**, and save. Confirm **Observe → Live Logs** appears without a
   runtime operation and opens Grafana Explore in a new tab with Loki, the
   last-hour range, and
   `{environment=~"production|development"} | json` already populated. The
   query editor must not be blank.
2. Enter `ftp://grafana.example.test` and confirm the form rejects it. Clear the
   saved value and confirm `GRAFANA_EXPLORE_URL` is used when configured. Then
   clear the saved value with no environment fallback and confirm **Live Logs**
   is absent. Sign in as a domain user and confirm the entry is always absent.
3. Restore a valid administrator URL. In Explore, live-tail one controlled operational event. Confirm request,
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
