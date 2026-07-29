---
title: Gzip and Brotli compression
description: Configure bounded delivery compression while retaining one canonical cache object.
---

# Gzip and Brotli compression

Compression is selected per service pool and compiled into each assigned
domain's revisioned edge artifact. It does not create a process, cache
directory, timer, or Nginx configuration per domain.

| Profile | Algorithms | Concurrent compression ceiling | Pool kinds |
| --- | --- | ---: | --- |
| Off | identity | 0 | all |
| Standard | Gzip level 5 | 32 per cell | all |
| Maximum savings | Brotli or Gzip level 5 | 16 per cell | reserved, dedicated |

Standard is the default. Maximum savings is rejected for shared and quarantine
pools by API validation and a PostgreSQL constraint. The immutable edge image
builds `ngx_brotli` commit `a71f9312c2deb28875acc7bacfdd5695a111aa53`
against the exact pinned OpenResty source.

## Cache and HTTP behavior

The outer cell always asks the inner origin proxy for identity encoding, so
cache lookup, revalidation, stale service, exact purge, and epoch purge operate
on one canonical representation. Delivery compression occurs after cache
lookup. Identity, Gzip, and Brotli clients therefore share the same resident
object and cache status.

Only the explicit text, JSON, XML, SVG, WebAssembly, and font MIME allowlist is
eligible. Responses smaller than 1 KiB, larger than 10 MiB, partial/range
responses, images, video, audio, archives, and already-compressed formats use
identity. Nginx owns correct `Vary: Accept-Encoding`, `ETag`, `HEAD`, `304`,
and filter behavior.

Malformed or unsupported `Accept-Encoding` values safely receive identity.
Brotli preference is used only by maximum-savings pools; Gzip remains the
compatible fallback.

## Pressure and emergency fallback

Each cell counts active requests in bounded shared memory. Standard falls back
to identity above 32 active compression requests; maximum savings falls back
above 16. A cell above 2,048 active connections also uses identity. Traffic
continues and telemetry records `cpu_pressure_identity`.

For immediate local containment, set `EDGE_COMPRESSION_DISABLED=1` and replace
the affected bounded cell. For durable fleet-wide disable, change the pool
profile to Off; that creates one coalesced asynchronous reconciliation and
preserves the prior active artifact until acknowledgement.

## Telemetry

Request events record encoding (`identity`, `gzip`, or `br`), delivered bytes,
compression ratio, selected profile, and fallback reason. The compression
analytics view is a raw, unsampled, domain-scoped query bounded to 24 hours and
reports delivered bytes, estimated identity bytes, bytes saved, and savings
ratio. Apply the additive ClickHouse migration before deploying Vector:

```sh
docker compose --env-file .env.prod -f compose.prod.yml exec -T clickhouse \
  clickhouse-client --multiquery < docker/clickhouse/migrations/2026_07_29_add_compression_telemetry.sql
```

Telemetry failure or migration delay never changes serving decisions.
