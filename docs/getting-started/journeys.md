---
title: Your path through the docs
description: Follow an ordered reading path for your role, from first look to daily work, for evaluators, platform administrators, domain owners, on-call operators, and contributors.
---

# Your path through the docs

Pick the role that fits you and follow the pages in order. Each path starts with
what you need to know first and ends with the pages you will return to every day.

## I am evaluating CDNFoundry

You want to decide whether it fits, before you install anything.

1. [Why CDNFoundry](why-cdnfoundry.md): who it is for, what it does and does not do.
2. [What is in CDNFoundry](whats-in-the-box.md): every tool and who uses it.
3. [How it works in four diagrams](../concepts/the-four-flows.md): DNS, HTTP, change, and telemetry.
4. [Choose your installation](../deployment/choose-installation.md) and the [sizing guide](../deployment/sizing.md): what it would take to run.
5. [Install the lab on one machine](installation.md) to see it working.
6. [FAQ](faq.md) for the questions people ask next.

## I am the platform administrator

You install the platform, keep it healthy, and give customers access.

1. [Choose your installation](../deployment/choose-installation.md), then follow the [production guide](../deployment/production-guide.md).
2. [Admin panel tour](../guides/admin-panel.md): every screen you will use.
3. [First domain](first-domain.md): serve a test domain end to end.
4. [Users and access](../guides/users-and-access.md): add your first customer.
5. [Fleet web guide](../operations/fleet-web.md): install, upgrade, and back up hosts.
6. [Grafana by team](../operations/grafana-by-team.md) and the [alert reference](../reference/alerts.md): watch the platform.
7. [Backup and recovery](../operations/backup-and-recovery.md): and practice a restore.
8. [Upgrade](../deployment/upgrade.md) when a new release arrives.

## I own a domain

You use the CDN for your own sites. An administrator has created your account.

1. [Domain panel tour](../guides/domain-panel.md): where things are.
2. [Domains](../guides/domains.md): add a domain and set its nameservers.
3. [Authoritative DNS](../guides/dns.md): add your records.
4. [Proxy and origins](../guides/proxy-and-origins.md): point the CDN at your server.
5. [TLS](../guides/tls.md): get HTTPS.
6. [Cache and purge](../guides/cache.md) and [Compression](../guides/compression.md): make it fast.
7. [Security](../guides/security.md) and [Managed WAF](../guides/managed-waf.md): protect it.
8. [Analytics](../guides/analytics.md): see what happens.
9. Stuck? [Troubleshoot by symptom](../troubleshooting/by-symptom.md).

## I am on call

You respond when something breaks.

1. [Grafana by team](../operations/grafana-by-team.md): open **Now** and read the first line.
2. [Alert reference](../reference/alerts.md): look up the alert, then open its runbook.
3. [Runbooks](../operations/runbooks.md): the steps for each situation.
4. [How health is checked](../operations/health-probes.md): why a PoP is or is not in DNS.
5. [Respond to an incident](../guides/incident-response.md): the controls, and how to undo them.
6. [Troubleshoot by symptom](../troubleshooting/by-symptom.md): when you only see the symptom.

## I want to contribute

You want to change CDNFoundry.

1. [Contribution guide](../contributing/index.md): the rules and how to send a change.
2. [Developer setup](../development/index.md) and the [project layout](../development/project-layout.md).
3. [Testing](../development/testing.md): the checks that must pass.
4. [Documentation rules](../contributing/documentation.md): how to write a page.
