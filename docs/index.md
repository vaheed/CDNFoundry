---
layout: home
title: Self-hosted private CDN software
description: Run your own CDN with authoritative DNS, edge delivery, TLS, caching, security, and analytics on servers you control.
keywords: private CDN software, self-hosted CDN, ISP CDN, on-premises CDN, authoritative DNS, edge caching, PowerDNS, DNSdist, OpenResty, Laravel CDN
hero:
  name: CDNFoundry
  text: A CDN you run on your own servers
  tagline: Free and open source. DNS, HTTPS delivery, caching, and analytics stay on infrastructure you control.
  actions:
    - theme: brand
      text: Install the quick start
      link: /getting-started/
    - theme: alt
      text: Read how it works
      link: /concepts/how-cdnfoundry-works
---

<div class="vp-doc cdnf-home">

## Choose where you are

<CardGrid>
<Card title="Evaluate" link="/getting-started/private-cdn-design">Decide whether a private CDN fits your network, and what it needs.</Card>
<Card title="Install" link="/getting-started/installation">Start a control node and a first PoP, then serve a domain.</Card>
<Card title="Operate" link="/deployment/">Deploy a production fleet, upgrade it, back it up, and respond to alerts.</Card>
<Card title="Use as a domain owner" link="/guides/">Add a domain, set DNS records, proxy an origin, and manage TLS and cache.</Card>
<Card title="Look something up" link="/reference/">API, configuration, limits, ports, DNS records, and the glossary.</Card>
<Card title="Contribute" link="/contributing/">Set up the development stack, run the tests, and send a change.</Card>
</CardGrid>

## What it does

| Area | What you get |
| --- | --- |
| Control plane | A Laravel and Filament panel for administrators and for domain users, plus a token API. |
| DNS | Public DNSdist in front of private PowerDNS, with DNS-only, proxied, and Geo-DNS records. |
| Edge | Bounded OpenResty cells on each PoP, with stable placement and a last-valid-state rule. |
| TLS | Managed DNS-01 certificates and uploaded certificates, with keys encrypted at rest. |
| Cache and security | Deterministic cache keys, URL and full purge, per-client limits, and a managed WAF. |
| Analytics | Vector to ClickHouse for logs and usage, and Grafana dashboards for operators. |

## What it does not do

It is not a hosted service, and it does not scrub volumetric attacks that fill
your uplink. See the [FAQ](getting-started/faq.md) and the
[private CDN design guide](getting-started/private-cdn-design.md) for the
trade-offs.

</div>
