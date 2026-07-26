---
title: Architecture data flows
description: Follow DNS, HTTP, reconciliation, enrollment, TLS, and telemetry through CDNFoundry.
---

# Architecture data flows

## Authoritative DNS

```mermaid
sequenceDiagram
    participant R as Recursive resolver
    participant D as DNSdist
    participant P as PowerDNS
    participant M as Local MMDB
    participant V as Vector
    R->>D: UDP/TCP query
    D->>P: Private backend query
    opt Geo-DNS record
      P->>M: Local country/continent lookup
      M-->>P: Classification
    end
    P-->>D: Authoritative response
    D-->>R: Response
    D-->>V: Best-effort dnstap
```

1. A resolver sends UDP or TCP DNS to DNSdist.
2. DNSdist selects the first available private PowerDNS backend.
3. PowerDNS reads the derived runtime database.
4. Geo-DNS Lua records consult the local memory-mapped MMDB and EDNS Client Subnet when present.
5. DNSdist emits best-effort dnstap to Vector after answering.

Laravel is absent from this path.

## Customer HTTP and HTTPS

```mermaid
sequenceDiagram
    participant C as Client
    participant E as OpenResty cell
    participant Cache as Shared bounded cache
    participant O as Validated origin
    participant V as Vector
    C->>E: HTTP/HTTPS request
    E->>E: Select domain, certificate, client IP, security policy
    E->>Cache: Deterministic cache-key lookup
    alt Cache hit
      Cache-->>E: Cached response
    else Miss or bypass
      E->>O: Bounded upstream request
      O-->>E: Origin response
      E->>Cache: Admit only when policy permits
    end
    E-->>C: Normalized response
    E-->>V: Redacted best-effort event
```

1. DNS returns a listener-ready pool address.
2. The client connects to the assigned OpenResty cell.
3. OpenResty rejects unknown hosts and selects the certificate from runtime data.
4. Lua resolves the trusted client address, applies emergency controls, ordered security rules, profile ceilings, cache policy, and origin policy.
5. A cache hit returns locally; a miss uses the explicitly configured origin.
6. Access telemetry goes directly to Vector.

Laravel and ClickHouse are absent from the serving decision.

## Runtime mutation

```mermaid
flowchart LR
    Request["Authorized mutation"] --> Validate["Typed validation"]
    Validate --> Tx["Transaction:<br/>state + revision + audit"]
    Tx --> Job["Unique job after commit"]
    Job --> Fresh{"Latest revision?"}
    Fresh -- No --> Skip["Skip obsolete work"]
    Fresh -- Yes --> Render["Deterministic render"]
    Render --> Check["Checksum + validate"]
    Check --> Activate["Atomic activation"]
    Activate --> Verify["Verify + acknowledge"]
    Verify --> Receipt["Deployment result"]
    Check -- Invalid --> Preserve["Preserve active state"]
    Activate -- Failed --> Preserve
```

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

```mermaid
sequenceDiagram
    participant CP as Control worker
    participant DNS as Desired DNS
    participant PDNS as PowerDNS targets
    participant CA as ACME CA
    participant Edge as Edge agents
    CP->>DNS: Add bounded DNS-01 TXT
    DNS->>PDNS: Reconcile revision
    PDNS-->>CP: Required acknowledgements
    CP->>CA: Validate and finalize
    CA-->>CP: Certificate chain
    CP->>CP: Validate and encrypt private key
    CP->>Edge: Publish signed edge revision
    Edge-->>CP: Activate and acknowledge
    CP->>DNS: Remove challenge in later revision
```

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

::: info Independence rule
Telemetry is downstream of serving. Bounded buffers may sacrifice telemetry
when exhausted, but telemetry backpressure must never enter DNS or HTTP paths.
:::
