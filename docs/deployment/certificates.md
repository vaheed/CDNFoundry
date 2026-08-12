---
title: Internal certificates
description: Generate, distribute, verify, and rotate CDNFoundry edge-control, runtime, and DNS API certificates.
---

# Internal certificates

::: danger CA private keys define fleet identity
Never copy `edge-server-ca.key` or `edge-identity-ca.key` to an edge host.
Losing or replacing the identity CA without a planned rotation breaks agent
trust and cannot be repaired from PostgreSQL alone.
:::

Customer TLS is documented in [TLS certificates](../guides/tls.md). This page covers
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

## Which address belongs in a certificate?

There are two different identities in the edge-control mTLS connection:

- The **edge-control server certificate** must contain the DNS hostname from
  `EDGE_CONTROL_URL`. CDNFoundry requires a hostname here; publish it in
  operator DNS instead of configuring agents with a literal IP. This hostname
  belongs to the control service, not an edge management or pool endpoint.
- The **edge-agent client certificate** is created during enrollment from the
  agent's CSR. Its common name is the administrator-created edge UUID. It does
  not contain a management IP or service endpoint IP.

Management addresses may be absent or private. Pool endpoint addresses are
customer traffic listeners. Neither is required for agent enrollment, task
polling, heartbeats, or artifact acknowledgement.

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

For edge identity rotation, open the edge under **Infrastructure → Edges** and
choose **Rotate identity**. The confirmation explains the immediate effect:
the old certificate is rejected and heartbeat/configuration delivery pauses,
while the gateway and cells can continue serving their previous valid runtime.
Confirm only while an operator is ready to update Fleet state and the matching
PoP immediately.

After rotation, a non-dismissible one-time recovery modal uses the same guided
layout as initial edge enrollment and shows the unchanged edge UUID, replacement
token, and exact commands in three distinct contexts:

1. register and rerender on the Fleet authority;
2. transfer the complete bundle and recreate only `edge-agent` on the named PoP;
3. after heartbeat returns, clear the consumed token, rerender and transfer the
   token-free bundle, then recreate only `edge-agent` once more.

Do not stop DNS, the gateway, or cells, edit `.env.prod`, reuse the token, or
copy another edge's identity volume. If the modal is left before the replacement
token is saved, rotate again and use the newest token only. The complete command
sequence is also documented in the [Fleet operator guide](production-fleet-operator-guide.md#rotate-an-edge-identity).

Public Caddy certificates are ACME-managed by Caddy and are separate from this
private PKI.
