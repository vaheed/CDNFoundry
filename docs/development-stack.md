# Development stack

The supported development runtime is Docker Compose. Host PHP, Composer,
PostgreSQL, Valkey, and web servers are not part of the workflow.
Compose supplies a fixed development-only `APP_KEY` so encrypted model fields
work in a clean checkout. It is intentionally public test material and must
never be copied to production, where `APP_KEY` is a required host secret.

Start the full stack and run the explicit database deployment:

```sh
make dev-up
make dev-migrate
```

`make dev-up` first builds the shared production Filament theme into the ignored `core/public/build` directory through Docker, so a clean checkout never depends on a host Node installation or a Vite development server. Run `make dev-assets` by itself after a UI-only edit when the existing containers do not need rebuilding.

Run tests inside the same application image:

```sh
make dev-test
```

Run the Python non-UI end-to-end qualification against the real stack:

```sh
make dev-e2e
```

This cumulative job exercises Phases 1–4 through HTTP APIs, authorization, idempotency, queues, persisted operations, real PowerDNS/DNSdist answers, Geo-DNS, mTLS edge control, pool migration, and the generic OpenResty runtime. Run the heavier 500,000-zone/1,000,000-record qualification separately with `make dev-scale-e2e`. Browser/UI acceptance remains the project owner's manual checklist.

Hosted scale qualification starts only the bounded control-plane dependencies with `make dev-scale-up`; it does not make an unrelated MMDB download or start DNS, telemetry, origin, and edge services. The full developer stack remains `make dev-up`.

The control panel is exposed at `http://localhost:8080`, Horizon at
`http://localhost:8080/horizon`, DNSdist at TCP/UDP port `1053`, the two shared
edge HTTP listeners at ports `8081` and `8082`, their HTTPS listeners at `8444`
and `8445`, the mTLS edge-control listener at `9443`, Prometheus at `9090`,
Alertmanager at `9093`, and development-only PowerAdmin at
`http://localhost:9191`. `make dev-up`
enables the `devtools` profile so PowerAdmin starts with the stack. Its default
development login is `admin` / `poweradmin-dev-only`; override it with
`POWERADMIN_ADMIN_USERNAME` and `POWERADMIN_ADMIN_PASSWORD` before first start.
On a fresh MMDB volume, Compose waits for the updater to activate a validated
GeoIP database, then for PowerDNS's native readiness check, before DNSdist starts.

The administrator panel is `http://localhost:8080/admin`; the domain-user panel is `http://localhost:8080/app`. Both use the same users, sessions, policies, and desired-state models. Disabled accounts lose API tokens immediately and are denied on their next browser or API request.

## Enrol UI-created development edges

The normal stack includes the two real OpenResty shared/quarantine runtimes and
the mTLS edge-control endpoint. Edge agents are optional because their edge IDs
and one-time tokens come from durable UI state. After creating two edges in the
administrator panel, copy the ignored local template and enter each exact ID and
one-time token:

```sh
cp .env.dev.example .env.dev
# Edit DEV_EDGE_A_ID/TOKEN and DEV_EDGE_B_ID/TOKEN.
chmod 600 .env.dev
make dev-edge-up
make dev-edge-status
```

Wait until both agents show a fresh heartbeat, `listener_ready=true`, and ready
`shared-default` and `quarantine-default` cells. Enrollment identities and
runtime snapshots live in named volumes, so restarts do not require new tokens.
Immediately clear `DEV_EDGE_A_TOKEN` and `DEV_EDGE_B_TOKEN` from `.env.dev`
after the first successful enrollment; the control plane stores only their
hashes and will not reveal them again. A lost or consumed token requires the
administrator **Rotate identity** action and replacement of that edge agent's
persistent identity.

The first enrollment also proves that the two UI rows are connected to real
agents; merely creating an edge record does not make it traffic-ready. See
[Architecture](architecture.md) for the control/data paths and
[Edge installation and registration](edge-installation-and-registration.md)
for the production workflow.

See `docs/manual-browser-qualification.md` for disposable application-account
creation, example values for every UI field, and the complete manual acceptance
sequence.

Application containers never migrate at startup. `make dev-migrate` is the
only normal schema deployment path.

PowerAdmin is diagnostic only. Direct changes modify disposable PowerDNS runtime state, are unsupported product state, and will be detected and overwritten by reconciliation once DNS reconciliation is implemented.

The queue lanes are `interactive`, `runtime`, `certificate_purge`, and `bulk_maintenance`. Horizon gives each a separate worker budget. Scheduler records Horizon metrics every five minutes and prunes expired idempotency responses hourly.
