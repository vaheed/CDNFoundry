# ClickHouse outage runbook

ClickHouse is derived telemetry storage and is never on the DNS, HTTP, TLS,
cache, or security decision path. During an outage analytics APIs return HTTP
503 with `analytics_unavailable`; the Filament pages visibly label the outage.

1. Confirm serving independently with DNSdist UDP and TCP queries and an HTTP or
   HTTPS request to an already active domain. Do not restart serving components.
2. Check `docker compose ps clickhouse vector` and Prometheus targets. Inspect
   `vector_buffer_byte_size`, `vector_component_errors_total`, and
   `vector_component_discarded_events_total`.
3. Restore ClickHouse storage/network access without deleting its named volume.
   Validate `SELECT 1`, table TTLs, and materialized views before broader work.
4. Watch buffer bytes decrease and successful sink events increase. If a sink
   does not resume after ClickHouse is healthy, restart only Vector; its disk
   buffer survives the restart. Confirm a uniquely generated pre-recovery event
   appears. Live traffic must remain responsive while backlog drains.
5. Record the exact outage and drop window. If discarded events increased, mark
   the interval incomplete; do not manufacture usage. Rebuild retained complete
   UTC hours through `POST /api/admin/usage/rebuild` and verify the operation.
6. If delivery still fails, validate Vector configuration and ClickHouse
   credentials/schema. Preserve the last serving state and the Vector data
   volume; never use `down -v`, truncate PostgreSQL, or refresh migrations.

The automated rehearsal is `python3 tests/e2e/phase7_analytics.py`. It stops and
starts only ClickHouse, proves DNS/edge responses continue, queues a unique
event while unavailable, and waits for that event after recovery.
