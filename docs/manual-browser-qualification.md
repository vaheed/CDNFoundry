---
title: Manual browser qualification
description: Owner-run browser checklist for every implemented CDNFoundry panel workflow.
---

# Manual browser qualification

This is a manual, owner-run release job. Coding agents must not automate it.
Record date, operator, exact commit, topology, browser/version, desktop and
mobile viewport, operation IDs, domain revisions, certificate fingerprints,
screenshots, actual results, and failures.

Use disposable application accounts and domains. Do not delete Compose volumes
or use customer data.

## Preparation

1. Start the persistent development or approved production-like topology.
2. Apply Laravel and PowerDNS migrations explicitly.
3. Confirm `/api/health`, `/api/ready`, DNSdist UDP/TCP, Horizon, and scheduler.
4. Create one administrator with `cdnf:admin:create`.
5. Prepare one unused domain-user email, one delegated test domain, two edge
   addresses, two origin fixtures, and two viewport sizes.
6. Sign in at `/admin`; confirm keyboard focus is visible, labels are associated,
   tables scroll on a narrow viewport, and no secret appears in page source or logs.

## Phase 1: foundation and access

### Administrator and user

1. Open **Customers → Users → New user**.
2. Enter name, unique email, type **User**, password, and confirmation.
3. Save. Expect one user row and no plaintext password display.
4. Edit the user and choose disable. Expect disabled status.
5. In another browser profile, attempt `/app` login. Expect denial.
6. Re-enable the user. Expect login to `/app`; no API token is recreated.
7. Confirm an administrator cannot disable, demote, or delete their own row.

### Profile and tokens

1. Open **Account → Profile** in each panel.
2. Change display name and save. Expect the header/account widget to update.
3. Change password with current password and matching confirmation.
4. Open **Account → API tokens**; create a named token.
5. Record that plaintext appears once; navigate away and back.
6. Expect only metadata/final six characters. Revoke it and confirm it disappears.

### System DNS identity

1. Open **Control plane → System DNS identity**.
2. Enter platform zone, two nameservers, IPv4 and IPv6 glue for each, proxy
   hostname, SOA contact, refresh, retry, expire, minimum, and TTL values.
3. Preview. Expect normalized records and a confirmation token.
4. Change one field after preview and try the old token. Expect rejection.
5. Preview again, apply the exact token, and record the returned operation.
6. Expect pending/running then succeeded deployment state without a blocked page.

### Phase 1 completion gate

| Gate | Result |
| --- | --- |
| Implementation | Present |
| Documentation | Current |
| Automated/runtime qualification | Record current run |
| Manual browser qualification | Pass only when every Phase 1 step above is recorded |

## Phase 2: domains and authoritative DNS

### DNS cluster

1. Open **Control plane → DNS clusters → New cluster**.
2. Enter unique name, location, HTTPS API URL, API key, server ID, nameserver
   list, and optional operational notes.
3. Save. Expect disabled/pending health and no API key in the table or edit form.
4. Edit and choose **Test connection**. Record the operation.
5. After success, enable the cluster and reconcile all zones.
6. Expect active checksums and no replacement of a healthy cluster's old state on a deliberately failed second target.

### Domain lifecycle and assignment

1. Open **Domains → New domain** and enter the delegated test domain.
2. Expect `pending_verification`, revision, nameserver guidance, and no origin.
3. Attach the domain user from the **Users** relation.
4. Sign in as that user. Expect only the assigned domain.
5. Choose **Verify nameservers**, wait for success, then **Activate**.
6. Expect active lifecycle and acknowledged DNS deployment.

### DNS records

1. Add A, AAAA, CNAME, MX, TXT, NS, CAA, and SRV examples with valid fields.
2. Confirm TTL minimum 30; MX requires priority; SRV requires
   `_service._protocol`, priority, weight, and port.
3. Try CNAME beside another record at the same owner. Expect inline rejection.
4. Try an owner outside the zone and a non-SRV underscore. Expect rejection.
5. As a domain user, try to change apex delegation NS. Expect the action hidden or denied.
6. Bulk delete selected non-delegation records. Expect one revision change.
7. Import a small BIND zone with append, then export it. Expect deterministic text.
8. Import with replacement and confirm the final record set.

### Phase 2 completion gate

| Gate | Result |
| --- | --- |
| Implementation | Present |
| Documentation | Current |
| Automated/runtime qualification | Record PHP, DNS, and scale evidence |
| Manual browser qualification | Pass only when every Phase 2 step above is recorded |

## Phase 3: Geo-DNS

1. Create an A record with mode **Geo-DNS**.
2. Add a default address, one continent override, and one country override.
3. Save. Expect normalized configuration and one domain revision.
4. Use **Preview** with an IP for each rule and an unknown/documentation IP.
5. Expect country before continent before default, with displayed classification.
6. Repeat with AAAA and confirm IPv6 input.
7. Try duplicate codes, no default target, too many targets, and CAA Geo-DNS.
8. Expect validation and no revision change.
9. Verify real DNS from approved external vantage points and record ECS behaviour.

### Phase 3 completion gate

| Gate | Result |
| --- | --- |
| Implementation | Present |
| Documentation | Current |
| Automated/runtime qualification | Record PHP and Geo-DNS runtime evidence |
| Manual browser/external vantage qualification | Pass only when every Phase 3 step is recorded |

## Phase 4: proxy and edge

### Pools, edges, and enrollment

1. Create one shared and one quarantine pool with unique stable names.
2. Create two edges with name, country, continent, unique IPv4, and optional IPv6.
3. Record each UUID/bootstrap token once; reload and expect token secrecy.
4. Edit each edge's cells and enter unique public IPv4/optional IPv6.
5. Enroll the real agents. Expect registered identity, fresh heartbeat,
   `listener_ready`, ready cells, version, sequence, and bounded capacity.
6. Rotate one identity. Expect the old agent to fail and a new token to appear once.
7. Restore enrollment, then drain/undrain a cell and one edge. Expect durable operations and routing exclusion/reinclusion.

### Proxied hostname

1. On the domain DNS relation, add a proxied A, AAAA, or CNAME.
2. Enter origin host, HTTP/HTTPS scheme, host header, verified HTTPS SNI,
   connect timeout, response timeout, retry count, WebSocket flag, and optional health-check path/interval.
3. Expect DNS content to become platform managed and the explicit origin to remain visible.
4. Try loopback, link-local, metadata, multicast, edge listener, and proxy hostname origins. Expect rejection.
5. Run **Test origin**. Expect an asynchronous result with address, latency,
   status, or stable failure reason.
6. Inspect edge delivery. Expect both eligible agents to acknowledge the revision.
7. Move the domain to the quarantine pool. Expect target ready before source drain.
8. Roll back to a retained revision. Expect a new higher revision, not a decremented number.

### Phase 4 completion gate

| Gate | Result |
| --- | --- |
| Implementation | Present |
| Documentation | Current |
| Automated/runtime qualification | Record PHP, Go, control, mTLS, and OpenResty evidence |
| Manual browser/real traffic qualification | Pass only when every Phase 4 step is recorded |

## Phase 5: TLS, cache, and purge

### Managed and custom TLS

1. With an active proxied domain, open the TLS section.
2. Expect managed mode and a visible pending/succeeded order without private data.
3. Verify public apex/wildcard HTTPS and record the fingerprint.
4. Use **Renew**, then **Reissue**; record operation IDs and final certificate state.
5. Upload a valid leaf, chain, and matching private key. Expect custom mode and metadata only.
6. Try a wrong key, wrong name, invalid chain, expired certificate, and oversized PEM. Expect rejection.
7. Remove the custom certificate. Expect managed mode and asynchronous reconciliation.
8. Set TLS disabled and confirm intended HTTPS behaviour; return to managed.

### Cache

1. Open **Cache settings** and set enabled, edge/browser TTLs, one allowed object
   size, origin-header respect, query policy, bypass cookies, and stale grace.
2. Save and wait for edge acknowledgement.
3. Request a cacheable object twice. Expect MISS then HIT and correct browser headers.
4. Enable development mode for a short duration. Expect absolute expiry and BYPASS.
5. Disable it and expect caching to resume.
6. Purge one exact URL. Expect one task per eligible edge and a later MISS.
7. Purge everything. Expect an incremented epoch, no filesystem scan, and later MISS.
8. Create a controlled cell-delivery failure. Expect visible retry of the same task.

### Phase 5 completion gate

| Gate | Result |
| --- | --- |
| Implementation | Present |
| Documentation | Current |
| Automated/runtime qualification | Record PHP, Pebble, cache, purge, and OpenResty evidence |
| Manual browser/public HTTPS qualification | Pass only when every Phase 5 step is recorded |

## Phase 6: security and DDoS readiness

1. Open **Security profile and limits**.
2. Select standard, protected, quarantine, then manual. Expect fixed profile
   values and manual values bounded by platform ceilings.
3. Select a quarantine policy, allowed methods, and trusted proxy CIDRs; save.
4. Add IP, CIDR, country, and continent allow/block rules with priorities.
5. Import rules with append and replacement. Expect duplicate/limit validation.
6. Send matching IPv4 and IPv6 traffic. Expect rule order and reason visibility.
7. Use **Restrict domain**, **Quarantine domain**, then **Release domain**.
8. Expect target-first placement and `recovering` before normal where applicable.
9. Apply and clear an expiring emergency mode to an edge and cell.
10. Withdraw and restore a pool. Expect DNS publication to change without corrupting another pool.
11. Confirm traffic to an unrelated cell/domain remains healthy throughout.

### Phase 6 completion gate

| Gate | Result |
| --- | --- |
| Implementation | Present |
| Documentation | Current |
| Automated/runtime qualification | Record PHP and security real-runtime evidence |
| Manual browser/real attack-pattern qualification | Pass only when every Phase 6 step is recorded |

## Phase 7: analytics and usage

1. Generate controlled DNS and HTTP traffic, cache hits/misses, origin failure,
   TLS, and security events.
2. As the domain user, open **Observe → Analytics**.
3. Check summary, timeseries, status, cache, countries, hostnames, URLs, origin,
   edges, and DNS with range and unit labels.
4. Open request, DNS, error, and security logs where surfaced. Expect opaque
   pagination, no query strings/secrets, and masked IPv4/IPv6.
5. Download domain usage CSV. Expect stable columns and only the assigned domain.
6. As administrator, open **Observe → Telemetry** and global views/export.
7. Stop ClickHouse in the controlled environment. Expect a visible outage, not
   a broken DNS or edge service.
8. Restore ClickHouse and confirm Vector backlog behaviour and partial interval labels.

### Phase 7 completion gate

| Gate | Result |
| --- | --- |
| Implementation | Present |
| Documentation | Current |
| Automated/runtime qualification | Record PHP and analytics outage/recovery evidence |
| Manual browser qualification | Pass only when every Phase 7 step is recorded |

## Phase 8: operations and release

1. Open the administrator dashboard. Confirm overall status, component detail,
   all four queue lanes, recent operations, and no secret values.
2. Open **Operations**. Inspect pending, succeeded, and failed rows; retry one supported failure.
3. Open failed jobs through the API/operational surface. Confirm payloads are bounded and redacted.
4. Open **Platform settings**. Change a non-runtime group and a runtime group.
5. Expect typed bounds, audit history, and a global operation only for runtime-affecting policy.
6. Create a backup. Expect snapshot metadata and verification without download.
7. Start restore preflight with exact confirmation and current password.
8. On an isolated host, perform the maintenance restore and complete the recovery checklist.
9. Run one canary upgrade and rollback with prior/current compatible images.
10. Verify desktop/mobile layout, keyboard operation, error focus, tables,
    destructive confirmations, CSV downloads, and one-time secret boundaries.

### Phase 8 completion gate

| Gate | Result |
| --- | --- |
| Implementation | Present |
| Documentation | Current |
| Automated/runtime qualification | Record operations, recovery, upgrade, throughput, MMDB, Compose, and image evidence |
| Manual browser/production qualification | Pass only when every Phase 8 and external release step is recorded |

## Result record

For each failed checkpoint, record expected result, actual result, sanitized
evidence, operation/task/revision IDs, severity, owner, and retest. The release
remains unqualified until every current checkpoint passes or an explicit
product-contract change removes it.
