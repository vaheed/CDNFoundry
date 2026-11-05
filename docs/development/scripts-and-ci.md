---
title: Scripts and CI/CD
description: Reference for the helper scripts, Make targets, CI jobs, published image names and tags, and documentation checks.
---

# Scripts and CI/CD

::: danger Keep secrets out of automation output
CI logs, generated documentation, test fixtures, and command transcripts must
never print production tokens, customer data, certificate keys, CA keys,
encryption keys, signing keys, or backup credentials.
:::

## Shell scripts

| Script | Contract |
| --- | --- |
| `ensure-development-certificates.sh` | Initialize or verify a complete persistent development PKI; refuse partial mixing |
| `generate-production-certificates.sh` | Create two private CAs plus control/runtime/DNS API server identities |
| `generate-production-env.sh` | Interactively create mode-`0600` control or combined DNS/edge environment |
| `validate-production-overrides.sh` | Validate every supported role, IPv4/IPv6, and external-data Compose combination |
| `install-production-prerequisites.sh` | Install Python 3 with YAML support, OpenSSL, and Docker with Compose when missing |
| `issue-edge-listener-material.sh` | Issue the per-PoP edge runtime certificate, its key, and the edge state encryption key |
| `prepare-image-runner.sh` | Free disk space on a disposable CI runner before image builds |

`tests/config/generate-production-env.sh`, run by `make config-check`, exercises
both interactive host roles, optional-backup output, mode `0600`, and the
separation between advertised or NAT addresses and local listener addresses.

Backup scripts inside the core image stream PostgreSQL to and from Restic. The
MMDB updater downloads, extracts, validates, and atomically activates GeoIP data.
The Fleet tool (`scripts/cdnfoundry_fleet`) is documented in the
[Fleet CLI reference](../reference/fleet-cli.md).

## Production Make targets

`make prod-migrate` starts and health-checks the local control PostgreSQL and
Valkey dependencies, then runs the Laravel migration. `make prod-pdns-migrate` does
the same for PowerDNS PostgreSQL, and `make prod-clickhouse-migrate` for ClickHouse. The
`prod-control`, `prod-dns`, `prod-edge`, and `prod-telemetry` targets start only
their named profile; monitoring and logs are not enabled implicitly. Run
`make prod-logs` separately when this host is configured to own one operational
log collector.

## CI jobs

| Job | Checks |
| --- | --- |
| `core` | PHP 8.4 setup, Composer install, validate, and audit, npm install and audit, Pint, PHPStan, frontend build, Laravel tests, PostgreSQL concurrency checks, OpenAPI check |
| `images` | Build the production images in three groups (application, edge, agents) |
| `compose` | Development and production Compose config, Compose policy, image scans, runtime image checks |
| `backend-e2e` | Full real development stack, migrations, cumulative Python non-UI E2E |
| `scale-e2e` | Bounded control stack with a 500,000-zone, 1,000,000-record dataset and mutation qualification |
| `go` | Go 1.26 formatting, vet, tests, build, origin probe qualification |
| `docs` | Version consistency, no tracked screenshots, dependency audit, and `make docs-check` |
| `deploy-docs` | Publish the documentation to GitHub Pages on `main` |
| `publish-images` | Push, scan, sign, and attest the images, and write release evidence |

`publish-images` and `deploy-docs` run after `core`, `compose`, `backend-e2e`,
`scale-e2e`, `go`, and `docs` pass. The `docs` job checks Markdown, duplicate
headings, local pages and anchors, OpenAPI and catalog consistency, and the
production site build.

## Published images

Successful pushes to `main`, `dev`, and `v*` tags publish these images to
`ghcr.io/vaheed/`:

- `cdnfoundry-core`
- `cdnfoundry-web`
- `cdnfoundry-edge-control`
- `cdnfoundry-edge-runtime`
- `cdnfoundry-edge-agent`
- `cdnfoundry-edge-gateway`
- `cdnfoundry-mmdb-updater`

Every image gets the exact commit SHA as a tag. Other tags depend on the ref:

- `main` also updates `latest`.
- `dev` also updates `dev` and `dev-latest`.
- A semantic tag publishes exact `vX.Y.Z` and `X.Y.Z`. A stable release also
  updates the major.minor tag, the major tag, and `latest`. A prerelease gets only
  the exact tags.

Production must pin the SHA or an exact version, never a mutable tag.

## Documentation site

Run all documentation checks from the repository root:

```sh
make docs-check
```

For local authoring, start the live preview:

```sh
npm --prefix docs run dev
```

Set `DOCS_SITE_URL` and `DOCS_BASE` when you deploy somewhere other than the
default GitHub Pages project path.

## Documentation checks

`make docs-check` runs, in order: the design-token match with the product theme,
the generated Fleet CLI reference check, the link and environment-key checks, every
shell block marked `test`, the Markdown lint, the VitePress build, and the built-site
check. Each step is a script under `tests/docs/` or `scripts/ci/`. Regenerate the Fleet CLI reference with
`python3 scripts/ci/generate_fleet_cli_reference.py` after changing a Fleet command.
Regenerate the documentation screenshots with
`tests/browser/run.sh docs-screenshots` at every release;
`scripts/ci/check_no_screenshots.py` fails when they are older than `VERSION`.
