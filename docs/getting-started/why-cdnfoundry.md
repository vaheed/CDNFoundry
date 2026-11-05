---
title: Why CDNFoundry
description: Who CDNFoundry is for, what it does and does not do, and how it compares with a commercial CDN and with a hand-built Nginx setup.
---

# Why CDNFoundry

CDNFoundry is free, open-source software for running your own CDN. You install
it on your servers. DNS, HTTPS traffic, certificates, cache, and logs stay on
your infrastructure.

## Who it is for

Choose it if you must keep traffic and data on your own network, or if you
already run servers and want a CDN you control:

- internet service providers and hosting providers;
- enterprises with sites in several regions;
- governments and universities;
- anyone who cannot send traffic or logs to a third-party CDN.

## What it does

- Serves authoritative DNS, including Geo-DNS.
- Serves HTTP and HTTPS from bounded edge cells, with a cache, purge, and
  compression.
- Issues and renews certificates with DNS-01, or accepts your own.
- Applies per-client limits, origin safety checks, and a managed WAF.
- Collects logs and usage into ClickHouse, with Grafana dashboards for
  operators.
- Gives administrators and domain users a panel and an API.
- Installs and upgrades hosts with the Fleet tool.

## What it does not do

- It is not a hosted service. You run it, patch it, and answer its alerts.
- It does not stop attacks that fill your uplink. It limits requests per
  client, domain, and cell only.
- It cannot reach a private origin. Origins must resolve to allowed public
  addresses.
- It has no billing, no reseller levels, and no plugin system.
- It does not need Kubernetes. It runs on Docker Compose.

## Compared with a commercial CDN

| | CDNFoundry | Commercial CDN |
| --- | --- | --- |
| Where traffic and logs go | Your servers | The provider's network |
| Where the PoPs are | Where you place servers | Where the provider has them |
| Capacity | What you buy and connect | The provider's, shared |
| Attack absorption | Your uplink | Usually offered as a service |
| Who runs it | You | The provider |
| Cost | Servers, transit, and your time | Usage-based fees |

Pick a commercial CDN when you need global reach or large attack absorption
and can accept the provider's terms. Pick CDNFoundry when control and placement
matter more.

## Compared with a hand-built Nginx setup

Nginx caches and proxies well. A CDN also needs the parts around it, and a
hand-built setup means you write and keep them:

| You need | In CDNFoundry | In a hand-built setup |
| --- | --- | --- |
| Authoritative DNS and placement | Included | You build it |
| Safe rollout and rollback of config | Signed, revisioned, last-valid kept | You script it |
| Certificates and key storage | Included, keys encrypted | You script it |
| Origin address checks | Included | You write them |
| Purge that matches the cache key | Included | You build it |
| Users, domains, and an API | Included | You build it |
| Logs and usage reports | Included | You build it |

If you only need one reverse proxy in one place, Nginx alone is simpler. See
[How to build a self-hosted private CDN](private-cdn-design.md) for the full
design trade-offs.

## Next

- [Quick start](index.md)
- [How it works in four diagrams](../concepts/the-four-flows.md)
- [FAQ](faq.md)
