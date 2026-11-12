---
title: Admin panel tour
description: Every screen of the administrator panel, what it shows, what you can do there, and which guide explains it in depth.
---

# Admin panel tour

The admin panel is where administrators run the platform. This page walks
through every screen in the menu, in the order you see it.

**You need:** an administrator account and the address
`https://<control host>/admin/login`. Domain users cannot sign in here. They use
the [domain panel](domain-panel.md).

<Screenshot name="admin-overview" alt="The admin panel Overview page, with the menu on the left grouped as Observe, Operate, Infrastructure, Customers, and Governance." />

The menu has five groups. Press `Ctrl+K` (or `⌘K`) at any time to search for a
domain, user, edge, or page.

| Group | Screens |
| --- | --- |
| [Observe](#observe) | Overview, Traffic and telemetry, Live Logs |
| [Operate](#operate) | Operations |
| [Infrastructure](#infrastructure) | Service pools, Edges, System DNS identity, DNS primary |
| [Customers](#customers) | Users, Domains |
| [Governance](#governance) | API tokens, Notifications, Platform settings, Audit logs |

## Observe

### Overview

Your first screen. It shows platform health, traffic for a time range, and a table
of edges with their state, heartbeat, revision, and current issue. Narrow it with
the **Time range**, **Domain**, and **Edge** filters. Turn on **Compare previous
period** to see change against the period before.

Use it every day to check that nothing needs attention. For a deeper look, open
[Grafana](../operations/grafana-by-team.md). See also
[Operations dashboard](../operations/laravel-operations-dashboard.md).

### Traffic and telemetry

Charts and tables built from telemetry: traffic, status codes, cache, and
errors, for all domains or one (**Domain**, optional). The **Rebuild usage**
action recalculates usage totals from raw data if they look wrong. See
[Analytics](analytics.md).

### Live Logs

Opens Grafana's log explorer for live log lines. It appears only after an
administrator sets the Grafana address in **Platform settings**.

## Operate

### Operations

Every background job the platform has run. Each row shows the operation ID, who
requested it, its state, and any error. When you save a change and the panel says
it was queued, this is where you watch it finish. See
[Revisions and operations](../concepts/revisions-and-operations.md).

## Infrastructure

### Service pools

A pool is a group of cells that serve the same kind of traffic. This screen lists
pools and lets you set, for each: its **Routing**, **Cache** and **Compression**
profiles, the **Managed WAF** level, the minimum number of ready cells, replicas
per edge, and maintenance. Open a pool to see its edge cells. See
[Multi-cell pools](../operations/multi-cell-pools.md).

### Edges

Each row is a PoP (one edge host). It shows status, location, last heartbeat,
agent and version, cells, and version drift. Open an edge to:

- see its cells and assign or unassign a service pool to each;
- see its pool endpoints, which are the service addresses it publishes;
- choose **Rotate identity** to revoke its identity and issue a new enrollment
  token;
- read the **Enrollment instructions** for a new edge.

**Reconcile all domains** on the list queues a refresh of every domain's edge
configuration. See [Edges and placement](edges.md).

### System DNS identity

The public face of your DNS: the platform domain, the two or more nameservers
with their addresses, the SOA timers and TTLs, the proxy hostname, and the
customer nameserver pairs. A save shows a review window first, and nothing
changes until you confirm with **Save DNS identity and queue update**. See
[Domains](domains.md#nameserver-pairs) and [DNS](dns.md).

### DNS primary

The hidden PowerDNS server that holds every zone. You register it with its API URL
and key, then use **Test connection**. Enable it only when the test succeeds.
**Reconcile all zones** re-sends every zone. See [DNS nodes](../operations/dns-nodes.md).

## Customers

### Users

Create and manage accounts. A user is an administrator or a domain user. Set the
name, email, and password, and use **Disable access** or **Enable access** on a
user. A disabled user cannot sign in. See [Users and access](users-and-access.md).

### Domains

All domains on the platform. Open one to see the same domain page that its owner
sees, plus the users assigned to it. See [Domains](domains.md) and
[the domain panel tour](domain-panel.md#a-domain-page).

## Governance

### API tokens

Your own tokens for the [API](../reference/api/index.md). A token is shown once,
when you create it. You can limit a token to some domains and give it an expiry.

### Notifications

Platform channels for Telegram, Discord, Slack, and signed webhooks. They receive every
alert and every domain event. See [Notifications](notifications.md).

### Platform settings

Product policy that is not an environment variable, in groups such as display, operations,
telemetry, observability, DNS lifecycle, revision history, rate limits, edge
runtime, origin safety, and proxy defaults. Each group saves with
its own button and is audited. See [Platform settings](../reference/platform-settings.md).

### Audit logs

A record of who did what and when: the actor, the subject, the time, and the IP
address. Use it to answer "who changed this?".

### First-run setup

A short wizard that appears only on a fresh install that has one administrator.
It walks through the platform settings and shows every default. After you finish
or skip it, it disappears.

## Where to go next

- **New to the platform?** [What is in CDNFoundry](../getting-started/whats-in-the-box.md)
- **Adding your first customer?** [Users and access](users-and-access.md)
- **Something is slow or failing?** [Troubleshoot by symptom](../troubleshooting/by-symptom.md)
