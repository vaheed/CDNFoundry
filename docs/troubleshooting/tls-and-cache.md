---
title: Troubleshoot TLS and cache
description: Diagnose managed and custom TLS, cache admission, development mode, and purge delivery.
---

# Troubleshoot TLS and cache

## Managed order is not created

The domain must be active, nameserver verified, and contain a proxied hostname.
Confirm ACME is enabled and an order budget remains. DNS-only domains
intentionally have no order.

## Order stalls on challenge

Inspect the latest order, challenge rows, DNS desired revision, and every DNS
deployment acknowledgement. Query the TXT record through public authoritative
DNS. Check CA directory access and clock only after DNS is correct.

## Custom upload is rejected

Check PEM boundaries, size, private-key match, key algorithm, validity dates,
chain ordering, and coverage for every required proxied hostname. A wildcard
covers only one label.

## HTTPS serves the bootstrap certificate

The runtime could not select an active domain certificate. Check hostname/SNI,
TLS mode, active certificate metadata, edge revision acknowledgement, target
pool assignment, and the per-pool runtime file. Do not replace the bootstrap
pair with a customer key.

## Expected response is not cached

Check cache enablement, development mode expiry, method, authorization, bypass
cookies, query-key policy, response status, `Set-Cookie`, `Cache-Control`,
`Vary`, range handling, object size, and cell-local capacity.

## Purge remains failed

Inspect per-edge task status and `last_error`, then confirm the agent can reach
each cell control endpoint with the correct status token. Retry the durable task
or purge reconciliation; do not create many new purges.

See [ACME failure](../operations/runbooks.md#acme-failure) and
[Cache purge failure](../operations/runbooks.md#cache-purge-failure).
