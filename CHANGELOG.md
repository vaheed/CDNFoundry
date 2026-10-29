# Changelog

All notable changes are recorded here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses
[Semantic Versioning](https://semver.org/). The root `VERSION` file is the
single source of truth; `make version-check` enforces it.

## [Unreleased]

### Changed

- The documentation site footer and the archived README and CONTRIBUTING
  pages state the Apache License 2.0; CONTRIBUTING records that contributions
  are licensed under the same terms.
- `docs/roadmap.md` is now the active platform rebuild roadmap: audit finding
  register from a code audit, and risk-ordered phases toward a breaking 2.0.

## [1.2.1]

v1.2.1 is a zero-state release: deploy it from scratch. There is no supported
upgrade path from 0.x. Entries below cover everything since `v0.9.9`.

### Added

- Root `VERSION` file with `make version-check` (CI-enforced) and
  `make check-no-screenshots`.
- Control-plane and DNS-API host protection: these hosts are rejected as proxied origins.
- Safe public service status snapshots and a rendered public health page.
- Verification of authoritative ACME challenges with PowerDNS readback; probes of
  authoritative DNS and verified edge serving paths.
- Expiry and replay rejection for edge artifact deliveries.
- Bounded telemetry buffers that prioritize security events, bounded edge shared
  memory and cache-refresh admission, single-flight cache refresh.
- Edge cells keep serving when the Vector DNS name is unavailable.
- `NOTICE` file and Apache-2.0 license metadata.
- `SUPPORT.md`, supported-version table in `SECURITY.md`.

### Changed

- The project is licensed under Apache-2.0.
- Edge headers are renamed to the `X-CDNF` prefix across runtime and Fleet.
- Edge private keys stay in memory with encrypted recovery state.
- Upstream software runs from pinned upstream images only; Alpine package pins are
  minimum-version floors; Go services are built with Go 1.26.10.
- API error envelope and proxy scheme aligned; fleet dry run and start activation hardened.
- Edge agent, edge gateway, fleet generator, API contract and release manifest
  all report the single project version.
- The staged roadmap is finished and archived under `docs/legacy/`.

### Fixed

- Domain-user panel returns 403 for a revoked domain in open Livewire requests.
- Origin peer checks and cache refresh bounds hardened.
- Edge referrer queries are redacted before fallback logging.
- Vector startup bounds and buffer sizes use supported minimums.
- Legacy DNS settings stay readable in persistent development state.
- CI supply-chain, topology and E2E gates; image revision verification;
  signing the GHCR digest; accepting an already-recorded transparency entry.

### Security

- Dependency advisories cleared for PHP, Node and Go toolchains; Alpine and BIND
  package floors raised for HIGH/CRITICAL advisories.
- Expired and replayed edge artifacts are rejected.

### Removed

- Staging-era handoff, audit and per-phase evidence reports moved to `docs/legacy/`
  (historical, unmaintained).

### Documentation

- Current docs aligned to the project; stale and unrelated pages archived in `docs/legacy/`.
- Release notes: [v1.2.1](docs/operations/releases/v1.2.1.md).

## Earlier releases

`v0.4.0` to `v0.9.9` are published as GitHub releases. Their notes are archived in
[`docs/legacy/releases/`](docs/legacy/releases/).
