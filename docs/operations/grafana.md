---
title: Grafana dashboards
description: The role-based Grafana folders, dashboards, teams, alerts, reports, and seed data, and how to run and test them.
---

# Grafana dashboards

::: warning Grafana is an operator surface
Keep port `3000` private. Publish Grafana only through a deployment-owned
authenticated HTTPS proxy or trusted tunnel, and keep the read-only data-source
roles. Grafana never changes desired state and never takes part in DNS, HTTP,
security, or telemetry ingestion.
:::

Grafana gives each audience its own folder, its own team, and its own home
dashboard. Everything is provisioned from the repository: dashboards, library
panels, data sources, alert rules, folders, teams, and permissions. Edit the
files, not the screens. A dashboard changed in the browser is replaced at the
next provisioning pass.

New to Grafana here? Read [Grafana by team](grafana-by-team.md) for where each team starts, and the [dashboards reference](../reference/grafana-dashboards.md) for every panel.

## Audiences

| Folder | Team that sees it | Dashboards |
| --- | --- | --- |
| `00 Overview` | everyone | **Now**, the home dashboard |
| `10 NOC` | `noc` | NOC wall, Global traffic, PoP fleet, PoP detail, Origin health, Cache, Edge latency, DNS authoritative, Synthetic probes, Config & purge propagation |
| `20 SOC` | `soc` | Security overview, WAF, L7 attack and flood, DNS abuse, TLS posture, Access and audit, Telemetry integrity |
| `30 Platform Admin` | `platform-admin` | Control plane, Fleet and rollouts, Data stores, Telemetry pipeline, Backups and recovery, Hosts and containers, Daily checks |
| `40 On-call & SLO` | `noc`, `soc` | SLOs, Incident view, Logs explorer, Request trace |
| `50 Domains` | `support` | Domain overview, Domain comparison |
| `60 Business & Capacity` | `business` | Usage and billing, Growth, Capacity |
| `90 Samples` | everyone | Query samples |

`platform-admin` sees every folder and is the only team that can edit. It edits
through the repository, because the provisioned dashboards are read-only. Every
other team can view its folders and cannot save a dashboard. Anonymous access is
off.

Team homes: `platform-admin` opens **Control plane**, `noc` opens **Now**, `soc`
opens **Security overview**, `support` opens **Domain overview**, and `business`
opens **Usage and billing**. The organization home is **Now**.

The access matrix lives in `docker/grafana/access.json`. A job named
`grafana-bootstrap` applies it every five minutes, so a permission someone
changed by hand is put back. Grafana's open-source edition cannot provision
teams or permissions from files, which is why a job does it through the API.

## What every dashboard has

- **Seven variables, in one order:** `environment`, `region`, `pop`, `edge`,
  `pool`, `domain`, `hostname`. Each defaults to **All**, and All means summed
  across every PoP. One Grafana serves one environment, so `environment` does
  not filter a query today.
- **A readable layout.** Every panel has a title that fits its width, a
  description, and a unit. A stat panel is at least four columns wide and a row
  holds at most six. A counter is always shown as a rate or an increase. No
  panel prints its query. Thresholds come from one shared set, and an empty
  state is grey, never red.
- **Annotations** for fleet rollouts, edge artifact publishes, cache purges,
  settings changes, emergency mode, and critical alerts.
- **Links** to the parent dashboard (**Now**), the sibling dashboards of the
  folder, and the runbook section for the dashboard.
- **Library panels** for every panel that appears on more than one dashboard,
  such as the traffic stats. Fix a library panel once and every dashboard
  changes.
- **Data links.** A `request_id` in a log line opens **Request trace**, a
  `domain_id` opens **Domain overview**, and a PoP opens **PoP detail**. The
  firing-alert table on **Now** opens an alert's dashboard at the time the alert
  started.

`docker/grafana/dashboards_*.py` define the dashboards, and
`docker/grafana/build_dashboards.py` writes the JSON. Run it after any change:

```bash
python3 docker/grafana/build_dashboards.py
python3 -m unittest tests/observability/test_grafana_contract.py
```

The contract test fails when a written file is stale, a panel breaks the style
rules, a query reads a table or view Grafana is not granted, or a Prometheus
query uses a metric nothing exports.

## Where each dashboard gets its data

| Source | Holds | Used for |
| --- | --- | --- |
| Prometheus | Live counters from every PoP, control-plane gauges, probes, host and service metrics, recorded SLO series | Live and operational panels |
| ClickHouse | Raw events for 7 days, the 1-minute rollup for 48 hours, hourly and daily rollups, 5-minute usage | Traffic splits, top lists, percentiles, billing |
| Control database | Read-only views of edges, cells, rollouts, purges, certificates, audit events, backups, and usage | Fleet state and audit |
| Loki | Operational logs | Logs, request trace |

Dashboards pick the ClickHouse rollup that fits the selected range: the
1-minute rollup up to 48 hours, the hourly rollup up to 400 days, and the daily
rollup beyond. Percentiles come from stored quantile states, so a 30-day p95 is
a real percentile and not an average of averages. See
[Platform settings](../reference/platform-settings.md#retention-tiers) for the
retention of every tier.

## Start and sign in

Development Grafana listens on `http://127.0.0.1:3000`. Sign in as `admin` with
`cdnf-grafana-dev-only`. The bootstrap job also creates one viewer for each team
(`noc-user`, `soc-user`, `support-user`, `business-user`, `platform-admin-user`)
with the development password `cdnf-grafana-team-users-dev-only`, so you can see
what a team sees. These credentials exist only on the development stack.

```bash
make dev-migrate dev-clickhouse-migrate
make dev-up
make dev-observability-seed
```

Production Grafana belongs to the `telemetry` profile and binds to
`127.0.0.1:3000` unless `GRAFANA_BIND` is changed. Set independent high-entropy
values for `GRAFANA_ADMIN_PASSWORD`, `GRAFANA_CLICKHOUSE_PASSWORD`, and
`GRAFANA_POSTGRES_PASSWORD`; Compose refuses to render without them. In
production, create the team members in Grafana (or through SSO) and add them to
the teams the bootstrap created. The bootstrap never creates users there.

```bash
docker compose --env-file .env.prod -f compose.prod.yml --profile telemetry --profile logs up -d
```

With the embedded PostgreSQL service, start the `control` profile first so the
idempotent Grafana role job can connect. With external PostgreSQL, set the
provisioning and query hosts, and change `GRAFANA_POSTGRES_SSLMODE` from the
embedded default `disable` to `require` or `verify-full`.

Publish Grafana through an authenticated HTTPS reverse proxy that preserves the
host and scheme, proxies WebSockets, and sets HSTS. Back up the `grafana-data`
volume.

## Data source accounts

| UID | Account | Permissions |
| --- | --- | --- |
| `prometheus` | none | private read-only HTTP endpoint |
| `clickhouse` | `cdnf_grafana` | `SELECT` on the event, rollup, and usage tables listed in `docker/clickhouse/users.d/grafana.xml`, and on `system.metrics`, `system.asynchronous_metrics`, and `system.events` |
| `control-db` | `cdnf_grafana` | connect, four inventory columns of `domains`, and the `grafana_*` views listed in `docker/grafana/postgres/provision.sql` |
| `loki` | none | private, bounded LogQL reads and live tail |
| `alertmanager` | none | the platform Alertmanager, for Grafana-managed alerts |

The `grafana_*` views are created by the application migrations. They leave out
certificate material, artifact payloads, audit metadata, and full client
addresses. Audit events show a network (a `/24` or `/48`), never an address.

The ClickHouse profile is `readonly=1`, caps execution at 60 seconds, defaults to
30 seconds, and bounds rows and bytes read. The data source pins its connection
timeout to 5 seconds and its query timeout to 30 seconds. PostgreSQL defaults
every transaction to read-only and applies 30-second statement and idle timeouts
and a 2-second lock timeout. Grafana never receives either application's write
credential.

For managed external databases, create equivalent accounts through the
provider's role workflow. `provision.sql` is the exact PostgreSQL contract; apply
the ClickHouse grants and profile as well, then set the `GRAFANA_*_HOST`, port,
protocol, TLS, user, and password variables. Verified TLS is required when
endpoints cross hosts.

## Alerts

Every alert carries `team` (`noc`, `soc`, or `platform`), `severity`, a
`summary`, a `runbook_url`, and a `dashboard_url`. Alertmanager routes by
`team`, so each team has its own receiver. A contract test fails on an alert
that lacks one of these, or whose runbook section or dashboard does not exist.

- **Prometheus alerts** cover the control plane, hosts, telemetry, traffic
  (5xx ratio, origin failures, cache-hit collapse, egress against the same hour
  last week, a PoP whose traffic stops), security (WAF block spike, a request
  flood, cache abuse, emergency mode, DNS NXDOMAIN and response-limit floods,
  TLS handshake failures, failed-login bursts, an administrator signing in from
  a new network), and propagation (purge, configuration, DNS serial).
- **Seven SLOs** have recording rules and multi-window burn-rate alerts: edge
  availability, 5xx ratio, edge latency, purge propagation, configuration
  propagation, DNS answer success, and telemetry delivery. A fast burn needs
  both the 1-hour and 5-minute windows over 14.4 times the budget; a slow burn
  needs the 6-hour and 30-minute windows over 6 times. The rules are generated by
  `scripts/observability/build_slo_rules.py`.
- **Grafana-managed alerts** cover signals only ClickHouse holds: one domain's
  5xx ratio at one PoP, a request flood on one domain, one client network above
  60 percent of a domain's requests, and an origin failover. They go to the same
  Alertmanager.
- **Inhibition.** A PoP that is down silences that PoP's per-domain alerts. A
  control-plane outage silences the derived staleness alerts. A fast burn
  silences the slow burn of the same SLO.

`CDNFoundryFailedOperations` counts failures inside a window
(`operations.failed_operations_window_minutes`, 60 by default) and resolves when
the window passes.

## Probes

A blackbox exporter on the monitoring host probes every PoP and nameserver over
IPv4 and over IPv6 with HTTP, TCP, and DNS probes. The monitoring host is
outside the PoP networks, so a PoP that serves its own health check but is
unreachable from outside still shows as down. Fleet writes the targets from the
nodes it manages.

## Seed data and scenarios

`make dev-observability-seed` fills a development stack with deterministic fake
traffic: three PoPs (`seed-fra`, `seed-iad`, `seed-sin`), eight fake domains,
thirty days of ClickHouse rollups, two days of Prometheus history, and six hours
of logs. An exporter then keeps delivering a minute of events at a time, so the
last 15 minutes are never empty. The seed writes only fake PoPs, domains, and
hostnames, and refuses to run anywhere but the development Compose project.

Pick a scenario with `SCENARIO`:

| Scenario | What changes | Alert that fires |
| --- | --- | --- |
| `normal` | Steady traffic with a little noise | none |
| `origin-down` | One domain's origin returns 502 and fails over | `EdgeGlobal5xxRatioHigh` (noc) |
| `l7-flood` | One domain floods one PoP | `DomainRequestFlood` (soc) |
| `dns-flood` | NXDOMAIN flood on the authoritative servers | `DnsNxdomainFlood` (soc) |
| `pop-loss` | `seed-sin` stops serving and its probes fail | `EdgeHeartbeatStale` (noc) |
| `cert-expiry` | Handshakes fail on an expired certificate | `CDNFoundryCertificateExpiry` (noc) |
| `purge-stuck` | A purge stays pending | `PurgePropagationSlow` (platform) |
| `telemetry-drop` | Vector drops events and one PoP stops delivering | `TelemetryEventsDropped` (platform) |

Each scenario has a window and posts an annotation to Grafana. `normal` ends
every other scenario.

## Reports

A scheduled job builds two reports from rollups only, each as CSV and PDF:

- **Monthly usage report** (`cdnf:reports:build usage`, on the 2nd at 03:40 UTC
  for the month that ended): bandwidth (bytes sent, headers included),
  95th-percentile bandwidth, requests, and DNS queries, per domain, pool, and
  region. It runs the same query as **Usage and billing**, so its totals equal
  that dashboard's for the same range.
- **Weekly operations report** (`cdnf:reports:build operations`, Mondays at
  06:00 UTC): SLO results, incidents, top errors, top attacked domains, and the
  capacity forecast.

Administrators list reports with `GET /api/admin/reports` and download one with
`GET /api/admin/reports/{id}/download`. Grafana's open-source edition has no
scheduled reporting, which is why the control plane builds them.

## Test it

```bash
python3 -m unittest tests/observability/test_grafana_contract.py \
  tests/observability/test_metrics_contract.py tests/observability/test_grafana_bootstrap.py
make dev-grafana-smoke                                  # every panel query, against the real data sources
GRAFANA_EXPECT_DATA=1 python3 tests/e2e/grafana_observability.py   # on a seeded stack, empty panels fail
make dev-grafana-browser                                # the Playwright suite
```

The browser suite signs in once per team and checks folder permissions. It then
opens every dashboard on a desktop, a phone, and a 1080p kiosk screen, over 15
minutes, 6 hours, 7 days, and 30 days, and fails on a query error, a panel that
shows "No data", a truncated title, printed query text, a link that does not
load, or a browser console error. It also checks Explore from a panel, live
tail, the NOC wall drill-down to PoP detail, and an alert opening its dashboard.
It saves a screenshot of every dashboard under
`tests/browser/test-results/grafana-screens`.

`make dev-grafana-browser ARGS=scenarios` starts each seed scenario and checks
that its alert fires, reaches the receiver for its team, and shows in Grafana's
alerting screen. It takes about an hour.

Run the suite only against development, test, or staging stacks with seeded
data, never against production.

## What is not covered yet

- PostgreSQL, Valkey, and PHP-FPM have no exporters, so the **Data stores**
  dashboard shows PostgreSQL size and connections from queries and ClickHouse and
  Loki from their own metrics. Valkey memory, evictions, and hit ratio, and
  PostgreSQL locks and slow queries, are not shown.
- Per-container CPU, memory, and restarts have no exporter. **Hosts and
  containers** shows host figures.
- Caddy's admin API is off by design, so Caddy has no metrics. Requests are
  counted at the application as `cdnfoundry_http_requests_total`.
- DNS events carry no ASN, because DNSdist's dnstap does not include one. Edge
  events do.
- The usage report is delivered through the API. There is no screen in the
  administration panel for it yet.

`docker/prometheus/scrape-coverage.yml` records, for every production service,
the Prometheus job that scrapes it or the reason it does not. A contract test
fails when a service is in neither list.

## Troubleshooting

1. Check `docker compose ps grafana prometheus clickhouse` and Grafana
   `/api/health`.
2. Run `python3 tests/e2e/grafana_observability.py`. It names any panel whose
   query fails.
3. A dashboard that does not appear usually references a library panel that does
   not exist yet. The bootstrap job creates them; check its logs, then wait one
   30-second provisioning pass.
4. For PostgreSQL permission errors, rerun the migrations and the
   `grafana-control-db-provision` service. Never grant table-wide access to
   certificate, DNS-record, user, audit, or secret columns.
5. For ClickHouse permission errors, compare the grants and profile with
   `docker/clickhouse/users.d/grafana.xml`. Do not give Grafana the ingestion
   account.
6. For empty panels on a real stack, check the retention tier first: raw events
   last 7 days, and the ASN panels read raw events.

Follow the [ClickHouse or Vector outage runbook](runbooks.md#clickhouse-or-vector-outage)
when ingestion or delivery is failing. An observability outage must not stop
serving.
