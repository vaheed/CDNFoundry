---
title: Installation
description: Start the CDNFoundry development stack with Docker Compose, create an administrator, and enroll the two local edges.
---

# Installation

This page starts a local development stack. You will build and run it with Docker
Compose, create the first administrator, and check that it works. It is for
trying and developing CDNFoundry. It is not a production install.

::: danger Keep development data
Named PostgreSQL and Compose volumes keep your data. Never run
`docker compose down -v`, destructive database refreshes, or `RefreshDatabase`
tests against PostgreSQL. The supported test command uses in-memory SQLite.
:::

## What you need

- Docker Engine with the Compose plugin
- GNU Make
- Python 3, for the real-runtime tests
- enough memory for the full local stack, which runs many containers
- these host ports free: `8080`, `8081`, `8082`, `8444`, `8445`, `9443`, `9191`,
  `1053/tcp`, `1053/udp`, `9090`, and `9093`

Docker builds PHP, Composer, Node, Go, PostgreSQL, Valkey, PowerDNS, OpenResty,
and ClickHouse inside containers. You install none of them on the host.

## Start the stack

```sh
git clone https://github.com/vaheed/CDNFoundry.git cdnfoundry
cd cdnfoundry
make dev-control-up
make dev-migrate
make dev-up
```

1. `make dev-control-up` starts only what Laravel migrations need.
2. `make dev-migrate` runs the Laravel migrations.
3. `make dev-up` builds and starts the full stack.

Neither start target runs migrations. You run them yourself. The dev stack also
has `make dev-pdns-migrate` (PowerDNS tables) and `make dev-clickhouse-migrate`
(ClickHouse tables) as separate steps.

**Expected result:** `docker compose -f compose.dev.yml ps` lists the services
as running or healthy.

## Create the first administrator

```sh
docker compose -f compose.dev.yml exec core \
  php artisan cdnf:admin:create \
  --name="Local Administrator" \
  --email="admin@example.test"
```

The command asks for a password twice. It refuses a duplicate email or a
password mismatch. **Expected result:** you can sign in at
`http://localhost:8080/admin`.

## Check the installation

```sh
curl --fail http://localhost:8080/api/health
curl --fail http://localhost:8080/api/ready
docker compose -f compose.dev.yml ps
```

`/api/health` returns `{"status":"ok"}`. `/api/ready` returns status `ready`, or
`503` with `not_ready` and the failed checks.

Run the isolated application tests:

```sh
make dev-test
```

This target forces `APP_ENV=testing`, `DB_CONNECTION=sqlite`, and
`DB_DATABASE=:memory:`, the only supported combination for destructive tests.

Run the non-browser real-runtime tests only when the stack is healthy and the
migrations are done:

```sh
make dev-e2e
```

They use real HTTP APIs, PostgreSQL, queues, DNSdist, PowerDNS, OpenResty, mutual
TLS, Pebble (a test certificate authority), Vector, and ClickHouse. They do not
check the rendered UI.

## Enroll the two local edges

The dev stack has two edges, A and B. Each edge agent needs an ID and a one-time
token from an edge row that an administrator creates first.

<div class="steps">

1. In **Infrastructure → Edges**, create edge A and edge B.
2. Copy the UUID and the one-time bootstrap token of each. The panel shows the
   token once.
3. Create the local environment file, which git ignores:

   ```sh
   cp .env.dev.example .env.dev
   chmod 600 .env.dev
   ```

4. In `.env.dev`, set `CDNF_DEV_EDGE_A_ID`, `CDNF_DEV_EDGE_A_BOOTSTRAP_TOKEN`,
   `CDNF_DEV_EDGE_B_ID`, and `CDNF_DEV_EDGE_B_BOOTSTRAP_TOKEN`.
5. Start the agents and check them:

   ```sh
   make dev-edge-up
   make dev-edge-status
   ```

6. When both edges are registered and their heartbeats are fresh, delete both
   bootstrap token values from `.env.dev`.

</div>

**Expected result:** both edges show fresh heartbeats in **Infrastructure → Edges**.

Named volumes keep each agent's identity. If you lose a volume, use **Rotate
identity** on the edge page. A one-time dialog shows the same ID and a new token.
Never copy one edge's identity volume to another edge.

## Production

Do not turn the development stack into production. Production uses published
images, private `.env.prod` files, explicit PKI, explicit Laravel and PowerDNS
migrations, firewalls, and a tested recovery method (the built-in encrypted Restic
backup is optional). Start with [Production deployment](../deployment/index.md).
