---
title: Using CDNFoundry
description: Learn the normal administrator, domain-user, and API workflows after CDNFoundry is installed.
keywords: CDNFoundry tutorial, use CDNFoundry, private CDN administration, CDN domain onboarding
---

# Using CDNFoundry

This page is a map of the normal work on an installed CDNFoundry fleet. It shows
the usual order for operators and domain users and links to the page for each task.

::: warning Install and check first
Do not use panel actions to work around an unhealthy database, queue, DNS host,
cell, or gateway. Finish the production acceptance checks first. Then add
customer domains.
:::

## Choose an interface

| Interface | Who uses it | Address or sign-in |
| --- | --- | --- |
| Administrator panel | Platform operators | `/admin`, active `admin` user |
| Domain-user panel | Assigned customers or teams | `/app`, active domain assignment |
| HTTP API | Automation and integrations | Sanctum bearer token and the same policies |
| Artisan commands | Platform operators | `php artisan cdnf:*` inside the `core` container |
| Fleet | Infrastructure operators | The `cdnfoundry-fleet` CLI, or its local web page on `127.0.0.1`. See the [Fleet CLI reference](../reference/fleet-cli.md) |

The panels and the API use the same permission rules. Browser requests from the
same origin can use the panel session. Automation uses bearer tokens. A domain
user sees only assigned domains in the API too.

Fleet manages hosts, containers, releases, and certificates. It never edits
domains, DNS records, pools, TLS, WAF rules, or users. Do that in the panels or API.

## Administrator bootstrap

Do these once on a new platform:

1. Deploy and qualify `CONTROL`, `EDGE_1`, and `EDGE_2` using the
   [Production quick start](../deployment/production-quick-start.md).
2. Create the first administrator with `cdnf:admin:create`.
3. Set the System DNS identity and check the glue records at the parent zone.
4. Register the DNS primary, test it, and enable it. Deploy at least two DNS hosts.
5. Create the shared and quarantine pools.
6. Enroll edges, assign cells, and set the pool service endpoints.
7. Check each agent identity, fresh heartbeats, gateway acknowledgement, and cell
   readiness.
8. Set up backups, metrics, alerts, and an external probe that you run.

Do not add a production customer until the platform zone, both DNS servers, and
the edge endpoints all pass your checks. [First domain](first-domain.md) covers
steps 3 and 4.

## Onboard a domain

### 1. Create and assign

Add the registrable domain in the panel (**Domains → Add domain**) or with
`POST /api/domains`. Give domain users access only if they should control the
whole zone.

A new domain needs no origin and gets no certificate. It starts as
*Pending verification*.

### 2. Delegate and activate

At the registrar, set the domain's **Assigned nameservers**. Verification then
activates the domain. If it did not run after your change, choose **Verify
nameservers**. Check that every DNS host reports the current revision.

Use [First domain](first-domain.md) for exact fields and checks.

### 3. Choose DNS-only or proxied service

| Need | Record mode | Additional requirements |
| --- | --- | --- |
| Publish mail, verification, or direct origin records | DNS-only | Type-valid content and delegation |
| Return geographic values without proxying | Geo-DNS | Required default answers and optional overrides |
| Serve HTTP/HTTPS through CDNFoundry | Proxied | Safe origin, pool placement, ready endpoint, TLS |

Use DNS-only while you migrate or test a new zone. Turn on proxying only after you
have tested the origin and the edge path separately.

### 4. Add a proxied hostname safely

1. Find the exact public origin hostname and port.
2. Check that it cannot resolve to a platform, edge, metadata, or private
   management address.
3. Add the proxied `A`, `AAAA`, or `CNAME` record with its origin.
4. Wait until the assigned cells have activated the new edge state.
5. Wait for a valid managed or uploaded certificate.
6. Test with a non-production hostname before you move real traffic.
7. Check headers, redirects, cookies, caching, and client IP handling.

See [Proxy and origins](../guides/proxy-and-origins.md) and
[TLS](../guides/tls.md).

## Normal feature workflows

### Manage authoritative DNS

- Create and edit records within the zone boundary.
- Preview a large import before you apply it.
- Treat `202 Accepted` as an operation receipt.
- Wait for the DNS deployments. Then query both DNS hosts over UDP and TCP.
- Use [Geo-DNS](../guides/geo-dns.md) only for deterministic DNS answers, not
  authorization or active HTTP health checks.

### Tune caching

Start from what your origin sends. Decide which responses are public and safe to
reuse, then set the cache policy. Test a miss, a hit, a bypass, expiry,
development mode, a URL purge, and a full purge.

Do not cache personalized responses unless you are sure the cache key keeps each
user's content separate. See [Cache and purge](../guides/cache.md).

### Configure security

Start with the standard profile. Add narrow, ordered rules with a reason and an
expiry. Put the managed WAF in Observe mode before Block, and read the security
events. Use quarantine to contain an incident. It does not fix the cause.

See [Security](../guides/security.md) and [Managed WAF](../guides/managed-waf.md).

### Use analytics and usage

Analytics comes after serving, so it can have gaps when telemetry fails. Use short
raw-data windows to investigate and aggregates for trends. Do not use these
numbers for billing unless you have defined and tested that use yourself.

### Purge content

Use a URL purge for a known, small set of URLs. Use a full purge only when all
cached content of the domain must go. A purge empties the edge cache. It does not
change anything at your origin.

## Understand operation status

| Observation | What it proves | What to do next |
| --- | --- | --- |
| Form or API accepted | The request was validated and saved | Keep the operation ID |
| Operation pending or running | The work has not finished | Wait. Do not send conflicting retries |
| Operation succeeded | The job finished | Check the target revision and acknowledgement |
| DNS deployment succeeded | The DNS host accepted the zone | Query the host and the public delegation |
| Edge acknowledged | The agent activated the new state | Test the gateway, TLS, cache, and origin from outside |
| Operation failed | The change is not active everywhere | Read the error code. Check that the previous state still works |

::: danger Do not retry mutations without identity
In API automation, send a UUID `Idempotency-Key` and reuse the same key when you
retry. A retry with a new key can do the same work twice. Reusing a key with
different input returns `409 idempotency_conflict`.
:::

## A safe change pattern

Use these steps for DNS, origin, TLS, cache, security, placement, and fleet
changes:

1. Note the current revision and how the service behaves.
2. Decide the expected result and when you would roll back.
3. Change the smallest scope you can.
4. Keep the operation ID.
5. Check every target.
6. Test from outside the control network.
7. Wait at least one cache or TTL period when it matters.
8. Close the change only when a rollback is still possible.

In a pool move, the target activates before the source drains. After a DNS change,
recursive resolvers may keep old answers until the TTL ends. For TLS, the current
certificate stays in use until the new one is active.

## Daily operator routine

- Check the health of components, queues, the scheduler, backups, the GeoIP
  database (MMDB), TLS, edges, gateways, and capacity.
- Review failed or old operations and stale target acknowledgements.
- Query both DNS hosts from outside.
- Check edge heartbeat freshness and listener readiness.
- Review certificate expiry and renewal spread.
- Watch origin failure, cache, WAF, rate-limit, telemetry buffer, and disk
  signals.
- Check the last backup result, and test a restore from time to time.

Use [Monitoring](../operations/monitoring.md),
[Grafana command centers](../operations/grafana.md), and
[Incident runbooks](../operations/runbooks.md).

## API automation tips

- Use the endpoint catalog. Do not guess paths.
- Use one token per integration.
- Send `Accept: application/json` and an `Idempotency-Key` on every change.
- Follow the cursor links. Do not build page numbers.
- Stay inside the documented bulk item and payload limits.
- Store operation IDs and error codes.
- When a dependency is unavailable, wait longer between retries. Do not retry in a tight loop.
- Never log bearer tokens, one-time secrets, private keys, or full request bodies.

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
