---
title: Developer setup
description: Set up the supported CDNFoundry development environment and workflow.
---

# Developer setup

Docker Compose is the supported development environment. Host PHP, Composer,
PostgreSQL, Valkey, PowerDNS, ClickHouse, and Node.js are not required for the
normal workflow. GNU Make, Docker Engine, the Compose plugin, and Python 3 are
required for the documented commands.

```sh
git clone https://github.com/vaheed/CDNFoundry.git cdnfoundry
cd cdnfoundry
make dev-control-up
make dev-migrate
make dev-up
make dev-test
```

`make dev-control-up` starts the control database, Valkey, Laravel, and the web
gateway. Run migrations explicitly before `make dev-up` starts the full
topology, because Grafana's read-only database grants target migrated tables.
The supported test target forces `APP_ENV=testing`, SQLite `:memory:`, array
cache, and synchronous queues before any Laravel test may migrate data.

::: info Persistent storage initialization
The shared Laravel storage volume disables Docker's automatic image copy-up.
The application entrypoint creates its runtime directories instead, preventing
parallel `core`, `horizon`, and `scheduler` startup from racing on a new volume.
Existing volume contents are retained across starts and rebuilds.
:::

The development URLs are:

| Service | URL or address |
| --- | --- |
| Domain panel | `http://localhost:8080/app` |
| Administrator panel | `http://localhost:8080/admin` |
| Horizon | `http://localhost:8080/horizon` |
| DNSdist | `127.0.0.1:1053` over UDP and TCP |
| Shared edge A/B HTTP | `http://localhost:8081`, `http://localhost:8082` |
| Shared edge A/B HTTPS | `https://localhost:8444`, `https://localhost:8445` |
| Edge control | `https://localhost:9443` |
| Prometheus | `http://localhost:9090` |
| Alertmanager | `http://localhost:9093` |
| PowerAdmin diagnostic UI | `http://localhost:9191` |

Create the first administrator with the interactive command, which avoids
putting a password in shell history:

```sh
docker compose -f compose.dev.yml exec core \
  php artisan cdnf:admin:create --name="Local Administrator" --email="admin@example.test"
```

Continue with [Testing](testing.md), [Project layout](project-layout.md),
[Frontend](frontend.md), and [Scripts and CI](scripts-and-ci.md).
