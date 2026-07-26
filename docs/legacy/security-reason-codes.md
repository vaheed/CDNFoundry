# Security and DDoS reason codes

Edge enforcement emits a stable reason in structured logs, bounded heartbeat
events, and controlled responses where safe. Telemetry loss never disables the
decision.

| Reason | Meaning |
|---|---|
| `unknown_host` / `unknown_sni` | Host or TLS name is not in the active cell artifact |
| `invalid_method` / `malformed_request` | Method or request syntax violates policy |
| `header_too_large` / `body_too_large` | Configured request size ceiling was exceeded |
| `header_timeout` / `body_timeout` | Client did not supply bounded input in time |
| `client_rate_exceeded` / `domain_rate_exceeded` | Client or whole-domain request budget is exhausted |
| `client_connections_exceeded` / `domain_connections_exceeded` | Concurrent connection budget is exhausted |
| `tls_handshake_rate_exceeded` | Domain TLS handshake budget is exhausted |
| `origin_capacity_exceeded` / `origin_circuit_open` | Origin concurrency is full or its circuit is open |
| `cache_abuse_detected` | Cache key/admission policy rejected high-cardinality input |
| `domain_restricted` / `domain_quarantined` | Operational state is enforcing stronger isolation |
| `edge_emergency_mode` | An active edge/cell emergency action rejected the request |

Reason codes describe the enforcing boundary, not attacker identity. Use the
domain event endpoint with cursor pagination and correlate its time, domain,
cell, and aggregate count with edge logs.
