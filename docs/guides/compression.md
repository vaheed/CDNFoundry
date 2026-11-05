---
title: Gzip and Brotli compression
description: Choose a compression profile for a pool, understand when the edge skips compression, and read compression telemetry.
---

# Gzip and Brotli compression

This page explains the three compression profiles of a service pool and how the edge falls back when it is busy. **You need:** administrator access, because the profile is set on the pool. Gzip and Brotli are two ways to shrink text responses on their way to the browser.

::: tip Start with Standard
Use Standard for ordinary shared traffic. Enable Maximum savings only on a
qualified reserved or dedicated WAF-capable image after measuring CPU,
latency, response mix, and fallback behavior under pressure.
:::

An administrator chooses the profile on a service pool. It goes into the edge artifact of each assigned domain. It does not create a process, cache directory, timer, or Nginx configuration per domain.

| Profile | Algorithms | Concurrent compression ceiling | Pool kinds |
| --- | --- | ---: | --- |
| Off | identity | 0 | all |
| Standard | Gzip level 5 | 32 per cell | all |
| Maximum savings | Brotli or Gzip level 5 | 16 per cell | reserved, dedicated |

Standard is the default. The API and a PostgreSQL constraint reject Maximum savings on shared and quarantine pools. The immutable edge image
builds `ngx_brotli` commit `a71f9312c2deb28875acc7bacfdd5695a111aa53`
against the exact pinned OpenResty source.

## Cache and HTTP behavior

The cell always asks the origin proxy for an uncompressed (identity) response. Cache lookup, revalidation, stale service, and both kinds of purge therefore work on one stored copy. The edge compresses after the cache lookup. Clients that accept identity, Gzip, or Brotli share the same cached object and cache status.

Only a fixed list of text, JSON, XML, SVG, WebAssembly, and font MIME types is compressed. These responses are sent as identity: smaller than 1 KiB, larger than 10 MiB, partial or range responses, images, video, audio, archives, and formats that are already compressed. Nginx sets `Vary: Accept-Encoding` and handles `ETag`, `HEAD`, and `304` correctly.

A malformed or unsupported `Accept-Encoding` header gets identity. Only Maximum savings pools prefer Brotli. Gzip is the fallback.

## Pressure and emergency fallback

Each cell counts active compression requests. Standard falls back to identity above 32 active requests. Maximum savings falls back above 16. A cell with more than 2,048 active connections also uses identity. Traffic continues and telemetry records `cpu_pressure_identity`.

To stop compression on one cell at once, set `EDGE_COMPRESSION_DISABLED=1` and replace that cell. To turn it off everywhere for good, set the pool profile to Off. That starts one asynchronous reconciliation and keeps the previous active artifact until the edges acknowledge.

## Telemetry

Request events record the encoding (`identity`, `gzip`, or `br`), delivered bytes, compression ratio, profile, and fallback reason. The compression view in [Analytics](analytics.md) uses raw, unsampled events for one domain and covers at most 24 hours. It shows delivered bytes, estimated identity bytes, bytes saved, and the savings ratio. On an existing install, apply this ClickHouse migration before you deploy Vector:

```sh
docker compose --env-file .env.prod -f compose.prod.yml exec -T clickhouse \
  clickhouse-client --multiquery < docker/clickhouse/migrations/2026_07_29_add_compression_telemetry.sql
```

A telemetry failure or a late migration never changes how the edge serves traffic.
