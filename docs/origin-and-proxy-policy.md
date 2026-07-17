# Origin and proxy policy

Proxy mode is available only for A, AAAA, and CNAME hostnames and is mutually exclusive with Geo-DNS. Each proxied hostname has exactly one origin containing scheme, host, port, Host header, optional TLS SNI, verification choice, bounded connect/response timeouts, no more than two retries, and an explicit WebSocket choice. Redirects are never followed.

The control plane rejects unspecified, loopback, carrier NAT, link-local, private, benchmark, multicast, reserved, and cloud-metadata destinations. Additional platform/service networks belong in `EDGE_BLOCKED_ORIGIN_NETWORKS`. A private network is usable only through the administrator-controlled `EDGE_PRIVATE_ORIGIN_ALLOWLIST`; keep it narrow. The agent must resolve again immediately before connecting and apply the same CIDR policy to every A and AAAA result, preventing DNS rebinding and mixed safe/unsafe answer sets.

The edge removes hop-by-hop and client-supplied forwarding headers, sets the configured origin Host, and creates trusted forwarding metadata. It rejects conflicting Content-Length/Transfer-Encoding framing, malformed names or values, oversized headers/bodies, unknown Host/SNI, and unapproved upgrades before origin work. HTTP/3 stays disabled. HTTP/2 stream, reset, header-list, and connection request counts must remain explicitly bounded.

Non-apex proxied names reference the shared platform proxy hostname. Apex records use healthy service addresses from the same placement model. Edge health changes update shared routing state; they are not a reason to compile every domain artifact.
