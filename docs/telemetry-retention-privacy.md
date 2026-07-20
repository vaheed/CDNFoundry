# Telemetry retention, loss, and privacy

## Retention and deletion

ClickHouse raw edge and DNS tables delete partitions after 7 days. Hourly
aggregates retain 400 days and daily aggregates retain 3 years. These are
deployment defaults and may be shortened as an operational privacy decision;
changes apply through ClickHouse TTL processing, not synchronously in a web
request.

Deleting a domain removes desired control-plane state according to its normal
lifecycle. It does not claim immediate erasure of derived ClickHouse rows.
Unlinked raw rows expire within the raw TTL and aggregate rows within their
configured TTL. For a legally required early erasure, an operator must run and
audit a bounded ClickHouse mutation for the exact domain ID and hostname/zone,
then verify replicas before closing the request.

## Safe schema and masking

Vector deletes authorization data, cookies, query data, request bodies, and
unknown fields before storage. It never receives TLS private keys. Paths omit
the query string and are limited to 2,048 bytes. Hostnames, methods, errors,
security reasons, edge identifiers, user agents, referrers, and DNS names are
sanitized for control characters and length-bounded. Normal logs and exports
mask IPv4 to `/24` and IPv6 to `/48`; invalid addresses render as `unknown`.

## Loss and overload semantics

Each ClickHouse sink has its own persistent 1 GiB Vector disk buffer, batches at
most 1,000 events, caps retry backoff at 300 seconds, and uses `drop_newest` at
the hard byte limit. The
two buffers therefore consume at most 2 GiB plus Vector metadata. A full or
unavailable telemetry path drops telemetry; it never blocks DNS or HTTP.

Prometheus scrapes buffer bytes, discarded events, component errors, and
collector availability. Alerts fire on any recent drop, sustained delivery
errors, a buffer above 80 percent, or collector loss. Operators must treat a
drop interval as incomplete: charts remain available but totals cannot be
reconstructed unless another explicitly retained source exists. Never infer
billing accuracy across a recorded loss window.
