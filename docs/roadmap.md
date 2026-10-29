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

## Phase gate

A phase is complete only when all four fields pass:

| Field | Required evidence |
| --- | --- |
| Implementation | Merged to `dev`. Replaced code is deleted, not left behind a flag. |
| Regression tests | Each finding fixed in the phase has a test that failed before the fix and passes after it. |
| Runtime tests | Python E2E under `tests/e2e` passes on a real Compose stack, over IPv4 and IPv6. |
| Documentation | Every page affected by the change is rewritten to match the new behavior. |

From Phase 6 onward, agentic browser tests are a fifth gate field.

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

## Before Phase 1

These are not phase work. They are date-bound, or they correct public
statements about the current release.

- [ ] Re-assess the two Trivy classifications and renew or remove them before
      2026-11-28 (A45).
- [ ] Add a status banner to the README that links the open critical and high
      findings. It replaces the "production-oriented" framing until Phase 2
      exits.
- [ ] Owner decision: publish a security notice for 1.2.x about C1 and C2 in
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

- [ ] Remove private keys from snapshots and artifacts. Artifacts carry a
      certificate id. The agent fetches the key over mTLS from a key endpoint,
      encrypted to that edge's identity key, and the key is never written to
      PostgreSQL except in `tls_certificates`.
- [ ] Purge the existing key material from history. Since 2.0 is zero-state,
      this means only that the new schema has no column that could hold a key.
- [ ] Return revisions through an API resource with an explicit field list
      (revision, status, checksum, created_at, and a diff summary). Do the
      same for every endpoint that returns raw models.
- [ ] Add `$hidden` to every encrypted model column as a second safeguard.
- [ ] Tag DNS telemetry with `domain_id` at the DNS node, from the zone the
      authoritative server answered from. Scope analytics and logs by
      `domain_id` and by the time since the current owner's activation.
- [ ] Authenticate Vector's HTTP sources with a per-node token, and bind them
      to the cell network.
- [ ] Derive the edge `domain_id` at the cell from the active snapshot, never
      from the event payload.
- [ ] Split ClickHouse users: insert-only for Vector, with a separate secret
      per node; read-only with a server-side settings profile for Laravel;
      administrator for migrations only.
- [ ] Render `pdns.conf` secrets from Compose secrets in every production
      path, and refuse to start with the development values.
- [ ] Make WAF exclusions remove only the excluded rule (or target) for the
      matching request. Keep request-wide monitor mode only for the explicit
      profile mode. Until this ships, reject new exclusions with a stable 409.
- [ ] On TLS listeners, reject a request whose `Host` is not covered by the
      certificate selected for its SNI.
- [ ] State exactly what telemetry keeps, or truncate client addresses in the
      long-lived rollups.
- [ ] Rewrite rollback: restore only customer-owned fields, re-validate them
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

- [ ] **Fail static.** Withdraw a PoP from DNS only on a positive signal
      (drain, disable, or a failed external health probe), never on missing
      heartbeats alone. Never publish an empty answer set for a pool: keep
      the last published set and alert.
- [ ] Add external health probes, run from at least two vantage points, that
      check each PoP's HTTP/TLS listener directly. Withdrawal needs a quorum.
- [ ] Renew edge identity certificates over mTLS at two thirds of their
      lifetime. Accept the old and new fingerprints until the new one is
      proven. Report the expiry in the heartbeat and alert 30 days before it.
- [ ] Make the agent apply artifacts per domain. A bad artifact is rejected
      and skipped, the cursor advances, and the server records the rejection
      for that domain only.
- [ ] Make agent and server agree on one rejection-reason list, defined once
      and tested by a contract test. An unknown reason is stored, never
      returned as 422.
- [ ] Promote a placement when a quorum of participating PoPs (default: all
      that are healthy, at least one) have acknowledged. Lagging PoPs catch
      up without blocking the pool.
- [ ] Page promotion through all deploying placements in bounded chunks.
- [ ] Exclude withdrawn pools from automatic placement.
- [ ] Publish proxied records independently of placement: before a placement
      is active, render the default pool's hostname, so the zone always
      publishes.
- [ ] Make the gateway forward the bytes it has already buffered after the
      HTTP preface.
- [ ] Pass the real client address to the origin hop in one internal header,
      cleared at ingress.
- [ ] Use only the PROXY-protocol address, and trust PROXY headers only from
      the gateway. Accept `X-Forwarded-For` only from configured trusted
      proxies, taking the rightmost untrusted entry.
- [ ] Configure Alertmanager receivers from typed settings, add an
      always-firing `Watchdog` alert, and refuse a production configuration
      with no notifier.
- [ ] Set `retry_after` above every worker and job timeout, and assert that
      in `make config-check`.
- [ ] Store idempotency receipts only for 2xx and deterministic 4xx
      responses, filter lookups on `expires_at`, and run the throttle before
      the idempotency middleware.
- [ ] Add health checks for every long-running service, including agent,
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

- [ ] Restrict automatic placement to `shared` pools. Only explicit
      administrator actions select `quarantine`, `reserved`, or `dedicated`.
- [ ] Ship the default `shared` pool WAF-capable, or reject a WAF profile
      change with a clear 409 when no WAF-capable pool exists. Never accept
      work that cannot run.
- [ ] Define the cache key once (path normalization, query decoding, and
      ordering), built from the raw request path. Keep `%2F` and `%3F`
      encoded, and reject requests that would normalize differently from the
      origin request. Generate the PHP and Lua implementations' test vectors
      from one shared fixture file, and run it in both test suites.
- [ ] Make URL purges durable. The agent persists purge generations and
      replays them on cell start. Keep them in a dedicated shared dictionary
      that cannot be evicted by rate-limit counters, and give each entry a
      TTL equal to the cache's maximum TTL.
- [ ] Reject requests with more than 128 query arguments, or hash the full
      argument set into the key.
- [ ] Reject purge URLs that carry credentials or a fragment.
- [ ] Add DNS retry with backoff, plus a scheduled drift check that compares
      each DNS target's served serial and checksum with desired state and
      repairs differences.
- [ ] Mark a DNS operation failed only after its last try, hold a per-domain
      lock, and re-check the revision just before activation.
- [ ] Derive the platform zone serial from a content checksum, so it changes
      exactly when the content changes.
- [ ] Send the client subnet from DNSdist to PowerDNS (`useClientSubnet` or
      PROXY protocol), so Geo-DNS sees the client network.
- [ ] Authorize operation reads through the operation's domain policy, and
      store the domain id as a typed column with a partial unique index for
      pending operations.
- [ ] Implement each accepted emergency action, or reject it with a stable
      code and remove it from the documentation.
- [ ] Return an operation id with every 202. Add stable codes for 405, 413,
      429, and 500. Return 404 for unauthorized bindings, and hide model
      class names.
- [ ] Audit every mutation, and allow `usage.rebuild` to be retried.
- [ ] Verify uploaded chains against the system trust store, with the
      uploaded intermediates as untrusted. Honor ACME `Retry-After`, reuse
      `Replay-Nonce`, and lock ACME account creation.
- [ ] Replace first-claim-wins with re-delegation proof. Each platform
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

- [ ] Move DNS to a hidden-primary design. Laravel writes to one private
      primary. DNS nodes are PowerDNS secondaries that use a catalog zone and
      TSIG-signed transfers, triggered by the PoP agent, outbound only. This
      removes the per-host API gateways and `dns-api-N` names, and it allows
      anycast nameserver addresses served by any number of nodes. Node
      convergence (serial per node) is reported in the heartbeat.
- [ ] Split queue lanes: `dns`, `edge`, `verification`, `tls`, and `purge`,
      each with its own worker bound.
- [ ] Make snapshots deterministic: sort every list. Move the global address
      blocklist out of per-domain snapshots into one PoP-level artifact.
- [ ] Make the agent incremental: store one file per domain, write only what
      changed, and keep the generation swap atomic.
- [ ] Sign `kind`, `domain_id`, and `revision` together with the checksum,
      sequence, and expiry. Require a strictly greater revision, and support
      signing-key rotation with an overlap window.
- [ ] Give agent requests per-request timeouts, contexts, and exponential
      backoff with jitter.
- [ ] Reload cells by generation: Lua checks a small generation file and
      decodes the state only when it changes.
- [ ] Add a per-source-address connection limit, idle timeouts in both
      directions, and half-close handling to the gateway.
- [ ] Key TLS handshake and connection limits per domain and client prefix,
      with a separate, higher ceiling for each domain.
- [ ] Size cell temporary files and body limits to the cell's temporary
      storage, and set `worker_processes` from the CPU limit.
- [ ] Generate the cell services from the PoP's slot count, or fix the slot
      count to what Compose runs. Allow small PoPs to run fewer cells.
- [ ] Bound DNS rollup cardinality: store the domain and a bounded top-N of
      query names in the long-lived rollups.
- [ ] Answer over-limit UDP queries with TC instead of dropping them, and add
      DNSdist limits for TCP connections, TCP queries, and ANY.
- [ ] Resolve bulk DNS origins outside the domain lock and transaction, or
      lower the bulk limit for proxied records.

**Exit:**

- A load test with 10,000 domains on one PoP shows bounded CPU when idle,
  and one domain change touches only that domain's files.
- A 20-node DNS simulation converges within the documented bound.
- One client cannot exhaust gateway slots or a domain's handshake budget.
- A tampered manifest is rejected.
- A random-subdomain flood adds bounded rollup rows.

### Phase 5: Production readiness and security hardening

Fixes H8, M12, L6, A26, A27, A28, A29, A30, A31, A33, A35, A36, A42.

- [ ] Publish IPv6 in production for DNS, control, and edges, including
      edge-control. Block saving a nameserver IPv6 address until that address
      answers from an external probe.
- [ ] Add token expiry, token abilities (read-only, DNS-only, purge-only),
      and optional per-domain token scope. Add TOTP for administrators.
- [ ] Harden accounts: a per-account login limiter, constant-time login
      checks, `current_password` required to change the email, one password
      policy for all users, token revocation on password reset, expiring
      bootstrap tokens, and trust for the ingress proxy only.
- [ ] Bind PHP-FPM only to the nginx containers' private network, and add a
      shared secret between nginx and PHP for the edge identity parameters.
- [ ] Add a shared hardening anchor to every service (`no-new-privileges`,
      `cap_drop`, `read_only` where possible) and memory limits on every
      service. Replace the log collector's Docker socket mount.
- [ ] Block 6to4, Teredo, and IPv4-compatible ranges in PHP and Lua, allow
      only an explicit origin port set, and restrict origin tests to edges in
      the domain's placement.
- [ ] Add an explicit, tracked ClickHouse migration service.
- [ ] Reload PowerDNS after a GeoIP update, ship a bundled fallback database,
      and require a checksum or signature in production.
- [ ] Back up with retention (`forget --prune`), a weekly `restic check`, a
      backup-age alert, and a scheduled CI job for the off-host restore.
- [ ] Give the cache its own eviction-enabled Valkey, or TTLs on every cache
      key. Read the Valkey password from a secret file.
- [ ] Pin development images to the production digests, and check in CI
      that they are equal.
- [ ] Builder-generated provenance; Dependabot for composer, npm, gomod, and
      Compose; govulncheck and Larastan in CI.
- [ ] fsync agent and gateway state writes, enable `gzip_vary`, and add OCSP
      stapling and session-ticket key rotation, or document why not.

**Exit:**

- E2E passes over IPv6 only.
- A scoped token cannot exceed its scope.
- The Compose policy check proves every service has a health check, hardening
  options, and a memory limit.
- The off-host restore job passes.
- The SSRF fixture blocks every transition range.

### Phase 6: Simple setup and agent-run browser qualification

Fixes M15, L1.

- [ ] One installer for control and one join command per PoP. PKI, TSIG
      keys, and bootstrap tokens are generated automatically, and a new PoP
      joins the default `shared` pool with default cells.
- [ ] A first-run wizard in the admin panel. All settings have visible
      defaults and a reset action.
- [ ] Remove the edge management address fields.
- [ ] Make browser qualification agent-run only. Delete
      `docs/manual-browser-qualification.md` and every reference to it
      (README, CONTRIBUTING, the pull request template, the docs, the skills,
      and `tests/docs`). Amend `AGENTS.md` and `RepositoryContractTest` so
      agents run the browser suite against non-production stacks. In the
      same change, reword the `AGENTS.md` sentence "The roadmap is closed"
      to refer only to the archived staged roadmap. Until this item ships,
      browser qualification stays manual and owner-run, as `AGENTS.md`
      requires.
- [ ] Add a Playwright suite under `tests/browser` that signs in to both
      panels and covers every screen and workflow, at desktop and phone
      width. Agents run it and report its results, and it joins the phase
      gate from here on.
- [ ] Rewrite the quick start around the new installer. A scripted run on
      fresh virtual machines must reach an active proxied domain with no
      undocumented step.

**Exit:** the scripted quick start passes on fresh VMs, and the browser suite
passes.

### Phase 7: Cleanup and documentation

Fixes L2, L3, L4, A44, A46.

- [ ] Delete `docs/legacy/`, the second agent activation path, every
      compatibility branch, and squash migrations into a fresh baseline.
- [ ] Rename phase-numbered tests after the feature they test.
- [ ] Wire or delete every documented environment variable. Extend the
      documentation check so that a documented key that is not wired fails.
- [ ] Delete thin helpers such as `MetricComparisonService`, and fix the
      `.PHONY` list.
- [ ] Rewrite the documentation: one page per concept, the command or screen
      first, and the explanation after it. Run every shell block marked
      `test` in CI.
- [ ] Update the project skills in `.agents/skills/` to match every behavior
      changed by Phases 1–6.

**Exit:** the docs build, link checks, and executable-block tests pass, and no
legacy file remains.

### Phase 8: Real production qualification

- [ ] On real servers (one control host and at least three PoPs in two
      regions, IPv4 and IPv6), an agent follows the documentation word for
      word. Every deviation is a documentation defect to fix and retest.
- [ ] Test every feature through the API, the CLI, and the browser suite,
      against real domains.
- [ ] Re-run every critical and high finding's regression scenario on the
      real fleet: control outage, edge certificate expiry, bad artifact, PoP
      loss, DNS host loss, purge after restart, WAF exclusion bypass, Geo-DNS
      from real resolvers in two countries, and IPv6-only resolution.
- [ ] Publish the 2.0.0 release notes with the evidence.

**Exit:** every test passes on real servers and is recorded in the release
notes.

## Daily checks this roadmap must make visible

Each check maps to a failure mode found in the audits. The admin dashboard
and a status command show every check by Phase 5.

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

## Out of scope unless the owner admits it

BGP control by CDNFoundry, Kubernetes, microservices, billing or reseller
hierarchies, plugin runtimes, and multi-origin load balancing.
