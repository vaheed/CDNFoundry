---
title: Production setup after quick start
description: Administrator handoff for an installed CDNFoundry fleet.
---

# Production setup after quick start

This guide starts after the [production quick start](production-quick-start.md) has
installed and qualified the control host and both POPs. Keep the verified fleet
configuration, release manifest, recovery keys, and backup location in the
operator's secure inventory. Use the [multi-region quick start](production-quick-start-multi-region.md)
when locations are split across failure domains.

## First administrator access

1. Open the administrator panel at the control hostname configured in the fleet.
2. Sign in with the one-time bootstrap administrator created during quick start.
3. Create a named administrator account for each operator. Confirm each account
   has `users.type = admin` before relying on administrator access.
4. Verify the new account can sign in, then retire the bootstrap credential.
   Store recovery credentials outside the fleet.
5. Give domain users only their assigned domains. Test a domain-user account
   against an assigned and an unassigned domain before inviting customers.

Browser actions and exact expected results are recorded in the
[manual browser qualification](https://github.com/vaheed/CDNFoundry/blob/dev/docs/manual-browser-qualification.md). API clients
use the same policies as browser sessions. Store API tokens securely; token
secrets are shown only when issued.

## Bring a domain into service

1. Create the domain under its intended owner. Record the operation ID for
   asynchronous changes and wait for successful reconciliation.
2. Complete ownership verification using the exact challenge and record shown
   by the panel. Confirm the active ownership state before changing delegation.
3. Add DNS records with their intended TTLs. Check both authoritative POPs over
   UDP and TCP before changing registrar NS records. Keep management DNS at an
   independent provider.
4. Configure one validated origin for each proxied hostname. Confirm its Host
   header, scheme, port, and TLS name. Test the origin directly, then test each
   POP with the customer hostname.
5. Enable proxying only after DNS and origin checks pass. Confirm DNS-01
   issuance, certificate status, HTTPS response, and renewal monitoring.
6. Test both IPv4 and IPv6 paths if IPv6 is enabled. Confirm cache and purge
   behavior using the same URL at each POP.

The [quick start acceptance sequence](production-quick-start.md) gives the
commands and expected network responses. Use the
[certificate guide](certificates.md) for uploaded certificates and renewal.

## Users and limits

CDNFoundry grants domain access through domain assignments. Create users in the
administrator panel, assign only the domains they operate, and verify the
assignment with a separate user session. Set domain policies and traffic limits
within the fields provided by the panel. There is no organization or reseller
hierarchy; group ownership externally if your business requires it.

## Daily operating checks

1. Review control, DNS, edge, queue, and telemetry health. Confirm both POPs
   are current and serving their assigned domains.
2. Check failed operations and Horizon queues. Resolve the cause and retry the
   revision; never edit derived PowerDNS or edge state directly.
3. Review DNS answers, certificate expiry, origin errors, cache hit rate,
   security events, disk use, and bounded Vector buffers.
4. Confirm backups completed and an off-host copy exists. Periodically restore
   to an isolated environment with application encryption and signing keys and
   externally stored TLS material.

Use the [fleet operator guide](production-fleet-operator-guide.md) for health,
logs, node lifecycle, and recovery commands, and the
[configuration reference](../reference/configuration.md) for environment values.
Keep `.env.prod` and fleet secrets outside version control.

## Changes, failures, and recovery

- **Deploy or roll back:** follow the [upgrade procedure](upgrade.md). Verify
  an immutable release, back up state, roll out by dependency order, and check
  each host before continuing. Preserve the previous valid bundle for rollback.
- **Add or remove a server:** update the validated fleet topology, render a
  candidate bundle, enroll the edge identity, activate and verify the target,
  then drain the source. See the [fleet operator guide](production-fleet-operator-guide.md).
- **Add a region:** use separate failure domains and regional node identifiers
  in the [multi-region topology](production-quick-start-multi-region.md). Verify
  management reachability, authoritative DNS, and regional traffic before
  moving customer traffic.
- **Control outage:** existing valid DNS and edge snapshots continue serving.
  Restore PostgreSQL and required keys, then resume reconciliation and verify
  revisions before accepting new changes.
- **POP outage:** verify the surviving POP serves traffic, repair or replace the
  failed node, and recheck DNS and HTTPS before restoring its traffic share.
- **Troubleshooting:** inspect the failed operation, current revision, host
  health, and service logs. Follow the [fleet troubleshooting steps](production-fleet-operator-guide.md#troubleshooting).

Do not treat this handoff as a release qualification record. Record each
installation's actual health checks, backup restore result, browser checklist,
and failure exercise separately.
