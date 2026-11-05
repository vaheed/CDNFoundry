---
title: CDNFoundry overview
description: What CDNFoundry provides, who it is for, and which page to read first.
---

# CDNFoundry overview

CDNFoundry is a private CDN you run on your own servers. It answers DNS for your
domains, proxies and caches HTTP and HTTPS traffic, manages TLS certificates,
applies security limits, and records telemetry. You control the hardware, the
network, and the data.

::: info Serving and management are separate
The control plane (a Laravel web application) stores what you asked for. DNS and
HTTP requests never pass through it. If the control plane is down, you cannot
change anything, but DNS and edges keep serving the last state they activated.
:::

## Who uses it

There are two web panels. Both use the same permission rules as the API.

- `/admin` is for active users whose type is `admin`.
- `/app` is for active domain users. It shows only the domains assigned to them.

API clients sign in with Laravel Sanctum bearer tokens.

See [What is in CDNFoundry](whats-in-the-box.md) for every tool and how to open it, and [Your path through the docs](journeys.md) for a reading order by role.

## Choose your path

| You are | Start here | Then read |
| --- | --- | --- |
| New to CDNs | [CDN fundamentals](../concepts/cdn-fundamentals.md) | [How CDNFoundry works](../concepts/how-cdnfoundry-works.md), then [Desired state](../concepts/desired-state.md) |
| A developer or contributor | [Local installation](installation.md) | [Developer setup](../development/index.md) and [Testing](../development/testing.md) |
| An administrator or domain user | [Using CDNFoundry](using-cdnfoundry.md) | [First domain](first-domain.md), then the [feature guides](../guides/index.md) |
| A hosting provider or ISP architect | [Private CDN design](private-cdn-design.md) | [Production reference architectures](../architecture/production-reference-architectures.md) |
| A production operator | [Production quick start](../deployment/production-quick-start.md) | [Production best practices](../operations/production-best-practices.md), then [Operations](../operations/index.md) |
| An API integrator | [API conventions](../reference/api/index.md) | [Endpoint catalog](../reference/api/endpoints.md) and [Errors](../reference/api/errors.md) |

::: tip Suggested order for a first deployment
Read the concepts, try the local installation, add a first domain, follow the
production quick start, then read operations. The quick start owns the install
order. Do not build a second procedure from the reference pages.
:::

## What is not included

The product does not include Kubernetes orchestration, billing, reseller
hierarchies, a plugin system, GraphQL, HTTP/3, private-origin tunnels, or
automatic failover between regions. This site documents only behavior that
exists and has tests. See the [feature guides](../guides/index.md).
