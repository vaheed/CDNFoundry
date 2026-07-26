---
title: Incident runbooks
description: Recover DNS, edge, origin, TLS, cache, telemetry, and security incidents without losing last-valid state.
---

# Incident runbooks

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

## ClickHouse or Vector outage

1. Confirm DNS and HTTP still serve.
2. Expect analytics HTTP 503 and visible partial/outage labels.
3. Inspect Vector buffer bytes, discarded events, and delivery errors.
4. Repair ClickHouse first; restart Vector only if delivery does not resume.
5. Confirm backlog drains without resource starvation.
6. Record the telemetry-loss interval; do not invent missing usage.

## Security incident

1. Identify the domain, edge, cell, or pool and reason codes.
2. Apply the smallest bounded emergency action with an expiry where possible.
3. For a noisy domain, move target-first to quarantine.
4. Withdraw a service IP only when its pool cannot safely serve.
5. Confirm unrelated cells and domains remain healthy.
6. Clear controls explicitly and observe `recovering` before `normal`.

Volumetric saturation requires upstream provider or network mitigation.

## Queue backlog

1. Identify which of the four lanes is deep or old.
2. Inspect failed jobs and oldest operation types.
3. Repair the dependency before retrying.
4. Add workers only for that lane and within database/external API capacity.
5. Confirm bulk work is not starving runtime or interactive work.
