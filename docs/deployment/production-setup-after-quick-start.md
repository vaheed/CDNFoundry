---
title: Production setup after quick start
description: First steps after installing a CDNFoundry fleet - administrator accounts, your first domain, daily checks, and how to handle changes and failures.
---

# Production setup after quick start

You will set up named administrator accounts, bring a first domain into service,
and learn the daily checks and the common recovery paths.

**You need:** a fleet that passed the [production quick start](production-quick-start.md)
(control host and both PoPs). A PoP is a point of presence: a site with DNS and
edge servers. If your locations sit in separate failure domains, you used the
[multi-region quick start](production-quick-start-multi-region.md) instead; this
page applies to both. Keep the verified fleet configuration, the release
manifest, the recovery keys, and the backup location in your secure inventory.

## 1. Set up administrator access

<div class="steps">

1. Open the administrator panel at `https://control.<operator-domain>/admin`.
2. Sign in with the administrator you created during the quick start.
3. Under **Customers → Users**, create one named administrator account for each
   operator. Choose the administrator type. The API calls this `users.type = admin`.
4. Sign in as each new account to check that it works.
5. If several people shared the first account, disable it or give it a new
   password that only the recovery owner knows. Store recovery credentials
   outside the fleet.
6. Create domain users and assign them only the domains they run. Sign in as one
   of them, and check that an assigned domain opens and an unassigned domain does
   not, before you invite customers.

</div>

**Expected result:** every operator has a personal sign-in, and a domain user sees
only assigned domains. Domain users sign in at `/app/login`, administrators at
`/admin/login`. See [Users and access](../guides/users-and-access.md).

API clients follow the same rules as browser sessions. A token secret is shown
only once, when you issue it, so store it right away.

## 2. Bring a domain into service

<div class="steps">

1. Create the domain under its owner (see [Domains](../guides/domains.md)). Open
   its operation and wait until its zone reaches both PoPs.
2. At the registrar, set the nameservers that CDNFoundry assigned to the domain.
   Query the parent zone's authoritative servers directly, from the control host
   and from each PoP. The answers from a recursive resolver, or the NS records
   inside your own zone, do not prove the delegation. If the parent still returns
   the old nameservers, wait for them to change, then ask for verification again.
   When verification passes, choose **Activate**.
3. Add DNS records with the TTLs you want. Wait until both PoPs acknowledge the
   change. Then query both authoritative servers over UDP and TCP.
4. For each proxied hostname, set one origin. Check its Host header, scheme, port,
   and TLS name. Test the origin directly. Then test each PoP with the customer
   hostname.
5. Turn on proxying only after the DNS and origin checks pass. CDNFoundry issues
   the certificate with DNS-01 when the hostname first becomes proxied. Check the
   certificate status, the HTTPS response, and your expiry monitoring.
6. If IPv6 is on, test both IPv4 and IPv6. Request the same URL at each PoP to
   check caching, then purge it and check again.

</div>

The commands and expected answers for these checks are in the
[quick start acceptance steps](production-quick-start.md). For uploaded
certificates, see the [TLS guide](../guides/tls.md#custom-certificates). The
private certificates between your own services are on the
[internal certificates](certificates.md) page.

Keep your management DNS at an independent provider throughout.

## 3. Users and limits

CDNFoundry gives a domain user access through domain assignments. There is no
organization or reseller level. If your business needs groups, track them outside
CDNFoundry. Set domain policies and traffic limits with the fields the panel
offers.

## 4. Daily checks

<div class="steps">

1. Review the health of control, DNS, edges, queues, and telemetry. Check that
   both PoPs are current and serve their assigned domains.
2. Look for failed operations and failed Horizon jobs. Fix the cause and retry the
   operation. Do not edit PowerDNS or edge state by hand: those are derived and
   the next reconcile overwrites your change.
3. Review DNS answers, certificate expiry, origin errors, cache hit rate,
   security events, disk use, and the Vector buffers.
4. Check that the last backup finished and that a copy exists off the host.
   Restore to an isolated environment now and then. A restore needs the
   application encryption and signing keys, and the TLS material you keep outside
   the database.

</div>

For health, logs, node lifecycle, and recovery commands, use the
[Fleet operator guide](production-fleet-operator-guide.md). For environment
values, use the [configuration reference](../reference/configuration.md). Keep
`.env.prod` and Fleet secrets out of version control.

## 5. Changes, failures, and recovery

- **Deploy or roll back:** follow the [upgrade procedure](upgrade.md). Verify a
  signed release, back up state, roll out in dependency order, and check each host
  before you continue. Keep the previous valid bundle for rollback.
- **Add or remove a server:** update the validated fleet topology and render a new
  bundle. Enroll the edge identity. Check that the new target serves, then drain
  the old one. See the [Fleet operator guide](production-fleet-operator-guide.md).
- **Add a region:** use separate failure domains and region names in the
  [multi-region topology](production-quick-start-multi-region.md). Check
  management reachability, authoritative DNS, and regional traffic before you move
  customer traffic.
- **Control plane outage:** DNS and edges keep serving their last valid state.
  Restore PostgreSQL and the required keys, resume reconciliation, and check the
  revisions before you accept new changes.
- **PoP outage:** confirm the other PoP serves traffic. Repair or replace the failed
  node, and check DNS and HTTPS before you give it traffic again.
- **Troubleshooting:** look at the failed operation, the current revision, host
  health, and service logs. See the
  [Fleet troubleshooting steps](production-fleet-operator-guide.md#troubleshooting).

This page is a handoff guide, not a release record. Record your own health checks,
restore test, and failure exercise separately.
