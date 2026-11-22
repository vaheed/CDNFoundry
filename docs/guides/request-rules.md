---
title: Redirects, rewrites, headers, and cache rules
description: Choose the right request rule, add a redirect in the domain panel or API, check the deployed result, and undo a change safely.
---

# Redirects, rewrites, headers, and cache rules

Use **Rules** on a domain to change how the edge handles a request. You can
redirect a visitor, change the URL sent to the origin, change headers, or choose
a cache policy for matching traffic.

**You need:** an account allowed to update the domain, an active proxied
hostname, and a working origin. Start with [First domain](../getting-started/first-domain.md)
if the hostname does not serve through the CDN yet.

<Screenshot name="app-request-rules" alt="The domain Rules tab showing ordered redirects, their conditions, targets, and enabled state in the seeded test stack." />

## Choose the right control

| You want to | Open | What happens |
| --- | --- | --- |
| Send visitors to another URL | **Rules → Redirects** | The edge answers with a redirect; the origin is not contacted |
| Keep the browser URL but change the origin path or query | **Rules → URL rewrites** | The edge changes the upstream URL |
| Set or remove request or response headers | **Rules → Headers** | Every matching header rule applies in order |
| Bypass cache or override lifetimes for some requests | **Rules → Cache** | The first matching cache rule supplies the policy |
| Set the domain's default cache behavior or purge content | **Overview → Cache** | Domain defaults and purge actions remain separate from rules |
| Block, allow, log, challenge, or skip managed inspection | **Firewall rules** | [Firewall rules](security.md#firewall-rules) decide access |
| Detect application attacks | The domain's **Web application firewall (WAF)** setting | [Managed WAF](managed-waf.md) uses ModSecurity and OWASP CRS |
| Limit request frequency | **Firewall rules**, with a rate limit | A matching rule applies a bounded per-cell limit |

Request rules run after security checks, including challenges and rate limits.
A redirect cannot bypass them. The domain's HTTPS redirect and maintenance
response also run before request rules.

The types run in this order: **redirects → rewrites → headers → cache**.
Redirects, rewrites, and cache rules use the first match within their type.
Header rules apply every match in order. Conditions always read the original
visitor request, even after another rule changes its path or headers.

## Redirect an old path

Use a temporary redirect while checking the result. Browsers can retain a
permanent 301 or 308 after you remove the rule.

<DocTabs :labels="['Domain panel', 'API']">
<template #tab-1>

1. Open **Domains**, select the domain, then open **Rules → Redirects**.
2. Choose **Add redirect**. Add the condition **Path equals `/old`**.
3. Choose a fixed URL, enter `/new`, and choose status **302**. Keep the query
   string if the destination needs the visitor's query arguments.
4. Check the sentence that describes the rule, then save it.
5. Put a narrow exception above a broader rule with **Move up** or **Move down**.

**Expected result:** the rule appears in the list and a new domain revision is
queued. Saving a rule does not mean every edge has activated it yet.

</template>
<template #tab-2>

Set `CDNF_URL`, `CDNF_TOKEN`, and `DOMAIN_ID` for your own test domain. The token
must belong to an account with access to that domain. Use a fresh UUID for a
new mutation; reuse it only when retrying the same request.

```sh
curl --fail-with-body --request POST \
  "$CDNF_URL/api/domains/$DOMAIN_ID/request-rules" \
  --header "Authorization: Bearer $CDNF_TOKEN" \
  --header "Idempotency-Key: $(uuidgen)" \
  --header "Content-Type: application/json" \
  --data '{"type":"redirect","name":"Old path","priority":100,"enabled":true,"conditions":[{"field":"path","operator":"equals","value":"/old"}],"action":{"target":"url","url":"/new","status_code":302,"preserve_query":true}}'
```

**Expected result:** HTTP `202` with the rule and `data.operation_id`. Follow
that operation through `GET /api/operations/{operation}`. Follow cursor links
when listing rules. See [API conventions](../reference/api/index.md) for
authentication, replay, and error handling.

</template>
</DocTabs>

## Check the deployed result

Wait for the new revision's operation and edge acknowledgements. Then send a
request to your proxied hostname, without following redirects:

```sh
curl --silent --show-error --head 'https://www.example.com/old?source=test'
```

Expect status `302` and a `Location` ending in `/new?source=test`. A different
result can mean an earlier rule matched, a security check stopped the request,
or the edge is still using its previous revision. Compare the saved order and
deployment status before changing the origin.

Raw access events carry matching rule IDs in `request_rule`, such as
`redirect:12`, with at most eight entries. Operators can inspect that field in
ClickHouse. The standard domain log API does not currently return it, so use
response checks and deployment acknowledgements for the domain-owner workflow.
See the [telemetry schema](../reference/telemetry-schema.md).

## Use a preset

Open **Presets** within the rule type. Presets include HTTP-to-HTTPS and hostname
redirects, prefix changes, security headers, hiding origin software headers,
static-file caching, and bypasses for sign-in paths or cookies.

Review the values before saving. A preset creates ordinary rules; saving the
same preset again replaces its rules. Editing a generated rule turns it into
an ordinary rule. Cache bypass presets precede the static-file cache preset.
The [reference](../reference/request-rules.md#presets) lists all presets and
their inputs.

Only enable HSTS after HTTPS works for every hostname it covers. Do not combine
opposing redirects, such as apex-to-www and www-to-apex.

## Rewrites, headers, and cache interactions

- A rewrite changes the origin URL. Cache lookup and URL purge still use the
  visitor's original address, so purge that address.
- Header rules cannot change framing, forwarding, compression, or platform
  decision headers. For example, `X-Forwarded-For` is set by the edge and is
  rejected as a rule target. See [protected headers](../reference/request-rules.md#header-rules).
- A cache override does not make authenticated, range, development-mode, or
  otherwise ineligible requests safe to cache. Check
  [cache admission](cache.md) before raising a TTL.
- Each domain can hold 50 rules of each type, with up to five conditions per
  rule. Conditions use fixed fields and operators; regular expressions and
  custom expression languages are not supported.

The [request rule reference](../reference/request-rules.md) is the canonical
source for conditions, actions, limits, preset API values, and rollback behavior.

## Undo or diagnose a change

Disable or delete the rule and wait for the next revision to activate. For a
preset, use its removal action. A failed replacement leaves the last valid edge
bundle active; inspect the operation error, correct the input, and publish a
forward change. Avoid disabling security to test a redirect.

The old cache **path rules** have moved to **Rules → Cache**. Existing path
rules were migrated in their original order. Domain cache settings no longer
accept `path_rules`. Use [Cache and purge](cache.md) for domain defaults and
purge verification, or [Edge and origin troubleshooting](../troubleshooting/edge-and-origin.md)
when the request cannot reach the CDN or its origin.
