---
title: First domain
description: Register the DNS primary, set the platform DNS identity, add a customer domain, delegate it, and activate it.
---

# First domain

In this page you prepare DNS for the whole platform once, then add and activate
your first customer domain.

**You need:**

- an administrator account;
- DNS hosts that are already deployed and reachable (see the
  [Production quick start](../deployment/production-quick-start.md));
- a domain you control for the platform itself, for example `cdn.example.net`,
  and access to its registrar.

::: warning Delegation is an external change
Test the platform zone and both DNS hosts directly before you change registrar
delegation. CDNFoundry cannot undo a wrong change at a registrar or parent zone.
:::

## Register the DNS primary first

The DNS primary is the hidden PowerDNS server that holds the master copy of every
zone. Register it before anything else. Registering a row does not start
PowerDNS or DNSdist. They must already run.

<DocTabs :labels="['Admin panel', 'API']">
<template #tab-1>

Open **Infrastructure → DNS primary** and create the primary. Enter:

- a unique **Name** and a **Location**;
- the **API URL**, the private PowerDNS API address (in the Compose layout this is `http://pdns-primary:8081`);
- the **Transfer endpoint**: the IP address and port that DNS nodes pull zones from, for example `192.0.2.10:5300`;
- the **API key** (at least 8 characters);
- the **Server ID**, normally `localhost`;
- the **Nameservers** your DNS nodes serve (at least two).

</template>
<template #tab-2>

```sh
curl -X POST "$CDNF_URL/api/admin/dns/clusters" \
  -H "Authorization: Bearer $CDNF_TOKEN" \
  -H "Idempotency-Key: $(uuidgen)" \
  -H "Content-Type: application/json" \
  -d @primary.json
curl -X POST "$CDNF_URL/api/admin/dns/clusters/$CLUSTER_ID/test" \
  -H "Authorization: Bearer $CDNF_TOKEN" \
  -H "Idempotency-Key: $(uuidgen)"
```

`primary.json` uses the same fields as the panel form. See the
[endpoint catalog](../reference/api/endpoints.md) for the exact names.

</template>
</DocTabs>

**Expected result:** the new row starts disabled. A background connection test
runs. When its last health result is healthy, turn on **Enabled**.

Only one primary can be enabled. **If the test fails:** check the API URL, the
API key, and that the `pdns-primary` container is healthy, then run the test again.

## Set the platform DNS identity

The platform identity is the zone and nameserver names that customers delegate
to. Open **Infrastructure → System DNS identity** and enter:

- **Platform domain**, such as `cdn.example.net`;
- **Proxy hostname**, a subdomain of the platform domain such as `proxy.cdn.example.net`;
- **Nameservers**: 2 to 8, in an even number, each inside the platform domain, such as `ns1.cdn.example.net` and `ns2.cdn.example.net`. Each needs an IPv4 glue address. Add an IPv6 address only if it is reachable;
- **Customer nameserver pairs**: 1 to 8 pairs of hostnames inside the platform domain, each with public addresses. Offer at least two pairs, such as `ns1`/`ns2` and `ns3`/`ns4`. A second claim on the same name always gets a different pair, so a second pair is what lets that claim prove control;
- the **SOA** values and the default TTL. The form shows the allowed range for each. The SOA expire time must be larger than refresh plus retry.

Review the summary, then choose **Save DNS identity and queue update**. The
API does this in two calls. `POST /api/admin/system/settings/dns/validate`
returns a `confirmation_token` that is valid for 15 minutes. Then
`PATCH /api/admin/system/settings/dns` applies that exact payload with the token
and returns `202 Accepted`.

**Expected result:** an operation appears under **Operate → Operations**. Wait
until it and every platform DNS deployment succeed.

## Delegate the platform zone

1. Query each DNS host directly, over UDP and over TCP, and check that it
   answers for the platform zone.
2. At the parent registrar, create the host (glue) records for your nameservers
   and delegate the platform zone to them. CDNFoundry cannot do this for you.

**Expected result:** a public resolver returns your nameservers for the platform
domain.

## Add the customer domain

<DocTabs :labels="['Admin panel', 'API']">
<template #tab-1>

Open **Domains**, choose **Add domain**, and enter the registrable domain name.

</template>
<template #tab-2>

```sh
curl -X POST "$CDNF_URL/api/domains" \
  -H "Authorization: Bearer $CDNF_TOKEN" \
  -H "Idempotency-Key: $(uuidgen)" \
  -H "Content-Type: application/json" \
  -d '{"name": "example.com"}'
```

</template>
</DocTabs>

CDNFoundry lower-cases the name and converts it to ASCII (Punycode). It rejects
public suffixes, IP addresses, single labels, wildcards, URLs, ports, and names
that are still in the reclaim cooldown.

**Expected result:** the domain is *Pending verification*. A worker assigns a
nameserver pair, deploys a first SOA and NS zone, and then checks the public
delegation by itself. The domain needs no origin and no certificate yet.

## Delegate and activate the domain

<div class="steps">

1. Open the domain and copy its **Assigned nameservers**. Each claim has its own
   pair and expires after seven days. Never reuse another claim's pair. If
   someone else already manages the name, you still get a claim and a different
   pair. The current owner keeps the name until you set your pair at the
   registrar and verification succeeds.
2. At the registrar (or the parent zone, for a subdomain), set exactly those
   names. Remove old DS records from the previous DNS provider. CDNFoundry
   zones are not signed.
3. Watch the verification operation. If the automatic attempt ran before the
   change propagated, choose **Verify nameservers** (API:
   `POST /api/domains/{domain}/verify-nameservers`). All parent authorities must
   agree. A DNSSEC error keeps the domain pending and stays visible.
4. Verification activates the domain. The **Activate** action
   (`POST /api/domains/{domain}/activate`) re-enables a domain that was verified
   and later disabled.
5. Check that each DNS deployment reports the domain's current revision.

</div>

**Expected result:** the domain is *Active* and its DNS deployments are acknowledged.

An administrator also sees **Force verify (local test)**. It exists for local
qualification only. It is not proof of public delegation.

## Add content

- Add DNS-only records with [Authoritative DNS](../guides/dns.md).
- Add a proxied hostname and an explicit, safe origin with
  [Proxy and origins](../guides/proxy-and-origins.md).
- The first eligible proxied hostname starts [managed TLS](../guides/tls.md).
- Give other people access with [Users and access](../guides/users-and-access.md).
