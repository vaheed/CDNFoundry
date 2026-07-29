---
title: Managed OWASP CRS WAF
description: Operate fixed profiles, bounded exclusions, canaries, and rollback.
---

# Managed OWASP CRS WAF

## The simple mental model

There are only two everyday decisions:

1. **Administrator, once per service pool:** after the WAF image passes a small
   fleet canary rollout, turn on **Offer managed WAF protection**. CDNFoundry
   fills the qualified pinned release automatically.
2. **Domain owner, per domain:** choose **Web application firewall (WAF)** and
   select Off, Observe, Recommended, or High sensitivity.

The pool answers “where can WAF run?” The domain setting answers “what should
WAF do for this website?” A domain using Recommended or High sensitivity waits
for a pool offering the qualified WAF runtime; CDNFoundry does not silently
place it on an untested or non-WAF runtime.

**Under Attack mode** is separate. It temporarily tightens request and
connection limits during an active incident. It does not turn WAF on or change
the chosen WAF profile.

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

First test a new immutable WAF image on a small canary edge/pool through the
bounded fleet rollout. Do not use a production pool containing thousands of
domains as the canary.

After that rollout passes, open the production pool and enable **Offer managed
WAF protection**. CDNFoundry displays the release it already pinned; there is
no version or canary field to fill. Enabling capacity does not turn WAF on for
all domains and does not revise all domain policies. Each domain remains Off
until its owner chooses another level.

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

If a fleet canary fails, the rollout pauses and the production pool keeps its
previous qualified image. Candidate validation or acknowledgement failure
leaves the active cell and signed artifact unchanged.

Telemetry contains only profile, numeric rule ID, anomaly score, action,
processing microseconds, body-limit outcome, and numeric exclusion ID.
Telemetry remains best effort and never participates in serving.

Before deploying Vector, apply the additive ClickHouse migration:

```sh
docker compose --env-file .env.prod -f compose.prod.yml exec -T clickhouse \
  clickhouse-client --multiquery < docker/clickhouse/migrations/2026_07_29_add_managed_waf_telemetry.sql
```
