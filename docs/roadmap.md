---
title: Roadmap
description: Evidence-based roadmap from the 2026-11-11 audit of the dev branch and the staging fleet. Existing defects come first, new capabilities after.
---

# Roadmap

**Status: active.** This roadmap replaces the "Roadmap to 2.0" phase list. It
comes from one audit, run on 2026-11-11 (UTC), of `dev` at `dad6250`
(release 2.0.7 plus seven unreleased commits) and of the staging fleet.
Nothing was fixed during the audit. Work happens on `dev`, one phase at a
time, in the order below.

Every item carries one evidence label:

| Label | Meaning |
| --- | --- |
| **Reproduced** | Triggered on purpose during the audit, on staging or locally. |
| **Confirmed** | Observed directly on staging (state, logs, responses) without triggering it. |
| **Code-confirmed** | Read end to end in the current code; not exercised at runtime. |
| **Reported, not reproduced** | Reported by the owner or a scanner; the audit could not reproduce it. |
| **Blocked** | Not tested; the reason is stated. |
| **False positive** | Checked and found not to be a defect. |

No secret, cookie value, token, key, or customer content is recorded here.

## Audit basis

### What was tested

| Area | How | Result |
| --- | --- | --- |
| Checkout | `git status`, history, `VERSION`, CI | Clean tree on `dev`. Release 2.0.7. Latest CI on `main` and tag `v2.0.7`: success (2026-11-10). The seven commits after 2.0.7 have no CI run on `dev`. |
| Laravel suite | `make dev-test` (SQLite in memory) | Exit 2. 595 passed, 4 failed. Two failures are real UI assertions on the analytics pages changed after 2.0.7 (`AnalyticsApiTest`, `OpsDashboardTest`). Two fail because the local test container does not mount the repository root (`ReportsTest`, `RepositoryContractTest`). |
| Go | `go test ./...` in `golang:1.26.10-alpine` | `edge-gateway` ok, `edge-agent` ok. |
| Contract and observability | `pytest -q tests/contract tests/observability` | 61 passed. |
| Fleet tests | `pytest -q tests/fleet` | 330 passed (about 10 minutes). |
| Staging hosts | Read-only SSH, `docker ps`, `ss`, firewall rules | See topology below. |
| Staging data | Read-only SQL on the control database, read-only ClickHouse, Prometheus and Loki queries | Used throughout. |
| Public surface | `curl`, `openssl`, TCP connects from an outside workstation; `dig` from a clean host in the PoP network | The workstation's port 53 is intercepted by its own network, so every direct DNS answer in this audit comes from the clean host. |
| Cache | 12 marked GET requests to the staging test domain through one PoP, read back from the edge log | Items 2.1 and 2.2. |
| TLS ClientHello | 6 single connections per PoP with a hand-built ClientHello | Item 3.4. |
| Load | Keep-alive ramp 1, 5, 10, 25, 50 connections, 12 s each, about 1 request per second per connection, second PoP as comparison | Item 3.5. |

### Staging as it actually runs

One control host (control, DNS primary, telemetry, monitoring) and two
combined DNS and edge hosts, each a small VM with 4 vCPU and about 4 GiB
RAM. Addresses, hostnames, and customer names are deliberately left out of
this document.

Facts that change how results must be read:

- The staging inventory file is stale: one listed PoP is an empty host and
  one host listed as a spare is a live PoP.
- Both PoPs are in the same network. This is two edge hosts, not two
  failure domains. No multi-PoP conclusion can be drawn from this fleet.
- No IPv6 anywhere. Every IPv6 behavior is **blocked** on staging.
- No host firewall on any host, so every published container listener was
  reachable from the internet.
- Staging has one real domain, one proxied hostname, and 16 cells.
- The host name given in the report for the DNS and edge listeners does not
  exist in the fleet definition, the control database, or public DNS. The
  reported listeners were found on both PoPs.

### Checked and not defects

| Report | Verdict |
| --- | --- |
| Cookie "No HttpOnly" on `XSRF-TOKEN` | **False positive.** The cookie is Laravel's CSRF token and must be readable by the page script. It is `Secure; SameSite=Lax`. The session cookie is `Secure; HttpOnly; SameSite=Lax`. |
| "Timestamp disclosure" on `/js/filament/support/support.js` | **False positive.** Ordinary `Last-Modified` and `ETag` of a static asset (image build time). |
| A scanner label that guessed a printer service for one listener | **False positive label.** It is the host metrics exporter. The exposure itself is real (item 1.2). |
| `/api/health?format=json` sets session cookies | **Reported, not reproduced.** The audit saw no `Set-Cookie` on `/api/health`, `/api/ready`, or `/up`. HTML routes (`/`, `/horizon`) do set them. |
| Stack traces on error pages | **Not reproduced.** `APP_DEBUG=false`; 404, 405, 403 and malformed requests return generic pages or stable JSON codes. Related hardening gaps are in item 1.4. |
| "CSP wildcard directive" and "CSP script-src unsafe-inline" | **Same single finding** as the missing policy (item 1.5), not three defects. The header is only `upgrade-insecure-requests`. |
| Clients in Asia served by the PoP labelled EU | **Accepted limitation.** Geo-DNS answered correctly per client subnet in the audit (subnets from the region of one PoP got that PoP; subnets from three other regions got the other). The decision uses the resolver's address unless the resolver forwards the client subnet, so a client behind a public resolver that hides it gets the resolver's region. |
| `/.env`, `/.git/config`, `/telescope`, `/_ignition`, `/metrics`, `/storage/logs` | Not exposed (403 or 404). |
| TLS on control and edge | TLS 1.2 and 1.3 only, valid Let's Encrypt certificates, unknown SNI is closed, unknown plain-HTTP host gets `421`. |
| DNS basics | Delegation and glue correct, AXFR refused, out-of-zone queries `REFUSED`, UDP and TCP answer. |

## Phase gate

A phase is complete only when all five fields pass and are recorded
separately in its release notes:

| Field | Required evidence |
| --- | --- |
| Implementation | Merged to `dev` in small commits. Replaced code is deleted. |
| Regression tests | Each defect has a test that fails before the fix and passes after it. |
| Runtime qualification | Python E2E under `tests/e2e` on a real Compose stack, and the phase's staging check below re-run by an agent. |
| Browser suite | `tests/browser` passes for every changed screen, with visual snapshots and accessibility checks. |
| Documentation | Every affected page states the new behavior. |

Before Phase 1 starts, fix the two analytics assertions and the local test
mount so `make dev-test` exits 0 on `dev`, and correct the staging inventory file.
Neither is phase work.

---

## Part 1: Existing problems

## Phase 1: Close the management and infrastructure exposure

The control host and both PoPs published internal services to the internet,
and the control plane ran a PHP release with published security fixes
outstanding. This came first because it was exploitable from outside without
any account. Port numbers, product versions, addresses, and hostnames are left
out of this phase on purpose; the operator pages name every listener.

| # | Finding | Evidence | Direction | Proof of completion |
| --- | --- | --- | --- | --- |
| 1.1 | **PHP was behind a published security release and advertised its version.** | **Confirmed.** The deployed core image pinned an older patch release than the newest security release. The running FPM had `expose_php`, `display_errors`, and `session.upload_progress.enabled` on, and every PHP response carried `X-Powered-By`. The audit verified the version gap, not exploitability. | Re-pin the base image to the newest patch release. Ship a production `php.ini` with the three settings off. | The deployed image reports the fixed release; no response on any path carries `X-Powered-By`; CI is green on the new digest. |
| 1.2 | **Metrics and admin listeners were open to the internet.** | **Reproduced** from outside. See the listener inventory below. | Bind exporters to the private or loopback address by default in Fleet's render, and have Fleet install a default-deny host firewall in `DOCKER-USER` that admits each internal listener only from the fleet's own addresses. No new service. | An outside scan of every fleet host shows only SSH, DNS, HTTP, HTTPS, and the edge-control listener; Prometheus targets stay `up`. |
| 1.3 | **The panels, the API, and Grafana were reachable by anyone.** | **Reproduced.** The panels answered with redirects to public login pages. API paths answered `401` from anywhere. Grafana's login was public on its own hostname, and its health endpoint (version and commit) and metrics answered without authentication. | Add a management-network allowlist for the panels, the API, Horizon, and Grafana at the Caddy layer and the same list in the application. Keep public only the health probes and the edge-control listener. Decide explicitly about the public status page. | From outside the allowlist, every management path returns `403` with no redirect, no login form, and no session cookie. From inside: unchanged. |
| 1.4 | **Version and state leaks on error paths.** | **Reproduced.** A malformed request returned an nginx page naming its version, the edge-control listener sent a versioned `Server` header, and `version.bind` on both nameservers returned the exact PowerDNS build. The control log showed `headers already sent` errors, consistent with `display_errors` being on. | `server_tokens off` on the web and edge-control images, a fixed `version-string` for PowerDNS, and the `php.ini` from 1.1. | No response from any public listener names a product version. |
| 1.5 | **No real Content-Security-Policy; wildcard CORS on the whole API.** | **Reproduced.** The policy was `upgrade-insecure-requests` only. Every `/api` response sent `Access-Control-Allow-Origin: *`, and a preflight from an arbitrary origin was granted `Authorization`. The API uses bearer tokens and sends no `Allow-Credentials`, so this was not a session-riding hole, but nothing needed it. HSTS had no `includeSubDomains`. | A panel policy Filament can run under. Remove wildcard CORS; allow only configured origins. | The policy has `default-src` and `script-src` without wildcards; a cross-origin preflight is refused; the browser suite passes with the policy enforced. |
| 1.6 | **Hardening left from the previous roadmap.** The core container's default user was root. | **Confirmed.** | Run every core-image process as an unprivileged user. | No process in a core-image container runs as root. |

Listener inventory (every one was reached from outside and confirmed on the
host; every one was published on all interfaces with no firewall in front):

| Listener | Host | Authentication | Risk |
| --- | --- | --- | --- |
| DNSdist statistics | Both PoPs | The admin routes needed credentials; the statistics routes answered without them. | Query and backend statistics to anyone. |
| Host metrics exporter | Both PoPs and control | None | Kernel, disk, network, and process detail of every host. |
| Edge-control mutual TLS | Control | A client certificate for every route except its health route. | Intended to be reachable by PoPs. Not a defect. |
| Edge gateway metrics | Both PoPs | None | Connection counters and active revision. |
| Vector exporters | All three hosts | None | Pipeline internals. No customer hostnames were found. |
| PowerDNS primary (zone transfers) | Control | Zone transfers refused from outside; ordinary queries answered. | The "private" primary was public, against the contract. |
| Telemetry ingest | Control | Refused from outside by an address allowlist. | Working as designed. |

**Blocked:** default-credential checks. The audit made no login attempts.
Generated secrets are present in the fleet state (confirmed by file name
only); the first-administrator path should be re-checked with the owner
present.

### What changed

| # | Change | Where |
| --- | --- | --- |
| 1.1 | Base image re-pinned to the newest patch release by digest. `cdnfoundry.ini` turns the three settings off. | `core/Dockerfile`, `core/docker/php/` |
| 1.2 | `firewall.sh` is generated per node and run by `start.sh` before any container. It judges Docker-published listeners in `DOCKER-USER` and the host-network gateway metrics in `INPUT`, admits only fleet addresses, and returns after a reboot through a systemd unit. | `scripts/cdnfoundry_fleet/firewall.py` |
| 1.3 | `management_networks` in the fleet state (several CIDRs, no catch-all, add or remove one entry at a time from the CLI or the Config page). Caddy and the application refuse everything else with a bare `403`. The status page moved behind the list; health probes and the edge listener stay open. | `deploy/production/Caddyfile*`, `RestrictToManagementNetworks`, Fleet |
| 1.4 | `server_tokens off`, PowerDNS `version-string`, PHP settings. | `docker/nginx/`, `docker/pdns/` |
| 1.5 | One policy with a per-request nonce, enforced by default (`report-only` and `off` selectable). API CORS allows only `CORS_ALLOWED_ORIGINS`. HSTS covers subdomains. The default avatar is drawn locally, because Filament's stock provider sent each user's initials to a third-party service on every page. | `SendContentSecurityPolicy`, `config/cors.php`, `InitialsAvatarProvider` |
| 1.6 | The entrypoint drops root for every command, including the FPM master, which logs through a named pipe copied to stderr. | `core/docker/php/entrypoint.sh` |

**Gate:** an outside scan and path sweep, run from a host outside the
allowlist and stored with the release, shows no management path, no metrics
endpoint, no version string, and a patched PHP. See the completion record
below for what has and has not been run.

## Phase 2: Cache isolation and key correctness

| # | Finding | Evidence | Direction | Proof of completion |
| --- | --- | --- | --- | --- |
| 2.1 | **A response to one visitor's cookie is served to another visitor.** Treat as critical. | **Reproduced on staging (mechanism).** Three requests to one URL with `Cookie: session=<A>`, then one with `Cookie: session=<B>`: the edge log records `MISS, MISS, HIT, HIT`. The second user was served the object stored for the first. The test object was a static page, so no private data moved; an origin that personalizes by cookie without `Set-Cookie` or `private` would leak. **Code-confirmed** cause: cache is on by default (`edge_ttl_seconds: 3600`, HTML included), `bypass_cookie_names` defaults to empty, and `cookie_bypassed()` only reacts to listed names. The edge then rewrites the response to `Cache-Control: public, s-maxage=3600`, so downstream caches may store it too. The two-request admission rule delays the leak by one request; it does not prevent it. Working today: `Authorization` bypasses (**reproduced**, `BYPASS`); `Set-Cookie`, `private`, `no-store`, `no-cache`, and any `Vary` other than `Accept-Encoding` prevent storage (code-confirmed). | Safe default: a request that carries any `Cookie` header bypasses the cache, unless the domain lists cookie names to ignore. Never add `public` to a response the origin did not mark cacheable when the request carried a cookie. Keep the existing response-side rules. | New E2E with an origin that echoes the cookie value: two isolated sessions, 1,000 alternating requests, zero responses containing the other session's value, on HTTP and HTTPS. Re-run the staging sequence: the fourth request is `BYPASS`. |
| 2.2 | **Requests that differ only in the order of a repeated parameter share one cache entry.** | **Reproduced on staging.** `?item=first&item=second` three times, then `?item=second&item=first`: `MISS, MISS, HIT, HIT`. **Code-confirmed:** `cache_key.lua` and `CacheKey.php` both sort pairs by name, then value. The origin receives the original order. PHP and Lua agree (shared vectors pass: `pytest tests/contract`, `CacheKeyVectorsTest`), so purge parity holds; the fixture has no repeated-name vector. | Keep sorting by name, but make the sort stable so repeated names keep their original relative order. Purge stays in parity because both sides share the rule. This keeps the hit-ratio benefit for independent parameters and removes the collision. Bypassing all duplicate-key requests is the fallback if a stable sort cannot be kept identical in both languages. | New vectors for repeated names in `cache_key_vectors.json`, passing in PHP and Lua. E2E against an order-sensitive origin shows two distinct objects, and a purge of one URL does not remove the other. |
| 2.3 | **The request log cannot tell cache variants apart.** `path` is the decoded `$uri` without the query. | **Reproduced.** `/web/index.html%3Fx=…` (a `404` from origin) is logged as `/web/index.html`. The cache key itself keeps the encoding (old findings H5 and A5 are fixed), so this is a visibility defect, not a poisoning one. | Log the raw path as sent, and a bounded hash of the query. | A test request with an encoded `?` is findable by its raw path. |
| 2.4 | **Not exercised:** purge propagation after restart, full-purge epochs, stale serving, cache lock under load, storage limits, restart persistence. | **Blocked on staging** (purge and restart change cache contents and running services). Covered by `edge_purge_protocol.py` and `openresty_runtime.py`, which the audit did not run. | Run both E2E files as part of this phase's gate; add the cookie and order cases to them. | Both files pass and are listed in the gate record. |

**Gate:** zero cross-session responses in the two-session test; distinct
objects for reordered repeated parameters; PHP, Lua, and purge keys equal on
the extended vectors.

## Phase 3: Edge health, heartbeat, and gateway stability

The reported "edge went down under about 50 connections and stayed Stale"
is real, but the cause is not capacity. The audit found the edge still
serving and its heartbeat rejected by the control plane.

| # | Finding | Evidence | Direction | Proof of completion |
| --- | --- | --- | --- | --- |
| 3.1 | **One unknown reason code makes an edge Stale forever.** | **Confirmed live.** One edge had a heartbeat age of 2 h 28 min during the audit while serving traffic normally. Its agent logs `422 … noisy_domains.3.reason_code is invalid` on every sync (567 failed syncs since its last restart). The cell reports `malformed_request_body`; `SecurityConfig::REASON_CODES` does not contain it, and `EdgeAgentController::heartbeat` validates the whole payload before it records `last_heartbeat_at`. The counter lives in a shared dictionary with no expiry, so the rejection repeats until the cell restarts. Other reason strings in `runtime.lua` are also absent from the list (`waf_body_limit`, `cache_mode_origin_disabled`, `certificate_unavailable`, `too_many_query_arguments`); whether each can reach a heartbeat was not verified. Prometheus alert `EdgeHeartbeatStale` fired correctly at 19:49:59. | Record liveness first, from the authenticated request alone. Treat `noisy_domains` and `passive_origins` as advisory: drop an entry that fails validation, count the drop, never fail the heartbeat. Generate the reason list for PHP and Lua from one source and add a contract test. | E2E: an agent heartbeat carrying an unknown reason code is accepted, `last_heartbeat_at` advances, and the bad entry is counted. On staging the edge returns to healthy within one heartbeat interval of the fix, measured. |
| 3.2 | **A missing optional field returns `500` to the edge.** | **Confirmed live.** The other edge: 943 failed syncs; control log: `Undefined array key "hostname"` 550 times in 24 h. The cell's hostname entry expires after one hour, the counter does not, and the handler reads `$event['hostname']` unguarded. The heartbeat timestamp is written before the failure, so the edge looks healthy while placement promotion and everything after it in the handler never runs. | Same change as 3.1, plus expiry on the cell's event counters. | The same E2E with an event without `hostname` returns success. |
| 3.3 | **The UI and health page say "Stale" for an edge that is serving.** | **Confirmed.** Blackbox probes for that edge succeed, the gateway reports ready, the pool endpoint is still published in DNS (the fail-static fix from the old roadmap holds), yet the public `/health` page shows "Edge nodes: Degraded". The panel view was not opened (5.7). | Show two facts separately: "control last heard from the edge" and "the edge is serving" (from the existing probes). | Browser test: an edge with a stale heartbeat and passing probes shows both states. |
| 3.4 | **A ClientHello split across TLS records is dropped.** | **Reproduced on both PoPs.** One record in one, two, or sixteen TCP segments: handshake proceeds. The same ClientHello re-framed as two TLS records: connection reset or closed, in three variants. **Code-confirmed:** `readClientHello()` reads exactly one record. The gateway also accepts a declared record length up to 64 KiB, above the TLS maximum. Only OpenSSL 3.0.13 and curl were available; browsers and post-quantum key shares that produce large hellos are **blocked** (no such client on the audit host). | Reassemble handshake records until the ClientHello is complete, with the existing 15 s deadline and a size bound of 16 KiB per record and 64 KiB total. | Go unit tests for multi-record hellos; the six-case script passes on staging; a current browser and a client offering a hybrid post-quantum group connect. |
| 3.5 | **Fifty connections do not exhaust the edge.** | **Reported failure not reproduced as capacity.** Ramp to 50 keep-alive connections at about 45 requests per second: 1,046 requests, all `200`, p50 9–11 ms, p95 33–45 ms at every step, comparison PoP unaffected, no state change. During the real incident (19:40–20:10 UTC) the edge log shows single clients sending thousands of requests per ten minutes and being limited as designed (`client_connections_exceeded` 3,545 times), followed by the automatic state changes in Phase 4 and the heartbeat rejection in 3.1. | No capacity work here. The fix is 3.1, 3.2, and Phase 4. A burst test above the per-client limits belongs to Phase 9 with the limits documented. | After Phases 3 and 4: the same incident pattern replayed in E2E leaves the heartbeat healthy and creates no revision. |
| 3.6 | **The gateway does not say why it refused a connection.** | **Confirmed.** 38,232 and 32,673 rejected connections on the two PoPs against 2,956 and 266 accepted; one counter, and a summary log line without a reason. | Split the counter by a fixed reason set (slots, per-source, invalid hello, unknown route, handshake budget). | Each rejection path increments its own series in a Go test. |
| 3.7 | **Sustained connection churn.** | **Blocked.** Not run: it would exceed the per-source handshake budget on a fleet with live traffic. | Measure in Phase 9 on a dedicated host. | See Phase 9. |

**Gate:** a recovered or misreporting edge is shown healthy within a
measured bound after its next heartbeat; no single field in a heartbeat can
fail it; fragmented ClientHello cases pass.

## Phase 4: Revisions mean configuration changes

| # | Finding | Evidence | Direction | Proof of completion |
| --- | --- | --- | --- | --- |
| 4.1 | **Automatic security state changes create almost every revision.** | **Confirmed from staging data.** The staging test domain reached revision 1,388 in two days. The audit log holds 11 operator changes. Of 1,124 consecutive snapshot pairs, 1,027 differ only in `security` (state and effective limits); 6 differ in `waf` or `settings`. Heartbeats are the trigger, but not by themselves: the handler moves a domain to `suspected` at a cumulative count of 50 and `restricted` at 100, bumps `domains.revision`, and reconciles; `cdnf:security:reconcile-readiness` moves it back after ten quiet minutes and bumps again; the cell's counter never expires, so the next heartbeat repeats the cycle (684 `security.state_recovered` entries). | Take the automatic state out of the configuration revision. Deliver it to cells as a small operational overlay with its own sequence, the way purge epochs already travel. Use rate over a window, not a lifetime counter, with hysteresis. | E2E: one hour of heartbeats with unchanged desired state and with noisy events creates zero rows in `edge_revisions` and no change in `domains.revision`. |
| 4.2 | **A domain's own block rule counts against the domain.** | **Code-confirmed and confirmed.** A customer rule with action `block` rejects with reason `domain_restricted`; that reason feeds the same counter (13,859 security events with that reason). Blocking an attacker therefore pushes the domain toward `restricted`. | Count only reasons that indicate the domain is a problem for the platform, not the domain's own protections working. | E2E: 1,000 requests blocked by a customer rule leave `security_state` at `normal`. |
| 4.3 | **Every revision briefly withdraws the PoP from DNS and bumps the zone serial.** | **Confirmed.** 519 `edge.endpoint_withdrawn` (`gateway_not_ready`) and 522 `edge.endpoint_published` entries in two days, one pair per revision; 4,089 platform routing reconciles; the zone SOA serial is the revision (1,389). | An endpoint stays published while the gateway serves the previous valid revision; "not yet acknowledged" is not "not ready". 4.1 removes most of the churn; this removes the outage window from real changes. | E2E: a settings change on one domain produces no `endpoint_withdrawn` entry and no change in the pool's published address set. |
| 4.4 | **Automatic changes leave no trace an operator can read; manual ones are buried.** | **Confirmed.** Transitions to `suspected` and `restricted` from heartbeats write no audit entry. The revision list in the panel shows 1,388 entries with no author for most. | Keep `edge_revisions` for desired-configuration changes with an actor and a one-line summary. Record automatic state changes as operational events in the existing audit log. No new store. | Browser test: the domain history shows the 11 operator actions as revisions and the automatic changes as events, filterable. |
| 4.5 | **Unbounded growth.** | **Code-confirmed and confirmed.** `security_events` (17,016 rows in two days, one per reported reason per heartbeat) and `operations` (6,614 rows) have no prune job; only revisions, audit logs, idempotency keys, and backups do. | Aggregate security events per domain, reason, and hour; add bounded retention for both tables to the existing nightly prune. | A seeded year of activity for one domain stays under a stated row bound in a feature test. |

**Gate:** unchanged desired state creates no revision; a configuration
change creates exactly one; a domain running through an attack shows the
operator's actions and nothing else as revisions; table growth is bounded
by a test.

## Phase 5: Telemetry and dashboards that show the attack

What works, verified with live queries: all 26 Prometheus targets `up`; 145
rules healthy; request, status-class, cache-status, DNS rcode, latency, and
gateway series are live, not seeded; ClickHouse is fresh (newest edge row
about two minutes old, DNS seconds old); a marked test request was traced by
user agent to the correct domain, PoP, edge, status, cache result, TLS
version, and request ID; Loki holds logs from about 30 services on all hosts.

| # | Finding | Evidence | Direction | Proof of completion |
| --- | --- | --- | --- | --- |
| 5.1 | **WAF blocks are invisible.** | **Confirmed.** With the WAF profile set to blocking (20:03–20:15 UTC), 8,227 requests returned `403` and were logged as `security_action=allow`, `waf_action=inspect`, `waf_rule_id=0`, empty reason. No row in two days has a rule ID or `waf_action=block`. **Code-confirmed:** `runtime.lua` sets `waf_action` before ModSecurity decides and nothing updates it. This is why the attack "could not be found". | Set action, rule ID, and score from the ModSecurity outcome in the log phase. | E2E: a CRS-triggering request in blocking mode is logged with `waf_action=block` and a non-zero rule ID, and appears in the panel's security view. |
| 5.2 | **Edge events are lost in parsing.** | **Confirmed.** `decoded_edge_syslog` reports 529 and 139 `conversion_failed` events in two hours on the two PoPs (unintentional discards); `TelemetryEventsDropped` and `TelemetryDeliveryFailures` were firing. The control log collector reports 125 connection failures reading the Docker socket proxy. | Find the failing line shape (long path or user agent is the likely cause; unverified) and make the decoder tolerate it; alert on the unintentional series only. | A replay of the captured failing lines parses with zero failures; the drop alert is quiet at rest. |
| 5.3 | **Alerts go nowhere on this fleet.** | **Confirmed.** Alertmanager runs with the no-notifier config (no webhook URL). The stale heartbeat, the stale backup (critical), and the telemetry drops all fired with no recipient. | Fleet `doctor` and the panel health page report "alerts have no receiver" as a failed check; setup asks for a receiver. | Fleet test for the check; the existing watchdog E2E against a real receiver. |
| 5.4 | **Traffic volume is incomplete.** Required: bandwidth, download, upload per edge, cell, domain, country. | **Confirmed.** Prometheus has `cdnf_edge_bytes_sent_total` by edge, PoP, region only; no received-bytes series. ClickHouse rows carry `bytes_in` (request length from the client), `bytes_out` (response body), and `bytes_sent` (response including headers), with domain, country, edge, and PoP. There is no cell column and no origin-side byte count. | Direction of bytes, from the log source: download is `bytes_sent` to the client; upload is `request_length` from the client; origin-side traffic is separate and not measured today. Prometheus keeps edge, PoP, and region (add received bytes). Domain and country stay in ClickHouse rollups; add a cell column there. Do not add domain, country, or client labels to Prometheus. | Panel and Grafana queries for each of the eight combinations return values that match a counted test transfer within a stated tolerance. |
| 5.5 | **Fields that are always empty.** | **Confirmed.** `tls_error` is empty in every row and `tls_failures_hourly` has zero rows; `asn` is 0 in every row; failed handshakes at the gateway (3.6) never reach telemetry. | Feed gateway rejection reasons and cell handshake failures into the existing TLS failure rollup; ship or document the ASN database. | A failed handshake appears in the rollup within a measured delay. |
| 5.6 | **Loki labels carry identifiers.** | **Confirmed.** Stream labels include `request_id`, `operation_id`, `domain_id`, `revision_id`, `task_id`, with the literal string `<null>` when absent. | Keep labels to host, role, service, level, environment; move identifiers into the JSON body (they are already there). | Label list on a fresh stack is the fixed set; the log-exploration queries below still work. |
| 5.7 | **Dashboards and panel views were not opened in a browser by this audit.** | **Blocked** (Grafana and panel sessions need credentials the audit did not use). Underlying queries were checked directly. | Run `tests/browser/grafana` and the panel suite against the seeded stack in this phase and against staging read-only. | Suite passes; a screenshot per dashboard is stored. |

Reusable filters that worked during the audit (ClickHouse `cdnf.edge_events`,
any time range on `occurred_at`): `hostname`, `domain_id`, `pop`, `edge_id`,
`country`, `status`, `cache_status`, `origin_error`, `security_action`,
`security_reason`, `waf_profile`, `request_id`, `user_agent`, `client_ip`.
Loki: `{service="edge-agent"} |= "edge_sync_failed"`,
`{service="edge-gateway"}`, `{host="<node>", service=~"cell-.+"}`.
Three log kinds stay distinct: request logs (ClickHouse), host and
container logs (Loki), application audit (`audit_logs` in PostgreSQL).
This phase publishes these as documented, tested queries.

**Gate:** a scripted test attack against a staging domain (rate-limit
trips, a WAF block, a blocked rule) is found by domain, edge, reason, and
rule from the panel and from Grafana, and its request, download, and upload
counts match what the script sent.

## Phase 6: Failure domains, recovery, and honest guarantees

| # | Finding | Evidence | Direction | Proof of completion |
| --- | --- | --- | --- | --- |
| 6.1 | **Staging has never taken a backup.** | **Confirmed.** `backups` has zero rows; `ControlBackupStale` (critical) has fired since 2026-11-10; no recipient (5.3). Recovery point on this fleet is "nothing". | Fleet setup does not report success until one backup and one restore preflight have completed. | A fresh fleet install shows a verified backup before it reports ready. |
| 6.2 | **RTO and RPO are not stated anywhere as numbers.** | **Confirmed.** Documentation asks the operator for targets; it publishes none. Restore E2E files exist (`backup_restore.py`, `offhost_recovery.py`); the audit did not run them and they do not time the procedure. | Time the documented restore on real hosts, for "control host lost" and "control host lost and PostgreSQL corrupted", and publish the measured figures with hardware and data size. | A recorded drill with timestamps for each step, repeated twice. |
| 6.3 | **What works without control for six hours is undocumented and partly untested.** | **Code-confirmed, not exercised.** Keeps working: DNS and HTTP serving from last valid state, cached content, existing certificates (SOA expire is 14 days). Stops: enrolling a replacement edge, revoking a compromised edge, issuing or renewing a certificate, any DNS or domain change, rolling back a deployment through the API. A compromised edge keeps a valid identity for the whole outage. | Write this as a table in the operations guide and prove each row with `control_plane_faults.py` extended to a timed outage. Add one offline runbook step that does not need control: removing a PoP's addresses at the registrar or upstream. | The E2E asserts each row of the published table. |
| 6.4 | **Whole-PoP loss with many domains.** | **Blocked.** Staging has two edge hosts in one network and one domain. Sequence by code reading: probes and heartbeat age mark the endpoint, platform DNS stops answering with it, resolvers follow the 300 s TTL, the surviving PoP serves with a cold cache. None of it is measured. | Measure on a fleet with PoPs in separate networks (Phase 9). | See Phase 9. |
| 6.5 | **Failed deployment keeps last valid state.** | **Code-confirmed; agent tests pass** (`edge-agent` rejects invalid bundles and keeps the active generation). Not exercised on staging. | Re-run `mixed_version_upgrade.py` and `cell_generation_reload.py` in the gate. | Both pass. |
| 6.6 | **The single-node installation is supported; it is not highly available.** | **Confirmed.** `control_nodes` is empty; qualification reports single-node control as a warning. | State the guarantee the product can give today: the data plane keeps serving the last valid configuration during a control outage; management availability equals one host; recovery takes the measured time from 6.2. Offer the existing two-node option as the path to more, with its own measured switchover. | The availability page states only measured figures. |

**Gate:** a timed restore drill, a timed control-outage drill, and a
published capability table, each backed by a recorded run.

---

## Part 2: New capabilities

Each phase here starts only after the phases above it in Part 1 that it
names are complete.

## Phase 7: A domain-scoped, time-limited Under Attack profile

Needs Phases 3, 4, and 5.

What exists today (code-confirmed, and seen on staging): "Enable Under
Attack mode" is an administrator-only action that sets the domain's
security state to `restricted`, which applies the `protected` limit set
(50 requests per second and 24 connections per client instead of 100 and
64). It does not touch the WAF, has no expiry the operator chooses, shows
no countdown, and the recovery job silently ends it after ten quiet
minutes. Separately, managed WAF (OWASP CRS 4.26 on ModSecurity 3.0.14)
has Off, Observe, Recommended, and High Sensitivity profiles and scoped
exclusions. The staging incident matches this: the attacker stayed under
the per-client limits, so Under Attack mode changed nothing; switching the
WAF to blocking stopped the requests (5.1).

| # | Item | Direction | Proof of completion |
| --- | --- | --- | --- |
| 7.1 | **One incident profile per domain.** | A single action that, for a chosen duration, sets WAF to blocking at high sensitivity, applies the protected limits, restricts methods and body size, and turns on the challenge (7.2). Stored as desired state with an expiry; delivered through the overlay from 4.1 so it creates one revision on and one off. Available to the domain's users, not only administrators. | E2E: enabling sets all four protections on the edge within a measured delay; expiry removes them without an operator. |
| 7.2 | **A real human or bot challenge.** Three options were compared. (a) A proof-of-work page verified at the edge: no third party, no personal data leaves the platform, works offline from control, but only raises the cost per request and is weak against a patient botnet. (b) A third-party CAPTCHA verified server-side: stronger against bots, but each solve calls an external service from the data plane, adds a privacy dependency, and fails when the provider is unreachable, which conflicts with "runtime never depends on an external API". (c) Both. Recommendation: (a) first, built in the existing cell runtime: a signed, expiring, single-domain token bound to client address and user agent, verified in Lua with a per-cell key delivered in the bundle, rate-limited issuance, replay bounded by expiry and a small seen-set. Evaluate (b) as an optional per-domain provider only after (a) has measured results. | Requirements for either: server-side verification, expiry, replay resistance, a no-script and screen-reader path, bypass for listed trusted clients and for API paths the domain marks, no cookies beyond the token, and a defined failure mode (fall back to rate limits, never to open or to a dead end). | E2E: a client without the token gets the challenge; a solved token passes until expiry; a replayed or foreign-domain token is refused; a headless flood stays blocked; accessibility check passes on the challenge page. |
| 7.3 | **Visible state and history.** | The domain page shows which protections are active, who enabled them, and when they end; one-click early end. Each action is an audit entry. Challenge issued, passed, failed, and WAF blocks appear in telemetry (uses 5.1). | Browser test of the banner, expiry, and recovery; the Phase 5 attack script shows challenge outcomes. |
| 7.4 | **Honest limits.** | Documentation and UI copy state that this protects the origin and the cell from request floods and does not absorb traffic that saturates a host, link, or upstream. | Copy reviewed in the browser suite. |
| 7.5 | **WAF upkeep.** | Not verified in this audit: false-positive workflow, rule visibility for domain users, managed ruleset updates across cells. Check each against the running system before changing anything. | A short verified status per topic in the gate record. |

**Gate:** the incident profile, enabled from the panel on a staging domain,
blocks the Phase 5 attack script, admits a real browser after the
challenge, expires on time, and leaves two revisions and a readable trail.

## Phase 8: Cache capabilities that the correctness work enables

Needs Phase 2.

| # | Item | Why now | Proof of completion |
| --- | --- | --- | --- |
| 8.1 | Cookie allowlist per domain: named cookies that do not affect the response and may be ignored for caching. | 2.1 makes every cookie-bearing request bypass; analytics cookies would otherwise disable caching for most sites. | Hit ratio measured before and after on a recorded traffic sample. |
| 8.2 | Path rules for cache behavior (bypass, TTL) inside the existing typed settings. | The only current scope is the whole domain; staging cached a login-adjacent application whole. | E2E per rule type; no new expression language. |
| 8.3 | Purge by prefix using the existing per-URL generation mechanism, if measurement shows full purge is overused. | Decide from purge logs after Phase 5; do not build on assumption. | Decision recorded with the numbers. |

**Gate:** each shipped item has a before and after measurement.

## Phase 9: Measured performance envelope

Needs Phases 3 and 5. Existing results (20,000 domains, 10,000 DNS changes;
`control_plane_scale.py`, `cell_throughput.py`) stand as control-plane and
single-cell results on a development host. They say nothing about a PoP.
The only edge measurement from this audit is the ramp in 3.5: 50
connections, about 45 requests per second, cache hits, a 4 vCPU, 3.9 GiB
VM shared with DNS, one domain. It is a health check, not a capacity
figure.

Measure separately, on dedicated hosts of the size the sizing guide
recommends, and record for each: hardware (CPU, RAM, storage, NIC), OS and
runtime versions, topology, counts of PoPs, edges, cells, and domains,
dataset, concurrency, duration, throughput, latency percentiles, errors,
the saturated resource, and recovery time.

1. Control-plane mutation throughput.
2. DNS propagation latency, change accepted to answer served on every PoP.
3. Edge request throughput on one host.
4. Cache-hit throughput.
5. Cache-miss throughput.
6. Origin connection saturation and recovery.
7. TLS handshake capacity, including the gateway's handshake budget.
8. Recovery after losing one edge, and after losing one PoP, with PoPs in
   separate networks.
9. Sustained connection churn, and the 50-connection incident pattern
   replayed above the per-client limits.

Publish one-cell, one-host, and multi-PoP results under separate headings.
Capacity improvements are chosen from the saturated resource each test
names, not before.

**Gate:** every row has a recorded run; the sizing guide quotes only those
runs.

## Phase 10: Evidence for adoption

| # | Question | What the audit found | What must be shown |
| --- | --- | --- | --- |
| 10.1 | **Independent security review** of artifact signing, origin validation, cache isolation, edge enrollment, identity renewal and revocation, deployment integrity. | **None exists.** The repository has internal tests, CodeQL, and an internal audit page. Internal tests are not an independent review, and Phase 2 shows cache isolation was wrong while its tests passed. | Commission a scoped external review after Phases 1 to 4; publish its scope, findings, and fixes. |
| 10.2 | **Why deploy this instead of running OpenResty, PowerDNS, DNSdist, and Grafana directly?** | What is implemented and was seen working: one desired state in PostgreSQL rendered into DNS zones, edge bundles, certificates, and dashboards; signed, revisioned bundles with last-valid-state on the edge; managed DNS-01 certificates; Geo-DNS that answered correctly by client subnet; a fleet tool that installed this staging fleet from one definition. What is not yet evidence: that it does these reliably, since this audit found the health loop, the revision loop, and the cache default wrong on a two-day-old fleet. | A third party installs a fleet from the documentation and passes the Phase 1 to 6 gates without help. |
| 10.3 | **What is hard to reproduce if the source is copied?** | Nothing demonstrated yet. Features and automation copy with the source. The candidates are operational proof: published measured envelopes (Phase 9), timed recovery drills (Phase 6), a clean external review (10.1), and a record of releases that passed staging gates. | Those four records, kept current per release. |

**Gate:** the external review is published and the three records exist for
the current release.

---

## What happened to the previous roadmap

The previous "Roadmap to 2.0" (eleven phases, 1,843 lines) is removed.
Disposition of its content, re-checked against current code and staging:

| Previous content | Disposition |
| --- | --- |
| Phases 1 to 7, all items ticked | Not carried forward. Spot checks: path-encoding and argument-limit cache findings (H5, A5, M9) are fixed (reproduced: encoded `?` gets its own entry); gateway per-source limit (M8) is present; stale-heartbeat PoPs stay in DNS (C3) holds on staging; lifetime failure counters (O11) are fixed. Items not spot-checked are **unverified**, not assumed done. |
| C4 "one bad artifact freezes a PoP" | Fixed for artifacts; the same shape is **still open** for heartbeats (3.1, 3.2). |
| A8 "alerts go nowhere" | Receivers exist in code; **still open in operation** (5.3). |
| A31 "FPM master runs as root" | **Partly verified:** the container default user is root (1.6). |
| H8 "no IPv6 in production" | **Unverified.** Staging has no IPv6; nothing could be tested. Carried as a blocked check in Phase 9. |
| Phase 8 open items (fleet web pages, release diff, pre-pull, guided major upgrades, scripted quick start) | Dropped as roadmap items. Re-admit individually if the owner still wants them; none blocks the phases above. The firewall and "alerts have no receiver" checks move into 1.2 and 5.3. |
| Phase 9 open items (scrape remaining services, Grafana browser suite) | Superseded by Phase 5 (5.7 keeps the browser suite). The blackbox exporter item was **incorrectly open**: probes run on staging. |
| Phase 10 open items (documentation rules, sizing guide, generated reference, versioned docs, reader test) | Sizing moves to Phase 9. Reader test moves to 10.2. The rest is dropped. |
| Phase 11 "real production qualification" | Replaced by the per-phase staging gates and Phase 9. Its three-PoP, two-region fleet requirement stands for Phase 9. |
| "Daily checks" table | Replaced by the gates above; the heartbeat, backup, and alert-delivery checks are items 3.3, 6.1, and 5.3. |

## Out of scope unless the owner admits it

BGP control by CDNFoundry, volumetric scrubbing, Kubernetes, microservices,
Kafka, a second dashboard or backend, billing or reseller hierarchies,
plugin runtimes, custom expression languages, and multi-origin load
balancing.
