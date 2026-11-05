---
title: Glossary
description: Plain definitions of the CDN, DNS, and CDNFoundry terms used in this documentation.
---

# Glossary

Short definitions for readers who are new to running a CDN. Terms are in
alphabetical order.

## A

**Anycast.** Several machines announce the same IP address, and the network
sends each client to the nearest one. CDNFoundry can serve a pool's traffic
this way. See [Simple Anycast](../operations/simple-anycast.md).

**Artifact.** A signed file the control plane builds for the edges. It holds the
settings for one domain at one revision. An edge rejects an artifact with a bad
signature or checksum and keeps serving the last good one.

**Authoritative DNS.** A DNS server that holds the real records for a zone and
answers for it. It is different from a resolver, which asks other servers on
behalf of a user.

## C

**Cache key.** The value that decides whether two requests share one cached
response. A URL purge and a cache lookup use the same key.

**Cell.** One bounded OpenResty runtime on an edge host. An edge has up to 32
cells. See [Edges, pools, and cells](../concepts/edges-and-cells.md).

**CNAME.** A DNS record that points one name to another name.

**Control plane.** The Laravel application, its database, and its workers. It
stores what you want and sends it to the DNS and edge servers. It never carries
customer traffic.

## D

**Data plane.** The servers that handle customer traffic: DNS servers and edge
cells.

**Desired state.** What you asked for, stored in PostgreSQL. Everything else is
built from it and can be rebuilt.

**DNS-01.** A way to prove to a certificate authority that you control a name,
by publishing a TXT record. CDNFoundry uses it to issue certificates.

**DNSdist.** The public DNS front end. It receives queries and passes them to
the private PowerDNS servers.

**Drain.** Stop sending new traffic to a PoP or cell, and let current requests
finish.

## E

**Edge.** One enrolled host that serves customer traffic. An edge runs an agent,
a gateway, and cells.

**Edge agent.** The small program on an edge host. It pulls artifacts, checks
them, and writes the runtime files. It does not serve customer traffic.

## F

**Fleet.** The tool that installs and upgrades CDNFoundry hosts. It has a command
line and a local web page.

## G

**Geo-DNS.** Answering a DNS query differently depending on where the client is.

## H

**Hidden primary.** The PowerDNS server that holds the master copy of every zone.
It is not reachable from the internet. Public servers copy zones from it.

## O

**Operation.** A background job you can watch. A request that changes a runtime
returns `202 Accepted` and an operation ID.

**Origin.** Your own server that holds the real content. The CDN fetches from it
when it has no cached copy.

## P

**Placement.** The choice of which pool serves a domain.

**Pool.** A group of cells that serve the same kind of traffic: `shared`,
`reserved`, `dedicated`, or `quarantine`.

**PoP (point of presence).** One location with an edge host that serves traffic.

**Proxied.** A DNS record whose name points to the CDN instead of to your origin.
Traffic for it goes through an edge.

**Purge.** Remove cached content. A URL purge removes one URL. A full purge
increments an epoch so every older object counts as stale.

## R

**Revision.** A number that goes up each time a domain's desired state changes.
Targets report which revision they hold.

## S

**SNI.** The host name a client sends at the start of a TLS connection. The
gateway uses it to choose a cell.

**SOA serial.** A number in a zone's start-of-authority record. Secondary DNS
servers copy a zone when it goes up. CDNFoundry only ever increases it.

## T

**TTL (time to live).** How many seconds a resolver or cache may keep an answer.

## Z

**Zone.** The set of DNS records for one domain and its subdomains.
