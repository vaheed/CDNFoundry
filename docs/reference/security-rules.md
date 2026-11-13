---
title: Custom rule reference
description: Every field, operator, action, and rate-limit setting of a custom security rule, each with examples for the panel and the API, and complete recipes.
---

# Custom rule reference

A custom rule has up to **five conditions** that must all match, and **one action**. Every condition is a field from the
list below, an operator from a fixed list, and a value. There are no regular expressions and no expression language. The
[Security guide](../guides/security.md#custom-rules) explains where rules fit; this page lists every item with examples.

Each example shows the condition as the panel reads it back (the **Reads as** line in the rule sheet) and as JSON for the
API (`POST /api/domains/{domain}/security/rules`). A complete rule in the API looks like this:

```json
{
  "name": "Block the admin area from abroad",
  "conditions": [
    {"field": "path", "operator": "starts_with", "value": "/admin"},
    {"field": "country", "operator": "not_in_list", "value": ["DE", "AT", "CH"]}
  ],
  "action": "block",
  "priority": 100,
  "enabled": true,
  "note": "Only the office countries reach the admin area"
}
```

`name`, `note`, `priority`, and `enabled` are optional in the panel: a new rule is placed after the existing ones and is
enabled. In the API, `priority` is required (lower numbers run first).

## Operators

| Operator (API) | Panel wording | Value | Works on |
| --- | --- | --- | --- |
| `equals` | equals | one value | every field |
| `not_equals` | does not equal | one value | every field except the flags |
| `starts_with` | starts with | text | text fields |
| `ends_with` | ends with | text | text fields |
| `contains` | contains | text | text fields |
| `in_list` | is one of | a list of 1 to 100 values | text, choice, number, and score fields |
| `not_in_list` | is not one of | a list of 1 to 100 values | text, choice, number, and score fields |
| `greater_than` | is greater than | a number | ASN, body size, and scores |
| `less_than` | is less than | a number | ASN, body size, and scores |

Rules for every operator:

- A list has no duplicates. The platform setting `security.maximum_rule_list_values` can lower the 100 limit.
- `starts_with`, `ends_with`, and `contains` compare plain text. `*`, `?`, and `.` have no special meaning.
- Hostname, user agent, referer, referer host, file extension, header, and content type compare without regard to case:
  `contains "sqlmap"` matches `SQLMap/1.7`. Path compares exactly.
- When the request has no value for the field (no such header, no Referer), only `not_equals` and `not_in_list` match.

## Fields

### Request target

#### `hostname`

The host the visitor asked for, without a port. Case does not matter. Operators: every text operator.

| Reads as | JSON |
| --- | --- |
| Hostname equals shop.example.com | `{"field": "hostname", "operator": "equals", "value": "shop.example.com"}` |
| Hostname starts with staging. | `{"field": "hostname", "operator": "starts_with", "value": "staging."}` |
| Hostname is one of api.example.com, api2.example.com | `{"field": "hostname", "operator": "in_list", "value": ["api.example.com", "api2.example.com"]}` |

#### `path`

The decoded request path without the query string. **Case matters**: `/Admin` and `/admin` are different. Operators: every
text operator.

| Reads as | JSON |
| --- | --- |
| Path starts with /wp-admin | `{"field": "path", "operator": "starts_with", "value": "/wp-admin"}` |
| Path ends with .php | `{"field": "path", "operator": "ends_with", "value": ".php"}` |
| Path contains /../ | `{"field": "path", "operator": "contains", "value": "/../"}` |
| Path is one of /login, /signin, /account/login | `{"field": "path", "operator": "in_list", "value": ["/login", "/signin", "/account/login"]}` |
| Path does not equal / | `{"field": "path", "operator": "not_equals", "value": "/"}` |

#### `query_present`

Whether the address has a query string at all. Operator: `equals` with `true` or `false`.

| Reads as | JSON |
| --- | --- |
| Query string is present | `{"field": "query_present", "operator": "equals", "value": true}` |
| Query string is absent | `{"field": "query_present", "operator": "equals", "value": false}` |

#### `file_extension`

The extension of the path in lower case, without the dot: `jpg` for `/photos/Cat.JPG`. A path without an extension has none.
Operators: `equals`, `not_equals`, `in_list`, `not_in_list`. Values are 1 to 10 letters or digits.

| Reads as | JSON |
| --- | --- |
| File extension is one of jpg, png, webp | `{"field": "file_extension", "operator": "in_list", "value": ["jpg", "png", "webp"]}` |
| File extension equals env | `{"field": "file_extension", "operator": "equals", "value": "env"}` |
| File extension is not one of html, css, js | `{"field": "file_extension", "operator": "not_in_list", "value": ["html", "css", "js"]}` |

#### `method`

The HTTP method: `GET`, `HEAD`, `POST`, `PUT`, `PATCH`, `DELETE`, or `OPTIONS`. Operators: `equals`, `not_equals`, `in_list`,
`not_in_list`.

| Reads as | JSON |
| --- | --- |
| Method equals POST | `{"field": "method", "operator": "equals", "value": "POST"}` |
| Method is not one of GET, HEAD | `{"field": "method", "operator": "not_in_list", "value": ["GET", "HEAD"]}` |

### Visitor

#### `client_ip`

The visitor's address. A value is one IPv4 or IPv6 address, or a network in CIDR form; both families work in one list.
Operators: `equals`, `not_equals`, `in_list`, `not_in_list`. "equals" with a network matches every address inside it.

| Reads as | JSON |
| --- | --- |
| Client IP equals 198.51.100.7 | `{"field": "client_ip", "operator": "equals", "value": "198.51.100.7"}` |
| Client IP equals 203.0.113.0/24 | `{"field": "client_ip", "operator": "equals", "value": "203.0.113.0/24"}` |
| Client IP is one of 203.0.113.0/24, 2001:db8:abcd::/48 | `{"field": "client_ip", "operator": "in_list", "value": ["203.0.113.0/24", "2001:db8:abcd::/48"]}` |

#### `country` and `continent`

From the edge's own location database, never an outside service. Countries are two-letter codes (`DE`, `IR`, `US`);
continents are `AF`, `AN`, `AS`, `EU`, `NA`, `OC`, and `SA`. The panel offers a searchable list by name. Operators: `equals`,
`not_equals`, `in_list`, `not_in_list`. A visitor whose location is unknown matches only `not_equals`.

| Reads as | JSON |
| --- | --- |
| Country equals DE | `{"field": "country", "operator": "equals", "value": "DE"}` |
| Country is not one of DE, AT, CH | `{"field": "country", "operator": "not_in_list", "value": ["DE", "AT", "CH"]}` |
| Continent does not equal EU | `{"field": "continent", "operator": "not_equals", "value": "EU"}` |

#### `asn`

The visitor's network number. Operators: `equals`, `not_equals`, `in_list`, `not_in_list`, `greater_than`, `less_than`. An
unknown network (0) matches only `not_equals`.

| Reads as | JSON |
| --- | --- |
| ASN equals 64500 | `{"field": "asn", "operator": "equals", "value": 64500}` |
| ASN is one of 64500, 64501, 64502 | `{"field": "asn", "operator": "in_list", "value": [64500, 64501, 64502]}` |

#### `user_agent`

The User-Agent header. Case does not matter. Operators: every text operator.

| Reads as | JSON |
| --- | --- |
| User agent contains sqlmap | `{"field": "user_agent", "operator": "contains", "value": "sqlmap"}` |
| User agent starts with curl/ | `{"field": "user_agent", "operator": "starts_with", "value": "curl/"}` |

To match a request **without** a User-Agent, use the `header` field: `header user-agent is not one of …` matches when the
header is missing.

### Where the visitor came from

#### `referer`

The whole Referer header. Case does not matter. Operators: every text operator.

| Reads as | JSON |
| --- | --- |
| Referer contains utm_source=spam | `{"field": "referer", "operator": "contains", "value": "utm_source=spam"}` |
| Referer starts with `https://evil.example` | `{"field": "referer", "operator": "starts_with", "value": "https://evil.example"}` |

#### `referer_host`

Only the host of the Referer, such as `www.example.com`. The port and any user name are ignored. Operators: `equals`,
`not_equals`, `in_list`, `not_in_list`. A request without a Referer has no host, so it matches only `not_equals` and
`not_in_list`; pair it with `referer_present` when that matters.

| Reads as | JSON |
| --- | --- |
| Referer host is not one of `www.example.com`, `example.com` | `{"field": "referer_host", "operator": "not_in_list", "value": ["www.example.com", "example.com"]}` |
| Referer host equals forum.example.net | `{"field": "referer_host", "operator": "equals", "value": "forum.example.net"}` |

#### `referer_present`

Whether the request carries a Referer with an address. Operator: `equals` with `true` or `false`.

| Reads as | JSON |
| --- | --- |
| Referer is present | `{"field": "referer_present", "operator": "equals", "value": true}` |

### Headers, cookies, and body

#### `header`

The value of one request header, named in `name`. Header names and values compare without regard to case. Operators: every
text operator.

| Reads as | JSON |
| --- | --- |
| Header x-api-key equals none | `{"field": "header", "name": "X-Api-Key", "operator": "equals", "value": "none"}` |
| Header accept-language starts with ru | `{"field": "header", "name": "Accept-Language", "operator": "starts_with", "value": "ru"}` |
| Header x-forwarded-for contains , | `{"field": "header", "name": "X-Forwarded-For", "operator": "contains", "value": ","}` |

#### `cookie_present`

Whether the request carries a cookie with the name in `name`. The value is never read. Operator: `equals` with `true` or
`false`.

| Reads as | JSON |
| --- | --- |
| Cookie session is present | `{"field": "cookie_present", "name": "session", "operator": "equals", "value": true}` |
| Cookie session is absent | `{"field": "cookie_present", "name": "session", "operator": "equals", "value": false}` |

#### `content_type`

The Content-Type header of the request. Case does not matter. Operators: every text operator.

| Reads as | JSON |
| --- | --- |
| Content type starts with multipart/form-data | `{"field": "content_type", "operator": "starts_with", "value": "multipart/form-data"}` |
| Content type does not equal application/json | `{"field": "content_type", "operator": "not_equals", "value": "application/json"}` |

#### `body_size`

The request body size in bytes, 0 to 1,073,741,824. Operators: `equals`, `not_equals`, `greater_than`, `less_than`.

| Reads as | JSON |
| --- | --- |
| Body size (bytes) is greater than 1048576 | `{"field": "body_size", "operator": "greater_than", "value": 1048576}` |
| Body size (bytes) equals 0 | `{"field": "body_size", "operator": "equals", "value": 0}` |

#### `tls_version`

The TLS version of the connection: `TLSv1`, `TLSv1.1`, `TLSv1.2`, `TLSv1.3`, or `none` for plain HTTP. Operators: `equals`,
`not_equals`, `in_list`, `not_in_list`.

| Reads as | JSON |
| --- | --- |
| TLS version is one of TLSv1, TLSv1.1 | `{"field": "tls_version", "operator": "in_list", "value": ["TLSv1", "TLSv1.1"]}` |
| TLS version equals none | `{"field": "tls_version", "operator": "equals", "value": "none"}` |

### Detection fields

These fields carry a value only when the detection behind them runs. **A rule on a field whose detection is not set up on the
domain is refused** with `Prompt injection score needs AI protection. Set up AI protection under Security first.` (422,
`validation_failed`), and the panel lists the field as not available. A detection cannot be turned off while a rule still uses
one of its fields (409).

| Field | Needs |
| --- | --- |
| `attack_score`, `attack_score_sqli`, `attack_score_xss`, `attack_score_rce` | nothing: the domain is scored while a rule uses a score field, even with the WAF off |
| `leaked_password` | [Credential check](../guides/security.md#leaked-passwords) with at least one login path |
| `prompt_injection_score`, `prompt_pii` | [AI protection](../guides/security.md#ai-prompts) with at least one endpoint |
| `schema_violation` | An [API schema](../guides/security.md#api-schema) |

#### `attack_score` and its three categories

From 1 (attack) to 99 (clean), computed from the managed WAF's anomaly points: 99 minus 5 for each point, never below 1.
`attack_score_sqli`, `attack_score_xss`, and `attack_score_rce` count only SQL injection, cross-site scripting, or command
injection signs. Operators: `equals`, `not_equals`, `in_list`, `not_in_list`, `greater_than`, `less_than`. A request that was
not scored never matches.

| Score | What it usually means |
| --- | --- |
| 99 | Nothing matched |
| 60 to 94 | One or two low-severity matches; often a false positive |
| 20 to 59 | Several matches |
| 1 to 19 | A very likely attack |

| Reads as | JSON |
| --- | --- |
| Attack score is less than 20 | `{"field": "attack_score", "operator": "less_than", "value": 20}` |
| Attack score (SQL injection) is less than 40 | `{"field": "attack_score_sqli", "operator": "less_than", "value": 40}` |
| Attack score (XSS) is less than 30 | `{"field": "attack_score_xss", "operator": "less_than", "value": 30}` |

#### `leaked_password`

`true` when the password sent to one of the login paths is in the leaked-password set. The password is never stored or
logged. Operator: `equals`.

| Reads as | JSON |
| --- | --- |
| Leaked password is present | `{"field": "leaked_password", "operator": "equals", "value": true}` |

#### `prompt_injection_score` and `prompt_pii`

On the AI endpoints only. The score is 1 (two or more known injection phrases), 29 (one phrase), or 99 (none); it is phrase
matching, not understanding. `prompt_pii` is `true` when the prompt holds an email address, card number, IBAN, or US social
security number.

| Reads as | JSON |
| --- | --- |
| Prompt injection score is less than 30 | `{"field": "prompt_injection_score", "operator": "less_than", "value": 30}` |
| Personal data in prompt is present | `{"field": "prompt_pii", "operator": "equals", "value": true}` |

#### `schema_violation`

How the request differs from the API schema: `unknown_path`, `wrong_method`, `missing_parameter`, `invalid_parameter`, or
`body_too_large`. `any` matches every violation and `none` a request that fits. Requests outside the schema's base path have
no value and never match. Operators: `equals`, `not_equals`, `in_list`, `not_in_list`.

| Reads as | JSON |
| --- | --- |
| API schema violation equals any | `{"field": "schema_violation", "operator": "equals", "value": "any"}` |
| API schema violation is one of unknown_path, wrong_method | `{"field": "schema_violation", "operator": "in_list", "value": ["unknown_path", "wrong_method"]}` |

## Actions

| Action | Response | Ends evaluation | Use it to |
| --- | --- | --- | --- |
| `block` | 403 (429 for a rate limit) | yes | Refuse a request outright |
| `challenge` | A short browser proof of work; scripts without a token get 403 | no | Slow down bots while letting people through |
| `log` | Nothing changes; a security event is recorded | no | Try a rule before it blocks |
| `allow` | The request continues | yes, for custom rules; it also skips the challenge | Trust an office network or a monitoring service |
| `skip_managed` | The managed WAF does not inspect the request | no | Stop false positives on one path, such as an editor that posts HTML |

`allow` does not skip the managed WAF; add `skip_managed` for that. A solved challenge lasts one hour.

## Order

1. Platform rulesets set by the administrator.
2. The domain's custom rules, lowest `priority` first; the rule ID breaks ties. Disabled rules are skipped.
3. The domain's rate-limit rules.
4. The managed WAF.

Only `block` and `allow` stop the custom rules. In the panel, **Move up** and **Move down** change the order; in the API,
`PUT .../security/rules/order` takes the full list of rule IDs.

## Rate limits

Turn on **Only above a request rate** in the rule sheet, or add a `rate_limit` object. The conditions decide which requests
are counted; the action applies once one client goes over the limit.

| Setting (API) | Panel | Values |
| --- | --- | --- |
| `key` | Count by | `client_ip` (Client IP), `client_ip_path` (Client IP and path), `header` (Header value) |
| `header_name` | Header name | Required for `header`, such as `X-Api-Key`. Requests without the header are not counted |
| `threshold` | Requests | 1 to 100,000 |
| `period_seconds` | Per | `10`, `60`, or `600` |
| `duration_seconds` | Then for (seconds) | 10 to 86,400 |

The action is `block` (429), `challenge`, or `log`. Counts are **per cell**, in fixed windows; they are not one count across
locations. A rate-limit rule may have no conditions, and then counts every request of the domain. A domain has up to 20
rate-limit rules by default (never more than 50).

```json
{"name": "Slow down login attempts",
 "conditions": [{"field": "path", "operator": "equals", "value": "/login"},
                {"field": "method", "operator": "equals", "value": "POST"}],
 "rate_limit": {"key": "client_ip", "threshold": 10, "period_seconds": 60, "duration_seconds": 900},
 "action": "block", "priority": 20}
```

```json
{"name": "Per API key budget",
 "conditions": [{"field": "path", "operator": "starts_with", "value": "/api/"}],
 "rate_limit": {"key": "header", "header_name": "X-Api-Key", "threshold": 600, "period_seconds": 60, "duration_seconds": 60},
 "action": "block", "priority": 30}
```

```json
{"name": "Whole-site ceiling per visitor",
 "conditions": [],
 "rate_limit": {"key": "client_ip", "threshold": 3000, "period_seconds": 600, "duration_seconds": 600},
 "action": "challenge", "priority": 40}
```

## Recipes

Each recipe is a complete rule. Paste the JSON into the API, or build the same conditions in the panel.

**Keep the admin area to two countries.**

```json
{"name": "Admin only from DE and AT", "action": "block", "priority": 10,
 "conditions": [{"field": "path", "operator": "starts_with", "value": "/admin"},
                {"field": "country", "operator": "not_in_list", "value": ["DE", "AT"]}]}
```

**Let the office network skip every later rule.**

```json
{"name": "Office", "action": "allow", "priority": 1,
 "conditions": [{"field": "client_ip", "operator": "in_list", "value": ["203.0.113.0/24", "2001:db8:abcd::/48"]}]}
```

**Block probes for secrets and dotfiles.**

```json
{"name": "Secret files", "action": "block", "priority": 5,
 "conditions": [{"field": "file_extension", "operator": "in_list", "value": ["env", "git", "sql", "bak", "old"]}]}
```

**Challenge scripts that post forms without a session.**

```json
{"name": "Form posts without a session", "action": "challenge", "priority": 50,
 "conditions": [{"field": "method", "operator": "equals", "value": "POST"},
                {"field": "cookie_present", "name": "session", "operator": "equals", "value": false},
                {"field": "path", "operator": "not_in_list", "value": ["/login", "/register"]}]}
```

**Block very likely attacks before the managed WAF.**

```json
{"name": "Attack score below 20", "action": "block", "priority": 60,
 "conditions": [{"field": "attack_score", "operator": "less_than", "value": 20}]}
```

**Watch SQL injection on the search page before blocking.**

```json
{"name": "Watch search", "action": "log", "priority": 61,
 "conditions": [{"field": "path", "operator": "equals", "value": "/search"},
                {"field": "attack_score_sqli", "operator": "less_than", "value": 50}]}
```

**Stop false positives on a rich text editor.**

```json
{"name": "Editor posts HTML", "action": "skip_managed", "priority": 15,
 "conditions": [{"field": "path", "operator": "equals", "value": "/editor/save"},
                {"field": "cookie_present", "name": "session", "operator": "equals", "value": true}]}
```

**Refuse old TLS and plain HTTP on the API.**

```json
{"name": "Modern TLS for the API", "action": "block", "priority": 25,
 "conditions": [{"field": "hostname", "operator": "equals", "value": "api.example.com"},
                {"field": "tls_version", "operator": "in_list", "value": ["none", "TLSv1", "TLSv1.1"]}]}
```

**Refuse large uploads outside the upload path.**

```json
{"name": "Large bodies only on /upload", "action": "block", "priority": 26,
 "conditions": [{"field": "body_size", "operator": "greater_than", "value": 2097152},
                {"field": "path", "operator": "not_equals", "value": "/upload"}]}
```

**Block logins that use a leaked password.** Needs Credential check.

```json
{"name": "Leaked passwords", "action": "block", "priority": 30,
 "conditions": [{"field": "leaked_password", "operator": "equals", "value": true}]}
```

**Challenge prompt injection on the chat endpoint.** Needs AI protection.

```json
{"name": "Prompt injection", "action": "challenge", "priority": 31,
 "conditions": [{"field": "prompt_injection_score", "operator": "less_than", "value": 30}]}
```

**Block requests that do not fit the API schema.** Needs an API schema.

```json
{"name": "Schema violations", "action": "block", "priority": 32,
 "conditions": [{"field": "schema_violation", "operator": "in_list", "value": ["unknown_path", "wrong_method"]}]}
```

**Block hotlinked images.** The hotlink preset writes a rule like this one for you.

```json
{"name": "Hotlinks", "action": "block", "priority": 70,
 "conditions": [{"field": "file_extension", "operator": "in_list", "value": ["jpg", "png", "gif", "webp"]},
                {"field": "referer_present", "operator": "equals", "value": true},
                {"field": "referer_host", "operator": "not_in_list", "value": ["example.com", "www.example.com"]}]}
```

## Limits and refusals

| Item | Limit | Refusal |
| --- | --- | --- |
| Rules per domain | 1,000 | 409 |
| Conditions per rule | 5 (0 only for a rate-limit rule) | 422 |
| Values in one list | 100 (a platform setting can lower it) | 422 |
| Text value | 1 to 512 printable characters | 422 |
| Rate-limit rules per domain | 20 by default, never above 50 | 409 |
| One import | 500 rules | 422 |
| The same conditions and action twice | refused | 422 |
| A detection field without its detection | refused | 422 |

`POST .../security/rules/preview` checks a rule, returns it normalized with the position it would take, and saves nothing.
