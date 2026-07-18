# System DNS identity

Administrators manage the platform DNS identity through `/api/admin/system/settings/dns`. It contains the platform domain, proxy hostname, two to eight nameservers, IPv4 and IPv6 glue for every nameserver, SOA fields, default TTL, and bounded internal cluster targets.

`POST /api/admin/system/settings/dns/validate` validates without changing desired state and returns a short-lived confirmation token bound to the exact normalized preview. Send that token as `confirmation_token` with `PATCH /api/admin/system/settings/dns`. A missing, expired, or payload-mismatched token returns conflict. The patch transactionally stores desired state, increments its revision, creates an audited operation, and returns `202`; the `runtime` queue then activates the candidate in only the configured healthy DNS clusters. Poll `/api/operations/{id}` or inspect administrator operations.

The request never connects to PowerDNS. A failed deployment retains its error and the previous active zone/RRsets; desired state remains visible for a safe retry. When the platform zone name changes, the replacement is activated and recorded before the prior zone is removed. IPv4 and IPv6 glue are mandatory from the first configuration.

```json
{
  "platform_domain": "cdnf.example",
  "proxy_hostname": "proxy.cdnf.example",
  "nameservers": [
    {"hostname": "ns1.cdnf.example", "ipv4": "192.0.2.10", "ipv6": "2001:db8::10"},
    {"hostname": "ns2.cdnf.example", "ipv4": "192.0.2.11", "ipv6": "2001:db8::11"}
  ],
  "soa_primary": "ns1.cdnf.example",
  "soa_mailbox": "hostmaster.cdnf.example",
  "soa_refresh": 3600,
  "soa_retry": 600,
  "soa_expire": 1209600,
  "soa_minimum_ttl": 300,
  "default_ttl": 300,
  "cluster_targets": ["pdns-auth:8081"],
  "confirmation_token": "token returned by the exact validation preview"
}
```

These are documentation addresses and must be replaced. Registrar glue and delegation are introduced by the domains module.
