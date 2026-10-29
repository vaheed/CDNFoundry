---
title: Codebase audit, 2026-10-30
description: Read-only audit of the CDNFoundry dev branch at d4c4fb7 — re-verification of the 2.0 roadmap findings, new findings, test gaps, and a ranked improvement plan.
---

# Codebase audit, 2026-10-30

This audit covers the `dev` branch at commit `d4c4fb7` (version file `1.2.1`). It changed no source, configuration, migration, or test. Its findings are merged into the [roadmap](../roadmap.md), which is the finding register. This page keeps the evidence.

It builds on the [2.0 roadmap](../roadmap.md). That roadmap already lists findings C1–C4, H1–H9, M1–M15, and L1–L6 from an earlier audit. This audit re-checks each of them against the current code and adds new findings, numbered **A1–A47**. It then ranks everything into small, independently shippable changes that fit the existing roadmap phases.

How to read the evidence:

- **Fact (observed):** the code or configuration says it directly. The cited line is the evidence.
- **Inference (likely):** it follows from the code plus documented behavior of the component (nginx, PowerDNS, Laravel, Go). No test reproduced it.
- **Speculation (worth checking):** plausible, but it depends on behavior I could not see.
- **"Verified by reading"** means I opened the cited lines myself and did not rely only on a delegated read.

Section 8 is the execution log of every command I ran. Anything not listed there was not executed.

## 1. Executive summary

**Overall health.** The control plane is disciplined:

- typed validation and policy-aware route binding
- a fail-closed test database guard
- an OpenAPI path list that matches the routes exactly
- signed artifacts and size-limited agent reads
- disk-buffered telemetry that drops events instead of applying backpressure
- SHA-pinned CI with Trivy, SBOM, and cosign

The data plane and operations layer are weaker. The weaknesses are in a few specific places, not spread across the design:

- how the edge passes client identity (client IP, Host/SNI, X-Forwarded-For)
- how WAF exclusions are applied
- how the cache key is built
- edge identity lifetime
- alert delivery
- some unsafe production defaults

All of these can be fixed inside the [product contract](https://github.com/vaheed/CDNFoundry/blob/dev/AGENTS.md). None needs a new service or a per-domain process. The roadmap's own critical findings C1–C4 are all still present.

### Top 5 risks

1. **Private key exposure.** Domain users can download platform-issued TLS private keys from `GET /domains/{domain}/revisions`, and every snapshot and artifact row stores the key in plaintext (C1, C2; still present).
2. **Fleet-wide DNS outage from one control-side fault.** Pools publish no address when heartbeats stop (C3). Edge identity certificates expire after 365 days with no renewal path (A1). A fleet enrolled together therefore stops resolving on the anniversary of enrollment.
3. **WAF bypass.** Any WAF exclusion turns CRS blocking off for the whole request. "Rule" exclusions apply to every request, and visitors can trigger "parameter" and "cookie" exclusions themselves (A4).
4. **Edge request integrity.**
   - Plain-HTTP POST bodies are dropped by the gateway (A2).
   - Every origin sees `127.0.0.1` as the client address (A3).
   - The cache key can be made to collide, which allows cache poisoning (A5, H5, M9).
5. **Blind operations.**
   - Alertmanager has no receiver, so every alert is discarded (A8).
   - Long queue jobs are redelivered while they are still running (A9).
   - Every PoP holds the ClickHouse administrator credential (A7).

### Top 5 quick wins

Each is about one small PR.

1. Return revisions through an API resource with an explicit field list, and hide secret columns (C1, L5, A37).
2. Raise `retry_after` above the largest worker timeout (A9).
3. Configure an Alertmanager receiver and fail startup on an empty route (A8).
4. Forward the bytes the gateway has already buffered (A2). Pass the real client address to the inner origin hop (A3).
5. Add the missing Compose health checks; the gateway already serves `/healthz` (M11). Turn on `useClientSubnet` in DNSdist so Geo-DNS sees the client network (A6).

**Production-readiness verdict: not ready for customer traffic.** It becomes ready when all of these are true:

1. C1, C2, C3, C4, A1, A2, A3, A4, A7, A8, and A10 are fixed, each with a regression test that failed before the fix.
2. The agent-owned `tests/e2e` suite passes on a real Compose stack over IPv4 and IPv6.
3. The owner has run the [production qualification](../operations/production-qualification.md) on real hardware.
4. The owner has completed the [manual browser checklist](https://github.com/vaheed/CDNFoundry/blob/dev/docs/manual-browser-qualification.md). It is owner-run and was not run in this audit.

Until then, the v1.2.1 documentation should not present the release as production-oriented without stating these limits (section 4).

## 2. System map

This is my understanding of the system, built from the code. Please confirm or correct it.

### Components

| Plane | Component | Code | Runs where |
| --- | --- | --- | --- |
| Control | Laravel 13 + Filament 5: API, `/admin`, `/app` | `core/` | control host (`core`, `web`, `horizon`, `scheduler`) |
| Control | PostgreSQL 18 (desired state), Valkey 9 (cache, sessions, queues) | `compose.prod.yml` | control host |
| Control | edge-control: nginx mTLS front for `/edge/v1` | `docker/nginx/edge-control.conf` | control host |
| Control | Caddy ingress for the panel/API, DNS API, and telemetry | `deploy/production/Caddyfile*` | each role |
| DNS | DNSdist 2.1 (the only public :53), PowerDNS Auth 5.1 + its own pdns-db | `docker/dnsdist/`, `docker/pdns/` | each DNS node |
| DNS | mmdb-updater (GeoIP for PowerDNS LUA records) | `docker/mmdb-updater/` | each DNS node |
| Edge | edge-gateway (Go, L4 SNI/Host router, PROXY protocol) | `edge-gateway/main.go` | each PoP, host network |
| Edge | OpenResty cells: 8 fixed slots, one data-driven runtime | `docker/openresty/runtime.lua`, `docker/nginx/edge-runtime.conf` | each PoP |
| Edge | edge-agent (Go): sync, verify, activate, tasks, acks | `edge-agent/*.go` | each PoP |
| Telemetry | Vector → ClickHouse; Prometheus, Alertmanager, Grafana, Loki | `docker/vector/`, `docker/clickhouse/`, `docker/prometheus/` | telemetry host and every node |

### Trust boundaries

1. **Internet → DNSdist (UDP/TCP 53).** Spoofable source addresses. The only defenses are per-IP and global QPS limits.
2. **Internet → edge-gateway → cell.** The gateway trusts nothing. Cells trust the PROXY header from any source (`set_real_ip_from 0.0.0.0/0`).
3. **Cell → origin.** Origins are customer-controlled and untrusted. The SSRF lists are enforced at connect time.
4. **PoP agent → control (mTLS, outbound only).** The edge is identified by a pinned certificate fingerprint that nginx passes to PHP-FPM as FastCGI parameters (L6).
5. **Every node → telemetry (Vector → Caddy → ClickHouse).** Today this uses a shared credential that has administrator rights (A7).
6. **Browser/API → Laravel.** Sanctum tokens have no expiry or scope (M12). Domain users are isolated by `DomainPolicy` and by route binding.
7. **Control → PowerDNS API on each DNS node.** HTTPS through Caddy, gated by source IP and API key (M4).

### Main data flows

- **Mutation.** API or panel → form validation → transaction (desired state + revision) → `Operation` coalesced per domain → one unique job on `runtime` → render and checksum → activate (PowerDNS PATCH, or an artifact for the edges) → verify → acknowledge → operation status.
- **Edge delivery.**
  1. The agent polls `/edge/v1` with a cursor.
  2. The server returns a signed manifest; the content signature covers the checksum, and the delivery signature covers the checksum, sequence, and expiry.
  3. The agent verifies and builds a candidate generation, then fsyncs and atomically swaps it in.
  4. Cells reload the state file every second (M6).
  5. The agent queues acks, and control promotes placements once every participating edge has acknowledged (H3).
- **DNS.** `PowerDnsZone` and `PlatformDnsZone` render the zones. `ReconcileDnsZone` pushes to every cluster in turn. DNSdist forwards queries to PowerDNS; PowerDNS answers from pdns-db, plus LUA records that use GeoIP.
- **Telemetry.** Cells and DNSdist (dnstap) send events to the local Vector, which redacts them and sends them with a disk buffer to ClickHouse. Laravel's `AnalyticsStore` reads ClickHouse with per-query limits.

## 3. Findings

Severity follows the roadmap's scale:

- **Critical:** leaks secrets, or can take down every domain.
- **High:** breaks a feature or a security property for many domains.
- **Medium:** wrong behavior with a limited blast radius, or a scaling or operability limit.
- **Low:** cleanup.

Effort: **S** is under a day, **M** is a few days, **L** is a week or more.

### 3.1 Existing roadmap findings, re-verified on `d4c4fb7`

| ID | Status | Current evidence | Note |
| --- | --- | --- | --- |
| C1 | Still present (verified by reading) | `core/app/Jobs/ReconcileEdgeDomain.php:132` puts `private_key_ciphertext`, which the encrypted cast decrypts, into the snapshot. `core/app/Http/Controllers/ProxyController.php:118` returns raw `EdgeRevision` models. `core/app/Models/EdgeRevision.php` has no `$hidden`. | — |
| C2 | Still present | `EdgeRevision` casts `snapshot` and `EdgeArtifact` casts `payload` as plain `array`. Written at `ReconcileEdgeDomain.php:211-237`. | — |
| C3 | Still present | `core/app/Models/EdgePoolEndpoint.php:45-47` (`heartbeat_stale`). `core/app/Support/PlatformDnsZone.php:45-47` has no branch for an empty pool. `core/routes/console.php:24` re-renders every minute. | No alert uses `cdnfoundry_pool_endpoint_state`. |
| C4 | Still present | `edge-agent/main.go:829-887` returns without moving the cursor. `core/app/Http/Controllers/EdgeAgentController.php:334` accepts only 3 of the agent's 5 rejection reasons. `flushAcks` stops at the first failure (`main.go:1832-1834`). | No test covers the rejection paths. |
| H1 | Still present | Pools are now loaded at `ReconcileEdgeDomain.php:64-67` and hashed at `:83-87`. | The roadmap's line references are stale. |
| H2 | Still present | `core/app/Http/Controllers/WafController.php:392-403` accepts any profile. `ReconcileEdgeDomain.php:68-71` then throws. | — |
| H3 | Still present | `core/app/Actions/PromoteReadyEdgePlacements.php:38` requires `every(...)` participant, at `:48`. | See also A12. |
| H4 | Still present | `core/app/Support/PowerDnsZone.php:27-29` throws, which aborts the whole zone. | — |
| H5 | Still present | PHP: `core/app/Support/CacheKey.php:26,37,42` uses the raw path and `rawurldecode`. Lua: `docker/openresty/runtime.lua:339-356` uses the decoded `ngx.var.uri` and `get_uri_args`. | New detail: PHP sorts repeated numeric values as numbers (`CacheKey.php:45`), while Lua sorts them as strings (`runtime.lua:354`). |
| H6 | Still present | `runtime.lua:1127` does an `incr` with no TTL in `runtime_limits`, a 10 MB dictionary (`edge-runtime.conf:3`) that is shared with per-second counters. `runCachePurge` (`edge-agent/main.go:387-415`) saves nothing to disk. | — |
| H7 | Still present | `core/app/Jobs/ReconcileDnsZone.php:24` sets `$tries = 3` with no `$backoff`. `routes/console.php` schedules no DNS drift or repair job, and `ReconcileAllDnsZones` is never scheduled. | — |
| H8 | Partial | `compose.prod.yml` binds only `${HOST_BIND_IPV4}`/`${DNS_BIND_V4}`. The Fleet renderer adds IPv6 binds when `bind_ipv6` is set (`scripts/cdnfoundry_fleet/render.py:157-158,435-446`), but not for edge-control (`render.py:294`). `dnsdist.conf:1` listens on `0.0.0.0:53` only. | The manual path has no IPv6. |
| H9 | Still present | `docker/vector/vector.yaml:145` (`domain_id: 0`). `core/app/Support/AnalyticsStore.php:63,113,142` filters with `endsWith`. Usage at `:192` picks the first suffix match. | Usage can be attributed to the wrong tenant. |
| M1 | Still present | `core/app/Support/ProxyRevisionRollback.php:236-243` copies `origin` without validation and deletes non-proxied records with the same name. | — |
| M2 | Still present | `ReconcileEdgeDomain.php:120-123` builds the list with six `pluck()` calls and no `orderBy`. | — |
| M3 | Partial | Routing changes bump the serial (`core/app/Jobs/ReconcilePlatformDnsIdentity.php:118`). The every-minute render depends on heartbeat readiness but does not bump it. Zones are `Native` with no SOA-EDIT-API (`core/app/Support/PowerDnsClient.php:27`). | — |
| M4 | Still present | `ReconcileDnsZone.php:64-93` loops over clusters one at a time. `PowerDnsClient.php:144` sets a 10 s timeout with 2 retries. One failing cluster fails the whole operation (`:99-103`). | — |
| M5 | Still present, worse than stated | `core/config/horizon.php:215-217`. No job calls `onQueue('interactive')`, so the `interactive` supervisor never receives work. | — |
| M6 | Still present | `runtime.lua:158` runs `ngx.timer.every(1, refresh)`. `load()` decodes up to 64 MiB on every tick (`:117-138`). | — |
| M7 | Still present | `edge-agent/main.go:1148-1182` rewrites all state. `backup.go:150-185` re-encrypts the full state. | — |
| M8 | Still present | `edge-gateway/main.go:83,340-348` has one global slot channel. | Extended by A18. |
| M9 | Still present | `runtime.lua:343` calls `get_uri_args(128)`. | Folded into A5. |
| M10 | Still present | `core/app/Http/Controllers/Admin/EdgeController.php:30` allows 1–32 slots. `compose.prod.yml` defines exactly 8 cells. | — |
| M11 | Still present | No `healthcheck:` on edge-control, horizon, scheduler, edge-agent, or edge-gateway. | `edge-gateway/main.go:575` already serves `/healthz`. |
| M12 | Still present | `core/config/sanctum.php` sets `expiration => null`. `core/app/Models/User.php:149` creates tokens with no abilities. Neither panel configures MFA. | Extended by A30. |
| M13 | Partial | A partial unique index exists (`core/database/migrations/2026_07_18_050000_add_domain_reclaim_state.php:16`). Names are still unique among live rows, and there is no takeover path. | The roadmap's reference is stale. |
| M14, M15, L1, L3, L4 | Still present | — | Design and cleanup items; not re-litigated here. |
| L2 | Still present | Two activation paths exist. `edge-agent/main.go:1220-1256` can never run, because a non-empty `runtimeDir` already returned at `:1193`. | — |
| L5 | Still present | Same location as C1. | Extended by A37. |
| L6 | Still present | `core/app/Http/Middleware/AuthenticateEdge.php:14-24` trusts the `X-Edge-Certificate-*` FastCGI parameters. | — |

**Severity change I recommend:** together, C3 and A1 are a time-bomb version of C3. Treat "edge identity renewal" as part of the Phase 2 exit criteria.

### 3.2 New findings: Critical and High

Each finding uses the same fields:

- **Evidence**, and how I verified it
- **Impact**, with a realistic scenario
- **Change**, the invariant it respects, and the alternatives considered
- **Effort and risk**, including rollout and rollback
- **Verify**: who runs the check, agent-owned or owner-run

#### A1 — Edge identity certificates expire after one year and nothing renews them

- **Area:** D, E, H. **Severity:** High (Critical in combination with C3). **Confidence:** High. **Fact.**
- **Evidence:**
  - `core/app/Support/EdgeCertificateAuthority.php:19` signs the edge certificate with `openssl_csr_sign(..., 365, ...)`.
  - The agent loads it once, in `configureMutualTLS` (`edge-agent/main.go:774-791`).
  - No code anywhere renews it, and `/edge/v1` has no renewal route (`core/routes/web.php:10-21`).
- **Impact:** 365 days after enrollment, each PoP loses its mTLS session to control.
  - Its heartbeats stop.
  - Because of C3, its pool endpoints are withdrawn from the platform zone, and a pool with no fresh endpoint publishes no address.
  - Edges enrolled in the same week (a normal rollout) all expire within the same week.
  - Every proxied domain stops resolving, even though every edge is still serving.
  - Recovery needs a new bootstrap token for each PoP.
- **Change:**
  - Add an mTLS-authenticated `POST /edge/v1/identity/renew`. The agent sends a CSR with a fresh key at two thirds of the certificate's lifetime.
  - The server records the new fingerprint next to the old one and accepts both until the agent's next heartbeat proves it is using the new one.
  - Export `edge_identity_not_after` in the heartbeat, and add an alert at 30 days before expiry.
  - **Invariant:** this is asynchronous, outbound-only, and keeps the last valid state, because the old certificate stays valid until the new one is proven.
  - **Alternatives considered:** a 10-year certificate. Rejected: revocation is by pinned fingerprint, but a long-lived key cannot be rotated.
- **Effort and risk:** M, low risk. Roll out the server first; old agents keep working. Roll back by disabling the renewal route; certificates already renewed stay valid.
- **Verify:**
  - **Agent-owned:**
    - A Go test with a fake clock renews at two thirds of the lifetime.
    - A feature test shows the old fingerprint is rejected after the new one is confirmed.
    - E2E (`tests/e2e`): issue a 2-minute certificate in dev, and check the PoP keeps its heartbeat across expiry.

#### A2 — The gateway drops request bytes that arrive with the HTTP headers

- **Area:** C, D. **Severity:** High. **Confidence:** High. **Fact, verified by reading.**
- **Evidence:**
  - `edge-gateway/main.go:419` wraps the client in `bufio.NewReaderSize(connection, 16<<10)`.
  - `readHTTP` returns only `buffer.Bytes()`, the header lines (`:439`).
  - `handle` writes that prefix upstream (`:401`), then copies from the raw `client` (`:409`).
  - Anything the bufio reader read past the blank line is lost.
- **Impact:** on port 80, a small POST body sent in the same packet as its headers never reaches the cell. Pipelined requests are lost the same way.
  - nginx waits for the Content-Length body until `client_body_timeout`, then returns 408 or the request hangs.
  - HTTP/1.1 form posts over port 80, ACME HTTP-01 checks against customer origins, and health probes that POST all fail at random, depending on packet boundaries.
  - TLS is not affected, because `readClientHello` uses `io.ReadFull` for exactly one record.
- **Change:**
  - Return the `bufio.Reader` from `readHTTP` and copy upstream from that reader, which serves the buffered bytes first, not from the raw connection.
  - Add a unit test that sends headers and body in one write, and one with two pipelined requests.
  - **Invariant:** no new process; keeps the bounded 16 KiB preface.
  - **Alternatives considered:** use `Peek` with a fixed size. Rejected: it is fragile when headers vary in length.
- **Effort and risk:** S, low risk. Ship it as a gateway image update, and roll back to the previous image digest if needed.
- **Verify:**
  - **Agent-owned:** a Go test in `edge-gateway` that reads the body bytes on the upstream side; an E2E `curl -d` through the dev gateway on port 80.

#### A3 — Every origin receives the client address as `127.0.0.1`

- **Area:** C. **Severity:** High. **Confidence:** High. **Fact, verified by reading.**
- **Evidence:**
  - The origin hop is a second server, `listen 127.0.0.1:9081` (`docker/nginx/edge-runtime.conf:157`).
  - The outer hop sends it only Host, Connection, Upgrade, `X-CDN-Original-Scheme`, and Accept-Encoding (`docker/nginx/cache-upstream.conf`).
  - Real-IP handling (`real_ip_header proxy_protocol`) exists only on the outer listeners (`edge-runtime.conf:33-35`).
  - `docker/nginx/origin-proxy.conf:7-8` (also `:32-33,:55-56`) sets `X-Forwarded-For $remote_addr` and `Forwarded "for=$remote_addr;..."`. In the inner server, `$remote_addr` is `127.0.0.1`.
  - The E2E test `tests/e2e/phase4_runtime.py:527-529` sends from 127.0.0.1, so it cannot tell the two apart.
- **Impact:** origin access controls, origin rate limits, fraud checks, and logs all see one address.
  - An origin that allow-lists or rate-limits by `X-Forwarded-For` either blocks every visitor or trusts every visitor.
  - It breaks the documented forwarding-header normalization.
- **Change:**
  - In the outer hop, set one internal header carrying the real client address, for example `X-CDNF-Internal-Client: $remote_addr`.
  - Clear any copy of that header the client sends, at ingress.
  - In the inner server, use it for `Forwarded`, `X-Forwarded-For`, and the Lua `client_address`, then strip it before `proxy_pass`.
  - **Invariant:** one data-driven runtime, deterministic headers.
  - **Alternatives considered:** `set_real_ip_from 127.0.0.1` with `real_ip_header` on the inner server. Equivalent, but easier to get wrong.
- **Effort and risk:** S, low risk. Ship it in the cell image, and roll back by image digest.
- **Verify:**
  - **Agent-owned:** E2E from a non-loopback source address on the dev network, using a container on the edge network. Assert the origin sees that address in both headers and never `127.0.0.1`.

#### A4 — One WAF exclusion turns off CRS blocking for the whole request

- **Area:** C, G. **Severity:** High. **Confidence:** High. **Fact, verified by reading.**
- **Evidence:**
  - `docker/openresty/runtime.lua:463-475`: when an exclusion matches, the runtime sets `mode = "monitor"` and the header `X-CDNF-Internal-WAF-Mode: monitor`.
  - The excluded `rule_id` is only range-checked (`900000–999999`). It is never passed to ModSecurity: `docker/openresty/waf.conf` has no remove-by-id action.
  - A `dimension == "rule"` exclusion matches every request.
  - The `parameter` and `cookie` dimensions match on the mere presence of a name the client controls (`args[exclusion.value] ~= nil`, `cookie_names[...]`).
- **Impact:**
  - A domain user who adds one rule exclusion to silence a false positive disables WAF blocking for the whole domain.
  - Worse, an attacker who learns that an exclusion exists for parameter `q` adds `?q=` to any attack request and bypasses the CRS completely.
- **Change:**
  - Apply an exclusion as a rule-scoped removal for that request only: ModSecurity `ctl:ruleRemoveById` or `ctl:ruleRemoveTargetById`, keyed to the exclusion's dimension and value.
  - Generate these as data from the typed exclusion. Use no custom expression language.
  - Keep request-wide monitor mode only for the explicit profile `mode=monitor`.
  - **Invariant:** typed validated input, one runtime, and no per-domain Nginx blocks, because the rules come from the domain snapshot and are applied per request.
  - **Alternatives considered:** reject exclusions until this is fixed. That is an acceptable interim step: a 409 with a stable code.
- **Effort and risk:** M, medium risk, because CRS interaction needs real-runtime tests. Roll out behind the existing per-domain WAF readiness checks. Roll back by image.
- **Verify:**
  - **Agent-owned:** extend `tests/e2e/phase9_waf.py`. With an exclusion for rule X on parameter `q`:
    - an attack that triggers rule Y is still blocked;
    - adding `?q=` does not unblock it;
    - the false positive for rule X on `q` passes.

#### A5 — The cache key collides on encoded path characters

- **Area:** C. **Severity:** High. **Confidence:** High that the collision exists; Medium on how exploitable it is. **Fact plus inference.**
- **Evidence:**
  - `runtime.lua:339-359` builds the key as `uri .. "?" .. args` from the decoded, normalized `ngx.var.uri`.
  - `/x%3Fv=1` decodes to the URI `/x?v=1` with no arguments, which gives the same key as the real `/x?v=1`.
  - The origin receives the raw request URI, because `proxy_pass` has no URI part.
  - `%2F`, `/../`, and `//` collide in the same way.
  - On top of that, H5 (PHP/Lua mismatch) and M9 (only the first 128 arguments count) apply.
- **Impact:** cache poisoning. With an origin that has an SPA catch-all, which returns 200 HTML for any path, an attacker requests `/app.js%3Fv=1`. The HTML response is stored under the key of `/app.js?v=1`, and every visitor gets HTML instead of the script.
- **Change:**
  - Write one cache-key specification. Build the key from the raw request path, normalized only in documented, reversible ways: decode unreserved characters, keep `%2F` and `%3F` encoded, and reject any request that would normalize differently from the origin request.
  - Use the full, sorted argument set, or reject requests with more than the limit.
  - Generate shared PHP and Lua test vectors from one fixture file, as the roadmap's Phase 3 already proposes.
  - **Invariant:** cache lookup and URL purge share one deterministic key.
  - **Alternatives considered:** forward the normalized URI to the origin. Rejected: it changes origin semantics.
- **Effort and risk:** M, medium risk. A one-time cache epoch bump is needed, which is a full purge for each domain, and it is already supported. Roll back by image, followed by another epoch bump.
- **Verify:**
  - **Agent-owned:** the shared fixture runs in both the PHP unit tests and a Lua test harness (Busted or `resty` in the cell image). E2E checks that a poisoned request does not affect the clean key, and that a URL purge removes exactly the fixture URL.

#### A6 — Geo-DNS sees the DNSdist address, not the client network

- **Area:** B. **Severity:** High. **Confidence:** Medium-High. **Inference from PowerDNS behavior, configuration verified by reading.**
- **Evidence:**
  - `docker/dnsdist/dnsdist.conf:21` calls `newServer({address=..., name=hostname})` with no `useClientSubnet`. There is no PROXY protocol to the backend either.
  - `docker/pdns/pdns.conf:6` sets `edns-subnet-processing=yes`.
  - The LUA records use `countryCode()`, `continentCode()`, and `pickhashed()` (`core/app/Support/EdgeRoutingCompiler.php:38-43`). Those use ECS when the resolver sends it, and otherwise the source address, which here is DNSdist's private container address.
  - `tests/e2e/phase3_geo_dns.py:93-103` checks only the default answers, never a country override.
- **Impact:**
  - Resolvers that send no ECS, which is most of them, all get the global answer, so per-country rules never apply.
  - `pickhashed` hashes them all to the same endpoint, which concentrates load on one edge.
  - Geo-DNS looks configured, but it is not working.
- **Change:**
  - Set `useClientSubnet=true` on the backends, with `setECSSourcePrefixV4(24)` and `setECSSourcePrefixV6(56)`. Alternatively, set `useProxyProtocol=true` and enable `proxy-protocol-from` in PowerDNS.
  - Add an E2E test that sends a query with ECS from an override country, and one without ECS, and checks the answers.
  - **Invariant:** runtime DNS never calls Laravel or an external GeoIP API.
- **Effort and risk:** S, low risk. Packet caches must include ECS in the cache key; check whether a DNSdist packet cache is enabled. Roll back by configuration.
- **Verify:**
  - **Agent-owned:** `dig +subnet=<IR prefix>` against dev DNSdist returns the override.
  - **Owner-run:** real public resolvers through actual delegation.

#### A7 — Every edge and DNS node holds the ClickHouse administrator credential

- **Area:** F, G. **Severity:** High. **Confidence:** High. **Fact, verified by reading.**
- **Evidence:**
  - In `compose.prod.yml`, ClickHouse is created with `CLICKHOUSE_USER: cdnf` and `CLICKHOUSE_DEFAULT_ACCESS_MANAGEMENT: "1"`.
  - Vector on every role sends with `CLICKHOUSE_USER: cdnf` and the same `CLICKHOUSE_PASSWORD`.
  - Remote nodes reach ClickHouse through `deploy/production/Caddyfile.telemetry`.
- **Impact:** an attacker who compromises one PoP, which is the most exposed host, can:
  - read every tenant's request and DNS logs;
  - change or drop tables, and erase evidence;
  - create users.
  This breaks the separation between a compromised edge node and other tenants.
- **Change:**
  - Create three users in ClickHouse init:
    - `cdnf_ingest`: `INSERT` only, on the raw tables only. Vector uses it.
    - `cdnf_query`: `SELECT` only, with a server-side settings profile for `max_execution_time`, `max_memory_usage`, and `readonly=2`. Laravel uses it.
    - the administrator user: used only for migrations.
  - Give each node its own ingest password, so one can be revoked.
  - **Invariant:** telemetry still goes directly from Vector to ClickHouse.
- **Effort and risk:** S–M, low risk. Roll out by creating the users, switching Vector, then removing the shared password from edges. Rollback is the reverse.
- **Verify:**
  - **Agent-owned:** E2E shows the ingest credential cannot `SELECT` or `DROP`, and the query credential cannot `INSERT`. `tests/e2e/vector_traffic.py` still passes.

#### A8 — Alertmanager has no receiver, so alerts go nowhere

- **Area:** H. **Severity:** High. **Confidence:** High. **Fact, verified by reading.**
- **Evidence:**
  - `docker/alertmanager/alertmanager.yml` routes everything to the receiver `local`, which has no webhook, email, or other integration.
  - The 30 rules in `docker/prometheus/telemetry-alerts.yml`, including certificate expiry, queue age, and DNSdist down, are evaluated and then dropped.
- **Impact:** every documented alert path is silent in production. Certificate expiry, queue stalls, a lost DNS backend, and a full disk are found only by users.
- **Change:**
  - Render receivers from typed environment values (webhook URL, SMTP), using a Fleet field and a manual-path variable.
  - Add a startup check, or a `config-check` rule, that refuses a production configuration whose route has no notifier.
  - Add an always-firing `Watchdog` alert, so a dead-man's switch can confirm delivery.
- **Effort and risk:** S, low risk. Roll back by configuration.
- **Verify:**
  - **Agent-owned:** a `promtool` and `amtool check-config` test in CI; E2E with a local webhook sink that receives `Watchdog`.

#### A9 — Long queue jobs are redelivered while they are still running

- **Area:** A. **Severity:** High. **Confidence:** High on the configuration; the effect is inferred from Laravel's queue semantics. **Fact, verified by reading.**
- **Evidence:**
  - `core/config/queue.php:71` sets `retry_after` to `env('REDIS_QUEUE_RETRY_AFTER', 90)`, and no Compose or environment file overrides it.
  - The Horizon timeouts are 120 s (`runtime`, `certificate-purge`) and 300 s (`bulk-maintenance`) (`core/config/horizon.php:215-217`).
  - `core/app/Jobs/CreateControlBackup.php:19` sets a 3700 s timeout.
- **Impact:** any job still running after 90 s is pushed back onto the queue. It then runs twice at the same time, or fails with "has been attempted too many times". That covers:
  - PowerDNS pushes across many clusters (M4)
  - global reconciles
  - backups
  - nameserver checks
  The result is duplicate backups, duplicate PowerDNS PATCHes, and operations marked failed while their work succeeded (see A15).
- **Change:**
  - Set `retry_after` per connection above the largest timeout on that connection, for example `REDIS_QUEUE_RETRY_AFTER=3900`, or give long jobs their own connection.
  - Add a `config-check` assertion that `retry_after` is greater than every supervisor timeout and every job `$timeout`.
- **Effort and risk:** S, low risk. Roll back by environment variable.
- **Verify:**
  - **Agent-owned:** a unit test that compares the configuration values; a feature test with a job that sleeps longer than 90 s, under the Compose Redis queue in E2E.

#### A10 — The manual production path ships the development PowerDNS credentials

- **Area:** B, G. **Severity:** High (manual path only). **Confidence:** High. **Fact.**
- **Evidence:**
  - `compose.prod.yml:320,325` overrides the entrypoint to `pdns_server` and mounts `docker/pdns/pdns.conf` unchanged.
  - That file has an inline development database password at `:12` and an inline API key at `:14`. Values are not reproduced here.
  - `PDNS_API_KEY` is documented in `.env.prod.example` and `docs/deployment/manual-compose.md` but never referenced in `compose.prod.yml`.
  - Only the Fleet renderer rewrites the two values (`scripts/cdnfoundry_fleet/render.py:572-587`).
- **Impact:** an operator who follows the manual Compose guide gets one of two results:
  - a PowerDNS that cannot reach its database, because the password does not match;
  - or a PowerDNS API key that is public in this repository, which anyone who can reach the DNS API ingress (gated only by source IP) can use to rewrite every zone.
- **Change:** render `pdns.conf` from a template at container start, with the API key and database password read from Compose secrets. Fail startup if either is still the development value.
- **Effort and risk:** S, low risk. Roll back by restoring the previous mount.
- **Verify:**
  - **Agent-owned:** `make config-check` gains an assertion that the production PowerDNS configuration contains no development secret; `tests/e2e/fleet_pdns.py` passes for both paths.

### 3.3 New findings: Medium

Every Medium finding is a fact unless it says otherwise. Columns:

- **Change / invariant:** the recommended change, and the invariant it respects.
- **Effort / risk:** effort is S, M, or L.
- **Verify:** who runs it. A = agent-owned, O = owner-run.

| ID | Area | Finding and evidence | Impact / scenario | Change / invariant | Effort / risk | Verify (owner) |
| --- | --- | --- | --- | --- | --- | --- |
| A11 | A | **Idempotency stores error responses for 24 h.** `core/app/Http/Middleware/IdempotentRequest.php:62-70` stores any JSON response below 500. `throttle:bulk` runs inside `idempotent` (`core/routes/api.php:69-110`). The lookup at `:43` ignores `expires_at`. The whole request runs in one transaction (`:33`), including origin DNS resolution. Verified by reading. | A client gets a 429, retries with the same key as the contract says, and receives the replayed 429 for 24 h. The same happens to transient 403 and 409 responses. | Store only 2xx and deterministic 4xx (422, 409 `idempotency_conflict`). Never store 429, `idempotency_busy`, or 5xx. Filter on `expires_at`. Put `throttle` before `idempotent`. Keeps the conflict-on-reuse contract. | S / low | A: feature test, 429 then a retry gives a fresh response |
| A12 | A | **Promotion only looks at the first 100 deploying placements.** `core/app/Actions/PromoteReadyEdgePlacements.php:26-27` uses `orderBy('id')->limit(100)`. All callers use the default. | If 100 placements with lower ids are stuck (H3, C4), every newer domain stays in `deploying` forever. | Page by cursor through all deploying placements in bounded chunks, or skip ones known to be blocked. Bounded, chunked global work. | S / low | A: feature test with 150 placements where the first 100 are blocked |
| A13 | A | **New domains can be placed in withdrawn pools.** `ReconcileEdgeDomain.php:64` filters only on `enabled`. `PowerDnsZone.php:28` checks `enabled`. `PlatformDnsZone.php:150-151` leaves withdrawn pools out. | A domain hashed into a withdrawn pool gets a CNAME to a hostname that is not published, so it never resolves. | Exclude withdrawn pools from automatic placement. Do it together with H1 (shared pools only). | S / low | A: feature test |
| A14 | A | **Coalesced operations are not visible to other members.** `core/app/Models/Operation.php:264-269` returns an existing pending operation that may belong to another actor. `OperationController.php:35` requires `actor_id === user`. The check-then-insert has no lock and no partial unique index. | User B receives user A's `operation_id` in a 202, then gets 403 when polling it. Two pending operations can exist for one domain. | Authorize operation reads through `DomainPolicy::view` on the operation's domain. Add a partial unique index on `(type, domain_id) WHERE status IN (pending, running)`, with the domain id as a typed column instead of JSONB. Expand/contract migration. | M / low | A: feature tests with two members; a PostgreSQL contract test (section 5) |
| A15 | A, B | **A DNS operation stays "failed" after a retry succeeds.** `core/app/Jobs/ReconcileDnsZone.php:56,101` marks the operation failed and rethrows. `operations()` at `:129` matches only pending or running. There is no per-domain lock, and the obsolete-work check at `:75` happens before `activate()`. | The user sees "failed" while DNS is correct. For a short time an older revision can overwrite a newer one, until the job is re-dispatched (inference). | Mark failed only in `failed()`, after the last try. Hold a per-domain lock (`WithoutOverlapping` keyed on the domain), and re-check the revision just before `activate()`. Skips obsolete work. | S / low | A: a job test that fails once and then succeeds |
| A16 | C | **Host and SNI are never compared.** The certificate is chosen by SNI (`runtime.lua:180-182`); the tenant is chosen by `ngx.var.host` (`:503-504`). The gateway routes HTTP by the Host of the first request only (`edge-gateway/main.go:369`, `readHTTP`). | SNI `a.com` with `Host: b.com` returns b's content using a's valid certificate. That bypasses b's `tls.mode=disabled` and its expired-certificate check (`:194-197`). Cross-tenant fronting. | On TLS listeners, reject (421) a request whose Host does not equal the SNI, unless both are in the same domain's certificate set. One runtime rule. | S / medium | A: E2E with a mismatched SNI and Host |
| A17 | C | **Leftmost `X-Forwarded-For` is trusted, with weak parsing.** `runtime.lua:265-268` takes the first XFF entry. `ipv4_number` does not check octet ranges (`:38-40`), and any string with `:` counts as IPv6. PROXY listeners trust `0.0.0.0/0` (`edge-runtime.conf:33-35`). | Invalid or rotating values evade IP/CIDR rules, country rules, and per-client rate-limit keys. Any process on the edge network can forge the PROXY header. | Use the PROXY-protocol address only. Never use client-supplied XFF unless the request came from a configured trusted-proxy list, and then take the rightmost untrusted entry. Restrict `set_real_ip_from` to the gateway's address. | S / low | A: E2E with forged XFF and PROXY headers |
| A18 | C | **Gateway slots can be held forever.** After the preface, both deadlines are cleared (`edge-gateway/main.go:406-407`). `<-done` waits on the client-to-upstream copy (`:414`). The preface deadline is 15 s (`:362`). A ClientHello can take up to 64 KiB (`:468`). Extends M8. | An idle client keeps its slot and two goroutines with no end. About 550 connections per second from one IP fill the 8192 slots. | Idle timeouts in both directions, refreshed on activity. Close the client side when the upstream closes. Add per-source slot limits (roadmap Phase 4). Bounded per client. | M / low | A: Go tests for idle timeout and half-close |
| A19 | C, H | **Five emergency actions are accepted but do nothing.** `reject_unknown_hosts`, `quarantine_domain`, `reduce_keepalive`, `reduce_origin_concurrency`, and `withdraw_service_ip_from_dns` are on the accepted list (`runtime.lua:1081-1083`), but nothing reads them. | Operators get `accepted:true` during an incident, and no protection is applied. | Implement them, or remove them from the accepted list and the documentation, and reject them with a stable code. Prefer smaller and true. | S–M / low | A: E2E for each action that is kept |
| A20 | C | **Per-domain limits are shared by all clients.** One handshake counter per domain, 50/s (`runtime.lua:190-192`). `limit_conn hostname_connections 512` (`edge-runtime.conf:59`). | One client making 50 handshakes per second blocks new TLS sessions for the whole domain. | Key handshake and connection limits on (domain, client prefix), with a separate, higher domain ceiling. Bounded per client and per domain. | S / low | A: E2E from two source addresses |
| A21 | C, H | **Cell temporary storage and workers don't match the container limits.** `/var/lib/nginx/tmp` is a 64 MB tmpfs (`compose.prod.yml:69`) inside a 512 MB memory limit. The configs allow temp files up to 1024 MB (`edge-runtime.conf:149`), 100 MB (`origin-proxy.conf:16`), and 16 MB bodies. `worker_processes auto` (`docker/openresty/openresty.conf:13`) ignores `cpus: 0.5`. | Four 16 MB uploads fill temp storage, and every buffered request on the cell fails. On many-core hosts, one state copy per worker, decoded every second (M6), leads to OOM kills. | Size `proxy_max_temp_file_size` and `client_max_body_size` to the tmpfs, or use a bounded disk volume. Set `worker_processes` from the CPU limit. Bounded temporary storage. | S / low | A: E2E upload test; O: hardware sizing |
| A22 | D | **Some manifest fields are not covered by the signature.** The content signature covers only the checksum (`edge-agent/main.go:1897`). The delivery signature covers the checksum, sequence, and expiry joined by pipes (`:1915`). `kind`, `domain_id`, and `revision` are unsigned (`:831`). Tombstones don't check the payload's domain (`:866-867`). Equal revisions are accepted (`:875`). The signing key is trusted on first use (`:741,1080`). | A delivery replayed within 600 s with a changed manifest can tombstone any domain. This needs an on-path attacker inside mTLS, so it is defense in depth. There is no way to rotate the signing key. | Sign `kind`, `domain_id`, `revision`, checksum, sequence, and expiry together. Require a strictly greater revision. Add signing-key rotation with an overlap window, sent over the mTLS channel. | M / medium | A: Go tests for a tampered manifest and for key rotation |
| A23 | F | **Telemetry ingest is unauthenticated, and `domain_id` comes from the payload.** `docker/vector/vector.yaml:4-16` (HTTP on 8686 and 8687, UDP on 9000, on `0.0.0.0`). `:42` trusts `domain_id`. | Anything on the edge network can forge usage, which feeds billing exports, and analytics for any tenant. | Bind sources to the cell network only, require a per-node token on the HTTP sources, and derive `domain_id` at the cell from the active snapshot. This matches the roadmap's H9 approach. | M / low | A: E2E with a forged event from another container |
| A24 | F | **Telemetry stores full client IPs and user agents, but README says it is redacted.** `vector.yaml:83,86,128,150` keep the full `client_ip` for 7 days. Query strings, cookies, and authorization headers are removed (`:45,88,107`). | A privacy claim that is not accurate. Raises GDPR exposure for operators. | Either document exactly what is kept, or truncate addresses to /24 and /48 in long-lived rollups and keep full addresses only in the 7-day raw table. Pick one and state it. | S / low | A: Vector unit test |
| A25 | F, L | **DNS rollups keep attacker-chosen names for years.** `docker/clickhouse/init.sql:94-133`: the hourly and daily DNS rollups key on `zone`, which is the `qname`, with TTLs of 400 days and 3 years. The DNS scope can't use the primary key (`:64`, together with H9). | A random-subdomain flood creates millions of rows that stay for years, inflating storage and query cost. | Store the domain apex (or `domain_id`) and a bounded top-N of qnames per hour in the rollups. Bounded cardinality. | M / low | A: ClickHouse E2E with a synthetic flood |
| A26 | F, H | **ClickHouse migrations are not run or tracked in production.** `docker/clickhouse/migrations/*.sql` are not mounted in `compose.prod.yml`. Only `tests/e2e/clickhouse_upgrade.py` and manual documentation steps apply them. | Telemetry schema drifts between hosts, and an upgrade forgets a migration. | An explicit one-shot `clickhouse-migrate` Compose service with a tracked version table, run like `prod-migrate`. Explicit migrations. | S / low | A: `clickhouse_upgrade.py` uses that service |
| A27 | B | **GeoIP refresh and bootstrap.** The updater replaces the file with `mv -f` (`docker/mmdb-updater/entrypoint.sh`), but PowerDNS has it open with `mode=mmap` (`pdns.conf:3`) and is never reloaded (inference). The default source is an unpinned CDN URL, and `MMDB_EXPECTED_SHA256` is empty by default. `pdns-auth` depends on the updater being healthy (`compose.prod.yml:330`). | Geo answers keep using the old database until the next restart. A DNS node with no internet access never starts on first boot. | Signal PowerDNS to reload after the swap (or document a scheduled restart). Ship a bundled fallback database so DNS can start without network access. Require a checksum or signature in production. Runtime DNS stays independent of external services. | S–M / low | A: `phase8_mmdb.py` checks the served answer changes after a swap |
| A28 | H | **Backups cover only the control database, with no retention or integrity check.** `core/docker/backup/create.sh` pipes `pg_dump` into `restic backup`. There is no `forget --keep-*`, `prune`, or `restic check`, and no alert on backup age. `tests/e2e/phase8_recovery.py` (off-host restore) is not in CI. | The repository grows without limit, and corruption is found only during a restore. | Scheduled `forget --prune` with documented retention, a weekly `restic check`, a `cdnfoundry_backup_age_seconds` alert, and the off-host restore in a scheduled CI job. | S–M / low | A: E2E; O: real off-host restore drill |
| A29 | H | **One Valkey instance serves cache, sessions, and queues, with `noeviction`.** `compose.prod.yml:22-24,287` (1 GB, `noeviction`). The password is passed in argv. | When the cache fills Valkey, writes fail, job dispatch stops, and no runtime change goes out. | Separate logical limits: give cache keys TTLs, or run a second, eviction-enabled Valkey for cache. Read the password from a Compose secret file. | S / low | A: E2E fill test |
| A30 | A, G | **Gaps in account security.** Login throttle keyed on IP plus email (`core/app/Providers/AppServiceProvider.php:54`), so password spraying isn't limited. Unknown users skip `Hash::check` (`AuthController.php:21`), so timing reveals which emails exist. `PATCH /me` changes the email with no `current_password` (`:66-68`). `Password::defaults()` is never configured. An admin password reset does not revoke tokens. Edge bootstrap tokens never expire (`Admin/EdgeController.php:31-34`). `trustProxies(at: '*')` (`core/bootstrap/app.php:125`). Extends M12. | Account takeover by spraying. One stolen session can change the account email. A leaked bootstrap token works forever. | Add a per-account limiter and constant-time verification. Require `current_password` to change the email. Set `Password::defaults()` to the admin policy. Revoke tokens on password reset. Give bootstrap tokens a TTL. Trust only the Caddy address. Standard Laravel features only. | S–M / low | A: feature tests |
| A31 | G | **Containers run with few restrictions.** No `no-new-privileges` on any service. `cap_drop` on only 4 services. No memory limits on core, web, horizon, scheduler, control-db, redis, pdns-*, dnsdist, clickhouse, vector, prometheus, or caddy. The PHP-FPM master runs as root (`core/Dockerfile` has no `USER`). The log collector mounts `/var/run/docker.sock` (`compose.prod.yml:469`). | A compromised container has a wider blast radius, and one runaway service can take memory from DNS or the database. | Add an `x-hardening` anchor (`no-new-privileges`, `cap_drop: [ALL]` plus the capabilities each service needs, `read_only` where possible), and memory limits on every service. Use Docker's journald log driver instead of mounting the socket. | M / medium | A: `config-check` policy test; E2E |
| A32 | B | **DNSdist DoS posture.** A global `MaxQPSRule(50000)` drops queries for everyone (`dnsdist.conf:7`). There is no TC fallback, no TCP per-client limits, and no handling for ANY queries or large responses. Only `MaxQPSIPRule(500, 32, 64)` applies per client (`:6`). | A spoofed flood above 50k qps drops legitimate queries for every zone. | Replace the global drop with `TCAction` for over-limit UDP traffic (real clients retry over TCP). Add TCP connection and query limits, and ANY→TC. No claim of volumetric scrubbing. | S / medium | A: dnsperf in dev; O: hardware limits |
| A33 | I | **Development versions differ from production.** `compose.dev.yml` uses ClickHouse 26.3 (production 26.8), Vector 0.55 (0.58), DNSdist 2.1.0 (2.1.2), and PowerDNS 5.1.3 (5.1.4), and 20 development images are pinned by tag only. | The E2E suite qualifies versions that are not shipped. | Pin development images to the production digests from one shared variables file, with a CI check that they are equal. | S / low | A: CI policy check |
| A34 | A | **Bulk DNS work holds the domain lock for a long time.** `core/app/Http/Controllers/DnsRecordController.php:28` (`BULK_LIMIT = 5000`). Each proxied item resolves its origin with a 5 s alarm inside the domain lock and transaction (`:124-145`, `core/app/Support/DnsRecordData.php:76`). | One request can hold the domain lock and a PHP-FPM worker for hours. | Lower the bulk limit for proxied items, or resolve origins before taking the lock, outside the transaction, and recheck at the edge. Bulk work must not starve interactive work. | S–M / low | A: feature test with fake slow DNS |
| A35 | A, C | **Remaining SSRF gaps.** 6to4 `2002::/16`, Teredo `2001::/32`, and IPv4-compatible `::/96` are not blocked (`core/app/Support/NetworkAddress.php:7-13`, `runtime.lua:91-96`). Any port is allowed (`OriginData.php:114`). `testOrigin` accepts any edge id (`ProxyController.php:84`). | Edges can be used to probe or relay to arbitrary ports (for example SMTP on 25). There is an IPv6 transition-address bypass on networks that route 6to4. Domain users can make out-of-pool edges probe for them. | Add the ranges to both lists. Allow only an explicit port set (80, 443, 8080, 8443, plus 1024–65535 except a deny list). Restrict `testOrigin` to edges in the domain's placement. | S / low | A: `tests/e2e/origin_destinations.py` cases |
| A36 | G, I | **Supply-chain coverage gaps.** Provenance is built by hand (`provenance: false` at `.github/workflows/ci.yml:125`, predicate assembled with `jq` at `:653-655`). Dependabot has no `composer`, `npm`, `gomod`, or Compose ecosystems (`.github/dependabot.yml`). There is no govulncheck, PHPStan/Larastan, or CodeQL. | Library CVEs and image digests in Compose files go unnoticed until Trivy scans images. | Use builder-generated provenance (`provenance: mode=max`), add the missing Dependabot ecosystems, govulncheck in the Go job, and Larastan at a pragmatic baseline. | S–M / low | A: CI |

### 3.4 New findings: Low and Info

| ID | Area | Finding and evidence | Change | Effort | Verify |
| --- | --- | --- | --- | --- | --- |
| A37 | A | More endpoints return raw models. `DnsDeploymentController.php:18-20` (`active_rrsets`, PowerDNS `last_error`), `ProxyController.php:104` (placement), `CacheController.php:90` (`CachePurge`). `core/app/Models/DnsCluster.php` encrypts `api_key` but does not hide it. Same root cause as L5. | Use API resources everywhere, and add `$hidden` to every encrypted column as a second safeguard. | S | A: a test that walks every API response for `key`, `pem`, or `secret` |
| A38 | A, K | Inconsistent 202 responses and error codes. The cache endpoints return 202 with no `operation_id` (`CacheController.php:44-73`). Geo-DNS returns 200. 429, 405, and 413 all map to `http_error` (`core/bootstrap/app.php:179-191`). 500 responses have no `code`. Model-not-found messages include class names. 403 vs 404 lets anyone enumerate domain ids (`core/app/Models/Domain.php:25-33`). | Return `operation_id` for every 202. Add stable `rate_limited`, `method_not_allowed`, `payload_too_large`, and `server_error` codes. Return 404 for unauthorized bindings. | S | A: `OpenApiContractTest` plus error tests |
| A39 | A | The purge URL check uses `isset($parts['user'], $parts['pass'], $parts['fragment'])` (`CacheKey.php:13`), which is true only when all three are present. | Reject a URL when any one of them is present. | S | A: unit test |
| A40 | A | Some mutations write no audit entry: DNS deployment reconcile, usage rebuild, domain-user `verify-nameservers`. `usage.rebuild` can't be retried (`OperationController.php:53`). | Add the entries, and allow the retry. | S | A |
| A41 | E | Uploaded chains must include their own root, which most public CAs do not supply, and the uploaded root is trusted (`core/app/Support/UploadedCertificate.php:282-301`). ACME has no handling for 429 or `Retry-After`, gets a fresh nonce for every request (`core/app/Support/AcmeClient.php:150-197`), and `AcmeAccount::firstOrCreate` can race (`:24`). Key matching, validity, names, encryption at rest, renewal jitter, and keeping the last valid certificate are all correct. | Verify chains against the system trust store, with the uploaded intermediates as untrusted. Honor `Retry-After`, reuse `Replay-Nonce`, and lock account creation. | S–M | A: TLS feature tests; `tests/e2e/uploaded_tls.py` |
| A42 | C, D | No fsync for acks, controls, and the plaintext `identity.json` fallback (`edge-agent/main.go:1945-1955`, gateway `atomicWrite`). `gzip_vary off` (`openresty.conf:48`). No OCSP stapling or session-ticket key rotation (`edge-runtime.conf:44-47`). A ClientHello split across TLS records is rejected. | fsync the file and its directory. Turn on `gzip_vary`. Add stapling or document why not. Rotate ticket keys, or disable tickets. | S | A |
| A43 | D | The agent uses one 15 s HTTP timeout for everything, including the 96 MiB full snapshot (`edge-agent/main.go:106,1070`). The loop sleeps a fixed 5 s with no backoff or jitter (`:202`). Requests ignore `context`. | Per-request timeouts, exponential backoff with jitter, and `NewRequestWithContext`. | S | A: Go tests |
| A44 | K | Environment variables drift. Documented but unused: `HOST_BIND_IPV6`, `PDNS_API_KEY`, `CDNF_RELEASE`. Keys that can't be set in production because the fixed `x-core-env` list omits them: `ACME_*`, `TLS_EXPIRY_ALERT_DAYS`, `LOG_LEVEL`, `HORIZON_*`, `SESSION_*`. | Wire or delete each one. Extend `tests/docs/check_links.py`'s environment coverage check to flag keys that are documented but not wired. | S | A |
| A45 | I | Two Trivy classifications in `supply-chain/trivy-classifications.yaml` expire on 2026-11-28. Expiry is enforced by `tests/e2e/trivy_classifications.py`. | Re-assess them and renew or remove them before that date. This is time-sensitive. | S | A: CI |
| A46 | J | Code-quality notes; see the list below this table. | Delete the dead code. Split `edge-agent/main.go` along its sync, activate, and tasks seams, and `runtime.lua` into modules, when the Phase 4 work touches them. Not a standalone refactor. | S–M | A |
| A47 | — | Solid areas; see the list below this table. | Keep them. | — | — |

**A46, code-quality notes:**

- `core/app/Ops/Services/MetricComparisonService.php` is a stateless 23-line class resolved through the container in two widgets; it could be a static helper.
- `$legacyHash` in `IdempotentRequest.php:47` is dead compatibility code, since receipts expire after 24 h.
- `edge-agent/main.go:1220-1256` can never run (L2).
- `fleet-test`, `qualification-tools-check`, and `supply-chain-check` are missing from `.PHONY`.
- Largest files: `ViewDomain.php` 581 lines, `DnsRecordsRelationManager.php` 545, `EdgeAgentController.php` 536, `edge-agent/main.go` 1993, `runtime.lua` 1136.

**A47, solid areas.** Each was checked by reading.

- OpenAPI paths match the routes exactly (194/194), though the schemas are generic.
- `core/tests/TestCase.php:9-24` fails closed.
- Filament `canAccessPanel` and the domain-panel query scoping are correct.
- Mass assignment is not exploitable, because every controller passes validated input.
- The edge re-checks SSRF at connect time against every A/AAAA answer.
- Vector buffers are on disk with `drop_newest`, so a telemetry outage cannot apply backpressure to serving.
- The `control`, `dns-private`, and `telemetry` networks are `internal: true`. Production publishes no database, Valkey, ClickHouse, PowerDNS API, or Prometheus port.
- CI pins actions by SHA, uses least-privilege `permissions`, fails on Trivy HIGH and CRITICAL, and produces SBOMs, cosign signatures, and signed manifests.
- Agent reads are size-limited, redirects are refused, and signatures are verified before the payload is parsed.
- Private keys are encrypted at rest in `tls_certificates`, ACME accounts, orders, and DNS clusters. C2 is the exception.

## 4. Documentation versus code

| # | Where | Documentation says | Code or another document says | Recommendation |
| --- | --- | --- | --- | --- |
| D1 | `README.md` (opening, "Why CDNFoundry") | "production-oriented"; "encrypted custom certificates"; "one lookup/purge key"; "Existing data planes continue from their last valid state"; "redacted DNS and edge events". | The roadmap says "CDNFoundry is not in production yet" and lists C1/C2 (plaintext keys), C3 (a control fault becomes a DNS outage), H5/H6 (purge key and durability), and A24 (full client IPs stored). | Add a status banner to the README that links the roadmap's open critical and high findings, until Phase 2 exits. |
| D2 | `SECURITY.md`, `docs/operations/releases/v1.2.1.md` | 1.2.x is "supported". The known limitations do not mention C1–C4. | C1 (key leak to domain users) is a known, unfixed critical issue in the supported line. | Owner decision: publish an advisory, or a "known security issues" section with an interim mitigation. The mitigation: don't assign domain users to domains that use managed TLS, or restrict `GET /revisions` to administrators until Phase 1. |
| D3 | `AGENTS.md` ("The roadmap is closed") | The staged roadmap is closed. | `docs/roadmap.md` says "Status: active". AGENTS.md's first paragraph also calls the rebuild active. | Reword the "roadmap is closed" sentence to refer to the archived staged roadmap only. |
| D4 | Commit `d4c4fb7` message vs. `AGENTS.md`, `CONTRIBUTING.md:19-21`, `.github/pull_request_template.md`, `docs/manual-browser-qualification.md:8`, `README.md`, skills `cdnf-filament-ui` and `cdnf-production-qualification` | The commit message says "browser qualification is agent-run". | Every governing file still says browser qualification is manual and owner-run, and forbids agents from running it. The roadmap schedules the change for Phase 6. | Under AGENTS.md, owner-run stays in force until Phase 6 amends it. This audit treats it that way. Fix the commit-message ambiguity in the next roadmap edit. |
| D5 | `README.md` badges | PHP 8.3+, Go 1.24+. | `core/composer.json` (`^8.3`) and `go.mod` (`go 1.24`) agree. CI uses PHP 8.4 and Go 1.26.8. The images use PHP 8.5 and Go 1.26.10. | No real conflict. State the build and test versions in the release notes. |
| D6 | Geo-DNS guide (`docs/guides/geo-dns.md`) | Country and continent rules. | The rules don't work for resolvers that send no ECS (A6). | Fix A6, then document the ECS and source-address behavior. |
| D7 | Emergency controls (operator docs and the API) | Five emergency actions. | They have no effect (A19). | Remove them or implement them. |
| D8 | `.env.prod.example`, `docs/deployment/manual-compose.md` | `PDNS_API_KEY`, `HOST_BIND_IPV6`. | Not wired in `compose.prod.yml` (A10, A44, H8). | Wire them or delete them. |
| D9 | `docs/roadmap.md` line references | H1 at `ReconcileEdgeDomain.php:79-83`; M13 at `create_domains_tables.php:23`. | H1 is now at `:64-67,83-87`. M13 is now a partial index in `2026_07_18_050000_add_domain_reclaim_state.php`. | Update the references when the A-items are added to the register. |
| D10 | OpenAPI (`docs/public/openapi.json`) | 194 operations. | It matches `core/routes/api.php` exactly, but request and response schemas are generic (`additionalProperties: true`), so field-level drift such as C1 can't be detected. | Add response schemas for the resources that carry secrets first: revisions, certificates, DNS clusters. |

## 5. Test and CI gap analysis

### Current coverage (observed)

- **Laravel:** 368 feature and unit tests, 12790 assertions, all passing (section 8), on isolated in-memory SQLite only. PostgreSQL-specific behavior is never exercised: advisory locks (`IdempotentRequest.php:37-42`, `Domain::lockCanonicalName`), CHECK constraints, partial unique indexes, and JSONB `input->domain_id` queries.
- **Go:** gofmt, vet, and tests pass for `edge-agent` and `edge-gateway`. The gateway tests cover parsing and scale, but not `handle()` (A2, A18). The agent tests don't cover the rejection paths in `sync()` (C4), `flushAcks` against a 422, tombstone and replay checks (A22), or `verifyDelivery` expiry bounds.
- **Lua:** no unit tests. Cache key, WAF exclusions, XFF parsing, and Host/SNI behavior are covered only by E2E, if at all.
- **E2E in CI** (`make dev-e2e`, plus the compose and scale jobs): DNS, Geo-DNS defaults, mTLS, runtime, TLS, security, analytics, operations including an isolated PostgreSQL restore, WAF, origin failover, scale, and the image, ingress, and Vector checks.
- **E2E not in CI:**
  - `phase8_recovery.py`, `phase8_upgrade.py`, `phase8_throughput.py`, `phase8_mmdb.py`
  - `edge_purge_protocol.py`, `gateway_ingress.py`, `cell_inventory.py`, `grafana_observability.py`, `operational_logging.py`
  - the six `staging_*` scripts
- **IPv6:** tested only over development loopback (`phase2_dns.py` uses `dig @::1`) and in origin fixtures. Production IPv6 publication is not tested.
- **Browser:** no browser automation in the repository. Manual browser qualification is owner-run and was not run.

### Gaps against the AGENTS.md test requirements

| Area | Happy | Permissions | Validation | Idempotency | Retry/failure | Rollback/last-valid | IPv4/IPv6 | Real runtime |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| Domains, claims | ✓ | ✓ | ✓ | ✓ | ✓ | partial | n/a | ✓ |
| DNS records, zones | ✓ | ✓ | ✓ | ✓ | **missing** (A15, H7) | ✓ | partial (loopback v6) | ✓ |
| Geo-DNS | ✓ | ✓ | ✓ | partial | partial | ✓ | partial | **missing override test** (A6) |
| Edge delivery | ✓ | ✓ | ✓ | ✓ | **missing** (C4, A12) | ✓ | partial | ✓ |
| Origins, SSRF | ✓ | ✓ | ✓ | ✓ | ✓ | partial (M1) | ✓ | partial (A3, A35) |
| Cache, purge | ✓ | ✓ | ✓ | partial (A11) | **missing** (H6) | n/a | partial | **missing key fixture** (H5, A5) |
| TLS | ✓ | **missing secret-leak test** (C1) | ✓ | ✓ | partial (A41) | ✓ | partial | ✓ |
| WAF, security | ✓ | ✓ | ✓ | ✓ | partial | ✓ | partial | **missing bypass test** (A4, A16, A17) |
| Telemetry | ✓ | **missing tenant isolation** (H9) | partial | n/a | ✓ | n/a | partial | **missing forged-ingest test** (A23) |
| Gateway | partial | n/a | ✓ | n/a | **missing** (A2, A18) | n/a | partial | partial |
| Operations, backup | ✓ | ✓ | ✓ | ✓ | partial (A9) | ✓ (local restore) | n/a | **missing off-host in CI** (A28) |

### Proposed additions, in priority order

All of these are agent-owned unless marked otherwise.

1. **Secret-leak sweep.** A feature test that calls every API GET as a domain user and as an administrator, and fails if any response contains PEM blocks, `api_key`, `private_key`, or `secret` (C1, A37).
2. **Shared cache-key fixture.** One JSON file of URL → key vectors, run by the PHP unit tests and by a Lua harness in the cell image (H5, A5, M9).
3. **Gateway proxying tests.** A body in the same write as the headers, pipelined requests, idle timeouts, and half-close (A2, A18).
4. **Agent wedge tests.** A rejected artifact followed by a good one (C4), a 422 in the ack queue, and a tampered manifest (A22).
5. **Edge identity checks.** Real client address seen by the origin (A3), forged XFF and PROXY headers (A17), and SNI/Host mismatch (A16).
6. **WAF bypass E2E** (A4).
7. **PostgreSQL contract test.** Run the migrations on a throwaway PostgreSQL database that the test creates and drops itself, never the development volume. Exercise the advisory locks, partial unique indexes, and CHECK constraints.
   - This needs an explicit AGENTS.md-compatible design: it must not use `RefreshDatabase` against the persistent development database. A separate ephemeral container satisfies that.
8. **Chaos test for C3.** Stop edge-control and assert that the platform zone keeps its endpoints.
9. **CI hygiene.**
   - Scheduled weekly job: `phase8_recovery.py`, `phase8_upgrade.py`, `edge_purge_protocol.py`.
   - Development and production version-parity check (A33).
   - govulncheck and Larastan (A36).
   - A check that `retry_after` exceeds the job timeouts (A9).
10. **Owner-run checks.** Real IPv6 resolution, Anycast, load limits on target hardware, and the manual browser checklist.

**Test isolation safety (confirmed).**

- `core/tests/TestCase.php:9-24` throws before any migration unless the environment is `testing`, the default connection is `sqlite`, and the database is `:memory:`.
- `core/phpunit.xml` forces SQLite and `:memory:`.
- `compose.test.yml` forces the same values, and uses tmpfs for storage.
- I confirmed the effective configuration inside the container (`testing|sqlite|:memory:`) before running the suite.

## 6. Prioritized improvement roadmap

Each item is one PR. Items map onto the existing roadmap phases. "Pull forward" means I recommend doing an item earlier than its phase.

### Now: security and data loss

| # | Change | Fixes | Phase | Effort | Depends on |
| --- | --- | --- | --- | --- | --- |
| N1 | Revisions (and every raw-model endpoint) through API resources with explicit field lists; `$hidden` on encrypted columns; the secret-leak sweep test. | C1, L5, A37 | 1 | S | — |
| N2 | Make WAF exclusions rule-scoped, or reject new exclusions with a stable 409 until that is done. | A4 | pull forward (3) | M | — |
| N3 | ClickHouse ingest, query, and administrator users; a separate ingest secret for each node. | A7 | pull forward (5) | S–M | — |
| N4 | Template `pdns.conf` secrets in the manual production path; fail if development values are present. | A10 | pull forward (5) | S | — |
| N5 | Remove private keys from snapshots and artifacts (key endpoint encrypted to the edge identity). | C2 | 1 | L | N1 |
| N6 | DNS telemetry `domain_id` set at the source, authenticated Vector sources, `domain_id` derived at the cell. | H9, A23 | 1 | M | N3 |
| N7 | Cache-key specification and shared fixture; collision handling; argument limit. | A5, H5, M9 | pull forward (3) | M | — |
| N8 | Interim security notice in SECURITY.md and the release notes, plus a README status banner. | D1, D2 | owner decision | S | — |
| N9 | Rollback re-validation, and refusing a partial rollback. | M1 | 1 | M | — |

### Next: reliability and operability

| # | Change | Fixes | Phase | Effort | Depends on |
| --- | --- | --- | --- | --- | --- |
| R1 | Alertmanager receivers, a `Watchdog` alert, and refusing an empty route; alerts for empty pools and backup age. | A8, part of C3, A28 | pull forward (5) | S | — |
| R2 | `retry_after` above all timeouts, plus a configuration assertion. | A9 | pull forward (4) | S | — |
| R3 | Gateway forwards buffered bytes; idle timeouts and half-close. | A2, A18 | pull forward (4) | S | — |
| R4 | Real client address on the inner hop; PROXY trust limited to the gateway; XFF policy. | A3, A17 | pull forward (5) | S | — |
| R5 | Edge identity renewal, plus an expiry alert. | A1 | add to 2 | M | — |
| R6 | Fail static: never publish an empty pool; withdraw only on a positive signal. | C3, M3 | 2 | M | R1 |
| R7 | Agent applies artifacts per domain; one shared rejection-reason list with a contract test. | C4 | 2 | M | — |
| R8 | Quorum promotion, cursor-paged promotion, and excluding withdrawn and non-shared pools from automatic placement. | H3, A12, A13, H1 | 2–3 | M | R7 |
| R9 | Proxied records always render (default pool before activation); WAF pool check returns 409. | H4, H2 | 2–3 | S–M | — |
| R10 | Idempotency storage rules and middleware order. | A11 | pull forward | S | — |
| R11 | Health checks for edge-control, horizon, scheduler, edge-agent, and edge-gateway. | M11 | pull forward (5) | S | — |
| R12 | DNSdist `useClientSubnet`, a Geo-DNS override E2E, and the DNSdist TC fallback and TCP limits. | A6, A32 | pull forward | S | — |
| R13 | Operation visibility, a partial unique index, DNS operation status, and a per-domain lock. | A14, A15, H7 | 3 | M | — |
| R14 | Backup retention, `restic check`, scheduled off-host restore in CI; ClickHouse migration service; a separate Valkey for cache; GeoIP reload and bundled fallback. | A28, A26, A29, A27 | 5 | M | R1 |
| R15 | Renew or remove the Trivy classifications before 2026-11-28. | A45 | — | S | — |
| R16 | Durable URL purges. | H6 | 3 | M | N7 |

### Later: performance and features

- **Phase 4 scale work:**
  - Items: M2, M4, M5, M6, M7, M8, M10.
  - Add to it: A20 (per-client handshake and connection limits), A21 (temp storage and worker sizing), A22 (signed manifest fields and key rotation), A25 (bounded DNS rollup cardinality), A34 (bulk DNS off the lock), A43 (agent timeouts and backoff).
  - Exit with a load test at 10,000 domains, as the roadmap already says.
- **Phase 5 hardening:**
  - Items: M12, H8, L6.
  - Add to it: A16 (SNI/Host binding), A30 (account security), A31 (container hardening), A35 (SSRF gaps), A36 (supply chain), A41 (TLS upload and ACME), A42 (fsync and TLS details).
- **Phase 7 cleanup:** A38–A40, A44, A46.
- **Product gaps that fit the invariants:**
  - token abilities and per-domain token scope, plus administrator TOTP (Filament MFA) (M12)
  - tenant quotas (domains, records, purges per minute, WAF exclusions) as typed `users`/`domains` columns enforced in form requests
  - an audit-log export endpoint (cursor-paginated, bounded)
  - log delivery as bounded, time-boxed ClickHouse exports exposed as 202 operations
  - origin shielding as a pool-level setting, where a designated PoP cell fetches from the origin, reusing the existing cell runtime with no new service
- **Rejected as out of scope:**
  - per-domain log streaming processes (violates the no-per-domain-process rule)
  - a separate analytics service (adds a microservice)
  - Kubernetes-based scaling (not a requirement)

**Roadmap merge (done 2026-10-30).** The [roadmap](../roadmap.md) now holds these findings and is the single register. This page stays as the evidence for each A item.

- A1–A46 are in the roadmap's finding tables, under their A ids. A1 is listed as Critical, because it is a guaranteed, dated trigger for C3.
- Every A item is assigned to a phase. Most items in the Now and Next lists above landed in the earliest phase that matches their risk:
  - Phase 1: A4, A7, A10, A16, A23, A24, A37.
  - Phase 2: A1, A2, A3, A8, A9, A11, A12, A13, A17, and M11, which moved there from Phase 5.
- A45 and the public-statement fixes (D1, D2) are in the roadmap's "Before Phase 1" list.
- The stale references (D9) are fixed.
- The AGENTS.md wording (D3) is part of the Phase 6 AGENTS.md amendment.

## 7. Not verified, and what the owner must check

| Item | Why I could not verify it | Owner action |
| --- | --- | --- |
| ECS and Geo-DNS behavior at real public resolvers (A6) | Needs real delegation and public resolvers. | After R12, query from resolvers in at least two countries. |
| Anycast and BGP, public IPv6 reachability of DNS, control, and edges (H8) | Needs real network infrastructure. | Production qualification, IPv6 section. |
| Load ceilings: cell CPU at 10k domains (M6), gateway slot exhaustion (A18), DNSdist QPS (A32), and the realistic ceiling of the minimum topology | Needs hardware and load generators; `phase8_throughput.py` was not run. | Run the throughput qualification on target hardware. |
| Behavior under disk pressure (cache volume full, ClickHouse disk full) | Needs a running stack with constrained volumes. | Fill test on staging. |
| Whether OpenResty's keepalive pool keeps verified and unverified TLS origin connections apart | Depends on how OpenResty builds the pool key; not reproduced. | Agent-owned follow-up: an E2E with two tenants on the same origin IP, SNI, and port, with different `verify_tls`. |
| Whether PowerDNS picks up a replaced mmap'd GeoIP file without a restart (A27) | Not reproduced. | Agent-owned follow-up in `phase8_mmdb.py`. |
| Whether TLS session resumption skips the Lua certificate and expiry checks | Not reproduced. | Agent-owned E2E follow-up. |
| Off-host Restic restore to a clean host (A28) | `phase8_recovery.py` not run. | Owner-run recovery drill with real S3. |
| Filament UI behavior for any UI-affecting fix | Browser automation is forbidden for agents by AGENTS.md. | [Manual browser checklist](https://github.com/vaheed/CDNFoundry/blob/dev/docs/manual-browser-qualification.md): **not run**, owner-run. |
| Production traffic patterns, tenant behavior, registrar delegation at scale | No production. | Production qualification. |
| The agent-owned real-runtime suite (`make dev-e2e` and the related targets) | Not run in this audit, because it needs the full dev stack and changes dev database state. | Agent-owned. Run it in the PR for each fix. |

## 8. Execution log

All commands ran from the repository root on 2026-10-30, on Docker 29.1.3.

| Command | Result |
| --- | --- |
| `make version-check` | **Passed.** `edge-agent/main.go` and `edge-gateway/main.go` report 1.2.1. |
| `make check-no-screenshots` | **Passed** (exit 0). |
| `make config-check` | **Passed.** Dev, test, and prod-example `config --quiet` succeeded; `production_env_generator=passed`; `production_compose=passed`. |
| `bash scripts/qualification/test-go.sh` (gofmt, `go vet`, `go test`, `go build` in pinned `golang:1.26.10-alpine`, read-only bind mount) | **Passed.** `ok cdnfoundry/edge-agent 1.530s`, `ok cdnfoundry/edge-gateway 8.159s`. |
| `docker compose -f compose.dev.yml run --rm --no-deps vendor-init` | **Passed** (exit 0). |
| Effective test environment: `php -r 'echo getenv(...)'` and `php artisan tinker --execute='...config(...)'` in the test container | **Confirmed** `testing\|sqlite\|:memory:` before running any test. |
| `make dev-test` (isolated SQLite suite) | **Passed.** `Tests: 368 passed (12790 assertions)`, duration 89.17 s. |
| `make openapi-check` | **Passed.** "OpenAPI contract and endpoint catalog are current." |
| `vendor/bin/pint --test` (test container) | **Passed.** 368 files. |
| `python3 -m unittest tests/observability/test_grafana_contract.py tests/observability/test_operational_logging_contract.py` (the unittest part of `make contract-check`) | **Passed.** Ran 20 tests, OK. |
| `make fleet-test` | **Passed.** 84 passed in 89.12 s. |
| `make supply-chain-check` (includes `qualification-tools-check`) | **Passed.** unittest OK; `supply_chain_policy=passed dockerfiles=6 workflows=2`. The line "release decision: failed" comes from `tests/qualification/test_evidence.py`, which checks that failed evidence stays non-passing. It is expected. |
| `python3 -m compileall -q tests scripts/cdnfoundry_fleet scripts/ci` | **Passed** (exit 0). |
| `make docs-check` (with this report present) | **Passed on the third run.** `documentation_validation=passed documents=100`, the lint passed, the VitePress build completed, and `built_site_link_validation=passed pages=94 internal_links=3595`. The first run failed on markdownlint errors in this report (inline `<br>`, unescaped pipes, bold text used as a heading). The second failed on two VitePress dead links to the build-excluded `manual-browser-qualification.md`. I fixed both in this report only. |

**Not executed:**

- `make dev-e2e` and every other `dev-*-e2e` / `dev-*-smoke` target. They need the full dev stack and change dev database state.
- `make dev-production-qualification`, the `staging_*` scripts, and the `prod-*` targets. They need real hosts.
- Any `vector test`, `promtool`, or `amtool` run outside `make` targets. These run in CI only.
- Any browser automation. Forbidden by AGENTS.md; the manual browser checklist is owner-run and was not run.
- Load and chaos tests.

**Read-only analysis commands** (`grep`, `sed`, `wc`, `git log`, `git show`) were also used to gather evidence. No file other than this report was created or modified.

## 9. The ten changes to make first

1. **N1: revisions and raw models through API resources, plus the secret-leak sweep.** It stops an active critical key leak with an S-sized change and adds the test that would have caught it.
2. **N2: rule-scoped WAF exclusions** (or a 409 until then). Today, visitors can trigger a WAF bypass themselves.
3. **N3: split the ClickHouse users.** One compromised PoP should not expose every tenant's logs.
4. **N4: template the PowerDNS secrets.** A public API key in the manual production path is a zone-takeover risk.
5. **R1: Alertmanager receivers and `Watchdog`.** Every other operational fix depends on alerts actually reaching someone.
6. **R2: `retry_after` above the timeouts.** A one-line change that stops duplicate backups and false operation failures.
7. **R3: the gateway forwards buffered bytes.** It fixes intermittent POST failures on port 80 for every domain.
8. **R4: the real client address on the inner hop.** Every origin's access control and logging currently sees `127.0.0.1`.
9. **R5: edge identity renewal.** It defuses the one-year deadline that turns C3 into a fleet-wide outage.
10. **R6: fail static for pool endpoints (C3).** Together with R5, a control-plane fault can no longer take every domain off DNS.

Items 1–8 are each S or M and independent, so they can go out in parallel PRs. Items 9 and 10 are M, and together they close the largest outage path. N5 (C2, keys out of PostgreSQL JSON) follows straight after, because it depends on N1 and is L-sized.
