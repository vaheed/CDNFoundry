---
title: Internal certificates
description: Generate, distribute, verify, and rotate CDNFoundry edge-control, runtime, and DNS API certificates.
---

# Internal certificates

Customer TLS is documented in [TLS certificates](/guides/tls). This page covers
private service PKI used by edge enrollment, agent control, bootstrap listeners,
and DNS API gateways.

## Generated material

`scripts/generate-production-certificates.sh` creates:

| File | Purpose |
| --- | --- |
| `edge-identity-ca.crt` | Trust issued edge-agent client identities |
| `edge-identity-ca.key` | Sign edge-agent CSRs; control host only |
| `edge-server-ca.crt` | Trust edge-control, runtime, and DNS API servers |
| `edge-server-ca.key` | Issue private server certificates; offline/protected |
| `edge-control-server.crt/.key` | Mutual-TLS control listener |
| `edge-runtime.crt/.key` | Bootstrap/default OpenResty listener |
| `dns-api-N.crt/.key` | One private PowerDNS API gateway |

The helper creates ECDSA P-256 CAs and server certificates, sets certificate
files mode `0644` and keys `0600`, verifies the chains, and refuses to overwrite
an existing output directory.

## Distribution

- The control core needs the identity CA certificate and restricted private key.
- Edge-control needs its server pair and the identity CA certificate.
- Every edge agent needs only `edge-server-ca.crt`.
- Each edge runtime needs the runtime server pair.
- Each DNS API gateway needs its own pair.
- Control workers that call DNS APIs need `edge-server-ca.crt` as
  `PDNS_CA_CERTIFICATE`.
- The server and identity CA private keys belong in the encrypted recovery set.

For the shipped core image, the identity CA key should be readable by the
PHP-FPM worker without being world-readable; use group ID 82 and mode `0640`.
The entrypoint fails closed if PHP-FPM cannot read it.

## Add a DNS host

Use the existing server CA without replacing it:

```sh
scripts/issue-production-dns-api-certificate.sh \
  /secure/cdnfoundry-pki \
  dns-api-3 \
  dns-api-3.ops.example.com
```

The command validates names, refuses overwrite, generates a random certificate
serial, verifies the result, and removes the CSR.

## Rotation

Use overlap:

1. issue a new certificate or CA;
2. deploy new trust before new identity where a CA changes;
3. restart one gateway, control listener, agent, or cell at a time;
4. verify mutual TLS, health, artifact acknowledgement, and serving;
5. remove old trust only after all dependants use the new identity.

For edge identity rotation, use the administrator action. It invalidates the old
serial and exposes a replacement bootstrap token once. Replace lost agent state
rather than copying another edge's key.

Public Caddy certificates are ACME-managed by Caddy and are separate from this
private PKI.
