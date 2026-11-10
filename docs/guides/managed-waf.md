---
title: Managed web application firewall
description: Turn on the managed OWASP CRS firewall for a domain, start in Observe mode, move to blocking, and recover when it misfires.
---

# Managed web application firewall

A web application firewall (WAF) inspects each request for common application
attacks, such as SQL injection. CDNFoundry runs the OWASP Core Rule Set (CRS)
inside every edge cell that offers it. This page shows how to offer the WAF on
a pool, choose a level for a domain, and handle false positives.

**You need:** an administrator account to offer the WAF on a pool, and update
rights on the domain to choose its level.

::: warning Observe before blocking
Start a new workload with **Observe**. Review the security events for false
positives, then move to **Recommended**. **High sensitivity** is more likely to
block legitimate traffic.
:::

## How the pieces fit

Two settings decide whether the WAF runs for a domain.

| Setting | Who sets it | Question it answers |
| --- | --- | --- |
| **Offer managed WAF protection** on a service pool | Administrator | Where can the WAF run? |
| **Web application firewall (WAF)** on a domain | Domain owner | What should the WAF do for this site? |

CDNFoundry pins the WAF release (ModSecurity 3.0.14, its Nginx connector, and
CRS 4.26.0) inside the immutable OpenResty image. Cells never download rules at
run time. Customers cannot upload rules or write `SecRule` lines.

**Under Attack mode** is a separate control. It tightens request and connection
limits during an incident. It does not turn the WAF on or change the WAF level.
See [Security](security.md).

## 1. Offer the WAF on a pool

<DocTabs :labels="['Admin panel']">
<template #tab-1>

Open **Infrastructure → Service pools**, edit the pool, and turn on **Offer
managed WAF protection**. Turn it on only after the pool's cells run the WAF
image. The form shows the release CDNFoundry already pinned. There is no version
or canary field to fill.

</template>
</DocTabs>

**Expected result:** the pool is saved. No domain changes, because every domain
stays Off until its owner chooses a level.

## 2. Choose a level for a domain

| Panel choice | API value | What happens |
| --- | --- | --- |
| Off | `off` | CRS is disabled. Platform request-safety and abuse limits stay active. |
| Observe | `monitor` | CRS runs in detection-only mode. It never blocks because of an anomaly. |
| Recommended | `balanced` | CRS blocks when its inbound anomaly score reaches 5. |
| High sensitivity | `strict` | CRS uses paranoia level 2 and blocks at score 3. Expect more false positives. |

<DocTabs :labels="['Domain panel', 'API']">
<template #tab-1>

Open the domain, choose **Web application firewall (WAF)**, pick a **Protection
level**, and save. Start with **Observe**.

</template>
<template #tab-2>

```sh
curl -X PATCH "$CDNF_URL/api/domains/$DOMAIN_ID/waf" \
  -H "Authorization: Bearer $CDNF_TOKEN" \
  -H "Idempotency-Key: $(uuidgen)" \
  -H "Content-Type: application/json" \
  -d '{"profile": "monitor"}'
```

</template>
</DocTabs>

**Expected result:** the domain revision increases and CDNFoundry queues a
signed deployment. The new target activates before the old one drains. Check
the domain's operation until the edges report the new revision.

**If it fails:** the API answers `409` with `waf_capable_pool_unavailable`, and
the panel shows **WAF protection is unavailable**. No enabled shared pool offers
the WAF yet. Nothing changed. Ask an administrator to do step 1. CDNFoundry never
accepts a level it cannot run, and never places the domain on a runtime without
the WAF.

## 3. Review detections, then tighten

Check the domain's security events for expected detections and false positives.
Move to **Recommended** when they look right. Use **High sensitivity** only
after you test the application.

Responses to blocked requests are a controlled HTTP status. They never show
matched data or rule text. Paths, parameter values, bodies, matched data, and raw
ModSecurity messages never reach responses or CDNFoundry telemetry. Platform
body admission can show only `waf_body_limit`.

## Handle a false positive

Per-rule exclusions are unavailable. Creating one returns `409` with
`waf_exclusions_unavailable`, and the domain screen has no create action.
Libmodsecurity cannot safely remove one chosen rule for one target. An earlier
design switched the whole request to monitor mode, and an attacker could add a
literal parameter name to bypass CRS.

Exclusions saved earlier stay visible and can be deleted, but the edge artifact
always carries an empty list. The domain page shows the **WAF exclusions** tab
only while such exclusions exist. To handle a false positive, choose **Observe** for
the domain, or ask an administrator to adjust the platform ruleset.

## Telemetry

The Nginx request event records the selected level, the transaction action, the
body-limit outcome, and a numeric exclusion ID. A separate minimal ModSecurity
audit record uses parts `A`, `H`, and `Z` only. It leaves out request headers,
cookies, authorization values, bodies, and query strings. CRS rule logging is
suppressed, and only bounded anomaly and intervention evidence is kept.
Telemetry is best effort and never takes part in serving.

Before you deploy Vector for the first time, apply the additive ClickHouse
migration:

```sh
docker compose --env-file .env.prod -f compose.prod.yml exec -T clickhouse \
  clickhouse-client --multiquery < docker/clickhouse/migrations/2026_07_29_add_managed_waf_telemetry.sql
```

## Failure behavior

- Laravel rejects unknown levels. Invalid runtime JSON leaves the cell's last
  valid in-memory state active.
- Missing CRS files, an unavailable module, or a CRS start-up error fail the WAF
  cell at start. Readiness then keeps domains off that cell.
- A ModSecurity error during one transaction keeps Nginx's module result and
  appears as a cell error. Operators remove an unhealthy WAF cell from placement.
  CDNFoundry does not bypass inspection silently.
- ClickHouse or analytics failures fail open. Serving continues, and the bounded
  Vector buffer drops the newest events when full.
- Losing the control plane does not change the loaded signed level.
- A WAF image change uses the normal bounded edge release and rollback. If the
  candidate fails validation or acknowledgement, the active cell and signed
  artifact stay as they were.

Platform safety checks are not WAF signatures. They keep enforcing host
identity, method, header, and body bounds, trusted-client parsing, rate and
connection limits, origin SSRF and loop protection, and malformed JSON handling,
even when the WAF is Off.
