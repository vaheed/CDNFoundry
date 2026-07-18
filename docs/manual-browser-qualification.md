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
| DNSdist | UDP/TCP `127.0.0.1:1053` | Use `dig` |

For every phase, check desktop and narrow mobile widths, browser-console errors, authorization, validation messages, audit events, operation visibility, retry/failure behavior, and persistence after refresh/sign-out/sign-in.

## Phase 1 — Foundation, access, and control-plane shell

### Administrator checkpoints

1. Sign in at `/admin`. Confirm styled navigation, `Local Administrator`, and no asset errors.
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
2. Confirm administrator navigation is absent.
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

Enter the platform domain first and blur the field. Confirm proxy, nameserver, and SOA names auto-fill but remain editable. Choose **Validate and queue update**, copy the operation ID, and confirm `platform_dns_identity.update` succeeds. Reject loopback/malformed glue, fewer than two nameservers, and an empty target list.

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

Confirm invalid RDATA is rejected according to its type. MX priority and SRV priority/weight/port remain fixed while geography selects their target. NS remains administrator-only and PTR remains reverse-zone-only. CAA must offer DNS-only mode but not Geo-DNS: the qualified PowerDNS Lua runtime returns NODATA for synthesized CAA, so exposing it would be a false feature.

Use preview with IPv4/IPv6 addresses whose classification is known in the installed MMDB and record the displayed country/continent. Query DNSdist with and without trusted ECS and confirm the answer matches that classification. Force a bad MMDB update and invalid deployment; the prior MMDB and last valid answers must continue serving while failure is visible.

### Phase 3 completion gate

- Implementation: present for every PowerDNS-Lua-compatible DNS record type with normal NS/PTR policy restrictions; CAA is explicitly DNS-only.
- Documentation: present above.
- Automated/runtime qualification: agent-owned API/compiler and real DNS tests must pass and be recorded.
- Manual browser/geographic-vantage qualification: owner-run; **failed/not complete until every Phase 3 checkpoint and required vantage-point query passes**.

## Phase 4 — Edge proxy and origin routing

### Edges and pools (administrator)

1. Open **Edge network → Edges** and create two entries. Replace documentation addresses with reachable addresses for real traffic:

   | Field | Edge A | Edge B |
   |---|---|---|
   | Name | `edge-browser-a` | `edge-browser-b` |
   | Country / continent | `IR` / `AS` | `DE` / `EU` |
   | IPv4 | `192.0.2.101` | `192.0.2.102` |
   | IPv6 | `2001:db8::101` | `2001:db8::102` |

2. Save each one-time bootstrap token, register the agents, and confirm fresh heartbeat, version, active revision, state, and bounded cell capacities.
3. Exercise drain/undrain and enable/disable. Rotate an identity, copy the replacement token once, and confirm the previous credential no longer works.
4. Open **Service pools**. Confirm shared and quarantine pools and their revisions. Create one dedicated pool and confirm one desired cell per registered edge.

### Proxy defaults and record eligibility

Open the domain proxy settings. Start with these compatibility-oriented defaults:

| Field | Value |
|---|---|
| Proxy enabled | On |
| Redirect HTTP to HTTPS | Off initially |
| HTTP versions | HTTP/1.1 and HTTP/2 |
| Default origin retry count | `1` |
| Maintenance mode | Off |

In **DNS records**, select every type. The **Proxied** mode must appear only for A, AAAA, and CNAME. **Geo-DNS** must appear only for A/AAAA. TXT, MX, NS, CAA, SRV, and PTR must be DNS-only; changing a proxied row to one of these types must reset mode to DNS-only and hide origin fields.

### Proxied apex form

Create or edit the apex:

| Field | Value / expected default |
|---|---|
| Type / name / mode | `A` / `@` / `Proxied` |
| Origin server hostname or IP | A public cPanel/shared-hosting origin, e.g. `server1.example.net` |
| Scheme / port | `HTTPS` / `443` |
| Origin Host header | Auto-fills to the domain name |
| TLS SNI | Auto-fills to the domain name |
| Verify origin TLS | On |
| Connect / response timeout | `2000` ms / `30000` ms |
| Retry count | `1` |
| WebSocket | Off |
| Health check | Off; when on, path `/`, interval `300` seconds |
| TTL | `300` |

The origin destination is the server reached by CDNFoundry; Host/SNI default to the public record hostname so name-based cPanel virtual hosting and certificates work. The destination must not point back to CDNFoundry, a platform hostname, loopback, link-local, private metadata, multicast, or an edge address.

### Proxied subdomain and editable automatic values

1. Create `www` as A, AAAA, or CNAME in Proxied mode with destination `server2.example.net`.
2. Confirm Host and SNI automatically become `www.<domain>`.
3. Change name to `shop`; untouched automatic fields must become `shop.<domain>`.
4. Manually change Host to `backend-vhost.example.net` and SNI to `backend-cert.example.net`; change the record name again and confirm both overrides are preserved.
5. Switch scheme HTTPS → HTTP and confirm conventional port `443` changes to `80`; switch back and confirm `80` changes to `443`. A deliberately custom port must remain editable.
6. With HTTPS verification on, blank SNI must be rejected. Origin Host must always be present for a proxied record.
7. Run **Test origin** and confirm status/latency or a bounded validation/connection error. Saving/testing remains asynchronous.

### Deployment and operation visibility

1. Save both proxied records and choose **Deploy proxy configuration**.
2. Open **Administration → Operations**. Confirm new `edge.domain_deploy` and `edge.origin_test` rows appear without refreshing manually, with requester, attempt, status, duration, and bounded error.
3. Retry a supported failure. It must not duplicate an already-active deployment.
4. Confirm the domain view exposes proxied-host count, desired/active revision, placement/pools, failure, and recent validated revisions.
5. Send HTTP and HTTPS through both real edges. Confirm correct origin selection, Host, SNI, IPv4/IPv6 behavior, unknown-host/SNI rejection, and continued serving of the last valid revision after a deliberately invalid candidate.

Current Phase 4 limitations that must remain recorded as **not passed** until implemented: service-pool-specific public addresses/DNS selection are required before quarantine/dedicated moves affect traffic, and an edge-local cell supervisor is required before drain/restart tasks can execute (current behavior fails closed with `cell_supervisor_unavailable`). The manual dual-edge traffic/migration checks remain owner-run.

### Phase 4 completion gate

- Implementation: partially complete; the two runtime limitations above are open.
- Documentation: present above and in the edge/origin runbooks.
- Automated/runtime qualification: agent-owned tests must pass and be recorded, but cannot waive the open runtime limitations.
- Manual browser/dual-edge qualification: owner-run; **failed/not complete until all Phase 4 checkpoints pass and both limitations are implemented**.

## Record the result

For each phase record: date/operator, commit SHA, browser/version, desktop/mobile viewports, exact domain and edge addresses, every checkpoint as pass/fail/not-ready, operation IDs, revisions, screenshots, relevant logs, and any deviations from the example values. Also record Horizon, PowerAdmin, DNSdist UDP/TCP, Prometheus, Alertmanager, and edge results where applicable.

Any broken flow, missing operation, unexpected access, asset error, unexplained pending state, last-valid-state regression, or runtime mismatch fails that checkpoint. Automated API/runtime tests support this job but never replace rendered UI and real-traffic qualification.
