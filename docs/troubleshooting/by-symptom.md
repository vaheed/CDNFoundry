---
title: Troubleshoot by symptom
description: Start from what you see, such as a domain that does not resolve, and follow the checks in order to the fix.
---

# Troubleshoot by symptom

Find what you see below. Do the checks in order and stop at the first one that
fails. Each symptom ends with the fix and a link to the full page.

::: warning Repair desired state, not derived state
Do not edit PowerDNS rows, generated edge files, or agent state to make a
symptom go away. Fix the setting in the panel or API and let the change
activate. See [Troubleshooting](index.md).
:::

## The domain does not resolve

1. Ask the authoritative server directly: `dig @<your DNS host> example.com SOA`.
   If it answers, the zone is published and the problem is delegation.
2. Compare the nameservers shown for the domain with the public NS answer from
   two resolvers. They must match.
3. Open the domain's DNS deployment and its operation. If it is pending or
   failed, read the error.
4. If DNSdist answers SERVFAIL, check that PowerDNS is healthy and its
   migration ran.

**Fix:** correct the delegation at your registrar, or fix the error the
deployment shows and reconcile that one domain.
Details: [DNS troubleshooting](dns.md).

## The certificate is not issued

1. The domain must be active and nameserver-verified, and must have a proxied
   hostname. A DNS-only domain never gets a certificate.
2. Check that ACME is enabled and that the order budget is not used up.
3. Open the latest order and its challenge. Query the TXT record through your
   public authoritative DNS.
4. Only when DNS is correct, check access to the CA and the host clock.

**Fix:** fix DNS first, then let the order retry. Do not delete the active
certificate. Details: [TLS and cache](tls-and-cache.md).

## A PoP is not syncing

1. Look at the edge's heartbeat. A stale heartbeat means the agent cannot reach
   the control plane.
2. Check the agent container, the client certificate expiry, and edge-control
   reachability on port 8443.
3. Read the agent log for the stable rejection reason. Common ones are a bad
   signature, an expired delivery, or an incompatible version.
4. Check that the agent's runtime directory is writable and has space.

**Fix:** repair the cause the log names. The PoP keeps serving its last good
state in the meantime. Do not reset sequences or delete agent state.
Details: [Edge and origin](edge-and-origin.md).

## The cache is not hit

1. Request the same URL twice and read the `X-CDNF-Cache` header.
2. Check that caching is enabled for the domain and that development mode has
   not expired.
3. Check the request: method, `Authorization`, and bypass cookies.
4. Check the response: status, `Set-Cookie`, `Cache-Control`, `Vary`, and size.
5. Check the query-string setting. A changing query string is a new key.

**Fix:** change the cache setting, or change what the origin sends. Details:
[TLS and cache](tls-and-cache.md#expected-response-is-not-cached).

## HTTPS shows the wrong certificate

1. Confirm the name you request matches a hostname on the domain.
2. Check the domain's TLS mode and that a certificate is active.
3. Check that each PoP reports the latest revision.

**Fix:** activate or upload a certificate that covers the name, then wait for
the revision to reach the PoPs.
Details: [TLS and cache](tls-and-cache.md#https-serves-the-bootstrap-certificate).

## The origin test fails or the site returns an error

1. Read the stable result of the origin test. It tells DNS, unsafe address,
   connect timeout, TLS, status, and response timeout apart.
2. Check the SNI and `Host` header values separately.
3. Remember that an origin that resolves to a blocked address is rejected on
   purpose.

**Fix:** correct the origin address or its TLS settings, then test again.
Details: [Edge and origin](edge-and-origin.md#origin-test-fails).

## A change does not show up

1. Open the operation returned when you saved. A `202` only means the work was
   queued.
2. Compare the desired revision with the revision each target reports.
3. Check the Horizon queue and failed jobs.

**Fix:** reconcile the smallest affected scope, for one domain rather than all.
Details: [Revisions and operations](../concepts/revisions-and-operations.md).
