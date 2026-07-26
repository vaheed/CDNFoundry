---
title: Architecture data flows
description: Follow DNS, HTTP, reconciliation, enrollment, TLS, and telemetry through CDNFoundry.
---

# Architecture data flows

## Authoritative DNS

1. A resolver sends UDP or TCP DNS to DNSdist.
2. DNSdist selects the first available private PowerDNS backend.
3. PowerDNS reads the derived runtime database.
4. Geo-DNS Lua records consult the local memory-mapped MMDB and EDNS Client Subnet when present.
5. DNSdist emits best-effort dnstap to Vector after answering.

Laravel is absent from this path.

## Customer HTTP and HTTPS

1. DNS returns a listener-ready pool address.
2. The client connects to the assigned OpenResty cell.
3. OpenResty rejects unknown hosts and selects the certificate from runtime data.
4. Lua resolves the trusted client address, applies emergency controls, ordered security rules, profile ceilings, cache policy, and origin policy.
5. A cache hit returns locally; a miss uses the explicitly configured origin.
6. Access telemetry goes directly to Vector.

Laravel and ClickHouse are absent from the serving decision.

## Runtime mutation

1. The API or Filament action validates and commits desired state.
2. A revision and audit event are recorded transactionally.
3. A unique job renders DNS RRsets or a canonical edge snapshot.
4. The target validates and activates the candidate.
5. Deployment rows, tasks, and operations record acknowledgement or failure.

See [Desired state](/concepts/desired-state) for failure behaviour.

## Edge enrollment and sync

1. An administrator creates the edge and receives a one-time token.
2. The agent creates a private key and CSR.
3. Edge control validates the token and signs a short-lived identity certificate.
4. Later requests require that certificate and its serial.
5. The agent fetches the manifest or a full recovery snapshot.
6. It verifies, compiles, atomically activates, then acknowledges.
7. Heartbeats report sequence, listener readiness, cell capacity, origin health, and bounded security summaries.

## Managed TLS

1. An active, nameserver-verified domain gains its first proxied hostname.
2. A bounded certificate job creates or reuses the ACME account.
3. DNS-01 challenge TXT records are added to desired DNS and reconciled.
4. Issuance waits for DNS acknowledgement before notifying the CA.
5. The validated key and certificate are encrypted/stored, then included in a new edge revision.
6. Challenge state is removed through another DNS revision.

DNS-only domains do not create orders.

## Telemetry

OpenResty sends JSON logs to Vector; DNSdist sends dnstap. Vector removes
authorization, cookies, bodies, and query strings, bounds field length, and
writes ClickHouse. The API queries raw data for at most 24 hours and aggregates
for at most 90 days. Usage rollups are finalized into compact PostgreSQL rows.
