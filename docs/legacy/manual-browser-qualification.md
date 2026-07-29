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

1. Open `/` first. Confirm the CDNFoundry landing page (not the Laravel starter screen) links to the domain workspace, administration, and `/api/health`, remains readable in light/dark mode, and has no missing assets. Sign in at `/admin`. Confirm the blue active-navigation treatment, collapsible desktop sidebar, readable responsive stat cards, **Control plane**, **Customers**, **Edge network**, **Operations**, **Observe**, and **Account** groups, `Local Administrator`, and no missing-theme or console asset errors. The dashboard must show Domains, Users, DNS clusters, Serving edges, Work in progress, Failed operations, Queue lanes, Recent audit activity, and Common tasks without raw unstyled lists.
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
2. Confirm administrator navigation is absent and **Domains**, **Observe**, and **Account** are the only application groups. Confirm the dashboard shows only assigned-domain totals and recent domains, plus the three-step **Start serving a domain** guide; an unassigned domain name must not appear.
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

As administrator, open **DNS clusters**, choose **Reconcile all zones**, confirm
the warning, and copy the resulting `dns.global_reconcile` operation ID. On one
active domain choose **Domain actions → Reconcile authoritative DNS** and confirm
the existing or new `dns.zone_reconcile` operation is shown without a duplicate
active operation. Repeat as its assigned domain user; an unassigned domain must
remain not found.

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

1. Save both proxied records. Confirm an edge reconciliation operation is queued automatically and that neither administrator nor domain-user **Delivery** menus contain a manual **Deploy proxy configuration** action.
2. Open **Administration → Operations**. Confirm new `edge.domain_reconcile` and `edge.origin_test` rows appear without refreshing manually, with requester, attempt, status, duration, and bounded error.
3. Retry a supported failure. It must not duplicate an already-active deployment.
4. Confirm the domain view header shows four compact action menus—**Domain actions**, **Delivery**, **Cache**, and **TLS**—without horizontal overflow at desktop or mobile widths. Confirm the page renders **Domain status**, **Edge delivery**, **Authoritative DNS deployment**, **Cache**, and **TLS** as one ordered stack of cards, with fields reducing to one column on a narrow viewport. Proxy defaults must appear as one readable summary (for example, `Enabled · HTTP/1.1 + HTTP/2 · HTTPS redirect off · 0 origin retries · Maintenance off`) rather than raw JSON or separate boolean/list fragments. Confirm proxied-host count, desired/active revision, placement/pools, failure, and recent validated revisions. The desired revision, active edge revision, retained rollback revisions, and each DNS cluster acknowledgement must show dates rather than bare revision numbers.
5. Send HTTP and HTTPS through both real edges. Confirm correct origin selection, Host, SNI, IPv4/IPv6 behavior, unknown-host/SNI rejection, and continued serving of the last valid revision after a deliberately invalid candidate.
6. Move the domain shared → quarantine → dedicated. For each move record the target-ready acknowledgement, target DNS answer, non-null drain deadline, source-removal artifact, final acknowledgement, and active pool. A failed/rejected target must leave source DNS and traffic active.
7. As administrator, open **Edges**, choose **Reconcile all domains**, confirm the
   warning, and verify one `edge.global_reconcile` operation processes domains
   in bounded chunks. This is maintenance reconciliation; the per-domain UI must
   still have no manual deploy action because normal saves deploy automatically.

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

## Phase 6 — Basic security and DDoS readiness

Use two disposable proxied domains: one assigned to the domain user and one
healthy comparison domain placed in another ready cell. Keep IPv4 and IPv6
service paths active. Sign in at `http://localhost:8080/app/login` with the
documented local domain-user account, and at `/admin/login` with
`admin@example.test` / `cdnlite-local-admin`. Replace documentation addresses
with controlled test clients/origins; never block the operator's only access
path.

### Domain security rules and profiles

1. Open the assigned domain. Record its current revision. Confirm the Security
   section shows configured/effective profile, operational state, rule count,
   recent reason codes, and the effective request/origin summary. Open
   **Security → Security profile and limits**.
2. Confirm the selector initially matches the configured profile. Select
   `standard`. The description must say it is recommended for normal traffic.
   Every limit field must immediately show the Standard column in
   `request-origin-limits.md` and remain disabled. Do not save yet; refresh the
   page and confirm the revision did not change.
3. Reopen the action and select `protected`. Without closing the modal, confirm
   every displayed value immediately changes to the Protected column, including
   `requests_per_second = 50`, `request_burst = 75`,
   `origin_retry_limit = 1`, and `origin_recovery_timeout = 60`. Every limit
   remains disabled. Select `quarantine` and confirm all values change again,
   including `requests_per_second = 10`, `origin_retry_limit = 0`, and
   `origin_recovery_timeout = 120`.
4. Select `standard`, policy `manual`, and methods `GET`, `HEAD`, `POST`; save.
   Confirm the success notification appears, the modal closes, and the Security
   section immediately shows configured profile `standard` with `100 req/s`
   without requiring a page reload. Record the one new revision and coalesced
   edge operation. Refresh and reopen the action; all Standard values must
   persist and remain disabled.
5. Select `manual`. Confirm every limit field becomes editable while policy,
   methods, and trusted proxy fields retain their current values. Set
   `requests_per_second = 37`, `request_burst = 61`, and
   `origin_recovery_timeout = 120`; leave every other field within its displayed
   range. Save and record exactly one new revision/operation. Confirm the page
   immediately shows `manual` and `37 req/s`; refresh and confirm all three
   edited values persist.
6. Reopen the manual profile, enter `requests_per_second = 101`, and save. The
   field must show its maximum validation error, the modal must retain the
   input, and no revision, operation, audit success, or effective runtime change
   may occur. Restore `37`. Repeat with `origin_retry_limit = -1` and
   `origin_recovery_timeout = 121`; each must fail without durable change.
7. With the saved manual profile open, select `protected`. Confirm all protected
   values replace the displayed manual values and are disabled, then cancel the
   modal. Reopen it and confirm the durable profile is still `manual` with
   `37`, `61`, and `120`. Select `protected` again and save; confirm exactly one
   revision, immediate `protected` display, and persistence after refresh.
8. If the domain is currently restricted or quarantined, separately confirm the
   configured profile remains the saved choice while the effective profile and
   enforced summary use the stricter operational-state ceilings. Releasing the
   disposable domain must restore the configured policy without rewriting it.
9. In **Security allow/block rules**, create these enabled rows and record IDs:

   | Priority | Type | Value | Action | Note |
   |---:|---|---|---|---|
   | 10 | IP address | controlled IPv4 client | Allow | browser IPv4 exception |
   | 20 | CIDR network | controlled IPv4 `/24` | Block | browser IPv4 range |
   | 30 | IP address | controlled IPv6 client | Allow | browser IPv6 exception |
   | 40 | CIDR network | controlled IPv6 `/64` | Block | browser IPv6 range |
   | 50 | Country | a known MMDB country code | Block | browser country |
   | 60 | Continent | a known MMDB continent code | Block | browser continent |

10. Confirm malformed IP/CIDR, IPv4 prefix above 32, IPv6 prefix above 128,
   unsupported geography, priority outside `-1000000..1000000`, and a note
   above 250 characters each remain in the form with errors and create no
   revision. Send controlled requests and confirm first-match priority and ID
   tie-break behavior, including unknown IPv6 geography continuing through
   IP/CIDR evaluation.
11. Choose **Import rules**. Add multiple preview rows, leave **Replace existing
   rules** off, confirm every normalized row before the confirmation, and save.
   All rows must appear under one new desired revision. Repeat with replacement
   after saving evidence; cancel once and confirm cancellation changes nothing.
12. Configure one **Trusted L4 proxy CIDR** only for a controlled balancer that
   overwrites `X-Forwarded-For`. A direct spoofed header must not change the
   client identity; traffic from the trusted peer must use its overwritten
   first address. Remove the test CIDR afterward.

### Real traffic, protection, and isolation

1. Against the active revision, record response status, security reason, event,
   edge cell, and resource behavior for unknown Host, unknown SNI, TRACE,
   malformed path, oversized header/body, slow header/body, keep-alive ceiling,
   request duration, IPv4/IPv6 allow/block, country/continent, client and domain
   request rate, client and domain connection concurrency, and TLS handshake
   rate. HTTP/2 streams/headers/requests must remain bounded; HTTP/3 and
   WebSocket must remain unavailable.
2. Use a deliberately slow disposable origin. Exceed origin concurrency and
   failure threshold; record `origin_capacity_exceeded` and
   `origin_circuit_open`, bounded retries, and cached/stale or controlled error
   behavior. A single incoming request must never create more than the selected
   retry limit.
3. Send random paths and query strings beyond cache-key/admission ceilings.
   Record `cache_abuse_detected`, cell cache/temp usage, and memory before/after.
   The cell must remain within its quota and the comparison domain must continue
   serving normally.
4. Stop Laravel, Horizon, scheduler, Valkey, control PostgreSQL, and telemetry
   input after the active artifact is present. Existing rules and traffic must
   continue locally. Restore services without deleting volumes. Submit an
   invalid candidate after recovery and confirm the prior rules and placement
   remain active.

### Administrator readiness and emergency controls

1. As administrator open the affected domain. Choose **Security → Restrict
   domain**. Record state, effective protected profile, operation, revision,
   events, and prove the comparison domain's limits did not change.
2. Choose **Quarantine domain**. Confirm the target quarantine cell activates
   and acknowledges before source drain/removal. Deliberately make a disposable
   target fail once; the active source placement and last-valid rules must stay
   live. Restore readiness and complete the move without restarting unrelated
   cells.
3. Choose **Release domain**. Confirm target-first movement to shared capacity,
   state `recovering`, then `normal` after a quiet scheduler interval. Record
   IPv4/IPv6 behavior throughout.
4. Open **Edge network → Edges** and apply **Emergency mode** to one disposable
   edge with actions `allow_get_head_only` and `disable_origin_retries`, expiry
   `2` minutes. Record the operation/tasks and confirm only its cells change.
   Restart one cell and confirm the agent reapplies the active control. Choose
   **Clear emergency** and verify normal traffic returns.
5. In the edge's **Cells** table apply **Emergency** to one cell with
   `return_maintenance_response` for `1` minute. Confirm another cell stays
   ready, then confirm automatic expiry sends the clear operation. Repeat the
   smallest applicable check on a service pool and verify only its cells.
6. Open **Service pools**, choose **Withdraw** on a disposable pool, and query
   DNSdist over UDP/TCP for IPv4 and IPv6. Only that pool's addresses must leave
   new answers. Choose **Restore** after every cell/address is ready and confirm
   answers return. Do not withdraw the operator's only reachable pool.
7. Inspect domain Security events, edge capacities, Audit logs, Operations, and
   active emergency controls. Confirm reason codes are stable, metrics are a
   bounded top 20 rather than one heartbeat row per attacker, expiry is visible,
   and a domain user cannot invoke any administrator action or view another
   domain.

### Phase 6 completion gate

- Implementation: present for local ordered rules, bounded profiles and limits,
  origin/cache protection, isolation, emergency controls, and pool withdrawal.
- Documentation: present in the Phase 6 guides and runbooks.
- Automated/runtime qualification: agent-owned evidence is recorded in
  `docs/phase-6-qualification.md`.
- Manual browser/real-host qualification: owner-run; **not executed and Phase 6
  is not release-qualified until every checkpoint above is recorded as passed**.

## Phase 7 — Logs, analytics, and usage export

Before opening the browser, choose one active proxied disposable domain assigned
to `user@example.test`. Generate at least: two cacheable HTTP requests producing
MISS then HIT, one controlled 5xx origin response, one blocked security request,
one IPv4 and one IPv6 request, and DNSdist UDP/TCP A/AAAA queries. Wait at least
two Vector batch intervals. Record exact UTC generation times and byte counts.

### Domain analytics

1. Sign in at `/app`, open **Analytics**, and select the assigned domain button.
   Confirm no unassigned domain button or data appears. Directly request
   `/app/analytics?domain=<unassigned-id>` and confirm it cannot reveal that
   domain.
2. Confirm the heading names the selected domain and visibly states the exact
   UTC range, `bytes`, `milliseconds`, and `no sampling`. The newest interval
   must show **Partial / provisional**, not silently appear finalized.
3. Inspect the six summary cards, **Request and bandwidth timeseries**, **Status
   codes**, **Cache ratio**, **Countries and continents**, **Hostnames**, **Top URLs**,
   **Origin health and latency**, **Edge distribution**, and **DNS activity**.
   Match request/DNS counts, bytes, status, MISS/HIT, hostname, edge, origin
   failure, and security block to the generated evidence. Unknown geography must
   be labelled `ZZ`, never guessed.
4. Inspect the **Recent logs** previews for **Requests**, **DNS**, **Errors**, and
   **Security**. Confirm each preview is limited to at most 10 rows from the
   selected domain and one-hour raw range. Verify IPv4 renders
   as its `/24`, IPv6 as its `/48`, paths contain no query string, and no
   authorization header, cookie, token, request body, or private key appears.
5. Open **Usage CSV export**. Confirm the header exactly matches
   `usage-export-contract.md`, timestamps are UTC, bandwidth is bytes, and the
   domain ID is the selected domain. Save the file and its checksum for the
   rebuild comparison.
6. At a narrow mobile width, repeat domain selection and inspect every panel and
   log/export button. Content may scroll within its bounded preview but must not
   overlap navigation or hide scope/range/unit/partial labels.

### Administrator telemetry

1. Sign in at `/admin`, open **Telemetry**, and confirm **ClickHouse available**,
   **Vector metrics available**, and the current partial/finalized label plus
   exact UTC range and units.
2. Match the global summary cards, **Global traffic**, and **Global DNS** to the
   domain evidence plus known other traffic. Confirm **Vector buffer and delivery
   metrics** shows bounded buffer/error/drop metrics, not customer secrets.
3. Inspect the **Recent logs** previews for **Errors**, **Security**, and
   **Edges** and confirm each has at most 10 masked rows from the last hour.
   Sign back in as the domain user and directly request `/admin/telemetry` and
   `/admin/telemetry/usage.csv`; both must be forbidden. Confirm no page button
   navigates to a token-protected `/api/admin/...` URL.
4. Inspect the latest 20 **Finalized usage** rows and open **Global usage CSV**.
   Choose **Rebuild usage**, optionally select the disposable domain, enter a
   complete UTC-hour range no longer than 31 days, confirm, and copy the
   `usage.rebuild` operation ID. Confirm an end before the start and a range over
   31 days are rejected. Separately rebuild the same interval through the API
   with one `Idempotency-Key`; replay it and record the same operation/result.
   Export again and confirm the selected domain row and contract version are
   unchanged.
5. Stop only ClickHouse with `docker compose -f compose.dev.yml stop clickhouse`.
   Refresh both analytics pages: each must render a clear analytics-unavailable
   message while its panel/navigation stays usable. During the interruption,
   repeat DNSdist UDP/TCP and edge HTTP/HTTPS requests and record continued
   responses.
6. Start ClickHouse with `docker compose -f compose.dev.yml start clickhouse`.
   Confirm the availability labels recover, Vector buffer bytes drain, and a
   uniquely generated outage request eventually appears. Any discarded-event
   increase must be recorded as a telemetry-loss interval, not treated as exact
   usage. Repeat at a narrow mobile width.

### Phase 7 completion gate

- Implementation: present for direct bounded telemetry, ClickHouse raw and
  aggregate storage, scoped APIs/UI, and idempotent PostgreSQL usage exports.
- Documentation: present in the analytics, log schema, retention/privacy,
  export-contract, outage-runbook, and qualification documents.
- Automated/runtime qualification: agent-owned evidence is recorded in
  `docs/phase-7-qualification.md`.
- Manual browser qualification: owner-run; **not executed and Phase 7 is not
  release-qualified until every checkpoint above is recorded as passed**.

## Phase 8 — Operations and production qualification

### Administrator operations

1. Sign in at `/admin`. Confirm **Component health** shows every component with
   exactly Healthy, Degraded, or Unavailable and a check time. The API detail
   must include control database, queue backend/workers, scheduler, host clock,
   MMDB, DNS/deployments, ClickHouse, Vector, edge nodes/listeners/cells/pools,
   placement/configuration/capacity, emergency modes, TLS, purges/runtime tasks,
   usage, operations, and backups. Stop ClickHouse; confirm only
   analytics/telemetry degrades while DNSdist UDP/TCP and edge HTTP/HTTPS
   continue. Restart it and confirm recovery.
2. Open **Platform settings → Operations and recovery**. Set audit retention
   `365`, scheduler stale threshold `180`, clock drift warning `5`, and backup
   stale threshold `26`. Save, refresh, and confirm typed persistence. Values
   outside displayed bounds must reject the whole save.
3. Create one disposable failed queue job. Call the failed-jobs API and confirm
   its lane, job name, first exception line, and time appear but serialized
   arguments/secrets do not. Correct the cause, retry it, and confirm an audit
   event. Delete a second disposable failure and confirm deletion is audited.
4. Invoke DNS, edge, TLS, purge, and usage reconciliation with five UUID
   `Idempotency-Key` values. Repeat each request and confirm one operation per
   scope, bounded progress, completion, and no duplicate per-resource storm.
5. Open Prometheus and confirm `cdnfoundry-control` is up; inspect component,
   queue depth/age, failed operation, DNS drift, stale edge, and certificate
   expiry series. Trigger one disposable threshold and confirm Alertmanager
   receives and later resolves the expected rule without customer labels or
   secrets.
6. Sign in as the domain user. Direct requests to system health/components,
   failed jobs, all reconciliation routes, settings, and Horizon must be
   forbidden. `/metrics` without the separate bearer token must be not found.

### Recovery, upgrade, and scale evidence

1. Using the approved off-host system, create an encrypted control PostgreSQL
   backup. Record external ID, checksum, cutoff time, encryption recipient, and
   separate recovery location for decryption material. Never attach secrets to
   this record.
2. Restore on an empty environment and a separate fresh replacement host. Supply
   the exact recovery set, run forward migrations, rebuild PowerDNS, enroll a
   fresh edge from a full snapshot, reconcile lost queues and TLS, rebuild one
   retained usage interval, and verify DNSdist UDP/TCP and edge IPv4/IPv6
   HTTP/HTTPS. Record measured RPO and RTO.
3. Stop/restart control, DNSdist, PowerDNS, one DNS database, one edge,
   ClickHouse, Vector, and the MMDB provider one at a time. Confirm last-valid
   serving, isolation, graceful activation, recovery, and alerts for each.
4. Create real clock offset beyond `5` seconds on a disposable host or inject it
   through the qualified host exporter. Confirm degraded health and alert, then
   restore synchronization and confirm resolution.
5. Canary one prior/current mixed-version control worker, DNS target, and edge.
   Confirm artifact compatibility, stop thresholds, then roll back application
   images without restoring PostgreSQL.
6. Run the roadmap dataset: at least 500,000 domains, 1,000,000 DNS records,
   50,000 daily changes, burst mutations, repeated-domain coalescing, and
   concurrent multi-DNS/multi-edge deployment. Add an edge and prove an
   edge-health change does not rewrite every domain.

### Phase 8 completion gate

- Implementation: present for the encrypted Restic backup API/CLI and
  maintenance-only restore executor.
- Documentation: current operations/recovery runbook and checklist are present.
- Automated/runtime qualification: incomplete until every outstanding item in
  `phase-8-qualification.md` has recorded evidence.
- Manual browser/host qualification: owner-run; **not executed and not complete
  until every Phase 8 checkpoint above is recorded as passed**.

---

## Record the result

For each phase record: date/operator, commit SHA, browser/version, desktop/mobile viewports, exact domain and edge addresses, every checkpoint as pass/fail/not-ready, operation IDs, revisions, screenshots, relevant logs, and any deviations from the example values. Also record Horizon, PowerAdmin, DNSdist UDP/TCP, Prometheus, Alertmanager, and edge results where applicable.

Any broken flow, missing operation, unexpected access, asset error, unexplained pending state, last-valid-state regression, or runtime mismatch fails that checkpoint. Automated API/runtime tests support this job but never replace rendered UI and real-traffic qualification.


---

# Archived post-baseline manual qualification (2026-07-29)

The following document was the active browser qualification immediately before the production-hardening qualification replaced it.

---
title: Post-baseline manual qualification
description: Owner-run browser and real-traffic checklist for the completed baseline and the current post-baseline roadmap phase.
---

# Post-baseline manual qualification

This is the manual, owner-run release job. Coding agents must not launch or
automate a browser. Automated tests and API probes do not replace any browser
checkpoint in this document.

A missing menu, field, action, status, metric, or runtime result is **Failed**,
not not-applicable. Record every result as **Passed**, **Failed**, **Blocked**,
or **Not run**. A current phase cannot pass with a blocked or unexecuted
applicable checkpoint.

## Current scope

The original roadmap is the completed regression baseline. The
[current roadmap](roadmap.md) contains only post-baseline work. This document
covers:

1. a focused browser and real-traffic regression of that baseline; and
2. implemented post-baseline Phases 1–12, with **Phase 12 — Final production
   qualification** as the current roadmap phase.

## Qualification record

Create one record for every run.

| Evidence | Recorded value |
| --- | --- |
| Result | Passed / Failed / Blocked / Not run |
| Date and operator | |
| Commit SHA and working-tree state | |
| Environment and topology | |
| Browser and version | |
| Desktop viewport | |
| Narrow/mobile viewport | |
| Gateway, agent, and cell image versions | |
| IPv4 and optional IPv6 service addresses | |
| Disposable domains and origins | |
| Operation, revision, and task IDs | |
| Metrics/log evidence location | |
| Screenshots or screen recording location | |
| Automated/runtime qualification report | |
| Failures, owners, and retest evidence | |

Evidence must be sanitized. Never record passwords, API tokens, bootstrap
tokens, private keys, customer data, certificate private material, or signing
keys.

## Preparation

Use disposable accounts, domains, origins, and publicly routed test addresses.
Documentation ranges such as `192.0.2.0/24`, `198.51.100.0/24`,
`203.0.113.0/24`, `2001:db8::/32`, and names below `example.test` are examples
only and do not qualify real traffic.

1. From the repository root, start the persistent development topology and run
   migrations explicitly:

   ```sh
   make dev-up
   make dev-migrate
   make dev-pdns-migrate
   docker compose -f compose.dev.yml ps
   ```

2. Confirm the existing named volumes remain in place. Never run `down -v`,
   delete volumes, use `migrate:fresh`, or run destructive Laravel tests against
   development PostgreSQL.
3. Verify `http://localhost:8080/api/health` and
   `http://localhost:8080/api/ready`, DNSdist on UDP and TCP
   `127.0.0.1:1053`, Horizon workers, the scheduler, PowerDNS, ClickHouse,
   Vector, both enrolled edge agents, and every required cell.
4. If an administrator is needed, create it with the documented
   `cdnf:admin:create` command. Do not put its prompted password in the
   qualification record.
5. Prepare:

   - one administrator and one enabled domain user;
   - one unassigned comparison user;
   - one real delegated disposable domain;
   - two proxied hostnames with distinguishable origin responses;
   - one DNS-only hostname;
   - at least two gateway service-address sets, including one IPv4-only edge;
   - a working IPv4 path and, for this support qualification, one configured IPv6 path;
   - one healthy comparison domain that must remain available during failures.

6. Record the exact public address, pool/cell, hostname, origin marker, and
   expected certificate fingerprint for every traffic route.
7. Use the surfaces below. Production-like runs must substitute approved HTTPS
   management and monitoring addresses.

| Surface | Development address |
| --- | --- |
| Landing page | `http://localhost:8080/` |
| Administrator panel | `http://localhost:8080/admin` |
| Domain-user panel | `http://localhost:8080/app` |
| Horizon | `http://localhost:8080/admin/horizon` |
| Development mail | `http://localhost:8025` |
| PowerAdmin, diagnostic only | `http://localhost:9191` |
| Prometheus | `http://localhost:9090` |
| Alertmanager | `http://localhost:9093` |
| Edge A/B health | `http://localhost:8081/healthz`, `http://localhost:8082/healthz` |
| Edge-control local health | `https://localhost:9443/healthz` |

For every browser section, repeat the important read and mutation paths at a
desktop width and a narrow/mobile width. Confirm visible keyboard focus,
associated labels, useful validation at the affected field, readable tables,
safe confirmation for destructive actions, no horizontal page overflow, no
missing assets, and no unexpected browser-console errors.

Where a field, entry, table heading, or section heading has optional
explanatory help, hover the title and focus it with the keyboard. Expect the
same short tooltip with no separate help icon and no persistent help paragraph.
Validation errors, warnings, confirmation text, degraded-state reasons, and
live operational evidence must remain visible without hovering.

## Completed-baseline regression

The baseline remains part of every post-baseline release. Record one result for
each section below; a sampling run does not authorize removal of any baseline
feature.

### Access, authorization, and secrets

1. Open the landing page and follow its links to `/admin`, `/app`, and
   `/api/health`. Expect the CDNFoundry page, compiled assets, and no Laravel
   starter screen.
2. Sign in to `/admin`. Expect the administrator dashboard and the
   **Control plane**, **Customers**, **Edge network**, **Operations**,
   **Observe**, and **Account** navigation groups.
3. Open **Customers → Users → New user**. Create a disposable **Domain user**
   with a unique name, email, password, and matching confirmation. Expect one
   user row and no plaintext password after save.
4. Disable that user. In a separate browser profile, expect `/app` login to be
   denied. Re-enable the user and expect login to succeed.
5. In each panel, open **Account → Profile** and **Account → API tokens**.
   Change the display name, create a named token, record that plaintext appears
   once, refresh, and confirm only metadata/final characters remain. Revoke the
   token and confirm it no longer authenticates.
6. As the domain user, directly request administrator pages for users, DNS
   clusters, edges, service pools, platform settings, audit logs, operations,
   and Horizon. Expect denial and no administrator navigation or data.
7. Open **Audit logs** as administrator. Expect the preceding mutations with
   actor, action, subject, IP, and time, without secrets or editable controls.

### Desired state, DNS, and domain isolation

1. Open **Control plane → System DNS identity**. Confirm the configured platform
   domain, proxy hostname, at least two nameservers and glue addresses, SOA
   values, TTLs, and cluster targets. Preview without mutation, then cancel.
2. Open **Control plane → DNS clusters**. Expect enabled targets to show health
   without exposing their saved API keys.
3. Open **Domains**, select the disposable delegated domain, and confirm its
   lifecycle, desired revision, assigned users, authoritative deployment, and
   edge-delivery state.
4. Sign in as the assigned domain user. Expect only assigned domains. Directly
   request an unassigned domain URL and expect not found or forbidden without
   leaking its name or state.
5. In **DNS records**, create and delete one disposable DNS-only TXT record.
   Expect one durable domain revision per effective mutation, asynchronous DNS
   reconciliation, the correct answer after creation, and no answer after
   deletion through DNSdist over UDP and TCP.
6. Attempt an out-of-zone owner, invalid TTL, conflicting CNAME, and a
   domain-user delegation change. Expect field-level rejection, no partial
   mutation, and no revision increase.
7. Preview one existing Geo-DNS record with country, continent, default, IPv4,
   and IPv6 inputs. Expect country before continent before default and no
   desired-state mutation.

### Proxy, TLS, cache, security, and isolation

1. Open a proxied hostname in **DNS records**. Confirm the origin destination,
   scheme, locked standard port, **Origin Host header**, **TLS SNI**, TLS
   verification, timeouts, retry count, WebSocket setting, health-check
   setting, and platform-managed DNS route.
2. Choose **Test origin**. Expect an asynchronous operation and a bounded
   status/latency result or stable failure reason; the browser request must not
   wait for the external probe.
3. Send real HTTP and HTTPS traffic over IPv4 and IPv6. Expect the intended
   origin marker, forwarding behavior, certificate, cache status, and no
   Laravel request-path dependency.
4. Open **TLS**. Confirm mode, covered names, expiry, fingerprint, and deployment
   status without private-key material.
5. Open **Cache**. Request one cacheable URL twice and expect MISS then HIT.
   Purge that exact URL and expect a durable operation followed by MISS. Do not
   scan or delete cache directories.
6. Open **Security profile and limits** and the security-rules relation. Confirm
   the active bounded profile and test one disposable IPv4 rule and one IPv6
   rule. Expect stable allow/block behavior and removal without unrelated
   traffic impact.
7. Open **Security** on the disposable domain. Start maintenance with a unique
   response message and expect HTTP 503 only for that domain; end maintenance
   and expect normal service. Use **Enable Under Attack mode**, then **Return to normal**,
   and confirm the security state and operation are visible. If a quarantine
   pool is ready, use **Quarantine domain** and confirm target-first placement
   moves only that domain before returning it to normal.
8. On a disposable service pool, choose **Maintenance**, enter an automatic
   expiry, and expect HTTP 503 only from cells assigned to that pool. Confirm
   edge and cell screens have concrete **Drain**, **Undrain**, and **Restart**
   controls and no generic emergency-action picker. End pool maintenance.
9. Keep the healthy comparison domain active throughout. Expect no change in
   its DNS, TLS, cache, origin, or security behavior.

### Telemetry, operations, and recovery visibility

1. Generate controlled DNS, HTTP, HTTPS, HIT, MISS, origin-failure, and security
   events. As the domain user, open **Observe → Analytics** and expect only
   assigned-domain aggregates and logs with bounded ranges and masked client
   addresses.
2. As administrator, open **Observe → Telemetry**. Expect component freshness,
   bounded/redacted data, and no secrets. Expect the blue **Live window
   included** label to explain that only the configured newest window is
   provisional, not that delivery is degraded. Expect **Finalized usage** to
   show no more than five rows and every shown row to be **Finalized**.
3. In **Compression savings**, expect identity delivery to retain its
   **Identity** wire label with **No content encoding** beneath it. An identity
   row without a fallback must explain that the response was normally
   ineligible or below the size limit rather than displaying **None**. Expect
   five rows initially with
   **Show more**/**Show fewer** controls. **Encoding / profile** and
   **Fallback** remain left-aligned while numeric columns remain right-aligned
   without page overflow. In
   **Recent logs**, expect five rows per stream, **Edge requests** to contain
   generated edge traffic, and **Show more**/**Show fewer** to expand and
   collapse a stream without reloading the page.
4. In **Vector buffer and delivery**, expect explicit human-readable current
   **Buffered data** and **Buffered events** gauges plus **Discarded events
   since start** and **Component errors since start**. Generate a controlled
   buffer/recovery event and expect the current gauges to recover. Lifetime
   counters need not reset before Vector restarts, may describe the same failed
   events, and must identify affected components when nonzero.
5. Open the administrator dashboard and inspect **Component health**. For each
   degraded/unavailable component, expect its bounded counts/timestamps and a
   component-specific **How to fix** direction. Confirm **Queue lanes** shows
   ready, reserved, delayed, and total work and **Recent audit activity** is a
   table directly below it in the same right-hand column. Leave the page open
   for 15 seconds and expect the evidence to refresh without a browser reload.
   Resolve one controlled failure and expect only its component to recover.
6. Open **Operations**. Inspect pending, succeeded, and failed examples. Expect
   operation ID, type, requester, status, attempts, timestamps/duration, and a
   bounded error. Use the copy control and paste into a plain-text field; expect
   the complete UUID rather than the shortened table label. Retry only a
   supported disposable failure and expect no duplicate active work.
7. Open **Platform settings**. Save one reversible non-runtime value and restore
   it. Expect typed validation and audit history. Do not change a production
   secret.
8. Stop telemetry only in the controlled environment. Expect a visible
   degraded state while DNS and edge traffic continue. Restore telemetry and
   record bounded buffer recovery.
9. Refresh, sign out, sign in, and restart the affected non-database service.
   Expect PostgreSQL desired state and the previous valid runtime state to
   remain intact.

### Baseline regression result

| Area | Result | Evidence or failure |
| --- | --- | --- |
| Access, authorization, and secrets | | |
| Desired state, DNS, and domain isolation | | |
| Proxy, TLS, cache, security, and isolation | | |
| Telemetry, operations, and recovery visibility | | |

The baseline regression passes only when every row is **Passed**.

## Phase 1 — Edge gateway ingress

### Purpose and topology

Qualify one minimal gateway per edge that binds public IPv4 and optional IPv6
service addresses and routes to bounded OpenResty cells. The gateway routes HTTP by
destination address and validated Host, and routes HTTPS by destination address
and TLS SNI without terminating customer TLS.

Use one dual-stack service-address set, one IPv4-only service-address set, two
distinguishable hostnames, two target cells, and one unrelated comparison route. Record the
exact mapping before the run:

| Route | Service IPv4 | Service IPv6 | Host/SNI | Target cell | Origin marker |
| --- | --- | --- | --- | --- | --- |
| A | | | | | |
| B | | | | | |
| Comparison | | | | | |

### Browser state and authorization

1. Sign in as administrator and open **Edge network → Edges**.
2. Open each participating edge. Expect **Enrolled at**, **Last heartbeat**,
   **Agent version**, **Traffic listener**, **Active configuration sequence**,
   **Identity expires**, and **Latest deployment rejection**. Record the values
   before traffic testing.
3. Open the **Cells** relation. Expect each target to show **Cell**,
   **Service pool**, status, **Service addresses**, runtime/version, workload,
   resources, storage, and drain state. Confirm Route A and Route B have the
   intended unique configured addresses and ready cells. The IPv4-only cell
   must save and become ready with its IPv6 field empty.
4. Open **Edge network → Service pools**. Expect the relevant enabled pool,
   withdrawal state, **DNS routing target**, revision, and edge-cell count.
5. Refresh both pages. Expect the same durable desired state and current
   acknowledged runtime state; no one-time enrollment secret may reappear.
6. Sign in as a domain user and directly request the edge and service-pool
   administrator URLs. Expect denial with no fleet addresses, revisions,
   capacity, or failure details disclosed.
7. On each edge detail page, expect **Gateway process** = **Ready**, **Gateway map
   revision** equal to **Active configuration sequence**, and visible **Gateway
   listeners**, **Gateway routes**, **Gateway active connections**, **Gateway
   errors**, and **Gateway rejected candidates**. Record every value. If any
   field is absent or the revision differs, mark this checkpoint **Failed**.
8. Leave one edge detail page open for at least 15 seconds. Expect **Last
   heartbeat** and the Cells/Endpoints readiness data to refresh without a
   browser reload. Restart only that edge agent container. Expect the heartbeat
   to become stale after the configured threshold and then recover within two
   successful five-second polls. Confirm the page remains a compact value grid
   without explanatory paragraphs below the fields.
9. Hover over every live edge field label and focus each label with the
   keyboard. Expect a short tooltip without an extra help icon or any layout
   change. Confirm the **Traffic listener** and **Gateway process**
   tooltips distinguish listener convergence from gateway process readiness.
   Confirm **Active configuration sequence** and **Gateway map revision**
   tooltips say these monotonic identities must not be reset. Confirm **Gateway
   routes** explains that it is the current destination-address plus Host/SNI
   protocol-map count, not a lifetime counter.

### HTTP routing

For every probe, record timestamp, destination address, Host, response status,
target cell, origin marker, gateway revision, and relevant metric/log evidence.

1. Send HTTP for Route A to its service IPv4 with Route A's exact Host. Expect
   Route A's cell and origin marker.
2. Repeat over Route A's service IPv6. Expect the same logical route and
   response.
3. Repeat over Route B's IPv4-only address. Expect Route B's cell and origin
   marker, not Route A's, with no IPv6 value required or synthesized.
4. Send Route A's Host to Route B's address and Route B's Host to Route A's
   address. Expect only mappings explicitly present in the active routing map;
   an absent address/Host pair must be rejected before origin traffic.
5. Send an unknown Host, empty Host where the protocol permits the probe,
   malformed Host, overlong Host, and duplicated/conflicting Host header.
   Expect a bounded rejection and zero origin requests.
6. Send traffic to an unconfigured destination address on the test host.
   Expect no gateway route and no cell or origin request.
7. Attempt to spoof the gateway-to-cell client-identity fields documented by
   the implementation. Expect untrusted inbound values to be removed or
   replaced and the cell to receive only the gateway's trusted identity.
8. Repeat a valid request after every rejection. Expect normal latency and no
   poisoned keepalive, route, or connection state.

### HTTPS SNI routing

Use valid certificates and strict verification for release qualification.
Development-only `-k` probes do not qualify certificate behavior.

1. Connect to Route A's IPv4 with Route A's exact SNI and send its matching HTTP
   Host through the TLS connection. Expect the cell-selected certificate,
   Route A's origin marker, and no customer TLS termination at the gateway.
2. Repeat Route A over IPv6, then repeat Route B over IPv4 only. Expect all
   configured paths to serve without requiring an IPv6 value for Route B.
3. Record the served certificate fingerprint for each route and compare it with
   the expected cell certificate.
4. Send Route A's SNI with Route B's Host and the reverse. Expect the
   implementation's documented safe rejection; no unintended origin may be
   contacted.
5. Try unknown SNI, missing SNI, malformed SNI, and an SNI name not present for
   that destination address. Expect rejection before customer request
   processing and zero origin requests.
6. Repeat a valid TLS request immediately afterward. Expect the active route
   and certificate to remain unchanged.

### Atomic activation, failure, and recovery

Perform candidate changes only through the supported desired-state workflow.
Never edit generated gateway or cell files directly.

1. Record the active gateway map revision and checksums, then make one valid
   disposable routing change. Expect desired state to commit, asynchronous work
   to become visible, a validated candidate to activate atomically, and the
   acknowledged revision to advance.
2. During activation, continuously request Route A, Route B, and the comparison
   route over every configured family. Expect no partial map, cross-route response, or
   unnecessary interruption.
3. Submit a deliberately invalid candidate using the supported qualification
   fixture. Expect validation failure, a stable reason, no acknowledgement of
   the invalid revision, and the previous valid map to keep serving.
4. Confirm **Latest deployment rejection** or the gateway-specific failure
   surface shows a bounded reason without candidate contents or secrets.
5. Retry/coalesce the same desired revision. Expect idempotent work and no
   duplicate activation. Supersede it with a newer revision and expect obsolete
   work to stop without replacing the newer valid state.
6. Restart only the gateway. Expect it to restore or rebuild the last valid map
   and become ready without rebuilding customer state through Laravel request
   paths.
7. Make the control plane temporarily unavailable. Expect established and new
   valid HTTP/HTTPS traffic to continue from local state.
8. Make one target cell unavailable. Expect a bounded failure isolated from the
   other route, comparison cell, edge agent, and gateway process.
9. Restore the cell and control plane. Expect reconciliation to converge on the
   latest desired revision without manual file repair.
10. Return every disposable mutation to its starting state and record the final
    active revision and health.

### Metrics, bounds, and scale evidence

1. In the implemented monitoring surface, confirm gateway listener, active
   revision, route count, connections, errors, and readiness are visible per
   edge without customer secrets or unbounded labels.
2. Generate accepted and rejected HTTP and HTTPS traffic over every configured
   family, including the IPv4-only edge. Expect the
   corresponding counters/state to change and unrelated cell telemetry to
   remain attributable.
3. Link the agent-owned scale report for at least 50,000 Host/SNI mappings and
   multiple dual-stack service pairs on one edge. The report must state
   hardware/topology, dataset, concurrency, duration, throughput, latency, CPU,
   memory, saturation point, and accepted limit.
4. Link failure evidence for invalid candidates, gateway restart, control-plane
   outage, target-cell outage, retry, obsolete work, and last-valid-state
   preservation.
5. Confirm alerts and runbooks identify the edge, active revision, degraded
   component, stable reason, and operator action.

### Phase 1 completion gate

Fill every row. Link evidence instead of writing only “passed.”

Agent-owned status on 2026-07-27: implementation, Go unit/scale tests, 162
isolated Laravel tests, Compose/Prometheus validation, documentation checks,
the non-browser dual-stack and IPv4-only HTTP/HTTPS runtime test, and the
completed-baseline non-browser regression stages passed. See
[Edge gateway ingress](operations/gateway-ingress.md). Agent-owned strict TLS
verification passed with the active development ACME trust root. Owner browser
evidence, including browser-native strict certificate verification, remains
**Pending owner run**, so the release decision remains **Blocked** and the rows
below must not be marked Passed by a coding agent.

| Gate | Result | Required evidence |
| --- | --- | --- |
| Implementation | Passed | [Gateway design and operation](operations/gateway-ingress.md) and administrator gateway state |
| Unit and feature tests | Passed | [CI run 30290594675](https://github.com/vaheed/CDNFoundry/actions/runs/30290594675) |
| Real-runtime E2E | Passed | [Gateway qualification evidence](operations/gateway-ingress.md#qualification-evidence) |
| IPv4 and IPv6 | Passed | Dual-stack and IPv4-only runtime evidence in the gateway qualification report |
| Scale | Passed | 50,000-map hardware, load, resource, latency, and saturation report |
| Failure and recovery | Passed | Invalid candidate, restart, outage, rollback, and last-valid runtime qualification |
| Isolation | Passed | Unknown route and target failure remain isolated in the runtime suite |
| Observability | Passed | [Metrics, alerts, and diagnostics](operations/gateway-ingress.md#monitoring-and-failures) |
| Documentation | Passed | User, administrator, architecture, deployment, operations, troubleshooting, and runbook checks |
| Manual qualification | Pending owner run | One gateway-detail screenshot accepted; all remaining baseline and Phase 1 checkpoints require owner evidence |
| Regression | Passed | CI backend/runtime E2E and completed-baseline non-browser regression stages |
| Release decision | Blocked | Awaiting the remaining owner-run manual browser qualification |

Phase 1 is complete only when every applicable gate is **Passed**. A missing UI,
failed configured IPv6 path, failed IPv4-only path, unexecuted scale run, or
unrecorded browser result keeps the phase incomplete.

## Phase 2 — Bounded cell inventory

Do not start this gate until Phase 1 is Passed. Record the edge UUID, configured
slot count, release SHA, host, browser, and timestamps. The shipped topology
uses eight slots.

### Fresh inventory and authorization

1. Sign in as an administrator and open **Edge network → Edges → New edge**.
   Enter a unique name, country, continent, public IPv4, optional public IPv6,
   and **Cell slots = 8**. Save and copy the one-time bootstrap token.
2. Open the new edge and its **Cells** relation. Expect exactly `cell-01`
   through `cell-08`, consecutive slot numbers, unique HTTP/HTTPS/status ports,
   unique runtime paths, and no extra row. Expect `cell-01` assigned to shared,
   `cell-02` assigned to quarantine, and `cell-03`–`cell-08` unassigned.
3. Attempt another edge with slot counts 0 and 33. Expect field validation and
   no edge, slot, token, task, or audit side effect. Create a disposable edge
   with one slot and expect exactly `cell-01`; drain, disable, and delete it.
4. Enroll the eight-slot edge. Refresh its detail page and Cells relation.
   Expect current enrollment, heartbeat, agent version, gateway readiness, and
   every running slot's ready/drained state and capacity. No bootstrap secret
   may reappear.
5. Sign in as a domain user and request the edge list/detail and cell API URLs.
   Expect denial without slot identity, assignment, address, capacity, path,
   resource, revision, or failure disclosure.

### Runtime controls and isolation

1. Record every cell's status, active revision, assigned domain count, active
   connections, CPU, memory, cache, temporary storage, and last restart.
   Expect CPU as a percentage, and memory/cache/temporary values in human-readable
   binary units rather than raw counters.
2. Drain `cell-02`. Expect one pending operation/task, then **Drained** only for
   that slot. Repeat the action with the same idempotency key through the API;
   expect the same operation and no duplicate task.
3. Undrain `cell-02`. Expect pending then ready. Restart `cell-02`; expect its
   restart timestamp/generation to advance after a bounded drain while the
   edge agent, gateway, `cell-01`, and `cell-03`–`cell-08` remain available.
4. Stop `cell-04` through the operator runtime fixture. Expect it to become
   stopped or degraded with a stable reason. Valid traffic targeting another
   cell must continue, gateway and agent readiness must remain, and unrelated
   revisions/capacity must not reset.
5. Saturate the disposable `cell-04` CPU and memory only up to its cgroup
   ceilings. Expect the container limit to hold and another cell's traffic and
   status to remain available. Record host and per-cell metrics.
6. Restore `cell-04`. Expect reconciliation to return it to the latest active
   revision without editing generated files or replaying unrelated cells.

### Recovery, bounds, and evidence

1. Restart the agent. Expect enrollment identity, mutual TLS, acknowledgements,
   active sequence, all eight slot files, drained controls, and last-valid
   snapshot recovery. No cell-engine socket may be mounted in the agent.
2. Make the control plane unavailable. Restart one cell and keep valid traffic
   on another. Expect local serving and previous valid state to continue; after
   restoration, expect convergence without duplicate activation.
3. Present an invalid slot mapping and invalid runtime candidate through the
   supported fixture. Expect rejection, bounded reason, and previous active
   state for every unrelated slot.
4. Confirm each cell has 512 MiB memory, 0.5 CPU, 128 PID, 256 MiB cache, 64 MiB
   request-temporary, and 16 MiB log ceilings. Fill each disposable storage area
   to its ceiling and expect bounded failure without host filesystem growth or
   another cell losing service.
5. Link the eight-slot agent-owned report with host/topology, idle and active
   overhead per slot, concurrency, workload, saturation result, accepted limit,
   crash isolation, restart, snapshot recovery, IPv4/IPv6, and baseline
   regression evidence.

### Phase 2 completion gate

Agent-owned implementation, PostgreSQL expand migration, 162 isolated Laravel
tests, Go format/vet/test/build, Compose validation, the cumulative non-browser
baseline/runtime regression, and the eight-slot overhead/isolation test passed
on 2026-07-27. The owner browser run above and the Phase 1 release gate must both
be Passed before changing this phase's release decision from **Blocked**.

| Gate | Result | Required evidence |
| --- | --- | --- |
| Implementation | Passed | [Bounded inventory design and operations](operations/cell-inventory.md) |
| Unit and feature tests | Passed | 162 Laravel tests / 1,280 assertions and Go format/vet/test/build |
| Real-runtime E2E | Passed | Eight-slot test plus enrollment, mTLS, snapshot, restart, and cumulative baseline runtime suite |
| IPv4 and IPv6 | Passed | Authoritative DNS and edge baseline dual-stack/IPv4-only evidence |
| Scale | Passed | Eight-slot idle/active overhead and isolation report |
| Failure and recovery | Passed | Control outage, restart, retry, rollback, and last-valid cumulative evidence |
| Isolation | Passed | `cell-04` stop left `cell-05` and support process ready |
| Observability | Pending owner run | Runtime metrics passed; cell state/capacity and alert screenshots remain owner evidence |
| Documentation | Passed | User, administrator, reference, deployment, operations, troubleshooting, and runbook checks |
| Manual qualification | Pending owner run | Every exact browser checkpoint above |
| Regression | Passed | Completed baseline and Phase 1 cumulative non-browser checks |
| Release decision | Blocked | Phase 1 and owner-run Phase 2 browser evidence are not Passed |

## Phase 3 — Multi-cell pools and stable placement

Use one enrolled edge with at least four running slots: three assigned to one
shared pool and one assigned to quarantine. Use three disposable proxied
domains with distinguishable origin markers. Do not change production traffic
for this checklist.

1. Sign in as administrator and open **Edge network → Service pools**. Create or
   edit the disposable shared pool. Expect kind, minimum ready cells, replicas
   per edge, maximum domains per cell, revision, and total participating cells.
2. Set minimum ready cells to `3`, replicas per edge to `1`, and a test-safe
   capacity. Open **Edge network → Edges**, edit the disposable edge, and use
   **Assign service pool** on three free cells. Select only the shared pool;
   there are no address fields on a cell. Expect one operation per assignment
   and stable cell names, ports, and runtime paths.
3. Attempt replicas `2` on shared and quarantine pools. Expect validation
   failure. On a reserved disposable pool, expect `2` or `3` to save; values
   above `3` must fail. Confirm dedicated placement cannot serve a second
   domain.
4. Open each test domain and choose **Move service pool**. Record domain,
   revision, active pool/cell, target pool/cell, operation ID, and timestamps.
   Expect normal domains to distribute across the three cells while each
   remains on one stable cell per edge.
5. Add an unrelated domain and an additional unused shared cell. Refresh all
   prior domains. Expect every valid prior assignment to remain unchanged.
6. Inspect the three cell runtime diagnostics and gateway routes. Each domain
   must appear only on its selected cell. The quarantine and unassigned cells
   must contain no test-domain artifact, certificate, Host route, or SNI route.
7. Start a move, keep the target cell unready, and send HTTP/HTTPS traffic.
   Expect the source marker throughout and visible deploying/degraded reason.
   Restore readiness and acknowledge the target. Expect the gateway to switch,
   then the source to remain during the bounded drain and disappear afterward.
   If a deploying reconciliation is interrupted for more than five minutes,
   expect the scheduler to requeue the same coalesced operation and converge.
8. Stop one shared target cell. Domains on the other two cells and quarantine
   traffic must continue. Restore it and expect last-valid state convergence
   without unrelated placement changes.
9. Repeat representative HTTP and strict HTTPS probes over IPv4 and configured
   IPv6. On an IPv4-only topology, leave IPv6 empty and expect readiness without
   a synthesized address.
10. Sign in as a domain user. Expect domain placement visibility only for
    assigned domains and denial from service-pool, cell-assignment, fleet
    capacity, and other-domain endpoints.

### Phase 3 completion gate

The coding-agent run must record implementation, automated/runtime, scale,
failure, isolation, documentation, and regression evidence below. The owner
records browser and real-traffic evidence after completing the exact steps
above. Until that row is Passed, the release decision remains **Blocked**.

| Gate | Result | Evidence |
| --- | --- | --- |
| Implementation | Passed | Stable per-edge cell state, pool policy, targeted artifacts, and cell-aware gateway maps; PostgreSQL expand migration applied in 209.95 ms |
| Automated/runtime qualification | Passed | 177 Laravel tests / 11,369 assertions; edge-agent Go test/build; strict dual-stack and IPv4-only gateway runtime; three-cell Host routing/isolation |
| Scale | Passed | 20,000 deterministic placements and 10,000 placement-affecting changes completed in 0.16 s with zero unnecessary reshuffles |
| Documentation | Passed | Concepts, API/OpenAPI, configuration, testing, operations, troubleshooting guidance, manual checklist, and roadmap |
| Manual browser and real traffic | Pending owner run | Steps 1–10 with screenshots, operation/revision IDs, and HTTP/HTTPS evidence |
| Release decision | Blocked | Owner-run evidence is mandatory |

## Phase 4 — Pool service endpoints and Geo-Unicast

Use two enrolled disposable edges. On the first edge assign three ready cells
to a shared pool and one ready cell to a reserved pool. Use service addresses
routed to the test gateways and distinct from every management address.

1. Sign in as administrator and open **Edge network → Edges → View → Pool
   endpoints**. Create a dual-stack endpoint for the shared pool. Enter its
   service IPv4 and IPv6. Expect `pending`, desired revision `1`, and no DNS
   publication before the gateway acknowledges it.
2. On the same edge create a different endpoint for the reserved pool. Attempt
   the shared IPv4, a management address, a private address, and an empty pair.
   Expect field validation and no desired revision or gateway change. Save a
   distinct valid pair.
3. Configure the second edge with an IPv4-only shared endpoint. On a disposable
   pool configure an IPv6-only endpoint. Expect all three family modes to save
   without synthesizing a missing address.
4. Set the shared pool minimum ready cells to `3`. Start all three cells and
   inspect the endpoint table. Expect gateway `ready`, active revision equal to
   or greater than desired revision, and readiness `ready`. Stop one cell;
   expect `insufficient_ready_cells` and only that endpoint to leave DNS.
5. Query the pool hostname from a country matching edge one, a continent-only
   location, and an unknown location. For A and AAAA separately, expect country,
   then continent, then global fallback and only ready endpoint addresses.
6. Send HTTP Host and strict HTTPS SNI traffic to both endpoint pairs. Expect
   the shared endpoint to distribute only across its three cells and the
   reserved endpoint to reach only its assigned cell. Unknown Host/SNI and an
   address/hostname conflict must be rejected before activation.
7. Withdraw the shared endpoint on edge one. Expect only that edge/pool pair to
   disappear; the reserved pair and edge-two shared pair continue. Restore it
   and expect publication only after a new gateway acknowledgement.
8. Edit a disposable dual-stack endpoint and clear IPv6. Expect IPv4 to remain
   and IPv6 to disappear after acknowledgement. Clearing the last address must
   fail. Turn on **Temporarily remove from traffic**, then delete the endpoint;
   expect the saved endpoint to disappear while its cells remain assigned.
9. Restart the gateway and agent while sending traffic. Expect the prior valid
   map to serve until the desired candidate validates, then gateway, DNS, pool,
   and cell state converge to the same revision without a broad domain rewrite.
10. Sign in as a domain user. Expect denial from endpoint CRUD, gateway candidate,
   fleet readiness, and management-address data.
11. Record topology, hardware, domain count, endpoint count, concurrent health
    changes, DNS reconciliation duration, CPU/memory, saturation point, accepted
    limit, revision IDs, and sanitized HTTP/HTTPS/DNS evidence for at least two
    edges and several pools.

### Phase 4 completion gate

| Gate | Result | Evidence |
| --- | --- | --- |
| Implementation | Passed | Revisioned edge/pool endpoints, unique address constraints, mTLS gateway candidate, readiness reasons, Geo-Unicast publication, API, and administrator UI |
| Unit and feature tests | Passed | 182 isolated Laravel tests / 11,388 assertions; edge-agent Go test/build |
| Real-runtime E2E | Passed | Two-edge mTLS control-plane and real PowerDNS test; placement migration reached revision 13 with zero obsolete artifacts |
| IPv4 and IPv6 | Passed | Feature coverage for IPv4-only, IPv6-only, and dual-stack endpoints plus real dual-stack DNS publication |
| Scale | Passed | Existing 20,000-domain / 10,000-change dataset plus bounded two-edge, two-pool endpoint reconciliation without a domain-wide rewrite |
| Failure, recovery, and isolation | Passed | Conflict rejection, readiness-gated publication, isolated edge/pool withdrawal, restoration, and last-valid gateway/DNS regression |
| Observability | Pending owner run | Endpoint state/reason and gateway revision screenshots plus runtime metrics |
| Documentation | Passed | Endpoint operations, topology, configuration, troubleshooting, and exact owner checklist |
| Manual browser and real traffic | Pending owner run | Steps 1–11 with screenshots, revisions, and traffic evidence |
| Regression | Passed | Full Laravel suite, Compose/OpenAPI/docs checks, edge-agent build, cache-control regression, and prior placement scale dataset |
| Release decision | Blocked | Owner-run evidence is mandatory |

## Phase 5 — Simple Anycast pools

Use two provider-approved POPs with the same routed IPv4 and, where available,
IPv6 service pair. Keep one Geo-Unicast pool and hostname active as an
unrelated comparison. Record provider ticket/change ID, prefix ownership,
route collectors, edge IDs, cell IDs, pool/endpoint revisions, gateway active
revisions, domain revisions, operation IDs, and UTC timestamps.

1. Sign in as administrator and open **Edge network → Service pools → New
   service pool**. Choose **Simple Anycast**. Hover or focus the relevant field
   labels and confirm the tooltips state
   that CDNFoundry binds and publishes addresses but does not announce or
   withdraw BGP routes. Confirm it also states that pool creation assigns no
   edge or cell, one distinct pair belongs to one pool, and **Shared** is the
   normal kind. Enter the approved IPv4 and optional IPv6 pair. After creation,
   confirm every existing edge has exactly the same assignments as before and
   no `edge.pool_provision` operation exists.
2. Attempt an empty pair, private/special address, management address, existing
   Geo-Unicast address, and address owned by another Anycast pool. Expect
   field-level rejection, no pool/revision, and no gateway or DNS change.
3. Use **Cells → Assign service pool** to assign the required slots on POP A
   and POP B. Expect the first assignment to create one participation record
   automatically on that edge with the inherited pair. Additional cells reuse
   it. Confirm the endpoint creation form lists only Geo-Unicast pools, and an
   unrelated edge consumes no slot and gains no participation record.
4. Enable the pool. Expect both gateways to receive the identical dual-stack
   pair, only their local assigned cells as targets, `pending` before local
   acknowledgement, and then pool route state `ready`. Unknown Host/SNI and
   conflicting address candidates must preserve the previous valid map.
5. Query the pool target and a disposable assigned domain through DNSdist over
   UDP/TCP from each supported family. Expect exactly the shared pair without
   country/continent data records. Confirm the comparison Geo-Unicast hostname
   still uses country, continent, then global fallback.
6. From at least three independent external vantage points (including one IPv6
   vantage when configured), record route origin/path, selected POP, HTTP Host,
   strict HTTPS SNI/certificate, origin marker, status, and latency. Expect the
   provider route—not CDNFoundry—to select a healthy POP.
7. Stop or drain POP A's participating cell/gateway without changing POP B.
   Expect pool `degraded`, POP B's candidate/revision and traffic unchanged,
   and DNS to retain the pair while POP B remains ready. Record route behavior
   from all vantage points and confirm unrelated Geo-Unicast traffic continues.
8. Through the network operator/provider workflow, withdraw the route at POP A
   and record ticket/change ID, exact request/effective timestamps, route
   collector evidence, traffic convergence, and accepted loss window. Restore
   it and record the same evidence. CDNFoundry must issue no router command and
   store no router credential.
9. Use the CDNFoundry pool **Withdraw** action. Expect gateway candidates and
   authoritative DNS publication to withdraw while the external route remains
   operator-owned. Coordinate provider withdrawal if required. Restore the
   pool, acknowledge both gateways, and expect publication and traffic only
   after local readiness returns.
10. Restart one agent and gateway. Inject one invalid candidate. Expect the
    previous valid map to serve, the other POP to remain unchanged, and the
    restarted POP to converge from desired state with clear reason/revision.
11. Record topology, provider, hardware, two-POP concurrent traffic, throughput,
    latency, CPU, memory, connection count, uplink utilization, saturation
    point, and accepted limit. Confirm the UI/runbook explicitly says Anycast
    is not upstream volumetric scrubbing and cannot protect a saturated uplink.
12. Sign in as a domain user. Expect only assigned-domain routing visibility
    and denial from pool pair, endpoint participation, edge candidate,
    readiness, and fleet capacity administration.
13. Create a disposable disabled pool and confirm **Delete** is available.
    Assign a cell and expect deletion to be blocked. For Anycast, unassign the
    final cell and expect its automatic participation record to disappear;
    then delete the empty pool. Expect an audit row and no effect on other
    pools, cells, endpoints, DNS, or gateway candidates.

### Phase 5 completion gate

| Gate | Result | Evidence |
| --- | --- | --- |
| Implementation | Passed | Pool-owned pair, explicit edge participation, shared gateway candidates, direct DNS publication, stable status reasons, authorization, audit, and last-valid reconciliation |
| Unit and feature tests | Passed | 191 isolated Laravel tests / 11,437 assertions, including 8 focused Anycast tests / 44 assertions |
| Real-runtime E2E | Passed | Two-edge mTLS gateway candidate and real PowerDNS withdrawal/restoration qualification; cache/placement regression reached revision 13 |
| IPv4 and IPv6 | Passed | Dual-stack Anycast and Geo-Unicast runtime plus IPv4-only automated gateway/endpoint regression |
| Scale and external network evidence | Pending owner run | Two approved POPs, at least three external vantage points, provider route evidence, load/saturation measurements |
| Failure, recovery, and isolation | Partially passed; owner network run pending | Controlled POP loss, gateway acknowledgement race recovery, unrelated POP state, invalid-candidate/last-valid regression passed; provider route withdrawal/restoration is owner-run |
| Observability | Pending owner run | Ready/degraded/withdrawn UI, gateway revisions/reasons, route collectors, metrics, logs, alerts |
| Documentation | Passed | Administrator UI guidance, operations runbook, architecture/troubleshooting links, exact owner checklist |
| Manual browser and real traffic | Pending owner run | Steps 1–13 with screenshots, revisions, provider evidence, and traffic captures |
| Regression | Passed | Full Laravel suite, Compose/OpenAPI/docs checks, edge-agent and edge-gateway Go test/build images, real cache and placement regression |
| Release decision | Blocked | Owner-operated BGP and external vantage evidence is mandatory and cannot be executed by the coding agent |

## Phase 6 — Persistent bounded cache

Use a disposable proxied domain in a pool with at least two ready cells. Record
the pool, placement, edge, cell, domain revision, cache epoch, storage quota,
origin request counter, and all operation/task IDs.

1. As administrator, open **Edge network → Service pools**, edit the disposable
   pool, and verify **Cache profile** offers Small, Standard, Large, and
   Streaming. Save Small. Expect one pool revision increment and one coalesced
   global edge reconciliation. Confirm no new cell, process, directory, timer,
   or container is created.
2. Open the domain and choose **Cache → Cache settings**. Verify enabled, edge
   and browser TTL, maximum object, origin-header policy, four query policies,
   selected query names, bypass cookies, approved status TTL map, admission
   count, stale-if-error, stale-while-revalidate, serving mode, and variant
   ceiling. Save include-selected with `page` and `lang`, 200=`60`, 404=`15`,
   admission=`2`, both stale windows=`10`, normal mode, and variants=`8`.
   Expect `202`, a domain revision increment, audit row, and target-first
   delivery to participating cells only.
3. Attempt 33 query names, status `418`, admission `0` and `11`, variant `0`
   and `129`, stale values above `86400`, and an object larger than 1 GiB.
   Expect typed validation with no revision, artifact, task, or audit side
   effect. Repeat one valid mutation with the same idempotency key and expect
   the original response; reuse it with different input and expect conflict.
4. Request `/asset?page=2&ignored=a&lang=fa` twice, then reorder the query and
   change only `ignored`. Expect one MISS followed by HITs. Change `page` and
   expect MISS. Switch to ignore-all and expect all query variants to share one
   key. Confirm an exact URL purge uses the same normalized key.
5. Request a cacheable 200 twice and expect MISS then HIT. Request a configured
   404 twice and expect MISS then HIT for 15 seconds. Confirm an unapproved
   status, authorization, configured cookie, Set-Cookie, private/no-store,
   unsupported Vary, oversized object, and range request bypass or do not
   store. Confirm the second request requirement prevents one-hit pollution.
6. Generate more than eight query variants in one minute. Expect later variants
   to bypass admission while an already-resident object remains a HIT. Drive
   cache admissions above the Small profile ceiling and fill its quota/minimum
   free reserve. Expect bounded bypass/eviction, no unbounded temporary growth,
   and unrelated-domain traffic on the other cell to continue.
7. Seed an object, restart its cell container normally, and request it again.
   Expect HIT from the persistent per-cell volume. Replace only the disposable
   cache volume and expect a safe MISS/rebuild without desired-state loss.
   Confirm no other cell volume or cache object changed.
8. Stop the origin after a one-second TTL. Within the configured windows expect
   stale-while-revalidate or stale-if-error service and bounded origin attempts.
   After expiry expect failure, not indefinite stale. Set Cache only, then
   Stale only: resident content remains available and an origin-bound miss
   returns 503 with `cache_mode_origin_disabled`. Restore Normal and the origin.
9. Purge one URL and confirm durable per-edge tasks, acknowledgements, and a
   MISS only for that key. Full purge and confirm one epoch increment, no disk
   scan, and MISS across participating cells. Interrupt one delivery, retry the
   same task, restart the agent, and confirm convergence without duplicate
   epochs or loss of the previous valid artifact.
10. Record mixed HIT/MISS load throughput, p50/p95/p99 latency, hit ratio, CPU,
    memory, IOPS, disk used/free, temporary bytes, origin requests, purge
    fan-out time, high-cardinality bypasses, saturation point, and accepted
    limit. Run IPv4 and configured IPv6 traffic plus the documented IPv4-only
    topology. Confirm telemetry failure never stops cache service.

### Phase 6 completion gate

| Gate | Result | Evidence |
| --- | --- | --- |
| Implementation | Passed | Persistent per-cell volumes, four profiles, typed domain policy, deterministic keys, TTL/admission/object/range/variant bounds, stale modes, and durable purge |
| Unit and feature tests | Passed | 194 isolated Laravel tests / 11,472 assertions plus Pint |
| Real-runtime E2E | Passed | OpenResty runtime covers persistence, query normalization, status TTL, stale, bounds, purge, restart, invalid-candidate last-valid state, and isolation |
| IPv4 and IPv6 | Partially passed; owner run pending | Cumulative DNS dual-stack passed; owner external cache traffic and IPv4-only evidence required |
| Scale | Pending owner load run | Exact metrics and accepted saturation limit from step 10 |
| Failure, recovery, and isolation | Partially passed; owner run pending | Automated restart, origin failure, last-valid, purge retry, and cell isolation passed; owner disk-pressure evidence remains |
| Observability | Pending owner run | UI state, cell capacity, logs, metrics, alerts, and stable reason captures |
| Documentation | Passed | Cache guide, API/reference, operations, troubleshooting, architecture, and this exact checklist |
| Manual qualification | Pending owner run | Steps 1–10; coding agents do not run browser automation |
| Regression | Passed | Full cumulative non-browser E2E passes foundation, dual-stack DNS, Geo-DNS, two-edge control plane through revision 14 with zero obsolete artifacts, mTLS, TLS, security, analytics outage recovery, operations recovery, and OpenResty cache runtime |
| Release decision | Blocked | Owner browser, external load, and disk-pressure evidence remain mandatory |

## Phase 7 — Gzip and Brotli compression

Use a disposable proxied domain in a ready shared pool and a second domain in
a ready reserved or dedicated pool. Serve a text/JSON object larger than 1 KiB,
an image, a 12 MiB text object, a range-capable object, an ETag response, and a
one-second cacheable response that can be served stale. Record pool/domain
revisions, cell identity, cache status, encoding, byte counts, CPU, latency,
and operation IDs.

1. As administrator, open **Edge network → Service pools**, edit the shared
   pool, and confirm **Compression profile** offers Off, Standard, and Maximum
   savings with explanatory help. Select Maximum savings and expect field-level
   rejection with no revision, artifact, audit, or task. Save Standard and
   expect one pool revision plus one coalesced asynchronous reconciliation.
2. Edit the reserved/dedicated pool, select Maximum savings, and save. Expect
   `202`, an operation ID, revisioned artifacts only for participating cells,
   acknowledgement before success, and no new process, container, timer,
   server block, or cache directory.
3. Request the same eligible object with `Accept-Encoding: identity`, `gzip`,
   and `br`. Decode each and compare hashes. Expect identical content, Gzip for
   Standard, Brotli preference for Maximum savings, `Vary: Accept-Encoding`,
   and one MISS followed by HITs without extra cache objects.
4. Repeat with quality values, unsupported encodings, HEAD, conditional
   `If-None-Match`, and origin 304 revalidation. Expect correct identity
   fallback, no body for HEAD/304, stable validators, and no representation
   corruption.
5. Request the image, archive, sub-1-KiB response, 12 MiB response, and byte
   range. Expect identity; the range must remain 206 with correct
   `Content-Range`. Confirm ordinary cache, stale-if-error, exact purge, epoch
   purge, and origin-header behavior remains unchanged.
6. Drive more than 16 concurrent eligible responses on the maximum-savings
   cell and more than 32 on Standard. Expect excess work to receive identity
   with `cpu_pressure_identity`, while every response succeeds and unrelated
   domains/cells retain normal latency and encoding.
7. Set `EDGE_COMPRESSION_DISABLED=1` on one canary cell and replace only that
   cell. Expect identity plus `emergency_disabled` without a serving outage.
   Remove it and expect configured encoding to resume. Then save pool profile
   Off and verify the durable asynchronous fleet-wide path.
8. Open **Observe → Analytics and logs** as administrator and domain user.
   Query no more than 24 hours. Compare request captures with encoding,
   delivered bytes, identity estimate, bytes saved, ratio, profile, and
   fallback. Expect accurate totals and domain-user isolation. Stop ClickHouse
   briefly and confirm traffic continues while analytics reports unavailable.
9. Run mixed identity/Gzip/Brotli HIT/MISS load over IPv4 and configured IPv6,
   plus the documented IPv4-only topology. Record dataset, hardware,
   concurrency, throughput, p50/p95/p99 latency, bytes saved, CPU, memory,
   saturation point, fallback count, and accepted limit.
10. Restart the cell and inject an invalid runtime artifact. Expect the prior
    valid compression/cache policy to keep serving. Restore desired state,
    confirm convergence and telemetry, and run the cumulative non-browser
    regression.

### Phase 7 completion gate

| Gate | Result | Evidence |
| --- | --- | --- |
| Implementation | Passed | Pool policy, PostgreSQL constraints, revisioned artifacts, canonical identity cache, pinned Brotli image, bounded filters, pressure/emergency fallback, telemetry, authorization, audit, and last-valid delivery |
| Unit and feature tests | Passed | 201 isolated Laravel tests / 11,513 assertions, including policy/API/artifact/analytics and ACME JWK coordinate coverage, plus Pint |
| Real-runtime E2E | Passed | Identity/Gzip/Brotli content, canonical HIT, range, pressure/emergency fallback, restart, stale, purge, invalid candidate, and real Vector/ClickHouse analytics |
| IPv4 and IPv6 | Partially passed; owner run pending | Local IPv4/IPv6 listener and cumulative dual-stack DNS passed; owner external compression traffic and IPv4-only evidence required |
| Scale | Pending owner load run | Exact measurements from step 9 |
| Failure, recovery, and isolation | Partially passed; owner run pending | Automated pressure, emergency, restart, invalid-candidate, telemetry outage, and unrelated-cell checks passed; owner saturation remains |
| Observability | Partially passed; owner run pending | Real encoding/bytes/ratio/profile/fallback events and analytics passed; owner UI/alert capture remains |
| Documentation | Passed | Compression guide, cache/analytics/telemetry/upgrade references, and this exact checklist |
| Manual qualification | Pending owner run | Steps 1–10; coding agents do not run browser automation |
| Regression | Passed | Foundation, DNS, Geo-DNS, two-edge control plane through revision 14 with zero obsolete artifacts, mTLS, TLS, security, analytics outage recovery, operations recovery, and compression/cache runtime |
| Release decision | Blocked | Owner browser, external load, CPU saturation, and external IPv4/IPv6 evidence remain mandatory |

## Phase 8 — Primary and backup origin failover

Use one disposable proxied hostname with distinguishable primary and backup
responses, one cacheable one-second object, and one unrelated proxied hostname.
Record the domain/revision, pool/cell, origin request counters, transition
headers, operation IDs, and timestamps. The two origins must be independently
stoppable and must pass the normal public-destination safety policy.

1. As the domain owner, open the proxied record in **DNS records**. Enable
   **Backup origin** and enter backup hostname/IP, scheme, Host header, TLS SNI
   and verification, connect/response timeouts, failure threshold, recovery
   threshold, hold-down, and failback delay. Save. Expect one desired revision,
   one coalesced asynchronous deployment, and no new process, container,
   server block, cache directory, worker, or timer.
2. Enter loopback, link-local, metadata, platform-listener, proxy-loop, invalid
   TLS, identical-primary, and out-of-range policy values. Expect field-level
   rejection with no revision, artifact, audit success, or runtime change.
   Confirm an unassigned domain user cannot view or mutate either origin.
3. Choose **Test origin**, then **Test backup**. Expect separate asynchronous
   operation IDs and bounded results from selected ready edges. Confirm the
   result contains status/latency but does not disclose credentials or private
   keys.
4. Request uncached paths while both origins are healthy. Expect the primary
   marker and `X-CDNFoundry-Origin: primary`. Confirm analytics stores
   `origin_role=primary` and a bounded transition reason.
5. Stop the primary and send concurrent MISS traffic. Before the configured
   failure threshold expect bounded failures; afterward expect the backup
   marker, `X-CDNFoundry-Origin: backup`, and
   `primary_failure_threshold`. Record transition time, origin pressure,
   errors, CPU, memory, and p50/p95/p99 latency.
6. Keep the primary unavailable past the hold-down and verify traffic remains
   stable on backup. Disconnect Laravel/Redis from the cell network and repeat
   requests. Expect local failover to continue without a control-plane call.
7. Restore primary. Before failback delay expect backup. After the delay expect
   primary probes and only return to stable primary after the recovery
   threshold. Interrupt recovery once and confirm the success count resets
   rather than flapping.
8. Seed the one-second cache object, then stop both origins after it expires.
   Within stale-if-error expect `STALE`; after the stale window expect a bounded
   upstream or configured maintenance failure. Confirm attempts do not form a
   retry storm.
9. While both origins fail, load the unrelated hostname and a second cell.
   Expect normal origin connection budgets, latency, and service. Inject an
   invalid backup artifact and confirm the prior valid primary/backup state
   remains active.
10. Run controlled HIT/MISS failover and recovery load over external IPv4 and
    configured IPv6, plus the documented IPv4-only topology. Record hardware,
    concurrency, throughput, transition time, p50/p95/p99, primary/backup
    pressure, errors, stale responses, CPU, memory, saturation point, and the
    accepted operating limit.

### Phase 8 completion gate

| Gate | Result | Evidence |
| --- | --- | --- |
| Implementation | Passed | One validated backup, bounded policy, revisioned artifacts, local cell state, role-specific tests, stale precedence, diagnostics, and telemetry |
| Unit and feature tests | Passed | 205 isolated Laravel tests / 11,548 assertions cover authorization, validation, idempotency conflict, atomic preservation, IPv4/IPv6 artifact, telemetry presentation, live queue accounting, and safety envelopes; Pint passes 311 files |
| Real-runtime E2E | Passed | Primary, threshold failover, 24 concurrent backup requests, hold-down, delayed threshold recovery, dual failure stale, bounded error, and unrelated-host isolation |
| IPv4 and IPv6 | Partially passed; owner run pending | IPv4 and IPv6 backup state compiles; owner external traffic and IPv4-only topology evidence required |
| Scale | Pending owner load run | Exact measurements and accepted saturation limit from steps 5 and 10 |
| Failure, recovery, and isolation | Partially passed; owner run pending | Automated local transitions, stale, bounded failure, and unrelated-host isolation passed; owner control-plane partition and external saturation remain |
| Observability | Partially passed; owner run pending | Runtime headers, authenticated active-role status, and a real backup/primary-failure Vector-to-ClickHouse event passed; owner UI and alert captures remain |
| Documentation | Passed | Origin guide, telemetry, upgrade instructions, roadmap evidence, and this exact checklist |
| Manual qualification | Pending owner run | Steps 1–10; coding agents do not run browser automation |
| Regression | Passed | Full isolated suite, cumulative foundation/DNS/Geo-DNS/control-plane/mTLS/TLS/security/analytics/operations E2E, established and Phase 8 OpenResty runtime, Compose, OpenAPI, Vector, and docs checks |
| Release decision | Blocked | Owner browser, external load/saturation, control-plane partition, and external IPv4/IPv6 evidence remain mandatory |

## Phase 9 — Managed OWASP CRS WAF

Use disposable off, monitor, balanced, and strict domains, one WAF-capable
pool, and one non-WAF comparison pool. Record image digests,
ModSecurity/connector/CRS versions, pool and cell
IDs, revisions, operation IDs, corpus versions, and sanitized measurements.

1. As administrator, open **Edge network → Service pools**. On the candidate
   pool enable **Offer managed WAF protection** and confirm **Managed WAF
   release** is filled automatically. Expect no version, readiness, or canary
   field and one
   audited bounded global reconciliation. Existing domains must keep their
   individual WAF choices.
2. As the assigned domain user, open the domain and choose **Security → Managed
   Web application firewall (WAF)**. Exercise Off, Observe, Recommended, and
   High sensitivity. Expect one revision and asynchronous operation per effective
   change. Arbitrary configuration, `SecRule`, rule upload, wildcard, and
   expression inputs must not exist and API attempts must fail.
3. Run the approved benign and attack corpora against all four profiles. Expect
   Off to remain uninspected, Observe to report without blocking, and
   Recommended/High sensitivity to block according to their fixed thresholds.
   Run HIT/MISS load and the body corpus. A failed runtime update must preserve
   the previous active image and artifact.
4. Send benign traffic through all four profiles. Expect the same origin/cache
   result. Send approved XSS, SQL injection, traversal, and command-injection
   samples. Off serves; Monitor detects and serves; Balanced and Strict return
   HTTP 403 with only `waf_request_blocked`.
5. Send malformed JSON plus bodies just below and above 256 KiB and 1 MiB.
   Expect strict and balanced documented bounds and stable `waf_body_limit`,
   without unbounded buffering, raw body reflection, worker failure, or retry.
6. In **Managed WAF exclusions**, create one literal path and one rule/parameter
   exclusion with reason and expiry. Expect visible owner, expiry, audit rows,
   one revision each, and detection marked with numeric exclusion ID. Verify a
   wildcard, invalid rule ID, short reason, more than 30 days, and the 51st
   active exclusion are rejected. Expire/delete and expect enforcement to
   return.
7. Open domain request/security logs and administrator telemetry. Expect
   profile, numeric rule, score, action, processing time, body-limit outcome,
   and numeric exclusion ID. Confirm no request body, matched value, query
   secret, cookie value, raw ModSecurity message, or rule text is exposed.
8. Apply concurrent benign/attack HIT/MISS load. Record throughput, p50/p95/p99,
   detection and false-positive rates, CPU, RSS, temporary storage, and accepted
   limit for every profile. Keep one off-profile domain and non-WAF pool loaded;
   expect healthy latency and throughput throughout WAF pressure/failure.
9. Deploy an invalid/new candidate image or ruleset and mark its canary
   **Failed**. Expect the previous passed image/ruleset and active artifact to
   remain serving. Restart a WAF cell and disconnect control-plane/telemetry
   dependencies; expect last-valid local service and best-effort telemetry.
10. Repeat representative benign, attack, exclusion, and body-bound traffic
    over external IPv4 and configured IPv6, including the documented IPv4-only
    topology. Confirm TLS, cache, compression, origin failover, and unrelated
    domains remain healthy.

### Phase 9 completion gate

| Gate | Result | Evidence |
| --- | --- | --- |
| Implementation | Passed | Fixed profiles, WAF-aware placement, bounded owned exclusions, pinned immutable image, signed artifacts, stable reasons, and bounded telemetry |
| Unit and feature tests | Passed | 210 isolated Laravel tests / 11,595 assertions cover WAF policy/API/idempotency/authorization/audit/artifact/pool behavior; Pint passes 318 files |
| Real-runtime E2E | Passed | Pinned image builds and validates; off/monitor/balanced/strict, XSS/SQLi, malformed/oversized bodies, exclusion, 48 attack plus 48 healthy concurrent requests, stable reasons, privacy, and non-WAF isolation pass |
| IPv4 and IPv6 | Pending owner run | Step 10 external evidence |
| Scale | Pending owner load run | Exact measurements and accepted operating limits from step 8 |
| Failure, recovery, and isolation | Partially passed; owner run pending | Automated canary/last-valid policy, configuration rejection, WAF/non-WAF concurrency, and cumulative origin/runtime isolation pass; owner invalid-candidate and external saturation evidence remains |
| Observability | Partially passed; owner run pending | Privacy-safe runtime events and real Vector-to-ClickHouse analytics pipeline pass; owner UI/alert capture remains |
| Documentation | Passed | Managed WAF guide, API/telemetry/testing references, roadmap evidence, and this checklist |
| Manual qualification | Pending owner run | Steps 1–10; coding agents do not run browser automation |
| Regression | Passed | Full isolated suite, clean cumulative non-browser E2E, immutable image/config validation, Compose, OpenAPI, Vector/ClickHouse analytics, and docs checks pass |
| Release decision | Blocked | Owner browser, external load/saturation, invalid-image drill, and external IPv4/IPv6 evidence remain mandatory |

## Phase 10 — Observability and capacity control

1. As administrator open **Observe → Telemetry**, the dashboard, service pools,
   endpoints, and **Edge network → Edges**. Expect healthy, degraded, or
   unavailable state plus exact edge, pool, cell, endpoint, and revision.
2. As a domain user inspect analytics/logs for two assigned domains. Directly
   request an unassigned domain and confirm no aggregate, hostname, address,
   WAF, origin, pool, cell, or edge detail leaks.
3. Generate HIT/MISS, compression fallback, origin failover, WAF block/error,
   gateway rejection, endpoint mismatch, and cell pressure. Confirm bounded,
   redacted records and the corresponding Prometheus series.
4. Stop ClickHouse and Vector under traffic. Confirm DNS/HTTP/HTTPS serving and
   cache continue, bounded buffers do not exceed their limits, and recovery
   drains without serving decisions depending on telemetry.
5. Load at least 20,000 active proxied domains over several pools, endpoints,
   cells, and edges. Record query latency, scanned/result rows, memory, CPU,
   retention, alert time, recovery time, and accepted saturation.
6. Trigger stale-map, endpoint-mismatch, cache/memory/connection pressure,
   origin failover, WAF error, Anycast disagreement, and telemetry alerts.
   Follow every runbook link and confirm it identifies a bounded recovery.

### Phase 10 completion gate

| Gate | Result | Evidence |
| --- | --- | --- |
| Implementation | Passed | Bounded component state, revision/dimension metrics, capacity ratios, drift, alerts, scoped analytics, redaction, retention, and best-effort telemetry |
| Unit and feature tests | Passed | 215 isolated Laravel tests / 11,625 assertions cover scoped analytics, bounded queries, redaction, outage behavior, health states, metrics authorization, fleet behavior, and the simplified WAF operator workflow |
| Real-runtime E2E | Passed | Real Vector-to-ClickHouse analytics, privacy, usage, 20,000-domain bounded query, outage, buffer, and recovery qualification |
| IPv4 and IPv6 | Pending owner run | External traffic evidence in steps 4–5 |
| Scale | Pending owner run | Exact 20,000-domain query and outage measurements |
| Failure, recovery, and isolation | Pending owner run | Steps 3–6 |
| Observability | Pending owner run | Steps 1–6 |
| Documentation | Passed | Monitoring, telemetry schema, rollout operations, runbooks, roadmap, and this checklist |
| Manual qualification | Pending owner run | Steps 1–6 |
| Regression | Passed | Full isolated suite, Go agent suite, OpenAPI, Compose, docs, and real telemetry qualification |
| Release decision | Blocked | Owner browser, external load, alert, and outage evidence remain mandatory |

## Phase 11 — Bounded fleet rollout automation

Use at least two POPs, a canary edge, two later waves, mixed normal/WAF cells,
one healthy comparison edge, and two compatible immutable releases.

1. Create a release through the administrator fleet API and inspect the edge
   version fields in **Edge network → Edges**. Expect four digest-pinned images
   and one compatibility range. Tags and missing components must fail.
2. Create a rollout with explicit canary/later edges, wave size, parallelism,
   readiness/error thresholds, and mixed-window bound. Expect canary wave 1 and
   no later dispatch before canary success.
3. Observe current/desired versions, wave, progress, drift, and audits. Confirm
   the fixed slot count and assignments never change and the agent has no
   command input or container-engine socket.
4. Complete the canary, then one later wave. Keep HTTP/HTTPS/cache/WAF traffic
   running through upgraded and previous-version edges and record continuity.
5. Fail a canary installer/readiness check. Expect automatic pause, stable
   reason, no later task, previous runtime serving, and an actionable alert.
6. Start rollback to the recorded compatible release. Expect bounded waves,
   current digests to converge, desired drift to clear, and previous
   configuration/traffic to remain valid.
7. Restart the control plane, queue, one edge agent, gateway, normal cell, and
   WAF cell during controlled runs. Expect durable rollout state, idempotent
   task replay, and no duplicate active upgrade.

### Phase 11 completion gate

| Gate | Result | Evidence |
| --- | --- | --- |
| Implementation | Passed | Immutable releases, compatibility, explicit canary/waves, bounded parallelism, pause, rollback, drift, task intent, and full audit |
| Unit and feature tests | Passed focused | Authorization, digest/range validation, canary ordering, parallel bound, and unready pause |
| Real-runtime E2E | Pending owner installer run | Fixed-purpose privileged installer and multi-POP traffic steps 4–7 |
| IPv4 and IPv6 | Pending owner run | Mixed-window traffic over both families |
| Scale | Pending owner run | Multi-edge/multi-POP measurements and saturation |
| Failure, recovery, and isolation | Pending owner run | Failed-canary, restart, rollback, and comparison-edge evidence |
| Observability | Pending owner run | Version/wave/drift/audit/alert capture |
| Documentation | Passed | Fleet operations, runbooks, API/OpenAPI, roadmap, and exact checklist |
| Manual qualification | Pending owner run | Steps 1–7 |
| Regression | Passed | 215 isolated Laravel tests / 11,625 assertions, Go agent suite, OpenAPI, Compose, docs, and Phase 10 real telemetry qualification |
| Release decision | Blocked | Owner browser, installer, mixed-traffic, failure, and rollback evidence remain mandatory |

## Phase 12 — Final production qualification

Use the same commit for the automated report and this owner run. Use at least
two POPs/edges, exactly eight installed slots per edge, a three-cell-per-edge
shared pool, separate dual-stack reserved and quarantine endpoints,
Geo-Unicast, approved Simple Anycast routing, persistent cache, both
compression algorithms, two origins, managed WAF, and one healthy unrelated
comparison route.

1. Follow the clean installation procedure on a disposable edge in each POP.
   Register both through **Edge network → Edges**, inspect all eight cells on
   each edge, and confirm management addresses are not service endpoints.
2. Open the shared, reserved, dedicated, and quarantine pools and their
   endpoints. Confirm distinct service pairs, pool kind, endpoint mode,
   participating cells, readiness, current revision, and no artifacts on
   non-participating cells.
3. Send real HTTP and HTTPS traffic over IPv4 and IPv6 to every service pair.
   Confirm Host/SNI routing, certificates, origin marker, trusted client
   identity, and rejection of unknown address, Host, and SNI values.
4. Move a domain target-first, fail target readiness, retry, drain its source,
   quarantine it, and roll it back. Confirm uninterrupted comparison traffic
   and no source removal before the target is active.
5. From independent networks check Geo-Unicast selection. In the approved
   routing environment announce the Simple Anycast pair from both POPs, fail
   and withdraw one POP, restore it, and record convergence, traffic ownership,
   health disagreement, and route-policy evidence.
6. Prime cache, restart the serving cell, verify persisted HITs, perform URL
   purge and epoch-based full purge, serve stale during bounded origin failure,
   fill to the storage pressure threshold, and confirm admission/recovery
   bounds. Verify Gzip, Brotli, identity fallback, `Vary`, and no double
   compression.
7. Fail the primary origin, verify bounded backup failover, restore it, and
   verify controlled failback without unsafe retries or request-body replay.
8. Exercise managed WAF off, monitor, balanced, and strict profiles; one
   expiry-bound owned exclusion; block and monitor telemetry; an invalid
   candidate; and rollback. Confirm no sensitive body or exclusion value leaks.
9. Keep DNS, HTTP, HTTPS, cache, and comparison traffic running while stopping
   the control plane and then Vector/ClickHouse. Confirm serving continuity,
   bounded buffering, visible degradation, and recovery.
10. Submit invalid gateway, normal-cell, and WAF-cell candidates. Confirm each
    stable reason, unchanged active checksum/revision, last-valid traffic, and
    successful later reconciliation.
11. Saturate one cell to its documented CPU, memory, connection, and temporary
    storage bounds. Confirm unrelated cells and pools continue and record the
    first saturation point and recovery time.
12. Perform a fleet canary upgrade through the fixed-purpose installer. Fail
    its readiness gate, confirm pause and no later wave, then roll back and
    confirm compatible digests, fixed slots, continuous traffic, and audit.
13. On a clean replacement control host restore the encrypted backup with the
    complete recovery secret set. Recreate queues, reconcile DNS, edge, TLS,
    purge, and usage derived state, then repeat DNS and edge traffic checks.
14. Inspect the administrator dashboard, **Observe → Telemetry**, pools,
    endpoints, edges/cells, operations, audits, analytics, alerts, and linked
    runbooks at desktop and narrow widths. As assigned, unassigned, disabled,
    and administrator users, confirm policy scope and stable errors.
15. Run `make dev-production-qualification` with paths to all five sanitized
    owner evidence files. Link the resulting JSON report and logs. Confirm its
    release decision is `passed`; any `failed` or `not_run` result blocks the
    release.

### Phase 12 completion gate

| Gate | Result | Evidence |
| --- | --- | --- |
| Implementation | Passed | One bounded report joins contracts, application/Go suites, real runtime, scale, recovery, upgrade, and explicit owner evidence without silently skipping checks |
| Unit and feature tests | Passed | 216 isolated Laravel tests / 11,634 assertions; application result passed |
| Real-runtime E2E | Passed locally | Gateway, cells, uninterrupted cumulative runtime, recovery, upgrade, throughput, and GeoIP provider checks passed |
| IPv4 and IPv6 | Pending owner run | Steps 3, 5, and 15 |
| Scale | Local bound passed; pending owner run | 500,000 zones, 1,000,000 records, and 50,000 changes passed; Steps 11 and 15 still require external hardware and accepted saturation |
| Failure, recovery, and isolation | Local checks passed; pending owner run | Last-valid runtime, origin failover, clean-host recovery, upgrade rollback, and MMDB outage passed; Steps 4 and 6–13 still require owner evidence |
| Observability | Pending owner run | Steps 5 and 8–14 |
| Documentation | Passed | Production qualification, testing, operations index, roadmap evidence, and this exact checklist |
| Manual qualification | Pending owner run | Steps 1–15; coding agents do not run browser automation |
| Regression | Passed | Contracts, application, Go runtime, gateway, cells, scale, recovery, upgrade, throughput, MMDB, and cumulative runtime results |
| Release decision | Blocked | Owner public dual-stack, Anycast, external load, fleet installer, and browser evidence are mandatory |

## Failure record

For every failed or blocked checkpoint, record:

| Field | Value |
| --- | --- |
| Checkpoint | |
| Expected result | |
| Actual result | |
| Sanitized evidence | |
| Operation/task/revision IDs | |
| Severity and traffic impact | |
| Owner | |
| Fix or approved scope decision | |
| Retest date, commit, and result | |

Do not mark the release qualified until every current failure passes retest or
an explicit product-contract change removes the requirement.
