---
title: Incident runbooks
description: Recover DNS, edge, origin, TLS, cache, telemetry, and security incidents without losing last-valid state.
---

# Incident runbooks

Use this page when something is failing. Find the section that matches the symptom, then follow its steps. Each alert in Grafana links to its section in [Alert runbooks](#alert-runbooks). Most runbooks tell you to open a screen in the admin panel, such as **Infrastructure → Edges**, or a Grafana dashboard.

::: danger Preserve the last valid state
During diagnosis, do not delete the agent identity, reset revisions, edit derived runtime files, remove zones by hand, or erase named volumes. Capture evidence. Then repair the desired state or the failed external dependency.
:::

## Control node lost

DNS and HTTP keep serving from the last valid state; nothing in the data plane needs
action. Without a standby, restore from backup. With a standby, follow
[Control-plane high availability](./control-plane-high-availability.md):
confirm the node is down, run `cdnfoundry-fleet control promote --old-active-is-powered-off`,
apply the two record changes it prints, and keep the old node isolated until
`control rejoin`. A `promote` refusal means the old node still answers as active:
run `control demote` on it, or confirm that it is powered off.

## Stale edge map or endpoint mismatch

1. Open **Infrastructure → Edges**. Record the edge, desired endpoint revision,
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
3. Verify challenge TXT acknowledgement on every DNS target, then query each
   configured authoritative nameserver directly for the exact TXT value with
   the `aa` flag. DNSdist's supported configuration has no packet cache.
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

## Loki or operational-log collector outage

1. Confirm DNS, HTTP, edge activation, queues, and reconciliation continue; do
   not restart those services to repair observability.
2. Check Loki `/ready`, telemetry-gateway TLS/source allowlists, collector
   `component_id="loki"` failures, buffer ratio, non-intentional drops, and host
   filesystem usage.
3. If one host collector failed, preserve its `operational-vector-data` volume,
   repair only that collector, and confirm its stable `collector_id` did not
   change. If the host itself failed, logs not durably buffered before failure
   are unavailable.
4. If Loki failed, preserve `loki-data`, repair disk/permissions/configuration,
   and wait for `/ready`. Do not delete the volume to recover availability.
5. Confirm the bounded oldest-first buffers drain, new logs appear in Explore,
   and error/drop alerts clear. Events dropped after the 2 GiB buffer filled are
   irrecoverable; record the exact interval.
6. Verify compactor retention succeeds and the retention boundary advances (`LOKI_RETENTION_PERIOD`, 14 days in production). The
   standard control backup does not restore Loki.

## Security incident

1. Identify the smallest affected scope and its reason codes.
2. For one domain, use **Enable Under Attack mode**, **Start maintenance**, or
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

1. Identify which queue lane is deep or old. The lanes are listed in [Limits](../reference/limits.md#queue-lanes).
2. Inspect failed jobs and oldest operation types.
3. Repair the dependency before retrying.
4. Add workers only for that lane and within database/external API capacity.
5. Confirm bulk work is not starving runtime or interactive work.

## Alert runbooks

Every alert links here. Each section names the Grafana dashboard to open, the likely causes, and the first action. Grafana shows the dashboard folder in the alert.

## Watchdog stopped {#alerting-heartbeat}

**Open:** Now.

**Likely causes:** The alert pipeline is broken: Prometheus down, Alertmanager down, or the webhook unreachable.

**First action:** Open Prometheus and Alertmanager health first. Fix the notifier before anything else, because no other alert can be trusted until the heartbeat returns.

## Stale edge heartbeat {#stale-edge-heartbeat}

**Open:** PoP fleet.

**Likely causes:** The edge agent stopped, the host lost its route to the control plane, or the host is down. DNS still publishes the PoP.

**First action:** Check whether the PoP still serves (Synthetic probes). If it does not, drain it; if it does, restart only the agent.

## Pool serving the last published set {#pool-serving-last-published-set}

**Open:** PoP fleet.

**Likely causes:** Every endpoint of a pool looks unhealthy, so DNS keeps the last set instead of publishing nothing.

**First action:** Find out whether the endpoints or the health signal are wrong (PoP fleet, then Synthetic probes). Repair the endpoints; do not remove the pool.

## Edge identity certificate expiring {#edge-identity-certificate-expiring}

**Open:** PoP fleet.

**Likely causes:** Renewal failed for an edge, usually because the agent cannot reach the control plane.

**First action:** Check the agent log on that PoP and the control address list. Renewal happens on the next successful heartbeat.

## Control-plane metrics unavailable {#control-plane-metrics-unavailable}

**Open:** Control plane.

**Likely causes:** The web container is down, the metrics token changed, or Prometheus cannot reach the control node.

**First action:** Open the control plane dashboard and the Caddy and web logs. Confirm the token in the Prometheus secret matches the control plane.

## Component unhealthy {#component-unhealthy}

**Open:** Control plane.

**Likely causes:** A dependency or reconciliation component reports degraded. The component name is on the alert.

**First action:** Open the component in Control plane, read its reason, and follow the runbook for that component.

## Control backup stale {#backup-stale}

**Open:** Backups and recovery.

**Likely causes:** The backup job failed, the repository is unreachable, or the integrity check failed.

**First action:** Run the backup by hand and read the error. Do not delete the repository. See [Backup and recovery](./backup-and-recovery.md).

## Failed operations {#failed-operations}

**Open:** Control plane.

**Likely causes:** One or more operations failed inside the failed-operations window. The alert clears when the window passes.

**First action:** Open **Operations**, filter by failed, and read the stable error code. Repair the dependency the code names, then retry.

## Certificate expiring {#certificate-expiry}

**Open:** TLS posture.

**Likely causes:** A managed certificate was not renewed, or an uploaded certificate is near its end.

**First action:** Open TLS posture for the domain and names. Renew a managed certificate; ask the owner to upload a new one otherwise.

## Gateway candidate rejected {#gateway-candidate-rejected}

**Open:** PoP detail.

**Likely causes:** The gateway refused a new routing map. The previous map keeps serving.

**First action:** Read the rejection reason on the edge. Correct the desired state that produced the candidate.

## Host clock {#host-clock}

**Open:** Hosts and containers.

**Likely causes:** NTP is stopped or the host is far from the time source. Signed artifacts and certificates depend on time.

**First action:** Fix time synchronization on that host before restarting any CDNFoundry service.

## Operational error logs {#operational-error-logs}

**Open:** Logs explorer.

**Likely causes:** A service logs errors faster than usual, or a service that normally logs went silent.

**First action:** Open Logs explorer for that service and read the first error. A silent service is down or cannot ship logs.

## Host filesystem pressure {#host-filesystem-pressure}

**Open:** Hosts and containers.

**Likely causes:** Docker volumes, Loki, or ClickHouse data fill the root filesystem.

**First action:** Find the volume that grew (Hosts and containers). Add disk or lower the retention setting. Never delete a named volume.

## 5xx ratio high {#edge-5xx-ratio-high}

**Open:** Global traffic.

**Likely causes:** An origin is failing, a PoP is unhealthy, or a release broke a path.

**First action:** Split 5xx by PoP and by domain on Global traffic. A single PoP means drain it; a single domain means check its origin.

## Cache hit ratio collapsed {#cache-hit-collapse}

**Open:** Cache.

**Likely causes:** A purge, a cache-key change, a disk or volume loss, or cache abuse.

**First action:** Check recent purges and settings changes on the annotations, then cache fill and eviction.

## Egress anomaly {#egress-anomaly}

**Open:** Global traffic.

**Likely causes:** A large download, a viral object, an attack, or lost traffic.

**First action:** Open top domains by bandwidth. Compare with the same hour last week before acting.

## PoP traffic dropped {#pop-traffic-dropped}

**Open:** PoP fleet.

**Likely causes:** DNS stopped steering to the PoP, its gateway is down, or the collector stopped delivering.

**First action:** Check the PoP's heartbeat and probes. If probes pass, the cause is DNS or telemetry.

## Synthetic probe failing {#synthetic-probe-failing}

**Open:** Synthetic probes.

**Likely causes:** The PoP, the nameserver, or the network path to the monitoring host is down.

**First action:** Check the same target from the second vantage. If both fail, treat it as a PoP failure.

## WAF block spike {#waf-block-spike}

**Open:** WAF.

**Likely causes:** An attack, a new rule, or a client release that trips a managed rule.

**First action:** Open WAF, find the top rule and domain, and check whether the matched requests are legitimate before adding an exclusion.

## Request flood {#request-flood}

**Open:** L7 attack and flood.

**Likely causes:** A layer-7 flood on a PoP or domain.

**First action:** Open the domain's top client networks, ASNs, and paths. Use Under Attack mode for that domain. Volumetric saturation needs the network provider.

## Cache abuse detected {#cache-abuse}

**Open:** L7 attack and flood.

**Likely causes:** Clients send requests that try to fill the cache with unique keys.

**First action:** Open the domain's top paths and query counts. Tighten the cache limits for the domain.

## Emergency mode on {#emergency-mode}

**Open:** Security overview.

**Likely causes:** An operator switched emergency mode on for a domain, pool, or the platform.

**First action:** Confirm it was intended. End it with **Return to normal** once the incident is closed.

## DNS flood {#dns-flood}

**Open:** DNS abuse.

**Likely causes:** A random-subdomain or NXDOMAIN flood, or a client hitting its response limit.

**First action:** Open DNS abuse for the zone and client networks. Rate limits already bound the load; confirm authoritative capacity holds.

## TLS handshake failures {#tls-handshake-failures}

**Open:** TLS posture.

**Likely causes:** A scanner, a client with old protocols, or a missing or expired certificate.

**First action:** Group by reason. `unknown_sni` is usually a scanner; `certificate_expired` or `certificate_unavailable` for a real domain needs action.

## Failed login burst {#failed-login-burst}

**Open:** Access and audit.

**Likely causes:** Password guessing or a misconfigured integration.

**First action:** Open Access and audit and group by account and network. Rate limits already apply; disable the account if it is targeted.

## Administrator login from a new network {#admin-login-from-new-network}

**Open:** Access and audit.

**Likely causes:** An administrator traveling, a new VPN, or a stolen credential.

**First action:** Contact the administrator. If unconfirmed, disable the account and revoke its tokens.

## Telemetry integrity {#telemetry-integrity}

**Open:** Telemetry integrity.

**Likely causes:** Vector rejected events: forged events, malformed events, or a version mismatch.

**First action:** Open Telemetry integrity to see the source. Counts here mean the dashboards may be incomplete.

## Control-plane latency {#control-plane-latency}

**Open:** Control plane.

**Likely causes:** Database load, a slow queue, or a slow external call in a request.

**First action:** Open API latency by route and find the slow route. Check PostgreSQL and Valkey.

## Edge availability budget {#edge-availability-burn}

**Open:** SLOs.

**Likely causes:** Probes from the monitoring host fail for one or more PoPs.

**First action:** Open SLOs and the Synthetic probes dashboard, then follow the synthetic-probe runbook.

## Edge 5xx budget {#edge-5xx-burn}

**Open:** SLOs.

**Likely causes:** Sustained 5xx responses are using the error budget.

**First action:** Follow the 5xx ratio runbook. Page-level burn means act now.

## Edge latency budget {#edge-latency-burn}

**Open:** SLOs.

**Likely causes:** More than 5 percent of requests take over 500 ms.

**First action:** Open Edge latency and split by PoP and by origin share.

## Propagation budget {#propagation-burn}

**Open:** SLOs.

**Likely causes:** Purges or configuration reach PoPs slower than the target.

**First action:** Open Config and purge propagation and find the slowest PoP. Check its agent and the edge task queue.

## DNS answer budget {#dns-answer-burn}

**Open:** SLOs.

**Likely causes:** Authoritative servers return SERVFAIL.

**First action:** Open DNS authoritative and check backend health and the PowerDNS logs.

## Telemetry delivery budget {#telemetry-delivery-burn}

**Open:** SLOs.

**Likely causes:** Events are dropped before ClickHouse.

**First action:** Open Telemetry pipeline. Repair ClickHouse first, then check Vector buffers.

## One domain returns 5xx {#domain-5xx-ratio-high}

**Open:** Domain overview.

**Likely causes:** The domain's origin fails or its configuration is wrong.

**First action:** Open Domain overview for the hostname. Check origin health and recent configuration changes.

## Request flood on a domain {#domain-request-flood}

**Open:** L7 attack and flood.

**Likely causes:** A flood or a viral event on one domain.

**First action:** Open the domain's top client networks and paths. Use Under Attack mode if the traffic is hostile.

## One network sends most of a domain's traffic {#domain-single-network-share}

**Open:** L7 attack and flood.

**Likely causes:** One client network or ASN is responsible for most requests.

**First action:** Check whether it is a known customer, monitor, or crawler before blocking. Block by network rule if hostile.

## Origin failover {#origin-failover}

**Open:** Origin health.

**Likely causes:** The primary origin failed and traffic moved to the backup.

**First action:** Repair the primary origin. Traffic returns when it is healthy.
