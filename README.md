# CDNFoundry

CDNFoundry is a production-oriented private CDN with a Laravel/Filament control
plane, PowerDNS and DNSdist authoritative DNS, bounded OpenResty edge cells,
managed and custom TLS, cache and purge, security controls, and direct
Vector-to-ClickHouse telemetry.

PostgreSQL owns desired state. DNS and HTTP traffic do not pass through Laravel.
External changes are asynchronous, revisioned, idempotent, and preserve the
previous valid runtime state.

## Start here

- [Full documentation](docs/index.md)
- [Development installation](docs/getting-started/installation.md)
- [Production deployment](docs/deployment/index.md)
- [Architecture](docs/architecture/index.md)
- [API reference](docs/reference/api/index.md)
- [Operations](docs/operations/index.md)
- [Roadmap and status](docs/roadmap.md)

For a local stack:

```sh
make dev-up
make dev-migrate
make dev-test
```

Create the first administrator with the interactive command:

```sh
docker compose -f compose.dev.yml exec core \
  php artisan cdnf:admin:create \
  --name="Local Administrator" \
  --email="admin@example.test"
```

The administrator panel is `http://localhost:8080/admin`; the domain-user panel
is `http://localhost:8080/app`.

## Project policy

Read [AGENTS.md](AGENTS.md) before changing behaviour. Browser qualification is
manual and owner run; non-browser real-runtime tests live under `tests/e2e`.
Never remove persistent development volumes or run destructive Laravel tests
against PostgreSQL.

See [Contributing](CONTRIBUTING.md), [Security](SECURITY.md), and the
[Code of conduct](CODE_OF_CONDUCT.md).
