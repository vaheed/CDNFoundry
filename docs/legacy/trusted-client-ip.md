# Trusted client IP deployment

By default the connecting socket address is the client identity. Configure
`trusted_proxy_cidrs` only when an approved L4/L7 balancer is immediately in
front of the edge and overwrites `X-Forwarded-For` from untrusted clients.

The edge accepts the first `X-Forwarded-For` address only when the direct peer
matches one of at most 32 configured IPv4/IPv6 CIDRs. Otherwise it ignores the
header. It never trusts the header merely because it is present. Configure the
smallest balancer ranges, include IPv4 and IPv6 paths explicitly, and prevent
direct access that bypasses the balancer.

Qualification must send a spoofed header directly and confirm the socket IP is
used, then send through each trusted balancer family and confirm the overwritten
address drives IP/CIDR and GeoIP decisions. A deployment that appends rather
than overwrites client-provided forwarding headers is unsafe and must not enable
this option.
