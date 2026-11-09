---
title: Manual production deployment with Docker Compose
description: Build and operate a CDNFoundry installation step by step with the checked-in production Compose file, without Fleet, Make, or repository scripts.
---

# Manual production deployment with Docker Compose

You will install CDNFoundry on three hosts by running `docker compose` against
the checked-in `compose.prod.yml`. This path does not use CDNFoundry Fleet,
generated bundles, `make`, or any file under `scripts/`. Most operators should
use the [production quick start](production-quick-start.md) with Fleet instead.

**You need:**

- three Linux hosts in separate failure domains (see the table below);
- a signed release manifest with the seven image digests (see
  [software supply chain](../operations/software-supply-chain.md));
- a DNS provider for the operator zone, and a registrar for the platform zone;
- a protected administration host where you create the private certificate
  authorities.

You do the work Fleet would otherwise do: inventory, secrets and their custody,
private PKI, host firewalls, DNS, copying configuration between hosts, upgrades,
backups, and a record of what is installed. Compose starts the services. It does
not do those jobs for you.

::: warning Meaning of "Compose only"
The production Compose file pulls published images by digest. It does not build
images on a production host. You use ordinary host tools (`git`, `install`,
`openssl`, `curl`, `dig`) to prepare and check the installation. Every
CDNFoundry service and migration runs through `docker compose`.
:::

## Resulting topology

Use at least three Linux hosts in separate failure domains:

| Host | Compose profiles | Public listeners |
| --- | --- | --- |
| `control-1` | `control`, optionally `telemetry` and `logs` | TCP 80/443, UDP 443, TCP 8443, TCP/UDP 5300 from DNS nodes only (hidden primary); TCP 8444 when telemetry is colocated |
| `pop-1` | `dns`, `edge`, optionally `logs` | UDP/TCP 53, TCP 80/443 on mapped service addresses |
| `pop-2` | `dns`, `edge`, optionally `logs` | Same as `pop-1` |

Do not run a single public DNS node. In this topology the control database and
Valkey (the Redis-compatible queue and session store) live on one host, so you
own their availability and backups. PostgreSQL holds the desired state. PowerDNS
data, edge snapshots, and telemetry are derived and can be rebuilt.

Use two unrelated DNS zones:

- an operator zone, such as `ops.example.com`, for control, Grafana, telemetry,
  and edge-control names;
- a platform/customer zone, such as `example.net`, for nameservers, delegated
  customer zones, and proxied hostnames.

Keep the operator zone at your existing DNS provider. Do not delegate it to
CDNFoundry: you need those names to reach and repair CDNFoundry itself.

## 1. Record the installation plan

Before touching a host, record:

- the exact release tag or 40-character commit SHA;
- hostnames, public and private IPv4/IPv6 addresses, NAT mappings, and failure
  domains;
- `control.ops.example.com`, `edge-control.ops.example.com`, `telemetry.ops.example.com`,
  and `grafana.ops.example.com`;
- `ns1.example.net` and `ns2.example.net` plus registrar glue addresses;
- the advertised-to-local address map for each edge;
- firewall sources for edge control, DNS zone transfers (port 5300), telemetry
  ingestion, operational logs, gateway metrics, and administration;
- backup repository, retention, restoration owner, and recovery location for
  application keys and private PKI.

A public address assigned directly to an edge host needs no
`EDGE_GATEWAY_ADDRESS_MAP`. Behind NAT, a firewall, a router, or a layer-4 load
balancer, map each advertised address one-to-one to a distinct private listener.
The private side of every map entry must exist on that edge host. Do not use a
wildcard or loopback address.

## 2. Prepare every host

Install a supported Linux distribution, Docker Engine, Docker Compose v2, Git,
OpenSSL, curl, and DNS tools such as `dig`. Turn on clock synchronization and a
host firewall. Allow outbound HTTPS for image pulls, ACME (the protocol that
issues certificates), MMDB updates (the GeoIP database), and origin access.

Check out the same immutable revision on all three hosts:

```bash
git clone https://github.com/vaheed/CDNFoundry.git /opt/cdnfoundry
cd /opt/cdnfoundry
git checkout v2.0.0
git rev-parse --verify HEAD
docker version
docker compose version
```

Expect `git rev-parse` to print one 40-character commit. Replace `v2.0.0` with
the release you chose. If the GitHub container registry asks for a login, use a
read-only package token with `docker login ghcr.io`.

Create protected configuration directories on each host:

```bash
sudo install -d -m 0750 /etc/cdnfoundry
sudo install -d -m 0700 /etc/cdnfoundry/secrets
sudo install -d -m 0700 /etc/cdnfoundry/pki
cp .env.prod.example .env.prod
chmod 0600 .env.prod
```

Do not commit `.env.prod`, secret files, private keys, edge bootstrap tokens, or
database dumps.

## 3. Generate independent secrets

Generate every secret independently. Run these commands in a protected shell
and do not paste their output into tickets or chat:

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
- `CLICKHOUSE_PASSWORD`, `CLICKHOUSE_INGEST_PASSWORD`, `CLICKHOUSE_READER_PASSWORD`,
  and `CLICKHOUSE_ZONES_PASSWORD`;
- `GRAFANA_ADMIN_PASSWORD`;
- `GRAFANA_CLICKHOUSE_PASSWORD`;
- `GRAFANA_POSTGRES_PASSWORD`;
- each edge host's `EDGE_STATUS_TOKEN`.

`pdns-primary` (control host) and `pdns-auth` (each DNS host) start through
`docker/pdns/production-start.sh`. The committed PowerDNS files hold development
credentials. At start, the wrapper writes the real `PDNS_API_KEY` (and, for the
primary, `PDNS_DB_PASSWORD`) into a private copy of the configuration. It refuses
to start, with exit code 78, if a value is missing or still the development
value. A DNS host creates its SQLite store on first start. Fleet bundles already
carry rendered credentials, which the wrapper keeps.

Create the metrics token without printing it:

```bash
umask 077
openssl rand -hex 32 | sudo tee /etc/cdnfoundry/secrets/metrics-token >/dev/null
sudo chmod 0600 /etc/cdnfoundry/secrets/metrics-token
```

Create the edge proxy secret the same way. The file is an nginx include that
edge-control uses and PHP also reads:

```bash
umask 077
printf 'fastcgi_param HTTP_X_EDGE_PROXY_SECRET "%s";\n' "$(openssl rand -hex 32)" \
  | sudo tee /etc/cdnfoundry/secrets/edge-proxy-secret.conf >/dev/null
sudo chown 0:82 /etc/cdnfoundry/secrets/edge-proxy-secret.conf
sudo chmod 0640 /etc/cdnfoundry/secrets/edge-proxy-secret.conf
```

Set `EDGE_PROXY_SECRET_FILE` to that path in `.env.prod`.

Use the same `APP_KEY`, artifact signing key, database credentials, metrics
token, and telemetry credentials on every host that needs them. Use a different
edge status token on each edge. If you turn backups off, leave `RESTIC_REPOSITORY`
and its credentials empty and set `RESTIC_PASSWORD_FILE=/dev/null`. If you turn
them on, create a separate mode-`0600` Restic password file and keep the
encrypted repository off the host.

## 4. Create the private PKI manually

Do the CA work on a protected administration host. This is a minimal OpenSSL
procedure. Replace every example hostname before you run it. The
[certificates page](certificates.md) explains what each file is for.

First create the edge-identity CA. It signs agent client certificates after
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

Then create a separate server CA:

```bash
openssl ecparam -name prime256v1 -genkey -noout -out edge-server-ca.key
openssl req -x509 -new -sha256 -days 3650 \
  -key edge-server-ca.key -out edge-server-ca.crt \
  -subj '/CN=CDNFoundry private server CA' \
  -addext 'basicConstraints=critical,CA:TRUE' \
  -addext 'keyUsage=critical,keyCertSign,cRLSign'
```

For each private server certificate, create a key and a certificate signing
request (CSR), then sign it with the server CA. This example creates the
edge-control certificate. Its name must be the hostname in `EDGE_CONTROL_URL`:

```bash
openssl ecparam -name prime256v1 -genkey -noout \
  -out edge-control-server.key
openssl req -new -sha256 -key edge-control-server.key \
  -out edge-control-server.csr \
  -subj '/CN=edge-control.ops.example.com' \
  -addext 'subjectAltName=DNS:edge-control.ops.example.com' \
  -addext 'extendedKeyUsage=serverAuth'
openssl x509 -req -sha256 -days 825 \
  -in edge-control-server.csr \
  -CA edge-server-ca.crt -CAkey edge-server-ca.key -CAcreateserial \
  -copy_extensions copy -out edge-control-server.crt
```

Repeat the same three commands for the edge runtime certificate. Use the file
names `edge-runtime.key`, `edge-runtime.csr`, and `edge-runtime.crt`, and a
subject alternative name (SAN) that is a bootstrap hostname you own for the edge
deployment.

Check both certificates, then delete the CSRs:

```bash
openssl verify -CAfile edge-server-ca.crt \
  edge-control-server.crt edge-runtime.crt
openssl x509 -in edge-control-server.crt -noout -subject -issuer -dates \
  -ext subjectAltName
rm -f edge-control-server.csr edge-runtime.csr
```

Expect `edge-control-server.crt: OK` and `edge-runtime.crt: OK`, then the
subject, issuer, validity dates, and the `edge-control.ops.example.com` name.
Copy the files you distribute below into `/etc/cdnfoundry/pki` on each host.

Distribute material as follows:

| Host | Files |
| --- | --- |
| control | identity CA certificate and key, server CA certificate, edge-control certificate and key |
| each edge | server CA certificate, edge-runtime certificate and key |
| offline recovery custody | both CA keys, both CA certificates, all server keys/certificates |

Never put `edge-server-ca.key` or `edge-identity-ca.key` on an edge. On the
control host, the PHP-FPM process in the shipped image must be able to read the
identity CA key:

```bash
sudo chown root:82 /etc/cdnfoundry/pki/edge-identity-ca.key
sudo chmod 0640 /etc/cdnfoundry/pki/edge-identity-ca.key
sudo chmod 0600 /etc/cdnfoundry/pki/*-server.key
```

Keep all other private keys at mode `0600`. Store the CA keys in encrypted,
off-host recovery storage.

## 5. Configure `.env.prod` on every host

Edit the copied template. Do not write a shortened file. Compose reads the whole
model, including required values for profiles you do not start. So every
`.env.prod` needs a valid value for each required key. A file path only has to
exist on a host that starts the service that uses it.

### Shared control values

Set the same release and the same installation-wide secrets on all hosts:

```dotenv
CDNF_RELEASE=v2.0.0
CDNF_CORE_IMAGE=ghcr.io/vaheed/cdnfoundry-core@sha256:REPLACE_FROM_RELEASE_MANIFEST
CDNF_WEB_IMAGE=ghcr.io/vaheed/cdnfoundry-web@sha256:REPLACE_FROM_RELEASE_MANIFEST
# Set all seven CDNF_*_IMAGE entries from release-manifest.json in the same way.
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

`CDNF_RELEASE` records which release you installed. Compose pulls the images
named in the separate `CDNF_*_IMAGE` keys. In production, set those seven keys to
the verified `@sha256` references from `release-manifest.json`. Do not build the
references from tags yourself.

Set all database, Valkey, PowerDNS, ClickHouse, Grafana, ACME, and backup values
in the template, and give each credential its own value. This topology uses the
control host's own `control-db` and `redis`. Leave `DB_HOST=control-db` and
`REDIS_HOST=redis`, and leave the URL fields empty.

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
```

Set `EDGE_PUBLIC_IPV4_ALLOWLIST` and `EDGE_PUBLIC_IPV6_ALLOWLIST` to the exact
edge egress addresses that may send telemetry. Set `LOG_SOURCE_IPV4_ALLOWLIST`
and `LOG_SOURCE_IPV6_ALLOWLIST` to the exact node addresses that may push logs.
An empty allowlist blocks that remote path.

### DNS and edge host

Give each host its address settings, and give its agent the local DNS node's
API:

```dotenv
DNS_BIND_V4=0.0.0.0
HOST_BIND_IPV4=0.0.0.0
EDGE_DNS_API_URL=http://pdns-auth:8081
EDGE_DNS_API_KEY=<this host's PDNS_API_KEY>
EDGE_CONTROL_CA_CERTIFICATE=/etc/cdnfoundry/pki/edge-server-ca.crt
EDGE_RUNTIME_TLS_CERTIFICATE=/etc/cdnfoundry/pki/edge-runtime.crt
EDGE_RUNTIME_TLS_PRIVATE_KEY=/dev/shm/cdnfoundry/edge-runtime.key
EDGE_STATE_ENCRYPTION_KEY_FILE=/dev/shm/cdnfoundry/edge-state-encryption.key
EDGE_GATEWAY_ADDRESS_MAP={}
EDGE_GATEWAY_REQUIRE_ADDRESS_MAP=false
EDGE_ID=
EDGE_BOOTSTRAP_TOKEN=
```

This example is a direct-public host. An advertised address that sits on a local
interface binds directly and needs no map. Behind one-to-one NAT, add the
private listener, for example
`EDGE_GATEWAY_ADDRESS_MAP={"198.51.100.40":"10.20.0.40"}`. Never put `0.0.0.0` or
`::` in this map. Keep JSON values in `.env.prod` on one line.

Also set `CONTROL_PUBLIC_IPV4_ALLOWLIST` and `CONTROL_PUBLIC_IPV6_ALLOWLIST` to
the exact source address of the control worker. `pop-2` needs its own API
hostname, certificate, address map, status token, log identity, edge UUID, and
bootstrap token.

Leave IPv6 out until routing, firewalling, DNS, and outside reachability all
work. Fleet dual-stack bundles publish IPv6 next to IPv4. With a hand-written
environment, publish IPv6 only after you test it end to end. Setting
`HOST_BIND_IPV6` alone does not give you IPv6 service.

## 6. Validate and pull the images

Run these on every host, from `/opt/cdnfoundry`:

```bash
docker compose --env-file .env.prod -f compose.prod.yml config --quiet
docker compose --env-file .env.prod -f compose.prod.yml config --profiles
```

Every service belongs to a profile, so `pull` needs the profiles that host runs. A `pull`
without `--profile` pulls nothing and prints nothing. On the control host:

```bash
docker compose --env-file .env.prod -f compose.prod.yml \
  --profile control --profile tools pull
```

On a DNS and edge host, use `--profile dns --profile edge --profile tools`. Add
`--profile telemetry` or `--profile logs` on a host that runs them. Docker Hub
images such as PostgreSQL and Valkey come from the same command. If the host
cannot reach Docker Hub, the pull fails and names the image it could not fetch.

The checked-in template always validates. CI runs this block:

```sh test
docker compose --env-file .env.prod.example -f compose.prod.yml config --quiet
```

To review the full rendered model, print it to your screen only. It contains
secrets, so do not save it in a shared place:

```bash
docker compose --env-file .env.prod -f compose.prod.yml config
```

Check that every image uses your chosen release and that every bind-mounted file
resolves to the absolute path you intend. Expect `config --quiet` to print
nothing and exit with code 0. It checks Compose structure and variable
substitution only. It does not check firewalls, file contents, certificate
chains, or reachability.

## 7. Start the control plane in dependency order

Start the databases and the MMDB updater first, and wait until they are healthy:

```bash
docker compose --env-file .env.prod -f compose.prod.yml \
  --profile control up -d --wait --wait-timeout 180 \
  control-db pdns-db redis mmdb-updater
```

Expect Compose to return when all four services report healthy.

Run the migrations yourself. Starting a container never migrates a database.
`migrate` updates the application database. `pdns-migrate` updates the PowerDNS
primary's database (`pdns-db`), which also runs on the control host:

```bash
docker compose --env-file .env.prod -f compose.prod.yml \
  --profile tools run --rm migrate
docker compose --env-file .env.prod -f compose.prod.yml \
  --profile tools run --rm pdns-migrate
```

The initial PowerDNS schema is mounted into PostgreSQL's init directory, so it
applies only to a new `pdns-db` volume. `pdns-migrate` applies later runtime
migrations and is safe to run again. Never delete the volume to force a fresh
initialization.

Start the control services:

```bash
docker compose --env-file .env.prod -f compose.prod.yml \
  --profile control up -d
docker compose --env-file .env.prod -f compose.prod.yml \
  --profile control ps
```

Create the first administrator. The command asks for the password, so it does not
enter your shell history:

```bash
docker compose --env-file .env.prod -f compose.prod.yml \
  --profile control run --rm core php artisan cdnf:admin:create \
  --name='Operations Administrator' --email='admin@example.com'
```

Check the control plane from the host and from outside:

```bash
curl --fail http://127.0.0.1:8080/api/health
curl --fail http://127.0.0.1:8080/api/ready
curl --fail https://control.ops.example.com/api/health
```

Expect each command to succeed. If it does not, look at one service at a time:

```bash
docker compose --env-file .env.prod -f compose.prod.yml ps
docker compose --env-file .env.prod -f compose.prod.yml logs --tail=200 core
docker compose --env-file .env.prod -f compose.prod.yml logs --tail=200 horizon
```

## 8. Start authoritative DNS on both PoPs

Each DNS host runs DNSdist (the public DNS endpoint), a PowerDNS secondary, and
the MMDB updater. Its PowerDNS keeps zones in a local SQLite store that the
primary can rebuild, so a DNS host has no database to migrate. On `pop-1`:

```bash
docker compose --env-file .env.prod -f compose.prod.yml \
  --profile dns up -d --wait --wait-timeout 180
docker compose --env-file .env.prod -f compose.prod.yml \
  --profile dns ps
```

Expect `pdns-auth`, `dnsdist`, and `mmdb-updater` to be healthy. Repeat on `pop-2`.

The node holds no zones yet. It receives them after you register the primary
(step 9) and start the edge agent (step 10). DNS answers appear once a zone
exists.

Keep PowerDNS port 8081 and the primary's PostgreSQL port private on every host.
The only PowerDNS port that leaves the control host is the transfer port 5300.
It accepts only TSIG-signed requests (requests signed with a shared key). Also
restrict it by firewall to the DNS node addresses.

## 9. Configure desired state before edge enrollment

Sign in at `https://control.ops.example.com/admin` and use this order:

<div class="steps">

1. In **Infrastructure → DNS primary**, create the one DNS primary, disabled.
   Use `http://pdns-primary:8081`, the control host's `PDNS_API_KEY`, and the
   control host's public address with port 5300 as the transfer endpoint. Test
   it, then enable it. See [DNS nodes](../operations/dns-nodes.md).
2. In **Infrastructure → System DNS identity**, set the platform domain, the
   `ns1` and `ns2` nameservers with their glue addresses (optional IPv6 too), and
   the SOA and TTL values. Apply only after the primary is healthy. Then wait
   until the platform deployment acknowledges the revision.
3. Create a test domain and a DNS-only A or AAAA record. Wait until the primary
   and every DNS node acknowledge the revision. Check both authoritative servers
   over UDP and TCP. Then create the glue records at your registrar and change
   the delegation.
4. In **Infrastructure → Edges**, create `pop-1` and `pop-2`. Save each edge UUID
   and one-time bootstrap token when the modal shows them. You cannot reopen it.
5. In **Infrastructure → Service pools**, check that the shared and quarantine
   pools exist. Assign each edge's cell slots to a pool. Then create the public
   service endpoints on the edge (its **Pool endpoints** tab). Each endpoint
   address must sit on the host, or match an `EDGE_GATEWAY_ADDRESS_MAP` entry.

</div>

An enrolled edge with no domains is healthy: it activates an empty generation.
Assign a cell before you create its Geo-Unicast endpoint. You do not need a
placeholder customer domain.

Never put an API bearer token in `.env.prod`. If you script this setup through
the API, send an `Idempotency-Key` header on every change. Each long change
returns `202 Accepted` with an operation ID; poll that operation until it ends.

## 10. Enroll and start each edge

On each PoP, put only that host's UUID and one-time token in `.env.prod`:

```dotenv
EDGE_ID=00000000-0000-0000-0000-000000000000
EDGE_BOOTSTRAP_TOKEN=REPLACE_WITH_ONE_TIME_TOKEN
```

Start the edge profile. It runs the eight cell slots, the gateway, the agent,
Vector, and the MMDB updater:

```bash
docker compose --env-file .env.prod -f compose.prod.yml \
  --profile edge up -d
docker compose --env-file .env.prod -f compose.prod.yml \
  --profile edge ps
docker compose --env-file .env.prod -f compose.prod.yml \
  logs --tail=200 edge-agent edge-gateway
```

Wait until the edge page in the administrator panel shows a registered identity,
a fresh heartbeat, ready assigned cells, and the expected runtime revision. At
that point enrollment is done. The token is spent and cannot enroll again. Blank
its value in `.env.prod`. You do not need to restart: the empty value applies the
next time the agent is recreated. To clear it from the running container now,
recreate only the agent:

```bash
docker compose --env-file .env.prod -f compose.prod.yml \
  --profile edge up -d --force-recreate edge-agent
```

Do not remove the `edge-agent-state` volume. It holds the enrolled client
identity. Do not copy it to another edge. If you lose it, choose **Rotate
identity** on the edge in the administrator panel and enroll again with the new
token (see [certificates](certificates.md#rotation)).

## 11. Enable telemetry and operational logs

Telemetry is not in the request path, but you should run and watch it. On a host
that runs both control and telemetry, first check that the Grafana PostgreSQL
settings point at the local control database. Then start telemetry:

```bash
docker compose --env-file .env.prod -f compose.prod.yml \
  --profile telemetry up -d
docker compose --env-file .env.prod -f compose.prod.yml \
  --profile telemetry ps
```

Run one log collector per host. Give each host its own `LOG_HOST`, `LOG_ROLE`,
and `LOG_COLLECTOR_ID` in `.env.prod`. Set the shared Loki bearer token as
`LOG_AUTH_TOKEN`. Compose passes it only to the collector. The telemetry Caddy
gateway checks the same token as well as the source-IP allowlist:

```bash
docker compose --env-file .env.prod -f compose.prod.yml \
  --profile logs up -d log-collector
```

Restrict telemetry port 8444 to the configured sources. Keep ClickHouse, Loki,
Prometheus, Alertmanager, Vector, PostgreSQL, Valkey, and container metrics
private. Before you rely on alerts, open Grafana and Prometheus and check that
every datasource and target is up. The ClickHouse schema is applied separately;
see the upgrade steps below.

## 12. Delegate and qualify a real test domain

Delegate a real test domain only after both DNS nodes and both edge hosts are
healthy. Run these checks, replacing `EDGE_1_IP` and `EDGE_2_IP`:

```bash
dig +short A control.ops.example.com @1.1.1.1
dig +tcp SOA example.net @ns1.example.net
dig +tcp SOA example.net @ns2.example.net
curl --fail --resolve www.example.net:443:EDGE_1_IP \
  https://www.example.net/
curl --fail --resolve www.example.net:443:EDGE_2_IP \
  https://www.example.net/
```

Each `dig` should return an SOA record, and each `curl` should return the
origin's page. Then test:

- DNS over UDP and TCP;
- origin safety, IPv4, and IPv6 if you enabled it;
- the HTTP-to-HTTPS redirect and managed certificate issuance;
- cache miss and hit, URL purge, and full purge;
- security and quarantine behavior, telemetry, and alerts;
- a restart of each host, and a restore from an off-host backup.

A telemetry failure must not stop DNS or HTTP serving. If it does, treat that as a
fault to fix before go-live.

Keep a record of the release, image digests, host inventory, environment-file
checksums (not contents), public certificate fingerprints, private CA
fingerprints, operation IDs, migration results, test evidence, and known
deviations.

## Routine operations

### Inspect and restart

```bash
docker compose --env-file .env.prod -f compose.prod.yml ps
docker compose --env-file .env.prod -f compose.prod.yml logs --tail=200 SERVICE
docker compose --env-file .env.prod -f compose.prod.yml restart SERVICE
```

Restart one DNS or edge host at a time and check it before you continue. Use
`docker compose stop` for planned stops. Never run `docker compose down -v`: it
deletes the volumes.

### Upgrade

1. Back up PostgreSQL, `APP_KEY`, `EDGE_ARTIFACT_SIGNING_KEY`, the private PKI,
   TLS material, and the Restic key. Check that you can restore the backup
   before you change anything.
2. Check out the target revision on every host. Set the same `CDNF_RELEASE` and
   the new `CDNF_*_IMAGE` digests in every `.env.prod`.
3. Run `config --quiet` and `pull` on all hosts.
4. On the control host, run the `migrate` and `pdns-migrate` services before you
   recreate the control services. Recreate Horizon through Compose so it
   restarts cleanly.
5. Replace and check one DNS host at a time. DNS hosts need no migration.
6. Before you replace an edge, put the bootstrap listener key and a stable 32-byte
   recovery key on the host's tmpfs (mode `0400`, readable by UID 10101). Take
   them from your external secret store. Set `EDGE_RUNTIME_TLS_PRIVATE_KEY` and
   `EDGE_STATE_ENCRYPTION_KEY_FILE` to those absolute paths. Replace one edge at
   a time. Keep the `edge-agent-state`, gateway state, and cache volumes.
   `edge-runtime-memory` is tmpfs: the edge restores it from its encrypted
   snapshot or rebuilds it from control desired state. Wait for the new runtime
   to be acknowledged before the next edge. An older deployment that still uses
   the disk-backed `edge-state` volume needs a migration while connected to the
   control plane. Keep the old serving cell active until the new cell is ready.
   Then retire the old plaintext volume under your storage policy.
7. Upgrade telemetry last and run `make prod-clickhouse-migrate`, or the
   equivalent Compose command:

   ```bash
   docker compose --env-file .env.prod --profile telemetry --profile tools \
     run --rm clickhouse-migrate
   ```

   It applies each file in `docker/clickhouse/migrations/` once, in name order. It
   records each file with its checksum in `cdnf.schema_migrations` and refuses to
   run if an applied file was edited. The Fleet `start.sh` runs it on the
   telemetry host.

Control host commands for steps 3 and 4:

```bash
docker compose --env-file .env.prod -f compose.prod.yml \
  --profile control --profile tools pull
docker compose --env-file .env.prod -f compose.prod.yml \
  --profile tools run --rm migrate
docker compose --env-file .env.prod -f compose.prod.yml \
  --profile tools run --rm pdns-migrate
docker compose --env-file .env.prod -f compose.prod.yml \
  --profile control up -d --remove-orphans
```

Roll back application images only when the release notes say the database change
is backward compatible. Restore data only through a planned recovery. Do not
improvise a schema downgrade. See also the [upgrade page](upgrade.md).

### Back up and recover

To recover, you need at least:

- the control PostgreSQL backup;
- `APP_KEY` and `EDGE_ARTIFACT_SIGNING_KEY`;
- identity and server CA keys/certificates;
- public and private TLS material;
- `.env.prod` values or an equivalent secrets record;
- the Restic password and narrowly scoped object-storage credentials.

Named Compose volumes survive `stop`, `restart`, and a plain `down`. Do not
delete them during tests or upgrades. PowerDNS and edge runtime state can be
rebuilt from desired state, but keeping them makes recovery faster. See
[backup and recovery](../operations/backup-and-recovery.md).

## Failure guide

| Symptom | Check first | Safe response |
| --- | --- | --- |
| Compose rejects interpolation | Required values in `.env.prod`, JSON quoting, absolute paths | Correct the environment; rerun `config --quiet` |
| `mmdb-updater` is unhealthy | Provider reachability, target filename, checksum, volume | Preserve the last valid MMDB; fix download settings |
| Migration cannot connect | Dependency health, password, Docker network, disk | Leave old services/state intact; repair dependency and rerun the idempotent migration |
| A DNS node serves no zones | The `dns` field in the agent heartbeat, `docker compose logs pdns-auth edge-agent`, and whether the node can reach the primary's port 5300 | Fix the firewall or the transfer endpoint. The node fills itself once it can pull |
| Edge enrollment fails | URL SAN, server CA, UUID/token, clock, port 8443 | Fix trust/reachability; rotate a consumed token instead of reusing it |
| Empty edge repeats `generation revision must be positive` | Edge-agent release predates empty-bootstrap support | Deploy a release containing the empty-bootstrap fix; do not create a placeholder domain |
| New gateway exits because candidate and `last-valid.json` are absent | Gateway release predates clean-start waiting support | Deploy the clean-start fix; the gateway must remain not-ready until the agent publishes its first candidate |
| First endpoint remains unacknowledged on an empty edge | Agent generation log, same-edge pool assignment, exact address-map coverage, gateway log | Assign a non-drained cell to the same pool, confirm its empty runtime, and verify gateway activation |
| Gateway reports `bind ...:80` or `bind ...:443: permission denied` with generated Compose | Exact gateway image digest, non-root UID, binary file capability, Compose `cap_drop`/`cap_add` | Deploy a corrected immutable gateway image that carries `cap_net_bind_service`; never run it privileged or add broader capabilities |
| Gateway refuses endpoints | Local IP assignment, and `EDGE_GATEWAY_ADDRESS_MAP` for translated addresses | Assign a direct public address locally, or add exact one-to-one private mappings behind NAT |
| New runtime is rejected | Agent/cell logs, checksum/signature, status token, bounds | Keep the previous valid generation active and repair desired state |
| Telemetry is unavailable | ClickHouse/Vector/Loki health, allowlists, buffers | Restore telemetry independently; do not stop serving traffic |

For deeper diagnosis, use [Runbooks](../operations/runbooks.md),
[DNS troubleshooting](../troubleshooting/dns.md), and
[Edge and origin troubleshooting](../troubleshooting/edge-and-origin.md).

## Done when

Your installation is complete when all of these are true, and you have written
down the evidence for each:

- Control, two DNS nodes, and two enrolled edges are running, with cells assigned
  and gateway mappings in place. Telemetry is running if you chose it.
- Your records match what is deployed: inventory, firewall rules, DNS and glue
  records, secret custody, backup and restore, and upgrade and rollback steps.
- Health checks, migrations, DNS over UDP and TCP, edge traffic, cache, TLS,
  failure behavior, restarts, and a restore all passed on the real hosts.
