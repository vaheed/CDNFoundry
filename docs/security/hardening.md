---
title: Deployment hardening
description: Checklist to lock down a CDNFoundry deployment, covering the public surface, secrets, containers, origins, accounts, backups, GeoIP, Valkey, and TLS sessions.
---

# Deployment hardening

Use this page to check that a production deployment exposes only what it must,
keeps secrets private, and limits what a stolen credential can do. Work through
the sections in order. Each one names the setting to check.

::: danger Private services must stay private
Docker publishes ports around simple host firewall rules, so a host firewall such
as `ufw` and a provider security group alone are not enough. The Fleet tool writes
`firewall.sh` into every node bundle. It puts a default-deny rule in front of every
internal listener (see [Internal listener firewall](#internal-listener-firewall)).
On a manual Compose install, add equivalent `DOCKER-USER` rules yourself. Then test
from a network you do not trust.
:::

## Public surface

Publish only these:

- Caddy control HTTP and HTTPS.
- DNSdist on UDP and TCP port 53.
- The shared and quarantine OpenResty HTTP and HTTPS addresses you intend to serve.
- Edge-control mutual TLS, limited to the exact edge source addresses.
- The DNS API and telemetry TLS gateways, limited to their allowed sources.

Keep these private: PostgreSQL, Valkey, PowerDNS, ClickHouse, Vector internals,
Prometheus, Alertmanager, Loki, node-exporter, the OpenResty control endpoint, and
any container API. For the full port list see
[Services and ports](../reference/services-and-ports.md).

**Check it worked:** from a host outside your network, scan the control and edge
addresses. Only the ports above answer.

## Internal listener firewall

Exporters, the DNSdist statistics listener, the edge gateway metrics, and the
PowerDNS transfer listener are reachable only by the fleet. Each bundle holds a
generated `firewall.sh`, and `start.sh` runs it before any container starts.

- It admits those ports only from the addresses of the enabled nodes in the fleet
  (public, bind, monitor, and log addresses). Everything else is dropped.
- Docker-published ports are judged in the `DOCKER-USER` chain on their original
  destination port. The edge gateway runs on the host network, so its metrics port
  is judged in `INPUT`. Traffic between containers on the same host is not touched.
- Public listeners (DNS, HTTP, HTTPS, edge control) are never filtered by it.
- On a host with systemd it installs `cdnfoundry-firewall.service`, so the rules return
  after a reboot before containers restart. It uses `ip6tables` too when present.

**Check it worked:** from a host outside the fleet, connect to each internal port.
Every connection times out. From a fleet host, Prometheus targets stay `up`.

## Management networks

The panels, the API, Horizon, Grafana, and the status page answer only the
networks you list in the fleet's `management_networks`. Set them with
`cdnfoundry-fleet management-networks --networks "203.0.113.0/24 2001:db8::/32"`
and deploy the configuration. The fleet's own node addresses are added for you.

- Everyone else gets a bare `403`: no redirect, no login form, no cookie.
- Left open on purpose: `/up`, `/api/health`, `/api/ready`, and the PoP-facing edge-control
  listener. Grafana has no open path.
- The control plane checks the same list again (`MANAGEMENT_NETWORKS`), so a
  misconfigured proxy cannot reopen the panels.
- An empty list restricts nothing. Set it on every production install, from a
  network you can still reach after the change.

**Check it worked:** from a network outside the list, `/admin`, `/app`, `/api/domains`,
`/horizon`, and Grafana each return `403`, and no `Set-Cookie` header appears.

## Browser security

- Every panel and API response carries one `Content-Security-Policy`: scripts run only
  from this origin or with the request's nonce, and nothing loads from another origin.
  `CONTENT_SECURITY_POLICY` selects `enforce` (default), `report-only`, or `off`.
- The API allows no cross-origin browser by default. List origins in
  `CORS_ALLOWED_ORIGINS`; there is no wildcard.
- HSTS covers subdomains.
- No response names a software version: PHP's `X-Powered-By`, nginx's version, and
  PowerDNS's `version.bind` are off. PHP errors go to the container log, never to a page.
- Every process of the control-plane image, including the PHP-FPM master, runs as
  `www-data`. The container starts as root only to prepare its storage.

## Secrets

- Generate new production values. Never reuse development credentials.
- Keep these separate: `APP_KEY`, the artifact signing key, the metrics token, the
  cell control token, database passwords, API keys, backup credentials, and CA keys.
- Keep `.env.prod`, password files, and keys at mode `0600` where possible.
- Give only the PHP workers read access to the edge identity CA key.
- Never print secrets, commit them, paste them into an issue, or leave them in shell history.
- Store the Restic password somewhere other than the backup repository.

## Containers and images

Pin immutable image tags and verify digests. Production services follow these rules:

- Every service has a health check, except one-shot tools.
- Every service sets `no-new-privileges`, drops all capabilities, and has a memory limit.
- A service that must start as root adds back only `CHOWN`, `DAC_OVERRIDE`, `FOWNER`,
  `SETGID`, and `SETUID`, so it can prepare its data and drop privileges. A service
  that binds port 53, 80, or 443 also adds `NET_BIND_SERVICE`.
- Services use read-only root filesystems where supported, tmpfs for temporary files,
  restart policies, bounded stop periods, and resource limits.
- Named volumes stay in place across upgrades.

`scripts/ci/check_compose_policy.py` reads the rendered production configuration.
It fails on any service that breaks these rules, mounts the Docker socket, or runs
privileged. It runs in `make config-check` and in CI.

The per-host operational Vector collector has no Docker socket. It reads container
logs through `docker-socket-proxy`, a separate container on a private network. The
proxy answers only container listing, logs, and events, and refuses every write.
It is the one service that mounts the socket, read-only. Limit host access to
deployment administrators and never publish its port. The collector's root
filesystem is read-only and all capabilities are dropped. Journal mounts are
read-only. Keep its allowlisted envelope and final redaction transform ahead of the
private Loki sink.

PHP-FPM listens only on the address core has on the private `fpm` network
(`FPM_NETWORK_SUBNET`, shared by core, `web`, and `edge-control`). Horizon, the
scheduler, the database, and every other container on the control network cannot
connect to it. Core trusts edge identity headers only when they carry the secret
from `EDGE_PROXY_SECRET_FILE`. Only the nginx in edge-control adds that secret, so a
request that reaches PHP another way cannot claim to be an edge.

## Origin policy

Prefer public HTTPS origins that require authentication. Allow a private CIDR range
only when you route to it on purpose, and keep the list small. CDNFoundry always
blocks loopback, link-local, metadata, multicast, reserved, edge-service, platform,
and proxy-loop destinations. You cannot override these. Keep TLS verification on,
with the right SNI and hostname. See
[Proxy and origins](../guides/proxy-and-origins.md).

## Accounts

- Give each person a named account and a strong password. Every user needs at least
  12 characters with letters, mixed case, and a number.
- Create one short-lived API token per automation device, and revoke it promptly.
- Limit each token to the ability it needs (`read`, `dns`, or `purge`) and to the
  domains it needs. Tokens expire after 90 days unless you choose another lifetime.
- Enrol every administrator in two-step sign-in. See
  [Users and access](../guides/users-and-access.md).
- Disable departed users, remove their domain assignments, and review audit logs.
- Protect `/admin`, Horizon, and the metrics token at the network edge as well as
  in the application.

## Recovery and rotation

Test a restore on an isolated host. A backup only counts once you can open its
snapshot and you hold the full recovery set. Rotate one credential boundary at a
time. Keep the old and new credential valid together, check health, then revoke the
old one. See [Backup and recovery](../operations/backup-and-recovery.md).

## Runtime limits

Choose the security profile from the traffic risk you expect, not from the
throughput you hope for. Watch the 80% capacity signals, queue age, cache and
temporary storage, Vector buffers, Loki host disk, and the clock. CDNFoundry cannot
absorb a flood that fills your uplink. Use DDoS protection from your upstream
provider for that.

## GeoIP database

The updater downloads the database to a temporary file, checks it, then renames it
into place.

- In production (`MMDB_REQUIRE_VERIFICATION=true`), a download must match
  `MMDB_EXPECTED_SHA256` or the checksum published at `MMDB_CHECKSUM_URL`. Otherwise
  the updater refuses it and keeps the previous database.
- Neither default public provider publishes a checksum. Production therefore needs a
  mirror you control, a pinned hash, or a provider that publishes one.
- On a first start with no usable download, the updater installs an empty database
  from its image so DNS still starts. Geo-DNS records then return their default
  answers, `FALLBACK_IN_USE` appears in the volume, and the next verified download
  replaces it.
- PowerDNS maps the file into memory, so its start script reloads PowerDNS within a
  minute of the file changing.

## Valkey

Two Valkey instances run:

- `redis` holds queues, sessions, and locks. It is persistent and never evicts keys.
- `cache` holds the application cache. It has no persistence and evicts the least
  recently used keys, so a full cache cannot stop job dispatch.

Both read their password from `VALKEY_CONFIG_FILE`, a mounted file, so the password
is not on a command line.

## TLS sessions

Edge TLS uses the shared session cache and turns session tickets off. A ticket key
lives as long as the nginx process and is never rotated, so a later leak would
expose recorded sessions.

The edge does not staple OCSP responses. The public CA used for managed certificates
stopped serving OCSP in 2025. The edge picks a certificate per handshake from many.
An uploaded certificate's responder cannot be relied on from every edge.
