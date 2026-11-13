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

CDNFoundry pins the WAF release (ModSecurity 3.0.17, its Nginx connector, and
CRS 4.30.0) inside the immutable OpenResty image. Cells never download rules at
run time. Customers cannot upload rules or write `SecRule` lines.

**Under attack mode** is a separate, temporary control. While it is on, the WAF
runs in blocking mode at high sensitivity whatever level you chose here, together
with tighter limits and a browser challenge. When it ends, your own level applies
again. See [Incident response](incident-response.md).

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

## Adjust the managed rules

The [managed WAF reference](../reference/waf.md) explains every adjustment with examples.

A protection level is a good start. When a level blocks something it should not,
or you want to watch a rule before it counts, adjust the managed rules for the
domain. Adjustments sit on top of the level and never turn an Off domain on.

<DocTabs :labels="['Domain panel', 'API']">
<template #tab-1>

Open the domain, choose **Web application firewall (WAF)**, and open **Adjust
managed rules**. It shows the pinned ruleset and offers a paranoia level, an
anomaly threshold, categories to turn off, single rules to turn off, and rules to
log only. Rule pickers search by number or by name.

</template>
<template #tab-2>

```sh
curl -X PATCH "$CDNF_URL/api/domains/$DOMAIN_ID/waf" \
  -H "Authorization: Bearer $CDNF_TOKEN" \
  -H "Idempotency-Key: $(uuidgen)" \
  -H "Content-Type: application/json" \
  -d '{"tuning": {"paranoia_level": 2, "inbound_threshold": 8,
       "disabled_categories": ["xss"], "disabled_rules": [942100], "log_only_rules": [913100]}}'
```

`GET /api/domains/$DOMAIN_ID/waf/rules` lists the adjustable rules with their
state for the domain (`enabled`, `disabled`, or `log_only`). It takes `category`,
`search`, and a `cursor`. Send `"tuning": null` to clear every adjustment. The
`tuning` object replaces the earlier one as a whole, and a request without it
keeps what is saved.

</template>
</DocTabs>

| Adjustment | Effect |
| --- | --- |
| Paranoia level (1 to 4) | Replaces the level's own paranoia. Higher levels add stricter rules and more false positives. |
| Anomaly threshold (1 to 100) | The score at which a request is blocked. Each matched rule adds 2 to 5 points. |
| Turn off a category | Every rule of the category stops running: method, scanner, protocol, protocol attacks, multipart, file inclusion, remote inclusion, command injection, PHP, generic injection, cross-site scripting, SQL injection, session fixation, Java. |
| Turn off a rule | The rule never runs for this domain. Up to 100 rules. |
| Log only | The rule still runs but never counts toward blocking or the attack score. When one matches, the domain records a `waf_rule_logged` security event. |

Rule numbers come from the catalog of the pinned ruleset, and anything else is
refused. A rule cannot be both disabled and log only. Rules that only gate
paranoia levels or evaluate the final score are not adjustable. The ruleset
version in the panel and in `GET .../waf` is the version the cells run: it ships
inside the image, and cells never download rules.

Log only is exact about whether a rule matched, not about which one. The event
says that a log-only rule matched on a request; the domain knows which rules it
listed. A domain with log-only rules is scored twice per request.

::: warning What changed for inspection
A request that the cell scores is inspected once, in the scoring step, with the
domain's adjustments applied. The cache location then skips its own second
inspection of that request. Responses are not inspected: the managed WAF covers
requests. If scoring is unavailable, the cell falls back to inspecting the request
with the level's unadjusted defaults.
:::

## 3. Review detections, then tighten

Check the domain's security events for expected detections and false positives.
Move to **Recommended** when they look right. Use **High sensitivity** only
after you test the application.

Responses to blocked requests are a controlled HTTP status. They never show
matched data or rule text. Paths, parameter values, bodies, matched data, and raw
ModSecurity messages never reach responses or CDNFoundry telemetry. Platform
body admission can show only `waf_body_limit`.

## Handle a false positive

Turn off the rule that fired, or set it to log only, in
[Adjust the managed rules](#adjust-the-managed-rules). Per-target exclusions are
unavailable. Creating one returns `409` with
`waf_exclusions_unavailable`, and the domain screen has no create action.
Libmodsecurity cannot safely remove one chosen rule for one target. An earlier
design switched the whole request to monitor mode, and an attacker could add a
literal parameter name to bypass CRS.

Exclusions saved earlier stay visible and can be deleted, but the edge artifact
always carries an empty list. The domain page shows the **WAF exclusions** tab
only while such exclusions exist. To handle a false positive, choose **Observe** for
the domain, or ask an administrator to adjust the platform ruleset.

## Telemetry

The Nginx request event records the selected level, the transaction outcome, the
body-limit outcome, and a numeric exclusion ID. `waf_action` is `monitor` for
detection-only domains, `pass` for an inspected request that was allowed, and
`block` when the engine denied it. A blocked request also records
`security_action=block`, `security_reason=waf_block`, rule ID `949110` (the CRS
blocking evaluation), and the inbound threshold it reached as `waf_score`.
The engine does not return the individual matching rules to the cell, so the
ID names the deciding rule and the cell's audit log lists the rest. Blocks
appear in the domain's security log and in the **Security** view of analytics,
and count as `waf` in security-class panels. A domain's own WAF blocks do not
move it toward a restricted state; see [Security](security.md).

 A separate minimal ModSecurity
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
