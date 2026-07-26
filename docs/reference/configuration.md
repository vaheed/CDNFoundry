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
[Platform settings](/reference/platform-settings).

## Control plane

| Variable | Required | Meaning and default |
| --- | --- | --- |
| `APP_KEY` | control | Laravel encryption key; required, retained with backups |
| `EDGE_ARTIFACT_SIGNING_KEY` | control | Independent high-entropy Ed25519 signing seed |
| `APP_URL` | control | Canonical public control-panel URL |
| `SESSION_SECURE_COOKIE` | HTTPS control | Secure-cookie flag; production default `true` |
| `CONTROL_BIND` | control | Host publication for web; default `127.0.0.1:8080` |
| `CONTROL_HOSTNAME` | control overlay | Public browser/API hostname |
| `TELEMETRY_HOSTNAME` | control overlay | Public telemetry-ingest hostname |
| `CONTROL_PUBLIC_IPV4_ALLOWLIST` | DNS API gateway | Exact control/worker sources allowed to call PowerDNS |
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
| `METRICS_TOKEN_FILE` | control/telemetry | Absolute mode-`0600` bearer-token file |

The Compose file fixes `APP_ENV=production`, `APP_DEBUG=false`,
`DB_CONNECTION=pgsql`, database/user `cdnf`, `CACHE_STORE=redis`,
`SESSION_DRIVER=redis`, `QUEUE_CONNECTION=redis`, `REDIS_CLIENT=predis`,
`CLICKHOUSE_DATABASE=cdnf`, `CLICKHOUSE_USER=cdnf`, and
`GEOIP_DATABASE=/mmdb/GeoLite2-City.mmdb`.

## Backup

| Variable | Required | Meaning and default |
| --- | --- | --- |
| `RESTIC_REPOSITORY` | control | Encrypted off-host Restic repository |
| `RESTIC_PASSWORD_FILE` | control | Absolute password-file path |
| `BACKUP_ACCESS_KEY_ID` | S3 repository | Prefix-scoped access key |
| `BACKUP_SECRET_ACCESS_KEY` | S3 repository | Prefix-scoped secret |
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

## Authoritative DNS

| Variable | Required | Meaning and default |
| --- | --- | --- |
| `PDNS_DB_PASSWORD` | DNS | PowerDNS PostgreSQL password |
| `PDNS_API_KEY` | DNS | Private PowerDNS API credential |
| `DNS_BIND_V4` | DNS | DNSdist IPv4 publication; default `0.0.0.0` |
| `PDNS_CA_CERTIFICATE` | control worker | Trust anchor for HTTPS PowerDNS API gateways |
| `DNS_API_HOSTNAME` | DNS overlay | DNS API TLS hostname |
| `DNS_API_SERVER_CERTIFICATE` | DNS overlay | Absolute server certificate path |
| `DNS_API_SERVER_PRIVATE_KEY` | DNS overlay | Absolute mode-`0600` key path |

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

Vector receives `CLICKHOUSE_ENDPOINT`, `CLICKHOUSE_USER`, and
`CLICKHOUSE_PASSWORD` from Compose. `MMDB_DIR` is an internal updater override
whose default is `/mmdb`.

## Images and host publication

| Variable | Required | Meaning and default |
| --- | --- | --- |
| `CDNF_RELEASE` | every production host | Exact commit SHA or exact release tag |
| `PUBLIC_BIND_IPV4` | multi-host overlay | Exact public IPv4 owned by the host |
| `PUBLIC_BIND_IPV6` | IPv6 overlay | Exact public IPv6; omit the overlay when absent |
| `EDGE_HTTP_BIND` | edge | Shared-cell HTTP, default `0.0.0.0:80` |
| `EDGE_HTTPS_BIND` | edge | Shared-cell HTTPS, default `0.0.0.0:443` |
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
| `EDGE_ONCE` | `false` | Run one sync cycle for diagnostics |

The OpenResty container receives `EDGE_CELL_NAME`, `EDGE_RUNTIME_FILE`, and
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
