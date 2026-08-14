---
title: Production best practices
description: Operate CDNFoundry safely in production with guidance for releases, networks, secrets, DNS, edge, origins, TLS, cache, monitoring, backup, and incidents.
keywords: CDN production best practices, private CDN operations, CDNFoundry hardening, CDN operations checklist
---

# Production best practices

Production readiness is evidence that the exact release, topology, network,
origin, and recovery procedure behave correctly together. It is not only a
successful Compose start or a green control-plane health endpoint.

::: danger Never experiment on the only serving copy
Keep the previous valid runtime, current certificate, database backup, key
material, exact release, and a tested rollback path before changing production.
Never use destructive volume or database refresh commands as an upgrade step.
:::

## Release and host discipline

- Deploy the same exact successful commit SHA or semantic-version tag on every
  role participating in one rollout.
- Never deploy mutable `latest`, major-only, or minor-only image aliases.
- Validate `compose.prod.yml` and every exact generated node bundle before pulling or starting.
- Use bounded canaries and readiness gates; do not restart every failure domain
  simultaneously.
- Keep host clocks synchronized. Certificates, DNS, telemetry, and operation
  timing all depend on sane time.
- Reserve CPU, memory, disk, file descriptors, connections, and network
  headroom for failure and recovery, not only average traffic.
- Treat manual changes inside containers as disposable diagnostics, not a
  deployment method.

Use [Fleet rollouts](fleet-rollouts.md) for the supported canary workflow.

## Network exposure

- Publish only DNSdist, required gateway listeners, and the authenticated
  control reverse proxy.
- Keep PowerDNS, its native API, PostgreSQL, Valkey, ClickHouse, Prometheus,
  Loki, and native Grafana private.
- Source-restrict the TLS DNS API gateway to control workers.
- Allow edge agents to initiate outbound mTLS; do not require inbound control
  access to every edge.
- Separate management inventory addresses from customer service addresses.
- Bind shared listeners to the exact public address when it is assigned
  directly to the host, or to a distinct private address behind one-to-one NAT.
  Never use a wildcard as a service-address mapping.
- Map each advertised edge service address one-to-one to a distinct locally
  assigned gateway address.
- Document firewall owner, rule purpose, source, destination, port, protocol,
  and expiry.

::: warning IPv6 is a separate production path
Do not publish AAAA, IPv6 glue, or IPv6 service endpoints merely because the
software supports them. Verify address assignment, firewall, routing, DNS,
TLS, origin reachability, telemetry, and failure behavior over IPv6 first.
:::

## Secrets and identity

- Generate secrets on trusted hosts and write them only to protected files.
- Never print production secrets in shell history, CI logs, issue reports, or
  screenshots.
- Store `APP_KEY`, signing keys, CA keys, edge identities, TLS material, metrics
  tokens, and backup credentials according to separate recovery roles.
- Keep CA private keys off edge hosts.
- Start the edge profile explicitly after adding its UUID/token. After a fresh heartbeat, blank the spent bootstrap token in `.env.prod`; generated `start.sh` never edits operator configuration.
- Use one least-privilege API token per human or integration and revoke it when
  ownership changes.
- Rotate identity through the documented workflow and prove both new success
  and old credential rejection.

## Desired state and changes

- Make changes through the panel, API, supported CLI, or migrations—not by
  editing derived PowerDNS rows or active edge files.
- Record the operation ID, previous revision, requested revision, operator,
  expected result, and rollback trigger.
- Use `Idempotency-Key` for mutation automation.
- Wait for target acknowledgements and then test public behavior.
- Avoid simultaneous unrelated changes during an incident.
- Allow for DNS TTL, cache lifetime, drain interval, and routing convergence
  before declaring a transition complete.
- Investigate failed candidates while confirming that last-valid state remains
  active.

## DNS practices

- Run at least two authoritative instances in distinct failure domains.
- Test direct queries to each endpoint over UDP and TCP.
- Keep platform/operator DNS outside the CDNFoundry customer platform zone.
- Create and verify parent glue before delegation.
- Keep PowerDNS private behind DNSdist and the restricted API gateway.
- Let CDNFoundry own SOA serials and rendered records.
- Use conservative TTLs during planned migration, then raise them after stable
  qualification.
- Test DNSSEC separately if added externally; the repository does not claim a
  managed DNSSEC lifecycle.
- Treat ECS and Geo-DNS as answer-selection hints, never authentication.

## Edge and pool practices

- Use shared pools for normal tenants, quarantine for containment, and
  dedicated placement only for exceptional justified cases.
- Maintain spare unassigned cell slots and capacity below pressure thresholds.
- Ensure target cells activate and acknowledge before draining a source.
- Advertise only endpoints whose gateway revision and assigned cells are ready.
- Test unknown destination, unknown Host, and unknown SNI rejection.
- Measure per-cell CPU, memory, connections, temporary storage, cache, and
  error rate rather than only host averages.
- Withdraw or drain a failing endpoint through the owned DNS/routing procedure;
  do not destroy its last-valid runtime during diagnosis.

## Origin practices

- Use a dedicated origin hostname that does not resolve back to the CDN.
- Prefer HTTPS origins and verify certificate/name policy appropriate to the
  deployment.
- Restrict origin ingress to intended CDN egress sources when operationally
  possible without creating a fail-open bypass.
- Capacity-plan for cache misses, purge events, cold starts, retries, and loss
  of one edge.
- Keep connect, response, body, and retry limits bounded.
- Revalidate hostname resolution and prevent loopback, private platform,
  metadata, and proxy-loop targets.
- Preserve correct Host and forwarding semantics while removing hop-by-hop and
  untrusted forwarding headers.

::: warning Retries amplify failures
An origin that is already overloaded can become less available when every edge
retries. Keep retry count and timeout bounded, monitor amplification, and prefer
fast failure plus eligible stale content where policy allows.
:::

## Cache practices

- Cache only responses whose ownership and variation are understood.
- Include every representation-changing request property in the cache policy.
- Validate behavior for cookies, authorization, query strings, range requests,
  redirects, errors, and compression variants.
- Use development mode for bounded debugging, then turn it off explicitly.
- Prefer URL purge for small known sets; reserve full-purge epochs for full
  namespace invalidation.
- Measure hit ratio together with origin bytes, object sizes, eviction, disk
  latency, and admission pressure.
- Never use cache storage as customer data backup.

## TLS practices

- Issue managed certificates only after real delegation and eligible proxy
  state exist.
- Monitor expiry, renewal spread, DNS-01 acknowledgement, and edge activation
  separately.
- Validate custom private-key match, chain, name coverage, expiry, and PEM
  bounds before acceptance.
- Retain the current valid certificate until a replacement is active.
- Include externally retained TLS material in recovery drills.
- Test SNI, certificate chain, hostname coverage, and renewal from outside the
  management network.

## Security practices

- Start with least privilege for admin accounts, domain assignments, tokens,
  database roles, and network rules.
- Use bounded application profiles; never remove ceilings as an incident fix.
- Introduce managed WAF policy in Observe mode, review real events, then use
  Block only with a rollback trigger.
- Give temporary allow/block rules an owner, reason, scope, and expiry.
- Keep trusted-proxy configuration exact so client identity cannot be spoofed.
- Test payload, header, connection, request-rate, and origin-concurrency bounds.
- Contract upstream scrubbing when the threat includes link saturation.

See [Deployment hardening](../security/hardening.md) for the detailed checklist.

## Observability practices

- Monitor the client path, not only component processes.
- Run external probes against both authoritative endpoints and each intended
  HTTP failure domain.
- Alert on queue age, scheduler freshness, failed/stale deployments, certificate
  expiry, edge heartbeat, gateway readiness, capacity pressure, origin errors,
  telemetry buffers, disks, backup results, and clock drift.
- Keep Grafana behind a separately authenticated HTTPS proxy or trusted tunnel.
- Preserve datasource accounts as read-only and bounded.
- Expect telemetry loss under sustained downstream failure; alert on it without
  coupling it back into serving.
- Record exact timestamps, revisions, edges, cells, and operation IDs during an
  incident.

## Backup and recovery practices

The built-in S3-compatible Restic integration is optional, but a tested
recovery design is mandatory for production.

- Store backups off-host and outside the serving failure domain.
- Restrict repository credentials to the intended bucket and prefix.
- Protect the Restic password separately from repository credentials.
- Back up PostgreSQL together with the encryption/signing/PKI/TLS material
  required to interpret and redeploy it.
- Record the exact release and migration boundary for each recovery point.
- Test restore into an isolated environment without overwriting production.
- Reconcile derived PowerDNS and edge state after control-state restoration.
- Define and measure RPO and RTO; a green backup job is not restore evidence.

Use [Backup and recovery](backup-and-recovery.md) for the supported procedure.

## Capacity practices

Measure each plane independently:

| Plane | Watch first | Typical scale action |
| --- | --- | --- |
| DNS | QPS, packet loss, latency, backend health | Add authoritative capacity or separate DNS hosts |
| Edge | Bandwidth, requests, connections, TLS rate, cell pressure | Add cells, hosts, or POP capacity |
| Cache | Hit ratio, churn, disk latency, eviction | Tune eligibility or add bounded cache capacity |
| Origin | Latency, errors, concurrency, retry amplification | Improve/add origin capacity or cacheability |
| Control | Queue depth/age, reconciliation duration | Add workers within isolated lanes |
| Telemetry | Events/s, buffer use, inserts, retention bytes | Add ClickHouse/Vector capacity or adjust owned retention |

Do not solve edge traffic growth by creating per-domain processes or bypassing
cell bounds. See [Scaling](scaling.md).

## Production change checklist

Before:

- [ ] Exact release and configuration diff reviewed.
- [ ] Current backup and recovery material verified.
- [ ] Health and capacity have enough margin for the change.
- [ ] Canary target, success criteria, timeout, and rollback trigger recorded.
- [ ] External probes and responsible operator are available.

During:

- [ ] One bounded failure domain changes at a time.
- [ ] Operation IDs, revisions, and target acknowledgements are retained.
- [ ] DNS, HTTP, TLS, origin, cache, and telemetry signals are watched.
- [ ] No unrelated remediation is mixed into the rollout.

After:

- [ ] Public UDP/TCP DNS and HTTP/HTTPS behavior is verified externally.
- [ ] IPv4 and IPv6 are checked wherever advertised.
- [ ] The observation window covers relevant TTL, drain, and cache periods.
- [ ] Previous state remains recoverable until acceptance is recorded.
- [ ] Deviations, incidents, and capacity findings are documented.

## Commands that do not belong in production recovery

Never use these as shortcuts:

- `docker compose down -v`;
- destructive database refresh or test migration commands;
- deletion of active/previous edge runtime directories;
- regeneration of `APP_KEY`, signing keys, or CA keys;
- direct edits to PowerDNS runtime rows as desired state;
- public exposure of database, Valkey, PowerDNS API, metrics, or Grafana ports.

## Final release gate

Production acceptance requires all four independently:

1. implementation for the exact revision is present;
2. operator and user documentation is current;
3. automated and real-runtime qualification passes;
4. the owner completes the manual browser and external-network checklist.

Use [Production qualification](production-qualification.md) for evidence,
[Incident runbooks](runbooks.md) for failures, and
[Production reference architectures](../architecture/production-reference-architectures.md)
for topology review.
