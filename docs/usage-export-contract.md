# Usage export contract

Usage is reporting data, never request-path enforcement. The hourly scheduler
reads finalized ClickHouse intervals in chunks of at most 250 domains and
upserts PostgreSQL on the unique key `(domain_id, interval_start, granularity)`.
Rebuilding the same interval is idempotent. Administrator rebuild requests
accept complete UTC hours, span at most 31 days, return HTTP 202 plus an
operation ID, and support `Idempotency-Key` replay.

Domain and administrator JSON exports have `meta.contract_version = 1` and are
limited to 10,000 rows. CSV is streamed in 500-row chunks with this stable
header:

```text
contract_version,domain_id,interval_start,interval_end,granularity,requests,bytes_in,bytes_out,cache_hits,dns_queries,status
```

Timestamps are UTC ISO 8601, bandwidth is bytes, counts are non-negative
integers, `granularity` is `hour`, and finalized rows use `status=finalized`.
Consumers must reject unknown contract versions and store the entire compound
interval identity, not assume delivery order. A newer contract must use a new
version rather than silently changing column meaning.

Filament downloads use session-authenticated browser routes:

- `/app/analytics/domains/{domain}/usage.csv` for an assigned domain
- `/admin/telemetry/usage.csv` for an administrator-wide export

These routes force CSV output, apply the same range bounds and policy scope as
the API, and stream the same contract. They intentionally do not send a browser
session to token-protected `/api/...` URLs.
