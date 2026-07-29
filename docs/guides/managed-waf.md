---
title: Managed OWASP CRS WAF
description: Operate fixed profiles, bounded exclusions, canaries, and rollback.
---

# Managed OWASP CRS WAF

## The simple mental model

There are only two everyday decisions:

1. **Administrator, once per service pool:** turn on **Offer managed WAF
   protection**, test it, then mark it **Ready for blocking**. CDNFoundry fills
   the pinned release automatically.
2. **Domain owner, per domain:** choose **Web application firewall (WAF)** and
   select Off, Observe, Recommended, or High sensitivity.

The pool answers “where can WAF run?” The domain setting answers “what should
WAF do for this website?” A domain using Recommended or High sensitivity waits
for a pool marked Ready for blocking; CDNFoundry does not silently place it on
an untested or non-WAF runtime.

**Apply incident protection** is separate. It temporarily tightens request and
connection limits during an active incident. It does not turn WAF on.

CDNFoundry pins ModSecurity v3.0.14, its Nginx connector v1.0.4, and OWASP CRS
v4.26.0 in the immutable OpenResty image. Cells do not download rules at
runtime. Customers cannot upload rules, enter `SecRule`, or define expressions.

| UI choice | Internal profile | What happens |
| --- | --- | --- |
| Off | `off` | No attack-signature inspection |
| Observe | `monitor` | Detect and report; never block |
| Recommended | `balanced` | Block common attacks; best default after observing |
| High sensitivity | `strict` | Block more aggressively; higher false-positive risk |

The public response exposes only `waf_request_blocked` or `waf_body_limit`.
Paths, parameter values, request bodies, matched data, raw ModSecurity messages,
and rule text are never returned or placed in CDNFoundry telemetry.

## Enable a profile

Prepare a pool whose cells use the immutable WAF image. In **Service pools**,
enable **Offer managed WAF protection**. CDNFoundry displays the release it
already pinned; do not type an image version. Leave **WAF readiness** at
**Testing — detect only**. After normal requests work and approved attack
samples appear in events, change it to **Ready for blocking**. If testing fails,
choose **Failed — keep previous runtime**.

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

Set a failing pool's **WAF readiness** to **Failed — keep previous runtime**.
Placement will not select it. Preserve the prior image and ruleset; restore it,
confirm **Ready for blocking**, and reconcile. Candidate validation or
acknowledgement failure leaves the active cell and signed artifact unchanged.

Telemetry contains only profile, numeric rule ID, anomaly score, action,
processing microseconds, body-limit outcome, and numeric exclusion ID.
Telemetry remains best effort and never participates in serving.

Before deploying Vector, apply the additive ClickHouse migration:

```sh
docker compose --env-file .env.prod -f compose.prod.yml exec -T clickhouse \
  clickhouse-client --multiquery < docker/clickhouse/migrations/2026_07_29_add_managed_waf_telemetry.sql
```
