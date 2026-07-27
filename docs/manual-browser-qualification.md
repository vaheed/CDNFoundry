---
title: Manual browser qualification
description: Owner-run browser and operator checkpoints aligned with the CDNFoundry product roadmap.
---

# Manual browser qualification

## 1. Purpose and ownership

This is the owner-run browser qualification contract for CDNFoundry.

Coding agents:

- maintain this file when implemented UI or operator behavior changes;
- do not launch or automate Chromium, Playwright, Selenium, Cypress, or another browser;
- report browser qualification as not run unless the owner supplies evidence.

A roadmap phase is not release-complete until its active browser section passes.

Planned roadmap phases must not invent screens, fields, or menu names before implementation. Their qualification contracts are activated and expanded into exact UI steps in this file when the corresponding UI exists.

## 2. Evidence record

For every run, record:

```text
Date:
Operator:
Commit SHA:
Release/image tags:
Environment:
Control-plane URL:
Topology:
DNS clusters:
Edges and POPs:
Cell slots per edge:
Pool routing modes:
Public IPv4/IPv6:
Browser and version:
Desktop viewport:
Mobile viewport:
Test domains:
Origin fixtures:
Start time:
End time:
Result: passed / failed / blocked
```

For each checkpoint, record:

- expected result;
- actual result;
- sanitized screenshot or screen recording;
- operation, task, revision, pool, cell, edge, certificate, purge, or backup identifier;
- browser console/network error when relevant;
- severity and owner for failures;
- retest evidence.

Never include passwords, tokens, API keys, private keys, CA keys, signing keys, backup credentials, raw customer telemetry, or customer data.

## 3. Global preparation

1. Use a disposable or approved production-like environment without deleting persistent Compose volumes.
2. Apply Laravel and PowerDNS migrations explicitly.
3. Confirm `/api/health`, `/api/ready`, DNSdist UDP/TCP, Horizon, scheduler, PostgreSQL, Redis/Valkey, ClickHouse, Vector, MMDB, registered edges, cells, and gateway health where implemented.
4. Create or identify one administrator and one disposable domain user.
5. Prepare delegated test domains, IPv4/IPv6 origin fixtures, one failing origin, and approved external DNS/HTTP vantage points.
6. Confirm the exact commit and immutable image tags being qualified.
7. Use desktop and narrow mobile viewports.
8. Confirm keyboard focus, labels, validation focus, table scrolling, empty/loading/degraded/error states, destructive confirmation, and one-time-secret boundaries throughout the run.
9. Confirm no secret appears in page source, browser storage beyond intended session/token data, browser console, rendered errors, audit details, or downloadable exports.

# Active qualification — implemented baseline

## Phase 1 — Foundation, access, and system identity

### Administrator and domain user

1. Sign in to the administrator panel.
2. Create a user with a unique disposable email and type `User`.
3. Confirm the user appears once and no plaintext password is displayed after save.
4. Disable the user and confirm login to the domain-user panel is denied.
5. Re-enable the user and confirm login succeeds.
6. Confirm an administrator cannot disable, demote, or delete their own active account.
7. Confirm a domain user cannot open administrator routes or see global resources.

### Profile, password, and tokens

1. Open the profile surface in both panels.
2. Change the display name and confirm shared navigation/account UI updates.
3. Change the password using the current password and matching confirmation.
4. Create a named API token.
5. Confirm plaintext token appears once only.
6. Reload and confirm only safe metadata is visible.
7. Revoke the token and confirm API use fails.

### System DNS identity

1. Open the system DNS identity surface.
2. Enter a platform zone, two nameservers, IPv4/IPv6 glue, proxy hostname, SOA mailbox, and bounded timing values.
3. Preview and record the normalized records and confirmation receipt.
4. Change one field and attempt to apply the old confirmation. Expect rejection.
5. Preview again and apply the exact payload.
6. Record the asynchronous operation and confirm pending/running/succeeded states without blocking the page.
7. Confirm failed deployment keeps the previous active identity.

### Completion record

| Gate | Result |
| --- | --- |
| Implementation | |
| Documentation | |
| Automated/runtime tests | |
| Scale checkpoint | |
| Failure/recovery | |
| Manual browser | |
| Release decision | |

## Phase 2 — Domains and authoritative DNS

### DNS cluster

1. Create a DNS cluster with unique name, location, private API details, server ID, and nameserver data.
2. Confirm credentials are not displayed in table, detail, audit, or edit surfaces.
3. Test the connection and record the operation.
4. Enable the qualified cluster.
5. Reconcile zones and confirm desired/active revision and checksum visibility.
6. Introduce a controlled failure on a second target and confirm the healthy target retains valid state.

### Domain lifecycle and assignment

1. Add a delegated test domain without entering an origin.
2. Confirm pending verification, revision, nameserver guidance, and no proxy/TLS side effect.
3. Assign the disposable domain user.
4. Sign in as that user and confirm only assigned domains are visible.
5. Verify nameservers and activate the domain.
6. Confirm lifecycle and DNS deployment acknowledgement.
7. Exercise delayed deprovision/cancel behavior only in the disposable environment and confirm tombstone/reclaim safeguards.

### DNS records and bulk work

1. Add valid A, AAAA, CNAME, MX, TXT, NS, CAA, and SRV examples.
2. Confirm type-specific fields, normalized names, and TTL limits.
3. Attempt CNAME coexistence, out-of-zone owner, invalid underscore, duplicate, and malformed IPv4/IPv6 input. Expect inline rejection and no revision change.
4. Confirm a domain user cannot change protected apex delegation behavior.
5. Bulk edit/delete a bounded set and confirm one intended revision transition.
6. Import a bounded BIND zone in append mode and export deterministic text.
7. Import in replacement mode and confirm the final record set.
8. Confirm cursor pagination and useful empty/degraded states.

### External verification

1. Use real UDP and TCP `dig` against every qualified DNS cluster.
2. Test IPv4 and IPv6 client paths.
3. Confirm SOA serial, authoritative flags, record values, and negative answers.
4. Stop the control plane and confirm existing authoritative DNS continues.

### Completion record

| Gate | Result |
| --- | --- |
| Implementation | |
| Documentation | |
| Automated/runtime tests | |
| Scale checkpoint | |
| Failure/recovery | |
| Manual browser/external DNS | |
| Release decision | |

## Phase 3 — Geo-DNS

1. Create a Geo-DNS A record with default, continent, and country targets.
2. Preview with addresses matching each level and with an unknown/documentation address.
3. Confirm country wins before continent, then default.
4. Repeat using AAAA and IPv6 preview input.
5. Attempt duplicate geography, missing default, excessive targets, invalid address family, and unsupported record type. Expect rejection and no revision change.
6. Confirm the UI labels resolver/ECS accuracy honestly.
7. Verify from approved external vantage points and record ECS or resolver-based behavior.
8. Interrupt MMDB update/provider access and confirm the last valid database remains active.

### Completion record

| Gate | Result |
| --- | --- |
| Implementation | |
| Documentation | |
| Automated/runtime tests | |
| Scale checkpoint | |
| Failure/recovery | |
| Manual browser/external vantage | |
| Release decision | |

## Phase 4 — Proxy, baseline edge pools, and edge agent

### Pools and edges

1. Create or inspect shared, quarantine, and exceptional dedicated pools.
2. Create two edges in different locations with unique IPv4 and optional IPv6 management/default addresses.
3. Record each bootstrap token once and confirm it disappears after navigation.
4. Enroll real agents and confirm identity, version, heartbeat, active sequence, cell state, and bounded capacity.
5. Rotate one identity and confirm the old identity is rejected.
6. Restore the edge and exercise drain/undrain.
7. Confirm routing and status change without affecting the other edge.

### Proxied hostname and origin

1. Enable proxy for a valid hostname.
2. Configure explicit origin scheme, host, port, Host header, SNI, TLS verification, timeouts, retry bound, WebSocket option, and health path where supported.
3. Confirm platform-managed DNS content and visible safe origin metadata.
4. Attempt loopback, private/disallowed, link-local, metadata, multicast, platform, edge-service, and proxy-loop destinations. Expect rejection.
5. Run the asynchronous origin test and record resolved address, status, latency, TLS result, or stable failure reason.
6. Confirm signed edge delivery acknowledgement.
7. Move the domain to quarantine and confirm target ready before source drain.
8. Roll back to a retained revision and confirm a new higher revision.

### Real traffic

1. Send HTTP and HTTPS through each eligible edge using correct Host/SNI.
2. Test IPv4 and IPv6.
3. Confirm unknown Host/SNI rejection.
4. Stop Laravel/queues temporarily and confirm existing traffic continues.
5. Submit an invalid artifact in the controlled runtime test and confirm the previous state remains active.

### Completion record

| Gate | Result |
| --- | --- |
| Implementation | |
| Documentation | |
| Automated/runtime tests | |
| Scale checkpoint | |
| Failure/recovery | |
| Manual browser/real traffic | |
| Release decision | |

## Phase 5 — TLS, baseline cache, and purge

### Managed and custom TLS

1. Enable proxy for an eligible active domain and open its TLS surface.
2. Confirm managed issuance state without private material.
3. Verify public apex/wildcard HTTPS as applicable and record certificate fingerprint, names, issuer, and expiry.
4. Exercise renew and reissue operations.
5. Upload a valid leaf, chain, and matching key and confirm only safe metadata remains visible.
6. Attempt wrong key, wrong name, invalid chain, expired certificate, and oversized PEM. Expect rejection.
7. Remove the custom certificate and confirm managed reconciliation.
8. Confirm a current valid certificate continues during a controlled ACME failure.

### Cache and purge

1. Configure enabled state, edge/browser TTL, object size, origin-header policy, query behavior, bypass cookies, and stale grace.
2. Save and confirm edge acknowledgement.
3. Request a cacheable object twice and confirm MISS then HIT.
4. Confirm browser headers and deterministic cache key behavior.
5. Enable short development mode and confirm absolute expiry and BYPASS.
6. Disable it and confirm caching resumes.
7. Purge one exact URL and confirm bounded target tasks and a later MISS.
8. Purge all and confirm epoch increment without filesystem scan.
9. Cause one controlled delivery failure and confirm safe retry of the same durable task.

### Completion record

| Gate | Result |
| --- | --- |
| Implementation | |
| Documentation | |
| Automated/runtime tests | |
| Scale checkpoint | |
| Failure/recovery | |
| Manual browser/public HTTPS | |
| Release decision | |

## Phase 6 — Security and DDoS readiness

1. Select standard, protected, quarantine, and manual profiles.
2. Confirm fixed profiles cannot exceed their values and manual input remains within platform ceilings.
3. Configure quarantine policy, allowed methods, and trusted proxy CIDRs.
4. Add ordered IP, CIDR, country, and continent allow/block rules.
5. Import bounded rules in append and replacement modes.
6. Send matching IPv4 and IPv6 traffic and confirm action/reason visibility.
7. Exercise restrict, quarantine, recover, and release.
8. Confirm target-first placement and safe recovery state.
9. Apply an expiring emergency action and confirm persistence across restart, expiry, and audit.
10. Withdraw and restore a pool service address from DNS.
11. Confirm an unrelated domain/cell remains healthy during controlled abuse traffic.
12. Confirm the UI and documentation do not claim protection after physical uplink saturation.

### Completion record

| Gate | Result |
| --- | --- |
| Implementation | |
| Documentation | |
| Automated/runtime tests | |
| Scale/isolation checkpoint | |
| Failure/recovery | |
| Manual browser/attack-pattern traffic | |
| Release decision | |

## Phase 7 — Telemetry, analytics, and usage export

1. Generate controlled DNS, HTTP, HTTPS, cache, origin, TLS, security, edge, and deployment events.
2. As the domain user, open analytics and confirm only assigned-domain data.
3. Verify summary, time series, units, ranges, status, cache, geography, hostname, URL, origin, edge, and DNS views that are currently implemented.
4. Inspect available raw request, DNS, error, and security logs.
5. Confirm bounded pagination, masking/redaction, and no secrets/query data beyond the documented schema.
6. Download domain usage JSON/CSV and confirm stable columns and scope.
7. As administrator, inspect global telemetry, edge/cell health, drops, and buffer state.
8. Stop ClickHouse and confirm visible analytics outage while DNS/HTTP continues.
9. Restore it and confirm bounded backlog recovery and partial-data labeling.

### Completion record

| Gate | Result |
| --- | --- |
| Implementation | |
| Documentation | |
| Automated/runtime tests | |
| Scale/query checkpoint | |
| Failure/recovery | |
| Manual browser | |
| Release decision | |

## Phase 8 — Operations, recovery, and baseline release

1. Inspect the administrator dashboard and confirm component, queue, edge, cell, DNS, TLS, backup, and recent-operation states currently implemented.
2. Inspect pending, succeeded, and failed operations and retry one supported failure.
3. Inspect failed-job information and confirm payload redaction and bounds.
4. Change one non-runtime typed setting and one runtime-affecting setting.
5. Confirm only the runtime change creates deployment/reconciliation work.
6. Create and verify an encrypted backup.
7. Perform restore preflight with exact confirmation and re-authentication.
8. Complete the documented clean-host maintenance restore in an isolated environment.
9. Run one compatible canary upgrade and rollback.
10. Confirm desktop/mobile layout, keyboard use, error focus, pagination, downloads, confirmations, and one-time secrets.
11. Record RPO, RTO, hardware, topology, throughput, saturation, and unresolved limitations.

### Completion record

| Gate | Result |
| --- | --- |
| Implementation | |
| Documentation | |
| Automated/runtime tests | |
| Scale/throughput checkpoint | |
| Backup/recovery/upgrade | |
| Manual browser/production | |
| Release decision | |

# Planned qualification contracts

The following sections are inactive until their implementation exists. They define required outcomes without inventing menu names, pages, fields, or controls. When a phase is implemented, replace its section with exact browser steps derived from the real UI and keep every checkpoint below.

## Phase 9 — Edge gateway and bounded cell inventory

Required owner-visible outcomes:

- installed cell-slot inventory, stable cell identity, assignment state, resource limits, health, active revision, and last report are inspectable;
- gateway service addresses, listeners, Host/SNI map revision, health, and last-valid state are inspectable;
- agent enrollment does not expose Docker control or create unbounded containers;
- drain, restart, invalid-map rejection, and rollback operations provide durable status and audit;
- desktop/mobile and degraded/empty/error states are usable.

Required real-traffic evidence:

- one public service IP pair routes HTTP Host and HTTPS SNI to the expected cells;
- unknown/mismatched traffic is rejected;
- client address preservation is correct;
- one cell restart does not interrupt unrelated cells;
- invalid gateway state preserves the previous map.

Activation rule: write exact UI steps when Phase 9 surfaces are implemented.

## Phase 10 — Multi-cell pools, endpoints, and stable placement

Required owner-visible outcomes:

- pool kind, participating edges, service endpoints, assigned cells, minimum-ready policy, capacity, and domain placement are inspectable;
- shared, reserved, dedicated, and quarantine constraints are enforced;
- slot assignment, release, drain, migration, and rollback have operation status and audit;
- only participating targets show deployment/acknowledgement.

Required real-traffic evidence:

- one IPv4/IPv6 pair fronts three shared cells;
- another pair fronts reserved customer cells on the same edge;
- stable domain placement preserves cache locality;
- migration is target-first and unrelated domains remain healthy;
- cell shortage and protected capacity reservations fail safely.

Activation rule: write exact UI steps when Phase 10 surfaces are implemented.

## Phase 11 — Geo-Unicast and Simple Anycast

Required owner-visible outcomes:

- routing mode, shared Anycast addresses, participating POPs, gateway/cell/revision readiness, route-advertised signal, and withdrawal state are inspectable;
- Geo controls are hidden or unavailable for Anycast pools;
- BGP neighbor credentials/configuration are absent;
- readiness and failure ordering are clear and audited.

Required external evidence:

- Geo-Unicast returns ready location endpoints;
- Simple Anycast returns one shared IPv4/IPv6 pair;
- multiple POPs serve the same active revision;
- failed POP withdrawal precedes exclusion;
- healthy POPs continue;
- control-plane outage does not stop valid externally routed traffic.

Activation rule: write exact UI steps when Phase 11 surfaces are implemented.

## Phase 12 — Cache v2 and compression

Required owner-visible outcomes:

- cache resource profile, persistent quota, free-space threshold, inactive period, object-size limit, query policy, cookie bypass, status TTL, stale policy, and compression profile are inspectable and bounded;
- Gzip/Brotli availability and effective profile are clear;
- disk, admission, variant, compression CPU, savings, purge, and degradation states are visible;
- emergency compression disable and retry/rollback are audited.

Required real-traffic evidence:

- identity, Gzip, and Brotli clients receive identical decoded content;
- cache remains canonical without accidental `Accept-Encoding` fragmentation;
- MIME, size, range, and precompressed exclusions work;
- MISS/HIT/STALE/revalidate/purge remain correct;
- disk/CPU pressure is bounded and unrelated cells remain healthy.

Activation rule: write exact UI steps when Phase 12 surfaces are implemented.

## Phase 13 — Simple origin resilience

Required owner-visible outcomes:

- primary/backup origin, health evidence, effective origin, failover/recovery state, thresholds, last transition, and audit are inspectable;
- both origins use the same safety validation;
- no weighted or percentage routing controls exist.

Required real-traffic evidence:

- healthy primary serves;
- qualified failure switches to backup;
- flapping is bounded;
- failed backup cannot loop retries;
- controlled primary recovery works;
- cache and unrelated domains remain correct.

Activation rule: write exact UI steps when Phase 13 surfaces are implemented.

## Phase 14 — Managed OWASP CRS WAF

Required owner-visible outcomes:

- off, monitor, balanced, and strict profiles are available only where supported;
- effective pinned engine/ruleset versions, WAF-capable pool/cell state, anomaly/action metrics, exclusions, expiry, audit, and rollout state are inspectable;
- exclusions are narrow and bounded;
- no raw ModSecurity or custom rule-language editor exists.

Required real-traffic evidence:

- monitor records but does not block;
- balanced/strict block qualified test cases;
- false-positive exclusion works only in its declared scope;
- invalid ruleset preserves the previous image/rules;
- WAF load is isolated from normal pools;
- canary rollout pauses and rolls back.

Activation rule: write exact UI steps when Phase 14 surfaces are implemented.

# Future admission

Phases 15 and 16 remain future candidates. Do not add browser checkpoints until a capability is admitted, implemented, and has real UI behavior.

# Final result record

For every failed checkpoint record:

```text
Phase:
Checkpoint:
Expected:
Actual:
Evidence:
Related IDs:
Severity:
Owner:
Decision:
Retest date:
Retest result:
```

A release remains unqualified until every active checkpoint passes or the product contract explicitly removes it.
