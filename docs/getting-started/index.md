---
title: CDNFoundry overview
description: Understand what CDNFoundry provides, who it is for, and where to begin.
---

# CDNFoundry overview

::: info Serving and management are separate
Laravel owns desired state and operator workflows, but DNS and HTTP requests do
not pass through it. A control-plane outage pauses changes while previously
activated DNS and edge state continues serving.
:::

CDNFoundry combines authoritative DNS, HTTP/HTTPS proxying, managed and custom
TLS, bounded caching, security controls, telemetry, and usage export in one
private platform. It is intended for an operator who controls the infrastructure
and wants predictable failure behaviour without a per-domain process or
container architecture.

The platform has two human interfaces:

- `/admin` is for active users whose `users.type` is `admin`.
- `/app` is for active domain users and shows only assigned domains.

Both panels use the same policies and desired-state models as the API. API
clients authenticate with Laravel Sanctum bearer tokens.

## Choose your path

| Audience | Start here | Continue with |
| --- | --- | --- |
| New to CDNs | [CDN fundamentals](../concepts/cdn-fundamentals.md) | [How CDNFoundry works](../concepts/how-cdnfoundry-works.md), then [Desired state](../concepts/desired-state.md) |
| Developer or contributor | [Local installation](installation.md) | [Developer setup](../development/index.md) and [Testing](../development/testing.md) |
| Administrator or domain user | [Using CDNFoundry](using-cdnfoundry.md) | [First domain](first-domain.md), then the [feature guides](../guides/index.md) |
| Hosting provider or ISP architect | [Private CDN design](private-cdn-design.md) | [Production reference architectures](../architecture/production-reference-architectures.md) |
| Production operator | [Production quick start](../deployment/production-quick-start.md) | [Production best practices](../operations/production-best-practices.md), then [Operations](../operations/index.md) |
| API integrator | [API conventions](../reference/api/index.md) | [Endpoint catalog](../reference/api/endpoints.md) and [Errors](../reference/api/errors.md) |

::: tip Recommended learning order
For a first deployment, follow concepts → local installation → first domain →
production quick start → operations. The quick start owns installation order;
reference pages explain individual settings and should not be assembled into a
second deployment procedure.
:::

## Current product boundary

The implemented system supports the features documented under
[Feature guides](../guides/index.md). It does not include Kubernetes orchestration,
billing, reseller hierarchies, a plugin runtime, GraphQL, HTTP/3, private-origin
tunnels, or automated multi-region failover. Those are not implied by the
presence of extension points in underlying dependencies.

The source tree and automated tests are the implementation source of truth.
Repository governance separates implemented behaviour from unqualified future
ideas; this public site documents implemented behaviour only.
