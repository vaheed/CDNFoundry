---
title: Deployment hardening
description: Harden CDNFoundry networks, secrets, identities, containers, origins, backups, and operations.
---

# Deployment hardening

::: danger Private services must remain private
A provider security group alone is not sufficient when Docker publishes ports.
Apply equivalent host `DOCKER-USER` policy and verify exposure externally from
an untrusted network.
:::

## Public surface

Publish only:

- Caddy control HTTP/HTTPS;
- DNSdist UDP/TCP 53;
- intended shared/quarantine OpenResty HTTP/HTTPS addresses;
- edge-control mutual TLS from exact edge sources;
- source-restricted DNS API and telemetry TLS gateways.

Keep PostgreSQL, Valkey, PowerDNS, ClickHouse, Vector internals, Prometheus,
Alertmanager, Loki, node-exporter, OpenResty control, and container APIs private.
Use provider firewalls and host `DOCKER-USER` policy because Docker publication
can bypass simple host input rules.

## Secrets

- Generate unique production values; do not copy development credentials.
- Separate `APP_KEY`, artifact signing, metrics, cell control, database, API,
  backup, and CA material.
- Keep `.env.prod`, password files, and keys mode `0600` where possible.
- Grant the identity CA key only restricted PHP worker read access.
- Never print, commit, paste into issue trackers, or include secrets in command history.
- Store Restic decryption separately from the repository.

## Containers and images

Pin immutable image tags and verify digests. Every production service has a
health check (one-shot tools excepted), `no-new-privileges`, all capabilities
dropped, and a memory limit. A service that must start as root adds back only the
few capabilities it needs to prepare its data and drop privileges
(`CHOWN`, `DAC_OVERRIDE`, `FOWNER`, `SETGID`, `SETUID`, and `NET_BIND_SERVICE`
for the services that bind port 53, 80, or 443); nothing else is allowed.
`scripts/ci/check_compose_policy.py` reads the rendered production configuration
and fails on any service that breaks these rules, mounts the Docker socket, or
runs privileged. It runs in `make config-check` and in CI. Production services use
read-only roots where supported, tmpfs for writable ephemera, restart policies,
bounded stop periods, and resource limits. Retain named volumes across upgrades.

The per-host operational Vector collector holds no Docker socket. It reads
container logs through `docker-socket-proxy`, a separate container on a private
network that answers only container listing, logs, and events and refuses every
write. The proxy is the one service that mounts the socket (read-only), so keep
host access to deployment administrators and never publish its port. The
collector's root filesystem stays read-only with all capabilities dropped.
Optional journal mounts are read-only. The collector's allowlisted envelope and
final redaction transform must remain before its private Loki sink.

PHP-FPM listens only on the address core has on the private `fpm` network
(`FPM_NETWORK_SUBNET`, shared by core, `web`, and `edge-control`). Horizon, the
scheduler, the database, and every other container on the control network cannot
open a connection to it. Edge identity headers are trusted only when they carry
the secret in `EDGE_PROXY_SECRET_FILE`, which only edge-control's nginx adds, so
a request that reaches PHP by any other path cannot claim to be an edge.

## Origin policy

Prefer public, authenticated HTTPS origins. Use a minimal private CIDR allowlist
only when routing is intentional. Built-in loopback, link-local, metadata,
multicast, reserved, edge-service, platform, and proxy-loop blocks cannot be
overridden. Keep TLS verification enabled with correct SNI and hostname.

## Accounts

Use separate named accounts, strong passwords (12 characters with mixed case and
a number, for every user), short-lived API tokens per automation device, and
prompt revocation. Give each integration a token limited to the ability it needs
(`read`, `dns`, or `purge`) and to its domains, and enrol every administrator in
two-step sign-in (see [users and access](../guides/users-and-access.md)). Tokens
expire after 90 days by default. Disable departed users, remove domain
assignments, and review audit logs. Protect `/admin`, Horizon, and the metrics
token at network boundaries as well as application authorization.

## Recovery and rotation

Test restore on an isolated host. Rotate one credential boundary at a time with
overlap, then verify health and revoke the old credential. A successful backup
is not sufficient until its snapshot can be opened and the complete recovery
set is available.

## Runtime limits

Select the security profile based on traffic risk, not throughput aspiration.
Monitor 80% capacity signals, queue age, cache/temp storage, Vector buffers, and
Loki host disk, and clock. Use upstream DDoS controls for uplink saturation.

## GeoIP database

The updater downloads the database to a temporary file, checks it, and only then
renames it into place. In production (`MMDB_REQUIRE_VERIFICATION=true`) a download
must match `MMDB_EXPECTED_SHA256` or the checksum published at `MMDB_CHECKSUM_URL`;
otherwise it is refused and the previous database stays. Neither of the default
public providers publishes a checksum, so production needs a mirror you control, a
pinned hash, or a provider that does. On a first start with no usable download the
updater installs an empty database bundled in its image so DNS still starts: Geo-DNS
records then return their default answers, `FALLBACK_IN_USE` appears in the volume,
and the next verified download replaces it. PowerDNS maps the file into memory, so
its start script reloads PowerDNS within a minute of the file changing.

## Valkey

Queues, sessions, and locks use one Valkey that is persistent and never evicts. The
application cache uses a second one (`cache`) with no persistence and
least-recently-used eviction, so a full cache cannot stop job dispatch. Both read
their password from `VALKEY_CONFIG_FILE`, a mounted configuration file, so it is not on
a command line.

## TLS sessions

Edge TLS uses the shared session cache but no session tickets: a ticket key lives
for the life of an nginx process and is not rotated, so its later compromise would
open recorded sessions. OCSP stapling is not offered: the public CA used for managed
certificates stopped serving OCSP in 2025, certificates are chosen per handshake from
many, and an uploaded certificate's responder cannot be relied on from every edge.
