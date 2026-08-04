---
title: Production quick start
description: Deploy a small three-host CDNFoundry fleet first, then optionally add monitoring and centralized logs.
keywords: private CDN deployment, production CDN, authoritative DNS, OpenResty CDN, PowerDNS, Grafana monitoring
---

# Production quick start

This guide deploys the smallest practical CDNFoundry production fleet:

- one control host, identified as `CONTROL`;
- two combined DNS and edge hosts, `EDGE_1` and `EDGE_2`, in different failure domains;
- one exact CDNFoundry release on every host;
- optional monitoring and centralized logs, enabled only after serving works.

Follow the numbered steps in order. Commands say exactly which host they run
on. Do not enable the optional `telemetry` or `logs` profiles during the base
installation.

::: danger Preserve production state
Never run `docker compose down -v`, delete named volumes, regenerate `APP_KEY`,
or replace CA keys during an upgrade. PostgreSQL, application keys, CA keys,
and externally stored TLS material are part of the recovery set.
:::

::: tip Replace the examples
Replace every uppercase placeholder and documentation IP before running a
command. `198.51.100.0/24` is reserved for documentation and cannot carry real
Internet traffic.
:::

## Resulting topology

| Host | Required services | Public listeners |
| --- | --- | --- |
| `CONTROL` | Laravel, web, Horizon, Scheduler, PostgreSQL, Valkey, edge-control, Caddy | TCP `80`, `443`, `8443`, `8444`; UDP `443` |
| `EDGE_1` | DNSdist, PowerDNS, DNS API, OpenResty cells, edge agent, gateway, traffic Vector | TCP/UDP `53`; TCP `80`, `443`, `8444` |
| `EDGE_2` | Same as `EDGE_1` in another provider, rack, or failure domain | TCP/UDP `53`; TCP `80`, `443`, `8444` |

The optional final step adds ClickHouse, Prometheus, Alertmanager, Grafana,
Loki, node-exporter, and one operational-log collector per host.

`CONTROL` is a single management failure domain in this minimum
topology. If it is offline, existing DNS and HTTP traffic continue using the
last valid runtime state. Management, deployments, new certificates, and
analytics pause until it recovers.

```mermaid
flowchart LR
    Admin["Administrator"] -->|"HTTPS 443"| Control["CONTROL"]
    Control --> Desired[("PostgreSQL desired state")]
    Agent1["EDGE_1 agent"] -->|"outbound mTLS 8443"| Control
    Agent2["EDGE_2 agent"] -->|"outbound mTLS 8443"| Control
    Control -->|"HTTPS 8444"| DNS1["EDGE_1 DNS API"]
    Control -->|"HTTPS 8444"| DNS2["EDGE_2 DNS API"]
    Resolver["DNS resolver"] -->|"UDP/TCP 53"| DNSdist["DNSdist"]
    Visitor["Visitor"] -->|"HTTP/HTTPS"| Cell["OpenResty cell"]
    Cell -->|"validated origin"| Origin["Customer origin"]
```

Laravel is never in the DNS or HTTP request path.

## Example values

Use your real values consistently on every host:

| Purpose | Example |
| --- | --- |
| Independent operator DNS zone | `ops.example.com` |
| CDNFoundry platform zone | `example.net` |
| `CONTROL` public/NAT IPv4 | `198.51.100.10` |
| `EDGE_1` public/NAT IPv4 | `198.51.100.20` |
| `EDGE_2` public/NAT IPv4 | `198.51.100.30` |
| `EDGE_1` advertised shared/quarantine service IPv4 | `198.51.100.120`, `198.51.100.121` |
| `EDGE_1` assigned local service IPv4 | `10.20.1.120`, `10.20.1.121` |
| `EDGE_2` advertised shared/quarantine service IPv4 | `198.51.100.130`, `198.51.100.131` |
| `EDGE_2` assigned local service IPv4 | `10.20.2.130`, `10.20.2.131` |
| Local IPv4 bind on every host | `0.0.0.0` or an assigned private address |
| Exact release | `v0.9.4` |
| Installation directory | `/opt/cdnfoundry` |
| Protected PKI directory | `/etc/cdnfoundry/pki` |

Keep `control.ops.example.com`, `edge-control.ops.example.com`,
`telemetry.ops.example.com`, and every `dns-api-N.ops.example.com` at an
independent DNS provider. Do not put management names inside the CDNFoundry
platform zone.

The public/NAT addresses above are advertised in DNS and used in peer firewall
allowlists. They are not Docker bind addresses. `HOST_BIND_IPV4` and
`DNS_BIND_V4` must name an address that exists locally; the generator defaults
both to `0.0.0.0`, which supports hosts behind DNAT, a provider firewall, or a
load balancer. Configure the external device to forward only the listed ports.
Edge customer traffic is stricter: each advertised pool service address maps
one-to-one to a distinct address actually assigned to the host. The gateway
binds only those local addresses.

## Step 1: prepare hosts and firewall rules

Prepare three supported Linux hosts with:

- Docker Engine and the Docker Compose plugin;
- Git, OpenSSL, curl, and CA certificates;
- accurate system time;
- an operator firewall and a provider firewall;
- optional S3-compatible object storage for encrypted Restic backups;
- console access in case a firewall rule is wrong.

A reasonable starting size is:

| Role | CPU | Memory | Disk |
| --- | ---: | ---: | ---: |
| Control | 4 vCPU | 8 GiB | 100 GiB SSD |
| `EDGE_1` and `EDGE_2`, each | 4 vCPU | 6 GiB | 50 GiB SSD plus cache capacity |

Allow only these inbound connections:

| Destination | Allowed source |
| --- | --- |
| Control TCP `22` | trusted administrator networks |
| Control TCP `80`, `443`; UDP `443` | public |
| Control TCP `8443`, `8444` | the two edge source addresses seen after NAT |
| Edge TCP/UDP `53` | public |
| Edge TCP `80`, `443` on advertised service addresses | public; forward one-to-one to mapped local service addresses |
| Edge TCP `8444` | the control source address seen after NAT only |
| PostgreSQL, Valkey, ClickHouse, PowerDNS API, Prometheus, Loki, Grafana `3000` | never public |

Docker-published ports can bypass ordinary UFW rules. Apply the same policy in
the provider firewall and the host `DOCKER-USER` chain. Keep outbound DNS and
HTTPS available for images, ACME, origins, GeoIP, telemetry, and backups.

## Step 2: create bootstrap DNS and registrar glue

At the independent provider for `ops.example.com`, create:

| Record | Value |
| --- | --- |
| `control.ops.example.com` A | control IPv4 |
| `edge-control.ops.example.com` A | control IPv4 |
| `telemetry.ops.example.com` A | control IPv4 |
| `dns-api-1.ops.example.com` A | `EDGE_1` IPv4 |
| `dns-api-2.ops.example.com` A | `EDGE_2` IPv4 |

Add AAAA records only when that host and its firewall are IPv6-ready.

At the registrar for `example.net`, register child nameserver glue:

| Child nameserver | Address |
| --- | --- |
| `ns1.example.net` | `EDGE_1` IPv4 and optional IPv6 |
| `ns2.example.net` | `EDGE_2` IPv4 and optional IPv6 |

Do not delegate `example.net` yet.

## Step 3: install the same exact release on every host

Run on `CONTROL`, `EDGE_1`, and `EDGE_2`:

```sh
sudo apt-get update
sudo DEBIAN_FRONTEND=noninteractive apt-get install -y \
  ca-certificates curl git openssl

docker version
docker compose version

export CDNF_RELEASE=v0.9.4
sudo install -d -m 0755 /opt/cdnfoundry
sudo git clone --branch "${CDNF_RELEASE}" --depth 1 \
  https://github.com/vaheed/CDNFoundry.git /opt/cdnfoundry
sudo chown -R "$(id -u):$(id -g)" /opt/cdnfoundry
cd /opt/cdnfoundry
git rev-parse HEAD
```

The final commit must match on all three hosts. Use an exact release tag or
40-character commit SHA; never deploy `latest` or a moving major/minor alias.
Authenticate to GHCR with a read-only package token if the images are private.

## Step 4: generate one private environment per host

The generator creates `.env.prod` with mode `0600` and refuses to overwrite an
existing file.

On `CONTROL`:

```sh
cd /opt/cdnfoundry
./scripts/generate-production-env.sh
stat -c '%a %n' .env.prod
```

Choose:

- role `control`;
- your operator and platform domains;
- the exact release from Step 3;
- the control public/NAT IPv4 advertised in DNS;
- local bind `0.0.0.0` or an assigned private control-host address;
- the control source address as the edge firewalls see it;
- the `EDGE_1` and `EDGE_2` source addresses as `CONTROL` sees them;
- one high-entropy shared telemetry password already stored in your password
  manager;
- whether to configure optional S3-compatible backups now.

The generator never uses the advertised public/NAT address as a listener bind.
Do not replace `HOST_BIND_IPV4=0.0.0.0` with an address that is owned only by an
external firewall, NAT gateway, or load balancer.

If you enable backups, the repository location is a Restic backend address—not
an encryption password. For example:

```dotenv
RESTIC_REPOSITORY=s3:https://object-storage.example/bucket/cdnfoundry-control
RESTIC_PASSWORD_FILE=/etc/cdnfoundry/secrets/restic-password
```

Use an existing bucket, a dedicated prefix, and backup-only S3 credentials that
cannot access unrelated objects. Restic encrypts repository contents using the
separate password file. Leaving the repository and credential fields empty
disables backups without preventing the control plane from starting. Other
Restic backends need deployment-specific credentials or mounts and are outside
the generator's S3 quick-start path.

Run the generator separately on `EDGE_1` and `EDGE_2`. Choose role `dns-edge`
and use a unique DNS API label:

- `dns-api-1` on `EDGE_1`;
- `dns-api-2` on `EDGE_2`.

When prompted, enter the shared telemetry password from the password manager. The
generator sets the remote ClickHouse and Loki endpoints, plus unique log host
and collector identities, so optional monitoring can be enabled later.

Verify every host:

```sh
cd /opt/cdnfoundry
test "$(stat -c '%a' .env.prod)" = 600
if grep -n 'replace-with' .env.prod; then
  echo 'Replace every value shown above before continuing.' >&2
  exit 1
fi
```

Never copy the whole control environment to an edge. Database passwords, API
keys, edge tokens, and agent identities have separate trust boundaries.

## Step 5: create secret files and internal certificates

Run once on `CONTROL`:

```sh
sudo install -d -m 0700 /etc/cdnfoundry/secrets
sudo install -d -m 0700 /etc/cdnfoundry/pki

sudo /opt/cdnfoundry/scripts/generate-production-certificates.sh \
  /etc/cdnfoundry/pki \
  edge-control.ops.example.com \
  proxy.example.net \
  dns-api-1.ops.example.com \
  dns-api-2.ops.example.com

sudo sh -c 'umask 077; openssl rand -base64 48 > /etc/cdnfoundry/secrets/metrics-token'
sudo chown root:82 /etc/cdnfoundry/pki/edge-identity-ca.key
sudo chmod 0640 /etc/cdnfoundry/pki/edge-identity-ca.key
```

If backups were enabled in Step 4, also create the configured Restic password
file. This password encrypts and unlocks the backup repository; losing it makes
the snapshots unrecoverable:

```sh
sudo sh -c 'umask 077; openssl rand -base64 48 > /etc/cdnfoundry/secrets/restic-password'
```

Store a protected recovery copy outside these hosts. Initialize and test the
repository after the control containers are healthy in Step 11.

Verify certificate names:

```sh
openssl x509 -in /etc/cdnfoundry/pki/edge-control-server.crt \
  -noout -subject -issuer -ext subjectAltName
openssl verify -CAfile /etc/cdnfoundry/pki/edge-server-ca.crt \
  /etc/cdnfoundry/pki/edge-control-server.crt \
  /etc/cdnfoundry/pki/edge-runtime.crt \
  /etc/cdnfoundry/pki/dns-api-1.crt \
  /etc/cdnfoundry/pki/dns-api-2.crt
```

Copy to `EDGE_1` and `EDGE_2` through separate protected channels:

- `edge-server-ca.crt`;
- `edge-runtime.crt` and `edge-runtime.key`;
- only that host's `dns-api-N.crt` and `dns-api-N.key`.

Certificates use mode `0644`; private keys use `0600`. Never copy
`edge-server-ca.key` or `edge-identity-ca.key` to an edge. See
[Internal certificates](certificates.md) for the full ownership matrix.

## Step 6: start the control plane

Run on `CONTROL`. Notice that only the required `control` profile is enabled.

```sh
cd /opt/cdnfoundry

docker compose --env-file .env.prod \
  -f compose.prod.yml \
  -f deploy/production/compose.control-host.yml \
  --profile control config --quiet

docker compose --env-file .env.prod \
  -f compose.prod.yml \
  -f deploy/production/compose.control-host.yml \
  --profile control pull

docker compose --env-file .env.prod \
  -f compose.prod.yml \
  -f deploy/production/compose.control-host.yml \
  --profile control up -d --wait --wait-timeout 120 control-db redis

docker compose --env-file .env.prod \
  -f compose.prod.yml \
  -f deploy/production/compose.control-host.yml \
  --profile tools run --rm migrate

docker compose --env-file .env.prod \
  -f compose.prod.yml \
  -f deploy/production/compose.control-host.yml \
  --profile control up -d

docker compose --env-file .env.prod \
  -f compose.prod.yml \
  -f deploy/production/compose.control-host.yml ps
```

The first `up` creates the persistent PostgreSQL and Valkey services and waits
for both health checks. The one-shot migration then connects to those already
healthy dependencies. Application startup never performs an implicit migration.

If `REDIS_PASSWORD` is changed after control containers have already been
created, recreate the control services so every container receives the same
value. A restart alone does not update container environment variables:

```sh
docker compose --env-file .env.prod \
  -f compose.prod.yml \
  -f deploy/production/compose.control-host.yml \
  --profile control up -d --force-recreate
```

Verify the control database, Valkey, workers, scheduler, web, edge-control, and
Caddy are running. Then check the public API:

```sh
curl -fsS https://control.ops.example.com/api/health
curl -fsS https://control.ops.example.com/api/ready
```

`health` proves process liveness. `ready` requires PostgreSQL and Valkey and
must return `ready` before continuing.

Create the first administrator:

```sh
docker compose --env-file .env.prod \
  -f compose.prod.yml \
  -f deploy/production/compose.control-host.yml \
  exec -u www-data core php artisan cdnf:admin:create \
  --name="CDN Operations" \
  --email="admin@example.com"
```

Enter the password only at the prompt. Sign in at
`https://control.ops.example.com/admin`.

## Step 7: start authoritative DNS on `EDGE_1` and `EDGE_2`

Run these commands independently on `EDGE_1` and `EDGE_2`:

```sh
cd /opt/cdnfoundry

docker compose --env-file .env.prod \
  -f compose.prod.yml \
  -f deploy/production/compose.dns-edge-host.yml \
  --profile dns --profile edge config --quiet

docker compose --env-file .env.prod \
  -f compose.prod.yml \
  -f deploy/production/compose.dns-edge-host.yml \
  --profile dns --profile edge pull

docker compose --env-file .env.prod \
  -f compose.prod.yml \
  -f deploy/production/compose.dns-edge-host.yml \
  --profile dns up -d --wait --wait-timeout 120 pdns-db

docker compose --env-file .env.prod \
  -f compose.prod.yml \
  -f deploy/production/compose.dns-edge-host.yml \
  --profile tools run --rm pdns-migrate

docker compose --env-file .env.prod \
  -f compose.prod.yml \
  -f deploy/production/compose.dns-edge-host.yml \
  --profile dns up -d

docker compose --env-file .env.prod \
  -f compose.prod.yml \
  -f deploy/production/compose.dns-edge-host.yml \
  ps pdns-db pdns-auth dnsdist dns-api
```

The first `up` creates the persistent PowerDNS PostgreSQL service and waits for
its health check. Only then does the separate runtime migration connect to it;
the remaining DNS services start after the migration succeeds.

From an external workstation, verify UDP and TCP on both hosts:

```sh
dig @198.51.100.20 example.net SOA
dig @198.51.100.20 example.net SOA +tcp
dig @198.51.100.30 example.net SOA
dig @198.51.100.30 example.net SOA +tcp
```

The zone does not exist yet, so an authoritative negative answer is acceptable.
A timeout, refusal, or response from an unrelated DNS server is not.

## Step 8: configure platform DNS

In **Control plane → System DNS identity**, enter:

| Field | Value |
| --- | --- |
| Platform domain | `example.net` |
| Proxy hostname | `proxy.example.net` |
| Nameserver 1 | `ns1.example.net`, `EDGE_1` IPv4, optional IPv6 |
| Nameserver 2 | `ns2.example.net`, `EDGE_2` IPv4, optional IPv6 |
| SOA primary | `ns1.example.net` |
| SOA mailbox | `hostmaster.example.net` |
| Refresh / retry / expire | `3600` / `600` / `1209600` |
| Minimum / default TTL | `300` / `300` |

Choose **Validate and preview**, review the normalized result, and apply the
exact confirmation token.

Create two disabled DNS clusters:

| Field | Cluster 1 | Cluster 2 |
| --- | --- | --- |
| API URL | `https://dns-api-1.ops.example.com:8444` | `https://dns-api-2.ops.example.com:8444` |
| API key | `EDGE_1` `PDNS_API_KEY` | `EDGE_2` `PDNS_API_KEY` |
| Server ID | `localhost` | `localhost` |
| Nameserver | `ns1.example.net` | `ns2.example.net` |

For each cluster:

1. save it disabled;
2. run the asynchronous connection test;
3. confirm TLS verification and a healthy result;
4. enable it;
5. reconcile system DNS identity;
6. wait for its candidate checksum to become active.

Verify the active zone externally:

```sh
dig @198.51.100.20 example.net SOA +tcp
dig @198.51.100.30 example.net NS
dig @198.51.100.20 ns1.example.net A
dig @198.51.100.30 ns2.example.net A
```

Only now delegate `example.net` to `ns1.example.net` and `ns2.example.net` at
the registrar.

## Step 9: map service addresses, then enroll `EDGE_1` and `EDGE_2`

In **Edge network → Edges**, create one edge per host and copy its UUID and
one-time bootstrap token. Each new installation already contains
`shared-default` and `quarantine-default` service pools. On `EDGE_1` and
`EDGE_2`, confirm `cell-01` is assigned to the shared pool and `cell-02` to the
quarantine pool, then create one **Pool endpoint** for each pool using its advertised service
address from the example table.

On each host, have the network operator assign the two corresponding local
service addresses. Configure the firewall, one-to-one DNAT, or layer-4 load
balancer so each advertised address forwards TCP `80` and `443` to exactly one
local address without terminating TLS. Verify that the local addresses exist;
do not add the advertised addresses to the host:

```sh
ip -brief address
```

Add the complete mapping and enrollment values to the matching `.env.prod`.
For `EDGE_1`, the example is:

```dotenv
EDGE_GATEWAY_ADDRESS_MAP={"198.51.100.120":"10.20.1.120","198.51.100.121":"10.20.1.121"}
EDGE_ID=replace-with-edge-uuid
EDGE_BOOTSTRAP_TOKEN=replace-with-one-time-token
```

Use `EDGE_2`'s advertised and local pairs on `EDGE_2`. IPv6 pairs use the same map.
Every advertised endpoint must be present, each local value must be distinct,
and both sides of a pair must use the same address family. Production rejects
an incomplete map and preserves the previous valid gateway configuration.

Start the edge runtime on that host:

```sh
docker compose --env-file .env.prod \
  -f compose.prod.yml \
  -f deploy/production/compose.dns-edge-host.yml \
  --profile edge up -d

docker compose --env-file .env.prod \
  -f compose.prod.yml \
  -f deploy/production/compose.dns-edge-host.yml \
  ps cell-01 cell-02 edge-agent edge-gateway vector mmdb-updater
```

The traffic Vector process starts with the edge profile. Until optional
telemetry is enabled, it retries into a bounded disk buffer and may eventually
drop old analytics events. This does not block or slow DNS and HTTP serving.

Wait until the panel shows:

- registered identity and a fresh heartbeat;
- ready shared and quarantine cells;
- listener-ready gateway status;
- an acknowledged active revision;
- bounded CPU, memory, disk, and connection capacity.

Remove the bootstrap token after registration and recreate only the agent:

```sh
sudo sed -i 's/^EDGE_BOOTSTRAP_TOKEN=.*/EDGE_BOOTSTRAP_TOKEN=/' \
  /opt/cdnfoundry/.env.prod

docker compose --env-file .env.prod \
  -f compose.prod.yml \
  -f deploy/production/compose.dns-edge-host.yml \
  --profile edge up -d --force-recreate edge-agent
```

Repeat this step on the other edge. Never clone an edge-agent identity volume.

## Step 10: add and test the first customer domain

Use this order:

1. create a domain user;
2. create and assign the domain;
3. ask the owner to delegate to `ns1.example.net` and `ns2.example.net`;
4. verify nameservers asynchronously;
5. activate the domain;
6. wait for both DNS clusters to acknowledge the revision;
7. add DNS-only A/AAAA records and test them;
8. add one proxied hostname with one explicit public origin;
9. wait for edge deployment and DNS-01 certificate completion.

From an external workstation:

```sh
dig +trace CUSTOMER_DOMAIN NS
dig @198.51.100.20 CUSTOMER_DOMAIN A
dig @198.51.100.30 CUSTOMER_DOMAIN A +tcp
curl --fail --head \
  --resolve CUSTOMER_DOMAIN:443:198.51.100.20 \
  https://CUSTOMER_DOMAIN/
curl --fail --head \
  --resolve CUSTOMER_DOMAIN:443:198.51.100.30 \
  https://CUSTOMER_DOMAIN/
```

Origin validation rejects loopback, link-local, multicast, metadata, internal
platform, edge-service, and proxy-loop destinations. Do not weaken this check
to make a private origin work.

## Step 11, optional: initialize and prove encrypted backups

The backup integration is optional and an empty `RESTIC_REPOSITORY` does not
block startup. Skipping it leaves the backup health component degraded and
means CDNFoundry has no built-in control-database recovery path. A tested
provider snapshot or another operator-owned recovery system may be used
instead.

When the S3-compatible Restic settings were enabled in Step 4 and the password
file was created in Step 5, initialize a new repository once from the healthy
control container. The shell maps CDNFoundry's backup-only variables to the
standard names expected by Restic without printing their values:

```sh
docker compose --env-file .env.prod \
  -f compose.prod.yml \
  -f deploy/production/compose.control-host.yml \
  exec core sh -eu -c '
    export AWS_ACCESS_KEY_ID="$BACKUP_ACCESS_KEY_ID"
    export AWS_SECRET_ACCESS_KEY="$BACKUP_SECRET_ACCESS_KEY"
    export AWS_DEFAULT_REGION="$BACKUP_DEFAULT_REGION"
    restic init
  '

docker compose --env-file .env.prod \
  -f compose.prod.yml \
  -f deploy/production/compose.control-host.yml \
  exec core php artisan cdnf:backups:create --wait
```

For an existing repository, replace `restic init` with `restic snapshots`.
Then verify the recorded snapshot remotely, restore it in an isolated
environment, and store `APP_KEY`, signing keys, CA keys, the Restic password,
backup credentials, and external TLS material in the protected recovery
system. Record the tested RPO and RTO. See
[Backup and recovery](../operations/backup-and-recovery.md).

The required serving checklist is:

- [ ] every host runs the same exact release;
- [ ] every Compose configuration renders successfully;
- [ ] Laravel and PowerDNS migrations completed explicitly;
- [ ] control `/api/health` and `/api/ready` succeed;
- [ ] both DNS servers answer UDP and TCP externally;
- [ ] registrar glue matches the active listener addresses;
- [ ] both DNS clusters have active system-zone revisions;
- [ ] `EDGE_1` and `EDGE_2` identities are registered and bootstrap tokens removed;
- [ ] shared cells and gateways are listener-ready;
- [ ] the test domain resolves through both nameservers;
- [ ] proxied HTTPS works through `EDGE_1` and `EDGE_2`;
- [ ] a failed runtime candidate preserves the previous valid state;
- [ ] the recovery choice is recorded; when built-in backups are enabled, an
      off-host snapshot and isolated restore are proven;
- [ ] firewall tests confirm private services are not public.

## Step 12, optional: enable monitoring and centralized logs

The CDN can serve without this step. Prometheus, Grafana, Loki, ClickHouse, and
operational log collectors are diagnostic systems: their failure must not stop
DNS, HTTP, queue processing, or runtime activation.

### 12.1 Start telemetry on `CONTROL`

The environment generator already created independent Grafana passwords,
ClickHouse credentials, Loki limits, and the source allowlists.

```sh
cd /opt/cdnfoundry

docker compose --env-file .env.prod \
  -f compose.prod.yml \
  -f deploy/production/compose.control-host.yml \
  --profile telemetry config --quiet

docker compose --env-file .env.prod \
  -f compose.prod.yml \
  -f deploy/production/compose.control-host.yml \
  --profile telemetry pull

docker compose --env-file .env.prod \
  -f compose.prod.yml \
  -f deploy/production/compose.control-host.yml \
  --profile telemetry up -d

docker compose --env-file .env.prod \
  -f compose.prod.yml \
  -f deploy/production/compose.control-host.yml \
  ps clickhouse prometheus alertmanager grafana loki node-exporter vector
```

### 12.2 Start exactly one operational-log collector per host

On `CONTROL`:

```sh
docker compose --env-file .env.prod \
  -f compose.prod.yml \
  -f deploy/production/compose.control-host.yml \
  --profile logs up -d log-collector
```

On `EDGE_1` and `EDGE_2`:

```sh
docker compose --env-file .env.prod \
  -f compose.prod.yml \
  -f deploy/production/compose.dns-edge-host.yml \
  --profile logs up -d log-collector
```

The Docker socket is a privileged read boundary. Only the collector receives a
read-only mount; never expose the socket over TCP or mount it into application
containers. Add `deploy/production/compose.host-journal.yml` only on hosts that
use persistent systemd journals and need kernel OOM, disk, and daemon events.

### 12.3 Configure Prometheus targets and Alertmanager

Populate deployment-owned copies of:

- `docker/prometheus/edge-targets.prod.yml` with private gateway metrics
  endpoints;
- `docker/prometheus/operational-log-targets.prod.yml` with each private
  `host:9599` collector endpoint.

Configure a real Alertmanager receiver. Keep metrics endpoints private and
require the metrics bearer-token file.

### 12.4 Open Grafana safely

Grafana binds to `127.0.0.1:3000`. For the first check, use an SSH tunnel from
an administrator workstation:

```sh
ssh -L 3000:127.0.0.1:3000 ADMIN_USER@control.ops.example.com
```

Open `http://127.0.0.1:3000`, sign in with `GRAFANA_ADMIN_USER` and the generated
`GRAFANA_ADMIN_PASSWORD`, and confirm exactly two dashboards exist. For ongoing
use, deploy an authenticated HTTPS reverse proxy or SSO gateway; never expose
port `3000` directly.

Verify:

- all four datasources report healthy;
- the System and Domain Command Centers load;
- HTTP and DNS access analytics reach ClickHouse;
- operational logs reach Loki without access-log duplication;
- the request tail shows client and origin status with a bounded refresh;
- stopping monitoring does not interrupt DNS or HTTP traffic.

See [Grafana](../operations/grafana.md),
[Monitoring](../operations/monitoring.md), and
[Operational logging](../operations/operational-logging.md).

## Daily status commands

On `CONTROL`:

```sh
docker compose --env-file .env.prod \
  -f compose.prod.yml \
  -f deploy/production/compose.control-host.yml ps

docker compose --env-file .env.prod \
  -f compose.prod.yml \
  -f deploy/production/compose.control-host.yml \
  logs --tail=200 core horizon scheduler caddy
```

On an edge:

```sh
docker compose --env-file .env.prod \
  -f compose.prod.yml \
  -f deploy/production/compose.dns-edge-host.yml ps

docker compose --env-file .env.prod \
  -f compose.prod.yml \
  -f deploy/production/compose.dns-edge-host.yml \
  logs --tail=200 dnsdist pdns-auth dns-api cell-01 cell-02 edge-agent edge-gateway
```

## Common startup failures

### Control certificate failure

Check the independent A/AAAA record, public TCP `80` and `443`, ACME contact,
Caddy logs, outbound HTTPS, and provider rate limits:

```sh
docker compose --env-file .env.prod \
  -f compose.prod.yml \
  -f deploy/production/compose.control-host.yml \
  logs --tail=200 caddy
```

### Core is unhealthy

```sh
docker compose --env-file .env.prod \
  -f compose.prod.yml \
  -f deploy/production/compose.control-host.yml \
  logs --tail=200 core
```

Verify migrations, secret-file paths, file permissions, PostgreSQL, Valkey,
and writable tmpfs/storage mounts. Do not make the production root filesystem
writable as a workaround.

### DNS reconciliation fails

Check the operation error code, control source IPv4, firewall counters,
`DNS_API_HOSTNAME`, certificate SAN, mounted CA, the edge's unique API key, and
`dns-api`/`pdns-auth` health. Never publish raw PowerDNS API port `8081`.

### Edge registration fails

Check edge UUID, one-time token state, server CA, system clock, outbound TCP
`8443`, identity volume, artifact signature, sequence, available disk, gateway,
and cell status. Do not delete the active runtime directory; it is the rollback
state.

### Optional monitoring is empty

Check `telemetry.ops.example.com`, source allowlists, edge `CLICKHOUSE_URL` and
`LOKI_ENDPOINT`, collector identities, Vector buffers, ClickHouse health, Loki
`/ready`, and the selected Grafana time range. Do not restart serving services
to repair monitoring.

## Optional IPv6

Omit IPv6 overlays on IPv4-only hosts. `HOST_BIND_IPV6=::` is only consumed
when an IPv6 overlay is explicitly included; public/routed AAAA addresses are
configured in DNS and do not need to be Docker bind addresses.

Control with IPv6:

```sh
docker compose --env-file .env.prod \
  -f compose.prod.yml \
  -f deploy/production/compose.control-host.yml \
  -f deploy/production/compose.control-host-ipv6.yml \
  --profile control up -d
```

Combined DNS/edge with IPv6:

```sh
docker compose --env-file .env.prod \
  -f compose.prod.yml \
  -f deploy/production/compose.dns-edge-host.yml \
  -f deploy/production/compose.dns-host-ipv6.yml \
  -f deploy/production/compose.edge-host-ipv6.yml \
  --profile dns --profile edge up -d
```

Publish AAAA and glue only after external IPv6 DNS and HTTPS checks pass.

## Upgrade and rollback

Back up first. Upgrade one edge at a time, then control components. Run explicit
migrations and keep application/runtime versions inside the documented
compatibility envelope. Never roll back a database after an incompatible
migration.

Use [Upgrade and rollback](upgrade.md) and
[Backup and recovery](../operations/backup-and-recovery.md).

## Next steps

- [Understand the production topology](topology.md)
- [Harden secrets and networks](../security/hardening.md)
- [Practice incident runbooks](../operations/runbooks.md)
- [Scale roles independently](../operations/scaling.md)
