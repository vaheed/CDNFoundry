---
title: Security and DDoS readiness
description: Write typed custom rules, choose a limit profile, and use the incident controls, with their bounds and recovery steps.
---

# Security and DDoS readiness

CDNFoundry gives each domain bounded application-layer controls: custom rules,
rate and connection limits, and incident actions. This page shows how to
use them and what each one does.

::: warning Not volumetric scrubbing
These controls do not absorb a flood that fills a host or an uplink. Such attacks
need upstream transit controls or provider scrubbing.
:::

```mermaid
flowchart TD
    subgraph Gate["Availability gate"]
      Request["Incoming request"] --> Maintenance{"Maintenance?"}
      Maintenance -- Yes --> Response["Bounded HTTP 503"]
    end
    subgraph Enforce["Layered enforcement"]
      Maintenance -- No --> Client["Trusted client IP"] --> Rules["Custom rules"]
      Rules --> Profile["Profile ceilings"] --> Limits["Rate + concurrency"]
      Limits --> Method["Method + size bounds"] --> Serve["Cache or origin"]
    end
    subgraph Observe["Outcome"]
      Response --> Event["Reason + telemetry"]
      Serve --> Event
    end
```

| Layer | Purpose |
| --- | --- |
| Trusted-client parsing | Trust forwarded addresses only from configured proxies |
| Custom rules | Up to five typed conditions and one action per rule |
| Profiles | Fixed presets or bounded manual values |
| Readiness | Remove stale, drained, failed, or overloaded targets |
| Incident controls | Explicit actions for a domain, pool, cell, or edge |

For application attack detection, see [Managed WAF](managed-waf.md).

## Custom rules

**You need:** update rights on the domain.

A rule is up to five conditions that must all match, and one action. Rules are
typed: every condition is a field from a fixed list, an operator from a fixed
list, and a value. There are no regular expressions and no expression language.

<DocTabs :labels="['Domain panel', 'API']">
<template #tab-1>

Open the domain, then **Custom rules**. **Add rule** opens a sheet with the
conditions, the action, and a "Reads as" line that shows the rule in words.
**Move up** and **Move down** change the order, and **Import rules** adds many.

</template>
<template #tab-2>

```sh
curl -X POST "$CDNF_URL/api/domains/$DOMAIN_ID/security/rules" \
  -H "Authorization: Bearer $CDNF_TOKEN" \
  -H "Idempotency-Key: $(uuidgen)" \
  -H "Content-Type: application/json" \
  -d '{
    "name": "Admin from abroad",
    "conditions": [
      {"field": "path", "operator": "starts_with", "value": "/admin"},
      {"field": "country", "operator": "not_equals", "value": "DE"}
    ],
    "action": "block", "priority": 100
  }'
```

`POST .../security/rules/preview` validates a rule and returns it normalized with
its position, without saving it. `PUT .../security/rules/order` takes the full
list of rule IDs in the new order. Bulk import is `POST .../security/rules/import`.

</template>
</DocTabs>

**Expected result:** the rule is saved, the domain revision rises, and the edges
receive a new signed revision.

### Fields and operators

| Field | Operators | Value |
| --- | --- | --- |
| `hostname`, `path`, `user_agent`, `referer`, `content_type` | equals, not equals, starts with, ends with, contains, in list | Text, up to 512 characters |
| `header` (with `name`) | the same | Text. The header name is case-insensitive |
| `query_present`, `cookie_present` (with `name` for the cookie) | equals | `true` or `false` |
| `method` | equals, not equals, in list | `GET`, `HEAD`, `POST`, `PUT`, `PATCH`, `DELETE`, `OPTIONS` |
| `country`, `continent` | equals, not equals, in list | A supported code |
| `asn` | equals, not equals, in list, greater than, less than | 0 to 4294967295 |
| `client_ip` | equals, not equals, in list | An IPv4 or IPv6 address or CIDR |
| `body_size` | equals, not equals, greater than, less than | Bytes, up to 1 GiB |
| `referer_host` | equals, not equals, in list, not in list | The host of the Referer header, such as `www.example.com` |
| `referer_present` | equals | `true` when the request has a Referer with an address |
| `file_extension` | equals, not equals, in list, not in list | The extension of the path in lower case, without the dot |
| `tls_version` | equals, not equals, in list | `TLSv1`, `TLSv1.1`, `TLSv1.2`, `TLSv1.3`, or `none` for plain HTTP |
| `attack_score`, `attack_score_sqli`, `attack_score_xss`, `attack_score_rce` | equals, not equals, in list, greater than, less than | 1 to 99 |

Hostname, user agent, referer, header, and content type compare without regard to
case. Path compares exactly and uses the decoded request path without the query
string. A visitor whose country, continent, or ASN is unknown never matches
"equals" or a comparison, and does match "not equals".

### Attack score

Every request the managed WAF inspects gets a score from 1 (attack) to 99 (clean).
It is 99 minus five points for each anomaly point the OWASP Core Rule Set adds,
never below 1, so a request that trips several rules scores near 1 and a clean
request scores 99. `attack_score` counts every category. The three other fields
count only SQL injection, cross-site scripting, or command injection signs.

- A custom rule on `attack_score < 20` blocks requests that are very likely
  attacks, in any profile. It runs before the managed rules and can also `log`
  or `challenge`.
- The Observe profile scores and logs without blocking. The score is stored on
  every request event as `attack_score` and its three category fields (0 means the
  request was not scored).
- A domain whose WAF profile is Off is scored only while a custom rule uses an
  attack score field, at paranoia level 1, and is never blocked by the managed
  rules.
- A request that could not be scored never matches an attack score condition. A
  body larger than the profile's body limit is not sent for scoring, so only its
  headers and address count.
- Scoring happens once per inspected request on the cell, over a loopback
  listener, and never calls Laravel or any outside service. A blocking profile
  refuses a request at its anomaly threshold from the same score, before the
  request is inspected again on its way to the origin.

### Rate-limit rules

A rate-limit rule is a custom rule that also counts. Turn on **Limit the request
rate** in the rule sheet, or send a `rate_limit` object in the API:

```json
{"conditions": [{"field": "path", "operator": "equals", "value": "/login"}],
 "action": "block", "priority": 10,
 "rate_limit": {"key": "client_ip_path", "threshold": 20, "period_seconds": 60, "duration_seconds": 300}}
```

- `key` is what one client is: `client_ip`, `client_ip_path` (the address on one
  path), or `header` with a `header_name` such as `X-Api-Key`. A request without
  that header is not counted.
- `threshold` requests per `period_seconds` (10, 60, or 600) is the allowance.
  The 21st request in the example goes over.
- Past the allowance the action applies to that key for `duration_seconds` (10 to
  86400), then the key is released and starts again with a fresh allowance.
- The action is `block` (answers 429, `custom_rule_rate_limited`), `challenge`, or
  `log`.
- A rate-limit rule with no conditions counts every request of the domain. Only a
  rate-limit rule may have no conditions.
- Rate-limit rules run after the domain's other custom rules and before the
  managed WAF. A client that an `allow` rule trusts is not counted.
- **Counts are per cell.** Each cell counts on its own with fixed time windows,
  so this is not a global count across locations. Over several cells a client can
  send up to the threshold on each one.
- A domain may have 20 rate-limit rules (a platform setting, never above 50). The
  counters live in fixed shared memory on the cell; when it is full, requests are
  let through, never blocked.

### Presets: user agents and hotlinks

Two presets write ordinary custom rules, so they follow the same order, limits,
and revisions as any rule. In the panel they are under **Presets** on the rules
tab. In the API they are `PUT` and `DELETE` on
`/api/domains/{domain}/security/presets/user-agent` and `.../hotlink`, and
`GET .../security/presets` reads them back.

- **User agents.** A list of up to 50 words and an action, `block` or `challenge`.
  Each word becomes one rule that matches when the user agent contains it, without
  regard to case. Saving again replaces the whole set in one revision.
- **Hotlink protection.** A list of file extensions and a list of allowed referer
  hosts. One rule blocks requests for those extensions that carry a Referer from
  any other host. Your own domain and its `www` form are always allowed, and a
  request with no Referer is never blocked. Hosts match exactly, so list each one
  you want, such as `www.partner.example`. The port and any user name in the
  Referer are ignored.
- A preset rule that you edit by hand becomes an ordinary rule, and the preset no
  longer lists it. Deleting a preset removes only its own rules.

### Actions

| Action | Effect |
| --- | --- |
| `block` | Answers 403 (`custom_rule_block`). |
| `challenge` | Asks a browser for the same short proof of work as the incident profile. A solved challenge lasts one hour. Scripts without a token get 403. |
| `log` | Records a `custom_rule_log` security event and continues. |
| `allow` | Stops evaluating custom rules and skips the challenge. |
| `skip_managed` | Turns the managed WAF off for matching requests. Body size limits still apply. |

### How rules run

- A lower priority number runs first. The rule ID breaks ties. Disabled rules are
  ignored. Only `block` and `allow` end evaluation; the other actions let later
  rules run.
- A domain may have up to 1,000 rules, a list condition up to 100 values, and one
  import up to 500 rules. The list limit is a platform setting that can only be
  lowered.
- A rule with the same conditions and action as another rule of the domain is
  refused. An import normalizes every rule, rejects duplicates, and can append to
  or replace the existing set in one revision.
- A rule the cell cannot read (no conditions, more than five, or an unknown field)
  never matches and never stops the cell.
- Custom rules never count against the domain: `custom_rule_block`,
  `custom_rule_challenge`, and `custom_rule_log` are recorded but do not raise the
  automatic security state.

::: warning Upgrading from 1.x
The old IP, CIDR, country, and continent rules are converted in place by one
migration, keep their IDs and priorities, and become one condition each. The old
rule shape is gone from the API. The migration cannot be reversed, so run it as
an explicit upgrade step with a database backup.
:::

## Profiles

A profile sets the ceilings for request rate and burst, client and domain
connections, TLS handshakes, body and header size, timeouts, keepalive, request
duration, origin concurrency and retries, cache key length, and cache admission.

- `standard`, `protected`, and `quarantine` are fixed platform ceilings.
- `manual` accepts values up to the standard ceiling. It cannot exceed platform
  capacity policy.

Choose **Security profile and limits** on the domain, or use
`PATCH /api/domains/{domain}/security`. The exact ceilings are in
[Limits](../reference/limits.md).

TLS handshakes are limited in two places. The edge gateway sees the real client
address. It allows each client prefix (one IPv4 address or one IPv6 /64) at most
the profile's per-second budget of new TLS connections per hostname. One client
cannot spend the budget other clients of the domain need. The cell adds a
separate, higher ceiling for the whole domain, 20 times that budget.

## Security state

A domain's security state is `normal`, `suspected`, `restricted`, `quarantined`,
or `recovering`. Two sources set it, and the domain shows the stricter of the two.

- **Operator state.** Administrator actions (Under Attack mode, quarantine, return to
  normal) are desired configuration. Each one is a configuration revision with an
  author, and it is delivered in the signed domain snapshot.
- **Automatic state.** Agent heartbeats carry a bounded set of noisy-domain summaries
  and reason codes. When the rate of events crosses a threshold, the platform moves
  the domain to `suspected` or `restricted` on its own. This is not a configuration
  revision and never changes the snapshot. It travels to the edges as a small
  per-domain overlay with its own increasing sequence, the way purge epochs travel.
  A cell enforces the stricter value of the snapshot and the overlay for every limit,
  keeps the overlay across a restart because the agent replays it, and ignores an
  overlay with an older sequence.

The rate is the number of events in the last five minutes: `suspected` at 50 or more,
and `restricted` at 100 or more when the domain's quarantine policy is automatic. It
is measured as the increase since the previous report, not as the cell's lifetime
counter, so a counter that keeps growing or restarts does not trigger a change. A
domain moves back one step (`restricted` to `recovering` to `normal`, or `suspected`
to `normal`) after ten minutes without a new event. A domain an operator has
restricted or quarantined is never changed automatically.

A domain's own protections never count against it. A request refused by a customer
custom rule (`custom_rule_block`) or an operator restriction (`domain_restricted`), a method restriction (`invalid_method`), an
emergency mode (`edge_emergency_mode`), an incident profile refusal (`incident_method`,
`incident_body_size`, `challenge_required`, `challenge_invalid`), or an unknown host or SNI is
still recorded as an event, but it does not raise the rate.

Edge-reported security events keep one row per domain, edge, reason, and hour, and a
daily job deletes events beyond `security_event_retention_days`. Every automatic
change is written to the audit log with its cause, and the domain's **History** tab
lists operator actions and automatic changes separately, with a filter. A returned
state shows in the API as `automatic_state`, `configured_state`, and the stricter
`state`.

Quarantine uses the normal placement protocol: CDNFoundry delivers the domain to
the quarantine pool, publishes its addresses, then drains the shared source.

## Incident controls

Each scope has only controls with clear runtime behavior. A cell enforces seven
emergency actions and rejects any other name with `400`, so it never reports a
protection that does nothing. The seven are `disable_request_bodies`,
`allow_get_head_only`, `disable_origin_retries`, `serve_cache_only`,
`serve_stale_only`, `return_maintenance_response`, and `disable_compression`.

| Scope | Control | Who | Effect |
| --- | --- | --- | --- |
| Domain | **Enable Under Attack mode** | Administrator | Tightens request and connection limits for a time. It does not move the domain or change the WAF. |
| Domain | **Quarantine domain** | Administrator | Deploys the domain to the quarantine pool, target first. |
| Domain | **Start maintenance** | Domain owner | Returns the configured HTTP 503 for that domain only. |
| Edge | Drain | Administrator | Stops new placement and traffic for that edge. |
| Cell | Drain, Restart | Administrator | Removes one cell from readiness, or restarts it. |
| Pool | Maintenance | Administrator | Returns HTTP 503 from the pool's cells until its required expiry. |
| Pool | Withdraw | Administrator | Removes the pool from gateway and DNS publication. It does not control BGP. |

The domain actions for Under Attack mode and quarantine are
`POST /api/admin/domains/{domain}/protect` and `.../quarantine`. Pool actions
include `POST /api/admin/edge-pools/{pool}/maintenance` and `.../withdraw`.

The WAF level is independent from Under Attack mode. The WAF detects common
application attacks all the time, at the domain's Off, Observe, Recommended, or
High sensitivity choice. Under Attack mode is an administrator response to
active abusive load.

### Recover

Use the inverse action: **Return to normal**, **End maintenance**, **Undrain**,
or **Restore** (pools: `DELETE .../maintenance` and `POST .../restore`; domains:
`POST .../return-to-normal`).

Pool maintenance is stored in PostgreSQL, sent as durable per-edge tasks only to
assigned cells, audited, and expires on its own. Domain maintenance is part of the
normal signed domain revision. A failed deployment therefore keeps the previous
valid edge snapshot.

The old `quarantine_domain` pool and edge flag is gone. It had no domain identity
and could not change placement. Quarantine is only the explicit domain action.
