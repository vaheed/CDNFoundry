---
title: Request rules
description: Redirects, URL rewrites, header rules, and cache rules on a domain, decided at the edge from the signed snapshot, with their presets, limits, and API.
---

# Request rules

Request rules change what a request does at the edge without touching your origin. Each rule has up to **five
conditions** that must all match, from the same condition builder as [firewall rules](security-rules.md), and one
action of its type. The edge decides every rule from the signed configuration it already holds; no request waits for
the control plane.

There are four types:

| Type | What it changes | Which rules apply |
| --- | --- | --- |
| [Redirects](#redirects) | Answers with a redirect; the origin never sees the request | The first match |
| [URL rewrites](#url-rewrites) | The path and query string your origin receives | The first match |
| [Header rules](#header-rules) | Request headers sent to the origin and response headers sent to the visitor | Every match, in order |
| [Cache rules](#cache-rules) | Whether a request is cached, and for how long | The first match |

Rules are on the domain page, in the **Rules** tab, with one tab per type: **Redirects**, **URL rewrites**,
**Headers**, and **Cache**.

## When rules run

Request rules run after every security stage: firewall rules, rate limits, the managed WAF, challenges, and the
built-in limits. A blocked or challenged request is never redirected, and a redirect never lets a request skip a
security rule. The HTTPS redirect and maintenance mode also come first.

Within one request the types run in this order: redirects, then rewrites, then header rules, then cache rules. A
redirect ends the request, so nothing after it runs. Rules of one type run from the lowest **order** number up.

Every condition matches the request as the visitor sent it. A rewrite does not change what a later header or cache
rule sees, and a request header set by a rule is not visible to another rule's conditions.

## Conditions

A request rule offers the fields that are known before any detection runs, so a rule never makes a request pay for
WAF scoring:

hostname, path, query string, method, country, continent, ASN, client IP, user agent, referer, referer host, referer
present, file extension, header, cookie, and TLS version.

Each field takes the operators listed in the [firewall rule reference](security-rules.md#operators). Attack scores,
leaked passwords, AI prompt checks, and API schema fields are not offered.

## Redirects

The edge answers with a redirect and the request never reaches your origin.

| Target | What the visitor is sent to | Example |
| --- | --- | --- |
| A fixed URL | The URL, as written | `/new`, or `https://shop.example/sale` |
| The same path on another host | The other host, with the request's path | `www.example.com` sends `/a/b` to `http://example.com/a/b` |
| Replace a path prefix | The path with the old prefix replaced | `/blog` to `/news` sends `/blog/2026/post` to `/news/2026/post` |
| The same address over HTTPS | The same host and path with `https://` | Used by the **Redirect HTTP to HTTPS** preset |

| Setting | Values | Notes |
| --- | --- | --- |
| Status | 301, 302, 307, 308 | 301 and 308 are permanent and browsers cache them. 307 and 308 keep the method and body of a POST. |
| Keep the query string | on or off | Adds the request's query string. A fixed URL with its own query keeps both, joined with `&`. |
| Scheme (same path on another host) | Same as the request, or always HTTPS | |

A prefix redirect matches every request whose path starts with its old prefix, so it needs no other condition. You
can still add conditions to narrow it, for example to one hostname.

### What a redirect refuses

- A URL that is not a path (`/new`) or an `http` or `https` address with a valid host. Addresses with a user name or
  password, `//host` paths, spaces, and control characters are refused.
- A rule whose fixed target is the path it matches, for example **Path equals /same** to `/same`.
- A redirect to the same host that a **Hostname equals** condition matches.
- A prefix redirect whose new prefix starts with the old one (`/old` to `/old-site`): the target would match again.
- Score and detection fields, and more than five conditions.

The edge also never sends a redirect to the address the request already has. A rule that would do that is skipped,
and the request continues to the next rule or to your origin.

## URL rewrites

A rewrite changes the path and query string your origin receives. The visitor's address bar does not change, and
neither does the cache key or URL purge (see [Cache key and purge](#cache-key-and-purge)). The first matching rewrite
applies.

| Path | What the origin receives | Example |
| --- | --- | --- |
| Keep the path | The request's path | Use it to change only the query string |
| Set a fixed path | The fixed path | `/index.php` for every matching request |
| Replace a path prefix | The path with the old prefix replaced; an empty new prefix removes it | `/api` to nothing sends `/api/users` as `/users` |

| Query string | What the origin receives |
| --- | --- |
| Keep the query string | The request's query string |
| Remove the query string | No query string |
| Set a fixed query string | The fixed text, such as `lang=en&page=1` |

A prefix rewrite matches every request whose path starts with its old prefix, so it needs no other condition. The
other rewrites need at least one condition. The origin's address and its `Host` header never change.

### What a rewrite refuses

- A rewrite that keeps both the path and the query string: it would change nothing.
- A path that does not start with `/`, or that contains a `.` or `..` segment, also when it is percent-encoded
  (`%2e%2e`). The cell checks the result again and never sends a path that leaves the root.
- A new prefix equal to the old one.
- A query string with spaces, control characters, or `#`, or longer than 1,024 bytes. A leading `?` is removed.

## Header rules

A header rule sets or removes request headers sent to your origin, response headers sent to the visitor, or both.
Every matching rule applies, from the lowest order number up, so a later rule wins when two rules set the same
header. A header rule with no condition applies to every request.

| Change | What happens |
| --- | --- |
| Set | Replaces the header, or adds it when it is missing |
| Remove | Removes the header |

Response header changes apply to every response the edge sends for a matching request, cache hits included. Request
header changes reach the origin only; they do not change the cache key, so two visitors with different values of a
header your rule sets still share one cached copy.

### Protected headers

A rule can never set or remove these headers. The edge writes them itself, or they carry its framing, forwarding,
or security decisions:

- `Host`, `Content-Length`, `Transfer-Encoding`, `Connection`, `Keep-Alive`, `Proxy-Connection`, `TE`, `Trailer`,
  and `Upgrade`
- `Proxy-Authenticate`, `Proxy-Authorization`, `Forwarded`, and `X-Real-IP`
- `Accept-Encoding`, `Content-Encoding`, and `Content-Range`
- every header that starts with `X-Forwarded-`, `X-CDN-`, or `X-CDNF-`

Names are compared without regard to case and are stored in lower case. The cell skips a protected header even if a
snapshot names one.

### What a header rule refuses

- A protected header, or a name that is not 1 to 64 header name characters.
- The same header twice in one rule.
- A value that is empty, longer than 1,024 bytes, or contains a line break or another control character.
- A rule with no change, or more than 10 changes counting both directions.

## Cache rules

A cache rule changes the cache for matching requests. The first matching rule applies; the domain's
[cache settings](../guides/cache.md#cache-settings) apply to every other request.

| Mode | What happens |
| --- | --- |
| Bypass the cache | The request always reaches your origin and nothing is stored |
| Cache with these settings | The values you set replace the domain's for this request; empty values keep the domain's |

| Setting | Values | Notes |
| --- | --- | --- |
| Edge TTL | 0 to 31,536,000 seconds | Replaces the origin's own lifetime. A status the domain's status TTLs never store is still not stored. |
| Browser TTL | 0 to 31,536,000 seconds | |
| Origin cache headers | Respect or ignore | Whether the origin's `Cache-Control` or `Expires` sets the edge lifetime when no edge TTL is set here |

A cache rule needs at least one condition, and an override needs at least one of the three settings. A rule never
makes a request cacheable that the edge refuses to cache: requests with authorization, unsafe methods, ranges, or a
cookie that is not on the domain's ignored list still skip the cache.

Cache rules replace the cache path rules of earlier releases. When you upgrade, each stored path rule becomes a cache
rule in the same order: a prefix becomes **Path starts with**, a suffix becomes **Path ends with**, a bypass stays a
bypass, and an edge TTL becomes an override with that edge TTL. The cache settings no longer accept `path_rules`.

### Cache key and purge

The cache key and URL purge always use the address the visitor asked for, never the rewritten one. Two URLs that
rewrite to the same origin path are cached separately, and purging the visitor's URL removes what that visitor sees.
The outcome of the matching cache rule is part of the cache policy, so changing a rule's lifetimes starts a new policy
generation and earlier copies are not reused.

## Presets

Presets write ordinary rules marked with the preset's name. Saving a preset again replaces its rules, and editing one
of its rules by hand turns that rule into an ordinary rule.

| Type | Preset | What it writes |
| --- | --- | --- |
| Redirect | Redirect HTTP to HTTPS | **TLS version equals none**, to the same address over HTTPS, keeping the query string |
| Redirect | Redirect www to the apex domain | **Hostname equals `www.example.com`**, to the same path on `example.com`, keeping the query string |
| Redirect | Redirect the apex domain to www | **Hostname equals `example.com`**, to the same path on `www.example.com`, keeping the query string |
| Redirect | Redirect other hostnames to one host | **Hostname is one of** the listed names (up to 20), to the same path on the chosen host |
| Redirect | Move paths to new prefixes | One prefix redirect per move, up to 20 moves, keeping the query string |
| Rewrite | Remove a path prefix before the origin | A prefix rewrite from the prefix to nothing: `/api/users` reaches the origin as `/users` |
| Rewrite | Add a path prefix before the origin | A prefix rewrite from `/` to the prefix: `/users` reaches the origin as `/app/users` |
| Header | Add security headers | One response rule that sets `Strict-Transport-Security` (optional, with a chosen lifetime and subdomains), `X-Content-Type-Options: nosniff`, `X-Frame-Options` (optional), `Referrer-Policy`, and `Permissions-Policy: camera=(), microphone=(), geolocation=()` |
| Header | Hide origin software headers | One response rule that removes `Server`, `X-Powered-By`, `X-AspNet-Version`, `X-AspNetMvc-Version`, and `X-Generator` |
| Header | Allow cross-origin use of static files | **File extension is one of** the listed extensions, setting `Access-Control-Allow-Origin` to `*` or one origin |
| Cache | Cache static files longer | **File extension is one of** the listed extensions (up to 50), with an edge TTL of 1 to 365 days and a browser TTL of 0 to 365 days |
| Cache | Bypass the cache for admin and sign-in paths | One bypass rule per path prefix (up to 20); each prefix starts with `/` |
| Cache | Bypass the cache for signed-in visitors | One bypass rule per cookie name (up to 20): **Cookie is present** |

Redirect presets ask for the status code. Preset rules are placed after rules you number by hand (order 500 and up),
and a cache bypass preset sits before the static file preset, so a bypass wins.

## Limits

| Limit | Value |
| --- | --- |
| Rules of one type per domain | 50 |
| Conditions per rule | 5 |
| Redirect target and rewritten path length | 2,048 bytes |
| Prefix length | 512 bytes |
| Fixed query string length | 1,024 bytes |
| Header changes per rule | 10, counting request and response |
| Header changes applied per request | 50 |
| Header name and value | 64 bytes and 1,024 bytes |
| Edge and browser TTL | 31,536,000 seconds |
| Hostnames, moves, paths, or cookies in one preset | 20 |

## Logs

Every request a rule acted on carries the rule in the access log, in the `request_rule` field, as `type:id` joined by
commas, for example `rewrite:12,header:4,cache:31`. At most eight rules are listed. Requests no rule changed carry an
empty value. If many header rules match, the list keeps the rewrite and cache decisions and the first header matches
that fit within this bound.

## API

| Method and path | What it does |
| --- | --- |
| `GET /api/domains/{domain}/request-rules?type=redirect` | Lists the rules, in order, with cursor pagination. `type` is `redirect`, `rewrite`, `header`, or `cache` |
| `POST /api/domains/{domain}/request-rules` | Adds a rule. Returns `202` with the rule and the operation ID |
| `PATCH /api/domains/{domain}/request-rules/{rule}` | Replaces a rule. Returns `202` with the operation ID |
| `DELETE /api/domains/{domain}/request-rules/{rule}` | Removes a rule. Returns `202` |
| `PUT /api/domains/{domain}/request-rules/order` | Sets the order of one type: `{"type": "redirect", "order": [ids]}` with every rule of that type once |
| `PUT /api/domains/{domain}/request-rules/presets/{preset}` | Saves a preset with its values (see below) |
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

The other targets use `"target": "url"` with `url`, `"target": "host"` with `host` and `scheme` (`same` or
`https`), or `"target": "https"` alone. A second rule with the same type, conditions, and action is refused, and so
is a 51st rule of one type (`409`).

The actions of the other types:

```json
{"path": "prefix", "from_prefix": "/api", "to_prefix": "", "query": "keep"}
{"path": "set", "path_value": "/index.php", "query": "set", "query_value": "lang=en"}
{"request": [{"op": "set", "name": "x-client-type", "value": "mobile"}],
 "response": [{"op": "remove", "name": "server"}]}
{"mode": "bypass"}
{"mode": "override", "edge_ttl_seconds": 86400, "browser_ttl_seconds": 3600, "respect_origin": false}
```

| Preset ID | Values |
| --- | --- |
| `http_to_https`, `www_to_apex`, `apex_to_www` | `status_code` |
| `merge_hosts` | `hosts` (list), `target`, `status_code` |
| `move_paths` | `moves` (list of `from` and `to`), `status_code` |
| `strip_prefix`, `add_prefix` | `prefix` |
| `security_headers` | `hsts_max_age` (0 to send none), `hsts_subdomains`, `frame_options` (`DENY`, `SAMEORIGIN`, or `none`), `referrer_policy` |
| `hide_origin_headers` | none |
| `cors_static` | `extensions` (list), `origin` |
| `cache_static` | `extensions` (list), `edge_ttl_days`, `browser_ttl_days` |
| `bypass_paths` | `paths` (list) |
| `bypass_cookies` | `cookies` (list) |

A refused value returns `422` with the field that failed, for example `action.response.0.name` for a protected
header.

## Deployment and rollback

Deploy the cell evaluator and the edge agent before the control plane starts publishing request rules.
A snapshot without `rules` has no request rules. The agent validates a candidate's rule types, bounds,
paths, targets, and headers before activating its generation; a rejected candidate keeps the last valid generation.

The two forward migrations create `request_rules`, then admit all four types and move stored cache path
rules into cache rules. The migration preserves their order and removes duplicate conditions and actions.
It increments each affected domain's revision. Keep the database and named volumes throughout the upgrade.
Do not run the down migration after rewrite, header, or cache rules exist: it refuses to discard them.

Rolling back a retained edge revision restores its active request rules, in order, through current validation,
and queues a new revision. The snapshot contains active behavior, so restored rules have no preset or display
name and disabled rules are not restored. A rule that fails current validation refuses the whole rollback;
the current rules and revision remain unchanged. Edit or disable a bad rule to queue a normal forward change.
