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

This job exercises HTTP APIs, authorization, idempotency, queues, and persisted operations. Browser/UI acceptance remains the project owner's manual checklist.

The control panel is exposed at `http://localhost:8080`, DNSdist at TCP/UDP
port `1053`, the two test edges at ports `8081` and `8082`, Prometheus at
`9090`, and Alertmanager at `9093`. PowerAdmin is development-only and starts
only with `docker compose -f compose.dev.yml --profile devtools up -d`.

The administrator panel is `http://localhost:8080/admin`; the domain-user panel is `http://localhost:8080/app`. Both use the same users, sessions, policies, and desired-state models. Disabled accounts lose API tokens immediately and are denied on their next browser or API request.

Application containers never migrate at startup. `make dev-migrate` is the
only normal schema deployment path.

PowerAdmin is diagnostic only. Direct changes modify disposable PowerDNS runtime state, are unsupported product state, and will be detected and overwritten by reconciliation once DNS reconciliation is implemented.

The queue lanes are `interactive`, `runtime`, `certificate_purge`, and `bulk_maintenance`. Horizon gives each a separate worker budget. Scheduler records Horizon metrics every five minutes and prunes expired idempotency responses hourly.
