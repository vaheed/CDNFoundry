# Manual browser qualification

This is the owner-run browser acceptance job for every roadmap phase. Coding agents do not launch or automate browsers. Run each applicable checkpoint manually and record the result. A missing menu, form, action, operation, or runtime result is **not ready/failed**—never mark it passed from API or automated-test coverage alone.

Use disposable data and replace documentation addresses such as `192.0.2.0/24`, `2001:db8::/32`, and `example.test` before real-traffic qualification. Never reuse these development passwords in production.

## Common preparation

From the repository root:

```sh
make dev-up
make dev-migrate
make dev-pdns-migrate
docker compose -f compose.dev.yml ps
```

`make dev-up` exports the compiled shared Filament theme before starting the containers. On an existing stack after a UI-only change, run `make dev-assets` and refresh the page. The admin and domain panels must never depend on an uncompiled development asset server.

Wait for `core`, `web`, `control-db`, `pdns-db`, `redis`, and `clickhouse` to become healthy. Development volumes persist. Never run `down -v`, remove volumes, use `migrate:fresh`, or run Laravel tests against the development database.

Create/reset the local accounts if needed:

```sh
docker compose -f compose.dev.yml exec -T core php artisan tinker --execute="App\\Models\\User::query()->updateOrCreate(['email'=>'admin@example.test'], ['name'=>'Local Administrator','password'=>Illuminate\\Support\\Facades\\Hash::make('cdnfoundry-admin-test'),'type'=>'admin','disabled_at'=>null]); App\\Models\\User::query()->updateOrCreate(['email'=>'user@example.test'], ['name'=>'Local Domain User','password'=>Illuminate\\Support\\Facades\\Hash::make('cdnfoundry-user-test'),'type'=>'user','disabled_at'=>null]);"
```

| Surface | Address | Login |
|---|---|---|
| Administrator | `http://localhost:8080/admin` | `admin@example.test` / `cdnfoundry-admin-test` |
| Domain user | `http://localhost:8080/app` | `user@example.test` / `cdnfoundry-user-test` |
| Horizon | `http://localhost:8080/horizon` | Existing administrator session |
| PowerAdmin (diagnostic only) | `http://localhost:9191` | `admin` / `poweradmin-dev-only` |
| Prometheus | `http://localhost:9090` | Development only, no login |
| Alertmanager | `http://localhost:9093` | Development only, no login |
| Edge A/B health | `http://localhost:8081/healthz`, `http://localhost:8082/healthz` | None |
| Edge control health | `https://localhost:9443/healthz` | Development private CA; use `curl -k` for this local health-only check |
| DNSdist | UDP/TCP `127.0.0.1:1053` | Use `dig` |

For every phase, check desktop and narrow mobile widths, browser-console errors, authorization, validation messages, audit events, operation visibility, retry/failure behavior, and persistence after refresh/sign-out/sign-in.

## Phase 1 — Foundation, access, and control-plane shell

### Administrator checkpoints

1. Sign in at `/admin`. Confirm the blue active-navigation treatment, collapsible desktop sidebar, readable responsive stat cards, **Control plane**, **Customers**, **Edge network**, and **Operations** groups, `Local Administrator`, and no missing-theme or console asset errors. The dashboard must show Domains, Users, DNS clusters, Serving edges, Work in progress, Failed operations, Queue lanes, Recent audit activity, and Common tasks without raw unstyled lists.
2. Open **Users** and create:

   | Field | Value |
   |---|---|
   | Name | `Browser Tester` |
   | Email | `browser.user@example.test` |
   | Type | `Domain user` |
   | Password | `browser-user-test1` |

3. Confirm a password shorter than 12 characters is rejected. Disable the account and verify `/app` login fails; re-enable it and verify login succeeds.
4. Open **API tokens**, enter name `manual-browser`, create it, and copy it from the one-time display. Refresh and confirm only metadata/final characters remain. Revoke it and verify it no longer authenticates.
5. Open **Profile**, change the display name to `Local Administrator Updated`, save, refresh, and confirm the change. If changing the password, use at least 12 characters and confirm other tokens are revoked.
6. Open **Audit logs** and confirm the preceding actions show actor, action, subject, IP, and time and cannot be edited.
7. Open **Operations**. Confirm newest-first ordering, 10-second polling, status/type/requester filters, ID/type/email/error search, optional timestamps, duration, attempts, bounded errors, and guarded retry for supported failures.
8. Open Horizon. Confirm workers exist; trigger an asynchronous action and confirm it leaves `pending`, then succeeds or exposes a useful failure.

### Domain-user authorization checkpoints

1. Sign in at `/app` as `user@example.test` / `cdnfoundry-user-test`.
2. Confirm administrator navigation is absent. Confirm the dashboard shows only assigned-domain totals and recent domains, plus the three-step **Start serving a domain** guide; an unassigned domain name must not appear.
3. Repeat the personal token and profile checks; changes must affect only this user.
4. Directly request `/admin/users`, `/admin/dns-clusters`, `/admin/audit-logs`, and `/horizon`; all must be forbidden.

### Phase 1 completion gate

- Implementation: present.
- Documentation: present above.
- Automated/runtime qualification: agent-owned tests must pass and be recorded for the release.
- Manual browser qualification: owner-run; **failed/not complete until every Phase 1 checkpoint above is recorded as passed**.

## Phase 2 — Domains and authoritative DNS

### System DNS identity

As administrator, open **System DNS identity** and fill:

| Field | Value |
|---|---|
| Platform domain | `cdnf.test` |
| Proxy hostname | `proxy.cdnf.test` |
| Nameserver 1 hostname / IPv4 / IPv6 | `ns1.cdnf.test` / `192.0.2.10` / `2001:db8::10` |
| Nameserver 2 hostname / IPv4 / IPv6 | `ns2.cdnf.test` / `192.0.2.11` / `2001:db8::11` |
| SOA primary | `ns1.cdnf.test` |
| SOA mailbox | `hostmaster.cdnf.test` |
| Refresh / retry / expire | `3600` / `600` / `1209600` |
| SOA minimum TTL / default TTL | `300` / `300` |
| Cluster targets | `pdns-auth:8081` |

Enter the platform domain first and blur the field. Confirm proxy, nameserver, and SOA names auto-fill but remain editable. Choose **Validate and preview** and review the normalized payload without any desired-state or operation change. Then choose the red **Confirm and queue update** action within 15 minutes, copy the operation ID, and confirm `platform_dns_identity.update` succeeds. Changing any field after preview must require a new preview. Reject loopback/malformed glue, fewer than two nameservers, and an empty target list.

### DNS cluster

Open **DNS clusters → New DNS cluster**:

| Field | Value |
|---|---|
| Name / location | `local-pdns` / `local-compose` |
| Enabled | Off initially |
| API URL / key | `http://pdns-auth:8081` / `pdns-dev-api-key` |
| Server ID | `localhost` |
| Nameservers | `ns1.cdnf.test`, `ns2.cdnf.test` |
| Capacity zones | `100000` |
| Notes | `Local Compose qualification cluster` |

Save, confirm `dns.cluster_test` appears in Operations, wait for healthy, then enable it. A wrong URL/key must become unhealthy; an unhealthy/untested cluster cannot be enabled; the saved key must never reappear.

### Domain lifecycle and assignments

1. Open **Domains → New domain**. Use a real delegated disposable domain for release acceptance; use `browser-test.example.test` only for local UI checks.
2. Attach `user@example.test` in the domain **Users** relation.
3. Choose **Verify nameservers** and confirm a queued operation and visible result. For local UI-only qualification use **Force verify (local test)** and confirm its warning/audit record; it does not qualify public delegation.
4. With a real domain, set the registrar to exactly the platform nameservers and retry until public verification succeeds.
5. Choose **Activate**. Confirm lifecycle, desired revision, per-cluster deployment, SOA, and NS state. Activation without a healthy enabled cluster must fail safely.
6. Sign in as the domain user. Confirm only the assigned domain is listed and an unassigned domain ID returns not found.

### DNS record forms

Create each row and fill every shown field:

| Type | Name | Content and additional fields |
|---|---|---|
| A | `@` | `192.0.2.20`, TTL `300` |
| AAAA | `@` | `2001:db8::20`, TTL `300` |
| CNAME | `www` | `@`, TTL `300` |
| MX | `@` | `mail.example.net`, priority `10`, TTL `300` |
| TXT | `@` | `v=spf1 -all`, TTL `300` |
| NS (administrator) | `delegated` | `ns1.example.net`, TTL `300` |
| CAA | `@` | `0 issue letsencrypt.org`, TTL `300` |
| SRV | `_sip._tcp` | target `sip.example.net`, priority `10`, weight `5`, port `5060`, TTL `300` |
| PTR (reverse zone only) | `20` | `host.example.net`, TTL `300` |

Edit TTL to `600`, delete a disposable record, and bulk-delete several disposable records. Duplicates, invalid values, out-of-zone names, and CNAME coexistence must fail without partial mutation. Import a small BIND zone in append mode, preview replace mode before committing, export it, and verify it can be imported again.

Query DNSdist—not private PowerDNS—over UDP and TCP:

```sh
dig @127.0.0.1 -p 1053 browser-test.example.test SOA +tcp
dig @127.0.0.1 -p 1053 browser-test.example.test A
dig @127.0.0.1 -p 1053 browser-test.example.test AAAA
dig @127.0.0.1 -p 1053 browser-test.example.test TXT
```

Use PowerAdmin only to inspect derived state. Never edit desired state there.

### Phase 2 completion gate

- Implementation: present.
- Documentation: present above.
- Automated/runtime qualification: agent-owned DNS/API tests must pass and be recorded.
- Manual browser and public delegation qualification: owner-run; **failed/not complete until every Phase 2 checkpoint above passes with a real delegated domain**.

## Phase 3 — Geo-DNS

On an assigned active domain, create an A record:

| Field | Value |
|---|---|
| Type / name / mode | `A` / `geo` / `Geo-DNS` |
| Default target | `192.0.2.30` |
| Country overrides | `IR` → `192.0.2.31`; `US` → `192.0.2.32` |
| Continent overrides | `AS` → `192.0.2.33`; `EU` → `192.0.2.34` |
| TTL | `300` |

Create an AAAA equivalent using `2001:db8::30` through `2001:db8::34`. Confirm country wins over continent and unknown geography uses default. Duplicate/invalid codes, mixed address families, and excessive overrides must be rejected without revision change. Edit and refresh to confirm the structured configuration persists.

Repeat Geo-DNS with each type exposed by the current user/zone:

| Type | Default answer | IR answer | Additional fixed fields |
|---|---|---|---|
| CNAME | `default.example.net` | `ir.example.net` | None |
| MX | `mail-default.example.net` | `mail-ir.example.net` | Priority `10` |
| TXT | `region=default` | `region=ir` | None |
| SRV | `sip-default.example.net` | `sip-ir.example.net` | Owner `_sip._tcp.geo`, priority `10`, weight `5`, port `5060` |
| NS (administrator only) | `ns-default.example.net` | `ns-ir.example.net` | Use only on a disposable delegated owner |
| PTR (reverse zone only) | `default.example.net` | `ir.example.net` | Use only in a managed reverse zone |

For the CNAME row, enter the default target and one country override, leave the
continent list completely empty, and save. It must persist. A CNAME must be the
only record at its owner: if another row already uses `geo`, the form must show
the conflict beside the record name rather than silently doing nothing. Confirm
invalid RDATA is rejected according to its type. MX priority and SRV
priority/weight/port remain fixed while geography selects their target. NS
remains administrator-only and PTR remains reverse-zone-only. CAA must offer
DNS-only mode but not Geo-DNS: the qualified PowerDNS Lua runtime returns NODATA
for synthesized CAA, so exposing it would be a false feature.

Use preview with IPv4/IPv6 addresses whose classification is known in the installed MMDB and record the displayed country/continent. Query DNSdist with and without trusted ECS and confirm the answer matches that classification. Force a bad MMDB update and invalid deployment; the prior MMDB and last valid answers must continue serving while failure is visible.

### Phase 3 completion gate

- Implementation: present for every PowerDNS-Lua-compatible DNS record type with normal NS/PTR policy restrictions; CAA is explicitly DNS-only.
- Documentation: present above.
- Automated/runtime qualification: agent-owned API/compiler and real DNS tests must pass and be recorded.
- Manual browser/geographic-vantage qualification: owner-run; **failed/not complete until every Phase 3 checkpoint and required vantage-point query passes**.

## Phase 4 — Edge proxy and origin routing

### Platform settings (administrator)

1. Open **Platform settings**. Confirm all five sections render with the current PostgreSQL value, a full explanation, and the shipped default beside every field.
2. Under **DNS lifecycle**, set **Deprovision delay** to `14` and **Domain reclaim cooldown** to `7`; save, refresh, and confirm both persist. Restore `7` / `7` after the check.
3. Under **API rate limits**, confirm defaults `10`, `600`, `240`, `12`, `20`, `10`, and `600` in displayed order. Enter `0` in one field and confirm validation rejects the entire save without changing its revision; restore the valid default.
4. Under **Edge runtime**, confirm heartbeat `45`, drain `300`, and artifact limit `2097152`. Change heartbeat to `60`, save, and confirm a `system_settings.update` operation is created and succeeds; restore `45` and confirm a second reconciliation operation.
5. Under **Origin destination safety**, add blocked CIDR `203.0.113.0/24` and address `198.51.100.20`. Confirm malformed CIDRs/IPs and duplicate entries are rejected. Save, deploy a disposable proxied hostname targeting the blocked range, and confirm validation/runtime both reject it. Remove the documentation-only entries afterward.
6. Under **Proxy defaults**, confirm enabled On, HTTPS redirect Off, HTTP/1.1 and HTTP/2 selected, and retry count `0`. Change retry count to `1`, save, and confirm bounded edge reconciliation; restore `0`.
7. Open **Audit logs** and confirm each effective change records `system_settings.updated`, its group, revision, actor, and operation ID when applicable. Sign in as a domain user and directly request `/admin/platform-settings`; it must be forbidden.

### Edges and pools (administrator)

1. Open **Edge network → Edges** and create two entries. Replace documentation addresses with reachable addresses for real traffic:

   | Field | Edge A | Edge B |
   |---|---|---|
   | Name | `edge-browser-a` | `edge-browser-b` |
   | Country / continent | `IR` / `AS` | `DE` / `EU` |
   | IPv4 | `192.0.2.101` | `192.0.2.102` |
   | IPv6 | `2001:db8::101` | `2001:db8::102` |

2. Save each one-time bootstrap token. For the bundled two-agent development
   topology, copy `.env.dev.example` to the ignored `.env.dev`, set the exact
   `CDNF_DEV_EDGE_A_ID/CDNF_DEV_EDGE_A_BOOTSTRAP_TOKEN` and the matching B
   values, run
   `chmod 600 .env.dev`, `make dev-edge-up`, and `make dev-edge-status`.
   Confirm fresh heartbeat, version, active revision, listener readiness, and
   bounded cell capacities, then immediately blank both token values in
   `.env.dev`. Restarts must use the persistent mTLS identities without tokens.
3. Exercise drain/undrain and enable/disable. Rotate an identity, copy the replacement token once, and confirm the previous credential no longer works.
4. Open **Service pools**. Confirm the page explains that one pool is one bounded delivery class with an equivalent cell at each participating edge. Confirm shared and quarantine pools, their revisions, and copyable `pool-<id>.<proxy-hostname>` DNS routing targets. `shared` is the normal default, `quarantine` isolates risky/noisy domains, and `dedicated` is an explicit exception. Create one dedicated pool, confirm `edge.pool_provision` completes, and confirm one desired cell per registered edge. New pools remain disabled until provisioning and service-address configuration are complete.
5. In each edge's **Cells** relation, verify that a cell before agent enrollment
   clearly says **Awaiting edge enrollment**, missing/stale heartbeat has its own
   explanation, and a connected cell shows runtime revision/version, workload,
   connections, memory, CPU, cache, and temporary-storage values. Set a unique
   public service IPv4 and IPv6 for every quarantine/dedicated cell. Editing must
   reject private, loopback, duplicate, or missing dual-stack addresses beside
   the affected field. A valid edit is durable even while its edge is offline;
   the success notice must say it is waiting for the agent. Enable a new pool
   only after every intended participant is fully addressed. A later unaddressed
   edge cell must remain excluded from that pool and must not block its existing
   participants.
6. Drain and undrain one cell and confirm its task completes through the agent. Restart it and confirm `last_restart_at` changes, traffic resumes after the bounded window, and sibling cells/agent stay running.

### Proxy defaults and record eligibility

Open the domain proxy settings. Start with these compatibility-oriented defaults:

| Field | Value |
|---|---|
| Proxy enabled | On |
| Redirect HTTP to HTTPS | Off initially |
| HTTP versions | HTTP/1.1 and HTTP/2 |
| Default origin retry count | `1` |
| Maintenance mode | Off |

In **DNS records**, select every type. The **Proxied** mode must appear only for A, AAAA, and CNAME. **Geo-DNS** is available for A, AAAA, CNAME, MX, TXT, SRV, administrator-managed NS, and reverse-zone PTR; CAA remains DNS-only. Changing a proxied row to an ineligible type must reset mode to DNS-only and hide origin fields.

### Proxied apex form

Create or edit the apex. If an apex A/AAAA already exists in DNS-only or Geo-DNS
mode, edit that record to Proxied; do not create a competing address record.

| Field | Value / expected default |
|---|---|
| Type / name / mode | `A` / `@` / `Proxied` |
| Origin server hostname or IP | A public cPanel/shared-hosting origin, e.g. `server1.example.net` |
| Scheme / port | `HTTPS` / locked `443` |
| Origin Host header | Auto-fills to the domain name |
| TLS SNI | Auto-fills to the domain name |
| Verify origin TLS | On |
| Connect / response timeout | `2000` ms / `30000` ms |
| Retry count | `1` |
| WebSocket | Off |
| Health check | Off; when on, path `/`, interval `300` seconds |
| TTL | `300` |

The origin destination is the server reached by CDNFoundry; Host/SNI default to the public record hostname so name-based cPanel virtual hosting and certificates work. The browser UI intentionally exposes the standard scheme/port pairs only: HTTP locks port `80` and hides TLS verification/SNI, while HTTPS locks port `443` and exposes both. Advanced API clients may submit a validated custom port. The destination must not point back to CDNFoundry, a platform hostname, loopback, link-local, private metadata, multicast, or an edge address.

Before saving, create DNS-only apex MX, TXT, and CAA records. The proxied apex
must save with those records still present. Attempt a second apex A or AAAA and
confirm the field error tells you to edit/remove the existing address/alias.
After deployment, PowerAdmin must show apex Lua A and AAAA RRsets plus the
unchanged MX/TXT/CAA records.

### Proxied subdomain and editable automatic values

1. Create `www` as A, AAAA, or CNAME in Proxied mode with destination `server2.example.net`.
2. Confirm Host and SNI automatically become `www.<domain>`.
3. Change name to `shop`; untouched automatic fields must become `shop.<domain>`.
4. Manually change Host to `backend-vhost.example.net` and SNI to `backend-cert.example.net`; change the record name again and confirm both overrides are preserved.
5. Switch scheme HTTPS → HTTP and confirm port `443` changes to a disabled `80`
   field and TLS/SNI fields disappear; switch back and confirm locked `443` and
   TLS/SNI return. Custom ports are an API-only advanced option.
6. With HTTPS verification on, blank SNI must be rejected. Origin Host must always be present for a proxied record.
7. Turn the health check off and save without entering hidden path/interval
   values; it must succeed. Turn it on and confirm path/interval become required.
8. Enter a blocked/private origin and confirm a visible error beside the origin
   field. Correct it, save, and confirm a success notice. If no edge is ready,
   desired state must still persist and the notice must explicitly say delivery
   is waiting for an enrolled, healthy edge.
9. Confirm the DNS record table's **Desired DNS route** says `CNAME → pool-<id>.<proxy-hostname>` and the domain **Edge delivery** section shows the same copyable service-pool DNS target. In diagnostic PowerAdmin, confirm the exact subdomain CNAME points to that pool target; it is intentionally not the generic proxy hostname.
10. Run **Test origin** and confirm status/latency or a bounded validation/connection error. Saving/testing remains asynchronous.

### Deployment and operation visibility

1. Save both proxied records and choose **Deploy proxy configuration**.
2. Open **Administration → Operations**. Confirm new `edge.domain_deploy` and `edge.origin_test` rows appear without refreshing manually, with requester, attempt, status, duration, and bounded error.
3. Retry a supported failure. It must not duplicate an already-active deployment.
4. Confirm the domain view header shows four compact action menus—**Domain actions**, **Delivery**, **Cache**, and **TLS**—without horizontal overflow at desktop or mobile widths. Confirm the page renders **Domain status**, **Edge delivery**, **Authoritative DNS deployment**, **Cache**, and **TLS** as one ordered stack of cards, with fields reducing to one column on a narrow viewport. Proxy defaults must appear as one readable summary (for example, `Enabled · HTTP/1.1 + HTTP/2 · HTTPS redirect off · 0 origin retries · Maintenance off`) rather than raw JSON or separate boolean/list fragments. Confirm proxied-host count, desired/active revision, placement/pools, failure, and recent validated revisions.
5. Send HTTP and HTTPS through both real edges. Confirm correct origin selection, Host, SNI, IPv4/IPv6 behavior, unknown-host/SNI rejection, and continued serving of the last valid revision after a deliberately invalid candidate.
6. Move the domain shared → quarantine → dedicated. For each move record the target-ready acknowledgement, target DNS answer, non-null drain deadline, source-removal artifact, final acknowledgement, and active pool. A failed/rejected target must leave source DNS and traffic active.

Saving **Proxy defaults** alone does not turn a DNS-only record into a proxied
hostname. Confirm its notice says that no hostname will be deployed until an A,
AAAA, or CNAME row is saved in **Proxied** mode. With a proxied row but no ready
edge, confirm the desired revision and operation remain visible with a clear
waiting/not-ready message; the control plane must not discard the user's intent.

### Phase 4 completion gate

- Implementation: present for service-pool DNS routing, target-first migration, acknowledged source removal, and authenticated cell control.
- Documentation: present above and in the edge/origin runbooks.
- Automated/runtime qualification: agent-owned tests must pass and be recorded in `docs/phase-4-qualification.md`.
- Manual browser/dual-edge qualification: owner-run; **failed/not complete until all Phase 4 checkpoints pass on two reachable edge hosts**.

## Phase 5 — TLS, cache, and purge

Use an active, nameserver-verified disposable domain assigned to the domain user, with the shared pool acknowledged on at least one reachable edge. Use a CA-approved real delegated name for public acceptance. The local Pebble CA qualifies the bundled development workflow only.

### Managed TLS

1. Start with DNS-only records and open the domain **TLS** section. Confirm Mode is `managed`, Certificate status says **Pending managed issuance**, Latest managed order says **Not queued**, and no certificate action has created an order.
2. Create the first eligible A, AAAA, or CNAME record in **Proxied** mode. Confirm DNS remains available, a `tls.managed_certificate` operation appears, and the TLS section progresses through a queued/publishing/validating/finalizing state to an active managed certificate after refresh.
3. Record the active certificate's Covered names, Expires value, SHA-256 fingerprint, latest order state, requested names, and operation ID. Confirm neither the page nor browser network responses contain a private key, CSR, ACME token, or account key.
4. Query `_acme-challenge.<domain>` through DNSdist while publishing and confirm the temporary TXT exists without a user-created DNS-record row. After success, confirm it disappears through a later acknowledged DNS revision. Confirm no fake apex A/AAAA row appeared.
5. Create a proxied `deep.one.<domain>` hostname. Confirm a supplemental managed order is shown and the resulting certificate covers that deeper name. Saving the same proxied set or choosing **Renew managed certificate** while coverage is sufficiently valid must reuse it rather than create a duplicate certificate order.
6. Choose **Reissue managed certificate**, accept the confirmation, record the operation ID, and confirm a replacement activates only after validation. During a deliberately failed CA/delivery attempt, refresh and confirm the previous fingerprint remains active, DNS continues answering, and the bounded error is visible on the latest order/operation.
7. As administrator, open the notification bell after creating one expiring-certificate or failed-order fixture through the supported maintenance workflow. Confirm one deduplicated alert names the domain and failure/expiry. A domain user must not see administrator notifications or another domain's TLS state.
8. Send HTTPS to each assigned edge using the proxied SNI. Confirm the dynamically selected certificate and hostname coverage without an OpenResty reload. Unknown SNI and a disabled/unavailable certificate must fail before origin traffic.

### Custom TLS and modes

1. Choose **Upload custom certificate** and fill every field:

   | Field | Value |
   |---|---|
   | Leaf certificate PEM | A currently valid leaf covering every proxied hostname |
   | Issuing chain PEM | Ordered issuer chain through its self-signed root |
   | Private key PEM | The matching RSA 2048–4096 or EC P-256/P-384 key |

2. Confirm a mismatched key, expired/not-yet-valid leaf, missing SAN, unsupported/small key, malformed PEM, oversized input, incomplete chain, and wrong chain order each fail without changing mode, revision, or active certificate.
3. Upload the valid bundle. Confirm Mode becomes `custom`, Covered names/expiry/fingerprint render, the key never renders again, edge reconciliation succeeds, and HTTPS selects the custom fingerprint by SNI.
4. Choose **TLS mode** and verify Managed, Custom, and Disabled are the only choices. Custom without a valid uploaded certificate must be rejected. Disabled must not delete the prepared managed certificate.
5. Choose **Remove custom certificate** and confirm. A valid managed fallback must become active; if none is available, managed mode must visibly remain pending while the previous last-valid edge revision is preserved until replacement activation.

### Cache settings and runtime

1. Confirm the **Cache** section shows the complete policy summary, Full-purge epoch, and Development mode until.
2. Choose **Cache settings** and verify these exact fields: Cache enabled; Edge TTL (seconds); Browser TTL (seconds); Maximum object size with only 1 MiB, 10 MiB, and 100 MiB; Respect origin cache headers; Include query string in cache key; Bypass cookie names; Stale-if-error (seconds).
3. Enter an edge/browser TTL below `0` or above `31536000`, more than 32 cookie names, or stale duration above `86400`. Each invalid value must remain in the modal with a field error and no revision change. Save each object-size tier and confirm a new desired revision and edge operation.
4. With development mode off, send the same eligible GET twice and record `MISS` then `HIT`. Confirm the response and structured edge log use the same cache state and the emitted browser `max-age` matches the configured Browser TTL.
5. Verify Authorization, Range, POST, configured bypass cookie, `Set-Cookie`, `private`, `no-store`, `Vary: *`, an unallowed Vary name, redirects, and negative responses return `BYPASS`. Verify normalized `Vary: Accept-Encoding` can return `MISS` then `HIT` without creating unbounded variants.
6. With query strings included, request `?a=1&b=2` and `?b=2&a=1`; each exact byte ordering must have its own `MISS` then `HIT`. Disable query participation and confirm only the intended shared key is used after the new revision activates.
7. Enable Respect origin cache headers and use a response with a short origin `max-age`; record `MISS`, `HIT`, then `EXPIRED`. Disable it and confirm the configured Edge TTL overrides that header. Request an object larger than the selected tier twice and confirm both are `BYPASS` while the cell stays healthy.
8. Configure an Edge TTL of `1` and a nonzero Stale-if-error window. Warm the object, stop its disposable origin, and record `STALE` inside the window. After the exact grace expires it must return a controlled origin error. Set grace to `0` and confirm stale is never served.
9. Choose **Enable development mode**, enter Duration (minutes) `30`, and save. Confirm an absolute future expiry and the **Disable development mode** action. Two real requests must be `BYPASS`; disable it and record `MISS` then `HIT`. Also allow a short mode to expire naturally and confirm bypass stops without a manual cleanup action.
10. Deliberately reject an invalid candidate revision and use the normal rollback action/path. Confirm the last validated cache policy and serving traffic remain active.

### Purge

1. Choose **Purge cache → Everything**. Record the purge ID, confirm Full-purge epoch increments, every healthy edge reports success, and the next identical request is `MISS` without a cache-directory scan.
2. Warm two URLs, including exact queries in different orders. Choose **Purge cache → Exact URLs**, enter one absolute URL per line, and save. The selected key must return `MISS` then `HIT`; the unpurged key must remain `HIT`.
3. Confirm a URL for another domain, credentials, fragment, non-HTTP scheme, non-default port, more than 100 URLs, or payload over 128 KiB is rejected without partial state.
4. Replay one purge with the same `Idempotency-Key` and input; confirm the original result. Reuse that key with different input and confirm conflict.
5. Use the purge status API to record every edge result. Make one disposable cell unreachable; the same durable task must retry up to five total attempts and become visibly failed without creating another purge or blocking traffic.

### Phase 5 completion gate

- Implementation: present for managed/custom TLS, bounded cache semantics, and asynchronous full/exact purge.
- Documentation: present here and in the managed TLS, ACME failure, custom certificate, cache, development-mode, and purge guides.
- Automated/runtime qualification: agent-owned results are recorded in `docs/phase-5-qualification.md`.
- Manual browser/public HTTPS qualification: owner-run; **not executed and Phase 5 is not release-qualified until every checkpoint above is recorded as passed**.

## Record the result

For each phase record: date/operator, commit SHA, browser/version, desktop/mobile viewports, exact domain and edge addresses, every checkpoint as pass/fail/not-ready, operation IDs, revisions, screenshots, relevant logs, and any deviations from the example values. Also record Horizon, PowerAdmin, DNSdist UDP/TCP, Prometheus, Alertmanager, and edge results where applicable.

Any broken flow, missing operation, unexpected access, asset error, unexplained pending state, last-valid-state regression, or runtime mismatch fails that checkpoint. Automated API/runtime tests support this job but never replace rendered UI and real-traffic qualification.
