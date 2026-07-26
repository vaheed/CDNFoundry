---
title: TLS certificates
description: Operate managed DNS-01 issuance, custom certificate upload, renewal, and failure recovery.
---

# TLS certificates

A domain TLS mode is `managed`, `custom`, or `disabled`.

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

The workflow writes DNS-01 challenges as desired TXT state, waits for every
required DNS acknowledgement, validates with the ACME server, stores the
encrypted private key and certificate, publishes a new edge revision, and
cleans challenge records. New orders are globally bounded per hour and initial
work is jittered.

Hourly maintenance renews certificates inside `ACME_RENEW_BEFORE_DAYS`, retries
eligible orders, removes expired challenge state, and creates deduplicated
administrator alerts for failures or impending expiry.

## Manual managed actions

- **Renew** creates work only when renewal is due.
- **Reissue** forces a new managed order.
- `GET .../tls/status` includes the latest order without private material.

Both actions return `202` and an operation UUID.

## Custom certificates

Upload a leaf certificate, issuing chain, and private key in PEM. Bounds are
16 KiB for leaf and key and 64 KiB for chain. Validation confirms:

- the key matches the leaf;
- accepted key algorithm and size;
- the chain verifies;
- the current time is inside validity;
- names cover every required proxied hostname.

Private keys are encrypted before persistence and never returned. The response
contains only metadata and fingerprint. Removing the active custom certificate
returns the domain to managed mode and queues managed issuance.

## Failure behaviour

An ACME failure records the order error, cleans unusable challenge state, and
preserves any valid active certificate and edge artifact. Do not delete a valid
certificate to force recovery. Repair DNS, CA access, clock, or configuration,
then renew or reissue.

See [Certificates](/deployment/certificates) for internal service PKI, which is
separate from customer TLS.
