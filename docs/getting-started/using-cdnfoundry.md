---
title: Using CDNFoundry
description: Learn the normal administrator, domain-user, and API workflows after CDNFoundry is installed.
keywords: CDNFoundry tutorial, use CDNFoundry, private CDN administration, CDN domain onboarding
---

# Using CDNFoundry

This guide is the task map for an installed CDNFoundry fleet. It shows the
order in which operators and domain users normally work and links to the exact
feature procedures.

::: warning Installation is a prerequisite
Do not use UI actions to compensate for unhealthy databases, queues, DNS
targets, cells, or gateways. Complete the production acceptance checks first,
then onboard customer state.
:::

## Choose an interface

| Interface | Audience | Address or authentication |
| --- | --- | --- |
| Administrator panel | Platform operators | `/admin`, active `admin` user |
| Domain-user panel | Assigned customers or teams | `/app`, active domain assignment |
| HTTP API | Automation and integrations | Sanctum bearer token and the same policies |
| CLI | Host and platform operators | `php artisan cdnf:*` inside the supported container context |

The panels and API use the same authorization policies. A domain user cannot
escape assignment scope by switching to the API.

## Administrator bootstrap

Complete these once for a new platform:

1. Deploy and qualify `CONTROL`, `EDGE_1`, and `EDGE_2` using the
   [Production quick start](../deployment/production-quick-start.md).
2. Create the first administrator with `cdnf:admin:create`.
3. Apply system DNS identity and verify parent-zone glue.
4. Register, test, and enable at least two authoritative DNS clusters.
5. Create the required shared and quarantine pools.
6. Enroll edges, assign bounded cells, and configure service endpoints.
7. Confirm agent identity, fresh heartbeats, gateway acknowledgement, and cell
   readiness.
8. Configure backup, metrics, alerts, and an owner-operated external probe.

Do not onboard a production customer while the platform zone, either
authoritative server, or the intended edge endpoints are unqualified.

## Onboard a domain

### 1. Create and assign

Create the normalized registrable domain in the administrator panel. Assign
domain users only after checking that they should control the full zone.

New domains do not require an origin and do not issue a certificate. They begin
as desired state pending nameserver verification.

### 2. Delegate and activate

At the registrar, point the domain to the platform nameservers. Run nameserver
verification, wait for success, then activate the domain. Confirm every
required DNS target acknowledges the current revision.

Use [First domain](first-domain.md) for exact fields and checks.

### 3. Choose DNS-only or proxied service

| Need | Record mode | Additional requirements |
| --- | --- | --- |
| Publish mail, verification, or direct origin records | DNS-only | Type-valid content and delegation |
| Return geographic values without proxying | Geo-DNS | Required default answers and optional overrides |
| Serve HTTP/HTTPS through CDNFoundry | Proxied | Safe origin, pool placement, ready endpoint, TLS |

Use DNS-only while migrating or validating a new zone. Add proxying only after
the origin and edge path have independent health evidence.

### 4. Add a proxied hostname safely

1. Identify the exact public origin hostname and port.
2. Confirm it cannot resolve to platform, edge, metadata, or private management
   addresses.
3. Configure the proxied `A`, `AAAA`, or `CNAME` record and explicit origin.
4. Wait for edge artifact activation on the assigned cells.
5. Wait for a valid managed or custom certificate.
6. Test with a non-production hostname before moving real traffic.
7. Verify headers, redirects, cookies, cache behaviour, and client IP handling.

See [Proxy and origins](../guides/proxy-and-origins.md) and
[TLS](../guides/tls.md).

## Normal feature workflows

### Manage authoritative DNS

- Create and edit records within the zone boundary.
- Use preview before large imports.
- Treat `202 Accepted` as an operation receipt.
- Wait for deployments, then query both authoritative endpoints over UDP and
  TCP.
- Use [Geo-DNS](../guides/geo-dns.md) only for deterministic DNS answers, not
  authorization or active HTTP health checks.

### Tune caching

Start from origin response semantics. Confirm which responses are public and
safe to reuse, then configure bounded cache policy. Validate miss, hit, bypass,
expiry, development mode, URL purge, and full-purge epoch behavior.

Never cache personalized responses unless the cache key and response controls
prove tenant and user isolation. See [Cache and purge](../guides/cache.md).

### Configure security

Start with the standard bounded profile. Add narrow ordered rules with an
explicit reason and expiry. Put managed WAF into Observe mode before Block and
review actual security events. Use quarantine as an incident containment tool,
not as a substitute for remediation.

See [Security](../guides/security.md) and [Managed WAF](../guides/managed-waf.md).

### Use analytics and usage

Analytics is downstream and can be incomplete during telemetry failure. Use
short raw windows for investigation and aggregates for trends. Do not infer
billing-grade completeness unless the operator has independently defined and
qualified that contract.

### Purge content

Use URL purge for a bounded known set of URLs. Use a full purge only when the
domain's entire cache namespace must become unreachable. Purge changes edge
cache behavior; it does not remove or regenerate content at the origin.

## Understand operation status

| Observation | What it proves | What to do next |
| --- | --- | --- |
| Form/API accepted | Desired state was validated and committed | Save the operation ID |
| Operation queued/running | Reconciliation has not finished | Wait; do not submit conflicting retries blindly |
| Operation succeeded | Required application workflow completed | Inspect target revision and acknowledgement |
| DNS deployment succeeded | Target accepted the rendered zone | Query the target and public delegation |
| Edge acknowledged | Agent activated the artifact | Test gateway, TLS, cache, and origin externally |
| Operation failed | Requested result is not active everywhere | Read stable error code; verify previous valid state |

::: danger Do not retry mutations without identity
For API automation, send a stable `Idempotency-Key`. Retrying an ambiguous
network response with a new key can create duplicate logical work. Reusing a
key with different input is correctly rejected as a conflict.
:::

## A safe change pattern

Use this pattern for DNS, origin, TLS, cache, security, placement, and fleet
changes:

1. record current revision and public behavior;
2. define the expected result and rollback trigger;
3. change the smallest possible scope;
4. retain the operation or task receipt;
5. verify every required target;
6. test from outside the control network;
7. observe through at least one cache/TTL window when relevant;
8. close the change only after rollback remains possible.

For a pool move, the target must activate before the source drains. For a DNS
change, account for recursive caches. For TLS, preserve the current valid
certificate until its replacement is active.

## Daily operator routine

- Check component, queue, scheduler, backup, MMDB, TLS, edge, gateway, and
  capacity health.
- Review failed or old operations and stale target acknowledgements.
- Verify both authoritative DNS endpoints from outside.
- Check edge heartbeat freshness and listener readiness.
- Review certificate expiry and renewal spread.
- Watch origin failure, cache, WAF, rate-limit, telemetry buffer, and disk
  signals.
- Confirm the last backup result and periodically prove restoration.

Use [Monitoring](../operations/monitoring.md),
[Grafana command centers](../operations/grafana.md), and
[Incident runbooks](../operations/runbooks.md).

## API automation rules

- Use the endpoint catalog rather than guessing paths.
- Use one least-privilege token per integration.
- Send `Accept: application/json` and a stable `Idempotency-Key` on mutations.
- Follow cursor links instead of constructing page numbers.
- Enforce documented bulk item and payload limits before sending.
- Persist operation IDs and stable error codes.
- Back off boundedly on unavailable dependencies; do not create retry storms.
- Never log bearer tokens, one-time secrets, private keys, or full sensitive
  request bodies.

Start at [API conventions](../reference/api/index.md) and the
[endpoint catalog](../reference/api/endpoints.md).

## Where to go next

| Goal | Documentation |
| --- | --- |
| Understand CDN terminology | [CDN fundamentals](../concepts/cdn-fundamentals.md) |
| Understand runtime behavior | [How CDNFoundry works](../concepts/how-cdnfoundry-works.md) |
| Select hosts and failure domains | [Production reference architectures](../architecture/production-reference-architectures.md) |
| Harden a real deployment | [Production best practices](../operations/production-best-practices.md) |
| Diagnose a symptom | [Troubleshooting](../troubleshooting/index.md) |
