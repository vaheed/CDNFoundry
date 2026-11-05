---
title: Production guide
description: One ordered path from planning to a monitored, backed-up private CDN, with a check at the end of every step.
---

# Production guide

This guide takes you from nothing to a monitored, backed-up CDN. It is a map:
each step says what to do, then links to the page that holds the commands.
Every step ends with a check. Do not start a step until the check of the
previous one passes.

**You need:** Linux servers you control (at least three for production),
a domain you can change the nameservers of, and root SSH access to the hosts.

<div class="steps">

1. **Plan the fleet.**

   Choose a layout and size it with the [sizing guide](sizing.md) and the
   [reference architectures](../architecture/production-reference-architectures.md).
   Decide your region names, public IPv4 and IPv6 addresses, and the domain for
   your platform nameservers. Read [Topology](topology.md) for ports and networks.

   **Check:** you have a list of hosts with a role, region, and address each, and
   losing any one host leaves DNS and HTTP serving.

2. **Prepare the hosts.**

   Install the prerequisites and open only the public ports in
   [Topology](topology.md). Keep PostgreSQL, Redis, ClickHouse, and PowerDNS
   private.

   **Check:** each host reaches the others on the private ports you chose, and
   its clock is correct.

3. **Install the first control node and two PoPs.**

   Follow the [production quick start](production-quick-start.md). Fleet writes
   the host bundles, you copy them, and you start each role in order.

   **Check:** `scripts/cdnfoundry-fleet status` lists every node, and the control
   panel answers on its HTTPS name.

4. **Delegate your platform nameservers.**

   Publish the glue records at your registrar, as shown in
   [First domain](../getting-started/first-domain.md#register-the-dns-primary-first).

   **Check:** `dig @<your DNS host> <platform domain> SOA` returns an answer from
   each DNS host, over IPv4 and over IPv6 if you publish IPv6.

5. **Serve the first domain.**

   Create a domain, delegate it, and add a proxied record, following
   [First domain](../getting-started/first-domain.md) and
   [Production setup after the quick start](production-setup-after-quick-start.md).

   **Check:** the domain's HTTPS response carries an `X-CDNF-Cache` header, and a
   second request is a hit.

6. **Add PoPs and DNS hosts.**

   Use [Scaling](../operations/scaling.md) for an edge-only host and for a DNS
   host. Place PoPs in at least two regions for a real CDN. For one pool across
   many PoPs, see [Simple Anycast](../operations/simple-anycast.md).

   **Check:** each new PoP shows a fresh heartbeat and ready cells before it
   receives traffic.

7. **Add control-plane high availability.**

   Add a standby control node by following
   [Control-plane high availability](../operations/control-plane-high-availability.md#add-a-standby).

   **Check:** `scripts/cdnfoundry-fleet control status` shows the standby in
   sync, and you have done one planned switchover and back.

8. **Turn on monitoring.**

   Set up Prometheus, Grafana, and alerts with [Monitoring](../operations/monitoring.md)
   and [Grafana dashboards](../operations/grafana.md). Run the external probers
   outside both control nodes.

   **Check:** the `Watchdog` alert reaches your receiver, and every dashboard
   shows data.

9. **Set up backups and test a restore.**

    Configure backups and restore into a clean host, following
    [Backup and recovery](../operations/backup-and-recovery.md). Keep the
    application keys and TLS material outside the backup.

    **Check:** a restore drill ends with a working panel and a served domain.

10. **Qualify before customer traffic.**

    Run the [production qualification](../operations/production-qualification.md)
    on your hardware, including the failure exercises.

    **Check:** the release decision in that page is recorded as pass.

</div>

## When something goes wrong

Start with [Troubleshoot by symptom](../troubleshooting/by-symptom.md). For an
alert, open its [runbook](../operations/runbooks.md). To upgrade, follow
[Upgrade](upgrade.md).
