---
title: Troubleshoot DNS
description: Fix domains that never verify, DNS record errors (422), pending or failed DNS deployments, DNSdist SERVFAIL, and unexpected Geo-DNS answers.
---

# Troubleshoot DNS

Use this page when a domain does not verify, a record is rejected, DNS changes do
not appear, or answers are wrong. You need administrator access, and shell access
to a DNS host for some checks.

::: danger Do not repair derived DNS by hand
PowerDNS rows and PowerAdmin edits are rebuilt from PostgreSQL, so your edits
drift and are lost. Fix the desired state or the reconciliation step. CDNFoundry
then activates a validated revision in one atomic step.
:::

## Delegation never verifies

Delegation means your registrar points the domain at the CDNFoundry nameservers.

<div class="steps">

1. Call `GET /api/nameservers` and note the names it returns.
2. Query the public NS records of the domain. Use more than one recursive resolver,
   and also query the authoritative IP directly.
3. Confirm the registrar sets glue records and delegation at the correct parent.
4. Look for an old nameserver still listed, or for DNSSEC state managed outside
   CDNFoundry.

</div>

**Expected result:** public NS answers match `/api/nameservers`. Verification then
succeeds on its next check.

An administrator can force verification. Do this only for controlled local
qualification. CDNFoundry records a force-verified domain in the audit log.

## Record mutation returns 422

Read the field errors, then check [DNS records](../reference/dns-records.md). Common
causes:

- The owner name is outside the zone.
- The TTL is below 30.
- A label is not valid IDNA (internationalized domain name).
- A CNAME exists next to other records at the same name.
- A proxied hostname has a second routing record.
- An MX or SRV record is missing its numbers.
- A PTR record is outside a reverse zone.

## Deployment stays pending or failed

<div class="steps">

1. Read the domain's DNS deployment and its operation.
2. Check that the DNS cluster is enabled and its latest test is healthy.
3. Check that the API URL is private or source restricted, and that its CA is trusted.
4. Inspect the runtime queue and its failed jobs.
5. Reconcile that one domain.

</div>

**Expected result:** the deployment reaches the desired revision. On failure, the
previous `active_rrsets` stay in place, so the old answers keep working.

## DNSdist answers SERVFAIL

- Query PowerDNS from the private DNS network.
- Check DNSdist backend status and PowerDNS health.
- Confirm the PowerDNS migration ran.
- If the zone has Lua Geo-DNS records, confirm the MMDB (GeoIP) file exists and is valid.
- Test both UDP and TCP.

## Geo-DNS returns unexpected geography

<div class="steps">

1. Check the age and provider of the MMDB file.
2. Preview the exact client IP in the Geo-DNS preview. See [Geo-DNS](../guides/geo-dns.md).
3. Find out whether the public query carried EDNS Client Subnet (ECS). Without ECS,
   CDNFoundry may classify the recursive resolver's address instead of the user's.
4. Query each authoritative DNS node, with and without a controlled ECS value.

</div>

Recursive caches can keep an earlier answer until the TTL ends. Wait for the TTL
before you decide a change did not work.

For recovery, use the
[DNS cluster runbook](../operations/runbooks.md#dns-cluster-failure).
