---
title: Troubleshoot TLS and cache
description: Fix missing managed certificate orders, stalled challenges, rejected uploads, the bootstrap certificate, uncached responses, and failed purges.
---

# Troubleshoot TLS and cache

Use this page when a certificate is not issued or served, a response is not cached,
or a purge does not finish. You need access to the domain in the panel, and
administrator access for some checks.

::: danger Preserve the valid certificate and cache state
Do not delete an active certificate, private key, runtime bundle, or cache volume
while you diagnose a failed replacement. Repair the new candidate. The previous
serving state stays until the new revision verifies.
:::

## Managed order is not created

Check that:

- the domain is active and its nameservers are verified;
- the domain has a proxied hostname;
- ACME (the automatic certificate protocol) is enabled and an order budget remains
  (`ACME_ORDER_BUDGET_PER_HOUR`, 20 by default).

A DNS-only domain has no order. This is correct.

## Order stalls on challenge

<div class="steps">

1. Open the latest order and its challenge rows.
2. Check the DNS desired revision and every DNS deployment acknowledgement.
3. Query the challenge TXT record through public authoritative DNS.
4. Only when DNS is correct, check access to the CA directory and the clock.

</div>

**Expected result:** the TXT record is visible publicly and the order moves on.

## Custom upload is rejected

Check the PEM boundaries, size, private-key match, key algorithm, validity dates,
chain order, and coverage of every proxied hostname. A wildcard covers only one label.

## HTTPS serves the bootstrap certificate

The runtime could not choose an active certificate for the domain. Check:

- the hostname and SNI;
- the TLS mode;
- the active certificate metadata;
- the edge revision acknowledgement;
- the target pool assignment;
- the per-pool runtime file.

Never replace the bootstrap pair with a customer key.

## Expected response is not cached

Check each of these:

- cache is enabled for the domain, and development mode has not expired;
- the request method, `Authorization` header, and bypass cookies;
- the query-key policy;
- the response status, `Set-Cookie`, `Cache-Control`, and `Vary`;
- range handling and object size;
- free cache capacity in the cell.

See [Cache](../guides/cache.md).

## Purge remains failed

<div class="steps">

1. Read each edge's purge task status and `last_error`.
2. Confirm the agent can reach each cell control endpoint with the right status token.
3. Retry the durable task, or run purge reconciliation.

</div>

Do not create many new purges. **Expected result:** the task completes on every edge.

See [ACME failure](../operations/runbooks.md#acme-failure) and
[Cache purge failure](../operations/runbooks.md#cache-purge-failure).
