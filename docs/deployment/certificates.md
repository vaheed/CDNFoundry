---
title: Internal certificates
description: Create, distribute, check, and rotate the private certificates that CDNFoundry services use to trust each other, including edge identities.
---

# Internal certificates

::: danger CA private keys define fleet identity
Never copy `edge-server-ca.key` or `edge-identity-ca.key` to an edge host.
If you lose or replace the identity CA without a plan, agents stop trusting the
control plane. PostgreSQL cannot repair that.
:::

This page covers the private certificates between CDNFoundry's own services:
edge enrollment, agent control, bootstrap listeners, and the edge runtime. For
certificates that visitors see, read [TLS certificates](../guides/tls.md).

**You need:** a protected host to create the certificate authorities (CAs), and
the hostname from `EDGE_CONTROL_URL`. If you use Fleet, it creates all of this
for you and you only need the rotation and verification sections.

## Create the certificates

Run the helper with an output directory, the edge-control hostname, and a
bootstrap hostname for the edge runtime:

```bash
scripts/generate-production-certificates.sh ./pki edge-control.ops.example.com runtime.ops.example.com
```

Expect `openssl verify` to print `OK` for both server certificates, then
`Certificates created in ./pki (valid for 3650 days).` The script stops if the
output directory exists and is not empty. For a script-free procedure, see
[manual Compose deployment](manual-compose.md).

The helper creates:

| File | Purpose |
| --- | --- |
| `edge-identity-ca.crt` | Trust issued edge-agent client identities |
| `edge-identity-ca.key` | Sign edge-agent CSRs; control host only |
| `edge-server-ca.crt` | Trust edge-control and runtime servers |
| `edge-server-ca.key` | Issue private server certificates; offline/protected |
| `edge-control-server.crt/.key` | Mutual-TLS control listener |
| `edge-runtime.crt/.key` | Bootstrap/default OpenResty listener |

The helper uses ECDSA P-256 keys. It sets certificate files to mode `0644` and
keys to `0600`, and it checks both certificate chains.

## Which name goes in which certificate?

The mutual TLS (mTLS) connection between an edge agent and edge-control uses two
identities:

- The **edge-control server certificate** must contain the DNS hostname from
  `EDGE_CONTROL_URL`. It must be a hostname, not an IP address. Publish it in your
  operator DNS. This name belongs to the control service. It is not an edge
  management address or a pool endpoint.
- The **edge-agent client certificate** is issued during enrollment, from a
  request the agent creates. Its common name is the edge UUID that the
  administrator created. It holds no IP address.

Pool endpoint addresses carry customer traffic. Agents do not need them to
enroll, poll for tasks, send heartbeats, or acknowledge artifacts.

## Identity verification boundary

An issued edge-agent certificate cannot act as a CA. It allows only client
authentication with digital signatures. Edge-control passes the verified client
certificate, the verification result, and the serial straight to PHP-FPM. Laravel
checks that the certificate's SHA-256 fingerprint equals the certificate stored
for that edge when it enrolled. The ordinary web ingress clears these three
identity headers and denies `/edge/v1/` routes.

A valid chain, or a serial number sent by a caller, is not enough to
authenticate an edge. If you write your own ingress, keep this boundary. Follow
the [update order](upgrade.md#order) when you change existing listeners and
identities. Fleet refuses a CA pair that is incomplete and tells you which
recovery material to restore. It never replaces a CA key that survived.

## Distribution

- The control core needs the identity CA certificate and restricted private key.
- Edge-control needs its server pair and the identity CA certificate.
- Every edge agent needs only `edge-server-ca.crt`.
- Each edge runtime needs the runtime server pair.
- The server and identity CA private keys belong in the encrypted recovery set.

In the shipped core image, PHP-FPM must read the identity CA key without the key
being world-readable. Give it group ID 82 and mode `0640`. If PHP-FPM cannot read
the key, the entrypoint refuses to start.

## Rotation

### Rotate a server certificate or a CA

Let the old and new material overlap:

<div class="steps">

1. Issue the new certificate or CA.
2. If a CA changes, deploy the new trust before the new identity.
3. Restart one gateway, control listener, agent, or cell at a time.
4. After each restart, check mTLS, health, artifact acknowledgement, and serving.
5. Remove the old trust only when every dependant uses the new identity.

</div>

### Rotate an edge identity

Use this when an agent identity or its saved state is lost or may be stolen. It
revokes the old identity at once. Edge identity certificates also renew
themselves before they expire (default validity 365 days, set by
`EDGE_IDENTITY_VALIDITY_DAYS`; see [Edges and placement](../guides/edges.md#identity-renewal)).

<div class="steps">

1. Open the edge under **Infrastructure → Edges** and choose **Rotate identity**.
2. Read the confirmation. The old certificate is rejected immediately, and
   heartbeats and configuration delivery pause. The gateway and cells keep
   serving their last valid runtime. Confirm only when you can reach the matching
   edge host.
3. The modal shows the same `EDGE_ID` and a new `EDGE_BOOTSTRAP_TOKEN`. You
   cannot close and reopen it. Paste both values into the `.env.prod` on that
   host (mode `0600`).
4. Run `sudo docker compose --env-file .env.prod --profile edge up -d`. Compose
   recreates the agent because its environment changed.

</div>

**Expected result:** the edge sends a fresh heartbeat with the new identity. The
agent keeps its previous identity file until the new certificate is issued. After
the heartbeat, blank the spent token in `.env.prod`.

**If it fails:** if you closed the modal before saving the token, rotate again and
use only the newest token. Do not reuse a token. Do not stop DNS, the gateway, or
the cells. Do not delete `edge-agent-state` or copy another edge's identity
volume. More detail is in the
[Fleet operator guide](production-fleet-operator-guide.md#rotate-an-edge-identity).

Caddy gets and renews its own public certificates through ACME. They are separate
from this private PKI.
