---
title: Developer setup
description: Start the Docker Compose development stack, run migrations and tests, find the local URLs, and create the first administrator.
---

# Developer setup

Docker Compose is the supported development environment. You do not need host
PHP, Composer, PostgreSQL, Valkey, PowerDNS, ClickHouse, or Node.js for the normal
workflow. You need GNU Make, Docker Engine, the Compose plugin, and Python 3.

## Start the stack

```sh
git clone https://github.com/vaheed/CDNFoundry.git cdnfoundry
cd cdnfoundry
make dev-control-up
make dev-migrate
make dev-up
make dev-test
```

`make dev-control-up` starts the control database, Valkey, Laravel, and the web
gateway. Run migrations before `make dev-up` starts the full topology, because
Grafana's read-only database grants target migrated tables.

**Expected result:** `docker compose -f compose.dev.yml ps` lists the services as
running or healthy, and `http://localhost:8080/app` loads.

`make dev-test` forces `APP_ENV=testing`, SQLite `:memory:`, array cache, and
synchronous queues before any Laravel test may migrate data. Never run Laravel
tests against the development PostgreSQL.

::: info Persistent storage initialization
The shared Laravel storage volume turns off Docker's automatic image copy-up. The
application entrypoint creates its runtime directories instead. This stops `core`,
`horizon`, and `scheduler` from racing on a new volume. Existing volume contents
stay across starts and rebuilds.
:::

## Local addresses

| Service | URL or address |
| --- | --- |
| Domain panel | `http://localhost:8080/app` |
| Administrator panel | `http://localhost:8080/admin` |
| Horizon | `http://localhost:8080/horizon` |
| DNSdist | port `1053` over UDP and TCP |
| Shared edge A/B HTTP | `http://localhost:8081`, `http://localhost:8082` |
| Shared edge A/B HTTPS | `https://localhost:8444`, `https://localhost:8445` |
| Edge control | `https://localhost:9443` (bound to `127.0.0.1` by default) |
| Prometheus | `http://localhost:9090` |
| Alertmanager | `http://localhost:9093` |
| PowerAdmin diagnostic UI | `http://localhost:9191` |

## Create the first administrator

The command prompts for the password, so it stays out of your shell history:

```sh
docker compose -f compose.dev.yml exec core \
  php artisan cdnf:admin:create --name="Local Administrator" --email="admin@example.test"
```

**Expected result:** you can sign in at `http://localhost:8080/admin`.

Continue with [Testing](testing.md), [Project layout](project-layout.md),
[Frontend](frontend.md), [Scripts and CI](scripts-and-ci.md), and
[Coding agents](coding-agents.md).
