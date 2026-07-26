---
title: Troubleshoot DNS
description: Diagnose delegation, record validation, DNS cluster, reconciliation, GeoIP, and answer failures.
---

# Troubleshoot DNS

## Delegation never verifies

- Compare `/api/nameservers` with public NS answers.
- Confirm registrar glue and delegation are at the correct parent.
- Query more than one recursive resolver and the authoritative IP directly.
- Check for a trailing old nameserver or DNSSEC state managed outside CDNFoundry.
- Use force verify only for controlled local qualification.

## Record mutation returns 422

Read the field errors, then check [DNS records](/reference/dns-records). Common
causes are an owner outside the zone, TTL below 30, invalid IDNA label, CNAME
coexistence, a second routing record on a proxied hostname, missing MX/SRV
numbers, or PTR outside a reverse zone.

## Deployment remains pending or failed

1. Read the domain DNS deployment and operation.
2. Check the cluster is enabled and its latest test is healthy.
3. Verify the API URL is private/source restricted and its CA is trusted.
4. Inspect the runtime queue and failed jobs.
5. Reconcile the one domain.

The previous `active_rrsets` should remain present on failure.

## DNSdist answers SERVFAIL

- Query PowerDNS from the private DNS network.
- Inspect DNSdist backend status and PowerDNS health.
- Confirm the PowerDNS migration ran.
- Confirm the MMDB exists and validates if the zone contains Lua Geo-DNS.
- Test both UDP and TCP.

## Geo-DNS returns unexpected geography

Confirm the MMDB age and provider, preview the exact client IP, and determine
whether the public query carried trusted EDNS Client Subnet. Without ECS the
recursive resolver address may be classified.

Use the [DNS cluster runbook](/operations/runbooks#dns-cluster-failure) for
recovery.
