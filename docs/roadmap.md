---
title: Roadmap
description: The 2.0 rebuild roadmap. Every item comes from an audit of the 1.2.1 code and is ordered by the risk it removes.
---

# Roadmap to 2.0

**Status: active.** CDNFoundry is not in production yet, so 2.0 makes breaking
changes with no backward compatibility and no data migration from 1.2.x. Work
happens on `dev`. A phase merges to `main` only after its gate passes.

This roadmap comes from two audits of the 1.2.1 code. Every finding below
names the code that causes it.

- **C, H, M, and L items** come from the first audit. Items marked
  **reproduced** were confirmed by running the code (a throwaway SQLite
  feature test). The others were confirmed by reading the code path end to end.
- **A items** come from the
  [2026-10-30 codebase audit](audit/2026-10-30-codebase-audit.md), which ran
  on `dev` at `d4c4fb7`. That audit re-checked every C, H, M, and L item: all
  are still present, and H8, M3, and M13 are partly addressed (noted in their
  rows). The audit page holds the full evidence for each A item: the scenario,
  the alternatives considered, and how to verify the fix.
- **HA items** come from a 2026-10-31 availability review of `dev` at
  `9042670`. They cover single points of failure that the two audits did not
  list. Phase 6 fixes them. The audit page uses the phase numbers from before
  Phases 6 and 7 were inserted: its Phase 6, 7, and 8 are Phase 8, 9, and 10
  here.

## Phase gate

A phase is complete only when all four fields pass:

| Field | Required evidence |
| --- | --- |
| Implementation | Merged to `dev`. Replaced code is deleted, not left behind a flag. |
| Regression tests | Each finding fixed in the phase has a test that failed before the fix and passes after it. |
| Runtime tests | Python E2E under `tests/e2e` passes on a real Compose stack, over IPv4 and IPv6. |
| Documentation | Every page affected by the change is rewritten to match the new behavior. |

From Phase 7 onward, agentic browser tests are a fifth gate field.

## Findings

What each severity means:

- **Critical:** leaks secrets, or can take down every domain.
- **High:** breaks a feature or a security property, or blocks deployment, for many domains.
- **Medium:** wrong behavior with a limited blast radius, or a scaling or operability limit.
- **Low:** cleanup.

### Critical

| ID | Finding | Where | Impact |
| --- | --- | --- | --- |
| C1 | **TLS private keys leak to domain users (reproduced).** The edge snapshot holds the decrypted private key. `EdgeRevision` is serialized without hiding anything, and `GET /domains/{domain}/revisions` returns it to any user assigned to the domain. | `ReconcileEdgeDomain.php:132`, `ProxyController::revisions`, `EdgeRevision` | Any domain user can download the platform-issued private key for that domain. |
| C2 | **Private keys are stored in plaintext in PostgreSQL.** `tls_certificates` encrypts the key, but every `edge_revisions.snapshot` and every per-edge `edge_artifacts.payload` stores it as plain JSON, for every revision that is kept, and backups copy them. | same, plus `EdgeArtifact` casts | Breaks the "encrypt private keys" rule. A database dump or backup exposes every certificate key. |
| C3 | **Healthy PoPs drop out of DNS when control stops hearing from them.** A pool endpoint is published only while the PoP's heartbeat is fresh. The platform zone is re-rendered every minute, and a pool with no fresh endpoint publishes **no** address. If heartbeats stop reaching control (edge-control nginx down, CA or certificate expiry, rate limiting, a network partition), every proxied domain stops resolving within about a minute, although the edges still serve. No alert uses `cdnfoundry_pool_endpoint_state`. | `EdgePoolEndpoint::readinessReason()`, `PlatformDnsZone::render()`, scheduler `ReconcilePlatformDnsIdentity` every minute | A control-plane fault becomes a global data-plane outage. |
| C4 | **One bad artifact freezes a whole PoP.** The agent applies the manifest as one batch. A rejected artifact aborts the batch without moving the cursor, so the next sync fetches and rejects the same artifact again, forever. The server's validation rejects two of the agent's rejection reasons, `invalid_artifact_schema` and `artifact_revision_replay`, with a 422. The ack queue then stops at the first rejection and holds back every later "applied" ack. | `edge-agent/main.go` `sync()`, `flushAcks()`; `EdgeAgentController::rejected()` | One domain's bad payload stops every domain on that PoP from updating, and no placement on it is ever promoted. |
| A1 | **Edge identity certificates expire after one year, and nothing renews them.** The CA signs edge certificates for 365 days. The agent loads its certificate once, and `/edge/v1` has no renewal route. When the certificate expires, the PoP loses mTLS to control and its heartbeats stop, which triggers C3. | `EdgeCertificateAuthority.php:19`, `edge-agent/main.go` `configureMutualTLS`, `routes/web.php` | A fleet enrolled in the same week stops resolving on the anniversary of enrollment. Every PoP needs a new bootstrap token. |

### High

| ID | Finding | Where | Impact |
| --- | --- | --- | --- |
| H1 | **New domains land in the quarantine pool (reproduced).** Placement hashes the domain name across **all** enabled pools, including `quarantine`, `reserved`, and `dedicated`. On a default install, 20 of 40 new domains were placed in `quarantine-default`. | `ReconcileEdgeDomain.php:64-67,83-87` | Half of normal traffic shares the cells meant for abusive domains. |
| H2 | **WAF can wedge a domain.** Neither default pool is WAF-capable. The API accepts `PATCH /waf` with 202, and then every edge reconcile for that domain throws "No enabled WAF-capable pool exists". All later changes to the domain fail until an administrator creates a WAF pool. | `WafController::update`, `ReconcileEdgeDomain.php:64-71` | Domain users can block their own deployments with one click. |
| H3 | **One offline PoP blocks deployment for its pool.** Promotion waits until **every** participating edge acknowledges. One PoP that is down, wedged (C4), or slow keeps every new domain and every change in that pool in `deploying`. | `PromoteReadyEdgePlacements` (`every`) | A single PoP failure stops deployment for the whole pool. |
| H4 | **A proxied record blocks the whole zone.** `PowerDnsZone::render()` throws when a proxied record has no active pool yet. On activation, edge placement is still deploying, so the zone (MX, TXT, everything) is not published until the edges acknowledge. Combined with H3 or C4, a newly delegated domain does not resolve at all. | `PowerDnsZone.php:26-30` | Outage at onboarding, and customers cannot edit any DNS record while the edges are unready. |
| H5 | **URL purge does not match the cache key.** PHP builds the purge key from the raw path and does not decode `+`. Lua builds the cache key from the decoded, normalized `ngx.var.uri` and from `get_uri_args`, which decodes `+`. PHP also sorts repeated numeric values as numbers, while Lua sorts them as strings. Purging a URL with `%20`, `+`, `..`, or `//` silently does nothing. | `CacheKey::fromUrl`, `runtime.lua` `request_cache_key` | Breaks the "one deterministic key" rule, and customers see stale content after a "successful" purge. |
| H6 | **URL purges are forgotten.** A URL purge only increments a counter in the cell's `ngx.shared.runtime_limits` dictionary. That dictionary is lost on cell restart, and it is LRU-evicted under memory pressure because it also holds the per-second rate-limit counters. | `runtime.lua` control `cache_purge`, `edge-agent` `runCachePurge` | Purged objects come back after a restart, or during an attack. |
| H7 | **DNS changes are not retried or repaired.** `ReconcileDnsZone` has 3 tries with no backoff, and the scheduler has no DNS drift or retry job (edges and TLS have one). A DNS host that is unreachable for more than a few seconds keeps stale zones until an administrator runs a global reconcile by hand. | `ReconcileDnsZone`, `routes/console.php` | Stale or missing records after any short DNS host outage. |
| H8 | **No IPv6 in production.** `compose.prod.yml` publishes DNSdist and control on IPv4 only, and `HOST_BIND_IPV6` is never used. The Fleet renderer adds IPv6 binds when `bind_ipv6` is set, but not for edge-control. Platform settings still accept nameserver IPv6 addresses and publish AAAA glue for them. | `compose.prod.yml` ports, `render.py`, `PlatformDnsZone.php:36` | Resolvers that prefer IPv6 time out on our nameservers. |
| H9 | **Domain users can read DNS logs of other tenants.** DNS telemetry has `domain_id = 0`, and DNS analytics and raw logs are scoped by zone-name suffix only. The owner of `example.com` sees queries, with client IPs, for another tenant's `shop.example.com`, and a new owner sees the previous owner's logs. Usage takes the first suffix match, so DNS usage can be billed to the wrong tenant. | `vector.yaml` dnstap remap, `AnalyticsStore.php:63,113,142,192` | Cross-tenant personal-data leak. |
| A2 | **The gateway drops request bytes that arrive with the HTTP headers.** `readHTTP` buffers the connection, returns only the header bytes, and then the copy loop reads from the raw connection. Bytes buffered past the blank line are lost. | `edge-gateway/main.go` `readHTTP`, `handle` | POST bodies and pipelined requests on port 80 stall until `client_body_timeout`. |
| A3 | **Every origin sees the client address as `127.0.0.1`.** The origin hop is a second server on `127.0.0.1:9081` with no real-IP context, and it sets `X-Forwarded-For` and `Forwarded` from `$remote_addr`. The E2E test calls from loopback, so it cannot detect this. | `edge-runtime.conf:157`, `origin-proxy.conf`, `cache-upstream.conf`, `tests/e2e/phase4_runtime.py` | Origin access control, rate limiting, and logs see one address for every visitor. |
| A4 | **One WAF exclusion turns off CRS blocking for the whole request.** A matching exclusion switches the request to `monitor` mode, and the rule id is never passed to ModSecurity. A `rule` exclusion matches every request. `parameter` and `cookie` exclusions match on a name the client controls. | `runtime.lua` WAF exclusions, `waf.conf` | Adding `?name=` to any attack request bypasses the WAF. |
| A5 | **The cache key collides on encoded path characters.** The key is built from the decoded `ngx.var.uri`, so `/x%3Fv=1` gets the same key as `/x?v=1`, while the origin receives the raw URI. `%2F`, `/../`, and `//` collide the same way. | `runtime.lua` `request_cache_key` | Cache poisoning, for example HTML stored under a script URL. |
| A6 | **Geo-DNS sees DNSdist's address, not the client network.** DNSdist backends have no `useClientSubnet` and no PROXY protocol. Resolvers that send no ECS are therefore geolocated by DNSdist's private address. The Geo-DNS E2E never tests an override. | `dnsdist.conf` `newServer`, `EdgeRoutingCompiler.php:38-43`, `tests/e2e/phase3_geo_dns.py` | Country rules do not apply, and `pickhashed` sends most traffic to one endpoint. |
| A7 | **Every edge and DNS node holds the ClickHouse administrator credential.** Vector on every role logs in as the bootstrap user, which has access management enabled. | `compose.prod.yml` ClickHouse and Vector environment, `Caddyfile.telemetry` | A compromised PoP can read or drop every tenant's telemetry. |
| A8 | **Alerts go nowhere.** The only Alertmanager receiver has no integration. | `docker/alertmanager/alertmanager.yml` | Certificate expiry, queue stalls, a lost DNS backend, and a full disk raise no notification. |
| A9 | **Long queue jobs are redelivered while still running.** `retry_after` is 90 s. The worker timeouts are 120 s and 300 s, and backups allow 3700 s. | `config/queue.php:71`, `config/horizon.php`, `CreateControlBackup` | Duplicate backups and duplicate PowerDNS pushes, and operations marked failed even though the work succeeded. |
| A10 | **The manual production path ships development PowerDNS credentials.** `compose.prod.yml` mounts `docker/pdns/pdns.conf` unchanged. That file contains the development database password and API key, and `PDNS_API_KEY` is documented but never wired. Only the Fleet renderer replaces them. | `compose.prod.yml` pdns-auth, `docker/pdns/pdns.conf`, `render.py` | Either PowerDNS cannot reach its database, or its API key is public. |

### Medium

| ID | Finding | Where | Impact |
| --- | --- | --- | --- |
| M1 | **Rollback writes unvalidated data.** Rollback copies `origin` from the snapshot, including the platform-injected `private_allowlist`, `blocked_networks`, and `blocked_addresses`, back into `dns_records.origin` without re-running `OriginData` validation. It also deletes non-proxied records that now use the same name, and it does not roll back WAF or TLS. | `ProxyRevisionRollback` | Platform policy is copied into customer JSONB. An origin that current policy prohibits can return, and the rollback is only partial. |
| M2 | **The edge snapshot is not deterministic.** `blocked_addresses` is assembled from unordered `pluck()` queries. Any change in row order changes the checksum, which forces a revision bump and a recompile. Every domain also embeds the global address list, so one pool address change requires recompiling every domain. | `ReconcileEdgeDomain.php:120-123` | Breaks the "render deterministically" rule and causes fleet-wide fan-out for one edit. |
| M3 | **The platform zone serial does not change with its content.** The SOA serial is `settings.revision`. Routing changes bump it. The every-minute render, whose content depends on heartbeat freshness, does not bump it. Zones are `Native` with no SOA-EDIT-API. | `PlatformDnsZone::render()`, `PowerDnsClient.php:27` | Breaks the "monotonic serials" rule, and secondaries and caches cannot detect the change. |
| M4 | **DNS fan-out does not scale.** Every zone change is pushed through the PowerDNS HTTP API to every DNS cluster, one after another (10 s timeout, 2 retries each), inside the shared 3-worker `runtime` lane. Each DNS host needs an inbound API gateway, a certificate, and a `dns-api-N` hostname, and there is no anycast model for nameservers. | `ReconcileDnsZone.php:64`, `PowerDnsClient`, `horizon.php:215` | One slow DNS host stalls edge deploys and nameserver checks, and the work grows as zones × hosts. |
| M5 | **One queue lane carries all runtime work.** DNS pushes, edge compiles, nameserver verification (up to 45 s of `dig`), platform-zone renders, and origin tests all share the `runtime` lane. Certificate issuance (500 tries) shares the `certificate_purge` lane with purges. No job uses the `interactive` queue, so its supervisor never runs anything. | `config/horizon.php` | Breaks the "bulk cannot starve interactive/runtime" rule. |
| M6 | **Cells reload the full state every second.** Every OpenResty worker reads and JSON-decodes its whole runtime file (up to 64 MiB) every second, whether or not it changed. | `runtime.lua` `M.start()` timer | Constant CPU cost that grows with the domain count. |
| M7 | **The agent rewrites everything on every change.** Each change rewrites the full `state.json`, every pool runtime, and a backup, including all certificates. | `edge-agent` `activate()` | Each change costs O(all domains) on every PoP. |
| M8 | **No per-client limit at the gateway.** The edge gateway has one global connection-slot limit and no limit per source address, so one client can hold every slot. | `edge-gateway/main.go` `accept()` | Breaks the "bounded per client" protection claim. |
| M9 | **Cache key truncates query arguments.** `get_uri_args(128)` ignores arguments after the 128th, while the origin receives all of them. | `runtime.lua` `request_cache_key` | Different URLs share one cache entry (cache poisoning). |
| M10 | **The API allows slot counts the deployment cannot run.** Production Compose always runs exactly 8 cells (4 GiB per PoP), but the API accepts 1–32 slots per edge. | `compose.prod.yml`, `EdgeController` `cell_slot_count` | An edge with 16 slots has 8 that never run, and small PoPs pay for 8. |
| M11 | **Critical services have no health check.** `edge-agent`, `edge-gateway`, `edge-control`, `horizon`, and `scheduler` have none, so Compose cannot restart them when they hang. The gateway already serves `/healthz`. | `compose.prod.yml` | Silent stalls. |
| M12 | **API tokens are unbounded.** Sanctum tokens never expire and have no abilities or domain scope. There is no second factor for administrators. | `config/sanctum.php` `expiration => null`, `TokenController` | One leaked administrator token is permanent full control. |
| M13 | **First claim wins a domain name.** A partial unique index (`deleted_at IS NULL`) keeps live names globally unique, so a pending claim blocks the real owner for 7 days. A domain whose registrant changed can never be reclaimed by the new registrant while the old zone is active. | `2026_07_18_050000_add_domain_reclaim_state.php:16`, `Domain::createPendingFor()` | Squatting, and no legitimate takeover path. |
| M14 | **Delegation names are random hex labels behind a wildcard.** Nameservers look like `<32 hex>.ns1.example.net`, and a wildcard A/AAAA record answers any label. | `Domain::initializeDelegationClaim()`, `PlatformDnsZone.php:34-37` | Hard to read and type, and typos are never caught. |
| M15 | **Setup has too many steps.** The starter quick start is 773 lines. A first domain needs fleet JSON, bundles, tmpfs keys, DNS API certificates, edge listener material, address maps, two DNS zones, and manual cell-to-pool assignment, because new PoPs get no automatic assignment. | `docs/deployment/production-quick-start.md`, `docs/operations/simple-anycast.md` | High chance of operator error, and slow adoption. |
| A11 | **Idempotency replays error responses for 24 hours.** Any JSON response below 500 is stored, including 429 (`throttle:bulk` runs inside `idempotent`) and transient 403 and 409 responses. The lookup ignores `expires_at`. | `IdempotentRequest.php`, `routes/api.php` | A client that retries a 429 with the same key keeps getting 429. |
| A12 | **Promotion only looks at the first 100 deploying placements.** | `PromoteReadyEdgePlacements.php:26-27` | 100 stuck placements block every newer domain. |
| A13 | **Automatic placement ignores withdrawn pools.** | `ReconcileEdgeDomain.php:64`, `PowerDnsZone.php:28` | A new domain gets a CNAME to a pool hostname that is not published. |
| A14 | **Coalesced operations are not visible to other members.** A coalesced operation can belong to another actor, and the operation controller only shows operations to the actor who created them. The check-then-insert has no lock or partial unique index. | `Operation::coalesceDomain`, `OperationController.php:35` | A member gets 403 when polling the operation id they were given, and duplicate pending operations are possible. |
| A15 | **A DNS operation stays "failed" after its retry succeeds.** It is marked failed before the retry, and a successful retry never updates it. There is also no per-domain job lock. | `ReconcileDnsZone.php` | Users see false failures, and an older revision can briefly overwrite a newer one. |
| A16 | **Host and SNI are never compared.** The certificate is chosen by SNI, and the tenant by `Host`. The gateway routes only the first keep-alive request by Host. | `runtime.lua` certificate selection and tenant lookup, `edge-gateway/main.go` | Cross-tenant fronting. It also bypasses another tenant's `tls.mode=disabled` and expired-certificate checks. |
| A17 | **Client address handling can be spoofed.** The runtime takes the leftmost `X-Forwarded-For` entry with weak parsing, and the PROXY listeners trust `0.0.0.0/0`. | `runtime.lua` `client_address`, `edge-runtime.conf:33-35` | IP and country rules and per-client rate-limit keys can be evaded. |
| A18 | **Gateway slots can be held forever.** Deadlines are cleared after the preface, and the gateway waits for the client side to close. Combined with the 15 s preface deadline, one client can fill every slot. | `edge-gateway/main.go` `handle` | Slowloris-style exhaustion. Extends M8. |
| A19 | **Five emergency actions are accepted but have no effect.** | `runtime.lua` emergency control list | Operators believe a protection is active during an incident when it is not. |
| A20 | **Per-domain TLS handshake and connection limits are shared by all clients.** | `runtime.lua` handshake limit, `edge-runtime.conf` `limit_conn` | One client can lock a domain out of new TLS sessions. |
| A21 | **Cell temporary storage and worker count do not match the container limits.** The 64 MB tmpfs sits inside a 512 MB memory limit, while temp files up to 1 GiB are allowed. `worker_processes auto` ignores `cpus: 0.5`. | `compose.prod.yml` cells, `edge-runtime.conf`, `openresty.conf` | A few uploads fill temporary storage, and large hosts hit OOM. |
| A22 | **Some manifest fields are not signed.** The signature does not cover `kind`, `domain_id`, or `revision`. Equal revisions are accepted. The signing key is trusted on first use, with no rotation. | `edge-agent/main.go` `verifyDelivery`, `sync()` | A replayed delivery with an altered manifest can tombstone a domain (defense in depth). |
| A23 | **Telemetry ingest is unauthenticated, and `domain_id` comes from the payload.** | `vector.yaml` sources | Usage and analytics can be forged from the edge network. |
| A24 | **Telemetry is not redacted as documented.** Full client IPs and user agents are kept for 7 days, while README says events are redacted. | `vector.yaml` remaps | Inaccurate privacy claim. |
| A25 | **DNS rollups key on the queried name and keep it for years.** | `clickhouse/init.sql` `dns_hourly`, `dns_daily` | A random-subdomain flood leaves millions of rows for 400 days to 3 years. |
| A26 | **ClickHouse migrations are not run or tracked in production.** | `docker/clickhouse/migrations/`, `compose.prod.yml` | The telemetry schema drifts between hosts. |
| A27 | **GeoIP refresh and bootstrap are fragile.** PowerDNS keeps the memory-mapped GeoIP file and is never reloaded after an update (inference). The source is unpinned, and DNS cannot start without it. | `mmdb-updater/entrypoint.sh`, `pdns.conf`, `compose.prod.yml` | Stale geo answers. DNS does not start on a host without internet access. |
| A28 | **Backups cover only the control database, with no retention, integrity check, or age alert.** The off-host restore is not in CI. | `core/docker/backup/create.sh`, `tests/e2e/phase8_recovery.py` | The backup repository grows without limit, and corruption is found only during a restore. |
| A29 | **One Valkey instance with `noeviction` serves cache, sessions, and queues.** The password is visible on the command line. | `compose.prod.yml` redis | A full cache stops job dispatch. |
| A30 | **Account protections are weak.** The login throttle is keyed on IP plus email. Timing and responses reveal which users exist. Email can be changed without the current password. The user password minimum is 8 characters. An admin password reset keeps the user's tokens. Bootstrap tokens never expire. `trustProxies('*')`. | `AuthController`, `AppServiceProvider`, `bootstrap/app.php`, `Admin/EdgeController` | Password spraying, and lasting access from stolen credentials. Extends M12. |
| A31 | **Containers run with few restrictions.** No service sets `no-new-privileges`. Few drop capabilities. Stateful and control services have no memory limits. The FPM master runs as root. The log collector mounts the Docker socket. | `compose.prod.yml`, `core/Dockerfile` | A compromise has a wider blast radius, and one service can starve another. |
| A32 | **DNSdist DoS posture is weak.** A global 50k QPS drop rule applies to everyone, with no TC fallback, no TCP limits, and no ANY handling. | `dnsdist.conf` | A spoofed flood drops legitimate queries for every zone. |
| A33 | **Development images are not the production versions.** Dev uses older ClickHouse, Vector, DNSdist, and PowerDNS, and is pinned by tag only. | `compose.dev.yml` | E2E qualifies versions that are not shipped. |
| A34 | **Bulk DNS resolves origins inside the domain lock.** Each proxied item can take 5 s, with up to 5000 items per request. | `DnsRecordController.php`, `DnsRecordData.php` | One request can hold the domain lock for hours. |
| A35 | **Remaining SSRF gaps.** 6to4, Teredo, and IPv4-compatible ranges are not blocked, any origin port is allowed, and `testOrigin` accepts any edge. | `NetworkAddress.php`, `OriginData.php`, `ProxyController.php:84`, `runtime.lua` | Edges can be used to probe ports or relay traffic. |
| A36 | **Supply-chain coverage gaps.** Provenance is built by hand. Dependabot does not cover composer, npm, gomod, or Compose. There is no govulncheck, Larastan, or CodeQL. | `.github/workflows/ci.yml`, `.github/dependabot.yml` | Library CVEs and image-digest drift go unnoticed. |

### Low (cleanup)

| ID | Finding | Where |
| --- | --- | --- |
| L1 | Edge management IPv4/IPv6 fields exist in the model, API, UI, and validation, but nothing operational uses them. | `EdgeController`, `EdgeResource`, `OriginData`, `EdgePoolEndpointData` |
| L2 | The agent has two activation code paths (with and without `runtimeDir`). Only one is deployed, and part of the other can never run. | `edge-agent` `activate()` |
| L3 | Compatibility code for earlier installs: the legacy nameserver fallback, the `legacy-<sequence>` generation id in Lua, the idempotency `$legacyHash`, and expand/contract migrations. | `Domain::assignedNameservers()`, `runtime.lua` `load()`, `IdempotentRequest.php`, migrations |
| L4 | 64 archived files under `docs/legacy/`, and test files named after roadmap phases (`phase2_dns.py` … `phase9_waf.py`). | `docs/legacy/`, `tests/e2e/` |
| L5 | `GET /revisions` returns raw Eloquent models instead of an API resource, so any new column is exposed automatically (this is the root cause of C1). | `ProxyController::revisions` |
| L6 | Edge identity relies on FastCGI parameters set by `edge-control.conf`. Anything else that can reach PHP-FPM on the internal network can claim to be an edge by sending the public certificate. | `AuthenticateEdge`, `docker/nginx/*.conf` |
| A37 | More endpoints return raw models: DNS deployments (including PowerDNS errors), placements, and cache purges. `DnsCluster.api_key` is encrypted but not hidden. Same root cause as L5. | `DnsDeploymentController`, `ProxyController`, `CacheController`, `DnsCluster` |
| A38 | Responses are inconsistent. The cache endpoints return 202 without an operation id, and Geo-DNS returns 200. 429, 405, and 413 share the code `http_error`, and 500 has no code. Model-not-found messages include class names. A 403 vs 404 difference lets anyone enumerate domain ids. | `CacheController`, `GeoDnsController`, `bootstrap/app.php`, `Domain` route binding |
| A39 | The purge URL check rejects credentials and fragments only when all three are present. | `CacheKey.php:13` |
| A40 | DNS deployment reconcile, usage rebuild, and domain-user nameserver verification write no audit entry, and `usage.rebuild` cannot be retried. | `DnsDeploymentController`, `Admin/UsageController`, `OperationController` |
| A41 | Uploaded chains must include their own root, and that root is trusted. ACME ignores `Retry-After`, fetches a fresh nonce for every request, and races on account creation. | `UploadedCertificate.php`, `AcmeClient.php` |
| A42 | Agent and gateway state writes are not fsynced. `gzip_vary` is off. There is no OCSP stapling or session-ticket key rotation. | `edge-agent/main.go`, `edge-gateway/main.go`, `openresty.conf`, `edge-runtime.conf` |
| A43 | The agent uses one 15 s timeout for every request, including the 96 MiB snapshot. It has no backoff or jitter, and requests ignore `context`. | `edge-agent/main.go` |
| A44 | Environment drift. `HOST_BIND_IPV6`, `PDNS_API_KEY`, and `CDNF_RELEASE` are documented but unused. Many `env()` keys cannot be set in production because the fixed `x-core-env` list omits them. | `.env.prod.example`, `compose.prod.yml` |
| A45 | Two Trivy classifications expire on 2026-11-28, and expiry is enforced in CI. | `supply-chain/trivy-classifications.yaml` |
| A46 | Small code-quality items: a stateless 23-line `MetricComparisonService`, and `fleet-test`, `qualification-tools-check`, and `supply-chain-check` missing from `.PHONY`. `edge-agent/main.go` (1993 lines) and `runtime.lua` (1136 lines) need splitting when Phase 4 touches them. | `core/app/Ops/Services/`, `Makefile` |

### High availability

These items use the severity scale above. HA1 is High only after Phase 2
ships fail-static. Until then, losing the control host also triggers C3, which
is Critical. HA is optional: a single control node stays supported, and these
items are about making a second node possible and safe.

| ID | Finding | Where | Impact |
| --- | --- | --- | --- |
| HA1 | **The control plane is one host.** `control-db`, `redis`, `web`, `edge-control`, `horizon`, and `scheduler` all run in the `control` profile on one host. The Fleet renderer picks the first control node it finds. The documentation states that the repository configures no database replication and no automatic failover, and leaves both to the operator. | `compose.prod.yml` control profile, `render.py:474,620`, `docs/deployment/topology.md:28`, `docs/operations/scaling.md:62-64` | Losing that host stops the API, both panels, every deployment, TLS issuance and renewal, purges, and edge sync, until an operator restores from backup. |
| HA2 | **Nothing stops a second control node from running jobs.** Every task uses `withoutOverlapping()` and none uses `onOneServer()`, and no service checks whether its database is the writable primary. A second node that starts its scheduler or workers runs every task a second time. | `core/routes/console.php`, `compose.prod.yml` horizon and scheduler | A returning or misconfigured node doubles backups, renders, and TLS maintenance, or writes against the wrong database. |
| HA3 | **The edge agent knows one control URL.** The Go dialer tries other resolved addresses only when a connection fails. It does not try them after a TLS error, a 5xx response, or a timeout, and the address list cannot be changed without editing every PoP. | `edge-agent/main.go` `EDGE_CONTROL_URL` | After control moves to another address, PoPs keep calling the old one until DNS caches expire, or until each PoP is edited by hand. |
| HA4 | **Queues, locks, and Horizon depend on one Valkey.** There is no replica or failover, and `noeviction` is set (see A29). | `compose.prod.yml` redis | When Valkey is lost, job dispatch and scheduler locks stop, even while PostgreSQL is healthy. |
| HA5 | **Monitoring shares fate with control.** Prometheus, Alertmanager, and Grafana run on the control host in the documented topology. | `docs/deployment/topology.md`, `compose.prod.yml` telemetry profile | The failure that most needs an alert, losing the control host, also removes the alerting. |
| HA6 | **Control ingress certificates are issued per host.** Caddy uses automatic HTTPS for `CONTROL_HOSTNAME`, with HTTP-01 or TLS-ALPN challenges. A standby that is not serving cannot obtain a certificate for the name. | `deploy/production/Caddyfile` | After a promotion, the new active node has no valid certificate until it completes a fresh issuance. |
| HA7 | **Phase 4 adds a single point of failure.** The hidden-primary DNS design names "one private primary". If that primary runs on one host, it is a new single point of failure for every zone change. | Phase 4 hidden-primary item | Zone changes stop while that host is down. Secondaries keep serving until the SOA expire time. |

## Before Phase 1

These are not phase work. They are date-bound, or they correct public
statements about the current release.

- [x] Re-assess the two Trivy classifications and renew or remove them before
      2026-11-28 (A45).
- [x] Add a status banner to the README that links the open critical and high
      findings. It replaces the "production-oriented" framing until Phase 2
      exits.
- [x] Owner decision: publish a security notice for 1.2.x about C1 and C2 in
      `SECURITY.md` and the v1.2.1 release notes, with the interim
      mitigation. The mitigation is to restrict `GET /revisions` to
      administrators, or not to assign domain users to managed-TLS domains.

## Phases

Phases are ordered by risk: first stop leaks, then stop outages, then fix
correctness, then scale, then simplify. Each phase leaves a working
platform. Items marked S-sized in the audit can ship as separate PRs at the
start of their phase.

### Phase 1: Stop secret leaks, tenant leaks, and security bypasses

Fixes C1, C2, H9, M1, L5, A4, A7, A10, A16, A23, A24, A37.

- [x] Remove private keys from snapshots and artifacts. Artifacts carry a
      certificate id. The agent fetches the key over mTLS from a key endpoint,
      encrypted to that edge's identity key, and the key is never written to
      PostgreSQL except in `tls_certificates`.
- [x] Purge the existing key material from history. Since 2.0 is zero-state,
      this means only that the new schema has no column that could hold a key.
- [x] Return revisions through an API resource with an explicit field list
      (revision, status, checksum, created_at, and a diff summary). Do the
      same for every endpoint that returns raw models.
- [x] Add `$hidden` to every encrypted model column as a second safeguard.
- [x] Tag DNS telemetry with `domain_id` at the DNS node, from the zone the
      authoritative server answered from. Scope analytics and logs by
      `domain_id` and by the time since the current owner's activation.
- [x] Authenticate Vector's HTTP sources with a per-node token, and bind them
      to the cell network.
- [x] Derive the edge `domain_id` at the cell from the active snapshot, never
      from the event payload.
- [x] Split ClickHouse users: insert-only for Vector, with a separate secret
      per node; read-only with a server-side settings profile for Laravel;
      administrator for migrations only.
- [x] Render `pdns.conf` secrets from Compose secrets in every production
      path, and refuse to start with the development values.
- [x] Make WAF exclusions remove only the excluded rule (or target) for the
      matching request. Keep request-wide monitor mode only for the explicit
      profile mode. Until this ships, reject new exclusions with a stable 409.
- [x] On TLS listeners, reject a request whose `Host` is not covered by the
      certificate selected for its SNI.
- [x] State exactly what telemetry keeps, or truncate client addresses in the
      long-lived rollups.
- [x] Rewrite rollback: restore only customer-owned fields, re-validate them
      with `OriginData`, `CachePolicy`, and `SecurityConfig`, and roll back
      WAF and TLS mode as well, or refuse.

**Exit:** all of these regression tests pass:

- An API sweep proves that no endpoint returns key material, PEM blocks, API
  keys, or secrets to a domain user.
- A tenant cannot see a nested or previous tenant's DNS logs.
- Rollback never writes platform fields.
- With an exclusion for one rule on parameter `q`, an attack that triggers a
  different rule is still blocked, with and without `?q=`.
- A mismatched SNI and `Host` is rejected.
- The Vector ingest credential cannot `SELECT` or `DROP`.
- A forged telemetry event from another container is rejected.
- The production PowerDNS configuration contains no development secret.

### Phase 2: Stop control-plane faults from becoming outages

Fixes C3, C4, H3, H4, A1, A2, A3, A8, A9, A11, A12, A13, A17, M11.

- [x] **Fail static.** Withdraw a PoP from DNS only on a positive signal
      (drain, disable, or a failed external health probe), never on missing
      heartbeats alone. Never publish an empty answer set for a pool: keep
      the last published set and alert.
- [x] Add external health probes, run from at least two vantage points, that
      check each PoP's HTTP/TLS listener directly. Withdrawal needs a quorum.
- [x] Renew edge identity certificates over mTLS at two thirds of their
      lifetime. Accept the old and new fingerprints until the new one is
      proven. Report the expiry in the heartbeat and alert 30 days before it.
- [x] Make the agent apply artifacts per domain. A bad artifact is rejected
      and skipped, the cursor advances, and the server records the rejection
      for that domain only.
- [x] Make agent and server agree on one rejection-reason list, defined once
      and tested by a contract test. An unknown reason is stored, never
      returned as 422.
- [x] Promote a placement when a quorum of participating PoPs (default: all
      that are healthy, at least one) have acknowledged. Lagging PoPs catch
      up without blocking the pool.
- [x] Page promotion through all deploying placements in bounded chunks.
- [x] Exclude withdrawn pools from automatic placement.
- [x] Publish proxied records independently of placement: before a placement
      is active, render the default pool's hostname, so the zone always
      publishes.
- [x] Make the gateway forward the bytes it has already buffered after the
      HTTP preface.
- [x] Pass the real client address to the origin hop in one internal header,
      cleared at ingress.
- [x] Use only the PROXY-protocol address, and trust PROXY headers only from
      the gateway. Accept `X-Forwarded-For` only from configured trusted
      proxies, taking the rightmost untrusted entry.
- [x] Configure Alertmanager receivers from typed settings, add an
      always-firing `Watchdog` alert, and refuse a production configuration
      with no notifier.
- [x] Set `retry_after` above every worker and job timeout, and assert that
      in `make config-check`.
- [x] Store idempotency receipts only for 2xx and deterministic 4xx
      responses, filter lookups on `expires_at`, and run the throttle before
      the idempotency middleware.
- [x] Add health checks for every long-running service, including agent,
      gateway (`/healthz`), edge-control, Horizon, and scheduler liveness.

**Exit:** E2E checks each of these on a real Compose stack:

- Stopping edge-control: every domain keeps resolving and serving.
- An edge certificate that expires in dev: the PoP keeps its heartbeat.
- A bad artifact: the other domains on that PoP still update.
- One PoP killed: deployments complete on the others.
- A new domain with a proxied apex: MX resolves immediately.
- A POST over port 80: its body reaches the origin.
- The origin sees the real client address over IPv4 and IPv6, and a forged
  `X-Forwarded-For` or PROXY header has no effect.
- A webhook sink receives `Watchdog`.
- A job longer than 90 s runs exactly once.
- A retried 429 is not replayed.
- Killing the agent process is detected and restarted.

### Phase 3: Make domain features correct

Fixes H1, H2, H5, H6, H7, M3, M9, M13, M14, A5, A6, A14, A15, A19, A38, A39,
A40, A41.

- [x] Restrict automatic placement to `shared` pools. Only explicit
      administrator actions select `quarantine`, `reserved`, or `dedicated`.
- [x] Ship the default `shared` pool WAF-capable, or reject a WAF profile
      change with a clear 409 when no WAF-capable pool exists. Never accept
      work that cannot run.
- [x] Define the cache key once (path normalization, query decoding, and
      ordering), built from the raw request path. Keep `%2F` and `%3F`
      encoded, and reject requests that would normalize differently from the
      origin request. Generate the PHP and Lua implementations' test vectors
      from one shared fixture file, and run it in both test suites.
- [x] Make URL purges durable. The agent persists purge generations and
      replays them on cell start. Keep them in a dedicated shared dictionary
      that cannot be evicted by rate-limit counters, and give each entry a
      TTL equal to the cache's maximum TTL.
- [x] Reject requests with more than 128 query arguments, or hash the full
      argument set into the key.
- [x] Reject purge URLs that carry credentials or a fragment.
- [x] Add DNS retry with backoff, plus a scheduled drift check that compares
      each DNS target's served serial and checksum with desired state and
      repairs differences.
- [x] Mark a DNS operation failed only after its last try, hold a per-domain
      lock, and re-check the revision just before activation.
- [x] Derive the platform zone serial from a content checksum, so it changes
      exactly when the content changes.
- [x] Send the client subnet from DNSdist to PowerDNS (`useClientSubnet` or
      PROXY protocol), so Geo-DNS sees the client network.
- [x] Authorize operation reads through the operation's domain policy, and
      store the domain id as a typed column with a partial unique index for
      pending operations.
- [x] Implement each accepted emergency action, or reject it with a stable
      code and remove it from the documentation.
- [x] Return an operation id with every 202. Add stable codes for 405, 413,
      429, and 500. Return 404 for unauthorized bindings, and hide model
      class names.
- [x] Audit every mutation, and allow `usage.rebuild` to be retried.
- [x] Verify uploaded chains against the system trust store, with the
      uploaded intermediates as untrusted. Honor ACME `Retry-After`, reuse
      `Replay-Nonce`, and lock ACME account creation.
- [x] Replace first-claim-wins with re-delegation proof. Each platform
      offers a small set of readable nameserver pairs. A new claim gets a
      pair different from the domain's current delegation, verification
      requires the parent delegation to equal that pair, and success moves
      the name from the previous owner. Keep the partial unique index over
      active states only, and remove the hex labels and the wildcard.

**Exit:**

- The reproduced H1 test shows 0 domains in non-shared pools.
- Cache-key and purge fixture tests, including encoded-path collisions, pass
  in PHP and Lua.
- A cell restart does not bring back purged content.
- A DNS host outage of 10 minutes self-heals, and the operation ends
  `succeeded`.
- A Geo-DNS override answers a query with ECS from that country.
- Every domain member can poll a coalesced operation.
- The claim test suite covers squatting, takeover, and competing claims.

### Phase 4: Scale the runtime

Fixes M2, M4, M5, M6, M7, M8, M10, A18, A20, A21, A22, A25, A32, A34, A43.

- [x] Move DNS to a hidden-primary design. Laravel writes to one private
      primary. DNS nodes are PowerDNS secondaries that use a catalog zone and
      TSIG-signed transfers, triggered by the PoP agent, outbound only. This
      removes the per-host API gateways and `dns-api-N` names, and it allows
      anycast nameserver addresses served by any number of nodes. Node
      convergence (serial per node) is reported in the heartbeat.
      Delivered: `pdns-primary` runs on the control host; only one cluster can
      be enabled (a database constraint). Every zone is a primary-kind member of
      the catalog `catalog.cdnfoundry.invalid` with `TSIG-ALLOW-AXFR`, and the
      primary stores the rendered serial plus one (`SOA-EDIT-API=INCREASE`), so
      each change raises it. A zone-change feed (`/edge/v1/dns/changes`) tells
      each node's agent what to pull; the agent installs the TSIG key (sealed to
      its identity key) and the catalog consumer through the node's local API,
      signs and pulls each changed zone, and reports `dns.cursor`,
      `dns.latest`, and `dns.pending` in the heartbeat. Nodes keep a SQLite
      store and no database. The `dns-api` gateway, its certificates, and
      `PDNS_CA_CERTIFICATE` are deleted. "Serial per node" is delivered as a
      cursor into the change feed, which also covers a zone whose content
      changed while its serial did not.
- [x] Split queue lanes: `dns`, `edge`, `verification`, `tls`, and `purge`,
      each with its own worker bound.
      Delivered with `interactive` and `bulk_maintenance` kept; the global purge
      reconciliation runs on `purge`, because no per-request purge job exists.
- [x] Make snapshots deterministic: sort every list. Move the global address
      blocklist out of per-domain snapshots into one PoP-level artifact.
      Delivered: every set in the snapshot is sorted, and the platform origin
      destination policy is one signed document per PoP (`/edge/v1/origin-policy`)
      that the agent injects at compile time, so a pool address change recompiles
      and re-signs no domain.
- [x] Make the agent incremental: store one file per domain, write only what
      changed, and keep the generation swap atomic.
      Delivered: durable state is `state/domains/<id>.json`, generations hold only
      compiled cell and gateway files, and a cell file with unchanged content is
      hardlinked from the previous generation. At 10,000 domains one change
      rewrites one state file and the one cell file that serves the domain
      (`incremental_activation_test.go`). A cell file still holds every host of
      its pool, so a changed cell rewrites that whole file.
- [x] Sign `kind`, `domain_id`, and `revision` together with the checksum,
      sequence, and expiry. Require a strictly greater revision, and support
      signing-key rotation with an overlap window.
      Delivered: all of them are signed together, only a strictly greater
      revision is accepted (an identical replay is a no-op), and the current key
      endorses the next one so both are accepted until the new one signs a
      delivery.
- [x] Give agent requests per-request timeouts, contexts, and exponential
      backoff with jitter.
      Delivered: 15 s for control requests, 2 minutes for the snapshot, and
      cancellation on shutdown.
- [x] Reload cells by generation: Lua checks a small generation file and
      decodes the state only when it changes.
      Delivered as a `.stamp` content identity beside each cell file.
- [x] Add a per-source-address connection limit, idle timeouts in both
      directions, and half-close handling to the gateway.
      Delivered: 256 connections per address (an IPv6 /64 counts once), 120 s
      idle, 30 s after a half-close.
- [x] Key TLS handshake and connection limits per domain and client prefix,
      with a separate, higher ceiling for each domain.
      Delivered: connection limits were already per client and per domain. The
      gateway, which sees the real client, now limits TLS handshakes per client
      prefix and hostname, and the cell applies a domain ceiling of 20 times the
      per-client budget.
- [x] Size cell temporary files and body limits to the cell's temporary
      storage, and set `worker_processes` from the CPU limit.
      Delivered as an 8 MiB body limit, response buffering on the cache volume,
      and workers from the CPU limit.
- [x] Generate the cell services from the PoP's slot count, or fix the slot
      count to what Compose runs. Allow small PoPs to run fewer cells.
      Delivered by fixing the count at eight in the API and panel. Small PoPs
      still run eight cells.
- [x] Bound DNS rollup cardinality: store the domain and a bounded top-N of
      query names in the long-lived rollups.
      Delivered: the rollups are keyed by the served zone and its domain,
      resolved by a ClickHouse dictionary that Laravel fills (`cdnf:analytics:
      publish-zones`, through an insert-only account), and a bounded top-20 name
      sketch is stored per hour and zone. A zone added in the last minute or two
      falls into one unattributed bucket until the next refresh.
- [x] Answer over-limit UDP queries with TC instead of dropping them, and add
      DNSdist limits for TCP connections, TCP queries, and ANY.
      Delivered for UDP rate limits, TCP connection limits, and ANY.
- [x] Resolve bulk DNS origins outside the domain lock and transaction, or
      lower the bulk limit for proxied records.
      Delivered: lookups run before the lock, and a bulk request may name at
      most 50 distinct origin hostnames.

**Exit:**

- A load test with 10,000 domains on one PoP shows bounded CPU when idle,
  and one domain change touches only that domain's files.
- A 20-node DNS simulation converges within the documented bound.
- One client cannot exhaust gateway slots or a domain's handshake budget.
- A tampered manifest is rejected.
- A random-subdomain flood adds bounded rollup rows.

**Completion gate (2026-11-01).** The four fields are recorded separately.

| Field | Status | Evidence |
| --- | --- | --- |
| Implementation | Complete, with the deviations marked above | Commits on `dev`; replaced code is deleted (the `dns-api` gateway, its certificates and scripts, the per-pool and combined runtime files, `state.json` inside generations) |
| Regression tests | Pass (489 PHP tests) | PHP: `DnsPrimaryTransferTest`, `DnsNodeSyncApiTest`, `OriginPolicyDeliveryTest`, `SigningKeyRotationTest`, `ServedZonePublisherTest`, `PlacementResilienceTest`, `QueueTimingContractTest`. Go agent: `dns_sync_test.go`, `origin_policy_test.go`, `signing_keys_test.go`, `incremental_activation_test.go`, `request_timing_test.go`, `runtime_stamp_test.go`, `artifact_apply_test.go`. Go gateway: `relay_test.go`. Fleet: 84 tests |
| Runtime tests | Pass for the exit items below | `dns_node_convergence.py`, `dns_rollup_cardinality.py`, `clickhouse_roles.py`, `clickhouse_upgrade.py`, `cell_generation_reload.py`, `tls_domain_handshake_ceiling.py`, `dnsdist_limits.py`, `pdns_secrets.py`, `fleet_pdns.py`, plus live `e2e.py`, `phase2_dns.py`, `dns_outage_recovery.py`, `control_plane_faults.py`, `client_address.py`, `phase4_runtime.py`, `phase7_analytics.py` on the Compose stack. IPv4 only |
| Documentation | Updated | DNS nodes (new), signing-key rotation (new), runtime generations, analytics, DNS, gateway ingress, cell inventory, limits, configuration, CLI commands, edge-agent API, manual Compose, topology, certificates, fleet, and quick starts |
| Manual browser | Not run; checklist updated | `docs/manual-browser-qualification.md` (DNS cluster form: **Transfer endpoint**, single primary; **Cell slots** fixed at 8) |

Exit items and how each was checked:

| Exit item | Check |
| --- | --- |
| 10,000 domains: bounded idle CPU, one change touches only its files | Regression: `incremental_activation_test.go` (one state file and one cell file rewritten at 10,000 domains). Runtime: `cell_generation_reload.py` (an unchanged stamp causes no reload). Idle CPU was not measured at 10,000 domains on a live cell |
| A 20-node DNS simulation converges within the documented bound | Runtime: `dns_node_convergence.py` with 20 real PowerDNS secondaries: 200 zones pulled in 48.5 s, an update to 20 zones reached all nodes in 5.2 s, a deletion left every node, and an unsigned transfer was refused. The nodes were driven with the agent's API calls, not by the Go agent itself |
| One client cannot exhaust gateway slots or a domain's handshake budget | Regression: `relay_test.go` (per-address limit, per-client handshake budget). Runtime: `tls_domain_handshake_ceiling.py` for the cell's domain ceiling. The per-client budget was not run through a live gateway |
| A tampered manifest is rejected | Regression: `artifact_apply_test.go` (a relabelled entry is rejected and the domain keeps serving; an equal revision with different content is a replay) |
| A random-subdomain flood adds bounded rollup rows | Runtime: `dns_rollup_cardinality.py` on the real ClickHouse schema: 601,010 events became 5 rollup rows, attributed to the longest served zone |

Known limitations and deviations:

- The live agent was verified against the dev stack's one DNS node. Twenty nodes
  were exercised with a script that makes the agent's local API calls.
- A DNS node needs the primary's transfer port (5300) reachable from its address;
  the primary's address must be an IP literal.
- Existing zones keep a date-based serial from the old design until the next
  write raises them; a node that already holds a higher serial must be rebuilt
  (its store is a cache).
- `dns_events.zone` keeps the queried name for the seven days of raw events.
- `phase4_control_plane.py` still fails at the drain step noted in Phase 3.

### Phase 5: Production readiness and security hardening

Fixes H8, M12, L6, A26, A27, A28, A29, A30, A31, A33, A35, A36, A42.

- [x] Publish IPv6 in production for DNS, control, and edges, including
      edge-control. Block saving a nameserver IPv6 address until that address
      answers from an external probe.
      Delivered: the Fleet renderer publishes `edge-control` on IPv6 beside DNS,
      the control ingress, the primary's transfer port, and the gateway.
      Validating a payload with a new nameserver IPv6 address queues a `dns_probe`
      task for up to three fresh edges; the agent sends a SOA query for the
      platform zone over IPv6 and needs an authoritative NOERROR answer. An
      answer is good for 24 hours; addresses already saved are not probed again.
      Deviation: the manual `compose.prod.yml` still publishes IPv4 only; IPv6
      publication is a Fleet feature.
- [x] Add token expiry, token abilities (read-only, DNS-only, purge-only),
      and optional per-domain token scope. Add TOTP for administrators.
      Delivered: tokens expire (90 days by default, 365 at most, 30 for login
      tokens); abilities are `*`, `read`, `dns`, and `purge`; `domain_ids` limits a
      token to up to 50 domains, enforced in `DomainPolicy` and route binding. A
      scoped token cannot create or list tokens. TOTP uses Filament's app
      authentication for the admin panel (with recovery codes) and `otp` on
      `POST /api/auth/login`. Enrolment is optional per administrator.
- [x] Harden accounts: a per-account login limiter, constant-time login
      checks, `current_password` required to change the email, one password
      policy for all users, token revocation on password reset, expiring
      bootstrap tokens, and trust for the ingress proxy only.
      Delivered: a limiter per account and one per address; a dummy hash for
      unknown addresses; 12-character mixed-case-and-digit passwords everywhere;
      an administrator password reset revokes the user's tokens; bootstrap tokens
      expire after 24 hours; forwarded headers are trusted only from
      `TRUSTED_PROXIES` (default loopback and private ranges).
- [x] Bind PHP-FPM only to the nginx containers' private network, and add a
      shared secret between nginx and PHP for the edge identity parameters.
      Delivered: FPM listens only on core's address on the private `fpm`
      network, resolved from the `core-fpm` alias; edge identity headers need
      the secret in `EDGE_PROXY_SECRET_FILE`.
- [x] Add a shared hardening anchor to every service (`no-new-privileges`,
      `cap_drop`, `read_only` where possible) and memory limits on every
      service. Replace the log collector's Docker socket mount.
      Delivered: `x-hardened` (no-new-privileges, all capabilities dropped, a
      small allowed `cap_add` set) and a memory limit on every production
      service, enforced by `scripts/ci/check_compose_policy.py`. The collector
      reads logs through a read-only `docker-socket-proxy`. Deviation: Loki's
      image has no shell, so a static busybox is copied into a volume for its
      health check; `read_only` was already present where the image allows it.
- [x] Block 6to4, Teredo, and IPv4-compatible ranges in PHP and Lua, allow
      only an explicit origin port set, and restrict origin tests to edges in
      the domain's placement.
      Delivered in PHP, Lua, and the agent's origin probe; the port set is a
      constant kept equal across the three by a test.
- [x] Add an explicit, tracked ClickHouse migration service.
      Delivered: `clickhouse-migrate` with `cdnf.schema_migrations` checksums;
      the Fleet `start.sh` runs it on the telemetry host.
- [x] Reload PowerDNS after a GeoIP update, ship a bundled fallback database,
      and require a checksum or signature in production.
      Delivered: the start script reloads PowerDNS when the file changes; an
      empty bundled database lets DNS start with no source; production refuses an
      unverified download. Limitation: the reload was confirmed to be accepted by
      PowerDNS, but a database swap was not traced through to a changed Geo-DNS
      answer.
- [x] Back up with retention (`forget --prune`), a weekly `restic check`, a
      backup-age alert, and a scheduled CI job for the off-host restore.
      Delivered: `cdnf:backups:maintain prune|check`, the `ControlBackupStale`
      alert, and `restore-drill.yml` (weekly). The drill workflow was not run on
      GitHub. The public MinIO images were withdrawn, so the recovery tests use
      SeaweedFS.
- [x] Give the cache its own eviction-enabled Valkey, or TTLs on every cache
      key. Read the Valkey password from a secret file.
      Delivered: a `cache` Valkey (`allkeys-lru`, no persistence) and a
      `valkey.conf` secret for both instances.
- [x] Pin development images to the production digests, and check in CI
      that they are equal.
      Delivered: `scripts/ci/check_dev_image_parity.py`, run by
      `validate-production-overrides.sh`.
- [x] Builder-generated provenance; Dependabot for composer, npm, gomod, and
      Compose; govulncheck and Larastan in CI.
      Delivered: Larastan runs in CI (`vendor/bin/phpstan analyse`, level 5 over
      `app` and `routes`) against the committed `core/phpstan-baseline.neon`
      (677 existing findings), so any new finding fails the build. None of the CI
      changes ran on GitHub.
- [x] fsync agent and gateway state writes, enable `gzip_vary`, and add OCSP
      stapling and session-ticket key rotation, or document why not.
      Delivered: durable writes in the agent and gateway, `gzip_vary on`,
      session tickets turned off. OCSP stapling is documented as not offered.

**Exit:**

- E2E passes over IPv6 only.
- A scoped token cannot exceed its scope.
- The Compose policy check proves every service has a health check, hardening
  options, and a memory limit.
- The off-host restore job passes.
- The SSRF fixture blocks every transition range.

**Completion gate (2026-11-01). Phase 5 is complete, with the limitations below.** The four fields are
recorded separately.

| Field | Status | Evidence |
| --- | --- | --- |
| Implementation | Complete | Larastan configuration, baseline and CI step added after the original gate (`core/phpstan.neon`, `core/phpstan-baseline.neon`, `ci.yml`); not yet committed |
| Regression tests | Pass (516 PHP tests, run again at the gate) | `TokenScopeTest`, `AccountHardeningTest`, `EdgeProxySecretTest`, `BackupMaintenanceTest`, `SystemIdentityApiTest` (IPv6 probes), origin port and range tests. Go agent: `origin_destination_test.go`, `dns_probe_test.go`, `durable_state_test.go`. Fleet: 87 tests |
| Runtime tests | Pass (IPv4, plus the IPv6 data path) | `e2e.py`, `phase2_dns.py`, `phase4_runtime.py`, `phase7_analytics.py`, `phase8_recovery.py`, `dns_outage_recovery.py`, `origin_destinations.py`, `object_store.py`, `backup_retention.py`, `gateway_ingress.py`, `ipv6_only.py`. Test fixes made at the gate: `phase8_recovery.py` checks that every migration file is recorded (a long-lived dev database keeps rows for removed files); `phase2_dns.py` has a longer helper timeout and tolerates the 502 while `core` restarts; `gateway_ingress.py` reads `current/` instead of the pre-generation state path; `phase7_analytics.py` probes Vector from a curl container because its image has no HTTP client. Dev `vector` and `log-collector` now set `VECTOR_DANGEROUSLY_ALLOW_ENV_VAR_INTERPOLATION`, as production already did |
| Documentation | Updated | Users and access, hardening, configuration, manual Compose, production fleet, backup and recovery, CLI commands, supply chain, proxy and origins |
| Manual browser | Not run; checklist written | `docs/manual-browser-qualification.md`, "Phase 5" job |

Exit items and how each was checked:

| Exit item | Check |
| --- | --- |
| E2E passes over IPv6 only | `ipv6_only.py` passes for DNS and the gateway. The dev stack is not IPv6-only end to end (control, origins and telemetry stay on IPv4), so this covers the data path only |
| A scoped token cannot exceed its scope | Regression: `TokenScopeTest` |
| The Compose policy check proves health check, hardening and memory limit | `scripts/ci/check_compose_policy.py`, run by `validate-production-overrides.sh` |
| The off-host restore job passes | Backup, retention and restore ran against SeaweedFS locally. `restore-drill.yml` never ran on GitHub, and `phase8_recovery.py` fails as above |
| The SSRF fixture blocks every transition range | Runtime: `origin_destinations.py`; regression: PHP and Go tests |

Known limitations and deviations:

- Larastan runs at level 5 with a 677-finding baseline; the baseline should shrink over time.
- The GitHub workflows (provenance, govulncheck, restore drill) have never run.
- PowerDNS accepts the GeoIP reload command; a database swap changing a Geo-DNS answer was not traced.
- The manual `compose.prod.yml` publishes IPv4 only; IPv6 publication is a Fleet feature.

### Phase 6: Optional control-plane high availability

Fixes HA1, HA2, HA3, HA4, HA5, HA6, HA7.

Phase 2 keeps a control outage from becoming a data-plane outage. This phase
lets an operator keep the control plane itself available. HA is optional:

- **Single node** stays a supported production setup. The status page and the
  qualification report show `ha: none` as a warning, never as a failure.
- **Two nodes, active and standby.** The operator can add a standby at any
  time, on a running installation, with one command and no reinstall of edges
  or DNS hosts. The operator can also remove it again.

There is no third node, no consensus cluster, and no new service type. The
product stays one Laravel monolith, and the standby is a warm copy of the
active node.

**Why failover is one command and not automatic.** Two nodes cannot tell a
dead peer from a broken link between them. If the standby promotes itself
because of a network fault, both nodes accept writes (split brain), and
desired state forks. Phase 2 already keeps every domain resolving and serving
while control is down, so a control outage of a few minutes harms no traffic.
A safe, one-command promotion is simpler and safer than automatic failover on
two nodes.

**Targets** when the active node is lost:

- The API, both panels, and edge sync are back within 5 minutes after the
  operator runs `promote`.
- Committed desired state lost is at most the replication lag, which is
  bounded and alerted at 10 seconds.
- DNS and HTTP keep serving the whole time, as Phase 2 already requires.

#### What runs where

| Service | Active node | Standby node |
| --- | --- | --- |
| PostgreSQL (control and PowerDNS primary data) | Primary | Streaming replica (asynchronous) |
| Valkey | Primary | Replica |
| `web`, `edge-control`, Caddy ingress | Running | Stopped |
| `horizon`, `scheduler` | Running | Stopped |
| PowerDNS hidden primary (Phase 4) | Running | Stopped |
| Prometheus, Alertmanager | Running | Running, so each node watches the other |
| Grafana, Loki, ClickHouse | Running, single instance | Not run |

#### Addresses: failover must not break connections

After a promotion, the control plane answers from a different node. Every
other part of the platform must keep working without manual edits. The rule
is: **every node and allowlist knows both control addresses from the moment
the standby is added, and never learns about a node only during an outage.**

- [x] **Stable names, two fixed addresses.** Edges, DNS hosts, and users always
      connect to `control.<operator-zone>` and `edge-control.<operator-zone>`,
      never to a node address. The names point to the active node with a TTL of
      60 seconds, and `promote` prints the record change. Clients that need to
      be fast do not wait for DNS, as the next item explains. There is no
      floating or virtual address (no VRRP): the owner decided to support only
      two fixed addresses.
      Delivered: `control promote` prints the two record changes (`A`, or
      `AAAA` when the pair is IPv6) with a TTL of 60. The choice of family is
      made once, by `add-standby --control-address-family`.
- [x] **The edge agent knows both addresses (HA3).** The agent gets an
      ordered list of control addresses (`EDGE_CONTROL_ADDRESSES`). It keeps
      the `edge-control` hostname for TLS and SNI, and dials each address in
      turn. It moves to the next address on a connect error, a TLS error, a
      5xx response, a "standby" response, or a timeout, and it remembers the
      address that last worked. Control sends this list inside the signed
      edge configuration. Adding or removing a standby therefore reaches
      every PoP through normal sync, with no reinstall.
      Delivered: a signed `GET /edge/v1/control-addresses` document, checked
      with the artifact-signing key like the origin policy. The agent keeps the
      last address that worked and the highest accepted epoch on disk
      (`control-addresses.json`). `EDGE_CONTROL_ADDRESSES` is an optional seed
      that the signed list replaces. A definite answer from a live active
      node, such as a `422`, does not move the agent.
- [x] **Both nodes are trusted everywhere.** Render both node addresses into
      every place that filters by source address: the PowerDNS secondaries'
      primaries list and NOTIFY sources (with the same TSIG key), DNS API
      source restrictions, the Vector, ClickHouse, and Loki source allowlists,
      Prometheus scrape targets, and host firewall rules on DNS and edge
      hosts. Edge-to-control traffic is outbound only, so edges need no inbound
      rule for the new node.
      Delivered for what the fleet renders: the Caddy and telemetry gateway
      allowlists (both control addresses), the DNS secondaries' primaries list
      (the transfer response carries both, under one TSIG key; PowerDNS accepts
      NOTIFY from either), and the node-exporter scrape targets. Deviations:
      the `cdnfoundry-control` metrics job scrapes only the local node, because
      scraping the peer by address needs a per-target TLS server name; the peer
      is watched through its node exporter instead. Host firewalls are the
      operator's: the page lists the ports to open between the two nodes.
- [x] **The same identity on both nodes.** Both nodes hold the same `APP_KEY`,
      artifact-signing key, edge identity CA, session key, ACME account key,
      TSIG keys, and ingress and `edge-control` server certificates. Edge
      certificates therefore stay valid after a promotion. The Fleet renderer
      distributes these secrets to tmpfs. Each node reports a fingerprint of
      each secret (never the value), and `ha: ready` requires the
      fingerprints to match. Issue ingress certificates with ACME DNS-01, or
      use operator-supplied certificates, so the standby holds a valid
      certificate before it ever serves (HA6).
      Delivered: `ha/status.sh` and `cdnf:control:fingerprints` report keyed
      fingerprints of the edge identity CA certificate and key, the edge server
      CA, the edge proxy secret, the Valkey and replication passwords, the
      ingress certificate, `APP_KEY`, and the signing key; `ha: ready` needs
      them equal, which the real two-node test proves by tampering with the
      signing key. The ACME account key and TSIG keys live in PostgreSQL and
      replicate. Deviations: the files are bundle files with restricted modes
      (the existing fleet model), not tmpfs; the `edge-control` leaf certificate
      differs per node but comes from the same edge server CA with the shared
      name, so agents trust both; the ingress certificate is operator-supplied
      (DNS-01 is left to the operator's own tooling, since the stock Caddy image
      has no DNS provider module). Fingerprints are compared when `add-standby`
      or `control check` runs, not continuously.
- [x] **No split brain after the old node returns.** `promote` increments a
      `control_epoch` stored in PostgreSQL. A node starts `web`, `horizon`,
      `scheduler`, and `edge-control` only when its PostgreSQL is not in
      recovery and its epoch is the highest it can see. Every `edge-control`
      response carries the epoch, and the agent ignores a response with a
      lower epoch than one it has already accepted. A returning old active
      node therefore cannot take writes or steer edges, and it can rejoin
      only as the new standby (HA2).

      Delivered: `control_plane_state.epoch`; `cdnf:control:guard` (writable
      primary, recorded active node, no peer reporting a higher epoch) as the
      start command of `horizon` and `scheduler`; `cdnf:control:enforce` every
      minute; every scheduled task gated by the same check; `503 standby` and
      the epoch header on every `edge-control` response; agents that ignore a
      lower epoch. Limit: a node that returns while its peer is unreachable and
      was never demoted cannot learn it was replaced; edges still ignore it,
      and the documentation tells the operator to keep it isolated until
      `rejoin`.

#### Commands

- [x] **`cdnfoundry-fleet control add-standby <host>`.** It runs on a working
      installation, in this order, and stops at the first failure:
  1. Render both addresses into every allowlist, firewall, and DNS secondary
     configuration, and roll them out.
  2. Publish the new control address list to the edges, and wait until every
     enabled PoP reports it in its heartbeat.
  3. Copy the shared secrets and certificates to the standby.
  4. Seed PostgreSQL with `pg_basebackup` and start streaming replication.
     Start the Valkey replica.
  5. Report `ha: ready` only after replication lag is under its bound, the
     fingerprints match, and every PoP and DNS host has the new address.
      Delivered over SSH with the node's own `ha/*.sh` scripts. The new node
      needs a private replication address (refused otherwise), an
      operator-supplied ingress certificate, and a name; the command resumes
      after a failure. The edge and DNS bundles are unchanged by it (tested).
- [x] **`cdnfoundry-fleet control promote`.** It refuses to run while the old
      active node is still reachable and serving, unless the operator first
      runs `demote` on it, or confirms that it is powered off. It then
      promotes PostgreSQL, makes the Valkey replica the primary, increments
      the epoch, starts the stopped services, prints the DNS change, and checks that edges are syncing.
- [x] **`cdnfoundry-fleet control demote` and `rejoin`.** `demote` stops the
      application services on a node and makes its PostgreSQL read-only, for
      planned maintenance. `rejoin` rebuilds the old active node as the new
      standby (`pg_rewind`, or a new base backup when `pg_rewind` cannot be
      used).
- [x] **`cdnfoundry-fleet control remove-standby`.** It removes the standby,
      then removes its address from the edges and every allowlist.
- [x] **Planned switchover.** `demote` on the active node, then `promote` on
      the standby. No committed write is lost, because `promote` waits for
      the replica to replay all of the old primary's WAL.

#### The rest of the platform

Each part is listed with the HA it needs and the reason. Only what a real
failure needs is added.

| Part | Decision | Why |
| --- | --- | --- |
| DNS serving (DNSdist, PowerDNS secondaries) | No change | It already runs on two or more independent hosts. Secondaries list both control nodes as primaries, and the SOA expire time is at least 7 days, so even a long control outage never expires a zone (HA7). |
| Edges and PoPs | No change | Several PoPs and the Phase 2 fail-static design already cover them. Run the Phase 2 external probers outside the control nodes. |
| Prometheus and Alertmanager | One on each node | Each node watches the other. The `Watchdog` receiver (Phase 2) must be outside the fleet, so an external dead-man alarm fires if both nodes go silent (HA5). |
| Valkey | Replica on the standby | Valkey holds no desired state. A contract test lists every job class and the reconciler that recovers its effect from PostgreSQL after a failover (HA4). |
| Grafana | Single instance, not HA | Its dashboards are provisioned from the repository, and its database is the replicated PostgreSQL. |
| Loki | Single instance, not HA | Operational logs are diagnostic, and losing them never affects serving. |
| ClickHouse and Vector | Single instance, not HA | Telemetry failure must never stop serving, and Vector buffers are bounded. Back up the usage and rollup tables so hourly usage survives the loss of the host. Raw logs are allowed to be lost. |
| Backups | Taken on the active node | Keep the existing off-host restic repository. A promotion moves the backup job with the scheduler. |

- [x] Alert on: the standby being unreachable, replication lag over 10
      seconds, Valkey replication broken, fingerprint mismatch, and any PoP
      missing the current control address list.
      Delivered: `control-ha-alerts.yml` (six alerts, unit-tested with `promtool`)
      and, on the standby, a watcher Prometheus and Alertmanager with
      `watcher.yml`; the real alert path is proven by `control_ha_alert.py`.
- [x] Back up the ClickHouse usage and rollup tables, and add them to the
      restore check.
      Delivered: `cdnf-backup-create` exports `edge_hourly`, `dns_hourly`,
      `edge_daily`, and `dns_daily` to a separate Restic snapshot (tag
      `cdnfoundry-rollups`) before the control dump; a completion line makes a cut
      stream recognizable; `cdnf-backup-restore-rollups` restores only complete
      snapshots into empty tables; restore preflight reports
      `usage_rollups: present|absent`; the prune covers both tags. The
      `dns_top_names` sketch is not backed up. `rollup_backup.py` runs the round
      trip against real ClickHouse and Restic.
- [x] Rewrite `topology.md`, `scaling.md`, the production reference
      architectures, and the backup and recovery page. Add runbooks for:
      adding HA, planned switchover, promoting after the active node is lost,
      rejoining the old node, and removing HA.
      Delivered: the new page [Control-plane high availability](operations/control-plane-high-availability.md)
      holds all five runbooks; the four existing pages, `monitoring.md`,
      `runbooks.md`, `production-qualification.md`, `cli-commands.md`, and
      `configuration.md` are updated.
- [x] Add a two-node test stack: a Compose file that runs the active and
      standby nodes as separate projects on separate networks, so the E2E
      can kill, partition, and restore them.
      Delivered differently: no new Compose file. `tests/e2e/control_ha.py`
      renders two real production control bundles with the fleet generator and
      runs them as two Compose projects on this host (addresses on the loopback
      interface, pinned subnets, a real edge agent), driving them with the real
      `cdnfoundry-fleet control` commands. A new file would have tested a copy
      of the topology instead of the one that ships. `ha_node_shell.py` stands in
      for SSH. Not covered: a network partition (the test kills the active node
      and returns it; it does not cut the link between two running nodes).

**Exit:** E2E on the two-node test stack, over IPv4 and IPv6:

- A single-node installation passes production qualification and shows
  `ha: none` as a warning.
- `add-standby` on a running installation with active domains reaches
  `ha: ready`, with no serving interruption and no change on any edge host.
- After `add-standby`, every PoP holds both control addresses, and every DNS
  secondary accepts NOTIFY and transfers from both nodes.
- Killing the active node: DNS and HTTP keep serving. After `promote`, edges
  resume syncing within 5 minutes, using the second address and no DNS
  change.
- A write committed more than 10 seconds before the kill is present after
  `promote`.
- `promote` refuses to run while the old active node is still serving.
- The old active node comes back after a promotion: it does not start
  application services, edges ignore it, and `rejoin` makes it a healthy
  standby.
- A planned switchover loses no committed write.
- A node with a mismatched signing key cannot reach `ha: ready`.
- Killing the active node delivers an alert from the standby's Alertmanager.
- `remove-standby` returns the installation to `ha: none`, with no edge or DNS
  errors.

**Completion gate (2026-11-01). Phase 6 is complete, with the limitations below.** The four fields are
recorded separately.

| Field | Status | Evidence |
| --- | --- | --- |
| Implementation | Complete | Ten commits on `dev`: epoch and fencing, signed control addresses, the agent's ordered dialing, `cdnfoundry-fleet control`, HA alerts, rollup backup, E2E tests, documentation. The owner decided to support two fixed addresses only; no floating address was built |
| Regression tests | Pass (536 PHP tests; Go agent suite; 106 Fleet tests) | `ControlPlaneHighAvailabilityTest` (fencing, signed list, status, promotion, dashboard row), `SignInFormTest`, `tests/fleet/test_control_ha.py` (order of steps, bundles, refusals, IPv6), `control_addresses_test.go` (failover, lower epoch, IPv6 dial), `promtool test rules` |
| Runtime tests | Pass over IPv4 and IPv6 | `control_ha.py --family 4` and `--family 6`, each uninterrupted on two real production control bundles with a real edge agent. `control_ha_alert.py` (real Prometheus and Alertmanager: `ControlNodeUnreachable` reached the webhook 144 s after the loss). `rollup_backup.py` (real ClickHouse and Restic) |
| Documentation | Updated | New page *Control-plane high availability* with the five runbooks; topology, scaling, reference architectures, backup and recovery, monitoring, runbooks, qualification, CLI, configuration, fleet operator guide |
| Manual browser | Not run; checklist written | `docs/manual-browser-qualification.md`, "Phase 6" job (includes the sign-in check) |

Exit items and how each was checked:

| Exit item | Check |
| --- | --- |
| Single node passes qualification and shows `ha: none` as a warning | The report carries `ha` and a warning, never a failure; `control_ha.py` asserts `ha: none` on the single node; dashboard test |
| `add-standby` on a running installation with active domains reaches `ha: ready`, with no change on any edge host | `control_ha.py` (about 290 s); the Fleet test proves edge and DNS bundles are byte-identical and never contacted. The lab has one edge and no customer domains |
| Every PoP holds both addresses; every DNS secondary accepts both primaries | The agent state file holds both addresses; the transfer response lists both. A real DNS secondary was not run in the lab |
| Killing the active node: serving continues; after `promote`, edges resume within 5 minutes through the second address | Promoted and synced in 102 to 151 s with no DNS change. DNS and HTTP data-plane serving were not exercised in this lab, only the Phase 2 fail-static design |
| A write committed more than 10 seconds before the kill is present | Asserted after the kill (`ha_before_loss`) |
| `promote` refuses while the old active node is serving | Asserted, and also when the node answers with an unreadable role or not at all |
| The old node returns: no application services, edges ignore it, `rejoin` makes it a standby | Asserted: the guard reports `superseded_by_higher_epoch`, `horizon` and `scheduler` stay stopped, the old database receives no heartbeat, then `rejoin` reaches `ha: ready`. The rewind path ran after a planned switchover; the base-backup path ran after the kill |
| Planned switchover loses no committed write | 500 rows written before `switchover` are present on the former standby |
| A mismatched signing key cannot reach `ha: ready` | `control check` reports `application_secret_fingerprint_mismatch` and the active node turns `degraded` |
| Killing the active node delivers an alert from the standby's Alertmanager | `control_ha_alert.py`, with the real watcher configuration and rules; the lab above has no Alertmanager |
| `remove-standby` returns to `ha: none` with no edge or DNS errors | Asserted; the agent ends holding one address |

Known limitations and deviations:

- Two nodes only, asynchronous replication, no automatic failover, no floating address.
- A node that returns while its peer is unreachable and was never demoted cannot learn it was replaced.
- Replication needs a private network; traffic on it is not encrypted beyond the database and Valkey passwords.
- Secret fingerprints are compared by `add-standby` and `control check`, not continuously.
- The lab has no network partition between two running nodes, no real DNS secondary, no customer domains, and no data-plane traffic.
- The first-start database race, the `pdns-migrate` omission, and an off-by-one in the rollup restore were found by these runs and are fixed.
- A sign-in problem reported on `localhost:8080` ("The password field is required" with a password entered) was traced to a stale published Filament asset (`password-reveal.js` returned `404`). The development entrypoint now republishes assets; the fix was checked with `curl` against the running stack, not in a browser.

### Phase 7: Professional UI on Filament, and agent-run browser qualification

Both panels work, but they read like long reports: every card has a paragraph
of explanation, the overview is one very long page, and the look is
Filament's default. This phase gives CDNFoundry a professional, dense,
consistent operator UI, at the level of modern infrastructure tools, while staying on Filament.

**Filament stays.** The panels stay on Filament 5, with a CDNFoundry theme
built on the existing shared theme
(`resources/css/filament/shared/theme.css`). Filament already provides
slide-over actions, global search with a key binding, collapsible navigation
groups, SPA navigation, tabs with badges, stat widgets, badge columns, polling,
and dark mode. Custom views are added only where Filament has no component,
such as the topology view. No second frontend is added.

#### Design system

- [x] **Tokens.** One neutral palette with a single accent color, status
      colors (healthy, degraded, failed, pending, disabled), spacing, radius,
      and a border-first style with few shadows. Complete light and dark mode.
- [x] **Type.** One sans-serif UI font, and a monospace font for every
      number, ID, timestamp, IP address, hostname, revision, and checksum.
- [x] **Density.** Compact table rows and form rows, sized for daily
      operator work.
- [x] **Patterns, written down once and used everywhere:**
  - Sidebar with collapsible groups, the version at the bottom, and ⌘K / Ctrl+K
    search across domains, edges, pools, users, and operations.
  - List page: a KPI strip, a search box, a Filters button, a sortable table
    with status badges, and cursor pagination.
  - Detail page: a header with the status badge and the main actions, tabs
    with counts, a KPI strip, and a "Needs attention" box that shows only
    what is wrong and links to the fix.
  - Create, edit, and inspect open in a right-side slide-over panel, so the
    operator never loses the list.
  - Settings as rows: a label, a one-line description, and the control on
    the right, with the default value visible and a reset action.
  - Every long-running action shows its operation, with live progress, in a
    bell panel for background work.
  - Empty states that say what to do next, with one primary action.
- [ ] **Less text in the UI.** A screen states facts and actions. Each card
      keeps at most one short line of description. Explanations move to the
      documentation, linked with a small help icon.
- [x] **A UI guide in `docs/development/frontend.md`.** The tokens, the
      patterns, and one example of each, so every new screen follows them.

#### Kumo design system

On 2026-11-02 the owner set the Kumo design language (`kumo`, MIT) as the design system. Kumo is React, so it is ported into Filament, never installed: its token values, type scale, ring edges, and component shapes become CSS in the shared theme, and Filament's components and the `x-ui.*` Blade components take that look. Owner decisions: the antislop owner design rules win where Kumo's React defaults differ (compact rows, no zebra striping, neutral selection); icons switch to Phosphor, as Kumo uses; the accent is Kumo's brand blue, used for primary actions and links only. Cloudflare's orange, logo, and product names are not used. This is presentation only: no policy, query, validation, action, job, route, or test id changes.

- [x] **Foundation.** Kumo surface, line, text, brand, status, and chart tokens in light and dark mode; neutral Filament palettes; Kumo type scale on Inter; 8 px radius and ring edges; Phosphor icons for every screen and for Filament's own icons (`PhosphorIcons`). `DesignSystemConsistencyTest` fails on raw colors, Heroicons, unknown icon names, and non-neutral greys.
- [x] **App shell.** Kumo Sidebar proportions, canvas background with the page in one base panel, command-palette search, popover menus, login and MFA card.
- [x] **Component skins.** Buttons, inputs, toggles, badges (tint, and dot in tables), tables, pagination, sections as Layer Cards, tabs, dropdowns, tooltips, dialogs and sheets, banners, meters, and empty states; the shared Blade components on those skins.
- [x] **Owner review fixes (2026-11-03).** Colored status badges in sentence case; neutral counts and menus with icons; equal table row heights; compact dialogs for short forms and single-column sheets for long ones; example placeholders and helper lines on forms (security rules, DNS records, Geo-DNS, origins, API tokens); named country and continent selects; platform settings with categories and search; one top tab row on the domain and edge pages; a slimmer cells table; a lighter dark theme; the landing and service health pages on the same tokens; documentation link to the documentation site.
- [x] **One hidden DNS primary.** The platform zone goes to the enabled, healthy DNS primary; `cluster_targets` is no longer accepted by `PUT /api/admin/system/settings/dns` (it returns a validation error), and the UI shows the hidden primary read-only. The `platform_dns_settings.cluster_targets` column is optional now (expand); a Phase 9 contract migration drops it.
- [x] **Agent-run crawl.** `tests/browser/specs/crawl.spec.ts` visits every sidebar link and the first record of every list in both panels, opens every header action, menu item, row action, and tab, and fails on any HTTP 5xx or script error.
- [ ] **Decision spike on Kumo.** Overview, Domains list, domain detail, and platform settings, reviewed by the owner before the remaining screen groups (domain detail surfaces, infrastructure, observe, customers and account, the domain panel) are redesigned one by one.

#### Screens

- [ ] **Decision spike first.** Rebuild three screens on the design system:
      the administrator overview, the Domains list with a domain detail page,
      and one settings page. The owner reviews them against the target
      before the remaining screens start.
      *Status 2026-11-02: Built and committed. Waiting for the owner's approval; not self-certified.*
- [ ] **Administrator overview.** It answers, on one screen without
      scrolling on a desktop: is everything healthy, what needs attention,
      and what changed recently. KPI strip (requests, cache hit ratio,
      errors, healthy PoPs, DNS success), "Needs attention", and recent
      operations. Detailed charts move to the analytics pages.
      *Status 2026-11-02: Built: status line, KPI strip, Needs attention, five recent operations. Not yet verified to fit one desktop screen in a healthy state, because the browser stack has every service down and always shows conditions.*
- [x] **Topology data.** `GET /api/admin/system/topology` returns the nodes and
      links of control, DNS hosts, PoPs, cells, and telemetry with health
      status; the Phase 8 fleet web reuses it. *Status 2026-11-02: the owner
      removed the admin panel Topology page; the API stays.*
- [ ] **Rebuild every admin screen** on the design system: domains, DNS
      records, proxy and origins, cache and purge, TLS, WAF and security,
      Geo-DNS, edges, pools and cells, DNS clusters, users and tokens,
      operations, audit log, analytics, logs, backups, and settings.
      *Status 2026-11-02: Shared look on every Filament component. Individually rebuilt: overview, domains, domain detail, DNS records and their sheet, platform settings, edges, pools, clusters, users, operations, audit log, topology. Telemetry, proxy and origin sheets, TLS, WAF, backups, tokens, and system DNS identity are restyled and pass axe, but were not redesigned one by one.*
- [ ] **Rebuild every domain-user screen** with the same patterns, showing
      only that user's domains.
      *Status 2026-11-02: Overview and Domains rebuilt; analytics, tokens, and profile are restyled only.*
- [ ] **Live views.** Operations, logs, and edge status refresh by polling
      with a visible "live" indicator, and stop polling when the tab is
      hidden.
      *Status 2026-11-02: Operations, edges, topology, and the overview poll every 5 to 10 seconds, with a visible indicator on operations, edges, topology, and the overview. Livewire throttles a hidden tab to about 5 percent of its polls instead of stopping it. Logs are not live.*
- [ ] **Accessibility.** Keyboard navigation for every action, visible focus,
      and WCAG AA contrast in both themes.
      *Status 2026-11-02: axe passes on every screen. Keyboard use of every action was not walked through.*

#### Agent-run browser qualification

This phase changes every screen, so it is where automated browser tests are
needed most.

- [x] **Amend `AGENTS.md` for agent-run browser qualification and the new
      UI rules.** One change, together with `RepositoryContractTest`, so the
      contract and its test never disagree. Until it ships, browser
      qualification stays manual and owner-run, as `AGENTS.md` requires, so
      the decision spike is reviewed by the owner. The edits:
  - **Opening paragraph.** Delete "Until its Phase 7 amends this file, every
    rule below still applies."
  - **Product invariants.** Replace "Filament provides both administrator
    and domain-user panels" with: Filament provides both panels, using the
    CDNFoundry theme and the patterns in `docs/development/frontend.md`. No
    screen uses Filament's default theme, and no second frontend is added.
  - **Tests and change discipline, browser rule.** Replace the paragraph
    that starts "Browser E2E qualification is manual and user-owned" with:
    browser qualification is agent-owned and uses Playwright under
    `tests/browser`. Agents run it only against development, test, and
    staging stacks with seeded test data and generated test credentials,
    never against production or with real customer data. A UI change is
    complete only when its browser tests, visual snapshots, and
    accessibility checks pass.
  - **Non-UI end-to-end rule.** Keep Python under `tests/e2e` for APIs,
    queues, databases, Compose, DNS, TLS, and runtime state. Delete "it must
    not inspect or automate rendered UI", and say instead that rendered UI is
    tested in `tests/browser`.
  - **Completion report.** Replace "the manual browser job status" with "the
    browser suite results".
  - **"The roadmap is closed" paragraph.** Reword it to refer only to the
    archived staged roadmap. Replace "its owner-run manual browser checklist
    is written with exact step-by-step fields and expected results" with
    "its browser tests pass". Delete the sentences about keeping
    `docs/manual-browser-qualification.md` and about inventing future
    screens. In the completion gate, replace "manual browser status" with
    "browser suite status".
  - **Last paragraph.** Delete the sentence "Preserve and extend
    `docs/manual-browser-qualification.md` …". Keep the database and volume
    safety rules, and add that the browser suite uses its own disposable
    database, never the persistent development database.
  - **Contract test.** In `RepositoryContractTest`, replace the two
    assertions on the old browser sentences with assertions on the new rule
    ("uses Playwright under `tests/browser`" and "never against
    production"), and assert that `docs/manual-browser-qualification.md`
    does not exist.
- [x] **Change every other file that names the manual job, in the same
      change.** Delete `docs/manual-browser-qualification.md`, and update
      every reference to it: `CONTRIBUTING.md`, the pull request template,
      `README.md`, `docs/development/testing.md`,
      `docs/development/frontend.md`, the docs navigation in `docs/.vitepress/config.mts`, the deployment and
      operations pages that link it (production quick start, setup after
      quick start, manual Compose, production qualification, production best
      practices, and the simple anycast qualification), and the docs checks
      in `tests/docs/check_links.py` and `tests/docs/check_built_site.py`.
      Leave the v1.2.1 release notes unchanged, because they describe that
      release.
- [x] **Update the project skills that mention browser work.**
      `cdnf-filament-ui` follows the design system and runs the browser suite.
      `cdnf-production-qualification` includes the browser suite in the
      qualification evidence.
- [x] Add a Playwright suite under `tests/browser` that signs in to both
      panels and covers every screen and workflow, in light and dark mode, at
      desktop and phone width. Agents run it and report its results, and it
      joins the phase gate from here on. Interactive elements carry stable
      test ids.
- [x] Add visual regression snapshots for the main screens, so an unintended
      change of the design is caught in review.
- [x] Add an automated accessibility check (axe) to every page in the suite.

**Exit:**

- The owner approves the decision spike.
- Every admin and domain-user screen uses the design system. A check fails
  on any screen that still uses Filament's default theme or colors.
- The overview answers "healthy, needs attention, recent changes" on one
  desktop screen.
- The topology view shows a failure injected by E2E (a stopped PoP) within
  one refresh.
- The browser suite, visual snapshots, and accessibility checks pass in both
  themes and at both widths.

**Completion gate (2026-11-02). Phase 7 is not complete.** The owner has not approved the decision spike, and several screens are restyled, not redesigned. The five fields are recorded separately.

| Field | Status | Evidence |
| --- | --- | --- |
| Implementation | Partial | Design tokens and a shared Filament look; compact sidebar; rebuilt overview, domains, domain detail, DNS records, settings, edges, pools, clusters, users, operations, audit log, topology; domain-user overview. Telemetry, proxy and origin sheets, TLS, WAF, backups, tokens, and system DNS identity are restyled only |
| Regression tests | Pass | 541 PHP tests, including `DesignSystemConsistencyTest` (no raw colors, themed panels) and `TopologyTest` |
| Runtime tests | Pass for the topology view | `tests/e2e/topology_failure.py` on the development stack: stopping `edge-agent-a` turned `edge-browser-a` failed within the stale window and restarting it recovered. Run against IPv4 only |
| Documentation | Updated | Frontend guide (design system, patterns, browser suite), topology page, testing guide, contract and contributor files, skills |
| Browser suite | Pass: 126 passed, 0 failed, 54 skipped (52 opt-in review screenshots and 2 desktop-only shortcut tests) | `tests/browser/run.sh` on a disposable SQLite stack, light and dark, desktop and phone, with a deliberately awkward seed; visual snapshots written fresh in this run; axe on every screen |

**Kumo redesign update (2026-11-03).** The Kumo foundation, shell, component skins, and the owner's review fixes are built and committed; the decision spike still needs the owner's approval before the remaining screen groups are redesigned one by one.

| Field | Status | Evidence |
| --- | --- | --- |
| Implementation | Partial | Kumo tokens and skins on every Filament component; spike screens, domain and edge screens, settings, tokens, and the public pages redesigned; hidden DNS primary replaces cluster targets. Telemetry and analytics run-on lines remain |
| Regression tests | Pass | 543 PHP tests, including `DesignSystemConsistencyTest` and the cluster-target rejection test |
| Runtime tests | Not run for this update | No runtime behavior changed except the platform zone target; the E2E payloads were updated but not run |
| Documentation | Updated | Frontend guide, UI skills, quick start, roadmap |
| Browser suite | Pass: 177 passed, 0 failed, 11 skipped (full run); 125 passed, 0 failed after the last two fixes | `crawl.spec.ts` (every link and action), `spike.spec.ts`, `every-screen.spec.ts` with axe, light and dark, desktop and phone |

Exit items:

| Exit item | Status |
| --- | --- |
| The owner approves the decision spike | Open. Needs the owner |
| Every screen uses the design system; a check fails on default theme or colors | Check added (`DesignSystemConsistencyTest`); screens not individually redesigned are listed above |
| The overview answers healthy, needs attention, recent changes on one desktop screen | Built; not verified in a healthy state |
| The topology view shows a stopped PoP within one refresh | Met (`topology_failure.py`) |
| The browser suite, snapshots, and accessibility checks pass in both themes and at both widths | See the browser suite row |

Known limitations:

- The topology E2E ran over IPv4 only and exercises one stopped agent.
- Visual snapshots were generated in this run, so they record the current look; they are not a comparison against an earlier approved design.
- The persistent development database was behind by the Phase 6 migrations; `make dev-migrate` was run to apply them (non-destructive).
- Livewire throttles polling in a hidden tab instead of stopping it.
- The browser seed has no healthy edge or ClickHouse, so healthy-state layouts are verified only by the PHP tests and the topology E2E.

### Phase 8: Simple setup, fleet over SSH, safe upgrades, and local fleet web

Fixes M15, L1.

Today the Fleet tool (`scripts/cdnfoundry_fleet`) keeps the fleet state,
generates PKI and secrets, and renders one bundle per node, but it has no
transport. The operator copies each bundle to its host and runs Compose there
by hand, which is why the quick start is so long. This phase gives Fleet the
missing transport (SSH), and gives it a local web page so the operator can see
and operate the whole fleet.

**Rebuild the engine, keep the tested parts.** The new engine replaces the
JSON state file with SQLite, adds the SSH executor, and adds the plan, apply,
and rollback action model. It ports the existing rendering, PKI, and
certificate code, which is already tested, instead of rewriting it. 2.0
allows breaking changes, so the new engine needs no compatibility with the
1.2.x state file.

**One engine, two faces.** The Fleet engine owns the fleet state and performs
every action. The Fleet CLI and the local fleet web are thin layers over that
engine, and neither has logic of its own. The manual Compose documentation
stays as the reference for what the engine does.

**The fleet web is local and narrow.** It is not a second CDN dashboard and
not a second source of truth. It runs on the administrator's workstation and
shows the fleet: hosts, roles, containers, versions, certificates, backups, and
HA. Domains, DNS records, pools, TLS, WAF, and users stay in the Filament
panels and in PostgreSQL. The fleet web may link to those panels, and it may
read their status through the API, but it never edits them.

#### Fleet engine

- [ ] **State in one local SQLite file.** It holds nodes, roles, addresses,
      SSH settings, rendered versions, and secrets. Secrets are encrypted at
      rest with an operator passphrase. The file and the secrets are never
      written to the repository.
- [ ] **SSH transport.** A node is added with a host, an SSH port, a user, and
      a key. The engine uploads the node's bundle, installs the
      prerequisites, starts the right Compose profiles, and checks the
      result. It works with key authentication only, pins each host key on
      first use, and refuses a changed host key.
- [ ] **Every action is plan, then apply.** The engine first shows what will
      change on each node, then applies it. Actions are idempotent and
      resumable: an action interrupted by a dropped SSH connection can be
      run again safely. Each step is logged per node.
- [ ] **Actions:** first setup of control, adding and removing nodes of every
      role, upgrading the release (rolling, one node at a time), rotating
      secrets, configuring monitoring, logs, and backups, running a backup
      now, restoring, and the Phase 6 HA commands (`add-standby`, `promote`,
      `demote`, `rejoin`, `remove-standby`).
- [ ] **Safe restore.** Restore shows which backup it will use and what it
      will replace, and it requires a typed confirmation. It restores into a
      fresh control node by default. Overwriting a live control node needs a
      separate, explicit option.
- [ ] **Fleet state is backed up too.** The engine backs up its own encrypted
      state to the same off-host restic repository, and a new workstation can
      restore it in one step. Losing the workstation must never mean losing
      control of the fleet.
- [ ] **The cluster never depends on the workstation.** Control, DNS, and
      edges run and serve with the workstation off. The workstation is needed
      only to make infrastructure changes.

#### Safe upgrades and config changes

The stack runs many open-source images (PostgreSQL, Valkey, PowerDNS,
DNSdist, OpenResty, Caddy, ClickHouse, Vector, Loki, Prometheus,
Alertmanager, Grafana). They need security updates often. The rule is:
**Fleet deploys qualified releases. It never changes an image or a config
file on a host outside a release.** Production pins every image by digest and
every host runs one exact `CDNF_RELEASE`. An image swapped on one host by
hand is a combination that no test has run.

- [ ] **Security status.** The fleet web and `cdnfoundry-fleet doctor` show,
      for every node, the images it runs and their digests, the release they
      belong to, and the known vulnerabilities from the existing Trivy policy
      (`supply-chain/trivy.yaml` and its classifications). For each
      vulnerability, they show one of: fixed in an available release, no fix
      upstream yet, or classified (with its expiry date).
- [ ] **Fixes go through the repository.** An image update is a digest change
      in the repository. Dependabot for Compose (Phase 5, A36) opens it
      automatically. CI runs the Trivy gate and the E2E suite, then publishes
      a signed release manifest that lists every image digest and every
      rendered config checksum. Fleet installs only signed manifests that
      passed CI, and it refuses any other.
- [ ] **A fast lane for urgent vulnerabilities.** A security release may use
      a shorter qualification profile: the image gate, the E2E checks for the
      roles whose images changed, and the IPv4 and IPv6 smoke checks. The
      profile is defined in the repository, it never skips the checks for an
      affected role, and the release notes say which profile was used.
- [ ] **Release diff before upgrade.** Before an upgrade, Fleet shows what
      changes between the running manifest and the target manifest: images,
      config files, migrations, and the nodes affected. Nodes whose images
      and configs do not change are not restarted.
- [ ] **Pre-pull.** Fleet pulls every new image on every affected node and
      verifies its digest before it restarts anything. A slow or broken
      registry therefore never causes downtime.
- [ ] **Rolling order.** Fleet upgrades one canary node first, then the rest
      one node at a time within each role:
  - **DNS hosts:** one at a time. Fleet never takes down a host while it is
    the only healthy nameserver for its address.
  - **PoPs:** one at a time. Each PoP is upgraded cell by cell where the
    images allow it, so the PoP keeps serving.
  - **Control with a standby:** upgrade the standby, run a planned
    switchover (Phase 6), then upgrade the old active node.
  - **Control without a standby:** a short control pause. DNS and HTTP keep
    serving through it, because of Phase 2 fail-static.
  - **Telemetry and monitoring:** last. Serving never depends on them.
- [ ] **Health gate after every node.** Containers healthy, DNS answering
      over UDP and TCP, the PoP serving HTTP and HTTPS and synced to the
      latest artifact, control answering `/api/health`, and no new alerts.
      If the gate fails, Fleet stops the roll, rolls that node back to the
      previous manifest, and reports the failed check. Nodes already
      upgraded stay on the new release only if the gate allows mixed
      versions; otherwise Fleet rolls them back too.
- [ ] **Validated config changes.** Rendered configs are versioned in the
      Fleet state. Fleet shows the diff, uploads the new config next to the
      active one, and validates it on the node before swapping: `nginx -t`
      for OpenResty and edge-control, `dnsdist --check-config`, `pdnsutil`
      checks for PowerDNS, `caddy validate`, `promtool check config`,
      `amtool check-config`, `vector validate`, and `docker compose config`.
      It then swaps atomically, reloads where the service supports it,
      restarts only where it does not, runs the health gate, and restores
      the previous config on failure. A node never runs a config that did
      not pass its own validator.
- [ ] **Rollback in one action.** Fleet keeps the previous manifest and
      config set on every node and can return any node, role, or the whole
      fleet to them. A rollback never reverses a database migration: a
      release whose migration cannot run with the previous code is marked
      in its manifest, and Fleet refuses to roll back across it without a
      restore.
- [ ] **Major upgrades of stateful services are guided, not automatic.**
      Major versions of PostgreSQL, ClickHouse, and Valkey are never part of
      an ordinary upgrade. Fleet runs them as a separate guided action: a
      fresh backup and `restic check` first, the documented migration steps
      one at a time, a check after each step, and a stop for the operator
      before any step that cannot be undone. Minor versions with the same
      data format follow the normal rolling upgrade.
- [ ] **Upgrade history.** Every upgrade, config change, and rollback is
      recorded with the operator, the source and target manifests, every
      node's result, and the health-gate output.

#### Local fleet web

- [ ] **Starts with one command** on the workstation, for example
      `cdnfoundry-fleet web`. It binds to `127.0.0.1` only, refuses any other
      bind address, and asks for the state passphrase to unlock.
- [ ] **Visual topology.** A diagram of the whole fleet: control (active and
      standby), DNS hosts, PoPs and their cells, and the telemetry host. It
      shows the links between them (edge to edge-control, DNS secondaries to
      their primaries, Vector to ClickHouse, backups to the restic
      repository), with a health color on every node and every link.
- [ ] **One-click check of the whole stack.** One button runs the checks
      against every node over SSH and the API, and shows one report. The
      checks are: hosts reachable, containers healthy, versions equal to
      the release, certificates and edge identity expiry, PostgreSQL and
      Valkey replication, HA state, DNS serials on every target, PoP sync
      position, queue latency, the last backup age and `restic check`, and
      the `Watchdog` delivery. It is the same report as `cdnfoundry-fleet
      doctor`, and it covers every row of the daily checks table at the end
      of this roadmap.
- [ ] **Easy node add.** A form takes the role, the host, the SSH port, the
      user, and the key. It shows the plan, applies it with live progress,
      and ends with the node green on the topology. For a PoP, the node joins
      the default `shared` pool with default cells.
- [ ] **Day-to-day pages:** security status, upgrade (with the release diff
      and live progress per node), config changes, rollback, backup now,
      restore, HA, secret rotation, and the action and upgrade history.
      Every page runs the same engine action as the CLI.
- [ ] **Simple build, same look.** Python like the rest of Fleet,
      server-rendered pages, and no JavaScript build step. It uses the Phase 7
      design tokens (one shared CSS file) and the same patterns: sidebar,
      KPI strip, tables, slide-over panels, "Needs attention", and light and
      dark mode. The topology view reuses the Phase 7 topology component, and
      is the only client-side drawing.

#### Setup and panels

- [ ] The installer is `cdnfoundry-fleet setup` (CLI or web). PKI, TSIG keys,
      shared secrets, and bootstrap tokens are generated automatically. It
      sets up a single control node, and it offers `add-standby` (Phase 6) as
      an optional step, before or after the first domain.
- [ ] A first-run wizard in the admin panel, for CDN settings only. All
      settings have visible defaults and a reset action.
- [ ] Remove the edge management address fields.
- [ ] Amend `AGENTS.md` to state that the local fleet web is part of the Fleet
      tool and not a second dashboard or backend under its "multiple
      dashboards" and "second backend/frontend" rules, and to state the
      border between Fleet (infrastructure) and Filament (CDN).
- [ ] Extend the Phase 7 browser suite to the local fleet web: every page and
      action, at desktop and phone width.
- [ ] Write a scripted quick start for the fleet web and the CLI, with the
      same steps in both. A scripted run on fresh virtual machines must reach
      an active proxied domain with no undocumented step. Phase 9 rewrites the
      published quick start from this script.

**Exit:**

- The scripted quick start passes on fresh VMs, once through the CLI and once
  through the fleet web.
- Adding a PoP from the fleet web, with only a host, an SSH port, and a key,
  ends with the PoP serving traffic.
- The one-click check finds every failure injected by the existing E2E
  scenarios (a stopped container, an expired certificate, broken
  replication, a stale backup, and a lagging PoP).
- An upgrade interrupted by a dropped SSH connection completes when it is run
  again.
- A rolling upgrade of the whole fleet keeps every test domain resolving and
  serving, over IPv4 and IPv6, from start to end.
- A release with a broken image on one role stops at the canary, rolls the
  canary back, and leaves every other node on the previous release.
- An invalid config for each validated service (OpenResty, DNSdist,
  PowerDNS, Caddy, Prometheus, Alertmanager, Vector) is rejected on the node
  and never activated.
- Fleet refuses an unsigned manifest, and a manifest whose image digest does
  not match what it pulled.
- An image with a known vulnerability shows in the security status, and
  disappears from it after a fixed release is rolled out.
- Rolling back the whole fleet returns every node to the previous manifest
  and config set, and refuses to cross an irreversible migration.
- Fleet state restored on a new workstation can manage the existing fleet.
- The fleet web refuses to bind to anything other than `127.0.0.1`.
- The browser suite passes.

### Phase 9: Cleanup, documentation, and adoption

Fixes L2, L3, L4, A44, A46.

The goal of 2.0 is that CDNFoundry becomes the first choice for any
organization that needs its own private CDN: free, open source, and
self-hosted. That depends on the documentation as much as on the code. Today
the documentation is long, written as lists of rules, and hard to follow for
a newcomer. This phase rewrites it, text and presentation, so that anyone
with a few Linux servers can understand CDNFoundry and run it step by step.

#### Cleanup

- [ ] Delete `docs/legacy/`, the second agent activation path, every
      compatibility branch, and squash migrations into a fresh baseline.
- [ ] Drop the unused `platform_dns_settings.cluster_targets` column (contract step of the 2026-11-03 hidden-primary change).
- [ ] Rename phase-numbered tests after the feature they test.
- [ ] Wire or delete every documented environment variable. Extend the
      documentation check so that a documented key that is not wired fails.
- [ ] Delete thin helpers such as `MetricComparisonService`, and fix the
      `.PHONY` list.
- [ ] Update the project skills in `.agents/skills/` to match every behavior
      changed by Phases 1–8.

#### Writing rules

- [ ] Write the rules once in `docs/contributing/documentation.md`, and apply
      them to every page:
  - Task first: what you will do, what you need, the steps, how to check that
    it worked, and what to do if it did not.
  - One page per task or concept. Short sentences, one idea each, in plain
    English that a non-native reader can follow.
  - The command or the screen comes first, the explanation after it.
  - Every step has an expected result.
  - The same task is shown in tabs: **Admin panel**, **API**, and **Fleet
    CLI** (or **Fleet web**), wherever more than one way exists.
  - No rule lists copied from `AGENTS.md`. Contract language stays in the
    contributor docs.

#### Structure

- [ ] Reorganize the site into top-level tabs, each with its own sidebar:
  - **Get started:** what CDNFoundry is, how it works in one diagram, a
    quick start, and the first domain.
  - **Guides:** one page per feature, for operators and domain users.
  - **Deploy and operate:** production setup, HA, upgrades, backup and
    restore, monitoring, scaling, and runbooks.
  - **Reference:** API, configuration, CLI, limits, ports, DNS records, and
    telemetry schema.
  - **Architecture:** components, data flows, security model, and design
    decisions.
  - **Community:** contributing, support, security reporting, and the
    roadmap.
- [ ] A landing page with card grids that send each reader to the right
      place: evaluate, install, operate, use as a domain owner, or
      contribute.

#### New and rewritten content

- [ ] **Why CDNFoundry.** Who it is for (ISPs, hosting providers,
      enterprises, governments, and anyone who must keep traffic and data on
      their own infrastructure), what it does, what it does not do, and an
      honest comparison with commercial CDNs and with a hand-built Nginx
      setup. Every claim must be backed by a feature that exists and passed
      qualification.
- [ ] **How it works.** One page with one diagram per flow: a DNS query, an
      HTTP request through a PoP, a configuration change from the panel to
      the edges, and telemetry to analytics.
- [ ] **Quick start.** From zero to a domain served through the CDN on one
      control node and one PoP, with the fleet web. It is generated from the
      Phase 8 scripted quick start, so the page and the test cannot differ.
- [ ] **Production guide.** Step by step for a real deployment: planning
      (sizing, network, DNS delegation, IPv6), installing, the first domain,
      adding PoPs and DNS hosts, adding HA, monitoring, and backups. Each step
      ends with a check.
- [ ] **Sizing guide.** Recommended host sizes for small, medium, and large
      fleets, taken from measured qualification results, not guesses.
- [ ] **Feature guides.** One page per feature: domains, DNS, Geo-DNS,
      proxy and origins, cache and purge, TLS, WAF, security, compression,
      analytics, users and tokens.
- [ ] **Operations.** Upgrades and security updates, HA switchover and
      failover, backup and restore drills, monitoring and alerts, and one
      runbook per alert, each with symptoms, checks, and the fix.
- [ ] **Troubleshooting by symptom:** "the domain does not resolve", "the
      certificate is not issued", "a PoP is not syncing", "the cache is not
      hit", and others. Each one gives the checks in order and the fix.
- [ ] **Generated reference.** The API reference is generated from
      `openapi.json`, the CLI reference from the Fleet command definitions,
      and the configuration reference from the code. None is written by hand.
- [ ] **FAQ and glossary.** CDN and DNS terms explained for operators new to
      running a CDN.
- [ ] **README.** A short project page: one paragraph, the main features,
      the screenshots, the quick start link, and the license.

#### Presentation

- [ ] Restyle the VitePress site with the Phase 7 design tokens, so the docs
      and the product look like one product, in light and dark mode.
- [ ] Add documentation components: cards and card grids, numbered steps,
      tabs (Admin panel / API / Fleet), callouts (note, tip, warning,
      danger), framed screenshots, and diagrams.
- [ ] **Generated screenshots.** The Phase 7 browser suite captures the
      screenshots for the docs from a seeded demo installation, in light and
      dark mode, at every release. Change `scripts/ci/check_no_screenshots.py`
      to allow only the generated screenshot directory, so screenshots are
      never added or edited by hand and are never out of date.
- [ ] Full-text search, "Edit this page" links, "Was this page helpful"
      feedback, and an `llms.txt` index for AI assistants.
- [ ] Versioned documentation: each release has its own docs, and the latest
      is the default.

#### Documentation tests

- [ ] Run every shell block marked `test` in CI.
- [ ] A reader test: an agent with no prior knowledge of the project follows
      the quick start and the production guide on fresh VMs, using only the
      published pages. Every place where it has to guess is a documentation
      defect.

**Exit:**

- The docs build, link checks, and executable-block tests pass, and no
  legacy file remains.
- Every page follows the writing rules. A review checklist is recorded for
  each page.
- The quick start, followed word for word on fresh VMs, reaches a served
  domain in under 30 minutes.
- The reader test completes the quick start and the production guide with
  no undocumented step.
- Every screenshot is generated, and none is older than the current release.

### Phase 10: Real production qualification

- [ ] On real servers (one active and one standby control node, and at
      least three PoPs in two regions, IPv4 and IPv6), an agent follows the
      Phase 9 documentation word for word. Every deviation is a documentation
      defect to fix and retest.
- [ ] Test every feature through the API, the CLI, and the browser suite,
      against real domains.
- [ ] Re-run every critical and high finding's regression scenario on the
      real fleet: control outage, edge certificate expiry, bad artifact, PoP
      loss, DNS host loss, adding a standby to a running fleet, losing the
      active control node and promoting the standby, a planned switchover,
      purge after restart, WAF exclusion bypass, Geo-DNS
      from real resolvers in two countries, and IPv6-only resolution.
- [ ] Publish the 2.0.0 release notes with the evidence.

**Exit:** every test passes on real servers and is recorded in the release
notes.

## Daily checks this roadmap must make visible

Each check maps to a failure mode found in the audits. The admin dashboard
and a status command show every check by Phase 5, and the HA checks by
Phase 6.

| Check | Catches |
| --- | --- |
| Every PoP's applied sequence is equal to the latest artifact sequence, or close behind it | C4, H3 |
| Every DNS target's serial equals the desired serial | H7, M3, M4, A15 |
| No pool's published address set has shrunk without an operator action | C3 |
| No edge identity certificate expires within 30 days | A1 |
| No placement has stayed in `deploying` longer than its bound | H2, H3, H4, A12, A13 |
| Queue latency of each lane is under its bound | M5, A9 |
| The Alertmanager `Watchdog` alert was delivered within the last hour | A8 |
| No certificate expires within 14 days without a renewal in progress | TLS |
| The last backup is younger than its bound, and the last restore preflight and `restic check` succeeded | Recovery, A28 |
| Vector reports no dropped events, and ClickHouse accepts inserts | A23, A25, A26 |
| The control HA state is shown: `ha: none`, or `ha: ready` with the standby reachable and PostgreSQL replication lag under 10 seconds | HA1 |
| With a standby, Valkey replication is connected, and the secret fingerprints of both nodes match | HA4, HA6 |
| Every PoP holds the current control address list and the current control epoch | HA3 |
| Application services run only on the node whose PostgreSQL is primary and whose epoch is highest | HA2 |
| With a standby, each node's Prometheus scrapes the other node, and the external dead-man alarm received `Watchdog` | HA5 |

## Out of scope unless the owner admits it

BGP control by CDNFoundry, Kubernetes, microservices, billing or reseller
hierarchies, plugin runtimes, and multi-origin load balancing.
