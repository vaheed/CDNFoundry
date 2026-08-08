---
title: Scripts and CI/CD
description: Understand CDNFoundry helper scripts, GitHub Actions checks, images, tags, and documentation automation.
---

# Scripts and CI/CD

::: danger Keep secrets out of automation output
CI logs, generated documentation, test fixtures, and command transcripts must
not print production tokens, customer data, certificate keys, CA keys,
encryption keys, signing keys, or backup credentials.
:::

## Shell scripts

| Script | Contract |
| --- | --- |
| `ensure-development-certificates.sh` | Initialize or verify a complete persistent development PKI; refuse partial mixing |
| `generate-production-certificates.sh` | Create two private CAs plus control/runtime/DNS API server identities |
| `issue-production-dns-api-certificate.sh` | Add one DNS API identity without overwriting files |
| `generate-production-env.sh` | Interactively create mode-`0600` control or combined DNS/edge environment |
| `validate-production-overrides.sh` | Validate every supported role, IPv4/IPv6, and external-data Compose combination |

`tests/config/generate-production-env.sh`, run by `make config-check`, exercises
both interactive host roles, optional-backup output, mode `0600`, and the
separation between advertised/NAT addresses and local listener addresses.

Backup scripts inside the core image stream PostgreSQL to and from Restic. The
MMDB updater downloads, extracts, validates, and atomically activates GeoIP data.

## Production Make targets

`make prod-migrate` starts and health-checks the local control PostgreSQL and
Valkey dependencies before running the explicit Laravel migration.
`make prod-pdns-migrate` does the same for PowerDNS PostgreSQL. The
`prod-control`, `prod-dns`, `prod-edge`, and `prod-telemetry` targets start only
their named profile; monitoring and logs are not enabled implicitly. Run
`make prod-logs` separately when this host is configured to own one operational
log collector.

## CI jobs

| Job | Checks |
| --- | --- |
| `core` | PHP 8.4 setup, Composer install/validate/audit, Node 24 npm install/audit, Pint, frontend build, Laravel tests, OpenAPI |
| `compose` | development/production Compose config, docs validation, Python syntax, every production image, read-only core smoke |
| `backend-e2e` | full real development stack, migrations, cumulative Python non-UI E2E |
| `scale-e2e` | bounded control stack and 500,000-zone/1,000,000-record mutation qualification |
| `go` | Go 1.24 formatting, vet, tests, build |
| `docs` | dependency audit, current-page/anchor/API contracts, Markdown lint, and production VitePress build |
| `publish-images` | immutable images after all required jobs |

The independent `docs` job gates publication alongside the application jobs.
It checks Markdown, duplicate headings, local pages/anchors, required information
architecture, OpenAPI/catalog consistency, and the production site build.

## Published images

Successful `main` pushes publish:

- `cdnfoundry-core`
- `cdnfoundry-web`
- `cdnfoundry-edge-control`
- `cdnfoundry-edge-runtime`
- `cdnfoundry-edge-agent`
- `cdnfoundry-mmdb-updater`

Every image receives the exact commit SHA. `main` also updates `latest`.
Semantic tags publish exact `vX.Y.Z` and `X.Y.Z`; stable releases also update
major/minor and `latest`. Prereleases receive only exact aliases.

Production must pin the SHA or exact version, never a mutable channel.

## Documentation site

From the repository:

```sh
make docs-check
```

For local authoring:

```sh
npm --prefix docs run dev
```

Set `DOCS_SITE_URL` and `DOCS_BASE` when deploying somewhere other than the
default GitHub Pages project path.
