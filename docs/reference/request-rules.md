---
title: Request rules
description: Redirect rules on a domain, decided at the edge from the signed snapshot: targets, status codes, conditions, presets, limits, and the API.
---

# Request rules

Request rules change what a request does at the edge without touching your origin. Each rule has up to **five
conditions** that must all match, from the same condition builder as [custom rules](security-rules.md), and one
action of its type. The edge decides every rule from the signed configuration it already holds; no request waits for
the control plane.

The first type is **redirects**. URL rewrites, header changes, and conditional cache rules follow as the next parts of
the [roadmap](../roadmap.md#phase-12-request-and-response-rules).

Rules are on the domain page, in the **Rules** tab, one tab per type.

## When rules run

Request rules run after every security stage: custom rules, rate limits, the managed WAF, challenges, and the
built-in limits. A blocked or challenged request is never redirected, and a redirect never lets a request skip a
security rule. The HTTPS redirect and maintenance mode also come first.

Rules of one type run from the lowest **order** number up. For redirects, the first matching rule answers the
request and the rest are skipped.

## Conditions

A request rule offers the fields that are known before any detection runs, so a rule never makes a request pay for
WAF scoring:

hostname, path, query string, method, country, continent, ASN, client IP, user agent, referer, referer host, referer
present, file extension, header, cookie, and TLS version.

Each field takes the operators listed in the [custom rule reference](security-rules.md#operators). Attack scores,
leaked passwords, AI prompt checks, and API schema fields are not offered.

## Redirects

The edge answers with a redirect and the request never reaches your origin.

| Target | What the visitor is sent to | Example |
| --- | --- | --- |
| A fixed URL | The URL, as written | `/new`, or `https://shop.example/sale` |
| The same path on another host | The other host, with the request's path | `www.example.com` sends `/a/b` to `http://example.com/a/b` |
| Replace a path prefix | The path with the old prefix replaced | `/blog` to `/news` sends `/blog/2026/post` to `/news/2026/post` |

| Setting | Values | Notes |
| --- | --- | --- |
| Status | 301, 302, 307, 308 | 301 and 308 are permanent and browsers cache them. 307 and 308 keep the method and body of a POST. |
| Keep the query string | on or off | Adds the request's query string. A fixed URL with its own query keeps both, joined with `&`. |
| Scheme (same path on another host) | Same as the request, or always HTTPS | |

A prefix redirect matches every request whose path starts with its old prefix, so it needs no other condition. You
can still add conditions to narrow it, for example to one hostname.

### What is refused

- A URL that is not a path (`/new`) or an `http` or `https` address with a valid host. Addresses with a user name or
  password, `//host` paths, spaces, and control characters are refused.
- A rule whose fixed target is the path it matches, for example **Path equals /same** to `/same`.
- A redirect to the same host that a **Hostname equals** condition matches.
- A prefix redirect whose new prefix starts with the old one (`/old` to `/old-site`): the target would match again.
- Score and detection fields, and more than five conditions.

The edge also never sends a redirect to the address the request already has. A rule that would do that is skipped,
and the request continues to the next rule or to your origin.

## Presets

Presets write ordinary rules marked with the preset's name. Saving a preset again replaces its rules, and editing one
of its rules by hand turns that rule into an ordinary rule.

| Preset | What it writes |
| --- | --- |
| Redirect www to the apex domain | **Hostname equals www.example.com**, to the same path on `example.com`, keeping the query string |
| Redirect the apex domain to www | **Hostname equals example.com**, to the same path on `www.example.com`, keeping the query string |
| Move paths to new prefixes | One prefix redirect per move, up to 20 moves, keeping the query string |

Each preset asks for the status code. Preset rules are placed after rules you number by hand (order 500 and up).

## Limits

| Limit | Value |
| --- | --- |
| Rules of one type per domain | 50 |
| Conditions per rule | 5 |
| Redirect target length | 2,048 bytes |
| Prefix length | 512 bytes |
| Moves in the path preset | 20 |

## Logs

Every request a rule acted on carries the rule in the access log, as `redirect:<id>` in the `request_rule` field.
Requests no rule changed carry an empty value.

## API

| Method and path | What it does |
| --- | --- |
| `GET /api/domains/{domain}/request-rules?type=redirect` | Lists the rules, in order, with cursor pagination |
| `POST /api/domains/{domain}/request-rules` | Adds a rule. Returns `201` with the rule and the operation ID |
| `PATCH /api/domains/{domain}/request-rules/{rule}` | Replaces a rule. Returns `202` with the operation ID |
| `DELETE /api/domains/{domain}/request-rules/{rule}` | Removes a rule. Returns `202` |
| `PUT /api/domains/{domain}/request-rules/order` | Sets the order of one type: `{"type": "redirect", "order": [ids]}` with every rule of that type once |
| `PUT /api/domains/{domain}/request-rules/presets/{preset}` | Saves a preset: `www_to_apex`, `apex_to_www`, or `move_paths` |
| `DELETE /api/domains/{domain}/request-rules/presets/{preset}` | Removes a preset's rules |

Mutations accept `Idempotency-Key`. A rule in the API looks like this:

```json
{
  "type": "redirect",
  "name": "Old blog",
  "priority": 100,
  "enabled": true,
  "conditions": [
    {"field": "hostname", "operator": "equals", "value": "www.example.com"}
  ],
  "action": {
    "target": "prefix",
    "from_prefix": "/blog",
    "to_prefix": "/news",
    "status_code": 301,
    "preserve_query": true
  }
}
```

The other targets use `"target": "url"` with `url`, or `"target": "host"` with `host` and `scheme` (`same` or
`https`). A second rule with the same type, conditions, and action is refused, and so is a 51st rule of one type
(`409`).
