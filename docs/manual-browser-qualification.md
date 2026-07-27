---
title: Manual browser qualification
description: Owner-run browser and operator checkpoints for the post-baseline CDNFoundry roadmap.
---

# Manual browser qualification

## Purpose

This document mirrors `docs/roadmap.md` and covers only the new post-baseline
phases. The completed original platform remains covered by regression checks and
is not repeated as roadmap work here.

Browser qualification is owner-run. Coding agents maintain this checklist but
must report it as not executed unless the owner records the evidence.

Do not invent UI controls before they exist. During implementation, replace each
phase's activation contract with exact menu names, fields, buttons, URLs, and
expected results from the real UI.

## Evidence template

Record the following for every qualification run:

```text
Date:
Operator:
Commit SHA:
Release/image tags:
Environment:
Control-plane URL:
Browser/version:
Desktop viewport:
Mobile viewport:
Edges/POPs:
Cell slots per edge:
Pools and routing modes:
Public IPv4/IPv6 endpoints:
Test domains:
Origin fixtures:
Start time:
End time:
Overall result: passed / failed / blocked
```

For every checkpoint, record:

- expected result;
- actual result;
- sanitized screenshot or screen recording;
- relevant operation, revision, edge, pool, cell, endpoint, certificate, purge,
  origin, WAF, or rollout identifier;
- browser console/network error when relevant;
- failure severity and owner;
- retest evidence.

Never record passwords, tokens, API keys, private keys, signing keys, backup
credentials, raw customer telemetry, or customer data.

## Global preparation

1. Use a disposable or approved production-like environment.
2. Preserve persistent Compose volumes and existing development data.
3. Confirm the exact commit and immutable image tags under qualification.
4. Confirm implemented health/readiness endpoints are healthy.
5. Prepare one administrator and one disposable domain user.
6. Prepare delegated test domains and IPv4/IPv6 origin fixtures.
7. Prepare one failing origin and approved external DNS/HTTP vantage points.
8. Use desktop and narrow mobile viewports.
9. Check keyboard navigation, focus, labels, validation focus, responsive tables,
   loading, empty, degraded, error, destructive-confirmation, and one-time-secret states.
10. Confirm no secret appears in page source, browser storage beyond intended
    session data, console output, rendered errors, audit details, or exports.
11. Run the completed baseline regression smoke suite before releasing any new phase.

# Phase 1 qualification — Edge gateway ingress

Activate this section when gateway endpoint and routing management exist in the UI.

- [ ] Create or edit an edge gateway configuration.
- [ ] Add valid IPv4 and IPv6 service addresses.
- [ ] Confirm duplicate, malformed, and conflicting addresses are rejected.
- [ ] Inspect generated Host/SNI routing state without exposing secrets.
- [ ] Apply a valid routing revision and observe pending, active, and acknowledged states.
- [ ] Attempt an invalid revision and confirm the previous valid map remains active.
- [ ] Send real HTTP Host traffic to each configured service address.
- [ ] Send real HTTPS SNI traffic to each configured service address.
- [ ] Confirm unknown Host, unknown SNI, and unassigned destination traffic are rejected.
- [ ] Restart the gateway and confirm active routing is restored.
- [ ] Verify gateway health, listener, revision, connection, and error visibility.
- [ ] Confirm existing baseline edge traffic remains functional.

**Phase 1 result:** passed / failed / blocked / not activated

# Phase 2 qualification — Bounded cell inventory

Activate this section when bounded cell-slot installation and inventory exist.

- [ ] Configure a bounded slot count for a disposable edge.
- [ ] Install or reconcile the edge and confirm exactly that number of slots exists.
- [ ] Confirm every slot has unique identity, ports, paths, health, and resource limits.
- [ ] Inspect assigned, unassigned, ready, degraded, drained, and stopped states.
- [ ] Assign and unassign a slot through the supported workflow.
- [ ] Drain and restore one slot.
- [ ] Restart one slot and confirm unrelated slots continue serving.
- [ ] Trigger a controlled unhealthy slot and confirm gateway and agent remain healthy.
- [ ] Confirm the agent UI/API exposes no unrestricted container command surface.
- [ ] Verify cache, temporary, and log quota visibility.
- [ ] Confirm enrollment, mTLS identity, and snapshot recovery regression checks pass.

**Phase 2 result:** passed / failed / blocked / not activated

# Phase 3 qualification — Multi-cell pools and placement

Activate this section when pool membership and placement management exist.

- [ ] Create or inspect shared, reserved, dedicated, and quarantine pool kinds.
- [ ] Add at least three cells from one edge to one shared pool.
- [ ] Configure minimum-ready-cell and capacity policy.
- [ ] Assign several test domains and confirm stable placement.
- [ ] Add an unrelated domain and confirm existing placements do not reshuffle unnecessarily.
- [ ] Confirm a dedicated pool rejects a second domain.
- [ ] Move a domain to another cell and observe target preparation before route switch.
- [ ] Force target preparation failure and confirm source placement remains active.
- [ ] Complete a successful move and confirm source state is removed only after cutover.
- [ ] Move a domain into and out of quarantine.
- [ ] Confirm non-participating cells do not receive domain artifacts.
- [ ] Verify unrelated domains and caches remain healthy during movement.

**Phase 3 result:** passed / failed / blocked / not activated

# Phase 4 qualification — Pool endpoints and Geo-Unicast

Activate this section when pool endpoint management exists.

- [ ] Add a dual-stack service endpoint to a shared pool on one edge.
- [ ] Add a different service pair to a reserved pool on the same edge.
- [ ] Confirm management and service addresses remain distinct.
- [ ] Confirm address conflicts are rejected before activation.
- [ ] Enable the endpoints and observe gateway binding and readiness.
- [ ] Verify system-managed DNS publishes only ready endpoints.
- [ ] Test country, continent, and fallback Geo-Unicast answers.
- [ ] Withdraw one endpoint and confirm only its pool changes.
- [ ] Restore the endpoint and confirm DNS and gateway state converge.
- [ ] Test IPv4-only, IPv6-only, and dual-stack endpoint behavior.
- [ ] Restart/reconcile gateway, DNS, and cells and confirm consistent state.

**Phase 4 result:** passed / failed / blocked / not activated

# Phase 5 qualification — Simple Anycast

Activate this section only when an approved multi-POP routing environment exists.

- [ ] Create or edit an eligible pool with Simple Anycast routing mode.
- [ ] Configure one shared IPv4 and optional IPv6 service pair.
- [ ] Attach at least two participating POPs/edges.
- [ ] Confirm the same pair is bound locally on each participating edge.
- [ ] Confirm assigned domains publish the shared pair.
- [ ] Verify ready, degraded, and withdrawn states are clear.
- [ ] Test traffic from approved external vantage points.
- [ ] Remove one POP through the external routing process and record convergence.
- [ ] Confirm another POP continues serving valid local state.
- [ ] Restore the POP and record route and serving recovery.
- [ ] Confirm the UI states that the operator/provider owns BGP advertisement.
- [ ] Confirm no router credentials or arbitrary routing command surface exists.

**Phase 5 result:** passed / failed / blocked / not activated

# Phase 6 qualification — Cache v2

Activate this section when Cache v2 settings and status exist.

- [ ] Select each available bounded pool cache profile.
- [ ] Configure cache size, temporary quota, inactive time, object limit, and free-space policy.
- [ ] Exercise include-all, ignore-all, include-selected, and ignore-selected query policies.
- [ ] Confirm deterministic cache-key behavior with repeated requests.
- [ ] Verify approved status-code TTL policies.
- [ ] Confirm MISS, HIT, BYPASS, STALE, and revalidation states where applicable.
- [ ] Exercise stale-if-error and stale-while-revalidate.
- [ ] Exercise cache-only and stale-only emergency modes.
- [ ] Restart a cell and confirm persistent cache behavior.
- [ ] Execute exact URL purge and full epoch purge.
- [ ] Trigger low-disk and high-variant protection in the approved lab.
- [ ] Confirm one domain cannot exhaust unrelated cache resources beyond declared limits.
- [ ] Verify disk, temporary storage, admissions, evictions, and hit-ratio visibility.

**Phase 6 result:** passed / failed / blocked / not activated

# Phase 7 qualification — Gzip and Brotli

Activate this section when compression profiles and telemetry exist.

- [ ] Test off, standard, and maximum-savings profiles where permitted.
- [ ] Request identical content with identity, Gzip, and Brotli support.
- [ ] Confirm decoded content is identical.
- [ ] Confirm one canonical cache object serves different encodings correctly.
- [ ] Verify MIME allowlist and minimum-size behavior.
- [ ] Confirm images, video, archives, and other compressed formats are not recompressed.
- [ ] Test HEAD, 304, ETag, Vary, stale, and purge behavior.
- [ ] Test range and large-response handling.
- [ ] Trigger compression concurrency or CPU-pressure limits in the approved lab.
- [ ] Confirm safe fallback or emergency disable without traffic interruption.
- [ ] Verify encoding, ratio, origin bytes, served bytes, savings, and fallback telemetry.
- [ ] Confirm shared pools cannot select unsafe compression settings.

**Phase 7 result:** passed / failed / blocked / not activated

# Phase 8 qualification — Origin failover

Activate this section when backup-origin configuration exists.

- [ ] Configure one valid primary and one valid backup origin.
- [ ] Confirm unsafe, looping, private/disallowed, and malformed origins are rejected.
- [ ] Verify normal traffic uses the primary.
- [ ] Trigger a qualified primary failure and observe transition to backup.
- [ ] Confirm transition reason and timestamps are visible without secrets.
- [ ] Restore primary health and verify hold-down and delayed failback.
- [ ] Confirm repeated health changes do not cause flapping.
- [ ] Fail both origins and verify stale, cache-only, or maintenance behavior.
- [ ] Stop the control plane and confirm local failover behavior remains available.
- [ ] Attempt invalid backup changes and confirm active valid state remains.
- [ ] Confirm one failing origin does not exhaust unrelated origin budgets.

**Phase 8 result:** passed / failed / blocked / not activated

# Phase 9 qualification — Managed OWASP CRS WAF

Activate this section when managed WAF profiles exist.

- [ ] Apply off, monitor, balanced, and strict profiles to approved test domains/pools.
- [ ] Confirm monitor mode records safe test detections without blocking.
- [ ] Confirm blocking profiles reject approved test attack patterns with stable reasons.
- [ ] Verify privacy-safe rule ID, category, score, action, and timing visibility.
- [ ] Add an approved bounded exclusion with reason, owner, and expiry.
- [ ] Confirm the exclusion affects only its intended scope.
- [ ] Confirm expired exclusions stop applying and remain audited.
- [ ] Verify arbitrary rules, raw directives, uploads, and runtime downloads are unavailable.
- [ ] Test oversized and malformed body limits in the approved lab.
- [ ] Run a monitor-only canary of a new WAF image/ruleset.
- [ ] Force canary failure and confirm the previous valid WAF runtime remains active.
- [ ] Confirm unrelated non-WAF pools remain healthy during WAF load or failure.

**Phase 9 result:** passed / failed / blocked / not activated

# Phase 10 qualification — Observability and capacity

Activate this section when the new operational views and analytics exist.

- [ ] Inspect gateway listener, route, map, connection, error, and revision status.
- [ ] Inspect service endpoint, Anycast, pool readiness, and placement status.
- [ ] Inspect cell CPU, memory, connections, cache disk, temporary space, and saturation.
- [ ] Inspect compression bytes, ratios, concurrency, and fallback.
- [ ] Inspect origin health, active origin, failover, and failback state.
- [ ] Inspect WAF profile, action, score, rule category, and processing cost.
- [ ] Confirm domain users see only assigned-domain data.
- [ ] Confirm raw logs and exports remain bounded and redacted.
- [ ] Stop ClickHouse/Vector and confirm serving continues with visible degraded analytics.
- [ ] Restore telemetry and verify bounded backlog recovery.
- [ ] Trigger representative alerts and confirm each links to an actionable runbook.
- [ ] Confirm queries remain responsive at the qualification dataset.

**Phase 10 result:** passed / failed / blocked / not activated

# Phase 11 qualification — Fleet rollout automation

Activate this section when rollout management exists.

- [ ] Inspect desired and current gateway, agent, normal-cell, and WAF-cell versions.
- [ ] Configure one canary edge/POP and later rollout waves.
- [ ] Start a canary rollout and observe progress and health gates.
- [ ] Confirm later waves do not start before canary success.
- [ ] Force a controlled canary failure and confirm automatic pause.
- [ ] Confirm the failure and pause reason are visible.
- [ ] Roll back and verify the previous compatible runtime is restored.
- [ ] Resume a successful rollout and verify bounded mixed-version serving.
- [ ] Confirm version drift and incompatibility are visible.
- [ ] Confirm no arbitrary command execution or unbounded container creation is exposed.
- [ ] Verify every rollout, pause, resume, and rollback is audited.

**Phase 11 result:** passed / failed / blocked / not activated

# Phase 12 qualification — Final production release

Activate this section after Phases 1 through 11 are implemented and individually qualified.

- [ ] Record at least two POPs/edges and at least eight cell slots per edge.
- [ ] Record one multi-cell shared pool, one reserved pool, and one quarantine pool.
- [ ] Verify several service pairs on the same edge.
- [ ] Verify Geo-Unicast and approved Simple Anycast traffic externally.
- [ ] Verify stable placement, migration, drain, quarantine, rollback, and recovery.
- [ ] Verify Cache v2 persistence, purge, stale behavior, and pressure controls.
- [ ] Verify Gzip and Brotli behavior and savings.
- [ ] Verify primary/backup origin failover and failback.
- [ ] Verify managed WAF monitor, block, exclusion, canary, and rollback.
- [ ] Stop selected control-plane and telemetry dependencies and confirm existing serving continues.
- [ ] Apply invalid gateway, cell, and WAF candidates and confirm last-valid state.
- [ ] Saturate one approved lab cell and confirm unrelated pools continue.
- [ ] Perform fleet canary upgrade and rollback.
- [ ] Perform clean-host control-plane restore and derived-state reconciliation.
- [ ] Run the completed baseline regression smoke suite.
- [ ] Confirm all relevant documentation and runbooks match the final product.
- [ ] Record measured limits, topology, hardware, RPO, RTO, throughput, latency,
      saturation points, known limitations, and unresolved risks.
- [ ] Confirm no unresolved critical or high-severity failure remains.

**Phase 12 result:** passed / failed / blocked / not activated

## Release record

```text
Roadmap phase:
Implementation: passed / failed / blocked
Unit and feature tests: passed / failed / blocked / not applicable
Real-runtime E2E: passed / failed / blocked / not applicable
IPv4 and IPv6: passed / failed / blocked / not applicable
Scale: passed / failed / blocked / not applicable
Failure and recovery: passed / failed / blocked / not applicable
Isolation: passed / failed / blocked / not applicable
Observability: passed / failed / blocked / not applicable
Documentation: passed / failed / blocked
Manual qualification: passed / failed / blocked
Baseline regression: passed / failed / blocked
Release decision: release / do not release
Evidence links:
Known limitations:
Owner approval:
```
