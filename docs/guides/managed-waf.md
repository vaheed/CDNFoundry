---
title: Managed OWASP CRS WAF
description: Operate fixed profiles, bounded exclusions, canaries, and rollback.
---

# Managed OWASP CRS WAF

CDNFoundry pins ModSecurity v3.0.14, its Nginx connector v1.0.4, and OWASP CRS
v4.26.0 in the immutable OpenResty image. Cells do not download rules at
runtime. Customers cannot upload rules, enter `SecRule`, or define expressions.

| Profile | Paranoia | Inbound score | Body inspected | Action |
| --- | ---: | ---: | ---: | --- |
| Off | 0 | — | 0 | No managed inspection |
| Monitor | 1 | 5 | 1 MiB | Detect and serve |
| Balanced | 1 | 5 | 1 MiB | Stable HTTP 403 |
| Strict | 2 | 3 | 256 KiB | Stable HTTP 403 |

The public response exposes only `waf_request_blocked` or `waf_body_limit`.
Paths, parameter values, request bodies, matched data, raw ModSecurity messages,
and rule text are never returned or placed in CDNFoundry telemetry.

## Enable a profile

Prepare a pool with the immutable WAF image. In **Service pools**, enable
**WAF-capable runtime**, record the image digest/version, and set the canary to
**Monitoring**. Only monitor can use a monitoring canary. After the approved
benign/attack corpora and HIT/MISS load pass, mark that version **Passed**.
Balanced and strict placement refuses pools without that state.

On the domain page choose **Security → Managed WAF profile**. Saving increments
one desired revision and queues the normal signed deployment. A protected
domain prefers reserved, dedicated, then quarantine WAF capacity. The target
activates before the source drains.

## Add an exclusion

Use **Managed WAF exclusions** on the domain. Each exclusion has one approved
dimension (literal path, CRS rule ID, parameter name, or cookie name), an
optional CRS rule ID in `900000`–`999999`, a reason, the authenticated owner,
and an expiry no more than 30 days away.

There are at most 50 active exclusions per domain. Paths and names are literal;
wildcards and expressions are rejected. Create/delete actions are audited and
deploy one revision. Expired exclusions are omitted from the next artifact.

## Canary failure and rollback

Set a failing candidate pool's canary state to **Failed**. Placement will not
select it. Preserve the prior image digest and ruleset; restore it, confirm its
passed state, and reconcile. Candidate validation or acknowledgement failure
leaves the active cell and signed artifact unchanged.

Telemetry contains only profile, numeric rule ID, anomaly score, action,
processing microseconds, body-limit outcome, and numeric exclusion ID.
Telemetry remains best effort and never participates in serving.

Before deploying Vector, apply the additive ClickHouse migration:

```sh
docker compose --env-file .env.prod -f compose.prod.yml exec -T clickhouse \
  clickhouse-client --multiquery < docker/clickhouse/migrations/2026_07_29_add_managed_waf_telemetry.sql
```
