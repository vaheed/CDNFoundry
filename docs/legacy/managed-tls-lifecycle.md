# Managed TLS lifecycle

Managed TLS starts only after an active, nameserver-verified domain gains its first proxied A, AAAA, or CNAME hostname. DNS-only domains create no ACME order, challenge, certificate, or edge key material, and CDNFoundry never creates a fake apex address record.

## Issuance

The normal certificate covers the apex and one wildcard level. Deeper proxied hostnames are grouped into supplemental requests, with at most 50 names per order and 10 outstanding orders per domain. Before ordering, the worker reuses any active managed certificate that covers the required names beyond the renewal threshold.

Issuance is asynchronous on the certificate/purge lane:

1. A globally rate-limited, jittered worker creates or reuses the encrypted ACME account.
2. The worker creates an order and stores only revisioned desired state.
3. Temporary `_acme-challenge` TXT values enter the derived PowerDNS zone.
4. Validation waits until every enabled DNS cluster acknowledges at least the challenge revision.
5. The worker acknowledges DNS-01, polls authorization, creates a bounded local key and CSR, finalizes the order, and validates names, time bounds, and key matching.
6. In one transaction it stores the encrypted key, activates the new certificate, marks the order successful, increments the domain revision, and queues DNS cleanup plus edge reconciliation.

The edge agent receives certificates only for its assigned cells. It validates the artifact, writes snapshots containing private keys as mode `0600` under the shared fixed unprivileged runtime UID, then atomically activates them. OpenResty selects the certificate by SNI without a reload. Unknown or unavailable SNI is rejected.

## Renewal and replacement

The hourly maintenance command spreads renewal work with jitter. A valid existing certificate remains active while renewal or reconciliation is pending or failed. Managed material is retained as fallback when custom mode is selected. Removing a custom certificate selects a still-valid managed certificate when available and otherwise visibly returns to pending managed mode.

Use **Renew managed certificate** to reuse sufficient coverage or queue only missing/expiring coverage. **Reissue managed certificate** deliberately creates replacement orders within the same global and per-domain bounds. The status API and domain TLS section expose order state, requested names, attempts, bounded errors, active names, expiry, and fingerprint; no private key, CSR, token, or account key is returned.

## Operational checks

- Confirm delegation through DNSdist and all enabled PowerDNS deployments before diagnosing CA validation.
- Confirm the domain is active, nameserver-verified, and has at least one proxied eligible record.
- Inspect the latest `tls.managed_certificate` operation and TLS order before retrying.
- Never edit challenge rows in PowerAdmin; PowerDNS is derived state.
- Never delete the active certificate to force renewal. Use the bounded renew/reissue actions.
- Treat application encryption/signing keys and externally stored TLS material as required recovery assets.
