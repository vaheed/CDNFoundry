---
title: Installation
description: Install CDNFoundry for local development or prepare a production deployment.
---

# Installation

::: danger Preserve development data
The named PostgreSQL and Compose volumes persist between phases. Never use
`docker compose down -v`, destructive database refreshes, or PostgreSQL-backed
`RefreshDatabase` tests. The supported test command forces in-memory SQLite.
:::

## Development requirements

- Docker Engine with the Compose plugin
- GNU Make
- Python 3 for real-runtime qualification
- at least 8 GiB RAM for the complete local topology
- free ports `8080`, `8081`, `8082`, `8444`, `8445`, `9443`, `9191`,
  `1053/tcp`, `1053/udp`, `9090`, and `9093`

The normal workflow builds PHP, Composer, Node, Go, PostgreSQL, Valkey,
PowerDNS, OpenResty, and ClickHouse dependencies inside containers.

## Install the development stack

```sh
# Clone the repository (development branch)
git clone --branch dev --depth 1 https://github.com/vaheed/CDNFoundry.git cdnfoundry
cd cdnfoundry
make dev-control-up
make dev-migrate
make dev-up
```

`make dev-control-up` starts only the services needed to run Laravel migrations.
After the explicit migration, `make dev-up` builds and starts the full topology,
including Grafana's read-only PostgreSQL role, development PKI, and GeoIP data.
Neither startup target runs Laravel or PowerDNS migrations implicitly.

Create the first administrator:

```sh
docker compose -f compose.dev.yml exec core \
  php artisan cdnf:admin:create \
  --name="Local Administrator" \
  --email="admin@example.test"
```

The command prompts twice for a password and rejects a duplicate email or
mismatch. Open `http://localhost:8080/admin`.

## Verify the installation

```sh
curl --fail http://localhost:8080/api/health
curl --fail http://localhost:8080/api/ready
docker compose -f compose.dev.yml ps
```

Run isolated application tests:

```sh
make dev-test
```

The target sets the only supported destructive-test database combination:
`APP_ENV=testing`, `DB_CONNECTION=sqlite`, and `DB_DATABASE=:memory:`.

Run non-browser real-runtime qualification only after the stack and migrations
are healthy:

```sh
make dev-e2e
```

This job uses real HTTP APIs, PostgreSQL, queues, DNSdist, PowerDNS, OpenResty,
mutual TLS, Pebble, Vector, and ClickHouse. It does not inspect the rendered UI.

## Enroll the bundled edge agents

The two OpenResty hosts run before the agents because edge IDs and one-time
tokens must come from administrator-created edge rows.

1. In **Edge network → Edges**, create edge A and edge B.
2. Copy the displayed UUID and bootstrap token for each.
3. Create the ignored local environment:

```sh
cp .env.dev.example .env.dev
chmod 600 .env.dev
```

1. Set `CDNF_DEV_EDGE_A_ID`, `CDNF_DEV_EDGE_A_BOOTSTRAP_TOKEN`,
   `CDNF_DEV_EDGE_B_ID`, and `CDNF_DEV_EDGE_B_BOOTSTRAP_TOKEN`.
1. Start and inspect the agents:

```sh
make dev-edge-up
make dev-edge-status
```

1. After both identities are registered and heartbeats are fresh, remove both
   bootstrap-token values from `.env.dev`.

Named agent volumes retain issued identities. Losing the volume requires the
administrator **Rotate identity** action and a new token.

## Production

Do not promote the development environment. Production uses published images,
host-private `.env.prod` files, explicit PKI, explicit Laravel and PowerDNS
migrations, firewalls, a tested recovery method (optionally the built-in
encrypted Restic integration), and the profiles described in
[Production deployment](../deployment/index.md).
