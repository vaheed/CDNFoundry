---
title: Deployment hardening
description: Harden CDNFoundry networks, secrets, identities, containers, origins, backups, and operations.
---

# Deployment hardening

## Public surface

Publish only:

- Caddy control HTTP/HTTPS;
- DNSdist UDP/TCP 53;
- intended shared/quarantine OpenResty HTTP/HTTPS addresses;
- edge-control mutual TLS from exact edge sources;
- source-restricted DNS API and telemetry TLS gateways.

Keep PostgreSQL, Valkey, PowerDNS, ClickHouse, Vector internals, Prometheus,
Alertmanager, node-exporter, OpenResty control, and container APIs private.
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

Pin immutable image tags and verify digests. Production core, edge, agent, and
gateway services use read-only roots where supported, tmpfs for writable
ephemera, restart policies, health checks, bounded stop periods, and resource
limits. Retain named volumes across upgrades.

## Origin policy

Prefer public, authenticated HTTPS origins. Use a minimal private CIDR allowlist
only when routing is intentional. Built-in loopback, link-local, metadata,
multicast, reserved, edge-service, platform, and proxy-loop blocks cannot be
overridden. Keep TLS verification enabled with correct SNI and hostname.

## Accounts

Use separate named accounts, strong passwords, short-lived API tokens per
automation device, and prompt revocation. Disable departed users, remove domain
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
clock. Use upstream DDoS controls for uplink saturation.
