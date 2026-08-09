---
title: Configuration reference
description: Reference every CDNFoundry deployment, edge-agent, development, and documentation environment variable.
---

# Configuration reference

::: danger Environment files contain production secrets
Keep `.env.prod` mode `0600`, outside version control, and accessible only to
the owning host administrators. Share individual cross-host values through a
protected channel, never by copying the whole file.
:::

Use `.env.prod.example` as the production key inventory. Create `.env.prod` with
`scripts/generate-production-env.sh` or copy the template, keep it mode `0600`,
and never commit it. A variable is required only on hosts running its owning
profile.

Runtime product policy is not an environment variable. Manage it through
[Platform settings](platform-settings.md).

## Control plane

| Variable | Required | Meaning and default |
| --- | --- | --- |
| `APP_KEY` | control | Laravel encryption key; required, retained with backups |
| `EDGE_ARTIFACT_SIGNING_KEY` | control | Independent high-entropy Ed25519 signing seed |
| `APP_URL` | control | Canonical public control-panel URL |
| `SESSION_SECURE_COOKIE` | HTTPS control | Secure-cookie flag; production default `true` |
| `CONTROL_BIND` | control | Host publication for web; default `127.0.0.1:8080` |
| `CONTROL_HOSTNAME` | control | Public browser/API hostname in independent management DNS |
| `TELEMETRY_HOSTNAME` | control/telemetry | Public telemetry-ingest hostname in independent management DNS |
| `CONTROL_PUBLIC_IPV4_ALLOWLIST` | DNS API gateway | Exact control/worker sources allowed to call PowerDNS |
| `CONTROL_PUBLIC_IPV6_ALLOWLIST` | DNS API gateway | Optional IPv6 control/worker sources allowed to call PowerDNS |
| `EDGE_PUBLIC_IPV6_ALLOWLIST` | telemetry gateway | Optional IPv6 edge sources allowed to submit telemetry |
| `LOG_SOURCE_IPV6_ALLOWLIST` | telemetry gateway | Optional IPv6 node sources allowed to submit operational logs |
| `EDGE_PUBLIC_IPV4_ALLOWLIST` | telemetry gateway | Exact edge/Vector ingestion sources |
| `CONTROL_DB_PASSWORD` | local control DB | PostgreSQL password for database `cdnf` |
| `REDIS_PASSWORD` | local Valkey | Required Valkey password |
| `DB_URL` | external DB | Full PostgreSQL URL; empty uses individual DB fields |
| `DB_HOST` | control | Default `control-db` |
| `DB_PORT` | control | Default `5432` |
| `DB_SSLMODE` | control | Default `prefer`; use verified TLS across hosts |
| `REDIS_URL` | external Valkey | Full URL; empty uses host and port |
| `REDIS_HOST` | control | Default `redis` |
| `REDIS_PORT` | control | Default `6379` |
| `METRICS_TOKEN_FILE` | control/telemetry | Absolute root:`www-data` mode-`0640` bearer-token file shared with Prometheus group `82` |

The Compose file fixes `APP_ENV=production`, `APP_DEBUG=false`,
`DB_CONNECTION=pgsql`, database/user `cdnf`, `CACHE_STORE=redis`,
`SESSION_DRIVER=redis`, `QUEUE_CONNECTION=redis`, `REDIS_CLIENT=predis`,
`CLICKHOUSE_DATABASE=cdnf`, `CLICKHOUSE_USER=cdnf`, and
`GEOIP_DATABASE=/mmdb/GeoLite2-City.mmdb`.

## Backup (optional)

Leave `RESTIC_REPOSITORY`, `RESTIC_PASSWORD_FILE`, and both credential values
empty to disable the built-in backup integration. The control plane still
starts, while backup creation returns an explicit unavailable error and backup
health remains degraded. The supported generator path uses S3-compatible
object storage; other Restic backends need their own credential/mount wiring.

| Variable | Required | Meaning and default |
| --- | --- | --- |
| `RESTIC_REPOSITORY` | optional control | Restic storage location, such as `s3:https://endpoint/bucket/prefix`; empty disables built-in backups |
| `RESTIC_PASSWORD_FILE` | configured backup | Absolute file containing the separate Restic encryption password |
| `BACKUP_ACCESS_KEY_ID` | configured S3 backup | Bucket/prefix-scoped access key |
| `BACKUP_SECRET_ACCESS_KEY` | configured S3 backup | Bucket/prefix-scoped secret |
| `BACKUP_DEFAULT_REGION` | S3 repository | Default `us-east-1` |
| `BACKUP_RESTORE_ALLOWED` | restore executor | Must be true in explicit maintenance context; default false |

## Managed TLS

| Variable | Required | Meaning and default |
| --- | --- | --- |
| `ACME_ENABLED` | control | Compose sets true |
| `ACME_CONTACT_EMAIL` | control | ACME account contact |
| `ACME_DIRECTORY_URL` | control | Let's Encrypt production directory by default |
| `ACME_ORDER_BUDGET_PER_HOUR` | control | New-order ceiling; default `20` |
| `ACME_VERIFY_TLS` | optional | Verify ACME directory TLS; default true, false only for local Pebble |
| `ACME_RENEW_BEFORE_DAYS` | optional | Renewal window; default `30` |
| `ACME_DNS_TTL` | optional | Challenge TTL; default `60` |
| `ACME_CHALLENGE_LIFETIME_MINUTES` | optional | Challenge expiry; default `120` |
| `ACME_INITIAL_JITTER_SECONDS` | optional | Initial spread; default `300` |
| `TLS_EXPIRY_ALERT_DAYS` | optional | Administrator expiry warning; default `14` |

## Managed WAF

| Variable | Required | Meaning and default |
| --- | --- | --- |
| `WAF_RULESET_VERSION` | optional control-plane label | Immutable WAF runtime identifier recorded in signed snapshots and telemetry; default `owasp-crs/4.26.0-modsecurity/3.0.14`. Change it only with the matching pinned edge image rollout. |

## Authoritative DNS

| Variable | Required | Meaning and default |
| --- | --- | --- |
| `PDNS_DB_PASSWORD` | DNS | PowerDNS PostgreSQL password |
| `PDNS_API_KEY` | DNS | Private PowerDNS API credential |
| `DNS_BIND_V4` | DNS | DNSdist IPv4 publication; default `0.0.0.0` |
| `PDNS_CA_CERTIFICATE` | control worker | Trust anchor for HTTPS PowerDNS API gateways |
| `EDGE_GATEWAY_BINDINGS` | edge agent | Optional rollout override. When absent, the agent fetches the bounded, revisioned edge/pool endpoint candidate over mTLS; when set, this static JSON remains authoritative. |
| `EDGE_CELL_TARGETS` | edge agent | Optional development-only JSON map from stable cell names to private container HTTP/HTTPS endpoints; production host-network cells use control-plane loopback targets |
| `EDGE_GATEWAY_ADDRESSES` | edge agent | Optional development-only JSON array of at most two gateway listener IPs; never set in production |
| `EDGE_GATEWAY_ADDRESS_MAP` | production edge agent | Exact JSON object mapping every advertised service IPv4/IPv6 address to its distinct private listener address. Both sides use the same family; public, wildcard, and duplicate local values fail closed. Default `{}` permits startup before endpoints exist. |
| `EDGE_GATEWAY_REQUIRE_ADDRESS_MAP` | production edge agent | Production Compose fixes this to `true`; every fetched endpoint must have a local mapping before activation. Do not override it. |
| `EDGE_GATEWAY_STATUS_URL` | edge agent | Gateway metrics URL used for heartbeat readiness |
| `EDGE_GATEWAY_METRICS_ADDRESS` | edge gateway | Restricted metrics listener; production default `0.0.0.0:9105` |
| `EDGE_GATEWAY_MAX_CONNECTIONS` | edge gateway | Global accepted-connection bound, `128`–`65536` (default `8192`) |
| `DNS_API_HOSTNAME` | DNS | DNS API TLS hostname in independent management DNS |
| `DNS_API_SERVER_CERTIFICATE` | DNS | Absolute server certificate path |
| `DNS_API_SERVER_PRIVATE_KEY` | DNS | Absolute mode-`0600` key path |

## Telemetry and GeoIP

| Variable | Required | Meaning and default |
| --- | --- | --- |
| `CLICKHOUSE_PASSWORD` | telemetry/control query | ClickHouse password |
| `CLICKHOUSE_URL` | telemetry/control query | Local `http://clickhouse:8123` or verified external endpoint |
| `CLICKHOUSE_DATABASE` | internal/default | Database queried by Laravel; Compose fixes `cdnf` |
| `GEOIP_DATABASE` | edge runtime | MMDB file path; Compose fixes `/mmdb/GeoLite2-City.mmdb` |
| `PROMETHEUS_URL` | optional control | Default `http://prometheus:9090` |
| `VECTOR_METRICS_URL` | optional control | Vector metrics probe URL when overridden |
| `METRICS_TOKEN` | optional control | Direct metrics bearer token fallback; prefer `METRICS_TOKEN_FILE` |
| `MMDB_STALE_HOURS` | optional control | Health threshold; default `48` |
| `MMDB_PROVIDER` | updater | `dbip-jsdelivr` default, `dbip-official`, `ip66`, or `generic` |
| `MMDB_TARGET_FILE` | updater | Default `GeoLite2-City.mmdb` |
| `MMDB_DOWNLOAD_INTERVAL_SECONDS` | updater | Default `86400`, clamped to at least 300 |
| `MMDB_DOWNLOAD_RETRIES` | updater | Default `5` |
| `MMDB_EXPECTED_SHA256` | updater | Optional lowercase candidate checksum |
| `MMDB_DOWNLOAD_URL` | custom updater | HTTPS artifact URL |
| `MMDB_DOWNLOAD_HEADER` | custom updater | Optional authorization header; treat as secret |

Grafana telemetry variables are:

| Variable | Required | Meaning and default |
| --- | --- | --- |
| `GRAFANA_ADMIN_USER` | telemetry | Initial administrator name; default `admin` |
| `GRAFANA_ADMIN_PASSWORD` | telemetry | High-entropy administrator password; no production default |
| `GRAFANA_BIND` | telemetry | Host bind; default `127.0.0.1:3000` |
| `GRAFANA_COOKIE_SECURE` | HTTPS telemetry | Secure-cookie flag; default `true` |
| `GRAFANA_CLICKHOUSE_PASSWORD` | telemetry | Dedicated read-only ClickHouse password; no default |
| `GRAFANA_CLICKHOUSE_HOST`, `GRAFANA_CLICKHOUSE_PORT` | telemetry | Local `clickhouse:9000` or external query endpoint |
| `GRAFANA_CLICKHOUSE_PROTOCOL`, `GRAFANA_CLICKHOUSE_SECURE` | telemetry | `native`/`false` locally; use provider-supported verified TLS externally |
| `GRAFANA_CLICKHOUSE_USER` | telemetry | Default `cdnf_grafana` |
| `GRAFANA_POSTGRES_PASSWORD` | telemetry | Dedicated read-only PostgreSQL password; no default |
| `GRAFANA_POSTGRES_HOST`, `GRAFANA_POSTGRES_PORT` | telemetry | Local `control-db:5432` or external endpoint |
| `GRAFANA_POSTGRES_DATABASE`, `GRAFANA_POSTGRES_USER` | telemetry | Defaults `cdnf`, `cdnf_grafana` |
| `GRAFANA_POSTGRES_SSLMODE` | telemetry | Default `disable` for embedded private PostgreSQL; set `require` or `verify-full` for external endpoints |
| `GRAFANA_POSTGRES_PROVISION_HOST`, `GRAFANA_POSTGRES_PROVISION_PORT` | local account provisioning | Privileged endpoint; external operators may apply the SQL separately |
| `PROMETHEUS_EDGE_TARGETS_FILE` | telemetry | Private file_sd target file; production default is empty |
| `PROMETHEUS_LOG_TARGETS_FILE` | telemetry | Private file_sd targets for remote collector metrics; production default is empty |
| `PROMETHEUS_CONTROL_TARGETS_FILE` | telemetry | Generated file_sd targets for authenticated control-plane metrics |
| `PROMETHEUS_NODE_TARGETS_FILE` | telemetry | Generated file_sd targets for node-exporter metrics on every fleet host |
| `PROMETHEUS_DNS_TARGETS_FILE` | telemetry | Generated file_sd targets for DNSdist metrics on DNS hosts |
| `GRAFANA_EXPLORE_URL` | control | Optional deployment fallback for the admin-only Live Logs link. The PostgreSQL-backed **Platform settings → Observability links → Grafana Explore URL** overrides it. Laravel supplies Loki, a safe selector, and a one-hour range when the chosen URL has no query; both empty hides the link |
| `GRAFANA_HOSTNAME` | control/telemetry | Public Grafana hostname in the independently hosted operator DNS zone |
| `GRAFANA_LOKI_URL` | telemetry | Private Grafana-to-Loki endpoint; default `http://loki:3100` |
| `LOKI_RETENTION_PERIOD` | telemetry | Loki retention; production default `336h` |
| `LOKI_MAX_QUERY_LENGTH` | telemetry | Maximum query range; production default `336h` |
| `LOKI_ENDPOINT` | logs | Collector push endpoint; use the source-restricted HTTPS telemetry gateway off-host |
| `LOG_ROLE` | logs | Stable host role: `control`, `dns`, `edge`, or `telemetry` |
| `LOG_HOST` | logs | Stable deployment host name |
| `LOG_COLLECTOR_ID` | logs | Globally unique stable collector identity |
| `LOG_AUTH_TOKEN` | logs | Secret bearer credential used by the per-host Vector collector when pushing to the source-restricted Loki gateway |
| `LOG_BUFFER_BYTES` | logs | Per-host disk-buffer bytes; production default `2147483648` |
| `LOG_METRICS_BIND` | logs | Host metrics publication; generated bundles use `monitor_ipv4` or the local `bind_ipv4`, never an advertised NAT-only address |
| `LOG_SOURCE_IPV4_ALLOWLIST` | telemetry gateway | Exact non-edge host sources allowed to push logs |

Vector receives `CLICKHOUSE_ENDPOINT`, `CLICKHOUSE_USER`, and
`CLICKHOUSE_PASSWORD` from Compose. `MMDB_DIR` is an internal updater override
whose default is `/mmdb`.

Laravel containers fix `LOG_CHANNEL=stderr`, `LOG_STACK=stderr`, and the
allowlisting JSON formatter. Changing these back to a private file would bypass
the supported host collector.

## Images and host publication

| Variable | Required | Meaning and default |
| --- | --- | --- |
| `CDNF_RELEASE` | every production host | Exact commit SHA or exact release tag |
| `CDNF_CORE_IMAGE` | production control/edge support | Immutable core image reference from the release manifest |
| `CDNF_WEB_IMAGE` | production control | Immutable web image reference from the release manifest |
| `CDNF_EDGE_CONTROL_IMAGE` | production control | Immutable edge-control ingress image reference from the release manifest |
| `CDNF_EDGE_RUNTIME_IMAGE` | production edge | Immutable OpenResty runtime image reference from the release manifest |
| `CDNF_EDGE_AGENT_IMAGE` | production edge | Immutable edge-agent image reference from the release manifest |
| `CDNF_EDGE_GATEWAY_IMAGE` | production edge | Immutable edge-gateway image reference from the release manifest |
| `CDNF_MMDB_UPDATER_IMAGE` | production hosts using GeoIP | Immutable MMDB updater image reference from the release manifest |
| `CDNF_GRAFANA_IMAGE` | production telemetry | Immutable provisioned Grafana image reference from the release manifest |
| `CDNF_LOKI_IMAGE` | production telemetry | Immutable Loki image reference from the release manifest |
| `HOST_BIND_IPV4` | generated multi-host bundle | Local listener address; default `0.0.0.0`, independent of public/NAT DNS addresses |
| `HOST_BIND_IPV6` | generated dual-stack bundle | Local IPv6 listener; default `::`; publish only after end-to-end IPv6 qualification |
| `EDGE_QUARANTINE_HTTP_BIND` | edge | Quarantine HTTP, default `127.0.0.1:18080` |
| `EDGE_QUARANTINE_HTTPS_BIND` | edge | Quarantine HTTPS, default `127.0.0.1:18443` |
| `EDGE_RUNTIME_TLS_CERTIFICATE` | edge | Bootstrap listener certificate path |
| `EDGE_RUNTIME_TLS_PRIVATE_KEY` | edge | Bootstrap listener key path |

## Edge control and identity

| Variable | Required | Meaning and default |
| --- | --- | --- |
| `EDGE_CONTROL_URL` | edge agent | Public mutual-TLS control URL |
| `EDGE_CONTROL_BIND` | control | Listener publication, default `0.0.0.0:8443` |
| `EDGE_CONTROL_SERVER_CERTIFICATE` | control | Edge-control server certificate path |
| `EDGE_CONTROL_SERVER_PRIVATE_KEY` | control | Edge-control private key path |
| `EDGE_CONTROL_CA_CERTIFICATE` | edge agent | Server trust anchor |
| `EDGE_IDENTITY_CA_CERTIFICATE` | control/edge-control | Client identity CA certificate |
| `EDGE_IDENTITY_CA_PRIVATE_KEY` | core | Restricted worker-readable CA key |
| `EDGE_IDENTITY_CA_PRIVATE_KEY_PASSPHRASE` | optional core | CA-key passphrase |
| `EDGE_STATUS_TOKEN` | edge host | Separate agent-to-cell control token |
| `EDGE_ID` | first enrollment | Administrator-created edge UUID |
| `EDGE_BOOTSTRAP_TOKEN` | first enrollment | One-time secret; remove after registration |

The edge-agent binary also accepts these internal variables:

| Variable | Default | Purpose |
| --- | --- | --- |
| `EDGE_STATE_DIR` | `/var/lib/cdnfoundry/agent` | Persistent identity, state, controls, acknowledgements |
| `EDGE_RUNTIME_DIR` | empty | Active and previous compiled runtime directories |
| `EDGE_CELL_STATUS_URLS` | empty | Comma-separated internal cell endpoints |
| `EDGE_CELL_ASSIGNMENTS` | `{}` | JSON object mapping at most 32 stable `cell-NN` names to a pool name or an empty unassigned value |
| `EDGE_RUNTIME_VERSIONS` | `{}` | JSON object containing the four immutable gateway, agent, normal-cell, and WAF-cell image digests reported after a fixed installer upgrade |
| `EDGE_ONCE` | `false` | Run one sync cycle for diagnostics |

Production fixes `EDGE_CELL_ASSIGNMENTS` to eight stable slots. The OpenResty container receives `EDGE_CELL_NAME`, `EDGE_RUNTIME_FILE`, and
`EDGE_STATUS_TOKEN` from Compose. These describe a cell and are not customer
settings.

## Development-only environment

`.env.dev` supports:

| Variable | Purpose |
| --- | --- |
| `CDNF_DEV_EDGE_A_ID`, `CDNF_DEV_EDGE_B_ID` | Created edge UUIDs |
| `CDNF_DEV_EDGE_A_BOOTSTRAP_TOKEN`, `CDNF_DEV_EDGE_B_BOOTSTRAP_TOKEN` | One-time tokens |
| `CDNF_DEV_EDGE_STATUS_TOKEN` | Shared development agent/cell token |
| `CDNF_DEV_EDGE_CONTROL_BIND` | Optional host override for port 9443 |
| `POWERADMIN_ADMIN_USERNAME` | PowerAdmin diagnostic login; default `admin` |
| `POWERADMIN_ADMIN_PASSWORD` | PowerAdmin diagnostic password; development-only default |

The development Compose file contains public test credentials. Never copy them
to production.

## Documentation build

| Variable | Default | Purpose |
| --- | --- | --- |
| `DOCS_SITE_URL` | `https://vaheed.github.io/CDNFoundry` | Canonical and sitemap origin |
| `DOCS_BASE` | `/CDNFoundry/` | VitePress deployment base path |

Set both when deploying the static site under a different origin or path.

## Laravel framework variables

The application retains conventional Laravel configuration keys in
`core/config/`: `APP_NAME`, `APP_ENV`, `APP_KEY`, `APP_DEBUG`, `APP_URL`,
`APP_LOCALE`, `APP_FALLBACK_LOCALE`, `APP_FAKER_LOCALE`, `APP_PREVIOUS_KEYS`,
`APP_MAINTENANCE_DRIVER`, and `APP_MAINTENANCE_STORE`.

Database drivers additionally accept `DB_CONNECTION`, `DB_URL`, `DB_HOST`,
`DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`, `DB_SOCKET`,
`DB_CHARSET`, `DB_COLLATION`, `DB_FOREIGN_KEYS`, `DB_ENCRYPT`,
`DB_TRUST_SERVER_CERTIFICATE`, `DB_CONNECT_TIMEOUT`, `MYSQL_ATTR_SSL_CA`, and
the documented `DB_SSLMODE`.

Cache, queue, Redis, and session drivers accept their standard keys:
`CACHE_STORE`, `CACHE_PREFIX`, `DB_CACHE_CONNECTION`, `DB_CACHE_TABLE`,
`DB_CACHE_LOCK_CONNECTION`, `DB_CACHE_LOCK_TABLE`, `DYNAMODB_CACHE_TABLE`,
`DYNAMODB_ENDPOINT`, `MEMCACHED_HOST`, `MEMCACHED_PORT`,
`MEMCACHED_USERNAME`, `MEMCACHED_PASSWORD`, `MEMCACHED_PERSISTENT_ID`,
`QUEUE_CONNECTION`, `QUEUE_FAILED_DRIVER`, `DB_QUEUE_CONNECTION`,
`DB_QUEUE_TABLE`, `DB_QUEUE`, `DB_QUEUE_RETRY_AFTER`, `BEANSTALKD_QUEUE_HOST`,
`BEANSTALKD_QUEUE`, `BEANSTALKD_QUEUE_RETRY_AFTER`, `SQS_PREFIX`, `SQS_QUEUE`,
`SQS_SUFFIX`, `REDIS_CLIENT`, `REDIS_URL`, `REDIS_HOST`, `REDIS_USERNAME`,
`REDIS_PASSWORD`, `REDIS_PORT`, `REDIS_DB`, `REDIS_CACHE_DB`, `REDIS_PREFIX`,
`REDIS_PERSISTENT`, `REDIS_CLUSTER`, `REDIS_MAX_RETRIES`,
`REDIS_BACKOFF_ALGORITHM`, `REDIS_BACKOFF_BASE`, `REDIS_BACKOFF_CAP`,
`REDIS_CONNECT_TIMEOUT`, `REDIS_READ_TIMEOUT`, `REDIS_QUEUE_CONNECTION`,
`REDIS_QUEUE`, `REDIS_QUEUE_RETRY_AFTER`, `REDIS_CACHE_CONNECTION`,
`REDIS_CACHE_LOCK_CONNECTION`, `SESSION_DRIVER`, `SESSION_LIFETIME`,
`SESSION_EXPIRE_ON_CLOSE`, `SESSION_ENCRYPT`, `SESSION_PATH`,
`SESSION_DOMAIN`, `SESSION_SECURE_COOKIE`, `SESSION_HTTP_ONLY`,
`SESSION_SAME_SITE`, `SESSION_PARTITIONED_COOKIE`, `SESSION_CONNECTION`,
`SESSION_TABLE`, and `SESSION_STORE`.

Mail/logging/storage/auth integrations retain the keys declared in their
Laravel config files: `FILESYSTEM_DISK`, `CACHE_STORAGE_DISK`,
`CACHE_STORAGE_PATH`, `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`,
`AWS_DEFAULT_REGION`, `AWS_BUCKET`, `AWS_URL`, `AWS_ENDPOINT`,
`AWS_USE_PATH_STYLE_ENDPOINT`, `MAIL_MAILER`, `MAIL_URL`, `MAIL_HOST`,
`MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_SCHEME`,
`MAIL_SENDMAIL_PATH`, `MAIL_EHLO_DOMAIN`, `MAIL_FROM_ADDRESS`,
`MAIL_FROM_NAME`, `MAIL_LOG_CHANNEL`, `POSTMARK_API_KEY`,
`POSTMARK_MESSAGE_STREAM_ID`, `RESEND_API_KEY`, `SLACK_BOT_USER_OAUTH_TOKEN`,
`SLACK_BOT_USER_DEFAULT_CHANNEL`, `LOG_CHANNEL`, `LOG_STACK`, `LOG_LEVEL`,
`LOG_DAILY_DAYS`, `LOG_DEPRECATIONS_CHANNEL`, `LOG_DEPRECATIONS_TRACE`,
`LOG_STDERR_FORMATTER`, `LOG_SYSLOG_FACILITY`, `PAPERTRAIL_URL`,
`PAPERTRAIL_PORT`, `LOG_PAPERTRAIL_HANDLER`, `LOG_SLACK_WEBHOOK_URL`,
`LOG_SLACK_USERNAME`, `LOG_SLACK_EMOJI`, `AUTH_GUARD`, `AUTH_MODEL`,
`AUTH_PASSWORD_BROKER`, `AUTH_PASSWORD_RESET_TOKEN_TABLE`,
`AUTH_PASSWORD_TIMEOUT`, `SANCTUM_STATEFUL_DOMAINS`, and
`SANCTUM_TOKEN_PREFIX`.

Horizon accepts `HORIZON_NAME`, `HORIZON_DOMAIN`, and `HORIZON_PATH`. Production
Compose intentionally fixes the supported drivers and does not expose most
framework alternatives in `.env.prod.example`.
