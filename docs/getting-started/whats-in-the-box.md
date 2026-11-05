---
title: What is in CDNFoundry
description: Every tool CDNFoundry gives you, who uses each one, how to open it, and which page explains it.
---

# What is in CDNFoundry

CDNFoundry is not one program. It is a set of tools that work together. This
page lists all of them, so you know which one to open for which job.

**Read this if:** you have just found the project and want the whole picture
before you install anything.

## The tools at a glance

| Tool | Use it to | Who uses it | How you open it |
| --- | --- | --- | --- |
| **Admin panel** | Run the platform: pools, PoPs, DNS, users, settings, audit | Administrators | `https://<control host>/admin/login` |
| **Domain panel** | Manage your own domains: DNS, origins, TLS, cache, security, analytics | Domain users | `https://<control host>/app/login` |
| **API** | Do anything the panels do, from scripts | Both | `https://<control host>/api`, with a token |
| **Fleet CLI** | Install, upgrade, back up, and roll back hosts | Infrastructure operators | `scripts/cdnfoundry-fleet` on your workstation |
| **Fleet web** | The same Fleet actions, as a web page | Infrastructure operators | `cdnfoundry-fleet web`, then `http://127.0.0.1:8765` on your workstation |
| **Grafana** | Watch traffic, security, hosts, and usage on dashboards | Operations, security, support, business | `http://127.0.0.1:3000` on the telemetry host, through your own authenticated proxy |
| **Alertmanager** | Receive alerts for problems | On-call | Alerts go to the receiver you configure |
| **Documentation** | Learn and look things up | Everyone | This site |

Prometheus, Loki, ClickHouse, PostgreSQL, and Valkey also run, but you do not
open them. They store data and metrics for the tools above. Keep them private.

## Pick your tool by job

| I want to | Open | Read |
| --- | --- | --- |
| Install a fleet | Fleet CLI or Fleet web | [Production quick start](../deployment/production-quick-start.md) |
| Add a domain and serve it | Domain panel, or the API | [Domains](../guides/domains.md) |
| Change DNS records | Domain panel, or the API | [Authoritative DNS](../guides/dns.md) |
| See if a PoP is healthy | Admin panel **Edges**, or Grafana **PoP fleet** | [Edges and placement](../guides/edges.md) |
| See traffic and errors | Domain panel **Analytics**, or Grafana | [Analytics](../guides/analytics.md) |
| Upgrade the platform | Fleet CLI or Fleet web | [Upgrade](../deployment/upgrade.md) |
| Respond to an alert | Grafana, then the runbook | [Runbooks](../operations/runbooks.md) |
| Back up and restore | Fleet, plus the admin panel | [Backup and recovery](../operations/backup-and-recovery.md) |
| Let a customer use the CDN | Admin panel **Users** | [Users and access](../guides/users-and-access.md) |

## What runs behind the tools

- **Control plane:** the Laravel application behind both panels and the API. It
  stores what you want and sends it to DNS and the PoPs. Customer traffic never
  passes through it.
- **DNS:** DNSdist answers the public queries, and PowerDNS holds the zones.
- **PoPs:** each point of presence runs an edge agent, a gateway, and eight
  OpenResty cells that serve HTTP and HTTPS.
- **Telemetry:** Vector collects logs and sends them to ClickHouse. Prometheus
  collects metrics, and Loki stores operational logs.

For the diagram, see [How it works in four diagrams](../concepts/the-four-flows.md).

## Where to start

1. **Evaluating?** Read [Why CDNFoundry](why-cdnfoundry.md) and the
   [FAQ](faq.md).
2. **Installing?** Follow the [production guide](../deployment/production-guide.md).
3. **Using a domain?** Start with [First domain](first-domain.md).
4. **Running the platform?** Read [Operations](../operations/index.md).
5. **Contributing?** Read the [contribution guide](../contributing/index.md).
