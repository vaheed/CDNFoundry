---
title: Operational logging
description: Deploy the Vector and Loki operational-log pipeline, query logs in Grafana, and recover from delivery failures.
---

# Operational logging

This page shows how to deploy the operational-log collector, search logs in Grafana, and recover when delivery fails. Operational logs are the application and container logs of CDNFoundry services. They are not customer traffic logs.

**You need:** a telemetry host that runs Loki, and shell access to each host that will send logs.

::: info Logging failure must not stop serving
Collectors use bounded buffers and redact secrets. If Loki or a remote sink is unavailable, DNS and HTTP keep working. Old buffered events may be dropped, and dropped events cannot be rebuilt.
:::

## How it works

One Vector collector on each host reads container logs and sends them to a single Loki service. Grafana reads Loki directly. Laravel never receives or forwards logs. Neither Loki nor Vector sits in the DNS, HTTP, queue, reconciliation, or edge activation path. When a buffer is full, the collector drops the newest log entry. It never slows a service.

HTTP access events and DNS query events stay in ClickHouse. Before logs reach Loki, the collector removes three shapes: the OpenResty `edge_json` request shape, common and combined Nginx access lines, and the dnstap-derived DNS query shape. This also stops an old web or edge-control container from leaking request paths or query strings into Loki.

## Deployment

`compose.prod.yml` pins Loki and the per-host Vector collector by image digest. Development publishes Loki on `127.0.0.1:3100` and keeps logs for seven days. Production starts Loki only with the `telemetry` profile, publishes no host port, stores data in the `loki-data` volume, and keeps logs for 14 days by default (`LOKI_RETENTION_PERIOD=336h`). The Loki compactor applies retention. Loki stores data on the local filesystem of the single telemetry host.

Start exactly one collector on every control, DNS, edge, and telemetry host. Add the `logs` profile to that host's normal role command:

```sh
docker compose --env-file .env.prod \
  -f compose.prod.yml \
  --profile edge --profile logs up -d
```

**Expected result:** `docker compose ps` lists `log-collector` and `docker-socket-proxy` as healthy.

In each host's environment file, set:

- `LOG_HOST`: a stable host name.
- `LOG_ROLE`: the host role.
- `LOG_COLLECTOR_ID`: an ID that is unique across all hosts.
- `LOG_AUTH_TOKEN`: the shared token for the telemetry gateway.
- `LOKI_ENDPOINT`: the source-restricted telemetry gateway, for example `https://telemetry.ops.example.com:8444`. A collector on the telemetry host itself may use the private `http://loki:3100`.

Do not run the base collector and a second role-specific collector on one host.

The default disk buffer (`LOG_BUFFER_BYTES`) is 2 GiB per production host and 256 MiB in development. The buffer drops the newest entry when full. The collector retries ten times with bounded backoff, so failure is explicit.

When a Fleet node has `monitor_ipv4`, the collector binds metrics to that
private address and Fleet adds `monitor_ipv4:9599` to the monitoring host's
generated discovery file. Without `monitor_ipv4`, the collector binds the
node's local `bind_ipv4`; discovery uses the advertised `public_ipv4` only from
a remote monitoring host. On the monitoring host itself, Prometheus uses the
private Compose target `log-collector:9599`, so NAT hairpin routing is not
required. Set `monitor_ipv4` on every remote node when its advertised public
address is not privately reachable from the monitoring host.

The collector reads Docker container logs only. `compose.prod.yml` does not configure host journal ingestion. If you add it yourself, keep the same redaction, labels, and bounded buffer. The collector cannot rebuild container lifecycle events that Docker logs do not contain.

The Docker socket is a privileged host boundary, even when mounted read-only. Only the `docker-socket-proxy` container mounts `/var/run/docker.sock`. It allows only container listing, logs, events, ping, and version, and refuses everything else, including POST and exec. The collector reaches it over an internal Compose network. Never expose the socket over TCP outside that network, and never mount it into an application container. The collector drops all Linux capabilities, uses a read-only root filesystem, and has CPU, memory, PID, and disk-buffer limits. Read access to the Docker API still shows host container metadata and logs.

## Envelope, labels, and redaction

Vector parses JSON when possible and emits a common JSON envelope with a timestamp, normalized level, service, role, host, event, message, correlation
IDs, revision and cell identifiers, duration, and error code. Plain text is
retained with `parse_error=true`; messages are capped at 16 KiB and stack traces
at 64 KiB. Multiline exceptions are merged before normalization.

Only `environment`, `host`, `role`, `service`, and `level` are Loki labels, and
no structured metadata is configured, so the label list is exactly that set.
Domain, request, operation, job, task, edge, cell, revision, collector, stream,
path, and error values stay in the JSON body. Filter them with `| json`, for
example `{service="core"} |= "<request id>" | json | request_id="<request id>"`.

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

Administrators may set **Platform settings → Observability links → Grafana
Explore URL** to show the administrator-only **Live Logs** navigation item.
This optional PostgreSQL value overrides `GRAFANA_EXPLORE_URL`, which remains a
deployment fallback. The link opens Grafana in a new tab with the Loki
datasource, a bounded one-hour range, and the safe operational selector
`{environment=~"production|development"} | json` already populated. A fully
configured Explore URL with its own non-empty query is preserved. Leaving the
admin value and environment fallback both empty hides the item. The form accepts
only absolute HTTP or HTTPS URLs, and the link builder also rejects embedded
credentials. CDNFoundry never iframes Grafana or proxies Loki.

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
