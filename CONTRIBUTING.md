# Contributing to CDNFoundry

Read [AGENTS.md](AGENTS.md), the [closed roadmap](docs/roadmap.md), and the full
[contribution guide](docs/contributing/index.md) before opening a change.

Use the supported Compose workflow:

```sh
make dev-control-up
make dev-migrate
make dev-up
make dev-test
make config-check
make openapi-check
make docs-check
```

Run relevant Python real-runtime tests for DNS, edge, TLS, cache, security,
telemetry, operations, upgrade, or recovery changes. Browser qualification is
manual and must be reported as not run unless the owner completes
[the checklist](docs/manual-browser-qualification.md).

Keep changes bounded and production named. Do not add per-domain processes,
containers, workers, timers, cache directories, or Nginx server blocks. Preserve
last-valid runtime state and persistent development volumes.

For documentation changes, follow
[Documentation contributions](docs/contributing/documentation.md).

## License

CDNFoundry is licensed under the [Apache License, Version 2.0](LICENSE). By
contributing, you agree that your contribution is licensed under the same terms,
as described in section 5 of the license.
