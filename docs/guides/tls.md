---
title: TLS certificates
description: Operate managed DNS-01 issuance, custom certificate upload, renewal, and failure recovery.
---

# TLS certificates

| Mode | Private-key owner | Validation |
| --- | --- | --- |
| Managed | Control plane, encrypted | ACME DNS-01 after an eligible proxied hostname |
| Custom | Uploaded then encrypted | Key match, chain, names, expiry, and size |
| Disabled | No domain certificate | Explicit policy |

```mermaid
stateDiagram-v2
    [*] --> Ineligible: DNS-only or unverified
    Ineligible --> Pending: first eligible proxied hostname
    Pending --> Challenge: publish DNS-01
    Challenge --> Issued: DNS acknowledged and CA finalized
    Issued --> Published: edge revision acknowledged
    Published --> Renewing: jittered renewal window
    Renewing --> Published: valid replacement
    Renewing --> LastValid: issuance failure
    LastValid --> Renewing: bounded retry
```

::: danger Private-key handling
Never log or expose certificate keys. Recovery needs the original application
encryption key and externally retained TLS material.
:::

A domain TLS mode is `managed`, `custom`, or `disabled`.

Selecting custom mode requires a current, active, unexpired custom certificate
when the domain transaction acquires its lock. If the certificate was removed
or expired while the request waited, the API returns `409` / `conflict` and the
panel shows a **Mode** validation error. Reload and upload a valid certificate
before retrying. The rejected selection preserves the saved mode, certificate
and revision.

## Managed certificates

Managed issuance requires all of the following:

- lifecycle state `active`;
- verified nameservers;
- at least one proxied hostname;
- ACME enabled and configured;
- healthy authoritative DNS reconciliation.

DNS-only and unverified domains do not create ACME orders.

The certificate-name compiler creates bounded name sets. The usual set includes
the apex and wildcard; deep hostnames that a wildcard cannot cover use a
supplemental order. A valid existing certificate is reused where it covers the
required names.

Managed planning reads the current domain under its database lock. Reusing a
certificate cannot overwrite a newer custom-certificate selection, and
activation advances from the latest desired revision. Certificate activation,
orders and their operation records commit together; queued issuance and edge
reconciliation are released after commit. A failed write rolls back the plan
so the job can retry without leaving a partial activation.

The workflow writes DNS-01 challenges as desired TXT state, waits for every
required DNS acknowledgement, validates with the ACME server, stores the
encrypted private key and certificate, publishes a new edge revision, and
cleans challenge records. New orders are globally bounded per hour and initial
work is jittered.

Hourly maintenance renews certificates inside `ACME_RENEW_BEFORE_DAYS`, retries
eligible orders, removes expired challenge state, and creates deduplicated
administrator alerts for failures or impending expiry.

Each maintenance invocation considers at most `--limit` eligible domains
(default 500, clamped to 1–2,000). A shared cache cursor advances through a
fixed domain-ID range, then starts another sweep. Domains added during a sweep
join the next one. Disabled, unverified and DNS-only domains are excluded.
Overlapping domain scans are skipped under a shared lease; dispatch failure
does not advance the cursor. Lost cache progress restarts the sweep and may
repeat idempotent work; it does not remove certificates.

Budget the maintenance interval and queue capacity against the renewal window.
For a stable set of N eligible domains and batch size L, a sweep needs at most
`ceil(N / L) + 1` successful invocations, including a possible empty final batch.
At the default hourly cadence, 10,000 eligible domains therefore need up to
21 hours just to enqueue their checks. This is a scheduling bound, not a load
qualification or an issuance-time guarantee. Queue delay, retries and CA/DNS
work require additional margin.

## Manual managed actions

- **Renew** creates work only when renewal is due.
- **Reissue** forces a new managed order.
- `GET .../tls/status` includes the latest order without private material.

Both actions return `202` and an operation UUID.

Each action's operation reports completion of its planning job. Renewal may
reuse valid coverage and return an empty `queued_order_ids` list. A pending
reissue remains pending until its forced planning job runs; ordinary renewal
does not complete it. When reissue creates an order, its receipt records the
order ID and retains that result on retry. Planning success does not establish
certificate issuance or edge activation: inspect TLS order status, runtime
acknowledgements and verified HTTPS separately.

## Custom certificates

Upload a leaf certificate, issuing chain, and private key in PEM. Bounds are
16 KiB for leaf and key and 64 KiB for chain. Validation confirms:

- the key matches the leaf;
- accepted key algorithm and size;
- an ordered chain of at most ten issuing/root certificates ends at its
  self-signed root and verifies for TLS server authentication;
- CA constraints, signing key usage, path length, server purpose, name
  constraints and critical extensions permit the leaf;
- chain keys and non-root signatures satisfy OpenSSL authentication level 2
  (at least 112-bit security), rejecting 1024-bit RSA and SHA-1 leaf/issuer
  signatures; root self-signatures do not establish trust;
- the leaf, issuers and root are currently valid;
- names cover every required proxied hostname.

Private keys are encrypted before persistence and never returned. The response
contains only metadata and fingerprint. Removing the active custom certificate
returns the domain to managed mode and queues managed issuance.

Only normalized public certificate blocks are stored in the issuing-chain
field. Extra export text or private-key blocks in that field are discarded;
paste the private key only into **Private key PEM**. Re-uploading the same leaf
updates its validated chain without changing the certificate ID and queues a
new domain revision. A rejected upload changes neither the active certificate
nor the domain revision or edge artifact.
If another request changes the domain during certificate validation, the API
returns `409` with code `conflict`; the panel shows a validation error on
**Leaf certificate PEM**. Reload current domain state and retry with a bundle
covering its current proxied hostnames. The conflicting upload leaves the
active certificate and other request's committed revision intact.

The supplied root is the explicit trust anchor for validation, so a valid
private CA is supported. This does not install that CA into visitors' trust
stores or prove public-browser trust. Supply the appropriate chain for your
clients. Validation performs no remote CA or revocation lookup.
The control-plane image includes the pinned OpenSSL command-line verifier.
It checks public certificate PEM locally with a five-second timeout; private
keys never enter its input or command arguments. Custom non-container PHP
test environments also need the OpenSSL 3 command available on `PATH`.

When upgrading from an earlier validator, review existing custom bundles in a
restricted environment without printing their contents. Re-upload a complete,
currently valid bundle to replace legacy chain text, including when retaining
the same leaf. If a private key was pasted into the old chain field, treat its
unencrypted database and backup copies as exposed material: issue a replacement
key/certificate and revoke the old certificate through its issuer. Preserve
required backups under restricted access; this update does not erase historical
rows, snapshots or backups. Existing invalid chains require a valid replacement;
the update does not silently deactivate currently serving certificates.

## Failure behaviour

An ACME failure records the order error, cleans unusable challenge state, and
preserves any valid active certificate and edge artifact. Do not delete a valid
certificate to force recovery. Repair DNS, CA access, clock, or configuration,
then renew or reissue.

See [Certificates](../deployment/certificates.md) for internal service PKI, which is
separate from customer TLS.
