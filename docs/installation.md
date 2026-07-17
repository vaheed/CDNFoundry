# Installation

## Requirements

- Docker Engine with the Compose plugin
- GNU Make
- At least 8 GiB RAM for the complete development topology
- Available development ports 8080, 8081, 8082, 9191, 1053 TCP/UDP, 9090, and 9093

No host PHP, Composer, PostgreSQL, Valkey, PowerDNS, or ClickHouse installation is required.

## Development installation

```sh
git clone <repository> cdnfoundry
cd cdnfoundry
make dev-up
make dev-migrate
make dev-test
```

Create the first administrator inside the application container with `php artisan tinker` and `App\Models\User::create([...,'type' => 'admin'])`. Use a unique email and a password of at least 12 characters. The administrator panel is `/admin`; the domain-user panel is `/app`.

Application startup never runs migrations. Deploy schema changes explicitly with `make dev-migrate` or `make prod-migrate`.

## Production installation

Copy `.env.prod.example` to a host-private `.env.prod`, replace every placeholder, generate a Laravel `APP_KEY`, and retain that key with backups. Then run:

```sh
make prod-migrate
make prod-control
make prod-dns
make prod-telemetry
make prod-edge
```

Profiles can run on separate hosts with site-specific Compose overrides. Only web ingress, DNSdist, and edge listeners should be public.

## Upgrade

Back up PostgreSQL and encryption/signing keys, deploy additive migrations, start web/workers/scheduler independently, and remove old fields only in a later compatible release. Use `php artisan horizon:terminate` for graceful worker replacement.
