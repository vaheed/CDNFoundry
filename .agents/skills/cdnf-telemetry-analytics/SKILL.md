# cdnf-telemetry-analytics

## Purpose
Implement bounded traffic telemetry, analytics, rollups, and export.
## When to use
For Vector, ClickHouse, analytics, logs, usage summaries, or billing export.
## Required inputs
Event schema, redaction, buffer/retention limits, query scope, aggregates, and accuracy dataset.
## Files normally touched
Vector, ClickHouse schema/views, bounded Laravel API/UI, rollups, tests and docs.
## Procedure
Define bounded redacted events; send Vector directly to ClickHouse; cap buffers/retention; build aggregates; enforce domain/time/filter/result/execution limits; keep compact PostgreSQL summaries; test traffic accuracy and outage.
## Validation commands
Schema/config tests, generated traffic comparison, query-bound and outage tests.
## Definition of done
Analytics are scoped/accurate/bounded and failure cannot interrupt serving.
## Stop conditions
Stop if sensitive fields, retention, bounds, or outage behavior is undefined.
## Forbidden shortcuts
No raw logs through Laravel/Redis/PostgreSQL, secrets/bodies/cookies, or unbounded labels/queries/buffers.
## Expected completion summary
Schemas, redaction/retention/bounds, accuracy and outage results.
