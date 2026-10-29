---
name: cdnf-tls-lifecycle
description: Work on CDNFoundry certificates: managed ACME DNS-01 issuance and renewal, uploaded certificates, key handling, and distribution to edges. Use for any TLS change.
---

# cdnf-tls-lifecycle

## Purpose
Issue, renew, validate, and distribute certificates without ever losing a valid certificate or exposing a key.

## When to use
Changes to managed or custom TLS, ACME, renewal scheduling, or certificate delivery to edges.

## Required inputs
The names (from `ManagedCertificateNames`), the issuer, the renewal window, key storage, and the delivery path.

## Files normally touched
`core/app/Http/Controllers/TlsController.php`, `core/app/Support/UploadedCertificate.php`, `AcmeClient.php`, `AcmeDnsPropagation.php`, `ManagedCertificateNames.php`, `core/app/Jobs/IssueManagedCertificate.php`, `EnsureManagedCertificates.php`, `core/app/Console/Commands/DispatchManagedTlsMaintenance.php`, `core/app/Models/TlsCertificate.php`, `TlsOrder.php`, `docs/guides/tls.md`.

## Procedure
1. Issue only for hostnames that are proxied on an active, verified domain, using DNS-01 TXT records published by `PowerDnsZone::render()`.
2. Validate uploads: key matches certificate, full chain, names covered, algorithm and size, not expired.
3. Keys stay encrypted (`encrypted` cast on `private_key_ciphertext`). Reading the attribute decrypts it, so never copy it into JSON, snapshots, logs, or API responses (roadmap C1, C2).
4. Spread renewals across days, not only by a few seconds of initial jitter. Keep the current certificate active until its replacement is deployed.
5. The ACME directory in development is Pebble (`compose.dev.yml` service `pebble`).

## Validation commands
- Backend: `make dev-test`, or one class with `docker compose -f compose.dev.yml -f compose.test.yml run --rm --no-deps core php artisan test --compact --filter <Class>` (the test override forces in-memory SQLite; never run tests against PostgreSQL).
- Style: `docker compose -f compose.dev.yml -f compose.test.yml run --rm --no-deps core vendor/bin/pint --test`.
- Feature: `TlsApiTest`, `ManagedTlsTest`.
- Runtime: `python3 tests/e2e/phase5_tls.py`, `python3 tests/e2e/uploaded_tls.py`.

## Definition of done
Valid certificates never disappear, keys never appear outside `tls_certificates` and the cell, and failures and expiry are visible to the administrator.

## Stop conditions
Stop if issuance would need HTTP-01, fake DNS records, a DNS-only domain, or a synchronous ACME call from a request.

## Forbidden shortcuts
No storing decrypted keys, no deleting the active certificate before its replacement is acknowledged, and no ACME retries without the global order budget.

## Expected completion summary
Names, issuer flow, renewal timing, key handling, edge delivery evidence, and failure behavior.
