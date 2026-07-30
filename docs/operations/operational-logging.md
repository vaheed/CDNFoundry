---
title: Operational logging
description: Deploy, query, secure, and recover CDNFoundry's bounded Vector and Loki operational-log pipeline.
---

# Operational logging

CDNFoundry sends application and container operational logs from one Vector
collector per host to a single-binary Loki service. Grafana reads Loki directly.
Laravel is never an ingestion proxy, and neither Loki nor Vector participates in
DNS, HTTP, queue, reconciliation, or edge activation paths. A full buffer drops
the newest log entry instead of applying backpressure to a service.

HTTP access events remain in ClickHouse. DNS query events remain in ClickHouse.
The operational collector explicitly removes the OpenResty `edge_json` request
shape, common/combined Nginx access lines, and the dnstap-derived DNS query
shape before Loki. This also prevents stale web or edge-control containers from
leaking request paths or query strings into the operational store.

## Deployment

Loki `3.7.2` and the per-host Vector `0.55.0` Debian collector are both pinned
by upstream manifest digest. Development
publishes `127.0.0.1:3100`, uses TSDB plus filesystem object storage, and retains
seven days. Production starts Loki only in `telemetry`, publishes no host port,
uses `loki-data`, and retains 14 days by default. The compactor applies retention.
Filesystem storage is the bounded single-telemetry-host default; object storage
is a future scaling option, not a deployment prerequisite.

Start exactly one collector on every control, DNS, edge, and telemetry host by
adding the `logs` profile to that host's normal role command:

```sh
docker compose --env-file .env.prod \
  -f compose.prod.yml \
  -f deploy/production/compose.edge-host.yml \
  --profile edge --profile logs up -d
```

Set a stable `LOG_HOST`, `LOG_ROLE`, and globally unique `LOG_COLLECTOR_ID` in
each host's environment copy. Set `LOKI_ENDPOINT` to the source-restricted
telemetry gateway, for example `https://telemetry.example.com:8444`. The
telemetry-host overlay uses private `http://loki:3100` locally. Do not run both
the base collector and a second role-specific collector on one host.

The default disk buffer is 2 GiB per production host and 256 MiB in development.
`when_full: drop_newest`, ten retries, and bounded backoff make failure explicit.
Expose `LOG_METRICS_BIND` only on a private monitoring address and put remote
`host:9599` targets in the file selected by `PROMETHEUS_LOG_TARGETS_FILE`.

## Optional host journal

On hosts with persistent systemd journals, add the overlay:

```sh
docker compose --env-file .env.prod \
  -f compose.prod.yml \
  -f deploy/production/compose.edge-host.yml \
  -f deploy/production/compose.host-journal.yml \
  --profile edge --profile logs up -d
```

It reads Docker/containerd units and kernel events for daemon failure, restart,
OOM, disk/filesystem, and service-crash evidence. Omit this overlay on hosts
without journald. Container lifecycle events not written to stdout or journald
cannot be reconstructed by the base Docker log source.

The Docker socket is a privileged host trust boundary even when mounted
read-only. Only the collector gets `/var/run/docker.sock`; never expose it over
TCP and never mount it into an application container. The collector drops all
Linux capabilities, uses a read-only root filesystem, and has CPU, memory, PID,
and disk-buffer bounds, but Docker API read access still reveals host container
metadata and logs.

## Envelope, labels, and redaction

Vector parses JSON when possible and emits a common JSON envelope containing
timestamp, normalized level, service, role, host, event, message, correlation
IDs, revision and cell identifiers, duration, and error code. Plain text is
retained with `parse_error=true`; messages are capped at 16 KiB and stack traces
at 64 KiB. Multiline exceptions are merged before normalization.

Only `environment`, `host`, `role`, `service`, `level`, `stream`, and
`collector_id` are Loki labels. Domain, request, operation, job, task, edge,
cell, revision, path, and error values stay in JSON or structured metadata.

The final shared Vector transform masks authorization, cookies, passwords,
secrets, access/refresh/bootstrap tokens, API keys, sessions, PEM content,
database credentials/URLs, bodies, URL queries, and sensitive command options.
Laravel's JSON formatter independently allowlists context and never serializes
arbitrary request context. Do not add raw bodies, headers, SQL bindings,
certificates, keys, tokens, client addresses, or access lines to structured logs.

## Queries and live tail

Use the two dashboard **Live Operational Logs** rows or Grafana Explore with
datasource UID `loki`. Useful queries include:

```text
{environment="production"} | json | level=~"error|critical"
```

```text
{environment="production", service="edge-agent"} | json
```

```text
{environment="production"} | json | domain_id="$domain_id"
```

```text
{environment="production"} | json | operation_id!="null"
```

```text
sum by (service) (
  count_over_time({environment="production"} | json | level=~"error|critical" [5m])
)
```

Set `GRAFANA_EXPLORE_URL` on control processes to show the administrator-only
**Live Logs** navigation item. It opens Grafana in a new tab. Leaving the value
empty hides the item; CDNFoundry never iframes Grafana or proxies Loki.

## Failure and recovery

For delivery failures, check collector metrics on 9599, the `loki` component's
buffer ratio/errors/drops, telemetry-gateway source allowlists and TLS, Loki
`/ready`, retention rejections, and host filesystem pressure. Do not restart
traffic services to repair logging. When Loki returns, Vector drains its bounded
oldest-first disk buffer. Events dropped after saturation are unrecoverable.

The standard control Restic backup does **not** include `loki-data`. Operational
logs are disposable derived data under retention. If incident policy requires
log preservation, snapshot the Loki volume with an operator-owned, crash-safe
volume procedure or move to supported object storage, and test restoration
separately. Restoring Loki is not required to restore CDN service.
