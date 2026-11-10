# Changelog

All notable changes are recorded here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses
[Semantic Versioning](https://semver.org/). The root `VERSION` file is the
single source of truth; `make version-check` enforces it.

## [Unreleased]

### Added

- Platform setting `proxy_defaults.diagnostic_response_headers` (off by default) controls the `Server` and `X-CDNF-*`
  response headers on proxied responses. The `Server` header no longer carries a version.
- Acknowledge a failed operation (Operations page, bulk action, and `POST /api/admin/operations/{operation}/acknowledge`)
  so a reviewed failure stops raising Degraded. A later success of the same operation type for the same domain also
  resolves it.
- Detail tables behind every dashboard drill-down on Traffic and telemetry (traffic, cache, origin, DNS).
- The admin Overview now also shows a Platform inventory (domains, proxied hostnames, certificates, ready cells, WAF
  coverage, release, nameservers), the traffic and error charts, edge health, cache, DNS, queue lanes, and data freshness.

### Changed

- Timestamps no longer print the timezone offset or name; the platform setting defines the zone.

### Fixed

- The Global traffic and Global DNS tables fit their panel with fixed columns instead of scrolling sideways.
- Admin dashboard charts and totals stopped at the last complete hour, so they trailed real time by up to an hour
  (more with a half-hour timezone). The hour in progress is now charted and labelled "so far".
- The failed-operations tile and `cdnfoundry_operations_failed_recent` counted failures that were already recovered
  or acknowledged.
- Enabling managed WAF on a service pool failed with "The waf runtime version field is required"; the release is now
  filled from the pinned image when the toggle is switched on.

## [2.0.6] - 2026-11-10

Makes Geo-DNS and the Grafana dashboards work on a fresh fleet, and adds automatic nameserver checks, registration
expiry, and a delete action for domains. See the [release notes](docs/operations/releases/v2.0.6.md).

### Fixed

- Geo-DNS always answered the default: production required a verified GeoIP database but Fleet configured no
  checksum, so nodes kept an empty placeholder. The default provider `dbip-npm` verifies the registry's SHA-512.
- Vector exported the `cdnf` metrics as `_cdnf_...`, so the recording rules and most DNS and edge dashboard panels
  were empty. The exporter no longer sets an empty namespace.
- The PoP logs panel failed for All PoPs because its LogQL selector matched only empty values.
- The edge gateway closed an unrouted plain-HTTP connection without an answer, so synthetic probes always failed. It
  now answers `421`.
- The blackbox DNS probe (`probe.cdnfoundry.invalid`) was counted as customer traffic, inflating DNS query and
  REFUSED figures by about half. Vector now drops it from DNS telemetry.
- Solid danger buttons failed contrast in light mode.

### Added

- Delete domain in the domain page menu.
- Scheduled nameserver checks for pending and active domains, with un-verify after three confirmed changes.
- Registration expiry in both panels and the API, with a warning window and reminders.
- A Domain monitoring settings group.

## [2.0.5] - 2026-11-09

Makes a fresh Fleet install complete by itself and fixes the zone transfer, the pair names, and the missing logs
that kept a new fleet from answering DNS. See the [release notes](docs/operations/releases/v2.0.5.md).

### Fixed

- A PowerDNS node could never pull zones from the primary: it sat only on an internal network, so every transfer
  failed with `Network is unreachable`. It now also joins the egress network.
- A customer nameserver pair name typed with capital letters failed the PowerDNS read-back and rolled the whole
  platform DNS identity back. Pair names are now lowercased.
- Central logs stayed off after setup, so Loki and the log collectors were never deployed and Grafana showed no
  logs. The monitoring presets now turn them on.
- The Fleet gate checked ports 80 and 443 on loopback and hung for minutes on a PoP with no placed pool address.
  It now checks the public address once the gateway listens, and prints the reason after each failed attempt.

### Added

- Fleet registers the private PowerDNS primary as a DNS cluster and stores the platform DNS identity after it
  installs the control node. Neither replaces something already set.
- A failed installation in Fleet web offers a Retry button that resumes at the first step not done.
- The DNS identity review lists every customer nameserver pair.

### Removed

- The scheduled off-host restore drill workflow. `make dev-recovery-e2e` still runs it by hand.

## [2.0.4] - 2026-11-09

Fixes a fleet that could not enrol its edges when the Fleet-generated edge identity CA had no
subject key identifier, and makes a failed host start show its cause. See the
[release notes](docs/operations/releases/v2.0.4.md).

### Fixed

- Edge enrolment failed with `Unable to sign the edge identity certificate` when the edge identity CA
  certificate had no Subject Key Identifier. Core now signs with such a CA, and Fleet adds the identifier
  to every CA it generates.
- A signing failure now says why (unreadable CA key, key and certificate that do not match, or the
  OpenSSL error) instead of a bare message.

### Added

- When a host fails to start, the Fleet action shows the last log lines of each failing container.

## [2.0.3] - 2026-11-09

Fleet fixes found by running every CLI command and web page against real hosts, a Fleet web
Config page for monitoring, logs, and backups, and a step-by-step HA guide. See the
[release notes](docs/operations/releases/v2.0.3.md).

### Added

- Fleet web: monitoring, central logs, and backups can be set on the Config page, and the HA form takes
  an SSH user.
- Documentation: [Set up control-plane HA step by step](docs/operations/set-up-control-plane-ha.md) for a
  local network or WireGuard, and the requirements of a dedicated monitoring host.

### Fixed

- `apply-config` compared a render with the previous render and missed saved changes. It compares with the
  bundle each host last ran.
- `control add-standby` failed with `KeyError`, and a standby added without image pins could not be
  promoted.
- ClickHouse could not finish its first start because it lacked the `KILL` capability.
- A failed image pull crashed Fleet with a traceback and hid the reason.
- A finished one-shot container marked a host degraded.
- A monitoring-role node with colocated monitoring rendered a broken bundle.
- Fleet web showed raw Python errors, and form fields overflowed a phone screen.
- The manual Compose guide ran `pull` without a profile, which pulls nothing.

## [2.0.2] - 2026-11-08

Fleet shows what it is doing, installs hosts in parallel, and no longer hangs at
ClickHouse migration. One behavior change outside Fleet: the Alertmanager webhook is
now optional. See the [release notes](docs/operations/releases/v2.0.2.md).

### Added

- Fleet web action page: every step of an action is listed up front, grouped by host,
  and ticked as it finishes, with a progress bar, a live log that follows the end, a
  log filter and download, and **Stop after this step**. A running or finished action
  is announced on every page, and an interrupted install offers **Continue**.
- Fleet inventory: processor, memory, disks, kernel, Docker, time sync, network
  interfaces, and containers of every host, with findings, on a new Inventory page and
  with `cdnfoundry-fleet inventory`.
- Fleet live view: online, degraded, or unreachable state and memory and disk gauges
  on Overview, Nodes, and Inventory, refreshed by a read-only probe, a linked
  topology, a recent-activity feed, container log tails, and `cdnfoundry-fleet watch`.
- Fleet CLI: a step progress bar on a terminal, and a first Ctrl+C that stops before
  the next step.
- Fleet streams host output into the action log while a host starts its services, and
  reports a step that has been quiet for 30 seconds.
- `cdnfoundry-fleet alert-webhook`, and an alert webhook field at setup, on Deploy,
  and on Config.

### Changed

- Fleet connects to, prepares, and pulls images on every host at the same time before
  it installs the first one (up to 6 hosts and 4 images per host at once).
- The Alertmanager webhook (`ALERT_WEBHOOK_URL`) is optional. With no value,
  Alertmanager starts with receivers that send nothing; alerts still fire and show in
  Prometheus. A value that is not an `https://` URL is still refused.

### Fixed

- A Fleet install hung at ClickHouse migration: host commands inherited the operator's
  terminal as standard input, and `clickhouse-client` waits for extra rows on it.
- Pinning several hosts at once could drop host keys from the known-hosts file.

## [2.0.1] - 2026-11-08

Fleet is easier to run. No change to the control plane, DNS, or edge runtime.
See the [release notes](docs/operations/releases/v2.0.1.md).

### Changed

- Fleet builds and signs the release itself: it reads the release your checkout is
  on, looks up the published image digests, and signs the manifest with a key kept
  in the encrypted vault. `set-release` and `upgrade` no longer need `--manifest`.
  Operators who sign releases themselves still pin their key and pass a manifest.
- Fleet web has a new layout on the CDNFoundry theme: grouped navigation, a page
  header with a one-line description, and bordered tables and forms.
- `scripts/fleet-web.py` restarts under Python 3.13 to 3.10 when the default Python
  is newer than the tested range, and installs PyYAML without a prebuilt wheel.

### Fixed

- A first install uses the release chosen at setup, not the tag of the checkout.
- The release lookup verifies the registry with the certifi bundle, so it works on
  Python builds without system root certificates (python.org builds on macOS).
- The Backups page no longer scrolls sideways on a phone.
- The Fleet browser suite no longer fails when its projects share fixture state.

## [2.0.0] - prepared 2026-11-05, not yet tagged

v2.0.0 is a breaking release with no upgrade from 1.x. See the
[release notes](docs/operations/releases/v2.0.0.md).

### Breaking changes

- No upgrade path or data migration from 1.x. Rebuild from scratch.
- Migrations are squashed into a PostgreSQL and a SQLite baseline.
- The edge agent requires `EDGE_RUNTIME_DIR` and has one activation path. Agents
  accept artifacts for agent versions 2.0.0 to 2.99.0.
- The platform zone goes to the hidden DNS primary, and
  `platform_dns_settings.cluster_targets` is removed.
- `EDGE_PROXY_SECRET` and `METRICS_TOKEN` are no longer read from the
  environment; use `EDGE_PROXY_SECRET_FILE` and `METRICS_TOKEN_FILE`.
- Every documented tunable is passed to the control plane through `.env.prod`.

### Added

- Documentation rebuilt around tasks: six sections, a Fleet web guide, a Grafana
  guide by team, panel tours, installation options, incident response, health
  probes, reports, a glossary, a FAQ, and generated references for the Fleet
  CLI, Grafana dashboards, and alerts.
- Generated documentation screenshots, a design-token match with the product
  theme, executable documentation blocks, and `llms.txt`.

- Phase 6 (optional control-plane high availability): an optional warm standby
  control node with `cdnfoundry-fleet control add-standby`, `switchover`,
  `demote`, `promote`, `rejoin`, `remove-standby`, `status`, and `check`; a
  monotonic `control_epoch` that fences a returning old node (start guard,
  scheduler gate, `503 standby` from edge-control); edge agents that dial an
  ordered list of control addresses from signed configuration and ignore a
  lower epoch; DNS nodes that follow both control nodes as primaries; HA
  alerts, a **Standby node** health check, and `ha: none` as a warning in the
  qualification report; ClickHouse usage rollups in the control backup. A
  single node stays supported. There is no floating address and no automatic
  failover. See [Control-plane high availability](docs/operations/control-plane-high-availability.md).
- Phase 2 (control-plane faults): PoPs leave DNS only on a positive signal and
  pools keep their last published set; PoPs probe each other's listeners; edge
  identity certificates renew over mTLS; the agent rejects a bad artifact for
  one domain only; placements promote by quorum; Alertmanager sends to a
  required webhook with an always-firing `Watchdog`; every long-running
  service has a health check; origins see the real client address. See the
  [roadmap](docs/roadmap.md) completion gate.
- Coding-agent tooling: rewritten project skills with front matter, real file
  paths, commands, tests, and known traps, plus a new `cdnf-codebase-map`
  skill; `.claude/skills` links to `.agents/skills`; `.mcp.json` registers the
  local CodeGraph MCP server (pinned 1.6.2, telemetry off). See
  `docs/development/coding-agents.md`.

### Fixed

- Signing in at `/admin/login` or `/app/login` no longer fails with "The password field
  is required" on a development stack whose published Filament assets were older than the
  installed package: the password field's script returned `404`, which broke the form. The
  development entrypoint now republishes the assets when it starts.
- A new control node's first start no longer fails when its databases report healthy
  before they accept TCP connections (the migration and PowerDNS runtime step wait).
- Fleet control bundles now contain the `pdns-migrate` tool their `start.sh`
  runs; a generated control node could not previously finish its first start.

### Changed

- The documentation site footer and the archived README and CONTRIBUTING
  pages state the Apache License 2.0; CONTRIBUTING records that contributions
  are licensed under the same terms.
- `docs/roadmap.md` is now the active platform rebuild roadmap: audit finding
  register from a code audit, and risk-ordered phases toward a breaking 2.0.
- `docs/roadmap.md` merges the 2026-10-30 codebase audit
  (`docs/audit/2026-10-30-codebase-audit.md`): findings A1–A46 join the
  register, every A item is assigned to a phase with updated exit criteria,
  a "Before Phase 1" list holds date-bound and disclosure work, stale code
  references are corrected, and M11 moves to Phase 2.

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
- The staged roadmap is finished and removed from the tree.

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

- Staging-era handoff, audit and per-phase evidence reports.

### Documentation

- Current docs aligned to the project; stale and unrelated pages removed.
- Release notes: [v1.2.1](docs/operations/releases/v1.2.1.md).

## Earlier releases

`v0.4.0` to `v0.9.9` are published as GitHub releases.
