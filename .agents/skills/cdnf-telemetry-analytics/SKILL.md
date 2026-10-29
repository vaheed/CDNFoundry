---
name: cdnf-telemetry-analytics
description: Work on CDNFoundry telemetry: Vector pipelines, ClickHouse schema and retention, analytics and log queries, and usage rollups. Use for any change to events, dashboards, or analytics APIs.
---

# cdnf-telemetry-analytics

## Purpose
Collect bounded, redacted events from edges and DNS straight into ClickHouse, and query them with strict tenant and time scoping.

## When to use
Changes to `docker/vector/*.yaml`, `docker/clickhouse/**`, `AnalyticsStore`, analytics and log endpoints, usage rollups, or Grafana dashboards.

## Required inputs
The event fields, redaction, retention, query bounds (range, rows, execution time), and the tenant scope.

## Files normally touched
`docker/vector/vector.yaml`, `docker/vector/operational.yaml`, `docker/clickhouse/init.sql`, `docker/clickhouse/migrations/*`, `core/app/Support/AnalyticsStore.php`, `core/app/Http/Controllers/AnalyticsController.php`, `DomainLogController.php`, `UsageController.php`, `core/app/Jobs/BuildUsageRollups.php`, `docs/reference/telemetry-schema.md`, `docs/guides/analytics.md`.

## Procedure
1. Every event that belongs to a tenant carries `domain_id`. Scope tenant queries by `domain_id`, never by name suffix. DNS events currently use `domain_id = 0` and are scoped by zone name, which leaks across tenants (roadmap H9).
2. Use ClickHouse query parameters (`{name:Type}`) for every value. Only fixed column and table names may be interpolated.
3. Bound everything: the range (90 days for analytics, 24 hours for raw logs), the `LIMIT`, the execution time, and the Vector buffers.
4. Redact client data according to `docs/reference/telemetry-schema.md`.
5. A ClickHouse outage must never stop serving. The API returns `AnalyticsUnavailableException` instead.

## Validation commands
- Backend: `make dev-test`, or one class with `docker compose -f compose.dev.yml -f compose.test.yml run --rm --no-deps core php artisan test --compact --filter <Class>` (the test override forces in-memory SQLite; never run tests against PostgreSQL).
- Style: `docker compose -f compose.dev.yml -f compose.test.yml run --rm --no-deps core vendor/bin/pint --test`.
- Feature: `AnalyticsApiTest`, `TelemetryBrowserExportTest`.
- Contract: `make contract-check` (Grafana and logging contracts).
- Runtime: `python3 tests/e2e/phase7_analytics.py`, `vector_traffic.py`, `vector_buffer_startup.py`.

## Definition of done
Counts match generated traffic, tenants see only their own events, and outages degrade analytics without affecting traffic.

## Stop conditions
Stop if raw telemetry would pass through Laravel, or a query could run unbounded.

## Forbidden shortcuts
No string-built values in SQL, no unscoped admin queries reused for tenants, and no retention-free tables.

## Expected completion summary
Schema changes, scoping, bounds, redaction, accuracy evidence, and outage behavior.
