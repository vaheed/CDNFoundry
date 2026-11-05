---
title: Domain panel tour
description: Every screen of the domain-user panel, how to read a domain page, and where each task lives.
---

# Domain panel tour

The domain panel is where you manage your own domains. You see only the domains
an administrator assigned to you.

**You need:** a domain-user account and the address
`https://<control host>/app/login`. Administrators sign in at `/admin/login`
instead. See the [admin panel tour](admin-panel.md).

<Screenshot name="app-domains" alt="The Domains list in the domain panel." />

The menu has three screens.

| Screen | Use it to |
| --- | --- |
| [Domains](#domains) | List your domains and open one to manage it |
| [Analytics and logs](#analytics-and-logs) | See traffic, cache, and errors for a domain |
| [API tokens](#api-tokens) | Create tokens to use the API from scripts |

## Domains

The list shows each domain with its status, nameservers, record count, and last
change. Choose **Add domain** to add one. See [Domains](domains.md) for the steps
from adding it to serving traffic.

### A domain page

Open a domain to see its page. It has an **Overview** and tabs for the work:

| Tab | What it holds | Read |
| --- | --- | --- |
| **Overview** | Cards for Delegation, DNS, Delivery, TLS, Cache, and Security | This page |
| **DNS records** | Your records, with proxy, origin, health-check, backup-origin, and Geo-DNS settings | [DNS](dns.md), [Proxy and origins](proxy-and-origins.md), [Geo-DNS](geo-dns.md) |
| **Security rules** | Allow and block rules by network, country, or continent | [Security](security.md) |
| **WAF exclusions** | Rules you turn off for a path, with an owner | [Managed WAF](managed-waf.md) |

The **Overview** cards answer the common questions at a glance:

- **Delegation:** the nameservers to set, and whether they are verified.
- **DNS:** how many records you have and the zone state.
- **Delivery:** which pool serves the domain and the last revisions.
- **TLS:** the certificate mode and expiry.
- **Cache:** whether caching is on and its limits.
- **Security:** the current security state.

### What you can do from a domain page

The **Domain actions** menu holds the one-time and rare actions:

| Action | What it does | Read |
| --- | --- | --- |
| **Verify nameservers**, **Activate**, **Disable** | Take the domain through its life | [Domains](domains.md) |
| **Import zone**, **Export zone** | Move DNS records in or out as a zone file | [DNS](dns.md) |
| **Reconcile authoritative DNS** | Re-send the zone to DNS | [DNS](dns.md) |
| **TLS mode**, **Renew managed certificate**, **Reissue managed certificate**, **Upload custom certificate**, **Remove custom certificate** | Manage the certificate | [TLS](tls.md) |
| **Cache settings**, **Purge cache**, **Enable development mode** | Control caching and purge | [Cache and purge](cache.md) |
| **Proxy defaults**, **Rollback proxy revision**, **Move service pool** | Change delivery | [Proxy and origins](proxy-and-origins.md) |
| **Security profile and limits**, **Web application firewall (WAF)**, **Start maintenance**, **Enable Under Attack mode**, **Quarantine domain**, **Return to normal** | Protect the domain | [Security](security.md), [Managed WAF](managed-waf.md) |

Actions that change a live service show a confirmation first, and long ones
start a background operation you can follow.

## Analytics and logs

Choose **Select domain** and pick one of your domains. Nothing loads until you do.
You then see a summary and time series, status codes and cache outcomes,
countries, hostnames, top URL paths, origin health, compression savings, and DNS
queries for that domain. See [Analytics](analytics.md).

## API tokens

Create a token with a name and a lifetime. You can limit it to some of your
domains. The token value is shown once, so copy it when you create it. See the
[API reference](../reference/api/index.md).

## Where to go next

- **First time?** [First domain](../getting-started/first-domain.md)
- **Something not working?** [Troubleshoot by symptom](../troubleshooting/by-symptom.md)
