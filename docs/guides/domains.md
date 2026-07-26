---
title: Domain lifecycle
description: Create, verify, activate, disable, deprovision, and re-create domains safely.
---

# Domain lifecycle

## Create

`POST /api/domains` and the domain create form accept a `name` and optional
`display_name`. Names are normalized by `DomainName` and must be registrable
DNS names. The built-in public-suffix guard covers the suffix list implemented
in `core/app/Support/DomainName.php`; it is intentionally not a full public
suffix database.

Creation:

- assigns the creating domain user;
- starts at `pending_verification`;
- creates the initial revision;
- queues DNS reconciliation;
- does not require an origin or certificate.

## Verify

`POST /api/domains/{domain}/verify-nameservers` creates an asynchronous
operation. The resolver compares public NS answers with the platform identity.
Administrators have a force-verify route for controlled local tests.

## Activate

Activation requires verified nameservers and changes lifecycle state to
`active`. DNS and edge work remain asynchronous. Use the domain status, DNS
deployment, and edge deployment endpoints to confirm acknowledgement.

## Disable

Disable stops new desired changes from representing an active customer service,
but retains last-valid runtime state for the `dns_lifecycle.deprovision_delay_days`
window. The scheduler then dispatches bounded DNS deprovisioning and final
domain retirement.

## Delete and reclaim

`DELETE /api/domains/{domain}` starts asynchronous retirement; it is not an
immediate row removal. Edge tombstones must be acknowledged before finalization.
The name is then held in `domain_name_tombstones` for
`domain_reclaim_cooldown_days`.

Never delete PowerDNS zones, edge files, or cache directories as a substitute
for this lifecycle.
