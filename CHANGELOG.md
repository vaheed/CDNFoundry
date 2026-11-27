# Changelog

All notable changes are recorded here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses
[Semantic Versioning](https://semver.org/). The root `VERSION` file is the
single source of truth; `make version-check` enforces it.

## [Unreleased]

### Added

- A platform switch for active origin monitoring (`origin_monitoring`): off for new installations, on after an upgrade only where a scheduled check already existed. Off stops scheduling, cancels queued checks, refuses manual tests unless allowed, and shows each origin's active signal as disabled; passive detection and failover are unchanged. The group also holds the shortest interval, the representatives per origin and the probes per account per minute.
- Diagnostic headers have a per-domain choice (Inherit, On, Off) and a platform force-off override. The effective decision travels in the signed snapshot, and a rollback restores the domain's choice. Diagnostics add `cdnf-diag-origin-status`; the origin role, transitions, errors and the security reason are no longer sent in any response header.
- Public response headers: a platform setting group (`public_headers`) for a configurable prefix, a PoP label and an on/off switch. Every proxied response carries a per-request reference equal to the logged `request_id`, the cache decision, the PoP label, and a mitigation marker on edge refusals. The edge removes headers under the prefix that a client, an origin or a response rule supplies, and a response rule cannot name one. The prefix travels in the signed snapshot and applies without a restart.
- Possible false positives: the domain security tab and `GET /api/domains/{domain}/security/false-positive-candidates` list client keys that a rule blocked and that then solved a challenge. Cells now log a solved challenge as an allowed request with reason `challenge_solved` and the client key of the challenged request.

### Changed

- Responses the edge writes itself (blocks, refusals, malformed or oversized requests, an unknown host, origin failures) use one neutral page with a reference, JSON for non-browser clients, no body for HEAD, and no product or runtime name. An origin timeout is a 504 and any other origin failure a 502. The `Server` header is no longer sent. The challenge cookie is `_chk`, its path `/.chk/verify`, and its element and script names are neutral; the previous cookie and path are still accepted.
- Fleet `apply-config` activates a host narrowly when its change is only the firewall rules (the firewall step alone, no container or migration) or only documentation and scripts (a directory swap). Every other change keeps the full activation.
- Fleet shows a service-level plan for `apply-config`: for each changed host, the services and the minimum safe action (none, firewall, file, reload, restart, recreate, node-wide), computed from digests of the files, environment keys and Compose service hashes the host last ran. It prints no value. Execution still activates a changed host as a whole.
- The generated `firewall.sh` rebuilds its chains in an alternate chain and swaps the jump, so the filter is never empty during an update. Rerun `start.sh` or `firewall.sh` on each host; the first run retires the old chain names.
- Usage is stored sparsely with a coverage record per hour. An hour with edges and no telemetry is `incomplete` instead of a final zero, `cdnf:usage:finalize` catches up every missed hour, a rebuild that changes a finalized hour is recorded as a correction, and usage rows keep the domain name and owners from when they were written and are no longer deleted with the domain. `GET /api/domains/{id}/usage` returns `meta.coverage`. Apply the migration `2026_11_30_000400_add_usage_attribution_and_coverage`.
- Origin health is one state per origin role (`healthy`, `unhealthy`, `degraded`, `pending`, `unknown`, `stale`, `disabled`) computed from separate scheduled, manual and passive signals, in the panel and in `origin_health` of the record and `GET .../origin/health` (`status` is kept, `state` and `roles` are new). A passive failure no longer replaces a test result, evidence expires, and an edited origin makes it stale. Apply the migration `2026_11_30_000200_add_origin_signals_to_dns_records`.
- New origins default to **Verify origin TLS: off**, in the domain panel and the API (`verify_tls` may be omitted on create). HTTPS without verification encrypts but does not authenticate the origin. An explicit value is always kept, an omitted value on update keeps the stored one, and stored origins are unchanged.

### Added

- The security events list pages by cursor through the whole 24-hour window instead of stopping at 500 rows (`limit`, `cursor`, `meta.next_cursor`), and each event carries a decision trace: the stage, rule references, shadow rules, matched managed rule IDs and scores, with the signals the cell does not log named as unavailable. A **Trace** action opens it in the panel.
- Cells log a keyed client hash (`client_key`) and the snapshot sequence on mitigated and shadow-matched requests (15 bytes a line, none on others); apply the ClickHouse migration `2026_11_27_edge_event_decision_trace.sql` and restart Vector before the cells. The events list filters by `client_key`.
- Attack episodes: `GET /api/domains/{id}/security/episodes` and `/security/client-keys`, and an **Attack episodes** sheet on the Security events tab. The security alert now sends one notification per attack (it ends after 5 quiet minutes) instead of one per hour, and `cooldown_minutes` in `GET /security/alert` is replaced by `episode_quiet_minutes`.
- Cells report the WAF engine and Core Rule Set commits they were built from. The Cells list shows whether they match the pinned versions, and the `CellRulesetDiffers` alert fires when they do not. The Service pools list shows the same state for each pool.

- A WAF verification baseline: versioned CRS regression, evasion and 10,300-request clean corpora, a harness that replays them against a real cell at every protection and paranoia level, and a latency and CPU matrix. See [WAF verification baseline](docs/operations/waf-verification-baseline.md).
- Blocked and scored requests list up to eight matched CRS rule ids in the new `waf_rule_ids` field, and `waf_rule_id` names a matched rule instead of the blocking evaluation `949110`. Apply the ClickHouse migration `2026_11_25_edge_event_waf_rule_ids.sql` before starting updated Vector instances.
- Managed WAF rules and categories can be turned off only for a path prefix or a method (`tuning.scoped_exclusions`), in the domain WAF dialog and the API.

- Custom and platform rules can run in shadow mode, recording `would_block` and `would_challenge` events without changing a response, and can carry an expiry that the cell enforces by its own clock. Rollback keeps both.
- Rules show how many requests they matched in the last 24 hours, enforcing and shadow separately, from a new hourly ClickHouse rollup. `POST /api/domains/{id}/security/rules/estimate` estimates what a rule would have matched over the stored request fields and labels the result partial. Apply the ClickHouse migration `2026_11_26_edge_event_shadow_rule.sql` before starting updated Vector instances.
- Named IP lists: up to 5 per domain with 10,000 addresses or networks each, managed in the panel and at `/api/domains/{id}/security/ip-lists`, and used from firewall rules with the field `client_ip_list`. Cells search compiled ranges in 1 to 3 µs at p99, and the agent refuses a corrupt or over-budget list while the previous one keeps matching.
- Platform rulesets roll out in stages: `shadow`, then a chosen subset of domains, then all (`rollout_stage` and `enforced_domain_ids`).

- Rate-limit rules count in sliding windows (`window`), by cookie value, query argument, ASN, country or a combination of two keys (`key_name`, `second_key`, `second_name`), over periods up to 24 hours, and can count only responses with chosen statuses (`count_statuses`) so failed logins are counted and successful ones never are. `scope: shared` divides the threshold by the number of cells serving the domain. A limited request answers 429 with `Retry-After`, and a JSON body for clients that do not ask for HTML. Control-plane aggregation of the 1 hour and 24 hour windows across cells is not part of this release.

- Shadow platform reputation: a domain can opt in (`PUT /api/domains/{id}/reputation-sharing`), cells report their heaviest offending clients, and the control plane keeps a decaying band per client for administrators (`GET /api/admin/client-reputation`). It changes no request. Migration `2026_11_29_000100_create_client_reputation_shadow_tables` is expand-only.

- The API schema checks JSON request bodies (type, required, enum, string length, unknown fields, nested objects and arrays) and cookie parameters. The new violations are `invalid_body` and `unknown_field`, and the events `schema_invalid_body` and `schema_unknown_field`. The schema is bounded in depth, fields and enum values, and nothing is blocked unless a rule says so.

- JWT validation at the edge for chosen paths: RS256 and ES256 public keys uploaded by the customer (no key fetching), expiry, not-before, issuer and audience checks, per-key end times, the rule field `jwt_valid`, the event `jwt_invalid` and the rate-limit key `jwt_subject`. Migration `2026_11_29_000200_add_jwt_validation_to_domains` is expand-only.

### Fixed

- Rate-limit rules that count responses now count: the pending count was lost when the request moved to the cache or proxy location, and a key whose mitigation marker found no room in the domain's share was not blocked before the request.

- A cell with more than 100 noisy domains no longer reports the same first hundred forever. A cell lists up to 1000 domain and reason entries, and the agent sends 100 per heartbeat, the domains reported longest ago first, so 200 domains under sustained attack are all reported within two heartbeats.

### Security

- On cells with more domains than the 100-entry rate-limit floor can serve, domain shares shrink to an equal part instead of oversubscribing the shared dictionary, so one domain's keys cannot evict another's.
- Rate-limit rules no longer let client keys through once a domain's counter share is exhausted: untracked keys share one bounded overflow counter per rule and window, held to the rule's threshold.

## [2.1.5] - 2026-11-25

### Added

- Administrators can remove a disabled, drained edge after confirmation. Active or assigned edges remain protected.
- The documentation homepage and README include project sponsorship links.

### Changed

- DNS deployment failures record a class and the time of the first failure (migration `2026_11_30_000100_classify_dns_deployment_failures`, expand-only). A PowerDNS `422` on a customer's zone content no longer degrades platform health and shows as `failure_class: customer_content` in the deployment API; platform failures degrade it after 2 minutes, a pending row after 10, and a surge of 20 rejections in 15 minutes counts as a platform fault. `cdnfoundry_dns_deployments_drifted` now counts platform faults only, and `cdnfoundry_dns_deployments_customer_rejected` is new. `cdnf:dns:repair-drift` checks platform failures first.
- Fleet uses signed IPFire location data by default and refreshes country and ASN databases daily. Custom MMDB sources remain supported.
- Cells reopen GeoIP database handles after an update without restarting.

### Fixed

- Browser challenge proofs work on domains that allow only GET or HEAD, including Under Attack mode. The internal proof POST is exempt from domain and incident method restrictions; ordinary requests keep their configured method restrictions.
- The IPFire updater uses the patched Alpine runtime.
- The CSV dependency is updated to 9.29.1 to address GHSA-m9j4-54gj-jvf8.

See the [v2.1.5 release notes](docs/operations/releases/v2.1.5.md) for deployment, rollback, and qualification limits.

## [2.1.4] - 2026-11-22

### Added

- Redirect rules on a domain, in the new **Rules** tab and at `/api/domains/{domain}/request-rules`. A rule has up to five conditions from the firewall-rule builder and redirects to a fixed URL, the same path on another host, or the path with a prefix replaced, with 301, 302, 307, or 308. The edge decides them from the signed snapshot after every security stage, and never sends a redirect to the address a request already has. Presets write the www to apex, apex to www, and moved-path redirects. The access log names the rule that acted (`request_rule`). See the [request rules reference](docs/reference/request-rules.md).
- URL rewrite rules in **Rules > URL rewrites**: the path sent to the origin is kept, set to a fixed path, or has a prefix replaced (an empty new prefix removes it), and the query string is kept, removed, or set. The visitor's URL, the cache key, and URL purge stay on the address the visitor asked for. A path with a `.` or `..` segment, also percent-encoded, is refused on save and never sent by the cell. Presets remove or add a path prefix before the origin.
- Header rules in **Rules > Headers**: set or remove request headers sent to the origin and response headers sent to the visitor, on cache hits too. Every matching rule applies in order, with up to 10 changes per rule and 50 per request. Framing, hop-by-hop, forwarding, encoding, and `X-CDN-`/`X-CDNF-` headers can never be changed. Presets add security headers, hide origin software headers, and allow cross-origin use of static files.
- Cache rules in **Rules > Cache**: bypass the cache, or set the edge TTL, the browser TTL, and whether origin cache headers are respected, for matching requests. The first matching rule applies and its outcome is part of the cache policy. Presets cache static files longer and bypass the cache for admin paths or signed-in visitors.
- A redirect can send a request to the same address over HTTPS, and two more redirect presets write it (**Redirect HTTP to HTTPS**) and send several hostnames to one host.

### Changed

- Cache path rules moved to cache rules. The upgrade migration turns each stored path rule into a cache rule in the same order (a prefix becomes **Path starts with**, a suffix **Path ends with**), and `PATCH /api/domains/{domain}/cache` now refuses `path_rules`. The cache settings sheet points to **Rules > Cache**.
- The domain page's **Custom rules** tab is now **Firewall rules**, with the same rules and API paths (`/api/domains/{domain}/security/rules`). The documentation and the security dashboard use the new name.
- An edge cell no longer runs ModSecurity inline on requests whose WAF mode is off: domains with the WAF off, requests the scoring step already decided, and requests a `skip_managed` rule exempts. ModSecurity switched its own engine off in phase 1 for them, but libmodsecurity applies that switch only from the next phase, so every remaining phase-1 CRS rule still ran. A cached request with the WAF off now costs about a quarter of the CPU it did. Requests whose score could not be computed are still inspected inline, unchanged.
- An edge cell parses the inline CRS rule set once instead of once per cache location. A cell starts in about 2.6 seconds instead of 7.4, and an idle cell uses about 105 MiB instead of 211 MiB.
- The WAF scoring step skips the CRS response rules: it answers with its own redirect, so no client response ever passed through them. The logging phase still runs, so a detection keeps its sanitized audit summary.
- The edge runtime uses jemalloc instead of musl's allocator. ModSecurity's string handling made allocation about a third of a WAF request's CPU; a WAF request now costs about 30 % less CPU and a cell about 30 MiB less memory.
- The edge access log goes to Vector only. The copy on the container log cost dockerd about half a core at 4,600 requests per second on one cell, and the operational log collector parsed every line only to drop it. `EDGE_ACCESS_LOG_STDOUT=true` restores the copy; a cell that cannot resolve Vector at startup still falls back to it.
- ClickHouse no longer keeps `system.background_schedule_pool_log`, and `system.error_log` and `system.query_metric_log` expire after three days like the query log.
- A production control-plane container builds Laravel's configuration, route and event caches and Filament's component and icon caches when it starts. Every FPM request had started the framework from scratch and scanned the icon sets: a trivial request took about 90 ms of CPU and now takes about 30. A container that cannot build them says so and serves without them.
- The sizing calculator checks whether one cell can carry the busiest domain (`--busiest-domain-rps`, `--cell-cpus`) and uses about 3,000 DNS queries per second per vCPU instead of 8,000: the earlier figure counted DNSdist's empty truncated replies as answers. See the [performance audit](docs/operations/performance-audit-2026-11-21.md).
- The production ClickHouse health check runs the same authenticated `SELECT 1` over the local HTTP interface instead of starting `clickhouse-client` every 10 seconds.

## [2.1.3] - 2026-11-15

Closes edge security gaps: managed-WAF body scoring, per-domain rate-limit budgets, IPv6 prefix counting, and distributed-evidence escalation. See the [release notes](docs/operations/releases/v2.1.3.md).

### Added

- The admin Customers list gains an Owner column and a nameserver verification filter.

### Changed

- Domain pickers in the admin panel list verified domains only, so a name that several users have claimed is no longer listed more than once.

### Fixed

- The browser challenge keeps a visitor's token when their address changes inside an IPv6 prefix or their browser updates. A token and a seed are bound to the client key (an IPv6 address counts under its prefix, as in rate limiting), not the exact address and User-Agent; `EDGE_CHALLENGE_BIND_USER_AGENT=true` restores the stricter binding. The proof-of-work difficulty is configurable per cell with `EDGE_CHALLENGE_BITS` (12 to 24, default 16). Tokens are still per cell.
- The admin edge page shows how many client connections are open across the edge's cells and how many connections the gateway has served since it started, so a gateway active count of zero can be told apart from a gateway that has seen no traffic. A cell no longer counts the agent's own status request as an open connection, so an idle cell reports none.
- Security events from busy cells are reported fairly. A cell reported only the first 20 domain and reason pairs it happened to see, the agent kept the first 20 across all cells, and the control plane refused more than 20, so a noisy domain could keep other domains' events, escalation evidence and alerts out for an hour. A cell now sends up to 100 entries with every recently active domain getting a slot before any domain gets a second, reports how many domains did not fit (`security_domains_dropped`), and the agent sends only entries whose total changed. The control plane accepts up to 200 entries and counts the excess instead of failing the heartbeat.
- **Back up now** on a fresh S3 bucket no longer fails with `repository does not exist: unable to open config file`: the control backup initialises a missing restic repository before its first snapshot, and still refuses to touch one that exists but cannot be opened.
- The Analytics focus filter on the admin Traffic and telemetry page no longer shows "Analytics unavailable" for the Traffic, Cache and DNS focuses. Their ClickHouse queries ordered by a name that is both a stored column and an aggregate alias, which ClickHouse rejects while `prefer_column_name_to_alias` is on.

### Security

- Fixed a managed-WAF bypass: a request body without a `Content-Length` header (an HTTP/1.1 chunked body or an HTTP/2 body) was never read for scoring, so the scoring step saw only the address and headers, returned a clean score, and switched off the inline inspection that would have caught it. The body now comes from Nginx whatever its framing, whether it sits in memory or a temporary file, and inline inspection is switched off only after a score exists for the complete request. A body over the limit is refused with `413` with or without `Content-Length`, and an unreadable or compressed body is refused (`400`, `415`) on a blocking level. If the scoring step fails, the request is inspected inline and recorded as `scoring_failed`. The `waf_body_limit` telemetry field now also reports `inspected`, `no_body`, `unreadable`, `unsupported_enc`, and `scoring_failed`, and the new security reasons are `waf_body_unreadable`, `waf_body_encoding`, and `waf_inspection_failed`. ModSecurity's non-file body limit now matches the 1 MiB inspection limit. See [Managed WAF](docs/guides/managed-waf.md#request-bodies).
- Fixed cross-domain interference in a cell's rate limiting: the counters and mitigations of every domain shared one dictionary, so a flood of distinct client keys against one domain evicted another domain's counters and active blocks. Each domain now has its own entry budget inside `rate_rules` and `traffic_limits` (an equal part of half the dictionary, with a floor), mitigations keep a separate budget from counters, and a domain past its share stops counting extra keys and records the new `rate_limit_state_full` event. Both dictionaries grow from 16 MB to 32 MB per cell. `waf_inspection_failed` and `rate_limit_state_full` never escalate a domain's automatic security state. See [Security](docs/guides/security.md#rate-limit-rules).
- Fixed IPv6 rate-limit evasion: the gateway grouped IPv6 clients by /64, but a cell counted each full /128, so a client could rotate addresses inside its prefix for a fresh allowance. Rate-limit rules, the baseline per-client request and connection limits, the challenge issue limit, and Nginx's own per-client limits now count an IPv6 client under its prefix (64 bits by default), and an address written two ways or as IPv4-mapped IPv6 is one client. `EDGE_IPV6_RATE_PREFIX` sets the prefix length and `EDGE_IPV6_EXACT_CIDRS` lists networks that keep per-address counting. See [Security](docs/guides/security.md#rate-limit-rules).
- Fixed automatic security escalation tightening a domain for every visitor because of one client. Fifty escalating events in five minutes, even from a single address, moved a domain to `suspected` and applied the `protected` limits (half the request rate, a 4 MiB body ceiling, and more) to everyone, and `client_rate_exceeded` fed itself. A cell now contains a client that causes 20 escalating events in a minute with a five-minute local ban (`client_temporarily_blocked`, never itself escalating), and reports how many distinct clients caused the events. The control plane escalates a domain only when the rate threshold is met and at least 10 distinct clients took part. Cells that do not report clients (older agents) give no evidence and never escalate a domain, so upgrade cells with the control plane. The reasons that never escalate now live in `security-event-reasons.json` (`non_escalating`) for both the cell and the control plane. See [Security](docs/guides/security.md#security-state).

## [2.1.2] - 2026-11-14

Limits an unverified domain to DNS records and keeps PostgreSQL small. See the [release notes](docs/operations/releases/v2.1.2.md).

### Added

- `cdnf:usage:compact` sums hourly usage into daily rows after 35 days and daily rows into monthly rows after a year, with the same totals, so `usage_rollups` stops growing by a row per domain per hour.
- `cdnf:history:prune`, `queue:prune-failed`, and `sanctum:prune-expired` run daily: finished edge tasks, cache purges, certificate orders, expired name holds, old failed jobs, and expired tokens no longer accumulate in PostgreSQL. New retention settings control the windows. A migration adds the settings, the `month` granularity, and the supporting indexes.

### Fixed

- Deleting a domain no longer turns Authoritative DNS and DNS deployments degraded while its zone waits for retirement: a pending retirement is the plan, so only failed retirements and real pending deployments count as drift, in the health page and the `cdnfoundry_dns_deployments_drifted` metric.
- The Fleet web Backups form now takes the S3 access key ID and secret access key, which it saves into the vault without showing them again.
- A domain whose nameservers are not verified can only be given DNS records: the WAF, security, cache, TLS, proxy, analytics, and log pages are hidden, the API answers them with `409 domain_not_verified`, and Geo-DNS and proxied records are refused until verification.
- The attack score, action, country, and network breakdowns on the domain Analytics page now include events stored before the `security_hourly` rollup existed (a ClickHouse migration backfills them).
- Next now works on the domain Security events list.
- Analytics breakdown and recent-event tables no longer cut off headings such as "Mitigated share", DNS response names, or client addresses.
- The System DNS identity "Add" buttons read "Add nameserver" and "Add pair" instead of printing the tooltip markup.

## [2.1.1] - 2026-11-14

Fixes an upgrade from 2.0.8 that stopped in `clickhouse-migrate`. See the [release notes](docs/operations/releases/v2.1.1.md).

### Fixed

- A Fleet rollback now runs the health gate on the restored release and reports a node that fails it, instead of
  counting a successful `start.sh` as a rollback. The control gate also checks that the administrator panel answers.
- Fleet validation refuses a host that holds files outside any release (a `compose.*.yml` overlay or a `hotfix`
  directory), because swaps and rollbacks would silently drop them.
- The ClickHouse migration that adds `edge_events.security_rule` now runs before the `security_hourly` view that reads it,
  so upgrading a 2.0.8 database no longer fails with `UNKNOWN_IDENTIFIER`.
- The ClickHouse upgrade qualification starts from the previous release's own schema.

## [2.1.0] - 2026-11-14

Adds notifications, log push, an incident profile, and a typed rule engine with rate limits, managed-WAF tuning,
platform rulesets, leaked-password, sensitive-data, AI-prompt, API-schema, and client-side protections. Domain users get a
traffic dashboard and administrators can log in as a domain user. See the
[release notes](docs/operations/releases/v2.1.0.md).

### Added

- Notifications: one `notifier` container delivers alerts and domain events to Telegram, Discord, Slack, and an
  administrator-only signed webhook. Administrators and, when a platform switch allows it, domain users add their own
  channels, with per-channel rate limits, digests, and automatic pausing after repeated failures.
- Log push: when an administrator allows it, a domain user sends the HTTP requests, security events, and DNS queries of
  their own domains to an HTTPS endpoint or S3-compatible storage. One shared `log-pusher` worker reads each
  destination's own rows from ClickHouse by cursor; nothing runs per domain, and serving and central telemetry are
  unaffected.
- Incident profile: a domain user turns on one time-limited bundle of protections for their own domain (tighter limits,
  firewall blocking, restricted methods and body size, a browser challenge). It expires on its own, ends early in one click,
  travels as a single overlay with no configuration revision, and is audited. The cell enforces every protection, including a
  proof-of-work challenge, and ends the profile by its own clock.
- A private Alertmanager receiver in the control plane. It stores nothing and queues one message per alert group.
- Typed custom security rules: conditions on request fields, five actions, preview and reorder endpoints, presets
  (user agent, hotlink), and per-cell fixed-window rate limits.
- Managed WAF scoring: an attack score on every request event, attack-score rule fields, a catalog of adjustable managed
  rules, and per-domain adjustments applied in the cell.
- Platform rulesets that administrators apply to all domains or a chosen list; they run first and carry their own reasons.
- Security events and analytics: mitigated requests with the rule behind each, a Security events tab with create rule and
  create exception, security analytics, and an alert when mitigated requests reach a domain's threshold.
- Leaked-password check, sensitive-data logging, AI-prompt scoring, API schema checks, and report-only CSP with
  client-side script and connection reports, each stored per domain and edited on the domain page.
- Cache path rules and cookie name prefixes in the domain cache policy, with analytics cookie presets.
- Domain users see traffic, what needs action, and every domain on the dashboard. Administrators can log in as a domain
  user; the session is marked, audited, and cannot create tokens or change passwords.
- Fleet web forms show an example in every input; a browser crawl enforces it.
- Grafana: attack-score, reason, client-report, and custom-rule panels, alert deliveries on the control plane
  dashboard, and fixed ranges for hourly and daily series so no panel is empty on a short range.

### Changed

- Notification channels replace the single alert webhook; see Removed.

### Removed

- `ALERT_WEBHOOK_URL`, the Fleet `alert-webhook` command, and the Fleet web alert webhook field. Add notification
  channels in the panel instead.

### Fixed

- A signed-in administrator who opens `/app` is sent to `/admin` instead of a 403 page.
- The Grafana browser suite scrolls and draws every panel before it checks one, so panels below the first screen are
  no longer skipped.

### Security

- Laravel framework 13.42 clears the task output injection advisory.

## [2.0.8] - 2026-11-12

Locks the control surfaces down by default, separates automatic security state from configuration revisions, shows
download and upload volume, and adds history and liveness detail to the domain and edge screens. See the
[release notes](docs/operations/releases/v2.0.8.md).

### Added

- A default-deny host firewall in front of every internal listener, and management networks that limit who reaches the
  admin panel and admin API (added and removed one entry at a time). The customer panel, customer API, and status page
  stay open.
- A strict Content-Security-Policy; wildcard CORS is refused.
- Automatic security state is delivered to cells as an overlay instead of a configuration revision. Each cell enforces the
  stricter of the snapshot and the overlay.
- A domain History tab of operator and automatic events, and a one-line summary on each configuration revision.
- Download and upload volume by edge, cell, domain, and country, in the API and the usage dashboard.
- Edge screens show when control last heard from an edge and whether it is serving, as two separate facts.
- Gateway handshake failures are reported to telemetry by reason, and ModSecurity verdicts and the raw request path are
  logged.
- Fleet doctor and the panel health page report alerts that have no receiver as a failed check, and a control install
  takes and verifies its first backup before it reports ready.
- Analytics for all of a user's domains, with range and domain filters.

### Changed

- Requests that carry a cookie bypass the cache unless the cookie name is ignored, and repeated query parameters keep the
  client's order in the cache key.
- Security events keep one record per domain, edge, reason, and hour; old events and operations are pruned.

### Security

- The core image is rebuilt on PHP 8.5.12 and ships `docker/php/cdnfoundry.ini`: `expose_php`, `display_errors`, and
  `session.upload_progress.enabled` are off, so no response carries `X-Powered-By` or an inline PHP error. Errors go to
  the container log.
- Public listeners no longer name nginx or PowerDNS versions, every core process runs as `www-data`, and the default
  avatar is drawn locally instead of calling a third-party service.
- The OpenResty and production nginx images upgrade `libxslt` to 1.1.43-r4, and the Go agents and gateway build on
  Go 1.26.11.

### Fixed

- A ClientHello split across TLS records is reassembled, and gateway refusals are counted by reason.
- Edge liveness is recorded before advisory heartbeat entries are checked, so an invalid entry no longer rejects a
  heartbeat.
- A published endpoint stays in DNS while its gateway takes a new revision.
- A domain's own protections no longer count toward an automatic security state change.
- Loki labels keep to a fixed set, and the admin overview no longer overflows phones.

## [2.0.7] - 2026-11-10

Hides edge diagnostic headers by default, lets administrators acknowledge reviewed failures, fixes managed WAF on a
service pool, and makes the admin overview and telemetry drill-downs show real detail. See the
[release notes](docs/operations/releases/v2.0.7.md).

### Added

- Platform setting `proxy_defaults.diagnostic_response_headers` (off by default) controls the `Server` and `X-CDNF-*`
  response headers on proxied responses. The `Server` header no longer carries a version.
- Acknowledge a failed operation (Operations page, bulk action, and `POST /api/admin/operations/{operation}/acknowledge`)
  so a reviewed failure stops raising Degraded. A later success of the same operation type for the same domain also
  resolves it.
- Detail tables behind every dashboard drill-down on Traffic and telemetry (traffic, cache, origin, DNS).
- The admin Overview now also shows a Platform inventory (domains, proxied hostnames, certificates, ready cells, WAF
  coverage, release, nameservers), the traffic and error charts, edge health, cache, DNS, queue lanes, and data freshness.

### Changed

- Timestamps no longer print the timezone offset or name; the platform setting defines the zone.

### Fixed

- The Global traffic and Global DNS tables fit their panel with fixed columns instead of scrolling sideways.
- Admin dashboard charts and totals stopped at the last complete hour, so they trailed real time by up to an hour
  (more with a half-hour timezone). The hour in progress is now charted and labelled "so far".
- The failed-operations tile and `cdnfoundry_operations_failed_recent` counted failures that were already recovered
  or acknowledged.
- Enabling managed WAF on a service pool failed with "The waf runtime version field is required"; the release is now
  filled from the pinned image when the toggle is switched on.

## [2.0.6] - 2026-11-10

Makes Geo-DNS and the Grafana dashboards work on a fresh fleet, and adds automatic nameserver checks, registration
expiry, and a delete action for domains. See the [release notes](docs/operations/releases/v2.0.6.md).

### Fixed

- Geo-DNS always answered the default: production required a verified GeoIP database but Fleet configured no
  checksum, so nodes kept an empty placeholder. The default provider `dbip-npm` verifies the registry's SHA-512.
- Vector exported the `cdnf` metrics as `_cdnf_...`, so the recording rules and most DNS and edge dashboard panels
  were empty. The exporter no longer sets an empty namespace.
- The PoP logs panel failed for All PoPs because its LogQL selector matched only empty values.
- The edge gateway closed an unrouted plain-HTTP connection without an answer, so synthetic probes always failed. It
  now answers `421`.
- The blackbox DNS probe (`probe.cdnfoundry.invalid`) was counted as customer traffic, inflating DNS query and
  REFUSED figures by about half. Vector now drops it from DNS telemetry.
- Solid danger buttons failed contrast in light mode.

### Added

- Delete domain in the domain page menu.
- Scheduled nameserver checks for pending and active domains, with un-verify after three confirmed changes.
- Registration expiry in both panels and the API, with a warning window and reminders.
- A Domain monitoring settings group.

## [2.0.5] - 2026-11-09

Makes a fresh Fleet install complete by itself and fixes the zone transfer, the pair names, and the missing logs
that kept a new fleet from answering DNS. See the [release notes](docs/operations/releases/v2.0.5.md).

### Fixed

- A PowerDNS node could never pull zones from the primary: it sat only on an internal network, so every transfer
  failed with `Network is unreachable`. It now also joins the egress network.
- A customer nameserver pair name typed with capital letters failed the PowerDNS read-back and rolled the whole
  platform DNS identity back. Pair names are now lowercased.
- Central logs stayed off after setup, so Loki and the log collectors were never deployed and Grafana showed no
  logs. The monitoring presets now turn them on.
- The Fleet gate checked ports 80 and 443 on loopback and hung for minutes on a PoP with no placed pool address.
  It now checks the public address once the gateway listens, and prints the reason after each failed attempt.

### Added

- Fleet registers the private PowerDNS primary as a DNS cluster and stores the platform DNS identity after it
  installs the control node. Neither replaces something already set.
- A failed installation in Fleet web offers a Retry button that resumes at the first step not done.
- The DNS identity review lists every customer nameserver pair.

### Removed

- The scheduled off-host restore drill workflow. `make dev-recovery-e2e` still runs it by hand.

## [2.0.4] - 2026-11-09

Fixes a fleet that could not enrol its edges when the Fleet-generated edge identity CA had no
subject key identifier, and makes a failed host start show its cause. See the
[release notes](docs/operations/releases/v2.0.4.md).

### Fixed

- Edge enrolment failed with `Unable to sign the edge identity certificate` when the edge identity CA
  certificate had no Subject Key Identifier. Core now signs with such a CA, and Fleet adds the identifier
  to every CA it generates.
- A signing failure now says why (unreadable CA key, key and certificate that do not match, or the
  OpenSSL error) instead of a bare message.

### Added

- When a host fails to start, the Fleet action shows the last log lines of each failing container.

## [2.0.3] - 2026-11-09

Fleet fixes found by running every CLI command and web page against real hosts, a Fleet web
Config page for monitoring, logs, and backups, and a step-by-step HA guide. See the
[release notes](docs/operations/releases/v2.0.3.md).

### Added

- Fleet web: monitoring, central logs, and backups can be set on the Config page, and the HA form takes
  an SSH user.
- Documentation: [Set up control-plane HA step by step](docs/operations/set-up-control-plane-ha.md) for a
  local network or WireGuard, and the requirements of a dedicated monitoring host.

### Fixed

- `apply-config` compared a render with the previous render and missed saved changes. It compares with the
  bundle each host last ran.
- `control add-standby` failed with `KeyError`, and a standby added without image pins could not be
  promoted.
- ClickHouse could not finish its first start because it lacked the `KILL` capability.
- A failed image pull crashed Fleet with a traceback and hid the reason.
- A finished one-shot container marked a host degraded.
- A monitoring-role node with colocated monitoring rendered a broken bundle.
- Fleet web showed raw Python errors, and form fields overflowed a phone screen.
- The manual Compose guide ran `pull` without a profile, which pulls nothing.

## [2.0.2] - 2026-11-08

Fleet shows what it is doing, installs hosts in parallel, and no longer hangs at
ClickHouse migration. One behavior change outside Fleet: the Alertmanager webhook is
now optional. See the [release notes](docs/operations/releases/v2.0.2.md).

### Added

- Fleet web action page: every step of an action is listed up front, grouped by host,
  and ticked as it finishes, with a progress bar, a live log that follows the end, a
  log filter and download, and **Stop after this step**. A running or finished action
  is announced on every page, and an interrupted install offers **Continue**.
- Fleet inventory: processor, memory, disks, kernel, Docker, time sync, network
  interfaces, and containers of every host, with findings, on a new Inventory page and
  with `cdnfoundry-fleet inventory`.
- Fleet live view: online, degraded, or unreachable state and memory and disk gauges
  on Overview, Nodes, and Inventory, refreshed by a read-only probe, a linked
  topology, a recent-activity feed, container log tails, and `cdnfoundry-fleet watch`.
- Fleet CLI: a step progress bar on a terminal, and a first Ctrl+C that stops before
  the next step.
- Fleet streams host output into the action log while a host starts its services, and
  reports a step that has been quiet for 30 seconds.
- `cdnfoundry-fleet alert-webhook`, and an alert webhook field at setup, on Deploy,
  and on Config.

### Changed

- Fleet connects to, prepares, and pulls images on every host at the same time before
  it installs the first one (up to 6 hosts and 4 images per host at once).
- The Alertmanager webhook (`ALERT_WEBHOOK_URL`) is optional. With no value,
  Alertmanager starts with receivers that send nothing; alerts still fire and show in
  Prometheus. A value that is not an `https://` URL is still refused.

### Fixed

- A Fleet install hung at ClickHouse migration: host commands inherited the operator's
  terminal as standard input, and `clickhouse-client` waits for extra rows on it.
- Pinning several hosts at once could drop host keys from the known-hosts file.

## [2.0.1] - 2026-11-08

Fleet is easier to run. No change to the control plane, DNS, or edge runtime.
See the [release notes](docs/operations/releases/v2.0.1.md).

### Changed

- Fleet builds and signs the release itself: it reads the release your checkout is
  on, looks up the published image digests, and signs the manifest with a key kept
  in the encrypted vault. `set-release` and `upgrade` no longer need `--manifest`.
  Operators who sign releases themselves still pin their key and pass a manifest.
- Fleet web has a new layout on the CDNFoundry theme: grouped navigation, a page
  header with a one-line description, and bordered tables and forms.
- `scripts/fleet-web.py` restarts under Python 3.13 to 3.10 when the default Python
  is newer than the tested range, and installs PyYAML without a prebuilt wheel.

### Fixed

- A first install uses the release chosen at setup, not the tag of the checkout.
- The release lookup verifies the registry with the certifi bundle, so it works on
  Python builds without system root certificates (python.org builds on macOS).
- The Backups page no longer scrolls sideways on a phone.
- The Fleet browser suite no longer fails when its projects share fixture state.

## [2.0.0] - prepared 2026-11-05, not yet tagged

v2.0.0 is a breaking release with no upgrade from 1.x. See the
[release notes](docs/operations/releases/v2.0.0.md).

### Breaking changes

- No upgrade path or data migration from 1.x. Rebuild from scratch.
- Migrations are squashed into a PostgreSQL and a SQLite baseline.
- The edge agent requires `EDGE_RUNTIME_DIR` and has one activation path. Agents
  accept artifacts for agent versions 2.0.0 to 2.99.0.
- The platform zone goes to the hidden DNS primary, and
  `platform_dns_settings.cluster_targets` is removed.
- `EDGE_PROXY_SECRET` and `METRICS_TOKEN` are no longer read from the
  environment; use `EDGE_PROXY_SECRET_FILE` and `METRICS_TOKEN_FILE`.
- Every documented tunable is passed to the control plane through `.env.prod`.

### Added

- Documentation rebuilt around tasks: six sections, a Fleet web guide, a Grafana
  guide by team, panel tours, installation options, incident response, health
  probes, reports, a glossary, a FAQ, and generated references for the Fleet
  CLI, Grafana dashboards, and alerts.
- Generated documentation screenshots, a design-token match with the product
  theme, executable documentation blocks, and `llms.txt`.

- Phase 6 (optional control-plane high availability): an optional warm standby
  control node with `cdnfoundry-fleet control add-standby`, `switchover`,
  `demote`, `promote`, `rejoin`, `remove-standby`, `status`, and `check`; a
  monotonic `control_epoch` that fences a returning old node (start guard,
  scheduler gate, `503 standby` from edge-control); edge agents that dial an
  ordered list of control addresses from signed configuration and ignore a
  lower epoch; DNS nodes that follow both control nodes as primaries; HA
  alerts, a **Standby node** health check, and `ha: none` as a warning in the
  qualification report; ClickHouse usage rollups in the control backup. A
  single node stays supported. There is no floating address and no automatic
  failover. See [Control-plane high availability](docs/operations/control-plane-high-availability.md).
- Phase 2 (control-plane faults): PoPs leave DNS only on a positive signal and
  pools keep their last published set; PoPs probe each other's listeners; edge
  identity certificates renew over mTLS; the agent rejects a bad artifact for
  one domain only; placements promote by quorum; Alertmanager sends to a
  required webhook with an always-firing `Watchdog`; every long-running
  service has a health check; origins see the real client address. See the
  [roadmap](docs/roadmap.md) completion gate.
- Coding-agent tooling: rewritten project skills with front matter, real file
  paths, commands, tests, and known traps, plus a new `cdnf-codebase-map`
  skill; `.claude/skills` links to `.agents/skills`; `.mcp.json` registers the
  local CodeGraph MCP server (pinned 1.6.2, telemetry off). See
  `docs/development/coding-agents.md`.

### Fixed

- Signing in at `/admin/login` or `/app/login` no longer fails with "The password field
  is required" on a development stack whose published Filament assets were older than the
  installed package: the password field's script returned `404`, which broke the form. The
  development entrypoint now republishes the assets when it starts.
- A new control node's first start no longer fails when its databases report healthy
  before they accept TCP connections (the migration and PowerDNS runtime step wait).
- Fleet control bundles now contain the `pdns-migrate` tool their `start.sh`
  runs; a generated control node could not previously finish its first start.

### Changed

- The documentation site footer and the archived README and CONTRIBUTING
  pages state the Apache License 2.0; CONTRIBUTING records that contributions
  are licensed under the same terms.
- `docs/roadmap.md` is now the active platform rebuild roadmap: audit finding
  register from a code audit, and risk-ordered phases toward a breaking 2.0.
- `docs/roadmap.md` merges the 2026-10-30 codebase audit
  (`docs/audit/2026-10-30-codebase-audit.md`): findings A1–A46 join the
  register, every A item is assigned to a phase with updated exit criteria,
  a "Before Phase 1" list holds date-bound and disclosure work, stale code
  references are corrected, and M11 moves to Phase 2.

## [1.2.1]

v1.2.1 is a zero-state release: deploy it from scratch. There is no supported
upgrade path from 0.x. Entries below cover everything since `v0.9.9`.

### Added

- Root `VERSION` file with `make version-check` (CI-enforced) and
  `make check-no-screenshots`.
- Control-plane and DNS-API host protection: these hosts are rejected as proxied origins.
- Safe public service status snapshots and a rendered public health page.
- Verification of authoritative ACME challenges with PowerDNS readback; probes of
  authoritative DNS and verified edge serving paths.
- Expiry and replay rejection for edge artifact deliveries.
- Bounded telemetry buffers that prioritize security events, bounded edge shared
  memory and cache-refresh admission, single-flight cache refresh.
- Edge cells keep serving when the Vector DNS name is unavailable.
- `NOTICE` file and Apache-2.0 license metadata.
- `SUPPORT.md`, supported-version table in `SECURITY.md`.

### Changed

- The project is licensed under Apache-2.0.
- Edge headers are renamed to the `X-CDNF` prefix across runtime and Fleet.
- Edge private keys stay in memory with encrypted recovery state.
- Upstream software runs from pinned upstream images only; Alpine package pins are
  minimum-version floors; Go services are built with Go 1.26.10.
- API error envelope and proxy scheme aligned; fleet dry run and start activation hardened.
- Edge agent, edge gateway, fleet generator, API contract and release manifest
  all report the single project version.
- The staged roadmap is finished and removed from the tree.

### Fixed

- Domain-user panel returns 403 for a revoked domain in open Livewire requests.
- Origin peer checks and cache refresh bounds hardened.
- Edge referrer queries are redacted before fallback logging.
- Vector startup bounds and buffer sizes use supported minimums.
- Legacy DNS settings stay readable in persistent development state.
- CI supply-chain, topology and E2E gates; image revision verification;
  signing the GHCR digest; accepting an already-recorded transparency entry.

### Security

- Dependency advisories cleared for PHP, Node and Go toolchains; Alpine and BIND
  package floors raised for HIGH/CRITICAL advisories.
- Expired and replayed edge artifacts are rejected.

### Removed

- Staging-era handoff, audit and per-phase evidence reports.

### Documentation

- Current docs aligned to the project; stale and unrelated pages removed.
- Release notes: [v1.2.1](docs/operations/releases/v1.2.1.md).

## Earlier releases

`v0.4.0` to `v0.9.9` are published as GitHub releases.
