# cdnf-tls-lifecycle

## Purpose
Implement managed and uploaded TLS certificate lifecycle.
## When to use
For ACME, renewal, upload, encrypted storage, or edge delivery.
## Required inputs
Proxy prerequisites, names, issuer, challenge, custody/encryption, retry, and delivery contract.
## Files normally touched
Certificate models/API/jobs, DNS challenge/edge delivery, tests and runbooks.
## Procedure
Check active verified proxy need; reuse coverage; issue DNS-01 asynchronously; validate key/chain/names/algorithm/size/expiry; encrypt keys; jitter renewal; distribute safely; retain current valid certificate; expose failure/expiry.
## Validation commands
Feature/parsing tests, real test-CA/HTTPS, outage and renewal tests.
## Definition of done
Required names serve correct TLS while failures never expose keys or replace valid state.
## Stop conditions
Stop without key custody/recovery, DNS-01 authority, or continuity behavior.
## Forbidden shortcuts
No DNS-only issuance, fake records, plaintext keys, synchronous ACME, or private material in output.
## Expected completion summary
Coverage, custody, lifecycle, delivery, tests, and limitations.
