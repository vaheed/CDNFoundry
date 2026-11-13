---
title: WAF roadmap
description: The plan for a stronger CDNFoundry WAF, starting from a code-verified audit of what the edge runs today. Eleven ordered phases, each with measurable acceptance criteria and verification.
---

# WAF roadmap

**Status: active.** Written 2026-11-14. It replaces the earlier roadmap in full.
It starts with an audit of the code on `dev` (the OpenResty runtime, the
ModSecurity and CRS integration, the gateway, the agent, the control plane, and
the tests) and then lists ordered phases. Work happens on `dev`, one phase at a
time, in order. A phase starts only when the phases it depends on have passed
their gates.

This roadmap is part of the product contract in `AGENTS.md`. Where a phase
conflicts with that contract, the contract wins, and the phase is changed first.

## 1. Starting point

### How a request is judged today

Every request reaches one data-driven OpenResty runtime
(`docker/openresty/runtime.lua`). Behind the edge gateway, the cell trusts
PROXY protocol only from the gateway's networks. The access phase runs in this
order:

1. Host and SNI checks, the drain and emergency switches, and the domain's
   enabled flag.
2. Request safety: the WAF body limit, malformed JSON, the allowed methods, the
   header and body size limits, and the incident profile's methods and body size.
3. Client address (a per-domain trusted proxy list for `X-Forwarded-For`), then
   country, continent, and ASN from local MaxMind files.
4. Detections, run only when a rule uses their field: the leaked password check,
   the prompt checks, and the API schema check (the schema check always runs
   when a schema exists).
5. Attack score. When the managed WAF is on, or a rule uses a score field, the
   cell opens a new loopback TCP connection to a second ModSecurity listener and
   copies the request to it. CRS scores the request there without blocking and
   returns its anomaly points in a redirect address. When the domain has
   log-only rules, the request is scored twice. After scoring, inspection in the
   cache location is switched off for that request.
6. Typed custom rules: platform rulesets first, then the domain's rules.
   Actions are `block`, `allow`, `log`, `challenge`, and `skip_managed`.
7. Rate-limit rules: fixed windows per cell, keyed by client address,
   address and path, or a named header.
8. The managed WAF decision: block when the score reaches the threshold.
9. Challenge, from a rule or from the incident profile.
10. Built-in per-client and per-domain request and connection limits, in
    fixed one-second windows per cell.
11. Cache key, admission, and the internal redirect to a cache location.

Rejections are counted per reason in shared memory. The agent sends them to the
control plane in its heartbeat. There they drive the domain's automatic security
state and the security alert. Every request is also logged through Vector to
ClickHouse.

### Capability status

Status terms: **Verified** means implemented and covered by a real-cell or
real-service test. **Tested in parts** means implemented, with important paths
untested. **Basic** means it works but is a limited form of the capability.
**Partial** means part of the capability is missing. **Inert** means it is in
the data model or documentation but the edge does not enforce it. **Missing**
means no implementation was found.

| Capability | Status | What the code shows |
| --- | --- | --- |
| Managed rules (ModSecurity 3.0.17, CRS 4.30.0, pinned in the image) | Verified, with a gap | `managed_waf.py` and `custom_rules.py` pass on real cells. A request body is sent for scoring only when it has a `Content-Length`, so chunked bodies are scored on headers and URI only, and inspection is then switched off. This was found by reading the code; no test sends a chunked attack body. |
| Profiles and tuning (paranoia, threshold, categories, single rules, log only) | Verified | A header-driven control file is generated at image build from the rule catalog. Log only costs a second full CRS pass. |
| Matched rule visibility | Partial | A block always records rule `949110` and the threshold. The rules that matched are not returned to the cell. |
| WAF exclusions | Inert | The API answers `409 waf_exclusions_unavailable` and the snapshot carries `[]`. The only per-path way out is a `skip_managed` rule, which turns off all managed rules for that path. |
| Attack score | Basic | 99 minus 5 for each CRS point. It is not calibrated, and its false positive rate on real traffic is not measured. |
| Typed custom rules and platform rulesets | Verified | Up to 5 conditions, fixed fields and operators, no regex, 1,000 rules per domain. There are no fields for query argument values or cookie values, no rule expiry, and no shadow mode. "Preview" only validates the rule and reports its position. |
| Built-in limits and rate-limit rules | Verified, basic | Fixed windows, counted per cell. IPv6 clients are keyed by the full /128 address, though the gateway already groups by /64. The counters for all domains share one dictionary each, and a full dictionary evicts entries, so high-cardinality traffic to one domain can evict another domain's counters. Requests are counted, never responses. |
| Gateway connection and handshake limits | Verified (Go tests) | Per source (IPv4 address or IPv6 /64) and per hostname. |
| Automatic security state | Tested in parts | It covers the whole domain: 50 escalating events in 5 minutes tighten limits for every client, even when one client caused them. Cells report at most 20 noisy entries per heartbeat across all domains, so domains past the first 20 can miss escalation and alerts. |
| Under attack mode (incident profile) | Verified on a cell | WAF blocking at high sensitivity, protected limits, method and body limits, and the challenge, with expiry by the cell's own clock. The challenge bypass prefixes are fixed to `/api/` and `/.well-known/`. The staging gate is still open. |
| Proof-of-work challenge | Partial | Fixed 16-bit difficulty. The HMAC key is derived from the node token and the cell name, so a token works on one cell only and the key never rotates. The token is bound to the exact client address and user agent, so it breaks when a mobile client changes address. A non-HTML request without a token gets a plain 403. Without JavaScript there is only an explanation, with no other way through. |
| Bot management and verified crawlers | Missing | There is only the user agent word-list preset. Nothing identifies real search engine crawlers, fingerprints TLS, or scores automation. |
| IP reputation and threat intelligence | Missing | There are only hand-written lists inside rules (100 values each). No cell shares anything with another. |
| Leaked passwords | Basic | About 100 common passwords, built into the image, not a signed dataset. |
| Sensitive data in responses | Basic | Log only, the first 16 KiB, uncompressed text only. |
| AI prompt checks | Basic | Phrase matching. Measured against 20 hand-written clean prompts. |
| API schema | Partial | OpenAPI 3 JSON only. Paths, methods, and path, query, and header parameters are checked. The body is checked for size only. No false positive or latency measurement. |
| Client-side script monitoring | Basic | Report-only CSP. Reports can be forged, so the list is only a hint. |
| Security events and analytics | Verified | Raw request events are kept for 7 days and an hourly security rollup for 400 days. The events tab shows the newest 500 of 24 hours. The alert counts heartbeat reports. |
| Ruleset version per cell | Missing | Cells do not report the CRS version they run (WAF upkeep check, 2026-11-12). |
| WAF performance | Not measured | `cell_throughput.py` measures cache hits only. The latency cost of the WAF, scoring, and rules has never been measured on a sized host. |

### The most important weaknesses

1. **Inspection can be bypassed and is costly.** Chunked request bodies are not
   inspected. Each scored request opens a new loopback connection and, with
   log-only rules, runs CRS twice. Nobody knows the latency cost.
2. **Nothing is measured.** No clean-traffic corpus, attack corpus, false
   positive rate, false negative rate, or latency budget exists for any
   detection.
3. **Mitigation is domain-wide or per address, nothing in between.** One noisy
   client can tighten limits for every visitor of a domain. IPv6 address
   rotation escapes per-client limits. One domain's attack can evict another
   domain's counters.
4. **Every cell is on its own.** Challenge tokens, rate counts, and offender
   knowledge stay on one cell. A client that moves between cells or PoPs starts
   over.
5. **There are no client signals beyond address, geography, and user agent.**
   No verified crawlers, TLS fingerprints, reputation, or bot score.
6. **Tuning is coarse and risky.** There are no scoped exclusions and no shadow
   mode. Rules cannot expire. Nobody can see which CRS rule matched or how often
   a rule matches.

## 2. Direction

- **Measure first.** Every detection, limit, and challenge change is judged
  against the same corpora and benchmark, built in Phase 1. A phase that cannot
  show its false positive rate, its false negative rate, and its latency cost
  is not complete.
- **Decide at the edge.** Every security decision is made in the cell from
  local state. The control plane manages policy, compiles and signs data,
  aggregates reports, and runs analytics. No request waits on Laravel, another
  cell, another PoP, or an outside service.
- **Mitigate the client first, the domain last.** Most actions target a client
  key (an address, an IPv6 prefix, a session, or a token). Domain-wide
  tightening is kept for attacks that come from many sources.
- **Bring intelligence in as data.** Crawler ranges, reputation, IP lists, and
  password corpora reach cells as signed, versioned, bounded datasets. Each
  dataset has a fixed memory size and a last valid version that stays active.
- **Share between cells asynchronously.** Cells report bounded summaries, the
  control plane aggregates them, and cells receive the result within minutes.
  Losing that path removes only the shared layer, never local protection.
- **Make every decision explainable.** Each decision records which stage acted,
  which rule or signal matched, and which datasets it used. Heuristics are named
  and readable. There is no machine-learned scoring.
- **Ship every new detection observe-first.** It starts in shadow or log mode,
  shows its measured impact, and only then blocks.

Bounds that apply in every phase: one engine per concern, typed settings, no
expression language, no unbounded regex, no per-domain process, file, or reload,
and shared memory that is bounded and partitioned so one tenant cannot evict
another tenant's state.

## 3. Phase rules

### Gate

A phase is complete only when every field passes. The fields are recorded
separately at the end of the phase.

| Field | Required evidence |
| --- | --- |
| Implementation | Merged to `dev` in small commits. Replaced code is deleted. |
| Tests | Feature and unit tests for the happy path, permissions, validation, idempotency, failure, and last valid state. Go and Lua tests where the runtime changed. |
| Runtime qualification | Python E2E under `tests/e2e` on real cells, including IPv4 and IPv6, and the Phase 1 harness re-run with its numbers recorded. |
| Browser suite | `tests/browser` passes for every changed screen, with visual snapshots and accessibility checks. Every input has an example placeholder and every complex item has a help tooltip. |
| Documentation | Every affected operator and user page states the new behavior and its limits. |

### Frontend rules

Every changed screen, in both panels and the Fleet web page, has a short tooltip
on every complex item. Every text, number, URL, list, and tag input has a
realistic example placeholder. A placeholder is never the only label and never a
real secret.

### Measurement rules

Numbers come from the Phase 1 harness on the reference cell, a host of the size
the sizing guide recommends, unless a phase says otherwise. A development-host
number may be recorded, but it is labeled as one and never passes a gate.

---

## Phase 1: Verification baseline

**Objective.** Make false positives, false negatives, and latency measurable,
and record today's numbers, before any detection changes.

**Current state.** The E2E tests prove single behaviors with a few hand-written
payloads. There is no clean-traffic corpus, no use of the CRS regression tests,
no WAF latency benchmark, and no report of the ruleset version per cell.

**Scope.**

- An **attack corpus**: the CRS project's own regression tests for the pinned
  version, plus CDNFoundry cases for evasions that concern this runtime
  (chunked bodies, HTTP/2 without a content length, encodings, header limits,
  oversized bodies).
- A **clean corpus** of at least 10,000 requests: browser page loads, form
  posts, JSON APIs, file uploads, mobile API clients, webhooks, and crawlers.
  It is generated or recorded only from test systems, never from customer data.
- A **harness** that replays both corpora against a real cell at each profile
  and paranoia level and reports the block, log, and pass rates.
- A **latency and CPU benchmark** for WAF off, Observe, Recommended, and High
  sensitivity, with log-only rules, with 100 and 1,000 custom rules, with 20
  rate-limit rules, and with each detection on. Body sizes are 0, 4 KiB,
  64 KiB, and 1 MiB.
- **Ruleset and dataset versions per cell** in the heartbeat, shown on the cell
  and pool screens, with an alert when they differ from the pinned versions.

**Technical direction.** The harness is a Python tool under `tests/` that
drives the cell over the network. It needs no hook inside the runtime. Its
results are written as a machine-readable record that each later phase
compares against.

**Dependencies.** None.

**Acceptance criteria.**

- The harness produces, for each profile, the attack detection rate, the clean
  false positive rate, and the latency at p50, p95, and p99 with CPU per request.
  The baseline is recorded in this roadmap.
- The chunked-body case is in the corpus. Its result today is recorded, whatever
  it is.
- A cell running a different CRS version from the pinned one raises an alert
  within two heartbeats.

**Verification.** The harness itself is tested against a stub origin. One full
run on the reference cell is stored with the release record. The version
mismatch is tested by starting a cell from an image with a changed version label.

---

## Phase 2: Inspection integrity and cost

**Objective.** Inspect every request that should be inspected, once, at a known
cost, and record why each request was blocked.

**Current state.** Scoring copies the request over a new loopback connection
every time. A body without a `Content-Length` is not sent to scoring. Log-only
rules need a second CRS pass. Only `949110` is recorded. Exclusions are inert.
When scoring fails, the cell falls back to inspecting the request with the
level's unadjusted defaults.

**Scope.**

- **Body coverage.** Bodies without a `Content-Length` (chunked, and HTTP/2) are
  read up to the inspection limit and inspected. For a body over the limit, the
  domain chooses one explicit policy: refuse with 413, or inspect the first N
  bytes and mark the request `body_truncated` (a new rule field).
- **One pass.** Each request goes through CRS at most once, log-only rules
  included. Connections to the scoring listener are reused.
- **Matched rules.** Up to 8 matched rule IDs and their categories reach the
  cell and the request event for every blocked or logged request. Matched data
  is never included.
- **Scoped exclusions.** A rule or category is turned off for a hostname, path
  prefix, or method. The cell evaluates the scope and passes it to the scoring
  pass through the existing off-list mechanism. Exclusions on a single parameter
  are added only if a mechanism is proven free of the earlier bypass.
- **Explicit failure.** A scoring failure is counted, logged with a reason, and
  alerted. The fallback stays inline inspection, and its effect on tuning is
  documented.

**Technical direction.** Keep ModSecurity and CRS pinned in the image. Change
how the cell hands requests to the engine, not the engine. Every change is
proven on the pinned ModSecurity version, because libmodsecurity's behavior
differs from the reference.

**Dependencies.** Phase 1.

**Acceptance criteria.**

- Every attack in the corpus that is blocked with a `Content-Length` is also
  blocked when sent chunked, and over HTTP/2 with no length.
- No request is inspected twice. The benchmark shows added p50 and p99 latency
  at Recommended at least 30% below the Phase 1 baseline, and log-only rules add
  under 10% on top of that.
- 100% of WAF blocks in the harness carry at least one matched rule ID besides
  `949110`.
- A path-scoped exclusion stops its rule only on that path. Sending the excluded
  path in a query, header, or encoded form does not trigger it.
- The clean false positive rate at Recommended is no higher than the Phase 1
  baseline.

**Verification.** The harness with the chunked and HTTP/2 variants. E2E for each
exclusion scope and the bypass attempts. A failure injection that stops the
scoring listener and checks the fallback, the counter, and the alert. The
benchmark compared with Phase 1.

---

## Phase 3: Client identity, fairness, and signal fidelity

**Objective.** Mitigate the client that misbehaves without harming other
visitors or other tenants, and report every domain's events accurately.

**Current state.** Cell limits, rate-limit rules, and challenge binding use the
full client address. Shared dictionaries are shared by all domains and evict
entries when full. Domain escalation reacts to any 50 events. Heartbeats carry
at most 20 noisy entries in total.

**Scope.**

- **One client key** for the cell and the gateway: IPv4 address, or an IPv6
  prefix (default /64, configurable per domain from /48 to /128). It is used by
  built-in limits, rate-limit rules, and challenge binding.
- **Fair memory.** Each domain gets a bounded share of counter memory. When the
  share is full, new keys fold into a coarser key (prefix, then ASN), never into
  "no decision", and other domains' counters are never evicted.
- **Client penalty box per cell.** A client key that trips repeated escalating
  events on a domain is challenged or blocked for a bounded time on that domain.
  The thresholds and actions are typed settings.
- **Breadth-aware escalation.** The automatic domain state escalates only when
  events come from more than a set number of distinct client keys. Events from
  fewer sources go to the penalty box.
- **Complete reporting.** Heartbeat summaries are bounded per domain and in
  total, ranked by count, and carry distinct client counts, so every domain's
  counts reach escalation and alerts.

**Technical direction.** All of it stays in the cell's shared memory and the
existing overlay and heartbeat paths. No new service. Counters are partitioned
by domain within fixed dictionaries.

**Dependencies.** Phase 1.

**Acceptance criteria.**

- A flood rotating through 1,000,000 IPv6 addresses inside one /64 is limited
  as one client.
- A flood with 1,000,000 distinct keys against domain A does not change when
  domain B's rate-limit rule trips: B's threshold is still met within ±5%.
- A single client generating 1,000 escalating events in 5 minutes does not
  change the domain state, and is penalized within 10 seconds on that cell.
  Traffic from 500 distinct clients does change the state.
- With 200 domains under attack on one cell, every domain's count reaches the
  control plane within two heartbeats.

**Verification.** E2E on a real cell with IPv4 and IPv6 traffic generators. A
cardinality flood test across two domains. A heartbeat test with 200 noisy
domains. Shared memory usage recorded under the flood.

---

## Phase 4: Policy lifecycle

**Objective.** Make every security change testable before it blocks, visible
while it runs, and quick to undo.

**Current state.** Rules apply on save. Revisions can be rolled back. There is
no shadow mode, no expiry, and no per-rule match count. Platform rulesets apply
to all their domains at once.

**Scope.**

- **Shadow mode** for any custom, rate-limit, or platform rule: the cell
  evaluates the rule and records what it would have done
  (`would_block`, `would_challenge`) without acting. A rule can move from shadow
  to enforce in one step.
- **Expiry** on any rule. The cell enforces it by its own clock, so temporary
  blocks end even when the control plane is down.
- **Match counts** per rule, enforced and shadow, by hour, from a ClickHouse
  rollup, on the rule list and in the API.
- **Impact estimate** before saving: what the rule would have matched in the
  last 24 hours, computed only over the fields the request events store, and
  labeled as partial.
- **Staged platform rulesets**: shadow first, then a chosen subset of domains,
  then all.
- **Measured propagation**: the time from saving a policy to it being enforced
  on every cell.

**Technical direction.** Shadow and expiry are rule attributes in the existing
typed model and snapshot. Counts and estimates come from ClickHouse, outside the
request path. Rollback stays the existing revision rollback.

**Dependencies.** Phase 2 (rule references in events), Phase 1.

**Acceptance criteria.**

- A shadow rule never changes a response. Its `would_*` count matches an
  enforce-mode run of the same traffic within 1%.
- An expiring rule stops acting within 2 seconds of its expiry, with the control
  plane stopped.
- Propagation p95 is 60 seconds or less from save to enforcement on all cells
  in a two-edge test. The measured value is documented.
- A rollback restores the previous behavior, proven by the harness.

**Verification.** Feature tests for the new attributes, permissions, and
idempotency. E2E for shadow, expiry, and rollback. A ClickHouse query test
against a counted sample. Browser tests for the shadow badge, the counts, and
the estimate.

---

## Phase 5: Signed security datasets

**Objective.** One safe way for large, changing security data to reach cells,
without a release and without any runtime download.

**Current state.** The leaked-password list is built into the image. GeoIP and
ASN databases are files on the host. Rule lists hold 100 values each. No
general dataset channel exists.

**Scope.**

- A **dataset artifact**: typed (CIDR list, hash set), versioned, signed by the
  control plane's artifact key, with a fixed maximum size per type. Cells hold
  it in a fixed-memory structure with lookups in logarithmic time or better.
- **Named IP lists** that customers and administrators maintain (up to a
  platform limit, for example 10,000 entries per domain list), usable from rules
  with `in_list`.
- **Platform datasets**: published search engine crawler ranges, a larger
  leaked-password corpus (at least 1,000,000 entries), and later the greylist
  (Phase 8). Sources come with a release or from an import an administrator
  enables in the control plane. A cell never fetches anything.
- Per-cell dataset versions in the heartbeat (built on Phase 1).

**Technical direction.** Reuse the signing, agent delivery, and atomic
activation already used for snapshots. A dataset that fails validation is
refused, the last valid version stays active, and the failure is alerted.

**Dependencies.** Phase 1, Phase 3 (memory bounds).

**Acceptance criteria.**

- A 10,000-entry list adds under 20 µs at p99 per request lookup, measured.
- A corrupted, oversized, or wrongly signed dataset is refused on every cell,
  and the previous version keeps matching.
- A list change reaches every cell within the Phase 4 propagation bound.
- The cell's memory stays within the stated budget with every dataset at its
  maximum size.

**Verification.** Go tests for signing and refusal. Lua tests for lookups. E2E
for activation, refusal, and last valid state. A memory measurement at maximum
size.

---

## Phase 6: Rate limiting

**Objective.** Rate limits that follow real abuse patterns, are accurate at
window edges, and give a stated approximation across cells.

**Current state.** Fixed windows of 10 s, 1 min, and 10 min, per cell. Keys are
address, address and path, or header. The actions are block, challenge, and log.
Only requests are counted.

**Scope.**

- **Sliding window approximation** (weighted current and previous window) for
  rate-limit rules.
- **More keys**: client key (Phase 3), ASN, country, cookie value, query
  argument, and combinations of two. Header and cookie values are hashed.
- **Counting on outcome**: a rule can count only responses with chosen statuses,
  for example 401 and 403 on a login path, counted in the log phase.
- **Responses**: 429 with `Retry-After`, challenge, or block, with a
  machine-readable body for non-HTML clients.
- **Multi-cell modes**: `per_cell` (today), and `shared`, where each cell
  enforces the threshold divided by the number of cells serving the domain.
  Long windows (1 hour, 24 hours) are aggregated by the control plane from
  heartbeat summaries and sent back as client-key mitigations. The documentation
  states the error bound of each mode.

**Technical direction.** Enforcement stays in cell shared memory. Cross-cell
aggregation is asynchronous and only adds mitigations; it never delays a request.

**Dependencies.** Phase 3, Phase 4, Phase 5 (lists as keys and exemptions).

**Acceptance criteria.**

- On one cell, a rule trips within ±5% of its threshold for steady traffic and
  within ±10% for traffic around window edges.
- In `shared` mode over three cells with balanced traffic, admitted requests
  stay at or below 120% of the threshold.
- A credential-stuffing run (5,000 failed logins from 200 addresses) is stopped
  by an outcome rule, and successful logins from the same NAT address are never
  counted.
- Long-window mitigations reach all cells within 5 minutes.

**Verification.** E2E on one and three cells with IPv4 and IPv6. A replayed
login abuse scenario. A memory bound test at maximum keys. Browser tests for the
new rule options.

---

## Phase 7: Challenge and bot mitigation

**Objective.** A challenge that works across cells and PoPs, adapts to risk,
does not break APIs or mobile clients, and is backed by explainable bot signals.

**Current state.** Proof of work at a fixed 16 bits with a per-cell key that
never rotates. Tokens are bound to the exact address and user agent. Non-HTML
requests get a plain 403. Under attack mode bypasses `/api/` and
`/.well-known/` (fixed). No crawler verification, no TLS fingerprint, no bot
signals.

**Scope.**

- **Pool-wide keys with rotation**: challenge keys issued by the control plane,
  delivered in signed bundles with a key ID, two keys active during a rotation.
  A token works on any cell that serves the domain, in any PoP.
- **Binding** to the client key (Phase 3) and the user agent family, so a client
  that changes address inside its prefix keeps its token. Each token has its own
  request budget, so a token shared by a bot farm stops working.
- **Adaptive difficulty** from the penalty box (Phase 3), reputation (Phase 8),
  and the domain state, capped by a measured solve time.
- **API-aware behavior**: non-HTML requests get a machine-readable refusal with
  the reason. Each domain chooses its own bypass prefixes and methods for under
  attack mode, instead of the fixed list.
- **Verified crawlers**: a request whose user agent claims a known crawler and
  whose address is in that crawler's published ranges (Phase 5) gets
  `verified_bot` and a category. The same claim from outside the ranges gets
  `fake_crawler`. Verified crawlers skip the challenge by default.
- **TLS fingerprint**: the gateway already parses the ClientHello. It computes a
  JA4-style fingerprint and passes it to the cell in a PROXY v2 TLV. Without a
  gateway the field is empty.
- **Bot signals**: a `bot_score` from named, readable components (the
  fingerprint does not match the claimed browser, browser headers are missing,
  automation markers, a fingerprint in a known automation dataset, a verified
  crawler). The components are logged, and the score is a rule field.

**Technical direction.** No third-party CAPTCHA and no outside service on the
request path. Key material is never in snapshots the customer can read and never
logged. The challenge page meets the browser suite's accessibility checks. Its
copy says that JavaScript is needed and what the visitor can do.

**Dependencies.** Phase 3, Phase 5, Phase 6.

**Acceptance criteria.**

- A token issued on one cell is accepted on another cell in another PoP. A key
  rotation re-challenges no client whose token is still valid.
- A mobile client moving between addresses in one /64 keeps its token.
- Solve time p95 at maximum difficulty is 3 seconds or less on the slowest device
  in the test matrix.
- A headless flood (scripted HTTP clients and a headless browser) does not get
  through more than the measured fraction stated in the documentation. Real
  browsers in the browser suite pass on desktop and phone.
- A verified crawler reaches the origin under attack mode. A fake crawler does
  not.
- `bot_score` marks under 1% of the clean corpus as automated at the default
  threshold.

**Verification.** Go tests for the fingerprint and TLV. Lua tests for token and
key handling. E2E across two edges. The harness with bot and clean corpora.
Browser and accessibility tests of the challenge page. An external review of
the token and key design, included in Phase 11.

---

## Phase 8: Client reputation and shared threat intelligence

**Objective.** A client that attacks one domain or PoP is treated with suspicion
everywhere within minutes, without blocking shared NATs, without exposing one
tenant's data to another, and without making a request wait on a remote system.

**Current state.** Nothing is shared between cells. The penalty box (Phase 3) is
per cell and per domain.

**Scope.**

- Cells report a bounded summary of their top offending client keys with reason
  counts, every heartbeat.
- The control plane aggregates the summaries over a sliding window across
  edges, PoPs, and domains, and assigns each client key a reputation band that
  decays over time. It publishes the result as a greylist dataset (Phase 5) with
  a TTL on each entry.
- Cells expose `client_reputation` (a band) and `greylisted` as rule fields. The
  platform default is to challenge greylisted clients, never to block them.
  Domains can opt out or ask for stricter handling.
- **NAT and crawler safety**: a key with a high share of clean traffic or solved
  challenges is excluded or capped. Verified crawlers are never greylisted.
- **Privacy and isolation**: client addresses in the aggregate are kept for 7
  days at most. A customer sees "platform reputation" as the cause, never the
  other domain or tenant.

**Technical direction.** This builds only on the heartbeat, the control plane
queues, and the dataset channel. There is no cell-to-cell traffic and no
synchronous lookup. When the control plane is down, cells keep the last greylist
until its entries expire, then fall back to local protection only.

**Dependencies.** Phase 3, Phase 5, Phase 6, Phase 7.

**Acceptance criteria.**

- A client attacking domain A through three PoPs is challenged on domain B in
  another PoP within 5 minutes at p95.
- In a NAT simulation (one address, 95% clean traffic from many browsers, 5%
  attack), the address is not greylisted.
- With the control plane stopped, greylist entries expire on schedule and no
  request is blocked by a stale entry after its TTL.
- No customer-facing API or screen shows another tenant's domain or data.

**Verification.** E2E on a two-PoP staging stack. NAT and crawler simulations
in the harness. Policy tests for tenant isolation. A retention test for the
aggregate.

---

## Phase 9: API and account protection

**Objective.** Positive security for APIs and protection against account
takeover, both decided at the edge.

**Current state.** The API schema checks paths, methods, and parameters, and the
body for size only. Only OpenAPI JSON is accepted. The leaked-password list is
small. There is no JWT validation and no endpoint discovery.

**Scope.**

- **JSON body validation** against the schema: types, required fields, enums,
  and unknown fields, with bounded depth, field count, and body size. Cookie
  parameters. YAML documents are accepted and converted in the control plane.
- **JWT validation** at the edge for marked paths: signature against keys the
  customer uploads (no runtime JWKS fetch), `exp`, `nbf`, `iss`, and `aud`. Sets
  `jwt_valid`, plus a hashed subject usable as a rate-limit key (Phase 6).
- **Account takeover**: login outcome counting (Phase 6) together with the
  large leaked-password corpus (Phase 5). Usernames and passwords are never
  logged.
- **Endpoint discovery**: the control plane groups observed paths into
  templates from request events and offers them as a starting schema.

**Technical direction.** Compile the schema and keys in the control plane into
bounded tables in the snapshot. The cell never parses OpenAPI. Every new check
starts in shadow mode (Phase 4).

**Dependencies.** Phase 4, Phase 5, Phase 6.

**Acceptance criteria.**

- On an API clean corpus, body validation marks under 0.5% of requests, and adds
  under 1 ms p99 for 16 KiB bodies.
- Tokens that are expired, wrongly signed, or for another audience are refused,
  and valid tokens pass, with IPv4 and IPv6.
- A leaked password from the large corpus sets the flag, and no log or event
  holds it.
- Discovered templates cover at least 90% of the requests in a test API's
  traffic.

**Verification.** Feature tests for compilation and refusal. Lua tests for
validation. E2E per check. Harness measurements of false positives and latency.
Browser tests of the schema and JWT screens.

---

## Phase 10: Investigation and analytics

**Objective.** An operator or domain owner can explain any decision and
follow an attack from start to end.

**Current state.** Request events carry the action, reason, rule reference, and
scores. The events tab shows the newest 500 of 24 hours. The alert counts
heartbeat reports. Attacks are not grouped.

**Scope.**

- A **decision trace** on each mitigated or shadow-matched request: the stage,
  the rule references, matched CRS IDs (Phase 2), scores, client key,
  reputation band, bot components, and dataset versions. It holds no request
  data beyond what events store today.
- **Security events without the 500 cap**: cursor pagination over a bounded time
  range, with the same filters.
- **Attack episodes**: mitigations grouped by domain, time, and overlapping
  client keys or rules into one record with a start, an end, a peak, and its top
  sources, paths, and rules. Alerts are sent per episode.
- **Views**: top client keys, rules by match count, bot and reputation
  breakdowns, and false-positive candidates (blocks followed by solved
  challenges or by allows from the same key).
- **Stated retention** for each of these, with redaction rules unchanged.

**Technical direction.** Everything is computed in ClickHouse rollups and
bounded queries. The request path only adds bounded fields to the existing log
line.

**Dependencies.** Phases 2, 3, 4, 7, and 8.

**Acceptance criteria.**

- For every mitigated request in a harness run, the trace names the stage and
  the rule or signal that acted.
- The events list pages through a 1,000,000-event attack in a 24-hour range with
  each page answered under 1 second on the reference ClickHouse.
- One scripted attack produces one episode and one alert.
- The log line grows by no more than the stated byte budget.

**Verification.** ClickHouse query tests against a counted sample. E2E from
cell to ClickHouse to the API. Browser tests for traces, episodes, and views.

---

## Phase 11: Full qualification and external review

**Objective.** Prove that the finished WAF works as one system on a real
multi-PoP fleet, under load and failure.

**Current state.** Earlier gates were often closed on a development stack. No
staging run, performance envelope, or external review exists.

**Scope.**

- A staging fleet with three PoPs in two regions.
- **Attack scenarios**: an L7 flood from 10,000 addresses and from IPv6
  rotation, credential stuffing, scanners, the evasion corpus, and a headless
  browser farm. The clean corpus runs at the same time.
- **Failure injection**: control plane down, agent down, a corrupt dataset, the
  scoring listener down, full shared memory, ClickHouse down, and a PoP lost.
- **A WAF performance envelope** on the reference cell: requests per second and
  latency for each profile and feature, and the saturated resource.
- **An external review** of the challenge tokens and keys, dataset signing,
  tenant isolation, and the bypass corpus.

**Dependencies.** Phases 1 to 10.

**Acceptance criteria.**

- Every attack scenario is mitigated. The clean corpus's false positive rate
  stays at or below each phase's stated rate.
- In each failure, serving continues from the last valid state, and the
  documented failure behavior matches what happened.
- The sizing guide quotes only numbers from this run.
- The external review is published with its scope, findings, and fixes.

**Verification.** The harness and scenario scripts on staging, each with a
stored record listing the release, the commit, and every result. No unexecuted
test is reported as passed.

---

## 4. Completion

The roadmap is complete when:

- Phases 1 to 11 have passed their gates on the same release.
- Every row in the capability table reads Verified, or states in the
  documentation why it stays limited.
- The harness runs in qualification for every release. A release that worsens
  a recorded false positive rate, detection rate, or latency beyond its
  tolerance fails.
- Every security decision a cell makes can be traced to its rule, signal, or
  dataset version in the analytics.

## 5. Out of scope unless the owner admits it

Volumetric scrubbing and BGP control (the edge cannot absorb a saturated
uplink), machine-learned attack or bot scoring, third-party CAPTCHA or any
outside verification on the request path, synchronous calls between cells or
PoPs, custom regex or an expression language in rules, full CRS response
inspection and response rewriting, customer-uploaded `SecRule` content,
Kubernetes, microservices, Kafka, and a second dashboard or backend.
