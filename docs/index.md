---
layout: home
title: Self-hosted private CDN software
description: Run your own CDN with authoritative DNS, edge delivery, TLS, caching, security, and analytics on servers you control.
keywords: private CDN software, self-hosted CDN, ISP CDN, on-premises CDN, authoritative DNS, edge caching, PowerDNS, DNSdist, OpenResty, Laravel CDN
hero:
  name: CDNFoundry
  text: A CDN you run on your own servers
  tagline: Free and open source under the Apache 2.0 license. DNS, HTTPS delivery, caching, and analytics stay on servers you control.
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
<Card title="Evaluate" link="/getting-started/private-cdn-design">Check whether a private CDN fits your network, and what it needs.</Card>
<Card title="Install" link="/getting-started/installation">Start a local stack, then add your first domain.</Card>
<Card title="Operate" link="/deployment/">Deploy a production fleet, upgrade it, back it up, and answer alerts.</Card>
<Card title="Use as a domain owner" link="/guides/">Add a domain, set DNS records, proxy an origin, and manage TLS and cache.</Card>
<Card title="Look something up" link="/reference/">API, configuration, limits, ports, DNS records, and the glossary.</Card>
<Card title="Contribute" link="/contributing/">Set up the development stack, run the tests, and send a change.</Card>
</CardGrid>

## What it does

| Area | What you get |
| --- | --- |
| Control plane | One web application with an admin panel, a domain-user panel, and a token API. |
| DNS | A public DNS front end (DNSdist) in front of private PowerDNS. Records can be DNS-only, proxied, or Geo-DNS. |
| Edge | A fixed number of OpenResty cells on each point of presence (PoP). A bad change never replaces the last working one. |
| TLS | Managed certificates (DNS-01) and uploaded certificates. Private keys are encrypted at rest. |
| Cache and security | URL purge and full purge, per-client limits, and a managed web application firewall (WAF). |
| Analytics | Logs and usage in ClickHouse, and Grafana dashboards for operators. |

## What it does not do

CDNFoundry is not a hosted service. You run it. It also cannot stop a
volumetric attack that fills your uplink. See the [FAQ](getting-started/faq.md) and the
[private CDN design guide](getting-started/private-cdn-design.md) for the
trade-offs.

</div>
