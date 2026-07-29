---
title: Incident runbooks
description: Recover DNS, edge, origin, TLS, cache, telemetry, and security incidents without losing last-valid state.
---

# Incident runbooks

## Stale edge map or endpoint mismatch

1. Open **Edge network → Edges**, record the edge, desired endpoint revision,
   gateway revision, last heartbeat, and candidate-rejection reason.
2. Confirm the edge agent and gateway are healthy. Do not withdraw unrelated
   pools.
3. Correct the candidate or connectivity failure and run bounded edge
   reconciliation. A bad candidate must leave the previous map serving.
4. Escalate if the revision remains mismatched for two minutes after a healthy
   heartbeat.

## Cell cache or compression pressure

1. Identify the exact edge, pool, cell, resource, and ratio from the alert.
2. Confirm whether cache, memory, connections, or temporary storage exceeded
   80%. Keep a comparison pool under traffic.
3. Drain only the affected cell if it cannot recover. Never create a replacement
   container dynamically.
4. Add a pre-provisioned slot or capacity only after recording saturation.

## Paused fleet rollout

1. Fetch the rollout and record its wave, pause reason, failed edge, desired and
   current immutable digests, readiness, revision, and capacity.
2. Do not resume while an edge is failed. Correct readiness or start rollback
   to the recorded compatible release.
3. Confirm the canary or rollback wave is healthy before later waves proceed.
4. Verify unrelated edges continue serving during the mixed-version window.

## DNS cluster failure

1. Check DNSdist backend state and query UDP and TCP directly.
2. Inspect the cluster health result and failed deployment without revealing its API key.
3. Leave healthy clusters enabled; do not replace their zones.
4. Repair TLS trust, allowlist, API URL, key, PowerDNS, or PostgreSQL.
5. Run the cluster test, enable only after success, then reconcile DNS.
6. Verify SOA serial monotonicity and active checksum.

Do not publish PowerDNS port 8081 or copy drift from PowerAdmin.

## Edge or cell failure

1. Check edge heartbeat, listener readiness, cell status, and capacity.
2. Drain the affected cell or edge so new DNS publication excludes it.
3. Keep healthy siblings serving.
4. Inspect last rejection, agent identity, status token, runtime file, memory,
   cache, temporary storage, and origin failures.
5. Restart only the affected cell through its durable task where possible.
6. Undrain after a fresh ready heartbeat and acknowledged revision.

If local state is lost, use full snapshot recovery. Do not copy another edge's
identity.

## Origin failure

1. Read the recorded origin health and stable failure reason.
2. Re-run the bounded asynchronous test.
3. Check DNS resolution from the edge, unsafe-address policy, SNI, certificate,
   host header, port, and timeouts.
4. Use stale content only inside the configured grace.
5. Correct desired origin state and wait for a new revision.

Do not allow a broad private CIDR to bypass built-in unsafe ranges.

## ACME failure

1. Inspect the latest order and challenge state.
2. Confirm the domain is active, delegated, verified, and proxied.
3. Verify challenge TXT acknowledgement on every DNS target.
4. Check CA directory access, account contact, clock, order budget, and names.
5. Repair the dependency, then use renew or reissue.

Keep any valid active certificate. Maintenance cleans expired challenge state.

## Cache purge failure

1. Inspect the purge and per-edge task status.
2. Confirm the target edge is enabled, fresh, and owns the domain's pool.
3. Check agent-to-cell status token and control endpoint.
4. Retry the same durable task or global purge reconciliation.
5. Verify epoch or exact keys on all cells.

Never scan or delete a cache volume for a normal full purge.

### Cache pressure or volume loss

1. Identify the affected cell, its pool profile, cache used/free bytes,
   temporary usage, IOPS, admissions, evictions, and unrelated-cell health.
2. If minimum free space is reached, keep traffic serving: new objects bypass
   or older/inactive objects evict. Do not delete individual domain paths; none
   exist.
3. Reduce admission pressure or select a smaller object/variant policy. A pool
   profile change is revisioned and reconciled asynchronously. Storage quota
   changes remain an operator deployment action.
4. For a corrupt/lost disposable cache volume, drain only that cell, replace
   only its named volume, start it, verify last-valid runtime restoration, then
   undrain. Content rebuilds from origins; PostgreSQL and purge epochs remain
   authoritative.
5. Escalate when temporary storage grows beyond its quota, resident HITs begin
   bypassing, an unrelated cell is affected, or origin load exceeds its bounded
   budget. Record the stable reason, revision, cache epoch, and time window.

## ClickHouse or Vector outage

1. Confirm DNS and HTTP still serve.
2. Expect analytics HTTP 503 and visible partial/outage labels.
3. Inspect Vector buffer bytes, discarded events, and delivery errors.
4. Repair ClickHouse first; restart Vector only if delivery does not resume.
5. Confirm backlog drains without resource starvation.
6. Record the telemetry-loss interval; do not invent missing usage.

## Security incident

1. Identify the smallest affected scope and its reason codes.
2. For one domain, use **Protect domain**, **Start maintenance**, or
   **Quarantine domain**. Quarantine moves target-first to the quarantine pool.
3. For one unhealthy cell or edge, use **Drain**; restart only the affected
   cell when required.
4. Use pool **Maintenance** only when every domain on that pool should return
   HTTP 503. It requires an automatic expiry.
5. Use pool **Withdraw** only when the whole pool must leave gateway and DNS
   publication. Coordinate BGP separately with the network provider.
6. Confirm unrelated cells, pools, and domains remain healthy.
7. Recover with **Return to normal**, **End maintenance**, **Undrain**, or
   **Restore**, and observe domain `recovering` before `normal`.

Volumetric saturation requires upstream provider or network mitigation.

## Queue backlog

1. Identify which of the four lanes is deep or old.
2. Inspect failed jobs and oldest operation types.
3. Repair the dependency before retrying.
4. Add workers only for that lane and within database/external API capacity.
5. Confirm bulk work is not starving runtime or interactive work.
