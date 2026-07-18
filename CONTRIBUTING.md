# Contributing to CDNFoundry

Thank you for helping improve CDNFoundry. Keep changes small, operationally
predictable, and consistent with [AGENTS.md](AGENTS.md) and the verified product
contract in [docs/roadmap.md](docs/roadmap.md).

## Before opening a change

1. Search existing issues and pull requests.
2. For a substantial feature or architecture change, open a proposal first.
3. Do not add a microservice, per-domain runtime, speculative abstraction, or
   new infrastructure dependency without an accepted roadmap change.
4. Never include production secrets, tokens, customer data, private keys, or
   copied production databases.

## Development workflow

```sh
make dev-up
make dev-migrate
make dev-test
make config-check
make openapi-check
```

Run `make dev-e2e` for DNS, edge, runtime, or control-plane changes. Run
`make dev-scale-e2e` when changing DNS storage, imports, bulk mutation, or query
bounds. Do not remove persistent Compose volumes or refresh the development
PostgreSQL database.

Laravel tests must use the project's isolated SQLite-in-memory command. Browser
automation is not part of automated qualification; update and manually execute
the relevant steps in `docs/manual-browser-qualification.md` for UI changes.

## Change requirements

- Use policies for browser and API authorization.
- Validate full desired state before committing it.
- Keep external effects asynchronous, revisioned, retry-safe, and last-valid.
- Add happy-path, authorization, validation, failure/retry, and runtime tests in
  proportion to the change.
- Update OpenAPI, environment examples, operator/user documentation, and the
  roadmap only when the result has been verified.
- Keep commits focused and explain observable behaviour in the pull request.

By contributing, you agree that your contribution is licensed under the MIT
License in [LICENSE](LICENSE).
