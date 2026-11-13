---
title: Roadmap
description: Owner roadmap after the 2026-11-11 audit phases. Adds notifications, customer log push, the incident profile, cache rules, a simple WAF, and measured performance, then closes every open gate.
---

# Roadmap

**Status: active.** Written 2026-11-12. It replaces the audit roadmap of
2026-11-11. That roadmap's Phases 1 to 5 are implemented on `dev`; their
missing gate records are closed in the last phase here. Its Phase 6 is
dropped. Its Phases 7 to 10 become Phases 3, 4, 9, and 10. Work happens on
`dev`, one phase at a time, in order.

Keep every item small. One engine per concern, typed settings, bounded
lists, no expression language, no per-domain process, and nothing on the
request path that calls Laravel or an outside service.

## Frontend rules for every phase

Every screen added or changed in any phase, in both panels and the Fleet web
page, follows two rules:

- **Help on every complex item.** Any field, toggle, action, or column whose
  meaning is not obvious from its label (rule conditions, attack score,
  thresholds, counting keys, datasets, schema uploads, and similar) has a
  short tooltip in plain words: what it does and when to use it. Use the
  existing help-label pattern; no long text in the tooltip.
- **An example placeholder in every input.** Every text, number, URL, list,
  and tag input shows a realistic example value as its placeholder (for
  example `https://logs.example.com/ingest`, `/api/login`, `100`). A
  placeholder is never the only label and never a real secret.

The browser suite checks both on every changed screen.

## Phase gate

A phase is complete only when all five fields pass and are recorded
separately at the end of the phase:

| Field | Required evidence |
| --- | --- |
| Implementation | Merged to `dev` in small commits. Replaced code is deleted. |
| Tests | Feature and unit tests for happy path, permissions, validation, idempotency, failure, and last valid state. |
| Runtime qualification | Python E2E under `tests/e2e` on a real Compose stack. |
| Browser suite | `tests/browser` passes for every changed screen, with visual snapshots and accessibility checks, and confirms every input has an example placeholder and every complex item has a tooltip. |
| Documentation | Every affected operator and user page states the new behavior. |

Phase 11 re-runs every gate together, so a phase that passed early is checked
again against the finished system.

---

## Phase 1: Notifications

Today: Alertmanager sends to one optional external webhook
(`ALERT_WEBHOOK_URL`). Only administrators receive anything, and staging had
no receiver at all.

Target: one `notifier` container, placed with the monitoring containers,
delivers every notification to Telegram, Discord, Slack, or a generic
webhook. It is the only container that needs outbound access to these
providers.

| # | Item | Direction | Proof of completion |
| --- | --- | --- | --- |
| 1.1 | **One notifier container.** | Runs the core image as a worker for a single `notifications` queue lane, using Laravel Notifications with one channel class per provider. Alertmanager sends to a private internal receiver in the control plane, which queues the alert for the notifier. Delivery is asynchronous, retried with backoff, and never blocks another lane. `ALERT_WEBHOOK_URL` is removed; the webhook becomes one optional, external, administrator-only channel type. | E2E: an alert reaches a fake receiver for each of the four channel types; a down provider does not delay other channels or queues. |
| 1.2 | **Administrator channels in Platform settings.** | Administrators add channels in Platform settings and receive everything: every Alertmanager alert, and every domain event below for all domains. Tokens and webhook URLs are encrypted and shown once. A "Send test" action returns an operation. | Feature and browser tests for add, test, remove, and the one-time secret. |
| 1.3 | **Domain user channels in the user panel.** | An administrator switch in Platform settings turns this on or off for domain users. When it is on, a domain user adds Telegram, Discord, or Slack channels under their account and picks events for their assigned domains only: certificate issued, failed, or expiring; domain verification; DNS deployment failed; security state changed; incident profile started or ended; origin errors above a threshold; log push failing (Phase 2); security event alerts (Phase 6). When it is off, existing user channels stop sending and are kept. | Policy tests: a user never receives an event for an unassigned domain; switching off stops delivery within one minute. |
| 1.4 | **Bounds and abuse safety.** | Maximum channels per user and per platform. Per-channel rate limit, with repeats of one event coalesced into one digest message. Slack and Discord URLs must use HTTPS and the provider's own host; the generic webhook goes through the same destination checks as an origin (no loopback, private, metadata, or platform addresses), checked again when it connects. A channel that fails N times in a row is paused and its owner sees why. | Tests for each limit, the URL checks, and the pause. |

**Gate:** on a staging stack, an administrator and a domain user each
receive a real test message on every channel type, and a domain user never
sees another customer's event.

---

## Phase 2: Log push for domain users

The platform's central logs (ClickHouse, Loki, and the administrator's own
access to them) are a separate system and do not change in this phase.

This phase adds one thing: an administrator can turn log push on or off for
domain users, and when it is on, a domain user can add an endpoint that
receives the logs of their own domains.

| # | Item | Direction | Proof of completion |
| --- | --- | --- | --- |
| 2.1 | **Administrator switch.** | One switch in Platform settings, off by default: "Domain users can push logs to their own endpoint". Turning it off stops every push within one minute and keeps the users' destinations. | Feature and browser tests. |
| 2.2 | **Destinations per domain.** | When the switch is on, a domain user adds up to two destinations per domain: HTTPS (gzip NDJSON, bearer token or HMAC signature) or S3-compatible storage. Datasets: HTTP requests, security events, DNS queries. Destination checks are the same as for an origin. Credentials are encrypted and shown once. A "Send test" action returns an operation. | Validation and policy tests; a destination on a private or platform address is refused. |
| 2.3 | **One shared push worker, reading ClickHouse.** | A `log-push` queue lane reads each destination's own domain rows from ClickHouse by cursor, in bounded batches, about once a minute. No per-domain Vector sink, process, or config: tenant settings never reach Vector. A slow or failing destination retries with backoff and keeps its cursor. When it falls behind the retention window it skips ahead and records the gap. Serving and central telemetry are never affected. | E2E: a test destination receives every row for its domain in order; a destination down for one hour catches up; one behind retention reports the gap. |
| 2.4 | **No foreign or platform data.** | A fixed field allowlist per dataset. Rows come only from the destination's domain. Excluded: edge and cell names, internal addresses, node proofs, platform hostnames, other domains' IDs, and raw query strings (only `query_hash`, as stored). Client IP is included, with an option to truncate it. | Contract test on the allowlist; E2E with two domains shows zero rows of the other domain. |
| 2.5 | **Visible state.** | The domain page shows each destination's last delivery, lag, failures, and bytes pushed. Repeated failure sends a "log push failing" notification. | Browser test of the status; notification test. |

**Gate:** two domains on one cell each push to their own receiver; each
receiver gets only its own rows, with delay under a stated bound; turning
the switch off stops both.

---

## Phase 3: A domain-scoped, time-limited Under Attack profile

Previously Phase 7. Today "Enable Under Attack mode" is an administrator-only
action that sets the security state to `restricted` (tighter per-client
limits only). It does not touch the WAF, has no expiry the operator
chooses, and the recovery job ends it after ten quiet minutes. On staging,
the attacker stayed under the per-client limits, and blocking mode on the
WAF is what stopped it.

| # | Item | Direction | Proof of completion |
| --- | --- | --- | --- |
| 3.1 | **One incident profile per domain.** | One action, for a chosen duration: WAF blocking at high sensitivity, protected limits, restricted methods and body size, and the challenge from 3.2. Stored as desired state with an expiry, delivered through the security overlay (one revision on, one off). Available to the domain's users. | E2E: all four protections reach the edge within a measured delay; expiry removes them with no operator action. |
| 3.2 | **A proof-of-work challenge at the edge.** | A signed, expiring, single-domain token bound to the client address and user agent, verified in Lua with a per-cell key from the bundle. Issuance is rate-limited and replay is bounded by expiry and a small seen-set. Needs a no-script and screen-reader path, a bypass for trusted clients and marked API paths, no cookie except the token, and a fallback to rate limits, never to open. A third-party CAPTCHA is evaluated only after this has measured results. The challenge becomes an action that WAF rules can use (Phase 5). | E2E: no token gets the challenge; a solved token passes until expiry; replayed or foreign-domain tokens are refused; a headless flood stays blocked; the accessibility check passes. |
| 3.3 | **Visible state and history.** | The domain page shows active protections, who enabled them, and when they end, with an early end in one click. Each action is an audit entry. Challenge outcomes and WAF blocks appear in telemetry. Start and end send notifications (Phase 1). | Browser test of the banner, expiry, and early end. |
| 3.4 | **Honest limits.** | Copy states that this protects the origin and the cell from request floods. It does not absorb traffic that saturates a host, link, or upstream. | Copy reviewed in the browser suite. |
| 3.5 | **WAF upkeep check.** | Check the false-positive workflow, rule visibility for domain users, and ruleset updates across cells against the running system. Inputs for Phase 5. | A short verified status per topic. |

**Gate:** the profile, enabled from the panel on a staging domain, blocks
the scripted test attack (rate-limit trips, a WAF block, a blocked rule),
admits a real browser after the challenge, expires on time, and leaves two
revisions and a readable trail.

---

## Phase 4: Cache rules

Previously Phase 8.

| # | Item | Direction | Proof of completion |
| --- | --- | --- | --- |
| 4.1 | **Cookie names to ignore, with presets.** | `ignore_cookie_names` exists. Add presets for common analytics cookies and show the hit ratio effect. | Hit ratio measured before and after on a recorded traffic sample. |
| 4.2 | **Path rules for cache behavior.** | Bypass or TTL by path prefix or suffix, inside the typed domain settings, bounded count. | E2E per rule type. |
| 4.3 | **Purge by prefix, only if needed.** | Decide from purge logs. Build on the per-URL generation mechanism only if full purge is overused. | Decision recorded with the numbers. |

**Gate:** each shipped item has a before and after measurement.

### Phase 4 completion gate (recorded 2026-11-13, branch `dev`)

| Field | Status |
| --- | --- |
| Implementation | Done for 4.1 presets and 4.2 path rules (commits `6a68680`, `98fe0c2`, `9105081`). 4.3 purge by prefix is not built: it waits for purge-log numbers. |
| Tests | `CacheApiTest` and `FilamentWorkflowTest` pass (40 tests): bounds, permissions, validation, the cross-field checks, and the form action. |
| Runtime qualification | `tests/e2e/openresty_runtime.py` passes on a fresh image build (28 min): prefix cookie names, bypass prefix and suffix rules, an unmatched path, and a TTL rule that outlives a one-second origin TTL. |
| Browser suite | Only `tests/browser/specs/cache-rules.spec.ts` was run, as the screen it covers is the only one changed: 12 of 12 pass (light and dark, desktop and phone), with placeholder, tooltip, and accessibility checks and a new form snapshot. The full suite is re-run in Phase 11. |
| Documentation | `docs/guides/cache.md` and `docs/reference/limits.md` updated. |
| Open | 4.1 hit ratio before and after on a recorded traffic sample, and the 4.3 decision from purge logs: neither has been measured yet. The gate stays open on these two items. |

### Phase 5 completion gate (recorded 2026-11-13, branch `dev`)

| Field | Status |
| --- | --- |
| Implementation | Done for 5.1 to 5.6: typed custom rules, attack score, rate-limit rules, adjustable managed rules, user agent and hotlink presets, platform rulesets. The old match type and value rule shape is gone; one migration converted existing rules in place. |
| Tests | The full Laravel suite passes (698 tests, 15,102 assertions): validation of every bound, permissions, idempotent routes, duplicate refusal, revision rollback, rollout to exactly the affected domains, and the panel flows. |
| Runtime qualification | On real OpenResty cells: `tests/e2e/custom_rules.py` passes (every field and action, attack score on clean and attack payloads, Observe, blocking and skip, rate limits tripping at the stated count and releasing after their duration, category, rule, log-only and threshold adjustments, both presets, platform rules first and removed again, and the scripted attack stopped by a score rule, a rate-limit rule and a platform rule with each stop found in the request telemetry). `managed_waf.py`, `edge_security.py`, `openresty_runtime.py`, `vector_traffic.py`, `telemetry_analytics.py`, and `clickhouse_migrations.py` pass. |
| Browser suite | Only the screens this phase changed were run: `custom-rules.spec.ts` (24 of 24), `waf-tuning.spec.ts` (8 of 8), `platform-rulesets.spec.ts` (16 of 16), each in light and dark, desktop and phone, with placeholder, tooltip and accessibility checks. The full suite is re-run in Phase 11. |
| Documentation | `docs/guides/security.md`, `docs/guides/managed-waf.md`, `docs/reference/limits.md`, `docs/architecture/data-model.md`, and the generated API reference. |
| Open | The attack score weighs five points per CRS anomaly point; its false positive rate is not measured on a recorded clean sample. Real HTTPS `tls_version` values are not covered by an E2E (plain HTTP reports `none`). Revision snapshots written before the typed rules cannot be rolled back to. The WAF no longer inspects responses. |

---

## Phases 5 to 8: A simple WAF

Today: managed WAF (OWASP CRS on ModSecurity) with Off, Observe,
Recommended, and High Sensitivity profiles and scoped exclusions; allow or
block rules by IP, CIDR, country, or continent; per-domain client rate and
connection limits.

Rules for all four phases:

- **One rule engine.** Custom rules, rate-limit rules, user agent blocking,
  and hotlink protection are all typed rules evaluated by one Lua module in
  the cell, delivered in the domain's bundle. Presets are just rules.
- **Typed rules, not an expression language.** A rule is: up to 5 conditions,
  all of which must match. Each condition is a field from a fixed list, an
  operator from a fixed list (equals, not equals, starts with, ends with,
  contains, in list, greater than, less than), and a value. No regex until it
  can be bounded. Every detection in Phases 7 and 8 adds a field, not a new
  engine.
- **Fields:** hostname, path, query presence, method, country, continent,
  ASN, client IP or CIDR, user agent, referer, a named header, a named cookie
  present, content type, body size, TLS version, attack score, and the
  detection flags added later.
- **Actions:** block, challenge (Phase 3), log, allow (stop evaluating custom
  rules), and skip managed rules.
- **Order:** platform rulesets, then domain custom rules, then rate-limit
  rules, then managed rules.
- **Bounds:** maximum rules per domain, values per list, and body bytes
  inspected, set as platform settings with hard ceilings.

## Phase 5: Rules and managed rules

| # | Item | Direction | Proof of completion |
| --- | --- | --- | --- |
| 5.1 | **Custom rules.** | Replace today's IP and geo rules with the typed rule model above. Existing rules are migrated to it. Create, edit, reorder, disable, and preview in the domain panel and API. | E2E per field and action on a real cell; validation tests for every bound. |
| 5.2 | **Attack score.** | Each inspected request gets a score from 1 (attack) to 99 (clean) from the CRS anomaly scores, overall and for SQL injection, XSS, and command injection. It is logged and usable as a rule field, also in Observe mode. | E2E: known payloads score low and clean requests score high; a rule on `attack_score < 20` blocks. |
| 5.3 | **Rate-limit rules.** | A rule's conditions, a counting key (client IP, IP and path, or a named header), a threshold per period (10 s, 1 min, 10 min), an action, and a duration. Counted per cell; the copy says it is not a global count across PoPs. | E2E: the threshold trips at the stated count and releases after the duration. |
| 5.4 | **Managed rules you can adjust.** | Per category on or off, per-rule disable or log-only, paranoia level, and anomaly threshold, on top of today's profiles. The ruleset version is shown; updates come only through a release, never downloaded at runtime. | E2E: a disabled rule no longer blocks; ruleset version matches the release. |
| 5.5 | **User agent blocking and hotlink protection.** | Two presets in the UI. User agent: a list of values with block or challenge. Hotlink: file extensions plus allowed referer hosts, with an empty referer allowed. Both are stored as custom rules. | E2E for each preset. |
| 5.6 | **Platform rulesets.** | Administrators define a ruleset (custom and rate-limit rules) and apply it to all domains or a selected list. It runs before domain rules. Domain users see its name and its matches, not its contents. | E2E: a platform rule blocks on two domains; removing it restores both. |

**Gate:** the scripted test attack is blocked by a custom rule on attack
score, a rate-limit rule, and a platform ruleset, each found in telemetry.

## Phase 6: Security events and analytics

| # | Item | Direction | Proof of completion |
| --- | --- | --- | --- |
| 6.1 | **Security events.** | A domain page of mitigated requests (sampled logs only): time, rule, action, attack score, path, country, ASN, user agent, request ID. Filters on each. "Create exception" and "Create rule" start from an event. Sampling and retention are bounded and stated. | Browser test of filters and both shortcuts. |
| 6.2 | **Security analytics.** | All requests, mitigated or not, by attack score band, action, country, ASN, path, and user agent, from ClickHouse rollups. | Query test against a counted test sample; browser test. |
| 6.3 | **Security event alerts.** | Per-domain threshold on mitigations per period, sent through the notifier (Phase 1). Coalesced. | E2E: the attack script produces one alert, not one per request. |

**Gate:** a domain user finds the attack script's requests in security
events, builds a rule from one, and receives one alert.

## Phase 7: Detections

Each detection sets a field for custom rules and logs it. None blocks on its
own unless a rule says so. Datasets come through a signed release, never
from an outside service at runtime.

| # | Item | Direction | Proof of completion |
| --- | --- | --- | --- |
| 7.1 | **Leaked credentials.** | The domain names its login paths and the username and password fields (with presets for common form and JSON shapes). The cell checks a hash of the password against a bounded leaked-password set, delivered as a release artifact with a fixed memory size. Sets `leaked_password`. Passwords and hashes are never logged. | E2E: a known leaked password sets the flag; a fresh password does not; no log contains either. |
| 7.2 | **Sensitive data detection.** | Log-only inspection of the first bounded bytes of text responses for card numbers (with Luhn check), IBANs, private keys, and common API key formats. Shows in security events with the match masked. | E2E per pattern; no unmasked value is stored. |
| 7.3 | **Security for AI applications.** | The domain marks its LLM endpoints and the JSON field holding the prompt. A managed pattern ruleset sets `prompt_injection_score` and `prompt_pii`. The copy says it is pattern-based. | E2E with a fixed set of known injection prompts and clean prompts; false positive rate recorded. |

**Gate:** each detection has a measured false positive rate on a recorded
clean sample and a measured latency cost.

## Phase 8: API and client-side protection

| # | Item | Direction | Proof of completion |
| --- | --- | --- | --- |
| 8.1 | **Application profiles (API schema).** | Upload an OpenAPI 3 document, bounded in size and number of operations. Control compiles it into a typed table of paths, methods, and parameters; the cell sets `schema_violation` (unknown path, wrong method, missing or mistyped parameter, JSON body too large). Review detections in security events, then block with a custom rule. | E2E per violation type; an invalid document is refused with a clear error. |
| 8.2 | **Client-side security.** | For opted-in domains, the edge adds a report-only Content-Security-Policy to HTML responses and receives reports on a reserved path, bounded per domain, through Vector into ClickHouse. The domain page lists scripts and connections seen per page with first-seen dates. A new script sends a notification. An enforced policy built from the approved list is a later, separate choice. | E2E: a page loading a new script appears in the list and triggers one notification. |

**Gate:** a test API with a schema shows its violations, and a test page
shows a newly added script, both in the panel.

---

## Phase 9: Measured performance envelope

Previously Phase 9. Existing results (20,000 domains, 10,000 DNS changes) are
control-plane and single-cell results on a development host only. The audit's
50-connection ramp is a health check, not a capacity figure.

Measure on dedicated hosts of the size the sizing guide recommends. Record
hardware, OS and runtime versions, topology, counts of PoPs, edges, cells,
and domains, dataset, concurrency, duration, throughput, latency
percentiles, errors, the saturated resource, and recovery time:

1. Control-plane mutation throughput.
2. DNS propagation latency, change accepted to answer on every PoP.
3. Edge request throughput on one host.
4. Cache-hit and cache-miss throughput.
5. Origin connection saturation and recovery.
6. TLS handshake capacity, including the gateway's handshake budget.
7. Recovery after losing one edge, with PoPs in separate networks (three
   PoPs, two regions).
8. WAF cost: latency with managed rules, custom rules, and each detection on.
9. Log push and notifier load: bounded worker time per domain at the
    stated domain counts.

Publish one-cell, one-host, and multi-PoP results under separate headings.
Choose capacity work from the saturated resource each test names.

**Gate:** every row has a recorded run, and the sizing guide quotes only
those runs.

## Phase 10: Evidence for adoption

Previously Phase 10.

| # | Question | What must be shown |
| --- | --- | --- |
| 10.1 | Independent security review of artifact signing, origin validation, cache isolation, edge enrollment, identity renewal and revocation, deployment integrity, and the log push and notifier egress. | An external review; its scope, findings, and fixes published. |
| 10.2 | Why deploy this instead of running OpenResty, PowerDNS, DNSdist, and Grafana directly? | A third party installs a fleet from the documentation and passes the Phase 11 gate without help. |
| 10.3 | What is hard to copy? | Measured envelopes (Phase 9), a clean external review (10.1), and a record of releases that passed staging gates, kept current per release. |

**Gate:** the external review is published and the records exist for the
current release.

---

## Phase 11: Missing gate records and full qualification

The last phase. Nothing new is built here. It closes every gate the work
above left open and proves the finished system end to end.

| # | Item | Direction | Proof of completion |
| --- | --- | --- | --- |
| 11.1 | **Audit Phase 1: exposure.** | Outside scan and path sweep from a host outside the management allowlist. | Stored with the release: only SSH, DNS, HTTP, HTTPS, and the edge-control listener answer; no management path, metrics endpoint, or version string. |
| 11.2 | **Audit Phase 2: cache isolation.** | Run `openresty_runtime.py` and `edge_purge_protocol.py` and the browser suite, and record the results the gate left blank. | Both E2E files and the browser suite pass, listed in the record. |
| 11.3 | **Audit Phase 3: edge health.** | On staging, measure the time from a fixed heartbeat to a healthy edge. Run the split ClientHello script on both PoPs. | Recovery within one heartbeat interval, measured; all six cases pass. |
| 11.4 | **Audit Phase 5: telemetry.** | Run the Grafana and panel browser suites against staging, read-only. Replay the scripted test attack. | Suites pass with one screenshot per dashboard; the attack is found by domain, edge, reason, and rule, and its request, download, and upload counts match. |
| 11.5 | **Full test run for Phases 1 to 10.** | On one fresh Compose stack: the Laravel suite, Go tests, contract and observability tests, every `tests/e2e` file added by these phases, and the full browser suite with visual snapshots and accessibility checks. Then each phase's own gate re-run on staging. | One record listing every suite, its result, and the commit it ran on. No unexecuted test is reported as passed. |
| 11.6 | **Documentation check.** | Every page touched by Phases 1 to 10 matches the running system. | Each page reviewed against the record in 11.5. |
| 11.7 | **Tooltip and placeholder check.** | Walk every screen of the platform, in both Filament panels and the Fleet web, not only those added or changed by Phases 1 to 10, against the frontend rules. | A browser test crawls every screen of both panels and the Fleet web and lists zero inputs without an example placeholder and zero complex items without a tooltip. |

**Gate:** one record per audit phase (11.1 to 11.4) and one full
qualification record (11.5), all on the same release.

---

## Out of scope unless the owner admits it

BGP control, volumetric scrubbing, machine-learned bot or attack scoring,
outside verification services on the request path, Kubernetes,
microservices, Kafka, a second dashboard or backend, billing or reseller
hierarchies, plugin runtimes, custom expression languages, and multi-origin
load balancing.
