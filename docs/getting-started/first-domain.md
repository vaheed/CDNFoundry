---
title: First domain
description: Configure system DNS, add a domain, delegate it, and activate serving.
---

# First domain

This workflow assumes an administrator account and deployed, reachable
authoritative DNS runtimes. It registers those runtimes as healthy clusters
before applying platform identity.

::: warning Delegation is an external change
Create and verify platform identity, authoritative clusters, glue, and direct
UDP/TCP answers before changing registrar delegation. CDNFoundry cannot roll
back an incorrect parent-zone or registrar change.
:::

## Register authoritative clusters first

In **Infrastructure → DNS clusters**, create each private PowerDNS API target
with:

- a descriptive unique name and location;
- the source-restricted HTTPS API URL;
- its API key;
- server ID, normally `localhost`;
- the nameserver identities served by that target.

A new cluster is disabled until its asynchronous connection test succeeds.
Enable it only after the last health result is successful. The DNS runtime and
restricted API must already be deployed; a control-plane row does not start
PowerDNS or DNSdist.

## Prepare and deploy platform identity

In `/admin/system-dns-identity`, enter:

- a platform zone such as `cdn.example.net`;
- nameservers such as `ns1.cdn.example.net` and `ns2.cdn.example.net`;
- one IPv4 glue address for each nameserver, and IPv6 only where reachable;
- a proxy hostname such as `proxy.cdn.example.net`;
- SOA values within the displayed validation bounds.

Preview first. The apply request requires the confirmation token bound to that
exact normalized preview. Wait for its operation and every platform DNS
deployment to succeed.

Query each authoritative host directly over UDP and TCP. Only after both hosts
serve the acknowledged platform revision should you create the required
host/glue records and delegate the platform zone at the parent registrar.
CDNFoundry cannot automate registrar configuration.

## Create the customer domain

In **Domains → Create domain**, enter the registrable domain name. CDNFoundry
normalizes it to lower-case ASCII/Punycode and rejects public suffixes, IP
addresses, single labels, wildcards, URLs, ports, and names still inside reclaim
cooldown.

Creation writes desired DNS state, queues the initial SOA/NS zone deployment,
and automatically queues public nameserver verification after that deployment
succeeds. It does not require an origin and does not issue a certificate.

## Delegate and activate

1. Create the customer domain and copy its **Assigned nameservers**. Each fresh
   claim has its own assignment and a seven-day expiry; do not reuse the global
   shared nameserver pair or a previous claim's assignment.
2. At the registrar (or authoritative parent for a delegated subdomain), set
   exactly those names. Remove stale DS records from the previous provider;
   CDNFoundry customer zones are unsigned.
3. Watch the verification operation. Use **Verify nameservers** after propagation
   if the automatic attempt failed. Parent authorities must agree; DNSSEC errors
   remain visible and leave the domain pending.
4. Successful verification activates the domain automatically. **Activate** is
   the deliberate action for re-enabling an already verified disabled domain.
5. Confirm that each DNS deployment has acknowledged the domain revision.

The administrator-only force-verify action exists for controlled local
qualification; it is not proof of public delegation.

## Add content

- Add DNS-only records with [Authoritative DNS](../guides/dns.md).
- Add a proxied hostname and explicit safe origin with
  [Proxy and origins](../guides/proxy-and-origins.md).
- The first eligible proxied hostname starts [managed TLS](../guides/tls.md).
- Assign domain users with [Users and access](../guides/users-and-access.md).
