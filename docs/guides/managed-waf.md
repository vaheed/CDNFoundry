---
title: Managed OWASP CRS WAF
description: Operate fixed profiles, bounded exclusions, and rollback.
---

# Managed OWASP CRS WAF

::: warning Observe before blocking
Start a new workload with **Observe**, review sanitized detections and false
positives, then move to **Recommended**. High sensitivity is intentionally more
likely to block legitimate application traffic.
:::

## The simple mental model

There are only two everyday decisions:

1. **Administrator, once per service pool:** turn on **Offer managed WAF
   protection** only where cells run the pinned WAF image. CDNFoundry fills the
   pinned release automatically.
2. **Domain owner, per domain:** choose **Web application firewall (WAF)** and
   select Off, Observe, Recommended, or High sensitivity.

The pool answers “where can WAF run?” The domain setting answers “what should
WAF do for this website?” A domain using Observe, Recommended, or High
sensitivity waits for a pool offering the pinned WAF runtime; CDNFoundry does
not silently place it on a non-WAF runtime.

**Under Attack mode** is separate. It temporarily tightens request and
connection limits during an active incident. It does not turn WAF on or change
the chosen WAF profile.

CDNFoundry pins ModSecurity v3.0.14, its Nginx connector v1.0.4, and OWASP CRS
v4.26.0 in the immutable OpenResty image. Cells do not download rules at
runtime. Customers cannot upload rules, enter `SecRule`, or define expressions.

| UI choice | Internal profile | What happens |
| --- | --- | --- |
| Off | `off` | CRS is disabled for the transaction; platform request-safety and abuse limits remain active |
| Observe | `monitor` | CRS runs in transaction-level detection-only mode and never disrupts solely for an anomaly |
| Recommended | `balanced` | CRS blocks when its inbound anomaly score reaches 5 |
| High sensitivity | `strict` | CRS uses paranoia level 2 and blocks at score 3; false-positive risk is higher |

The public response is a controlled HTTP status and never exposes matched data
or rule text. Platform body admission can expose only `waf_body_limit`.
Paths, parameter values, request bodies, matched data, raw ModSecurity messages,
and rule text are never returned or placed in CDNFoundry telemetry.

## Enable a profile

Open the pool and enable **Offer managed WAF protection** only after its cells
run the pinned WAF image. CDNFoundry displays the release it already pinned;
there is no version, readiness, or canary field to fill. Enabling capacity does
not turn WAF on for all domains and does not revise all domain policies. Each
domain remains Off until its owner chooses another level.

On the domain page choose **Web application firewall (WAF)**. Start with
**Observe**. Check security events for expected detections and false positives,
then choose **Recommended**. Use **High sensitivity** only after application
testing. Saving increments one desired revision and queues the normal signed
deployment. The target activates before the source drains.

## Add an exclusion

Use **Managed WAF exclusions** on the domain. Each exclusion has one approved
dimension (literal path, CRS rule ID, parameter name, or cookie name), an
optional CRS rule ID in `900000`–`999999`, a reason, the authenticated owner,
and an expiry no more than 30 days away.

There are at most 50 active exclusions per domain. Paths and names are literal;
wildcards and expressions are rejected. Create/delete actions are audited and
deploy one revision. Expired exclusions are omitted from the next artifact.

## Testing failure and rollback

WAF image changes use the normal bounded edge release and rollback process.
Candidate validation or acknowledgement failure leaves the active cell and
signed artifact unchanged.

The Nginx request event contains the selected profile, transaction action,
body-limit outcome, and numeric exclusion ID. A separate minimal ModSecurity
audit record uses parts `A`, `H`, and `Z` only; request headers, cookies,
authorization values, bodies, and query strings are excluded. CRS rule logging
is suppressed and only bounded anomaly/intervention evidence is retained.
Telemetry remains best effort and never participates in serving.

An exclusion that matches its literal path, parameter, cookie, or rule scope
places that one request into monitor mode. This is deliberately broader than
removing a dynamically supplied rule ID because libModSecurity does not accept
runtime-expanded `ctl:ruleRemoveById` safely. The exception remains bounded,
owned, audited, and expiring; use the narrowest literal dimension.

## Failure behavior

- Invalid or unknown profiles are rejected by Laravel and invalid runtime JSON
  leaves the cell's last valid in-memory state active.
- Missing CRS files, an unavailable module, or CRS initialization errors fail
  the WAF cell at startup; readiness prevents placement on it.
- A transaction-level ModSecurity error preserves Nginx's module result and is
  surfaced as a cell/runtime error; operators remove an unhealthy WAF cell from
  placement rather than silently bypassing managed inspection.
- Telemetry, ClickHouse, and analytics failures fail open: serving continues
  and the bounded Vector buffer drops newest events when full.
- Loss of the control plane does not change the loaded signed profile.

Platform safety checks are not managed WAF signatures. They continue to enforce
host identity, method/header/body bounds, trusted-client parsing, rate and
connection limits, origin SSRF/loop protection, and malformed JSON handling
even when managed CRS is Off.

Before deploying Vector, apply the additive ClickHouse migration:

```sh
docker compose --env-file .env.prod -f compose.prod.yml exec -T clickhouse \
  clickhouse-client --multiquery < docker/clickhouse/migrations/2026_07_29_add_managed_waf_telemetry.sql
```
