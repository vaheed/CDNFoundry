# Manual browser qualification

This is the release-acceptance walkthrough for every browser surface currently implemented. Browser qualification is intentionally performed by the project owner. Coding agents do not run browser automation.

Use only disposable development domains and the development credentials below. Never reuse these passwords in production.

## 1. Start and prepare the development stack

From the repository root:

```sh
make dev-up
make dev-migrate
make dev-pdns-migrate
docker compose -f compose.dev.yml ps
```

Wait until `core`, `web`, `control-db`, `pdns-db`, `redis`, and `clickhouse` are healthy. `make dev-up` also starts the development-only PowerAdmin profile.

Named development volumes persist across roadmap phases. Normal `make dev-up`, container rebuilds, and restarts do not delete PostgreSQL data. Never use `docker compose down -v`, `docker volume rm`, `migrate:fresh`, or direct `php artisan test` inside the running development container. Run Laravel tests only with `make dev-test`; it forces isolated in-memory SQLite and the test harness refuses any other database.

Create or reset two disposable application accounts. These examples are local-development credentials only:

```sh
docker compose -f compose.dev.yml exec -T core php artisan tinker --execute="App\\Models\\User::query()->updateOrCreate(['email'=>'admin@example.test'], ['name'=>'Local Administrator','password'=>Illuminate\\Support\\Facades\\Hash::make('cdnfoundry-admin-test'),'type'=>'admin','disabled_at'=>null]); App\\Models\\User::query()->updateOrCreate(['email'=>'user@example.test'], ['name'=>'Local Domain User','password'=>Illuminate\\Support\\Facades\\Hash::make('cdnfoundry-user-test'),'type'=>'user','disabled_at'=>null]);"
```

Application logins:

| Role | URL | Username | Password |
|---|---|---|---|
| Administrator | `http://localhost:8080/admin` | `admin@example.test` | `cdnfoundry-admin-test` |
| Domain user | `http://localhost:8080/app` | `user@example.test` | `cdnfoundry-user-test` |

## 2. Browser and diagnostic addresses

| Surface | Address | Authentication | What to verify |
|---|---|---|---|
| Administrator panel | `http://localhost:8080/admin` | Application administrator above | Control-plane administration |
| Domain-user panel | `http://localhost:8080/app` | Domain user above | Assigned-domain workflow |
| Horizon | `http://localhost:8080/horizon` | Sign in to `/admin` first; it uses the same administrator session | Queue workers, workload, failures, and metrics |
| PowerAdmin | `http://localhost:9191` | Username `admin`, password `poweradmin-dev-only` | Diagnostic view of derived PowerDNS state |
| Prometheus | `http://localhost:9090` | None in development | Targets and metrics queries |
| Alertmanager | `http://localhost:9093` | None in development | Alert routing/status |
| Edge A | `http://localhost:8081/healthz` | None | Returns `ok` |
| Edge B | `http://localhost:8082/healthz` | None | Returns `ok` |
| DNSdist | TCP and UDP `localhost:1053` | DNS client such as `dig` | The only public authoritative DNS endpoint |

PowerAdmin is diagnostic only. Never use it to configure desired state. Any direct edit changes disposable derived state and can be overwritten by reconciliation. PowerDNS API, PostgreSQL, Valkey, ClickHouse, and origins intentionally have no host port; inspect them through Compose commands, not public browser ports.

## Phase 4 proxy and edge job

This job is manual and was not run by coding agents. In the domain DNS relation, create proxied A hostnames for the apex and a subdomain with different origins. Confirm the content field is replaced by explicit scheme, destination, port, Host header, SNI, TLS verification, timeouts, retries, and WebSocket fields. Confirm Geo-DNS and proxy cannot both be selected and blocked/private destinations show validation errors.

The non-browser runtime qualification in `tests/e2e/phase4_runtime.py` covers IPv4 and IPv6 clients, HTTP routing, the configured origin Host header, verified HTTPS with correct SNI, rejection of invalid origin SNI and blocked destinations, ambiguous-framing rejection, trusted forwarding-header replacement, unknown/disabled/deprovisioned host responses, 2,000 generic hostnames, malformed-state preservation, hot configuration activation without an OpenResty reload, disjoint shared/quarantine cell state, and continued shared traffic after the quarantine cell stops. The Go agent tests cover fresh full-snapshot recovery followed by incremental activation, placement-aware per-pool publication, and bounded origin-test execution. These checks do not replace the dual-edge, inbound TLS-SNI, mTLS-identity, or rendered-panel checks below.

OpenResty also records only failed upstream attempts in bounded cell-local shared memory. The edge agent reads at most 100 summaries through a token-protected, non-published status listener and reports them with its heartbeat; failure of this reporting path never blocks request serving.

As administrator, create two dual-stack edges, save each one-time bootstrap token, register them, and confirm fresh listener/cell capacity heartbeats. Send real HTTP and HTTPS traffic through each edge and verify the configured origin Host and SNI. Drain one edge and confirm routing state changes without recompiling every domain. Submit a deliberately bad-checksum candidate and confirm the rejection is visible while the previous configuration continues serving. Rotate one identity and confirm its old credential cannot heartbeat or fetch configuration.

Open **Administration → Operations** after changing a proxied hostname, requesting deployment, and starting an origin test. Confirm **Edge domain deployment** and **Edge origin test** rows appear, status updates on the 10-second poll, filters include both types, failures show a bounded reason, and retry creates work without duplicating an active deployment.

To choose different PowerAdmin development credentials before startup:

```sh
POWERADMIN_ADMIN_USERNAME=operator POWERADMIN_ADMIN_PASSWORD='choose-a-local-password' make dev-up
```

## 3. Administrator panel: every menu item

Sign in at `/admin`, then work through the navigation in this order.

### Dashboard

1. Confirm the page loads with normal styling and no oversized icons or unstyled HTML.
2. Confirm the account widget shows `Local Administrator`.
3. Check desktop width and a narrow mobile width.

Expected: administrator navigation is visible and there are no browser-console asset 404 errors.

### System DNS identity

Open **System DNS identity** and use this disposable example:

| Field | Example | Meaning |
|---|---|---|
| Platform domain | `cdnf.test` | Base identity owned by the platform |
| Proxy hostname | `proxy.cdnf.test` | Shared proxy/service hostname |
| Nameserver 1 hostname | `ns1.cdnf.test` | First authoritative nameserver |
| Nameserver 1 IPv4 | `192.0.2.10` | Documentation-only public IPv4 glue example |
| Nameserver 1 IPv6 | `2001:db8::10` | Documentation-only public IPv6 glue example |
| Nameserver 2 hostname | `ns2.cdnf.test` | Second authoritative nameserver |
| Nameserver 2 IPv4 | `192.0.2.11` | Documentation-only public IPv4 glue example |
| Nameserver 2 IPv6 | `2001:db8::11` | Documentation-only public IPv6 glue example |
| SOA primary | `ns1.cdnf.test` | Primary name in the SOA record |
| SOA mailbox | `hostmaster.cdnf.test` | Responsible mailbox, with the first dot representing `@` in DNS wire format |
| SOA refresh | `3600` | Secondary refresh interval, seconds |
| SOA retry | `600` | Retry interval after failure, seconds |
| SOA expire | `1209600` | Secondary expiry, seconds |
| SOA minimum TTL | `300` | Negative-cache TTL, seconds |
| Default TTL | `300` | Default record TTL, seconds |
| Cluster targets | `pdns-auth:8081` | Private PowerDNS API target inside Compose |

Enter `cdnf.test` first and leave the field. Confirm proxy hostname, two nameserver hostnames, SOA primary, and SOA mailbox fill automatically. IPv4 and IPv6 glue must still be supplied. Click **Validate and queue update**.

Expected: a success notification contains an operation ID. Open **Operations** and wait for `platform_dns_identity.update` to become `succeeded`. With a healthy enabled DNS cluster, PowerDNS must contain a `cdnf.test` platform zone with SOA, apex NS, and A/AAAA records for both nameserver hostnames. Updating System DNS Identity increments its revision and atomically replaces that derived zone; a failed deployment retains the previous active records.

Negative checks: `127.0.0.1` is not valid glue for this example, malformed IPv6 is rejected, fewer than two nameservers is rejected, and an empty cluster-target list is rejected.

### DNS clusters

Open **DNS clusters**, choose **New DNS cluster**, and enter:

| Field | Example | Meaning |
|---|---|---|
| Name | `local-pdns` | Unique operator-facing cluster name |
| Location | `local-compose` | Deployment location label |
| Enabled | Off while creating | Activation is blocked until health succeeds |
| API URL | `http://pdns-auth:8081` | Private PowerDNS API URL inside Compose |
| API key | `pdns-dev-api-key` | Development key from `docker/pdns/pdns.conf` |
| Server ID | `localhost` | PowerDNS API server identifier |
| Nameservers | `ns1.cdnf.test`, `ns2.cdnf.test` | Defaults from System DNS identity |
| Capacity zones | `100000` | Explicit cluster zone bound |
| Operational notes | `Local Compose qualification cluster` | Optional operator note |

Save the cluster. It must be saved disabled and automatically queue `dns.cluster_test`. Open **Operations** or refresh the cluster list until health becomes `healthy`. Edit the cluster and enable it only after health succeeds.

Negative checks: a wrong API key or URL produces `unhealthy` with a useful error; an untested/unhealthy cluster cannot be enabled; the saved API key is never displayed again; fewer than two nameservers is rejected.

### Users

Open **Users** and inspect the two prepared accounts. Then create another disposable domain user:

| Field | Example |
|---|---|
| Name | `Browser Tester` |
| Email | `browser.user@example.test` |
| Type | `Domain user` |
| Password | `browser-user-test1` |

Edit that user, choose **Disable access**, and verify login at `/app` is rejected. Re-enable access and verify login succeeds. Confirm a password shorter than 12 characters is rejected. Do not demote or delete the administrator currently in use.

### Domains

Open **Domains**, choose **New domain**, and enter:

| Field | Example |
|---|---|
| Domain | A real disposable delegated domain such as `dns-test.example.net` |

For UI-only checks, a reserved name such as `browser-test.example.test` is acceptable, but public nameserver verification cannot succeed for it.

Open the domain. In **Users**, attach `user@example.test`. Confirm the user appears in the assignment table and can later be detached.

Click **Verify nameservers**. A queued-operation notification must appear. The domain view must show the latest verification status and error. For a real acceptance pass, configure the registrar with exactly the System DNS Identity NS set, wait for DNS propagation, then retry until verification succeeds. Verification intentionally queries public DNS and does not accept local `/etc/hosts`, PowerAdmin-only records, or an extra registrar nameserver.

For local UI qualification without a public resolver, sign in as an administrator and choose **Force verify (local test)**. Confirm the warning explicitly says public delegation is not checked. The action records the administrator identity and audit event, then unlocks activation. This bypass qualifies only the local browser workflow; it does not satisfy the real nameserver-verification release checkbox.

After successful verification, confirm at least one DNS cluster is both `healthy` and enabled, then click **Activate**. Activation must be rejected if no healthy cluster is enabled. Expected: lifecycle becomes active, the desired revision increases, and deployment reaches `succeeded` on every enabled cluster; PowerDNS contains the generated SOA and platform NS records even when the user has not created records yet.

### DNS records inside a domain

On the domain view, use **DNS records**. Create these examples one at a time, adapting the domain name where required:

| Type | Name | Content | Extra fields |
|---|---|---|---|
| A | `@` | `192.0.2.20` | TTL `300` |
| AAAA | `@` | `2001:db8::20` | TTL `300` |
| CNAME | `www` | `@` | TTL `300`; `@` targets the zone apex |
| MX | `@` | `mail.example.net` | Priority `10` |
| TXT | `@` | `v=spf1 -all` | TTL `300` |
| NS | `delegated` | `ns1.example.net` | TTL `300`; administrator-only |
| CAA | `@` | `0 issue letsencrypt.org` | TTL `300`; quotes are optional and normalized |
| SRV | `_sip._tcp` | `sip.example.net` | Priority `10`, weight `5`, port `5060` |
| PTR | `20` | `host.example.net` | Available only in an appropriate managed reverse zone |

Edit an A record and change its TTL to `600`, then delete it. Select multiple disposable records and use bulk delete. Confirm duplicate records and a CNAME coexisting with other data at the same owner are rejected without partial changes.

Use **Import zone** with a small disposable BIND zone, first in append mode and then with **Replace existing records**. Use **Export zone** and verify the result can be imported again. Never replace records on a production zone during qualification.

### Operations

Open **Operations**. The newest request must be at the top and the list must refresh every 10 seconds. Check the friendly type, underlying machine type, status, requester, attempts, error, requested time, and duration. Use **Columns** to expose started and finished timestamps when investigating timing.

Exercise the filters independently and together:

- Status: pending, running, succeeded, or failed
- Type: platform identity, nameserver verification, zone reconciliation/import, cluster test, or global reconciliation
- Requested by: select an administrator or domain user

Search by the full operation ID, machine type, requester email, and a distinctive part of an error. Clear all filters and confirm the newest operation returns to the top. Check at least these operation types:

- `platform_dns_identity.update`
- `dns.cluster_test`
- `domain.nameservers_verify`
- `dns.zone_reconcile`

Expected: long-running work is visible, failures retain their error, and every supported failed operation shows a guarded retry action. Retrying returns the operation to pending and dispatches the correct queue job. Operations should not stay pending while Horizon shows healthy workers.

### Audit logs

Open **Audit logs**. Confirm the earlier identity update, cluster creation/test, user mutation, domain assignment, verification request, and DNS-record changes appear with actor, action, subject, IP address, and time. Audit logs are read-only.

### API tokens

Open **API tokens**, enter token name `manual-browser`, and create it. Confirm the Create button has clear spacing from the name field. Copy the token immediately; it must be displayed only once. Refresh the page and confirm only token metadata, including the token's final six characters, remains. Revoke it and verify an API request using that token returns unauthenticated.

### Profile

Open the account menu and select **Profile**. Change the display name to `Local Administrator Updated`. Optionally change the password to another disposable value of at least 12 characters. Expected: profile changes are audited; changing the password revokes other API tokens.

## 4. Domain-user panel: every menu item

Sign out of `/admin`, then sign in at `/app` as `user@example.test` / `cdnfoundry-user-test`.

### Dashboard

Confirm the onboarding text is visible and no administrator navigation appears.

### Domains

Confirm only assigned domains are listed. Open the assigned domain and verify DNS records and lifecycle state are visible. Directly opening an unassigned domain ID must return not found. The domain user must not see the administrator-only user-assignment relation.

### API tokens and Profile

Repeat the token one-time-display/revocation check and profile update using the domain-user account. Confirm these actions affect only the signed-in user.

Directly open `/admin/users`, `/admin/dns-clusters`, `/admin/audit-logs`, and `/horizon`. Expected: the domain user is forbidden from all four.

### Geo-DNS record workflow

1. As the assigned domain user, create an A record in Geo-DNS mode with a default set, an `EU` override, and an `IR` override.
2. Confirm duplicate targets/codes, invalid codes, excessive rows, and an IPv6 target in an A record are rejected without changing the revision.
3. Edit the record and confirm the mode and configuration persist.
4. Call the authenticated preview endpoint for an MMDB-known address and `2001:db8::1`; confirm country wins over continent and unknown returns default.
5. Query DNSdist with trusted ECS from at least three known locations and without ECS. Confirm answers match and record the resolver-location limitation.
6. Force an invalid MMDB update and failed Geo-DNS deployment. Confirm the previous MMDB and active answer remain valid while failure is visible.

Browser automation remains prohibited for coding agents; this workflow is manual and user-owned.

## 5. Runtime and diagnostic verification

### Horizon

Sign back in as the administrator, then open `http://localhost:8080/horizon`.

1. Confirm Horizon status is active.
2. Confirm supervisors/workers exist for the configured queue lanes.
3. Trigger a cluster test and nameserver verification from the UI.
4. Confirm jobs appear and finish; inspect failures without exposing API keys.

If jobs remain pending, inspect:

```sh
docker compose -f compose.dev.yml ps horizon
docker compose -f compose.dev.yml logs --tail=200 horizon
```

### PowerAdmin

Open `http://localhost:9191` and sign in with `admin` / `poweradmin-dev-only`. After an active domain reconciles successfully, confirm its zone and records appear. Do not edit them. Compare PowerAdmin with CDNFoundry desired state and treat a mismatch as drift or failed reconciliation.

### Authoritative DNS through DNSdist

Query DNSdist, never the private PowerDNS container directly:

```sh
dig @127.0.0.1 -p 1053 dns-test.example.net SOA +tcp
dig @127.0.0.1 -p 1053 dns-test.example.net A
dig @127.0.0.1 -p 1053 dns-test.example.net AAAA
dig @127.0.0.1 -p 1053 dns-test.example.net TXT
```

Expected: UDP and TCP answers match the active revision shown in CDNFoundry. Repeat using a host with IPv6 connectivity where available.

### Prometheus and Alertmanager

Open Prometheus `/targets` at `http://localhost:9090/targets`; configured targets should be up. Run a basic `up` query. Open `http://localhost:9093` and confirm Alertmanager loads and displays its current alert state.

### Edge cells

Open both `/healthz` URLs and confirm `ok`. Then run:

```sh
curl -i http://localhost:8081/healthz
curl -i http://localhost:8082/healthz
```

These endpoints qualify cell availability only; later roadmap phases add full proxied-domain browser behavior.

## 6. Record the qualification result

Record all of the following in the release notes or acceptance ticket:

- Date and operator
- Commit SHA (`git rev-parse HEAD`)
- Browser name and exact version
- Desktop and mobile viewport results
- Administrator-panel result
- Domain-user-panel result
- Horizon, PowerAdmin, Prometheus, Alertmanager, DNSdist, and edge results
- Real delegated test domain used
- Failed steps, operation IDs, screenshots, and relevant container logs

Any broken flow, unexpected access, missing state, asset failure, unexplained pending operation, or runtime mismatch is a failed qualification. Do not mark browser qualification complete until every applicable step above passes.
