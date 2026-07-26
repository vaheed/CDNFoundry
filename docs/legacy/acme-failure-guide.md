# ACME failure and recovery

Managed issuance failures never interrupt authoritative DNS or remove the last valid serving certificate. The TLS section, status API, operation row, and administrator database notification expose the bounded failure message.

## Failure states

- **Pending/creating:** initial jitter or the global hourly order budget is delaying creation. Wait for the displayed availability time; repeated manual reissue does not bypass the budget.
- **Publishing:** every enabled DNS deployment must acknowledge the challenge revision. Repair an unhealthy cluster or its asynchronous reconciliation; do not acknowledge the CA challenge early.
- **Validating:** query `_acme-challenge.<name>` through public DNSdist over UDP and TCP. Verify delegation, zone boundaries, propagation, and that no stale external TXT conflicts.
- **Finalizing:** inspect the CA error and order URL metadata. Key/CSR and issued-name validation failures are terminal after the bounded retry budget and never activate the candidate.
- **Failed:** correct the root cause, then use renew or reissue once. The failed order and operation remain visible; a subsequent order is separate and idempotent within its own request boundary.
- **Obsolete:** the proxied hostname set changed during issuance. Cleanup is queued and current required coverage is recalculated.

## Safe recovery

1. Verify the active certificate and edge revision are still serving; do not revoke or delete them.
2. Check Horizon's `certificate_purge` lane and the corresponding operation attempts/error.
3. Check all enabled DNS cluster deployment acknowledgements against the order's DNS revision.
4. Query the challenge through DNSdist, never private PowerDNS as the traffic endpoint.
5. Correct CA directory/account email, time synchronization, delegation, or cluster health as appropriate.
6. Choose **Renew managed certificate** for normal recovery. Choose **Reissue** only when a deliberate new order is required.
7. Confirm the new certificate is active on every assigned cell before considering the incident closed.

Expired challenge state is cleaned by hourly maintenance and a new DNS revision. Expiring active certificates and failed orders generate deduplicated administrator alerts. If the CA or control plane remains unavailable, continue serving the last valid edge artifact and restore the dependency; never replace it with an incomplete bundle.
