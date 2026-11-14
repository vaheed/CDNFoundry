---
title: Managed WAF reference
description: Every managed WAF setting (protection level, paranoia level, anomaly threshold, categories, single rules, log only) with what it does, when to use it, and examples.
---

# Managed WAF reference

The managed WAF is the OWASP Core Rule Set (CRS) running in ModSecurity inside each cell. The ruleset version is pinned in the
cell image and shown in the WAF sheet and in `GET /api/domains/{domain}/waf`. Cells never download rules. The
[Managed WAF guide](../guides/managed-waf.md) explains the workflow; this page lists every setting with examples.

How a request is judged: each CRS rule that matches adds anomaly points (2 for a notice, 3 for a warning, 4 for an error,
5 for a critical match). When the total reaches the **anomaly threshold**, a blocking level refuses the request with 403. The
same total becomes the [attack score](security-rules.md#detection-fields) that custom rules can use.

## Protection level

| Panel | API `profile` | Paranoia | Threshold | Body inspected | Blocks |
| --- | --- | --- | --- | --- | --- |
| Off | `off` | none | none | none | no |
| Observe | `monitor` | 1 | 5 | first 1 MiB | no, only records |
| Recommended | `balanced` | 1 | 5 | first 1 MiB | yes |
| High sensitivity | `strict` | 2 | 3 | first 256 KiB | yes |

When to use each:

- **Off**: static sites behind the cache with no forms or API. Custom rules and rate limits still run.
- **Observe**: always the first step for a new domain. Read the security events for a few days.
- **Recommended**: the normal level once Observe showed no false positives on real traffic.
- **High sensitivity**: login, payment, or admin hosts after the application was tested at this level.

Under attack mode raises any level to blocking at high sensitivity while it is on, then restores yours.

```sh
curl -X PATCH "$CDNF_URL/api/domains/$DOMAIN_ID/waf" \
  -H "Authorization: Bearer $CDNF_TOKEN" -H "Idempotency-Key: $(uuidgen)" \
  -H "Content-Type: application/json" -d '{"profile": "balanced"}'
```

## Paranoia level

`tuning.paranoia_level`, 1 to 4, replaces the level's own paranoia. Each level adds stricter rules on top of the ones below.

| Level | Adds | Typical use |
| --- | --- | --- |
| 1 | Rules with very few false positives | Every site |
| 2 | More SQL injection, XSS, and protocol checks | Sites with simple forms; login and admin hosts |
| 3 | Checks on special characters and rare encodings | APIs with strict, known inputs |
| 4 | The strictest checks; many false positives on normal text | Only short-lived tests |

Examples:

```json
{"tuning": {"paranoia_level": 2}}
```

A shop on Recommended that sees probes the default misses: raise paranoia to 2, keep the threshold at 5, watch in Observe
first.

## Anomaly threshold

`tuning.inbound_threshold`, 1 to 100, the score at which a request is blocked. A **lower** number blocks **more**.

| Threshold | Effect |
| --- | --- |
| 3 | One warning blocks. High sensitivity's own value |
| 5 | One critical match, or two smaller ones, block. Recommended's own value |
| 8 to 10 | Needs several matches. A first step for an application with many false positives |
| 20 and above | Nearly never blocks; prefer Observe instead |

Examples:

```json
{"tuning": {"inbound_threshold": 8}}
```

```json
{"tuning": {"paranoia_level": 2, "inbound_threshold": 7}}
```

The second raises the strictness of the checks and asks for more evidence before a block, a common pairing for an API.

## Categories

`tuning.disabled_categories` turns off every rule of a category for the domain.

| API value | Panel | CRS rule range | Turn it off when |
| --- | --- | --- | --- |
| `method` | Method enforcement | 911 | Your application uses methods such as `PROPFIND` (WebDAV) |
| `scanner` | Scanner detection | 913 | You run your own scanner against the site; better: an `allow` rule for its address |
| `protocol` | Protocol enforcement | 920 | Old clients send unusual but harmless headers |
| `protocol_attack` | Protocol attacks | 921 | Rarely; these catch request smuggling |
| `multipart` | Multipart attacks | 922 | A known upload client sends unusual multipart bodies |
| `lfi` | Local file inclusion | 930 | Paths legitimately contain `../` style segments |
| `rfi` | Remote file inclusion | 931 | Parameters legitimately carry full URLs |
| `rce` | Command injection | 932 | Users post shell snippets (a developer forum) |
| `php` | PHP injection | 933 | The site is not PHP and posts PHP code as text |
| `generic` | Generic injection | 934 | Users post JavaScript or Node code as text |
| `xss` | Cross-site scripting | 941 | An editor posts HTML; better: `skip_managed` on its path |
| `sqli` | SQL injection | 942 | Users post SQL as text (a database tutorial) |
| `session_fixation` | Session fixation | 943 | Rarely |
| `java` | Java injection | 944 | The site is not Java and posts Java code as text |

```json
{"tuning": {"disabled_categories": ["php", "java"]}}
```

Prefer the narrowest fix: a category turned off stops protecting every path. A custom rule with `skip_managed` on one path,
or one rule turned off, keeps the rest.

## Single rules

`tuning.disabled_rules` and `tuning.log_only_rules` each take up to 100 rule numbers from the pinned catalog. A rule cannot
be in both lists. The panel searches by number or by message. `GET .../waf/rules?search=942100` lists a rule with its state.

| List | The rule | Security event |
| --- | --- | --- |
| `disabled_rules` | never runs for the domain | none |
| `log_only_rules` | runs, but adds no points and no score | `waf_rule_logged` when it matches |

Examples:

```json
{"tuning": {"disabled_rules": [942100]}}
```

SQL injection detected by libinjection fires on a search box that accepts quotes: turn off 942100 rather than the whole SQL
injection category.

```json
{"tuning": {"log_only_rules": [920350]}}
```

"Host header is a numeric IP address" (920350) on a health checker that calls the address directly: keep seeing it, stop it
from counting.

```json
{"tuning": {"log_only_rules": [913100], "disabled_rules": [920420]}}
```

Watch scanner user agents (913100) without blocking them, and stop rule 920420 (request content type not allowed) for an API
that posts `application/x-ndjson`.

## A full tuning object

```json
{
  "profile": "balanced",
  "tuning": {
    "paranoia_level": 2,
    "inbound_threshold": 7,
    "disabled_categories": ["java"],
    "disabled_rules": [942100],
    "log_only_rules": [920350, 913100]
  }
}
```

The `tuning` object replaces the saved one as a whole. Leave it out to keep what is saved; send `"tuning": null` to clear
every adjustment. Tuning never turns an Off domain on.

## Choosing between the WAF and a custom rule

| You want to | Use |
| --- | --- |
| Stop a false positive on one path | A custom rule with `skip_managed` on that path |
| Stop one rule everywhere | `disabled_rules` |
| Keep a rule visible but harmless | `log_only_rules` |
| Block only near-certain attacks while staying in Observe | Observe plus a custom rule `attack_score < 20` with `block` |
| Block one kind of attack harder | A custom rule on `attack_score_sqli` or `attack_score_xss` |
| Trust a scanner or a partner network | A custom rule with `allow` on its address; it does not skip the WAF, so add `skip_managed` too |

## What is never shown

Blocked visitors get a status code, never the matched data or the rule text. Security events carry the rule reference, the
score, and the action, never parameter values or bodies. See [Security events](../guides/security.md#security-events).

## Ruleset updates

The ruleset version changes only with a platform release: the cell image is rebuilt with the new CRS, the adjustable rule
catalog is regenerated from it, and both ship together. Rule numbers you disabled keep working when the new catalog still has
them. A number the new catalog no longer has is ignored by the cells, and the next save refuses it until you remove it. Administrators check for a newer
upstream release with `make waf-version-check`.
