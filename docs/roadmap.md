---
title: Roadmap
description: The 2.0 rebuild roadmap. Every item comes from an audit of the 1.2.1 code and is ordered by the risk it removes.
---

# Roadmap to 2.0

**Status: active.** CDNFoundry is not in production yet, so 2.0 makes breaking
changes with no backward compatibility and no data migration from 1.2.x. Work
happens on `dev`. A phase merges to `main` only after its gate passes.

This roadmap comes from an audit of the 1.2.1 code. Every finding below names
the code that causes it. Findings marked **reproduced** were confirmed by
running the code (a throwaway SQLite feature test). The others were confirmed
by reading the code path end to end.

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

Severity means: **critical** leaks secrets or can take down every domain;
**high** breaks a feature or blocks deployment for many domains; **medium**
is wrong behavior with limited blast radius, or a scaling limit; **low** is
cleanup.

### Critical

| ID | Finding | Where | Impact |
| --- | --- | --- | --- |
| C1 | **TLS private keys leak to domain users (reproduced).** The edge snapshot holds the decrypted private key. `EdgeRevision` is serialized without hiding anything, and `GET /domains/{domain}/revisions` returns it to any user assigned to the domain. | `ReconcileEdgeDomain.php:132`, `ProxyController::revisions`, `EdgeRevision` | Any domain user can download the platform-issued private key for that domain. |
| C2 | **Private keys are stored in plaintext in PostgreSQL.** `tls_certificates` encrypts the key, but every `edge_revisions.snapshot` and every per-edge `edge_artifacts.payload` stores it as plain JSON, for every revision that is kept, and backups copy them. | same, plus `EdgeArtifact` casts | Breaks the "encrypt private keys" rule. A database dump or backup exposes every certificate key. |
| C3 | **Healthy PoPs drop out of DNS when control stops hearing from them.** A pool endpoint is published only while the PoP's heartbeat is fresh. The platform zone is re-rendered every minute, and a pool with no fresh endpoint publishes **no** address. If heartbeats stop reaching control (edge-control nginx down, CA or certificate expiry, rate limiting, a network partition), every proxied domain stops resolving within about a minute, although the edges still serve. | `EdgePoolEndpoint::readinessReason()`, `PlatformDnsZone::render()`, scheduler `ReconcilePlatformDnsIdentity` every minute | A control-plane fault becomes a global data-plane outage. |
| C4 | **One bad artifact freezes a whole PoP.** The agent applies the manifest as one batch. A rejected artifact aborts the batch without moving the cursor, so the next sync fetches and rejects the same artifact again, forever. Two of the agent's rejection reasons (`invalid_artifact_schema`, `artifact_revision_replay`) are not accepted by the server's validation (422), so the ack queue also stops at the first rejection and holds every later "applied" ack. | `edge-agent/main.go` `sync()`, `flushAcks()`; `EdgeAgentController::rejected()` | One domain's bad payload stops every domain on that PoP from updating, and no placement on it is ever promoted. |

### High

| ID | Finding | Where | Impact |
| --- | --- | --- | --- |
| H1 | **New domains land in the quarantine pool (reproduced).** Placement hashes the domain name across **all** enabled pools, including `quarantine`, `reserved`, and `dedicated`. On a default install, 20 of 40 new domains were placed in `quarantine-default`. | `ReconcileEdgeDomain.php:79-83` | Half of normal traffic shares the cells meant for abusive domains. |
| H2 | **WAF can wedge a domain.** Neither default pool is WAF-capable. The API accepts `PATCH /waf` with 202, and then every edge reconcile for that domain throws "No enabled WAF-capable pool exists". All later changes to the domain fail until an administrator creates a WAF pool. | `WafController::update`, `ReconcileEdgeDomain.php:64-71` | Domain users can block their own deployments with one click. |
| H3 | **One offline PoP blocks deployment for its pool.** Promotion waits until **every** participating edge acknowledges. One PoP that is down, wedged (C4), or slow keeps every new domain and every change in that pool in `deploying`. | `PromoteReadyEdgePlacements` (`every`) | A single PoP failure stops deployment for the whole pool. |
| H4 | **A proxied record blocks the whole zone.** `PowerDnsZone::render()` throws when a proxied record has no active pool yet. On activation, edge placement is still deploying, so the zone (MX, TXT, everything) is not published until the edges acknowledge. Combined with H3 or C4, a newly delegated domain does not resolve at all. | `PowerDnsZone.php:26-30` | Outage at onboarding, and customers cannot edit any DNS record while the edges are unready. |
| H5 | **URL purge does not match the cache key.** PHP builds the purge key from the raw path and does not decode `+`. Lua builds the cache key from the decoded, normalized `ngx.var.uri` and from `get_uri_args`, which decodes `+`. Purging a URL with `%20`, `+`, `..`, or `//` silently does nothing. | `CacheKey::fromUrl`, `runtime.lua` `request_cache_key` | Breaks the "one deterministic key" rule, and customers see stale content after a "successful" purge. |
| H6 | **URL purges are forgotten.** A URL purge only increments a counter in the cell's `ngx.shared.runtime_limits` dictionary. That dictionary is lost on cell restart, and it is LRU-evicted under memory pressure because it also holds the per-second rate-limit counters. | `runtime.lua` control `cache_purge`, `edge-agent` `runCachePurge` | Purged objects come back after a restart, or during an attack. |
| H7 | **DNS changes are not retried or repaired.** `ReconcileDnsZone` has 3 tries with no backoff, and the scheduler has no DNS drift or retry job (edges and TLS have one). A DNS host that is unreachable for more than a few seconds keeps stale zones until an administrator runs a global reconcile by hand. | `ReconcileDnsZone`, `routes/console.php` | Stale or missing records after any short DNS host outage. |
| H8 | **No IPv6 in production.** `compose.prod.yml` publishes DNSdist and control on IPv4 only. `HOST_BIND_IPV6` is generated and never used. Platform settings still accept nameserver IPv6 addresses and publish AAAA glue for them. | `compose.prod.yml` ports, `PlatformDnsZone.php:36` | Resolvers that prefer IPv6 time out on our nameservers. |
| H9 | **Domain users can read DNS logs of other tenants.** DNS telemetry has `domain_id = 0`, and DNS analytics and raw logs are scoped by zone-name suffix only. The owner of `example.com` sees queries, with client IPs, for another tenant's `shop.example.com`, and a new owner sees the previous owner's logs. | `vector.yaml` dnstap remap, `AnalyticsStore.php:63,113,141` | Cross-tenant personal-data leak. |

### Medium

| ID | Finding | Where | Impact |
| --- | --- | --- | --- |
| M1 | **Rollback writes unvalidated data.** Rollback copies `origin` from the snapshot, including the platform-injected `private_allowlist`, `blocked_networks`, and `blocked_addresses`, back into `dns_records.origin` without re-running `OriginData` validation. It also deletes non-proxied records that now use the same name, and it does not roll back WAF or TLS. | `ProxyRevisionRollback` | Platform policy is copied into customer JSONB. An origin that current policy prohibits can return, and the rollback is only partial. |
| M2 | **The edge snapshot is not deterministic.** `blocked_addresses` is assembled from unordered `pluck()` queries. Any change in row order changes the checksum, which forces a revision bump and a recompile. Every domain also embeds the global address list, so one pool address change requires recompiling every domain. | `ReconcileEdgeDomain.php:120-123` | Breaks the "render deterministically" rule and causes fleet-wide fan-out for one edit. |
| M3 | **The platform zone serial does not change with its content.** The SOA serial is `settings.revision`, but the content changes whenever heartbeat freshness changes, without a revision bump. | `PlatformDnsZone::render()` | Breaks the "monotonic serials" rule, and secondaries and caches cannot detect the change. |
| M4 | **DNS fan-out does not scale.** Every zone change is pushed through the PowerDNS HTTP API to every DNS cluster, one after another (10 s timeout, 2 retries each), inside the shared 3-worker `runtime` lane. Each DNS host needs an inbound API gateway, a certificate, and a `dns-api-N` hostname, and there is no anycast model for nameservers. | `ReconcileDnsZone.php:64`, `PowerDnsClient`, `horizon.php:215` | One slow DNS host stalls edge deploys and nameserver checks, and the work grows as zones × hosts. |
| M5 | **One queue lane carries all runtime work.** DNS pushes, edge compiles, nameserver verification (up to 45 s of `dig`), platform-zone renders, and origin tests all share the `runtime` lane. Certificate issuance (500 tries) shares the `certificate_purge` lane with purges. | `config/horizon.php` | Breaks the "bulk cannot starve interactive/runtime" rule. |
| M6 | **Cells reload the full state every second.** Every OpenResty worker reads and JSON-decodes its whole runtime file (up to 64 MiB) every second, whether or not it changed. | `runtime.lua` `M.start()` timer | Constant CPU cost that grows with the domain count. |
| M7 | **The agent rewrites everything on every change.** Each change rewrites the full `state.json`, every pool runtime, and a backup, including all certificates. | `edge-agent` `activate()` | Each change costs O(all domains) on every PoP. |
| M8 | **No per-client limit at the gateway.** The edge gateway has one global connection-slot limit and no limit per source address, so one client can hold every slot. | `edge-gateway/main.go` `accept()` | Breaks the "bounded per client" protection claim. |
| M9 | **Cache key truncates query arguments.** `get_uri_args(128)` ignores arguments after the 128th, while the origin receives all of them. | `runtime.lua` `request_cache_key` | Different URLs share one cache entry (cache poisoning). |
| M10 | **The API allows slot counts the deployment cannot run.** Production Compose always runs exactly 8 cells (4 GiB per PoP), but the API accepts 1–32 slots per edge. | `compose.prod.yml`, `EdgeController` `cell_slot_count` | An edge with 16 slots has 8 that never run, and small PoPs pay for 8. |
| M11 | **Critical services have no health check.** `edge-agent`, `edge-gateway`, `edge-control`, `horizon`, and `scheduler` have none, so Compose cannot restart them when they hang. | `compose.prod.yml` | Silent stalls. |
| M12 | **API tokens are unbounded.** Sanctum tokens never expire and have no abilities or domain scope. There is no second factor for administrators. | `config/sanctum.php` `expiration => null`, `TokenController` | One leaked administrator token is permanent full control. |
| M13 | **First claim wins a domain name.** `domains.name` is globally unique, so a pending claim blocks the real owner for 7 days. A domain whose registrant changed can never be reclaimed by the new registrant while the old zone is active. | `create_domains_tables.php:23`, `Domain::createPendingFor()` | Squatting, and no legitimate takeover path. |
| M14 | **Delegation names are random hex labels behind a wildcard.** Nameservers look like `<32 hex>.ns1.example.net`, and a wildcard A/AAAA record answers any label. | `Domain::initializeDelegationClaim()`, `PlatformDnsZone.php:34-37` | Hard to read and type, and typos are never caught. |
| M15 | **Setup has too many steps.** The starter quick start is 773 lines. A first domain needs fleet JSON, bundles, tmpfs keys, DNS API certificates, edge listener material, address maps, two DNS zones, and manual cell-to-pool assignment, because new PoPs get no automatic assignment. | `docs/deployment/production-quick-start.md`, `docs/operations/simple-anycast.md` | High chance of operator error, and slow adoption. |

### Low (cleanup)

| ID | Finding | Where |
| --- | --- | --- |
| L1 | Edge management IPv4/IPv6 fields exist in the model, API, UI, and validation, but nothing operational uses them. | `EdgeController`, `EdgeResource`, `OriginData`, `EdgePoolEndpointData` |
| L2 | The agent has two activation code paths (with and without `runtimeDir`). Only one is deployed. | `edge-agent` `activate()` |
| L3 | Compatibility code for earlier installs: the legacy nameserver fallback, the `legacy-<sequence>` generation id in Lua, and expand/contract migrations. | `Domain::assignedNameservers()`, `runtime.lua` `load()`, migrations |
| L4 | 69 archived files under `docs/legacy/`, and test files named after roadmap phases (`phase2_dns.py` … `phase9_waf.py`). | `docs/legacy/`, `tests/e2e/` |
| L5 | `GET /revisions` returns raw Eloquent models instead of an API resource, so any new column is exposed automatically (this is the root cause of C1). | `ProxyController::revisions` |
| L6 | Edge identity relies on FastCGI parameters set by `edge-control.conf`. Anything else that can reach PHP-FPM on the internal network can claim to be an edge by sending the public certificate. | `AuthenticateEdge`, `docker/nginx/*.conf` |

## Phases

Phases are ordered by risk: first stop leaks, then stop outages, then fix
correctness, then scale, then simplify. Each phase leaves a working
platform.

### Phase 1: Stop secret and tenant leaks

Fixes C1, C2, H9, M1, L5.

- [ ] Remove private keys from snapshots and artifacts. Artifacts carry a
      certificate id. The agent fetches the key over mTLS from a key endpoint,
      encrypted to that edge's identity key, and the key is never written to
      PostgreSQL except in `tls_certificates`.
- [ ] Purge the existing key material from history. Since 2.0 is zero-state,
      this means only that the new schema has no column that could hold a key.
- [ ] Return revisions through an API resource with an explicit field list
      (revision, status, checksum, created_at, and a diff summary).
- [ ] Tag DNS telemetry with `domain_id` at the DNS node, from the zone the
      authoritative server answered from. Scope analytics and logs by
      `domain_id` and by the time since the current owner's activation.
- [ ] Rewrite rollback: restore only customer-owned fields, re-validate them
      with `OriginData`, `CachePolicy`, and `SecurityConfig`, and roll back
      WAF and TLS mode as well, or refuse.

**Exit:** a regression test proves a domain user receives no key material
from any endpoint, a tenant cannot see a nested or previous tenant's DNS
logs, and rollback never writes platform fields.

### Phase 2: Stop control-plane faults from becoming outages

Fixes C3, C4, H3, H4.

- [ ] **Fail static.** Withdraw a PoP from DNS only on a positive signal
      (drain, disable, or a failed external health probe), never on missing
      heartbeats alone. Never publish an empty answer set for a pool: keep
      the last published set and alert.
- [ ] Add external health probes, run from at least two vantage points, that
      check each PoP's HTTP/TLS listener directly. Withdrawal needs a quorum.
- [ ] Make the agent apply artifacts per domain. A bad artifact is rejected
      and skipped, the cursor advances, and the server records the rejection
      for that domain only.
- [ ] Make agent and server agree on one rejection-reason list, defined once
      and tested by a contract test. An unknown reason is stored, never
      returned as 422.
- [ ] Promote a placement when a quorum of participating PoPs (default: all
      that are healthy, at least one) have acknowledged. Lagging PoPs catch
      up without blocking the pool.
- [ ] Publish proxied records independently of placement: before a placement
      is active, render the default pool's hostname, so the zone always
      publishes.

**Exit:** E2E stops edge-control and verifies that every domain keeps
resolving and serving. It injects a bad artifact and verifies that the other
domains on that PoP still update. It kills one PoP and verifies that
deployments complete on the others. It activates a new domain with a proxied
apex and verifies that MX resolves immediately.

### Phase 3: Make domain features correct

Fixes H1, H2, H5, H6, H7, M3, M9, M13, M14.

- [ ] Restrict automatic placement to `shared` pools. Only explicit
      administrator actions select `quarantine`, `reserved`, or `dedicated`.
- [ ] Ship the default `shared` pool WAF-capable, or reject a WAF profile
      change with a clear 409 when no WAF-capable pool exists. Never accept
      work that cannot run.
- [ ] Define the cache key once (path normalization, query decoding, and
      ordering). Generate the PHP and Lua implementations' test vectors from
      one shared fixture file, and run it in both test suites.
- [ ] Make URL purges durable. The agent persists purge generations and
      replays them on cell start. Keep them in a dedicated shared dictionary
      that cannot be evicted by rate-limit counters, and give each entry a
      TTL equal to the cache's maximum TTL.
- [ ] Reject requests with more than 128 query arguments, or hash the full
      argument set into the key.
- [ ] Add DNS retry with backoff, plus a scheduled drift check that compares
      each DNS target's served serial and checksum with desired state and
      repairs differences.
- [ ] Derive the platform zone serial from a content checksum, so it changes
      exactly when the content changes.
- [ ] Replace first-claim-wins with re-delegation proof. Each platform
      offers a small set of readable nameserver pairs. A new claim gets a
      pair different from the domain's current delegation, verification
      requires the parent delegation to equal that pair, and success moves
      the name from the previous owner. Replace the global unique index with
      a partial index over active states, and remove the hex labels and the
      wildcard.

**Exit:** the reproduced H1 test shows 0 domains in non-shared pools. Purge
fixture tests pass in PHP and Lua. A cell restart does not bring back purged
content. A DNS host outage of 10 minutes self-heals. The claim test suite
covers squatting, takeover, and competing claims.

### Phase 4: Scale the runtime

Fixes M2, M4, M5, M6, M7, M8, M10.

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
- [ ] Reload cells by generation: Lua checks a small generation file and
      decodes the state only when it changes.
- [ ] Add a per-source-address connection limit and an idle timeout to the
      gateway.
- [ ] Generate the cell services from the PoP's slot count, or fix the slot
      count to what Compose runs. Allow small PoPs to run fewer cells.

**Exit:** a load test with 10,000 domains on one PoP shows bounded CPU when
idle, and one domain change touches only that domain's files. A 20-node DNS
simulation converges within the documented bound. One client cannot exhaust
gateway slots.

### Phase 5: Production readiness and security hardening

Fixes H8, M11, M12, L6.

- [ ] Publish IPv6 in production for DNS, control, and edges. Block saving a
      nameserver IPv6 address until that address answers from an external
      probe.
- [ ] Add health checks for every long-running service, including agent,
      gateway, Horizon, and scheduler liveness.
- [ ] Add token expiry, token abilities (read-only, DNS-only, purge-only),
      and optional per-domain token scope. Add TOTP for administrators.
- [ ] Bind PHP-FPM only to the nginx containers' private network, and add a
      shared secret between nginx and PHP for the edge identity parameters.

**Exit:** E2E passes over IPv6 only. A scoped token cannot exceed its scope.
Killing the agent process is detected and restarted.

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
      agents run the browser suite against non-production stacks.
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

Fixes L2, L3, L4.

- [ ] Delete `docs/legacy/`, the second agent activation path, every
      compatibility branch, and squash migrations into a fresh baseline.
- [ ] Rename phase-numbered tests after the feature they test.
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
      real fleet: control outage, bad artifact, PoP loss, DNS host loss,
      purge after restart, and IPv6-only resolution.
- [ ] Publish the 2.0.0 release notes with the evidence.

**Exit:** every test passes on real servers and is recorded in the release
notes.

## Daily checks this roadmap must make visible

Each check maps to a failure mode found in the audit. The admin dashboard
and a status command show every check by Phase 5.

| Check | Catches |
| --- | --- |
| Every PoP's applied sequence is equal to the latest artifact sequence, or close behind it | C4, H3 |
| Every DNS target's serial equals the desired serial | H7, M3, M4 |
| No pool's published address set has shrunk without an operator action | C3 |
| No placement has stayed in `deploying` longer than its bound | H2, H3, H4 |
| Queue latency of each lane is under its bound | M5 |
| No certificate expires within 14 days without a renewal in progress | TLS |
| The last backup and the last restore preflight succeeded | Recovery |

## Out of scope unless the owner admits it

BGP control by CDNFoundry, Kubernetes, microservices, billing or reseller
hierarchies, plugin runtimes, and multi-origin load balancing.
