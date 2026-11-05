---
title: Scaling
description: Add Horizon workers, DNS hosts, edge hosts, and telemetry capacity by repeating bounded roles, after you have measured the bottleneck.
---

# Scaling

You will add capacity to one part of CDNFoundry. You need to know which resource is saturated before you start.

::: warning Scale measured bottlenecks
Add workers, DNS capacity, ClickHouse capacity, edges, or cells only after you have found the saturated resource. Never solve growth by creating a process, container, worker, timer, Nginx server block, cache directory, or reload for each domain.
:::

You scale by repeating bounded service roles:

- add Horizon workers (queue workers) for one queue lane. See [queue lanes](../reference/limits.md#queue-lanes);
- add private PowerDNS backends behind DNSdist;
- add public DNSdist capacity;
- add edge hosts with equivalent cells;
- add ClickHouse capacity or move telemetry to dedicated hosts;
- use replicated external PostgreSQL and Valkey that you select and operate.

## Capacity gate

Before you add traffic, measure:

- DNS UDP and TCP latency, and backend saturation;
- edge request, connection, TLS, origin, cache, temporary-storage, memory, and CPU pressure;
- queue depth and age per lane;
- PostgreSQL and Valkey latency;
- Vector buffer and ClickHouse insert and query load;
- backup duration and restore time.

The bundled throughput and scale tests give correctness evidence on their own host only. They are not a capacity promise for your hardware. See [Sizing](../deployment/sizing.md).

## Add an edge-only host

An edge-only host runs the edge agent, gateway, and cells. It does not need a new DNS cluster.

<div class="steps">

1. In the admin panel, open **Infrastructure → Edges** and create the edge row. Configure its cells. Copy the one-time enrollment token.
2. Add the host to the Fleet plan. Store the edge ID and token. Fleet renders the host bundle with the immutable release, `.env.prod`, certificates, and status token.

   ```sh
   ./scripts/cdnfoundry-fleet --state-dir ./.cdnfoundry-fleet add-node \
     --node edge-tokyo --role edge --region asia --location tokyo \
     --public-ipv4 192.0.2.80 --non-interactive
   ./scripts/cdnfoundry-fleet --state-dir ./.cdnfoundry-fleet configure-edge-registration \
     --node edge-tokyo --edge-id <edge-uuid> --bootstrap-token-file <token-file>
   ```

3. Open the host firewall as the bundle documents. If the host uses NAT or a load balancer, map each advertised service address to a private local gateway address with `EDGE_GATEWAY_ADDRESS_MAP`.
4. Validate Compose, then start the edge profile on the host. See [Production topology](../deployment/topology.md) and the [Fleet operator guide](../deployment/production-fleet-operator-guide.md#updating-the-fleet).
5. Wait for a fresh heartbeat and ready cells on **Infrastructure → Edges**.
6. Confirm existing pool routing includes the new cell only after it is ready.
7. Send test HTTP and HTTPS traffic and check that telemetry arrives. Do this before you rely on the capacity.

</div>

After enrollment, clear the one-time token with `clear-edge-bootstrap-token --node <name>`.

## Add a DNS host

Adding DNS capacity does not create an edge runtime.

<div class="steps">

1. Prepare a distinct public IP, private PowerDNS data, a valid GeoIP database file (MMDB), and a DNS API certificate.
2. Run the separate PowerDNS migration (`pdns-migrate`).
3. Start DNSdist, private PowerDNS, and the DNS API with the `dns` profile.
4. In the admin panel, register the cluster under **Infrastructure → DNS primary** and run **Test connection**. The API accepts only requests from the allowed source.
5. Enable the cluster and reconcile DNS.
6. From outside your network, check UDP and TCP answers, the SOA record, delegation, and Geo-DNS.

</div>

## Move telemetry

Use the `telemetry` profile, or a generated monitoring-role bundle with an exact allowlist of edge sources. Set the control `CLICKHOUSE_URL` and the edge Vector endpoints to verified TLS. Keep ClickHouse and Prometheus private.

Prove that a telemetry outage and a backlog drain do not affect serving. See the [ClickHouse or Vector outage runbook](./runbooks.md#clickhouse-or-vector-outage).

## External control data

Set `DB_URL` and `REDIS_URL` in the generated control bundle to replicated services that you operate. Use verified TLS, exact-source firewalls, backups, a failover plan, and a capacity plan.

A control bundle with external data services has no embedded database to replicate. So `control add-standby` supports only the embedded PostgreSQL and Valkey. For those, the optional [warm standby control node](./control-plane-high-availability.md) sets up streaming replication and a one-command promotion. It does not set up automatic failover.

All control replicas must share the application encryption, artifact-signing, session, database, queue, and edge identity material.
