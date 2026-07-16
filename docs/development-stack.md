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

The control panel is exposed at `http://localhost:8080`, DNSdist at TCP/UDP
port `1053`, the two test edges at ports `8081` and `8082`, Prometheus at
`9090`, and Alertmanager at `9093`. PowerAdmin is development-only and starts
only with `docker compose -f compose.dev.yml --profile devtools up -d`.

Application containers never migrate at startup. `make dev-migrate` is the
only normal schema deployment path.

