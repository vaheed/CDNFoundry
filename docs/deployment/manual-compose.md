---
title: Manual production deployment with Docker Compose
description: Build and operate a CDNFoundry installation step by step with the checked-in production Compose file, without Fleet, Make, or repository scripts.
---

# Manual production deployment with Docker Compose

This is the advanced, script-free installation path. It uses the checked-in
`compose.prod.yml` and its checked-in configuration files directly. It does not
use CDNFoundry Fleet, generated bundles, `make`, or any file under `scripts/`.

You remain responsible for inventory, secret generation and custody, private
PKI, host firewalls, DNS, transferring configuration between hosts, upgrades,
backups, and recording what is installed. Compose starts the declared services;
it does not replace those operator responsibilities.

::: warning Meaning of "Compose only"
The production Compose file pulls immutable published images. It does not build
application images on a production host. Standard host tools such as `git`,
`install`, `openssl`, `curl`, and `dig` are used to prepare and verify the
installation, but every CDNFoundry service and migration is run through
`docker compose`.
:::

## Resulting topology

Use at least three Linux hosts in separate failure domains:

| Host | Compose profiles | Public listeners |
| --- | --- | --- |
| `control-1` | `control`, optionally `telemetry` and `logs` | TCP 80/443, UDP 443, TCP 8443; TCP 8444 when telemetry is colocated |
| `pop-1` | `dns`, `edge`, optionally `logs` | UDP/TCP 53, TCP 80/443 on mapped service addresses, TCP 8444 from control only |
| `pop-2` | `dns`, `edge`, optionally `logs` | Same as `pop-1` |

Do not deploy a single public DNS node. The control database and Valkey are
single-host dependencies in this base topology; their availability and backups
remain explicit operator concerns. PostgreSQL is desired state. PowerDNS data,
edge snapshots, and telemetry are derived or rebuildable.

Use two unrelated DNS zones:

- an operator zone, such as `ops.example.com`, for control, Grafana, telemetry,
  edge-control, and DNS API names;
- a platform/customer zone, such as `example.net`, for nameservers, delegated
  customer zones, and proxied hostnames.

Keep the operator zone at your existing authoritative provider. Never delegate
it to CDNFoundry.

## 1. Record the installation plan

Before touching a host, record:

- the exact release tag or 40-character commit SHA;
- hostnames, public and private IPv4/IPv6 addresses, NAT mappings, and failure
  domains;
- `control.ops.example.com`, `edge-control.ops.example.com`, `telemetry.ops.example.com`,
  `grafana.ops.example.com`, and one `dns-api-N.ops.example.com` per DNS host;
- `ns1.example.net` and `ns2.example.net` plus registrar glue addresses;
- the advertised-to-local address map for each edge;
- firewall sources for edge control, DNS API, telemetry ingestion, operational
  logs, gateway metrics, and administration;
- backup repository, retention, restoration owner, and recovery location for
  application keys and private PKI.

The local side of every `EDGE_GATEWAY_ADDRESS_MAP` entry must exist on that edge
host. A public address assigned directly to the host maps to itself. Behind
NAT, a firewall, router, or layer-4 load balancer must map the advertised
address one-to-one to a distinct private listener. Do not use a wildcard or
loopback address.

## 2. Prepare every host

Install a supported Linux distribution, Docker Engine, Docker Compose v2, Git,
OpenSSL, curl, and DNS diagnostic tools. Configure clock synchronization and a
host firewall. Permit outbound HTTPS for image pulls, ACME, MMDB updates, and
origin access.

Check out the same immutable revision on all three hosts:

```bash
git clone https://github.com/vaheed/CDNFoundry.git /opt/cdnfoundry
cd /opt/cdnfoundry
git checkout v1.0.0
git rev-parse --verify HEAD
docker version
docker compose version
```

Replace `v1.0.0` with the selected immutable release. If GHCR requires
authentication, use a read-only package token with `docker login ghcr.io`.

Create protected configuration directories on each host:

```bash
sudo install -d -m 0750 /etc/cdnfoundry
sudo install -d -m 0700 /etc/cdnfoundry/secrets
sudo install -d -m 0700 /etc/cdnfoundry/pki
cp .env.prod.example .env.prod
chmod 0600 .env.prod
```

Never commit `.env.prod`, secret files, private keys, edge bootstrap tokens, or
database dumps.

## 3. Generate independent secrets

Generate every value independently. Run these commands in a protected shell;
do not paste their output into tickets or chat:

```bash
openssl rand -base64 32
openssl rand -hex 32
```

Use the base64 result prefixed with `base64:` for `APP_KEY`. Use a new hex
result for each of:

- `EDGE_ARTIFACT_SIGNING_KEY`;
- `CONTROL_DB_PASSWORD`;
- `REDIS_PASSWORD`;
- `PDNS_DB_PASSWORD`;
- `PDNS_API_KEY`;
- `CLICKHOUSE_PASSWORD`;
- `GRAFANA_ADMIN_PASSWORD`;
- `GRAFANA_CLICKHOUSE_PASSWORD`;
- `GRAFANA_POSTGRES_PASSWORD`;
- each edge host's `EDGE_STATUS_TOKEN`.

Create the metrics token without printing it:

```bash
umask 077
openssl rand -hex 32 | sudo tee /etc/cdnfoundry/secrets/metrics-token >/dev/null
sudo chmod 0600 /etc/cdnfoundry/secrets/metrics-token
```

Use the same `APP_KEY`, artifact signing key, database credentials, metrics
token, and telemetry credentials wherever the same logical installation needs
them. Use a different edge status token on each edge. When backups are disabled,
keep `RESTIC_REPOSITORY` and its credentials empty and
`RESTIC_PASSWORD_FILE=/dev/null`. When enabled, create a different mode-`0600`
Restic password file and keep the encrypted repository off-host.

## 4. Create the private PKI manually

Perform CA operations on a protected administration host. The following is a
minimal OpenSSL procedure. Replace all example hostnames before running it.

Create the edge-identity CA, which signs agent client certificates after
one-time enrollment:

```bash
umask 077
mkdir cdnfoundry-pki
cd cdnfoundry-pki
openssl ecparam -name prime256v1 -genkey -noout -out edge-identity-ca.key
openssl req -x509 -new -sha256 -days 3650 \
  -key edge-identity-ca.key -out edge-identity-ca.crt \
  -subj '/CN=CDNFoundry edge identity CA' \
  -addext 'basicConstraints=critical,CA:TRUE' \
  -addext 'keyUsage=critical,keyCertSign,cRLSign'
```

Create a separate server CA:

```bash
openssl ecparam -name prime256v1 -genkey -noout -out edge-server-ca.key
openssl req -x509 -new -sha256 -days 3650 \
  -key edge-server-ca.key -out edge-server-ca.crt \
  -subj '/CN=CDNFoundry private server CA' \
  -addext 'basicConstraints=critical,CA:TRUE' \
  -addext 'keyUsage=critical,keyCertSign,cRLSign'
```

For each private server certificate, create a key and CSR, then sign it with the
server CA. This example creates the edge-control certificate:

```bash
openssl ecparam -name prime256v1 -genkey -noout \
  -out edge-control-server.key
openssl req -new -sha256 -key edge-control-server.key \
  -out edge-control-server.csr \
  -subj '/CN=control.ops.example.com' \
  -addext 'subjectAltName=DNS:control.ops.example.com' \
  -addext 'extendedKeyUsage=serverAuth'
openssl x509 -req -sha256 -days 825 \
  -in edge-control-server.csr \
  -CA edge-server-ca.crt -CAkey edge-server-ca.key -CAcreateserial \
  -copy_extensions copy -out edge-control-server.crt
```

Repeat that three-command sequence with these output names and SANs:

| Files | Required SAN |
| --- | --- |
| `edge-runtime.crt/.key` | a bootstrap hostname owned by the edge deployment |
| `dns-api-1.crt/.key` | `dns-api-1.ops.example.com` |
| `dns-api-2.crt/.key` | `dns-api-2.ops.example.com` |

Remove CSRs after verifying every certificate:

```bash
openssl verify -CAfile edge-server-ca.crt \
  edge-control-server.crt edge-runtime.crt dns-api-1.crt dns-api-2.crt
openssl x509 -in edge-control-server.crt -noout -subject -issuer -dates \
  -ext subjectAltName
```

Distribute material as follows:

| Host | Files |
| --- | --- |
| control | identity CA certificate and key, server CA certificate, edge-control certificate and key |
| each edge | server CA certificate, edge-runtime certificate and key, that host's DNS API certificate and key |
| offline recovery custody | both CA keys, both CA certificates, all server keys/certificates |

Never put `edge-server-ca.key` or `edge-identity-ca.key` on an edge. On control,
the shipped PHP-FPM process must read the identity CA key:

```bash
sudo chown root:82 /etc/cdnfoundry/pki/edge-identity-ca.key
sudo chmod 0640 /etc/cdnfoundry/pki/edge-identity-ca.key
sudo chmod 0600 /etc/cdnfoundry/pki/*-server.key
```

Keep all other private keys mode `0600`. Preserve the CA keys in encrypted,
off-host recovery storage.

## 5. Configure `.env.prod` on every host

Edit the copied template rather than creating a shortened environment. Compose
interpolates the complete model, including required values belonging to inactive
profiles. Therefore every `.env.prod` must contain syntactically valid values
for all required substitutions, while paths only need to exist on hosts that
start the corresponding service.

### Shared control values

Set the exact same release and installation-wide credentials on all hosts:

```dotenv
CDNF_RELEASE=v1.0.0
CDNF_CORE_IMAGE=ghcr.io/vaheed/cdnfoundry-core@sha256:REPLACE_FROM_RELEASE_MANIFEST
CDNF_WEB_IMAGE=ghcr.io/vaheed/cdnfoundry-web@sha256:REPLACE_FROM_RELEASE_MANIFEST
# Set every CDNF_*_IMAGE entry from release-manifest.json in the same way.
APP_KEY=base64:REPLACE_WITH_32_BYTE_BASE64_VALUE
EDGE_ARTIFACT_SIGNING_KEY=REPLACE_WITH_UNIQUE_VALUE
APP_URL=https://control.ops.example.com
SESSION_SECURE_COOKIE=true
CONTROL_HOSTNAME=control.ops.example.com
TELEMETRY_HOSTNAME=telemetry.ops.example.com
GRAFANA_HOSTNAME=grafana.ops.example.com
EDGE_CONTROL_URL=https://edge-control.ops.example.com:8443
METRICS_TOKEN_FILE=/etc/cdnfoundry/secrets/metrics-token
```

`CDNF_RELEASE` is audit metadata and a coordinated rollout identifier. Compose
pulls the separate `CDNF_*_IMAGE` references; production values must be the nine
verified `@sha256` references from `release-manifest.json`, never reconstructed
tags.

Set all database, Valkey, PowerDNS, ClickHouse, Grafana, ACME, and backup values
in the template. Do not reuse credentials. The base Compose topology expects
the control host's local `control-db` and `redis`; leave `DB_HOST=control-db`,
`REDIS_HOST=redis`, and their URL fields empty.

### Control host

Use these certificate paths and binds:

```dotenv
CONTROL_BIND=127.0.0.1:8080
HOST_BIND_IPV4=0.0.0.0
EDGE_CONTROL_BIND=0.0.0.0:8443
EDGE_CONTROL_SERVER_CERTIFICATE=/etc/cdnfoundry/pki/edge-control-server.crt
EDGE_CONTROL_SERVER_PRIVATE_KEY=/etc/cdnfoundry/pki/edge-control-server.key
EDGE_IDENTITY_CA_CERTIFICATE=/etc/cdnfoundry/pki/edge-identity-ca.crt
EDGE_IDENTITY_CA_PRIVATE_KEY=/etc/cdnfoundry/pki/edge-identity-ca.key
PDNS_CA_CERTIFICATE=/etc/cdnfoundry/pki/edge-server-ca.crt
```

Set `EDGE_PUBLIC_*_ALLOWLIST` to the exact edge egress addresses allowed to
submit telemetry. Set `LOG_SOURCE_*_ALLOWLIST` to the exact node sources allowed
to push logs. Empty allowlists deny those remote paths.

### DNS and edge host

Give each host its own DNS API identity and registration fields:

```dotenv
DNS_BIND_V4=0.0.0.0
HOST_BIND_IPV4=0.0.0.0
DNS_API_HOSTNAME=dns-api-1.ops.example.com
DNS_API_SERVER_CERTIFICATE=/etc/cdnfoundry/pki/dns-api-1.crt
DNS_API_SERVER_PRIVATE_KEY=/etc/cdnfoundry/pki/dns-api-1.key
EDGE_CONTROL_CA_CERTIFICATE=/etc/cdnfoundry/pki/edge-server-ca.crt
EDGE_RUNTIME_TLS_CERTIFICATE=/etc/cdnfoundry/pki/edge-runtime.crt
EDGE_RUNTIME_TLS_PRIVATE_KEY=/etc/cdnfoundry/pki/edge-runtime.key
EDGE_GATEWAY_ADDRESS_MAP={"198.51.100.40":"198.51.100.40"}
EDGE_ID=
EDGE_BOOTSTRAP_TOKEN=
```

The example is a direct-public host: the advertised address is assigned to a
local interface, so it maps to itself. Behind one-to-one NAT, use the private
listener instead, for example
`EDGE_GATEWAY_ADDRESS_MAP={"198.51.100.40":"10.20.0.40"}`. Never use
`0.0.0.0` or `::` in this map. Set
`CONTROL_PUBLIC_*_ALLOWLIST` to the control worker's exact source address. JSON
values in `.env.prod` remain on one line. `pop-2` uses its own API hostname,
certificate, local mapping, status token, log identity, edge UUID, and
bootstrap token.

Keep IPv6 absent until routing, firewalling, DNS, and external reachability all
work. The base Compose file publishes IPv4; do not claim IPv6 service merely by
setting `HOST_BIND_IPV6`.

## 6. Validate and pull without helper commands

Run on every host from `/opt/cdnfoundry`:

```bash
docker compose --env-file .env.prod -f compose.prod.yml config --quiet
docker compose --env-file .env.prod -f compose.prod.yml config --profiles
docker compose --env-file .env.prod -f compose.prod.yml pull
```

Review the fully rendered model without saving it to a shared location because
it contains secrets:

```bash
docker compose --env-file .env.prod -f compose.prod.yml config
```

Confirm all image tags use the chosen immutable release and all bind-mounted
files resolve to the intended absolute paths. A successful `config --quiet`
checks Compose structure and interpolation; it does not check firewalls, file
contents, certificate chains, or remote reachability.

## 7. Start the control plane in dependency order

Start and wait for PostgreSQL, Valkey, and the MMDB updater:

```bash
docker compose --env-file .env.prod -f compose.prod.yml \
  --profile control up -d --wait --wait-timeout 180 \
  control-db redis mmdb-updater
```

Run the application migration explicitly. Container startup never migrates the
database:

```bash
docker compose --env-file .env.prod -f compose.prod.yml \
  --profile tools run --rm migrate
```

Start the control services:

```bash
docker compose --env-file .env.prod -f compose.prod.yml \
  --profile control up -d
docker compose --env-file .env.prod -f compose.prod.yml \
  --profile control ps
```

Create the first administrator interactively; the password is prompted and is
not placed in shell history:

```bash
docker compose --env-file .env.prod -f compose.prod.yml \
  --profile control run --rm core php artisan cdnf:admin:create \
  --name='Operations Administrator' --email='admin@example.com'
```

Verify locally and externally:

```bash
curl --fail http://127.0.0.1:8080/api/health
curl --fail http://127.0.0.1:8080/api/ready
curl --fail https://control.ops.example.com/api/health
```

If a service is not healthy, inspect only that service first:

```bash
docker compose --env-file .env.prod -f compose.prod.yml ps
docker compose --env-file .env.prod -f compose.prod.yml logs --tail=200 core
docker compose --env-file .env.prod -f compose.prod.yml logs --tail=200 horizon
```

## 8. Start authoritative DNS on both PoPs

On `pop-1`, start the PowerDNS database and MMDB updater first:

```bash
docker compose --env-file .env.prod -f compose.prod.yml \
  --profile dns up -d --wait --wait-timeout 180 pdns-db mmdb-updater
docker compose --env-file .env.prod -f compose.prod.yml \
  --profile tools run --rm pdns-migrate
docker compose --env-file .env.prod -f compose.prod.yml \
  --profile dns up -d
docker compose --env-file .env.prod -f compose.prod.yml \
  --profile dns ps
```

Repeat on `pop-2`. The initial schema is mounted into PostgreSQL's init
directory and applies only to a new `pdns-db` volume. The explicit
`pdns-migrate` service applies maintained runtime migrations and is safe to run
again. Never delete the volume to force initialization.

Before configuring clusters, confirm DNSdist answers on UDP and TCP and the DNS
API certificate matches its hostname:

```bash
dig @127.0.0.1 version.bind TXT CH +short
dig +tcp @127.0.0.1 version.bind TXT CH +short
openssl s_client -connect dns-api-1.ops.example.com:8444 \
  -servername dns-api-1.ops.example.com \
  -CAfile /etc/cdnfoundry/pki/edge-server-ca.crt </dev/null
```

An allowlisted control source should reach the API; every other source should
receive `403`. PowerDNS port 8081 and its PostgreSQL port must remain private.

## 9. Configure desired state before edge enrollment

Sign in at `https://control.ops.example.com/admin` and use this order:

1. Create both DNS clusters disabled. Use
   `https://dns-api-N.ops.example.com:8444`, that host's `PDNS_API_KEY`, and the
   server CA trust configured on control. Test each cluster, then enable it.
2. Configure the platform domain, `ns1`/`ns2`, glue A records, optional AAAA
   records, SOA defaults, and DNSSEC policy in **Control plane → System
   settings**. Apply only after both clusters are healthy, then wait for both
   platform deployments to acknowledge the revision.
3. Create a test domain and a DNS-only A/AAAA record. Wait until both clusters
   acknowledge the revision. Verify both authoritative servers over UDP and TCP,
   then create registrar glue and change delegation.
4. Create `pop-1` and `pop-2` in **Edge network → Edges**. Record each edge UUID
   and its one-time bootstrap token at the one-time display boundary.
5. Create or verify shared and quarantine pools. Assign the fixed cell slots and
   create the public service endpoints that correspond exactly to each host's
   `EDGE_GATEWAY_ADDRESS_MAP`.

If the service endpoint is the host's only public address, leave the optional
management address blank on the edge record; management inventory addresses
cannot also be service endpoints. A correctly enrolled edge becomes healthy
with zero domains by activating an empty sequence-`0` generation. Assign a cell
before creating its Geo-Unicast endpoint; no placeholder customer domain is
required.

Never put an API bearer token in `.env.prod`. API-driven setup must use
`Idempotency-Key` on mutations and poll operations returned with `202 Accepted`.

## 10. Enroll and start each edge

On each PoP, place only that host's UUID and one-time token in `.env.prod`:

```dotenv
EDGE_ID=00000000-0000-0000-0000-000000000000
EDGE_BOOTSTRAP_TOKEN=REPLACE_WITH_ONE_TIME_TOKEN
```

Start all eight bounded cell slots, the gateway, agent, Vector, and MMDB updater:

```bash
docker compose --env-file .env.prod -f compose.prod.yml \
  --profile edge up -d
docker compose --env-file .env.prod -f compose.prod.yml \
  --profile edge ps
docker compose --env-file .env.prod -f compose.prod.yml \
  logs --tail=200 edge-agent edge-gateway
```

Wait until the administrator panel reports registered identity, a fresh
heartbeat, ready assigned cells, and the expected runtime revision. Then remove
the token value from `.env.prod` and recreate only the agent:

```bash
docker compose --env-file .env.prod -f compose.prod.yml \
  --profile edge up -d --force-recreate edge-agent
```

Do not remove `edge-agent-state`; it contains the enrolled client identity. Do
not copy that volume to another edge. If it is lost, rotate the edge identity in
the administrator panel and perform a new one-time enrollment.

## 11. Enable telemetry and operational logs

Telemetry is not in the request path, but production operators should deploy
and monitor it. On a colocated control/telemetry host, first ensure the Grafana
PostgreSQL settings point at the local control database, then start telemetry:

```bash
docker compose --env-file .env.prod -f compose.prod.yml \
  --profile telemetry up -d
docker compose --env-file .env.prod -f compose.prod.yml \
  --profile telemetry ps
```

Run one operational log collector per host, with a unique `LOG_HOST`,
`LOG_ROLE`, and `LOG_COLLECTOR_ID` in that host's environment. Set the shared
Loki bearer credential as `LOG_AUTH_TOKEN`; Compose passes it only to the
collector and the canonical Vector configuration expands it inside that
container. The telemetry Caddy gateway requires the same bearer value in
addition to its source-IP allowlist:

```bash
docker compose --env-file .env.prod -f compose.prod.yml \
  --profile logs up -d log-collector
```

Restrict telemetry port 8444 to the exact configured sources. Keep ClickHouse,
Loki, Prometheus, Alertmanager, Vector, PostgreSQL, Valkey, and container metrics
private. Confirm Grafana datasources and Prometheus targets before relying on
alerts.

## 12. Delegate and qualify a real test domain

Only delegate after both DNS clusters and both edge hosts are healthy:

```bash
dig +short A control.ops.example.com @1.1.1.1
dig +tcp SOA example.net @ns1.example.net
dig +tcp SOA example.net @ns2.example.net
curl --fail --resolve www.example.net:443:EDGE_1_IP \
  https://www.example.net/
curl --fail --resolve www.example.net:443:EDGE_2_IP \
  https://www.example.net/
```

Qualify DNS over UDP and TCP, origin safety, IPv4 and any explicitly enabled
IPv6, HTTP-to-HTTPS behaviour, managed TLS issuance, cache miss/hit, URL purge,
full purge, development mode, security and quarantine behaviour, telemetry,
alerts, restarts, and restoration from an off-host backup. A telemetry failure
must not stop DNS or HTTP serving.

Record the release, image digests, host inventory, environment-file checksums
(not contents), public certificate fingerprints, private CA fingerprints,
operation IDs, migration results, test evidence, and known deviations.

## Routine operations

### Inspect and restart

```bash
docker compose --env-file .env.prod -f compose.prod.yml ps
docker compose --env-file .env.prod -f compose.prod.yml logs --tail=200 SERVICE
docker compose --env-file .env.prod -f compose.prod.yml restart SERVICE
```

Restart one DNS or edge host at a time and verify it before continuing. Use
`docker compose stop` for planned stops. Never use `docker compose down -v`.

### Upgrade

1. Back up PostgreSQL plus `APP_KEY`, `EDGE_ARTIFACT_SIGNING_KEY`, private PKI,
   TLS material, and the Restic key. Verify restoration before the change.
2. Check out the target immutable revision on every host and set the same exact
   `CDNF_RELEASE` in every `.env.prod`.
3. Run `config --quiet` and `pull` on all hosts.
4. On control, run the `migrate` one-shot service before recreating control
   processes. Restart Horizon cleanly by recreating it through Compose.
5. On each DNS host, run `pdns-migrate`, then replace and verify one DNS host at
   a time.
6. Replace and verify one edge at a time. Preserve `edge-state`,
   `edge-agent-state`, gateway state, and cache volumes. Confirm the new runtime
   acknowledgement before moving to the next edge.
7. Upgrade telemetry last and run the versioned ClickHouse migrations required
   by the selected release documentation.

Example control upgrade commands:

```bash
docker compose --env-file .env.prod -f compose.prod.yml pull
docker compose --env-file .env.prod -f compose.prod.yml \
  --profile tools run --rm migrate
docker compose --env-file .env.prod -f compose.prod.yml \
  --profile control up -d --remove-orphans
```

Rollback application images only when the release notes say the database change
is backward compatible. Restore data only in an explicit recovery procedure;
never improvise a schema downgrade.

### Back up and recover

At minimum, recovery needs:

- the control PostgreSQL backup;
- `APP_KEY` and `EDGE_ARTIFACT_SIGNING_KEY`;
- identity and server CA keys/certificates;
- public and private TLS material;
- `.env.prod` values or an equivalent secrets record;
- the Restic password and narrowly scoped object-storage credentials.

Named Compose volumes persist across `stop`, `restart`, and ordinary `down`.
Never delete them during testing or upgrade. PowerDNS and edge runtime state can
be rebuilt from desired state, but preserving them reduces recovery time.

## Failure guide

| Symptom | Check first | Safe response |
| --- | --- | --- |
| Compose rejects interpolation | Required values in `.env.prod`, JSON quoting, absolute paths | Correct the environment; rerun `config --quiet` |
| `mmdb-updater` is unhealthy | Provider reachability, target filename, checksum, volume | Preserve the last valid MMDB; fix download settings |
| Migration cannot connect | Dependency health, password, Docker network, disk | Leave old services/state intact; repair dependency and rerun the idempotent migration |
| DNS API returns `403` | Actual control source IP and allowlist | Correct the narrow allowlist; do not expose PowerDNS directly |
| Edge enrollment fails | URL SAN, server CA, UUID/token, clock, port 8443 | Fix trust/reachability; rotate a consumed token instead of reusing it |
| Empty edge repeats `generation revision must be positive` | Edge-agent release predates empty-bootstrap support | Deploy a release containing the empty-bootstrap fix; do not create a placeholder domain |
| First endpoint remains unacknowledged on an empty edge | Agent generation log, same-edge pool assignment, exact address-map coverage, gateway log | Assign a non-drained cell to the same pool, confirm its empty runtime, and verify gateway activation |
| Gateway refuses endpoints | `EDGE_GATEWAY_ADDRESS_MAP` and local IP assignment | Map a directly assigned public address to itself, or add exact one-to-one private mappings behind NAT |
| New runtime is rejected | Agent/cell logs, checksum/signature, status token, bounds | Keep the previous valid generation active and repair desired state |
| Telemetry is unavailable | ClickHouse/Vector/Loki health, allowlists, buffers | Restore telemetry independently; do not stop serving traffic |

For deeper diagnosis, use [Runbooks](../operations/runbooks.md),
[DNS troubleshooting](../troubleshooting/dns.md), and
[Edge and origin troubleshooting](../troubleshooting/edge-and-origin.md).

## Completion gate

The installation is complete only when all of these are recorded separately:

- **Implementation:** control, two authoritative DNS nodes, two enrolled edges,
  bounded cell assignments, gateway mappings, and optional telemetry are active.
- **Documentation:** inventory, firewall matrix, DNS/glue, secret custody,
  backup/restore, upgrade, and rollback records match the deployed revision.
- **Automated/runtime qualification:** health, migrations, UDP/TCP DNS, edge
  traffic, cache, TLS, failure behaviour, telemetry isolation, restart, and
  recovery evidence pass on the real topology.
- **Manual browser qualification:** the owner completes the current checklist
  in the repository file `docs/manual-browser-qualification.md`. This guide
  does not run browser automation.
