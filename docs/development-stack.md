# Development stack

The supported development runtime is Docker Compose. Host PHP, Composer,
PostgreSQL, Valkey, and web servers are not part of the workflow.

Start the full stack and run the explicit database deployment:

```sh
make dev-up
make dev-migrate
```

Run tests inside the same application image:

```sh
make dev-test
```

Run the Python non-UI end-to-end qualification against the real stack:

```sh
make dev-e2e
```

This cumulative job exercises Phases 1–4 through HTTP APIs, authorization, idempotency, queues, persisted operations, real PowerDNS/DNSdist answers, Geo-DNS, mTLS edge control, pool migration, and the generic OpenResty runtime. Run the heavier 500,000-zone/1,000,000-record qualification separately with `make dev-scale-e2e`. Browser/UI acceptance remains the project owner's manual checklist.

The control panel is exposed at `http://localhost:8080`, Horizon at
`http://localhost:8080/horizon`, DNSdist at TCP/UDP port `1053`, the two test
edges at ports `8081` and `8082`, Prometheus at `9090`, Alertmanager at `9093`,
and development-only PowerAdmin at `http://localhost:9191`. `make dev-up`
enables the `devtools` profile so PowerAdmin starts with the stack. Its default
development login is `admin` / `poweradmin-dev-only`; override it with
`POWERADMIN_ADMIN_USERNAME` and `POWERADMIN_ADMIN_PASSWORD` before first start.
On a fresh MMDB volume, Compose waits for the updater to activate a validated
GeoIP database, then for PowerDNS's native readiness check, before DNSdist starts.

The administrator panel is `http://localhost:8080/admin`; the domain-user panel is `http://localhost:8080/app`. Both use the same users, sessions, policies, and desired-state models. Disabled accounts lose API tokens immediately and are denied on their next browser or API request.

See `docs/manual-browser-qualification.md` for disposable application-account
creation, example values for every UI field, and the complete manual acceptance
sequence.

Application containers never migrate at startup. `make dev-migrate` is the
only normal schema deployment path.

PowerAdmin is diagnostic only. Direct changes modify disposable PowerDNS runtime state, are unsupported product state, and will be detected and overwritten by reconciliation once DNS reconciliation is implemented.

The queue lanes are `interactive`, `runtime`, `certificate_purge`, and `bulk_maintenance`. Horizon gives each a separate worker budget. Scheduler records Horizon metrics every five minutes and prunes expired idempotency responses hourly.
