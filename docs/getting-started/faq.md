---
title: Frequently asked questions
description: Answers to the questions operators ask before and after they install CDNFoundry.
---

# Frequently asked questions

## Do I need many servers to try it?

No. One control host and one PoP are enough to serve a domain. Use the
[quick start](index.md). A production setup needs more, see the
[production guide](../deployment/production-quick-start.md).

## Does customer traffic pass through Laravel?

No. DNS answers come from DNSdist and PowerDNS. HTTP traffic goes to the edge
gateway and OpenResty cells. Laravel only stores what you want and sends it out.

## What happens if the control plane goes down?

DNS and edges keep serving the last valid state they hold. You cannot make
changes until control returns. For a standby control node, see
[high availability](../operations/control-plane-high-availability.md).

## Why did my change not show up straight away?

Changes are applied in the background. The panel and the API return an
operation. Watch the operation, then test the public path. See
[Revisions and operations](../concepts/revisions-and-operations.md).

## Which TLS validation does it use?

DNS-01 only. It issues a certificate when a hostname first becomes proxied. A
domain that is DNS-only gets no certificate.

## Can one hostname have two origins?

No. One proxied hostname has one origin. See [Proxy and origins](../guides/proxy-and-origins.md).

## Can I point an origin at a private address?

No. Loopback, link-local, metadata, multicast, and platform addresses are
rejected, and the name is checked again before each connection.

## Does it stop a large DDoS attack?

No. It limits requests per client, domain, and cell. It does not remove
traffic that fills your uplink. Use your network provider for that.

## Where is my data kept?

On your servers. PostgreSQL holds desired state and ClickHouse holds telemetry.
The platform does not send customer traffic or telemetry to an outside service.

## How do I report a security problem?

Follow [Report a vulnerability](../security/reporting.md).
