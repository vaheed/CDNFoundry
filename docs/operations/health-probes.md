---
title: How health is checked
description: The three kinds of probes in CDNFoundry, what each one checks, when a PoP leaves DNS, and what to do when a probe fails.
---

# How health is checked

CDNFoundry checks health in three separate ways. They answer different
questions, so a problem can show in one and not in another. This page explains
each check, and the one rule that keeps a probe from taking down your service.

**Read this if:** a PoP shows as unhealthy, an alert mentions a probe, or you want to
know why a PoP did or did not leave DNS.

| Check | Question it answers | Run by | Can it remove a PoP from DNS? |
| --- | --- | --- | --- |
| [PoP probes](#pop-probes) | Can other PoPs reach this PoP? | Every PoP's agent | Yes, on a quorum |
| [Nameserver address probe](#nameserver-address-probe) | Does this new nameserver address answer DNS? | A few PoPs | No, it blocks a save |
| [Synthetic probes](#synthetic-probes) | Does the CDN look healthy from outside? | The monitoring host | No, it only alerts |

## The rule that protects you

A PoP leaves DNS only on a **positive signal**: an operator drains, disables, or
withdraws it, or a quorum of probes fails, or the PoP says its own listener or
cells are not ready. **Missing heartbeats are never a reason.**

If the control plane or the network between control and the PoPs fails, every
PoP keeps its published address and keeps serving. Silence raises the
`EdgeHeartbeatStale` alert instead. A pool also never loses all of its answers
to an automatic signal. If every PoP looks unhealthy, DNS keeps the last
published set and the `PoolServingLastPublishedSet` alert fires.

## PoP probes

Every PoP's agent connects to the HTTP port (80) and the TLS port (443) of every
other PoP's own addresses about every 15 seconds. It reports the results to
the control plane. A probe checks that both ports accept connections. It does
not fetch customer content.

A PoP is withdrawn from DNS only when **all** of these hold:

- at least 2 other PoPs reported (setting `edge_runtime.probe_min_vantage_points`);
- their reports are fresh, within 180 seconds (`edge_runtime.probe_result_max_age_seconds`);
- more than half of them failed.

A fleet with fewer than two other PoPs never withdraws a PoP on probes. For an
Anycast pool, set each PoP's endpoint address to its own unicast address, so the
probe reaches that PoP and not the nearest one.

**See it:** open **Infrastructure → Edges**, then the edge's **Pool endpoints**
tab. In Grafana, open **PoP fleet** and **PoP detail**.

**If a PoP was withdrawn:** read the reason on its endpoint. The reasons are
`gateway_not_acknowledged`, `gateway_not_ready`, `insufficient_ready_cells`,
`probe_failed`, `edge_unavailable`, and `withdrawn`. Fix the first one shown.
Then follow [Edge or cell failure](runbooks.md#edge-or-cell-failure). See also
[Pool service endpoints](pool-service-endpoints.md).

## Nameserver address probe

When you save a **new** nameserver address in **System DNS identity**, the
control plane first asks up to three recent PoPs to check it. Each one sends a
SOA query for the platform zone to that address over IPv6 and needs an
authoritative answer. The save only goes through when the address answers. An
answer is good for 24 hours. Addresses that are already saved are not probed
again.

**If it is refused:** the address did not answer from outside. Check that the DNS
host listens on that address, that your firewall allows UDP and TCP on port 53,
and that the platform zone is loaded. Then save again. See
[DNS nodes](dns-nodes.md) and [Troubleshoot DNS](../troubleshooting/dns.md).

## Synthetic probes

A blackbox exporter on the monitoring host probes every PoP and nameserver over
IPv4 and over IPv6, with HTTP, TCP, and DNS checks. The monitoring host sits
outside your PoP networks. A PoP that answers its own health check but cannot be
reached from outside therefore still shows as down. Fleet writes the target list
from the nodes it manages.

Synthetic probes never change DNS. They feed Grafana and alerts.

**See it:** open **Synthetic probes** in Grafana. It shows each probe's result,
its latency, and the certificate's expiry, per PoP and per IP version. Its alert
is `SyntheticProbeFailing`, listed in the [alert reference](../reference/alerts.md).

## Which check should I trust?

If they disagree, the disagreement is the clue:

| PoP probes | Synthetic probes | Likely cause |
| --- | --- | --- |
| Pass | Fail | A network or firewall problem between the outside world and the PoP |
| Fail | Pass | A problem between PoPs, or a probing PoP |
| Fail | Fail | The PoP is down or not ready |
| Pass | Pass | The PoP is fine. Look at the origin or the cache |
