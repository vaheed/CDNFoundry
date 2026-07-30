---
title: Grafana command centers
description: Start, secure, provision, and troubleshoot CDNFoundry's two operations dashboards.
---

# Grafana command centers

CDNFoundry provisions exactly two Grafana dashboards in the **CDNFoundry
Operations** folder. The system command center is the home dashboard; the
domain command center has one single-select `$domain_id` input. Neither
dashboard changes desired state or participates in DNS, HTTP, security, or
telemetry ingestion.

## Start and sign in

Development Grafana listens only on `http://127.0.0.1:3000`. Sign in with user
`admin` and password `cdnf-grafana-dev-only`. These public test credentials are
for the local stack only.

```bash
make dev-migrate
make dev-grafana-smoke
```

Production Grafana belongs to the `telemetry` profile and binds to
`127.0.0.1:3000` unless `GRAFANA_BIND` is explicitly changed. Set independent
high-entropy values for `GRAFANA_ADMIN_PASSWORD`,
`GRAFANA_CLICKHOUSE_PASSWORD`, and `GRAFANA_POSTGRES_PASSWORD`; Compose refuses
to render without them. Anonymous access and user signup are disabled.

```bash
docker compose --env-file .env.prod -f compose.prod.yml --profile telemetry --profile logs up -d
```

With the embedded PostgreSQL service, start the `control` profile first so the
idempotent Grafana role job can connect. With external PostgreSQL, include the
external-control-data override, set the provisioning/query hosts, and change
`GRAFANA_POSTGRES_SSLMODE` from the embedded-service default `disable` to
`require` or `verify-full` according to the provider's certificate setup.

Keep the listener on loopback and publish it through an authenticated HTTPS
reverse proxy. Preserve the original host and scheme, enable WebSocket proxying,
set HSTS according to site policy, restrict operator source networks or SSO,
and back up the `grafana-data` volume. Do not expose port 3000 directly.

The custom image pins Grafana `12.3.0` by manifest digest and installs the
official ClickHouse datasource plugin `4.8.2` from its checksum-verified
official GitHub release asset at image-build time. Container
startup never downloads plugins. Dashboard and datasource provisioning is
read-only and version controlled under `docker/grafana/`.

## Datasource accounts

| UID | Account | Permissions |
| --- | --- | --- |
| `prometheus` | none | private read-only HTTP endpoint |
| `clickhouse` | `cdnf_grafana` | `SELECT` on six `cdnf` event/aggregate tables and `system.metrics`, `system.asynchronous_metrics`, `system.events` only |
| `control-db` | `cdnf_grafana` | connect/usage, four inventory columns on `domains`, and the sanitized `grafana_domain_operational_metadata` view |
| `loki` | none | private, bounded LogQL reads and live tail |

The ClickHouse profile is `readonly=1`, caps execution at 60 seconds, defaults
to 30 seconds, and bounds rows/bytes read and result rows. The datasource pins
its connection timeout to 5 seconds and its query timeout to 30 seconds so the
plugin never requests a value above the account ceiling. Its constraint
explicitly allows the datasource to lower or raise `max_execution_time` within
1–60 seconds while remaining read-only. PostgreSQL defaults every transaction
to read-only and applies 30-second statement/idle and 2-second lock timeouts.
Grafana never receives either application's write credential.

The local ClickHouse account is loaded from `users.d/grafana.xml` on every
start. The PostgreSQL provisioning job is idempotent and safe with persistent
volumes. The application migration owns the sanitized view; run migrations
before expecting the metadata panel to be healthy.

For managed external databases, create equivalent accounts through the
provider's approved role workflow. `docker/grafana/postgres/provision.sql` is
the exact PostgreSQL grant contract. Apply the ClickHouse grants listed above,
including the bounded profile, then set the `GRAFANA_*_HOST`, port, protocol,
TLS, user, and password variables. Use
`deploy/production/compose.external-control-data.yml` for external PostgreSQL
and `deploy/production/compose.external-telemetry-data.yml` for external
ClickHouse. Verified TLS is required when endpoints cross hosts.

## Dashboard behavior

Both dashboards default to six hours and refresh every 30 seconds.

- **System Command Center** has no variables. Its first 16:9 screen is the
  incident strip; diagnostic rows below it are collapsed. Red means loss,
  drift, error, or immediate capacity pressure; yellow means warning or ≥80%
  capacity.
- The **New firing alerts** annotation is disabled by default. Enabling it
  shows one bounded marker near each new firing transition instead of painting
  every Prometheus scrape across the graph background.
- The HTTP analytics row includes a **Recent proxy request tail** backed by
  ClickHouse. It refreshes every 30 seconds and shows sanitized client-facing
  status plus edge-to-origin status, role, and transition. It never selects
  client IPs, query strings, headers, user agents, referrers, cookies, or bodies.
- Its collapsed **Live Operational Logs** row shows errors by service/host,
  critical events, restart/OOM evidence, edge/gateway/DNS/TLS/queue/data-store
  failures, ingestion health, and alert-adjacent logs.
- **Domain Command Center** gets its searchable domain list from non-deleted
  PostgreSQL `domains`, so domains without traffic remain selectable. Every
  panel filters on the numeric selected domain ID and renders a no-traffic
  state independently of datasource health.
- Its operational-log row reuses the same `$domain_id`; no second variable or
  label is introduced.
- Its HTTP request row contains the same bounded request tail scoped to the
  selected domain.

Raw HTTP/DNS tables retain seven days. Exact origin and WAF quantiles and rich
incident dimensions always use raw events and explicitly show when a selected
range crosses that boundary. Request/byte volume panels select raw, hourly, or
daily sources according to range. Hourly retention is 400 days and daily
retention is three years. Hourly averages are never presented as percentiles.

Paths exclude query strings at Vector ingestion and top lists are bounded.
Dashboards never select client IP, authorization, cookie, request body, user
agent, or referrer columns.

Access events are not Loki logs. For a faster incident view, expand the request
tail, shorten the dashboard range, and temporarily select the bounded 10-second
refresh. Use Grafana Explore with the ClickHouse datasource for ad hoc access
queries. Loki Explore remains reserved for operational and error logs.

## Prometheus target inventory

Development scrapes the two optional gateway containers through
`edge-targets.dev.yml`. Production starts with an empty
`edge-targets.prod.yml`: populate a deployment-owned copy with private
`host:9105` endpoints and set `PROMETHEUS_EDGE_TARGETS_FILE`. Restrict port
9105 at the host firewall. Per-edge heartbeat metrics remain available from
the control plane even when direct gateway scraping is intentionally absent.

ClickHouse's supported Prometheus exporter listens privately on port 9363.
Prometheus also scrapes itself so zero firing-alert counts can be distinguished
from a missing monitoring system.

Prometheus scrapes Loki and the local operational collector. Populate the
private target file selected by `PROMETHEUS_LOG_TARGETS_FILE` for collectors on
other hosts. See [Operational logging](operational-logging.md) for identities,
socket security, retention, saved queries, and outage recovery.

## Troubleshooting

1. Check `docker compose ps grafana prometheus clickhouse` and Grafana
   `/api/health`.
2. Run `python3 tests/e2e/grafana_observability.py`; it checks all datasource
   health endpoints and both dashboard UIDs without rendering a browser.
3. For PostgreSQL permission errors, rerun the migration and the idempotent
   `grafana-control-db-provision` service. Never grant table-wide access to
   certificate, DNS-record, user, audit, operation-input, or secret columns.
4. For ClickHouse permission errors, compare grants/profile with
   `docker/clickhouse/users.d/grafana.xml`. Do not give Grafana the ingestion
   account.
5. For empty panels, distinguish **No traffic in selected range** from a red
   datasource health error. Check the seven-day boundary before diagnosing
   missing raw detail.
6. For absent edge gateway series, validate the private file_sd targets and
   Prometheus `/targets`; do not expose metrics publicly.

Follow the [ClickHouse or Vector outage runbook](runbooks.md#clickhouse-or-vector-outage)
when ingestion or delivery is failing. An observability outage must not stop
serving.
