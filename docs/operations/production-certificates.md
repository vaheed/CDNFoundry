# Production certificate bootstrap and rotation

CDNFoundry uses three distinct certificate roles:

- `edge-identity-ca`: signs short-lived edge-agent client identities. Its private key belongs only on the control plane.
- `edge-server-ca`: signs the edge-control and bootstrap runtime server certificates. Only its public certificate is copied to edge-agent hosts.
- `edge-control-server`: secures the agent-facing control endpoint such as `edge-control.example.com:8443`.
- `edge-runtime`: bootstrap/default certificate for the public OpenResty listener. Phase 5 dynamically selects each active domain's managed or custom certificate by SNI; the mounted certificate remains only the non-customer bootstrap fallback.

The repository helper creates a private CA and two ten-year P-256 server certificates. Ten years is useful for private bootstrap infrastructure but increases key-compromise exposure. Prefer certificates from your organizational PKI with automated renewal when available. Public browsers will not trust the generated private CA automatically.

## Initial generation

Run on a protected control-plane administration host, outside the repository:

```sh
./scripts/generate-production-certificates.sh \
  /etc/cdnfoundry/pki \
  edge-control.example.com \
  edge.example.com \
  203.0.113.10 \
  203.0.113.20
```

The IP arguments are optional. Supply them only when agents or clients connect by IP. The script refuses to overwrite an existing directory, creates private keys with mode `0600`, verifies both chains, and prints no private-key content.

Set `.env.prod` paths:

```dotenv
EDGE_CONTROL_SERVER_CERTIFICATE=/etc/cdnfoundry/pki/edge-control-server.crt
EDGE_CONTROL_SERVER_PRIVATE_KEY=/etc/cdnfoundry/pki/edge-control-server.key
EDGE_CONTROL_CA_CERTIFICATE=/etc/cdnfoundry/pki/edge-server-ca.crt
EDGE_IDENTITY_CA_CERTIFICATE=/etc/cdnfoundry/pki/edge-identity-ca.crt
EDGE_IDENTITY_CA_PRIVATE_KEY=/etc/cdnfoundry/pki/edge-identity-ca.key
EDGE_RUNTIME_TLS_CERTIFICATE=/etc/cdnfoundry/pki/edge-runtime.crt
EDGE_RUNTIME_TLS_PRIVATE_KEY=/etc/cdnfoundry/pki/edge-runtime.key
```

Validate before starting services:

```sh
openssl x509 -in /etc/cdnfoundry/pki/edge-control-server.crt -noout -subject -issuer -dates -ext subjectAltName
openssl verify -CAfile /etc/cdnfoundry/pki/edge-server-ca.crt /etc/cdnfoundry/pki/edge-control-server.crt
docker compose --env-file .env.prod -f compose.prod.yml config --quiet
```

The control-plane PHP-FPM worker (UID/GID `82` in the shipped image) signs edge
CSRs, so it must be able to read the identity CA key without making that key
world-readable. On a single-host installation using the shipped image:

```sh
chown root:82 /etc/cdnfoundry/pki/edge-identity-ca.key
chmod 0640 /etc/cdnfoundry/pki/edge-identity-ca.key
```

Keep every other generated private key at `0600`. With a different container
UID/GID or a secrets manager, grant read access only to the effective PHP-FPM
identity. A root-only identity CA key makes enrollment fail closed as
`edge_identity_ca_unavailable`; do not weaken it to `0644`.

## Replacement and renewal

Never overwrite mounted key files in place. Generate into a new versioned directory, validate hostname/SAN, chain, key match, and dates, update the six `.env.prod` paths, then recreate only the affected services:

```sh
./scripts/generate-production-certificates.sh /etc/cdnfoundry/pki-2035 edge-control.example.com edge.example.com
docker compose --env-file .env.prod -f compose.prod.yml up -d --force-recreate edge-control edge edge-quarantine
```

Test agent registration/heartbeat and HTTPS on both edge cells before removing old material. Keep the previous directory until rollback is no longer needed.

Rotating only a server certificate while retaining the server and identity CAs does not invalidate edge client identities. Rotating the server CA requires distributing its new public certificate to edge agents before switching the server certificate. Rotating the identity CA is a coordinated client-trust migration: install the new trust anchor, re-register/rotate every edge identity, verify the fleet, and only then remove the old CA. Preserve both CA private keys, application key, artifact-signing key, and TLS material in encrypted off-host recovery storage.
