---
title: Respond to an incident
description: Choose the right control when a domain or pool is under attack or must stop serving, apply it, check it worked, and undo it.
---

# Respond to an incident

When traffic is abusive, an origin is failing, or a site must stop for a while,
CDNFoundry has controls at four levels: a domain, an edge, a cell, and a pool.
This page helps you pick the smallest control that solves the problem, apply it,
and undo it.

**Rule of thumb:** use the smallest scope that works. A domain control affects
one customer. A pool control affects everyone on that pool.

**You need:** an administrator account for most controls. A domain owner can
start maintenance for their own domain.

## Pick a control

| Situation | Control | Scope | Who |
| --- | --- | --- | --- |
| One domain is flooded with requests | **Enable Under Attack mode** | Domain | Administrator |
| One domain misbehaves and hurts others | **Quarantine domain** | Domain | Administrator |
| A site must show "temporarily unavailable" | **Start maintenance** | Domain | Domain owner |
| A pool must show "temporarily unavailable" | **Maintenance** | Pool | Administrator |
| A PoP must stop receiving traffic | Drain the edge | Edge | Administrator |
| One cell is unhealthy | Drain or restart the cell | Cell | Administrator |
| A pool must leave DNS entirely | Withdraw the pool | Pool | Administrator |
| Common attacks on a domain | Raise the WAF level | Domain | Domain owner |

## Apply a control

### Incident profile on a domain

The incident profile is one temporary bundle of protections for one domain, and a domain user can turn it on for their own
domains. Open the domain, choose **Security, Start incident profile**, and pick how long it stays on (15
minutes to 24 hours).

For that time the domain gets:

- protected request and connection limits for each client;
- the web application firewall in blocking mode at high sensitivity;
- only `GET`, `HEAD`, `OPTIONS`, and `POST`, with request bodies up to 1 MiB;
- a browser challenge before a visitor reaches the origin.

The profile ends by itself when its time is up, or sooner with **End incident profile**. The domain page shows a banner while it
is on, with who started it, when it ends, and each protection. Starting and ending it each send one overlay to the cells and no
configuration revision, and each is recorded in the audit log. Users who chose the **Incident profile started** and **ended**
events in [notifications](notifications.md) get a message for each.

The cell enforces every protection from the overlay and ends the profile by its own clock, so it stops on time even if the control
plane is unreachable.

**The browser challenge.** A visitor's page load without a token gets a short page that asks the browser to solve a small
computation (about a second on a current device) and then continues on its own. The answer is single use and is bound to the
visitor's address and browser; the token it earns works until the profile ends or for an hour, whichever is sooner, and is
the only cookie the profile sets. Requests that are not page loads (scripts, bots, API clients) and have no token are refused.
Paths under `/api/` and `/.well-known/`, and visitors that one of your own allow rules matches, are not challenged. The page
explains itself to screen readers and tells a visitor without JavaScript what to do. The check needs HTTPS, because browsers
only offer the cryptography it uses on secure pages. Each address can be issued only a limited number of challenges a minute, so
the challenge cannot be turned into a flood of its own.

Refusals are recorded as security events with the reasons `incident_method`, `incident_body_size`, `challenge_required`, and
`challenge_invalid`. They show the profile working and never raise the domain's automatic security state.

This protects your origin and the cells from request floods. It cannot absorb traffic that saturates a host, a link, or an
upstream. The same actions are in the API under `/api/domains/{domain}/incident`.

### Under Attack mode, quarantine, and maintenance on a domain

<DocTabs :labels="['Panel', 'API']">
<template #tab-1>

1. Open the domain and its **Domain actions** menu.
2. Choose **Enable Under Attack mode**, **Quarantine domain**, or **Start maintenance**.
3. For maintenance, set the **503 response message** visitors will see.
4. Confirm.

</template>
<template #tab-2>

```sh
curl -X POST "$CDNF_URL/api/admin/domains/$DOMAIN_ID/protect" \
  -H "Authorization: Bearer $CDNF_TOKEN" -H "Idempotency-Key: $(uuidgen)"
```

Use `/quarantine` instead of `/protect` to quarantine. Both return `202 Accepted`
with an operation ID.

</template>
</DocTabs>

**Expected result:** an operation starts, and the domain's security state changes.
Under Attack mode tightens request and connection limits for a time. It does not
move the domain or change the WAF. Quarantine deploys the domain to the
quarantine pool first, then drains the shared source, so there is no gap.
Maintenance returns HTTP 503 for that domain only. Only a proxied domain can be quarantined.

### Maintenance on a pool

<DocTabs :labels="['Panel', 'API']">
<template #tab-1>

1. Open **Infrastructure → Service pools** and choose the pool.
2. Choose **Maintenance**.
3. Set **Automatic expiry (minutes)**. It is required, from 1 to 1440 (24 hours).
4. Confirm.

</template>
<template #tab-2>

```sh
curl -X POST "$CDNF_URL/api/admin/edge-pools/$POOL_ID/maintenance" \
  -H "Authorization: Bearer $CDNF_TOKEN" -H "Idempotency-Key: $(uuidgen)" \
  -H "Content-Type: application/json" -d '{"duration_minutes": 30}'
```

</template>
</DocTabs>

**Expected result:** the pool's cells return HTTP 503 until the expiry. Only one
maintenance can be active on a pool at a time. A second attempt is refused
with a conflict until you end the first one.

## Check it worked

1. Open the operation in **Operate → Operations**. It should reach *succeeded*.
2. Request the site yourself and read the response. Maintenance returns 503.
3. In Grafana, open **Security overview** and look for the domain among the
   emergency-mode domains.

## Undo it

| You applied | Undo with |
| --- | --- |
| Under Attack mode, quarantine | **Return to normal** on the domain |
| Domain maintenance | **End maintenance** on the domain |
| Pool maintenance | **End maintenance** on the pool, or wait for its expiry |
| Edge or cell drain | **Undrain** |
| Pool withdraw | **Restore** on the pool |

Pool maintenance expires on its own. Domain controls stay until you undo them,
and the scheduler moves quiet domains through recovery.

**If an operation fails:** the previous state stays in place, so the domain keeps
serving as before. Read the error on the operation and try again, or follow
[Failed operations](../operations/runbooks.md#failed-operations).

## Learn more

- [Security](security.md) explains the security states and every control in depth.
- [Managed WAF](managed-waf.md) explains the WAF levels.
- [Runbooks](../operations/runbooks.md) has the alert-driven steps, including
  [Emergency mode on](../operations/runbooks.md#emergency-mode),
  [Request flood](../operations/runbooks.md#request-flood), and
  [DNS flood](../operations/runbooks.md#dns-flood).
