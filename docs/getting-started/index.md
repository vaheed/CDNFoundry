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

- To evaluate a company or ISP deployment, read [How to build a private CDN](private-cdn-design.md).
- To run the repository locally, follow [Installation](installation.md).
- To onboard a domain, follow [First domain](first-domain.md).
- To understand state and failure guarantees, read [Desired state](../concepts/desired-state.md).
- To deploy real hosts, start with the [Production quick start](../deployment/production-quick-start.md).
- To integrate over HTTP, use the [API reference](../reference/api/index.md).

## Current product boundary

The implemented system supports the features documented under
[Feature guides](../guides/index.md). It does not include Kubernetes orchestration,
billing, reseller hierarchies, a plugin runtime, GraphQL, HTTP/3, private-origin
tunnels, or automated multi-region failover. Those are not implied by the
presence of extension points in underlying dependencies.

The source tree and automated tests are the implementation source of truth.
Repository governance separates implemented behaviour from unqualified future
ideas; this public site documents implemented behaviour only.
