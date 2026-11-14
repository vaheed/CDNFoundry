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

The menu has three screens, and a fourth when an administrator turns on notification channels.

| Screen | Use it to |
| --- | --- |
| [Domains](#domains) | List your domains and open one to manage it |
| [Analytics and logs](#analytics-and-logs) | See traffic, cache, and errors for a domain |
| [API tokens](#api-tokens) | Create tokens to use the API from scripts |
| [Log push](log-push.md) | On a domain page: send that domain's logs to your own endpoint |
| [Notifications](notifications.md) | Send events for your domains to Telegram, Discord, or Slack |

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
| **Custom rules** | Typed rules: up to five conditions and one action, with rate limits and presets | [Security](security.md#custom-rules) |
| **WAF exclusions** | Shown only when the domain still has exclusions saved before they were disabled. They have no effect; delete them | [Managed WAF](managed-waf.md) |

The **Overview** tab is a set of sections. Each shows its facts and, on its title row, the buttons that change them, so an
action sits next to what it changes and appears only once. Values are plain text and flat grey tags; actions are raised
buttons with an icon.

| Section | Shows | Actions |
| --- | --- | --- |
| **Delegation** | The nameservers to set, verification, and registration expiry | **Verify nameservers** (at the top while unverified) |
| **DNS deployment** | Each DNS cluster's desired and active zone revision | **Reconcile**, **Import zone**, **Export zone** |
| **Delivery** | Placement, pool, cells, revisions, and proxy settings | **Proxy settings**, **Maintenance**, **Roll back**, **Move service pool** (administrators) |
| **Protection** | Under attack mode, security state, profile, limits, WAF, and each detection, on or off | **Edit** or **Set up** on each row; **Quarantine** and **Return to normal** (administrators) |
| **TLS** | Certificate mode, names, expiry, and fingerprint | **Mode**, **Upload certificate**, **Renew**, **Reissue**, **Remove custom certificate** |
| **Cache** | The cache policy and purge epoch | **Settings**, **Purge**, **Development mode** |

A detection (security alert, credential check, sensitive data, AI protection, API schema, client-side security) is turned off
from its own sheet with **Turn off**. It cannot be turned off while a custom rule uses one of its fields; change or delete
those rules first. **Custom** in **TLS mode** can be chosen only once a valid custom certificate is uploaded.

The page updates itself after every change you make. While a change is still
deploying, it checks again every 5 seconds until the deployment finishes, so you
never need to reload it.

### What you can do from a domain page

At the top right, **Under attack mode** turns on the temporary protections described in
[Incident response](incident-response.md). **More actions** holds the rare actions that concern the whole domain:
**Activate**, **Disable**, and **Delete domain** ([Domains](domains.md)).

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
